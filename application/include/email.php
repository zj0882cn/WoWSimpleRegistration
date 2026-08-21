<?php
/**
 * Email Authentication Module (SOAP-Only Mode)
 *
 * Replaces MobileAuth with email + verification code login.
 * All game account operations (create / set expansion / set password)
 * are performed via SOAP commands to the worldserver.
 *
 * Email-to-account bindings are persisted in a local JSON file.
 * Email codes are sent via SMTP.
 *
 * @author AzerothCore Community
 */

class EmailAuth
{
    /** @var string Path to the JSON file that stores email bindings */
    private static $dataFile;

    /** @var string Path to the JSON file that stores email codes (with expiry) */
    private static $codeFile;

    /** @var int Email code validity in seconds (10 minutes) */
    private static $codeTTL = 600;

    /** @var int Minimum interval between code sends (60 seconds) */
    private static $resendInterval = 60;

    /** @var int Maximum verification attempts per code */
    private static $maxAttempts = 5;

    // -----------------------------------------------------------------------
    //  Feature toggle checks
    // -----------------------------------------------------------------------

    /**
     * Check if email auth is enabled (master switch).
     */
    public static function isEnabled()
    {
        return (bool)get_config('email_enabled');
    }

    /**
     * Check if a specific email feature is enabled.
     * Falls back to master switch and then defaults to true.
     *
     * @param string $feature  Feature key (e.g. 'login', 'register', 'password_login', 'reset', 'bind')
     * @return bool
     */
    public static function isFeatureEnabled($feature)
    {
        if (!static::isEnabled()) {
            return false;
        }
        $key = 'email_' . $feature . '_enabled';
        $value = get_config($key);
        if ($value === null) {
            return true; // default: enabled
        }
        return (bool)$value;
    }

    // -----------------------------------------------------------------------
    //  Initialization
    // -----------------------------------------------------------------------

    /**
     * Initialize file paths.
     */
    public static function init()
    {
        if (static::$dataFile !== null) {
            return true;
        }
        if (!get_config('email_enabled')) {
            return false;
        }
        static::$dataFile = __DIR__ . '/../data/email_bindings.json';
        static::$codeFile = __DIR__ . '/../data/email_codes.json';
        return true;
    }

    /**
     * Ensure lazy initialization.
     */
    private static function ensureInit()
    {
        if (static::$dataFile === null) {
            static::init();
        }
    }

    // -----------------------------------------------------------------------
    //  Email validation
    // -----------------------------------------------------------------------

    /**
     * Validate an email address.
     *
     * @param string $email
     * @return bool
     */
    public static function isValidEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Mask an email address for display (e.g. t***@example.com).
     *
     * @param string $email
     * @return string
     */
    public static function maskEmail($email)
    {
        if (strpos($email, '@') === false) {
            return $email;
        }
        list($local, $domain) = explode('@', $email, 2);
        $localLen = strlen($local);
        if ($localLen <= 2) {
            $maskedLocal = substr($local, 0, 1) . str_repeat('*', max(1, $localLen - 1));
        } elseif ($localLen <= 4) {
            $maskedLocal = substr($local, 0, 1) . str_repeat('*', $localLen - 1);
        } else {
            $maskedLocal = substr($local, 0, 2) . str_repeat('*', $localLen - 2);
        }
        return $maskedLocal . '@' . $domain;
    }

    // -----------------------------------------------------------------------
    //  Verification code generation & storage
    // -----------------------------------------------------------------------

    /**
     * Generate a 6-digit verification code.
     *
     * @return string
     */
    private static function generateCode()
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Load all email codes from the JSON file.
     *
     * @return array
     */
    private static function loadCodes()
    {
        static::ensureInit();
        if (static::$codeFile === null || !file_exists(static::$codeFile)) {
            return [];
        }
        $content = file_get_contents(static::$codeFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save email codes to the JSON file.
     *
     * @param array $codes
     * @return bool
     */
    private static function saveCodes($codes)
    {
        static::ensureInit();
        if (static::$codeFile === null) {
            return false;
        }
        $dir = dirname(static::$codeFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents(
            static::$codeFile,
            json_encode($codes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) !== false;
    }

    /**
     * Clean up expired codes from storage.
     *
     * @return array Cleaned codes array
     */
    private static function cleanExpiredCodes()
    {
        $codes = static::loadCodes();
        $now = time();
        $codes = array_filter($codes, function ($entry) use ($now) {
            return ($now - $entry['created_at']) < static::$codeTTL;
        });
        $codes = array_values($codes);
        static::saveCodes($codes);
        return $codes;
    }

    /**
     * Send a verification code to an email address.
     *
     * @param string $email
     * @return array ['success' => bool, 'message' => string, 'code' => string (demo only)]
     */
    public static function sendCode($email)
    {
        if (!static::init()) {
            return ['success' => false, 'message' => 'email_auth_disabled'];
        }

        if (!static::isValidEmail($email)) {
            return ['success' => false, 'message' => 'invalid_email'];
        }

        // Clean expired codes first
        $codes = static::cleanExpiredCodes();

        // Check resend interval (rate limiting)
        foreach ($codes as $entry) {
            if ($entry['email'] === $email) {
                $elapsed = time() - $entry['created_at'];
                if ($elapsed < static::$resendInterval) {
                    $wait = static::$resendInterval - $elapsed;
                    return ['success' => false, 'message' => 'rate_limited', 'wait' => $wait];
                }
                break;
            }
        }

        // Remove any existing code for this email
        $codes = array_filter($codes, function ($entry) use ($email) {
            return $entry['email'] !== $email;
        });

        // Generate new code
        $code = static::generateCode();

        // Store the code
        $codes[] = [
            'email'      => $email,
            'code'       => $code,
            'created_at'  => time(),
            'attempts'   => 0,
            'verified'   => false,
        ];
        static::saveCodes(array_values($codes));

        // Send via the configured method
        $provider = get_config('email_provider') ?: 'smtp';
        $result = static::sendEmail($provider, $email, $code);

        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'email_send_failed'];
        }

        return ['success' => true, 'message' => 'code_sent'];
    }

    /**
     * Verify an email code for an email address.
     *
     * @param string $email
     * @param string $code
     * @return array ['success' => bool, 'message' => string]
     */
    public static function verifyCode($email, $code)
    {
        if (!static::init()) {
            return ['success' => false, 'message' => 'email_auth_disabled'];
        }

        $codes = static::cleanExpiredCodes();

        foreach ($codes as $key => $entry) {
            if ($entry['email'] === $email) {
                // Check attempt limit
                if ($entry['attempts'] >= static::$maxAttempts) {
                    unset($codes[$key]);
                    static::saveCodes(array_values($codes));
                    return ['success' => false, 'message' => 'max_attempts_exceeded', 'remaining' => 0];
                }

                // Increment attempts
                $codes[$key]['attempts']++;
                $currentAttempts = $codes[$key]['attempts'];
                static::saveCodes(array_values($codes));

                if ($entry['code'] === $code) {
                    unset($codes[$key]);
                    static::saveCodes(array_values($codes));
                    return ['success' => true, 'message' => 'verified'];
                }

                $remaining = static::$maxAttempts - $currentAttempts;
                return [
                    'success'   => false,
                    'message'   => 'wrong_code',
                    'remaining' => max(0, $remaining),
                ];
            }
        }

        return ['success' => false, 'message' => 'code_not_found'];
    }

    // -----------------------------------------------------------------------
    //  Email sending
    // -----------------------------------------------------------------------

    /**
     * Send an email via the configured provider.
     *
     * @param string $provider 'smtp' | 'mail'
     * @param string $email
     * @param string $code
     * @return array ['success' => bool, 'message' => string]
     */
    private static function sendEmail($provider, $email, $code)
    {
        switch ($provider) {
            case 'mail':
                return static::sendViaMail($email, $code);

            case 'smtp':
                return static::sendSMTP($email, $code);

            default:
                return ['success' => false, 'message' => 'unknown_email_provider'];
        }
    }

    /**
     * Send an email via PHP's native mail() function.
     *
     * Requires the server to have a properly configured MTA
     * (Sendmail, Postfix, or similar). Not recommended for production.
     *
     * @param string $email
     * @param string $code
     * @return array
     */
    private static function sendViaMail($email, $code)
    {
        $fromMail   = get_config('smtp_mail') ?: 'noreply@' . (get_config('baseurl') ?: 'localhost');
        $siteName   = get_config('email_from_name') ?: (get_config('page_title') ?: 'WoW Server');
        $subject    = '[' . $siteName . '] ' . lang('email_verify_subject', 'Email Verification Code');
        $body       = static::buildEmailBody($email, $code);

        // Build headers
        $headers  = [];
        $headers[] = 'From: ' . $siteName . ' <' . $fromMail . '>';
        $headers[] = 'Reply-To: ' . $fromMail;
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'X-Mailer: PHP/' . phpversion();
        $headers[] = 'MIME-Version: 1.0';

        // Try with -f parameter to set envelope sender
        $additionalParams = '-f ' . $fromMail;

        $sent = @mail($email, $subject, $body, implode("\r\n", $headers), $additionalParams);

        if (!$sent) {
            // Fallback: basic headers without -f
            $basicHeaders = 'From: ' . $fromMail . "\r\n" .
                           'Content-Type: text/html; charset=UTF-8' . "\r\n";
            $sent = @mail($email, $subject, $body, $basicHeaders);
        }

        if ($sent) {
            return ['success' => true, 'message' => 'email_sent'];
        }

        if (get_config('debug_mode')) {
            error_log('[EmailAuth] mail() failed for ' . $email);
        }
        return ['success' => false, 'message' => 'email_send_failed'];
    }

    /**
     * Send an email via SMTP (using PHPMailer if available, or fsockopen fallback).
     *
     * First tries PHPMailer (reliable). If not available, falls back to
     * a basic fsockopen-based SMTP implementation.
     *
     * @param string $email
     * @param string $code
     * @return array
     */
    private static function sendSMTP($email, $code)
    {
        $smtpHost   = get_config('smtp_host');
        $smtpPort   = get_config('smtp_port') ?: 587;
        $smtpUser   = get_config('smtp_user');
        $smtpPass   = get_config('smtp_pass');
        $smtpSecure = get_config('smtp_secure') ?: '';
        $smtpAuth   = (bool)get_config('smtp_auth', true);
        $fromMail   = get_config('smtp_mail') ?: 'noreply@' . (get_config('baseurl') ?: 'localhost');

        if (empty($smtpHost)) {
            return ['success' => false, 'message' => 'smtp_config_incomplete'];
        }

        $siteName = get_config('email_from_name') ?: (get_config('page_title') ?: 'WoW Server');
        $subject  = '[' . $siteName . '] ' . lang('email_verify_subject', 'Email Verification Code');
        $body     = static::buildEmailBody($email, $code);

        // Try PHPMailer first (via composer autoload)
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            $result = static::sendViaPHPMailer($email, $subject, $body, $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpSecure, $smtpAuth, $fromMail, $siteName);
            if ($result !== null) {
                return $result;
            }
        }

        // Fallback: basic fsockopen SMTP implementation
        return static::sendViaFsockopenSMTP($email, $subject, $body, $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpSecure, $smtpAuth, $fromMail, $siteName);
    }

    /**
     * Send via PHPMailer if available.
     *
     * @return array|null Returns result or null if PHPMailer not available
     */
    private static function sendViaPHPMailer($email, $subject, $body, $host, $port, $user, $pass, $secure, $auth, $fromMail, $siteName)
    {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return null;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPAuth   = $auth;
            $mail->Username   = $user;
            $mail->Password   = $pass;

            if ($secure === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($secure === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }

            $mail->setFrom($fromMail, $siteName);
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            return ['success' => true, 'message' => 'email_sent'];
        } catch (\Exception $e) {
            if (get_config('debug_mode')) {
                error_log('[EmailAuth] PHPMailer error: ' . $e->getMessage());
            }
            return null; // Fall through to fsockopen
        }
    }

    /**
     * Basic SMTP sender using fsockopen (no external dependencies).
     *
     * Implements the minimum SMTP protocol (EHLO/AUTH LOGIN/MAIL FROM/RCPT TO/DATA/QUIT)
     * for when PHPMailer is not available. Supports TLS via STARTTLS extension.
     *
     * @return array
     */
    private static function sendViaFsockopenSMTP($email, $subject, $body, $host, $port, $user, $pass, $secure, $auth, $fromMail, $siteName)
    {
        $timeout = 15;
        $crypto  = ($secure === 'ssl') ? 'ssl://' : '';
        $errno   = 0;
        $errstr  = '';

        $fp = @stream_socket_client(
            ($crypto ?: 'tcp://') . $host . ':' . $port,
            $errno, $errstr, $timeout
        );

        if (!$fp) {
            // Try without crypto prefix
            $fp = @stream_socket_client(
                'tcp://' . $host . ':' . $port,
                $errno, $errstr, $timeout
            );
        }

        if (!$fp) {
            if (get_config('debug_mode')) {
                error_log('[EmailAuth] SMTP connection failed: ' . $host . ':' . $port . ' - ' . $errstr);
            }
            return ['success' => false, 'message' => 'smtp_connect_failed'];
        }

        stream_set_timeout($fp, $timeout);

        // Helper to read response
        $readResponse = function() use ($fp) {
            $response = '';
            while (($line = fgets($fp, 515)) !== false) {
                $response .= $line;
                // Final line has a space after the code
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return trim($response);
        };

        // Expect 220
        $resp = $readResponse();
        if (strpos($resp, '220') !== 0) {
            fclose($fp);
            return ['success' => false, 'message' => 'smtp_banner_error'];
        }

        // EHLO
        fwrite($fp, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n");
        $resp = $readResponse();
        if (strpos($resp, '250') !== 0) {
            fclose($fp);
            return ['success' => false, 'message' => 'smtp_ehlo_error'];
        }

        // STARTTLS if needed
        $tlsSupported = (strpos($resp, 'STARTTLS') !== false);
        if ($secure === 'tls' && $tlsSupported) {
            fwrite($fp, "STARTTLS\r\n");
            $resp = $readResponse();
            if (strpos($resp, '220') === 0) {
                // Upgrade to TLS
                $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) {
                    stream_socket_enable_crypto($fp, true, $crypto_method);
                } else {
                    fclose($fp);
                    return ['success' => false, 'message' => 'smtp_tls_error'];
                }
                // Re-EHLO after STARTTLS
                fwrite($fp, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n");
                $resp = $readResponse();
                if (strpos($resp, '250') !== 0) {
                    fclose($fp);
                    return ['success' => false, 'message' => 'smtp_ehlo_error'];
                }
            }
        }

        // AUTH
        if ($auth && !empty($user)) {
            // Check supported auth methods
            $authMethods = [];
            if (preg_match('/AUTH\s+(.+)/i', $resp, $m)) {
                $authMethods = array_map('trim', explode(' ', $m[1]));
            }

            // Try LOGIN (most common)
            if (in_array('LOGIN', $authMethods) || in_array('PLAIN', $authMethods) || empty($authMethods)) {
                fwrite($fp, "AUTH LOGIN\r\n");
                $resp = $readResponse();
                if (strpos($resp, '334') !== 0) {
                    fclose($fp);
                    return ['success' => false, 'message' => 'smtp_auth_error'];
                }
                fwrite($fp, base64_encode($user) . "\r\n");
                $resp = $readResponse();
                fwrite($fp, base64_encode($pass) . "\r\n");
                $resp = $readResponse();
                if (strpos($resp, '235') !== 0) {
                    fclose($fp);
                    return ['success' => false, 'message' => 'smtp_auth_failed'];
                }
            }
        }

        // MAIL FROM
        fwrite($fp, "MAIL FROM:<{$fromMail}>\r\n");
        $resp = $readResponse();
        if (strpos($resp, '250') !== 0) {
            fclose($fp);
            return ['success' => false, 'message' => 'smtp_mailfrom_error'];
        }

        // RCPT TO
        fwrite($fp, "RCPT TO:<{$email}>\r\n");
        $resp = $readResponse();
        if (strpos($resp, '250') !== 0) {
            fclose($fp);
            return ['success' => false, 'message' => 'smtp_rcptto_error'];
        }

        // DATA
        fwrite($fp, "DATA\r\n");
        $resp = $readResponse();
        if (strpos($resp, '354') !== 0) {
            fclose($fp);
            return ['success' => false, 'message' => 'smtp_data_error'];
        }

        // Build full message
        $headers  = "From: {$siteName} <{$fromMail}>\r\n";
        $headers .= "Reply-To: {$fromMail}\r\n";
        $headers .= "To: <{$email}>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "\r\n";

        // Dot-stuff the body
        $message = $headers . $body;
        $message = str_replace("\r\n.", "\r\n..", $message);
        fwrite($fp, $message . "\r\n.\r\n");

        $resp = $readResponse();
        $success = (strpos($resp, '250') === 0);

        // QUIT
        fwrite($fp, "QUIT\r\n");
        fclose($fp);

        if ($success) {
            return ['success' => true, 'message' => 'email_sent'];
        }

        if (get_config('debug_mode')) {
            error_log('[EmailAuth] SMTP DATA response: ' . $resp);
        }
        return ['success' => false, 'message' => 'smtp_data_failed'];
    }

    /**
     * Build the HTML email body for verification.
     *
     * @param string $email
     * @param string $code
     * @return string
     */
    private static function buildEmailBody($email, $code)
    {
        $siteName = get_config('page_title') ?: 'WoW Server';
        $siteUrl  = get_config('baseurl') ?: '';
        $langCode = lang('email_greeting') ?: 'Hello';
        $langIntro = lang('email_intro') ?: 'You are receiving this email because you (or someone else) entered your email address on our website.';
        $langCodeLabel = lang('email_code_label') ?: 'Your verification code is';
        $langExpire = lang('email_expire') ?: 'This code will expire in 10 minutes.';
        $langIfNot = lang('email_if_not') ?: 'If you did not request this, please ignore this email.';

        $body = '<!DOCTYPE html>' . "\r\n";
        $body .= '<html><body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">' . "\r\n";
        $body .= '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; border-radius: 10px; text-align: center; color: white;">' . "\r\n";
        $body .= '<h2 style="margin: 0;">' . htmlspecialchars($siteName) . '</h2>' . "\r\n";
        $body .= '</div>' . "\r\n";
        $body .= '<div style="padding: 20px; background: #f9f9f9; border-radius: 10px; margin-top: 20px;">' . "\r\n";
        $body .= '<p style="font-size: 16px; color: #333;">' . htmlspecialchars($langCode) . ',</p>' . "\r\n";
        $body .= '<p style="color: #555;">' . htmlspecialchars($langIntro) . '</p>' . "\r\n";
        $body .= '<div style="text-align: center; margin: 30px 0;">' . "\r\n";
        $body .= '<div style="font-size: 14px; color: #888; margin-bottom: 10px;">' . htmlspecialchars($langCodeLabel) . '</div>' . "\r\n";
        $body .= '<div style="font-size: 36px; font-weight: 700; letter-spacing: 8px; color: #667eea; background: white; padding: 15px 30px; border-radius: 8px; display: inline-block; font-family: monospace;">' . htmlspecialchars($code) . '</div>' . "\r\n";
        $body .= '</div>' . "\r\n";
        $body .= '<p style="color: #888; font-size: 14px; text-align: center;">' . htmlspecialchars($langExpire) . '</p>' . "\r\n";
        $body .= '<p style="color: #aaa; font-size: 12px; margin-top: 20px; text-align: center;">' . htmlspecialchars($langIfNot) . '</p>' . "\r\n";
        if (!empty($siteUrl)) {
            $body .= '<p style="text-align: center; margin-top: 20px;"><a href="' . htmlspecialchars($siteUrl) . '" style="color: #667eea;">' . htmlspecialchars($siteUrl) . '</a></p>' . "\r\n";
        }
        $body .= '</div>' . "\r\n";
        $body .= '</body></html>';

        return $body;
    }

    // -----------------------------------------------------------------------
    //  SOAP command execution
    // -----------------------------------------------------------------------

    /**
     * Execute a SOAP command on the worldserver and return the response text.
     *
     * @param string $command
     * @return array ['success' => bool, 'message' => string]
     */
    public static function soapCommand($command)
    {
        if (empty($command)) {
            return ['success' => false, 'message' => 'empty command'];
        }

        $style = get_config('soap_style');
        if (is_string($style)) {
            $styleConstant = strtoupper($style);
            if (defined($styleConstant)) {
                $style = constant($styleConstant);
            } else {
                $style = SOAP_RPC;
            }
        }

        $soapOptions = [
            'location' => 'http://' . get_config('soap_host') . ':' . get_config('soap_port') . '/',
            'uri'      => get_config('soap_uri'),
            'style'    => $style,
            'login'    => get_config('soap_username'),
            'password' => get_config('soap_password'),
            'connection_timeout' => 5,
            'trace'    => true,
            'exceptions' => true,
        ];

        try {
            $conn = new SoapClient(NULL, $soapOptions);
            $result = $conn->executeCommand(new SoapParam($command, 'command'));
            unset($conn);

            $message = is_string($result) ? trim($result) : '';

            if (get_config('debug_mode')) {
                error_log('[Email SOAP] Command: ' . $command . ' -> OK: ' . substr($message, 0, 200));
            }

            return ['success' => true, 'message' => $message];
        } catch (Exception $e) {
            if (get_config('debug_mode')) {
                error_log('[Email SOAP] Error: ' . $command . ' -> ' . $e->getMessage());
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get server status info via SOAP command "server info".
     *
     * @return array|false Server status array or false on failure
     */
    public static function getServerStatus()
    {
        $result = static::soapCommand('server info');
        if (!$result['success']) {
            return false;
        }

        $raw = $result['message'];
        $status = [
            'revision'       => '',
            'online_players' => 0,
            'characters'     => 0,
            'peak'           => 0,
            'uptime'         => '',
            'update_time'    => '',
            'raw'            => $raw,
        ];

        if (preg_match('/Connected players:\s*(\d+)/i', $raw, $m)) {
            $status['online_players'] = (int)$m[1];
        }
        if (preg_match('/Characters in world:\s*(\d+)/i', $raw, $m)) {
            $status['characters'] = (int)$m[1];
        }
        if (preg_match('/Connection peak:\s*(\d+)/i', $raw, $m)) {
            $status['peak'] = (int)$m[1];
        }
        if (preg_match('/运行时间:\s*(.+)/i', $raw, $m)) {
            $status['uptime'] = trim($m[1]);
        } elseif (preg_match('/Uptime:\s*(.+)/i', $raw, $m)) {
            $status['uptime'] = trim($m[1]);
        }
        if (preg_match('/AzerothCore\s+(.+)/i', $raw, $m)) {
            $status['revision'] = trim($m[1]);
        }

        return $status;
    }

    /**
     * Check if a game account exists via SOAP.
     *
     * @param string $username
     * @return bool
     */
    public static function accountExists($username)
    {
        $username = strtoupper($username);
        $expansion = get_config('expansion');
        $command = "account set addon {$username} {$expansion}";
        $result = static::soapCommand($command);

        if (!$result['success']) {
            return false;
        }

        $msg = strtolower($result['message']);
        if (strpos($msg, 'not exist') !== false ||
            strpos($msg, 'not found') !== false ||
            strpos($msg, 'does not exist') !== false) {
            return false;
        }

        return true;
    }

    /**
     * Get character list for an account via SOAP "lookup player account".
     *
     * @param string $username
     * @return array|false
     */
    public static function getAccountCharacters($username)
    {
        $username = strtoupper($username);
        $result = static::soapCommand("lookup player account {$username}");

        if (!$result['success']) {
            return false;
        }

        $raw = $result['message'];
        $data = [
            'account_id'  => 0,
            'characters'  => [],
            'raw'         => $raw,
        ];

        if (preg_match('/\(Id:\s*(\d+)\)/i', $raw, $m)) {
            $data['account_id'] = (int)$m[1];
        }

        $raceMap = [
            'Human' => '人类', 'Orc' => '兽人', 'Dwarf' => '矮人', 'Night Elf' => '暗夜精灵',
            'Undead' => '亡灵', 'Tauren' => '牛头人', 'Gnome' => '侏儒', 'Troll' => '巨魔',
            'Blood Elf' => '血精灵', 'Draenei' => '德莱尼',
        ];
        $classMap = [
            'Warrior' => '战士', 'Paladin' => '圣骑士', 'Hunter' => '猎人', 'Rogue' => '潜行者',
            'Priest' => '牧师', 'Death Knight' => '死亡骑士', 'Shaman' => '萨满祭司',
            'Mage' => '法师', 'Warlock' => '术士', 'Druid' => '德鲁伊',
        ];

        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            if (preg_match('/^(.+?)\s*\(GUID\s*(\d+)\)\s*-\s*(.+?)\s*-\s*(.+?)\s*-\s*(\d+)/i', trim($line), $m)) {
                $raceEn = trim($m[3]);
                $classEn = trim($m[4]);
                $data['characters'][] = [
                    'name'     => trim($m[1]),
                    'guid'     => (int)$m[2],
                    'race'     => $raceMap[$raceEn] ?? $raceEn,
                    'race_en'  => $raceEn,
                    'class'    => $classMap[$classEn] ?? $classEn,
                    'class_en' => $classEn,
                    'level'    => (int)$m[5],
                ];
            }
        }

        return $data;
    }

    // -----------------------------------------------------------------------
    //  Binding storage — Relational JSON with account_id indexing
    // -----------------------------------------------------------------------

    /**
     * Get the expected data file structure version.
     */
    const DATA_VERSION = 2;

    /**
     * Load the entire accounts data structure.
     *
     * Structure:
     * {
     *   "version": 2,
     *   "accounts": {
     *     "USERNAME": {
     *       "account_id": 12345,
     *       "username": "USERNAME",
     *       "email": "user@example.com",
     *       "phone": "13812345678",
     *       "bind_time": "2026-08-21 10:00:00",
     *       "email_verified": true,
     *       "phone_verified": false
     *     }
     *   },
     *   "email_index": { "user@example.com": "USERNAME" },
     *   "phone_index": { "13812345678": "USERNAME" },
     *   "account_id_index": { "12345": "USERNAME" }
     * }
     *
     * @return array
     */
    private static function loadData()
    {
        static::ensureInit();
        if (static::$dataFile === null || !file_exists(static::$dataFile)) {
            return static::defaultData();
        }
        $content = file_get_contents(static::$dataFile);
        $data = json_decode($content, true);
        if (!is_array($data) || ($data['version'] ?? 0) < self::DATA_VERSION) {
            return static::defaultData();
        }
        // Ensure all sections exist
        $data = array_replace_recursive(static::defaultData(), $data);
        return $data;
    }

    /**
     * Save the entire accounts data structure.
     *
     * @param array $data
     * @return bool
     */
    private static function saveData($data)
    {
        static::ensureInit();
        if (static::$dataFile === null) {
            return false;
        }
        $dir = dirname(static::$dataFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $data['version'] = self::DATA_VERSION;
        return file_put_contents(
            static::$dataFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) !== false;
    }

    /**
     * Default data structure.
     *
     * @return array
     */
    private static function defaultData()
    {
        return [
            'version'         => self::DATA_VERSION,
            'accounts'        => [],
            'email_index'     => [],
            'phone_index'     => [],
            'account_id_index' => [],
        ];
    }

    /**
     * Rebuild all reverse indexes from the accounts table.
     * Keeps indexes consistent with account data.
     *
     * @param array $data
     * @return array
     */
    private static function rebuildIndexes($data)
    {
        $data['email_index']      = [];
        $data['phone_index']      = [];
        $data['account_id_index'] = [];

        foreach ($data['accounts'] as $username => $account) {
            if (!empty($account['email'])) {
                $data['email_index'][$account['email']] = $username;
            }
            if (!empty($account['phone'])) {
                $data['phone_index'][$account['phone']] = $username;
            }
            if (!empty($account['account_id'])) {
                $data['account_id_index'][(string)$account['account_id']] = $username;
            }
        }

        return $data;
    }

    /**
     * Look up a binding by email address.
     * Uses email_index for O(1) lookup.
     *
     * @param string $email
     * @return array|false
     */
    public static function getBindingByEmail($email)
    {
        $data = static::loadData();
        $username = $data['email_index'][$email] ?? null;
        if ($username && isset($data['accounts'][$username])) {
            return $data['accounts'][$username];
        }
        return false;
    }

    /**
     * Look up a binding by phone number.
     * Uses phone_index for O(1) lookup.
     *
     * @param string $phone
     * @return array|false
     */
    public static function getBindingByPhone($phone)
    {
        $data = static::loadData();
        $username = $data['phone_index'][$phone] ?? null;
        if ($username && isset($data['accounts'][$username])) {
            return $data['accounts'][$username];
        }
        return false;
    }

    /**
     * Look up a binding by game account username.
     * Direct account table lookup.
     *
     * @param string $username
     * @return array|false
     */
    public static function getBindingByUsername($username)
    {
        $username = strtoupper($username);
        $data = static::loadData();
        return $data['accounts'][$username] ?? false;
    }

    /**
     * Look up a binding by account_id (numeric).
     * Uses account_id_index for O(1) lookup.
     *
     * @param int|string $accountId
     * @return array|false
     */
    public static function getBindingByAccountId($accountId)
    {
        $data = static::loadData();
        $username = $data['account_id_index'][(string)$accountId] ?? null;
        if ($username && isset($data['accounts'][$username])) {
            return $data['accounts'][$username];
        }
        return false;
    }

    /**
     * Get account_id for a game account via SOAP.
     *
     * @param string $username
     * @return int|false
     */
    public static function getAccountId($username)
    {
        $username = strtoupper($username);
        $result = static::soapCommand("lookup player account {$username}");
        if (!$result['success']) {
            return false;
        }
        if (preg_match('/\(Id:\s*(\d+)\)/i', $result['message'], $m)) {
            return (int)$m[1];
        }
        return false;
    }

    /**
     * Add or update a binding for an account.
     * This is the central write method — updates account table and all indexes.
     *
     * NOTE: Passwords are NOT stored locally. All password operations
     * go directly to the AzerothCore server via SOAP.
     *
     * @param string $email
     * @param string $username
     * @param string $phone  Optional phone number
     * @return bool
     */
    public static function saveBinding($email, $username, $phone = '')
    {
        $username = strtoupper($username);
        $data = static::loadData();

        // Get existing account data if any
        $existing = $data['accounts'][$username] ?? [];

        // Preserve existing fields
        $account = [
            'account_id'     => $existing['account_id'] ?? null,
            'username'       => $username,
            'email'          => $email ?: ($existing['email'] ?? ''),
            'phone'          => $phone ?: ($existing['phone'] ?? ''),
            'bind_time'      => date('Y-m-d H:i:s'),
            'email_verified' => !empty($email),
            'phone_verified' => !empty($phone),
        ];

        // Try to resolve account_id if we don't have one
        if (empty($account['account_id']) && static::accountExists($username)) {
            $accountId = static::getAccountId($username);
            if ($accountId) {
                $account['account_id'] = $accountId;
            }
        }

        // Check for duplicate email on a different account
        if (!empty($account['email'])) {
            $existingUser = $data['email_index'][$account['email']] ?? null;
            if ($existingUser && $existingUser !== $username) {
                return false;
            }
        }

        // Check for duplicate phone on a different account
        if (!empty($account['phone'])) {
            $existingUser = $data['phone_index'][$account['phone']] ?? null;
            if ($existingUser && $existingUser !== $username) {
                return false;
            }
        }

        $data['accounts'][$username] = $account;
        $data = static::rebuildIndexes($data);

        return static::saveData($data);
    }

    /**
     * Update only specific fields of an account binding.
     *
     * @param string $username
     * @param array $fields   Associative array of fields to update
     * @return bool
     */
    public static function updateBinding($username, array $fields)
    {
        $username = strtoupper($username);
        $data = static::loadData();

        if (!isset($data['accounts'][$username])) {
            return false;
        }

        foreach ($fields as $key => $value) {
            $data['accounts'][$username][$key] = $value;
        }

        $data = static::rebuildIndexes($data);
        return static::saveData($data);
    }

    /**
     * Remove an account binding entirely.
     *
     * @param string $username
     * @return bool
     */
    public static function removeBinding($username)
    {
        $username = strtoupper($username);
        $data = static::loadData();

        unset($data['accounts'][$username]);
        $data = static::rebuildIndexes($data);

        return static::saveData($data);
    }

    /**
     * Unlink a specific email from an account (keeps account).
     *
     * @param string $email
     * @return bool
     */
    public static function unlinkEmail($email)
    {
        $data = static::loadData();
        $username = $data['email_index'][$email] ?? null;

        if (!$username || !isset($data['accounts'][$username])) {
            return false;
        }

        $data['accounts'][$username]['email'] = '';
        $data['accounts'][$username]['email_verified'] = false;
        $data = static::rebuildIndexes($data);

        return static::saveData($data);
    }

    /**
     * List all account bindings (for admin/debug).
     *
     * @return array
     */
    public static function listAllBindings()
    {
        $data = static::loadData();
        return $data['accounts'];
    }

    // Remove old password-related methods — passwords NOT stored locally.
    // All password operations go through SOAP to the game server.

    /**
     * Bind an email to an existing game account.
     * Uses relational JSON storage with account_id indexing.
     */
    public static function bindEmail($username, $email, $code)
    {
        if (!static::init()) {
            return ['success' => false, 'message' => 'email_auth_disabled'];
        }

        $username = strtoupper($username);

        $verifyResult = static::verifyCode($email, $code);
        if (!$verifyResult['success']) {
            return ['success' => false, 'message' => $verifyResult['message']];
        }

        // Check if email is already bound to a different account
        $existingBinding = static::getBindingByEmail($email);
        if ($existingBinding && strtoupper($existingBinding['username']) !== $username) {
            return ['success' => false, 'message' => 'email_already_bound'];
        }

        // Verify the game account exists on the server
        if (!static::accountExists($username)) {
            return ['success' => false, 'message' => 'account_not_found'];
        }

        // Update or create the binding using relational JSON
        $binding = static::getBindingByUsername($username);
        if ($binding) {
            // Update existing binding: set email and mark as verified
            static::updateBinding($username, [
                'email'          => $email,
                'email_verified' => true,
                'bind_time'      => date('Y-m-d H:i:s'),
            ]);
        } else {
            // Create new binding (no phone)
            static::saveBinding($email, $username, '');
        }

        $_SESSION['user_email'] = $email;

        return [
            'success'  => true,
            'message'  => 'email_bound',
            'username' => $username,
            'email'    => $email,
        ];
    }

    // -----------------------------------------------------------------------
    //  Login / Registration flow
    // -----------------------------------------------------------------------

    /**
     * Process login/registration after email code verification.
     *
     * Flow:
     *   1. Verify the email code
     *   2. Check if the email is already bound to a game account
     *   3. If bound: log in (set session)
     *   4. If not bound: auto-create a game account via SOAP, then log in
     *
     * @param string $email
     * @param string $code
     * @param string $customUsername  Optional custom username
     * @param string $password        Optional password (for new accounts)
     * @return array
     */
    public static function loginOrRegister($email, $code, $customUsername = '', $password = '')
    {
        if (!static::init()) {
            return ['success' => false, 'message' => 'email_auth_disabled'];
        }

        $verifyResult = static::verifyCode($email, $code);
        if (!$verifyResult['success']) {
            return ['success' => false, 'message' => $verifyResult['message']];
        }

        $binding = static::getBindingByEmail($email);
        $username = null;

        if ($binding) {
            if (static::accountExists($binding['username'])) {
                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_username']  = $binding['username'];
                $_SESSION['user_email']     = $email;

                return [
                    'success'  => true,
                    'username' => $binding['username'],
                    'password' => '',
                    'message'  => 'login_success',
                    'is_new'   => false,
                ];
            }
            $username = $binding['username'];
        }

        // New account flow
        $password = trim($password);
        if (empty($password) || strlen($password) < 6 || strlen($password) > 16) {
            $password = static::generatePassword();
        }

        $customUsername = trim($customUsername);
        if (empty($username)) {
            if (!empty($customUsername)) {
                if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $customUsername)) {
                    return ['success' => false, 'message' => 'invalid_username'];
                }
                $username = strtoupper($customUsername);
                if (static::accountExists($username)) {
                    $existingBinding = static::getBindingByUsername($username);
                    if ($existingBinding && !empty($existingBinding['email'])) {
                        return ['success' => false, 'message' => 'username_taken'];
                    }
                    // Save binding (password NOT stored locally — only email and username)
                    static::saveBinding($email, $username, '');
                    $_SESSION['user_logged_in'] = true;
                    $_SESSION['user_username']  = $username;
                    $_SESSION['user_email']     = $email;

                    return [
                        'success'  => true,
                        'username' => $username,
                        'password' => $password,
                        'message'  => 'login_success',
                        'is_new'   => false,
                    ];
                }
            } else {
                $username = static::generateUsername($email);
            }
        }
        $username = strtoupper($username);

        $createCommand = get_config('soap_ca_command') ?: 'account create {USERNAME} {PASSWORD}';
        $createCommand = str_replace('{USERNAME}', $username, $createCommand);
        $createCommand = str_replace('{PASSWORD}', $password, $createCommand);
        $result = static::soapCommand($createCommand);

        if (!$result['success']) {
            return ['success' => false, 'message' => 'soap_create_failed'];
        }

        $msg = strtolower($result['message']);
        if (strpos($msg, 'already exist') !== false) {
            $username = static::generateUsername($email . time());
            $password = static::generatePassword();
            $createCommand = get_config('soap_ca_command') ?: 'account create {USERNAME} {PASSWORD}';
            $createCommand = str_replace('{USERNAME}', $username, $createCommand);
            $createCommand = str_replace('{PASSWORD}', $password, $createCommand);
            $result = static::soapCommand($createCommand);
            if (!$result['success']) {
                return ['success' => false, 'message' => 'soap_create_failed'];
            }
        }

        $addonCommand = get_config('soap_asa_command');
        if (!empty($addonCommand)) {
            $addonCommand = str_replace('{USERNAME}', $username, $addonCommand);
            $addonCommand = str_replace('{EXPANSION}', get_config('expansion'), $addonCommand);
            static::soapCommand($addonCommand);
        } else {
            static::soapCommand("account set addon {$username} " . get_config('expansion'));
        }

        // Save binding (password NOT stored locally — only email and username)
        static::saveBinding($email, $username, '');

        $_SESSION['user_logged_in'] = true;
        $_SESSION['user_username']  = $username;
        $_SESSION['user_email']     = $email;

        return [
            'success'  => true,
            'username' => $username,
            'password' => $password,
            'message'  => 'register_success',
            'is_new'   => true,
        ];
    }

    // -----------------------------------------------------------------------
    //  Registration helpers
    // -----------------------------------------------------------------------

    /**
     * Generate a unique username from an email address.
     *
     * Format: U + domain part hash + random suffix
     *
     * @param string $email
     * @return string
     */
    public static function generateUsername($email)
    {
        $parts = explode('@', $email);
        $local = $parts[0] ?? 'user';
        $domain = $parts[1] ?? 'mail';

        // Take first 4 chars of local + hash of domain
        $local = preg_replace('/[^A-Za-z0-9]/', '', $local);
        $base = 'U' . strtoupper(substr($local, 0, 4)) . strtoupper(substr(md5($domain), 0, 4));
        $base = strtoupper(substr($base, 0, 14));

        $username = $base;
        $suffix = 1;
        while (static::accountExists($username)) {
            $username = $base . $suffix;
            $suffix++;
            if ($suffix > 99) {
                $username = $base . rand(100, 999);
                break;
            }
        }
        return $username;
    }

    /**
     * Generate a random password.
     */
    public static function generatePassword($length = 12)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
    }

    // -----------------------------------------------------------------------
    //  Password management
    // -----------------------------------------------------------------------

    /**
     * Reset the password for a game account using SOAP.
     * Generates a new random password and sets it via GM mode.
     */
    public static function resetPassword($username)
    {
        $username = strtoupper($username);

        if (!static::accountExists($username)) {
            return ['success' => false, 'password' => '', 'message' => 'account_not_found'];
        }

        $newPassword = static::generatePassword();

        // Use setPassword() which handles SOAP GM reset (no old password needed)
        $result = static::setPassword($username, $newPassword);

        if (!$result['success']) {
            return ['success' => false, 'password' => '', 'message' => $result['message']];
        }

        // NOTE: Password is NOT stored locally — it's only on the game server.
        // Return the new password so the caller can show it to the user once.

        return [
            'success'  => true,
            'username' => $username,
            'password' => $newPassword,
            'message'  => '',
        ];
    }

    /**
     * Reset password via email verification.
     *
     * @param string $email
     * @param string $code
     * @return array
     */
    public static function resetPasswordByEmail($email, $code)
    {
        if (!static::init()) {
            return ['success' => false, 'message' => 'email_auth_disabled'];
        }

        $verifyResult = static::verifyCode($email, $code);
        if (!$verifyResult['success']) {
            return ['success' => false, 'message' => $verifyResult['message']];
        }

        $binding = static::getBindingByEmail($email);
        if (!$binding) {
            return ['success' => false, 'message' => 'email_not_bound'];
        }

        return static::resetPassword($binding['username']);
    }

    /**
     * Change the password for a game account using SOAP.
     * Uses 3-parameter account set password with old password verification.
     *
     * @param string $username
     * @param string $oldPassword  Current password (verified by game server via SOAP)
     * @param string $newPassword  New password
     */
    public static function changePassword($username, $oldPassword, $newPassword)
    {
        $username = strtoupper($username);
        $oldPassword = trim($oldPassword);
        $newPassword = trim($newPassword);

        if (strlen($newPassword) < 6 || strlen($newPassword) > 16) {
            return ['success' => false, 'message' => 'invalid_password'];
        }

        if (!static::accountExists($username)) {
            return ['success' => false, 'message' => 'account_not_found'];
        }

        // Use 3-parameter SOAP: account set password USERNAME OLDPASSWORD NEWPASSWORD
        // The game server verifies the old password before allowing the change
        $pwCommand = "account set password {$username} {$oldPassword} {$newPassword}";
        $pwResult = static::soapCommand($pwCommand);

        if (!$pwResult['success']) {
            // Check if it's a wrong password error
            $msg = strtolower($pwResult['message'] ?? '');
            if (strpos($msg, 'password') !== false && (strpos($msg, 'wrong') !== false || strpos($msg, 'incorrect') !== false || strpos($msg, 'invalid') !== false)) {
                return ['success' => false, 'message' => 'wrong_old_password'];
            }
            return ['success' => false, 'message' => 'soap_error'];
        }

        $msg = strtolower($pwResult['message']);
        if (strpos($msg, 'not exist') !== false ||
            strpos($msg, 'not found') !== false ||
            strpos($msg, 'does not exist') !== false) {
            return ['success' => false, 'message' => 'account_not_found'];
        }

        // NOTE: Password is NOT stored locally — it's only on the game server.

        return [
            'success'  => true,
            'username' => $username,
            'message'  => 'password_changed',
        ];
    }

    /**
     * Set password without old password verification (GM/reset mode).
     * Uses 2-parameter account set password command.
     *
     * @param string $username
     * @param string $newPassword
     * @return array
     */
    public static function setPassword($username, $newPassword)
    {
        $username = strtoupper($username);
        $newPassword = trim($newPassword);

        if (strlen($newPassword) < 6 || strlen($newPassword) > 16) {
            return ['success' => false, 'message' => 'invalid_password'];
        }

        if (!static::accountExists($username)) {
            return ['success' => false, 'message' => 'account_not_found'];
        }

        // Try 2-parameter first (GM mode, no old password needed)
        $pwCommand = "account set password {$username} {$newPassword}";
        $pwResult = static::soapCommand($pwCommand);

        // Fallback: some AzerothCore versions require 3 parameters —
        // in that case, the admin must use the resetPassword() flow instead
        if (!$pwResult['success']) {
            return ['success' => false, 'message' => 'soap_reset_not_supported'];
        }

        $msg = strtolower($pwResult['message']);
        if (strpos($msg, 'not exist') !== false ||
            strpos($msg, 'not found') !== false ||
            strpos($msg, 'does not exist') !== false) {
            return ['success' => false, 'message' => 'account_not_found'];
        }

        return [
            'success'  => true,
            'username' => $username,
            'message'  => 'password_set',
        ];
    }

    /**
     * Change password for the currently logged-in user.
     * Since we don't store passwords locally, the old password is verified
     * by the game server via SOAP (3-parameter account set password).
     */
    public static function changeMyPassword($oldPassword, $newPassword)
    {
        if (!static::isLoggedIn()) {
            return ['success' => false, 'message' => 'not_logged_in'];
        }

        $username = $_SESSION['user_username'] ?? '';
        if (empty($username)) {
            return ['success' => false, 'message' => 'no_bound_account'];
        }

        // Delegate to changePassword() which verifies old password via SOAP
        return static::changePassword($username, $oldPassword, $newPassword);
    }

    // -----------------------------------------------------------------------
    //  Session management
    // -----------------------------------------------------------------------

    /**
     * Check if a user is logged in via email.
     */
    public static function isLoggedIn()
    {
        return !empty($_SESSION['user_logged_in']);
    }

    /**
     * Get the currently logged-in user info.
     */
    public static function getCurrentUser()
    {
        if (!static::isLoggedIn()) {
            return null;
        }
        return [
            'username' => $_SESSION['user_username'] ?? null,
            'email'    => $_SESSION['user_email'] ?? null,
        ];
    }

    /**
     * Log out the email session.
     */
    public static function logout()
    {
        unset($_SESSION['user_logged_in'],
              $_SESSION['user_username'],
              $_SESSION['user_email']);
    }
}
