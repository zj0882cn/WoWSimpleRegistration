<?php
/**
 * Mock SOAP Handler — Simulates AzerothCore Worldserver SOAP endpoint
 *
 * This file is used with PHP's built-in web server:
 *   php -S 0.0.0.0:7878 mock_soap_handler.php
 *
 * It receives SOAP requests from PHP's SoapClient and responds
 * exactly like an AzerothCore worldserver would.
 *
 * @author AzerothCore Community
 **/

$dataFile = __DIR__ . '/mock_accounts.json';

// Load accounts
$accounts = [];
if (file_exists($dataFile)) {
    $accounts = json_decode(file_get_contents($dataFile), true) ?: [];
}

// Read raw POST body
$body = file_get_contents('php://input');

// Log the request
$timestamp = date('H:i:s');
error_log("[{$timestamp}] SOAP Request received, length=" . strlen($body));

// Parse the SOAP command from the request body
$command = '';
if (preg_match('/<command[^>]*>(.*?)<\/command>/is', $body, $m)) {
    $command = trim(html_entity_decode($m[1]));
} elseif (preg_match('/<ns1:command[^>]*>(.*?)<\/ns1:command>/is', $body, $m)) {
    $command = trim(html_entity_decode($m[1]));
}

error_log("[{$timestamp}] CMD: {$command}");

// ---- Process command ----
$resultMessage = '';

if (empty($command)) {
    $resultMessage = 'Empty command.';
} elseif (preg_match('/^account\s+create\s+(\S+)\s+(\S+)$/i', $command, $m)) {
    // account create {username} {password}
    $user = strtoupper($m[1]);
    if (isset($accounts[$user])) {
        $resultMessage = "Account with name {$m[1]} already exist!";
    } else {
        $accounts[$user] = [
            'username'  => $user,
            'password'  => $m[2],
            'expansion' => '0',
            'created'   => date('Y-m-d H:i:s'),
        ];
        file_put_contents($dataFile, json_encode($accounts, JSON_PRETTY_PRINT));
        $resultMessage = "Account created: {$m[1]}";
        error_log("[{$timestamp}] -> Created: {$user}");
    }
} elseif (preg_match('/^account\s+set\s+addon\s+(\S+)\s+(\d+)$/i', $command, $m)) {
    // account set addon {username} {expansion}
    $user = strtoupper($m[1]);
    if (!isset($accounts[$user])) {
        $resultMessage = "Account not exist: {$m[1]}";
    } else {
        $accounts[$user]['expansion'] = $m[2];
        file_put_contents($dataFile, json_encode($accounts, JSON_PRETTY_PRINT));
        $resultMessage = "Expansion set to {$m[2]} for account {$m[1]}.";
        error_log("[{$timestamp}] -> Addon: {$user} => {$m[2]}");
    }
} elseif (preg_match('/^account\s+set\s+password\s+(\S+)\s+(\S+)\s+(\S+)$/i', $command, $m)) {
    // account set password {username} {password} {password}
    $user = strtoupper($m[1]);
    if (!isset($accounts[$user])) {
        $resultMessage = "Account not exist: {$m[1]}";
    } elseif ($m[2] !== $m[3]) {
        $resultMessage = "Passwords do not match.";
    } else {
        $accounts[$user]['password'] = $m[2];
        file_put_contents($dataFile, json_encode($accounts, JSON_PRETTY_PRINT));
        $resultMessage = "The password for account {$m[1]} was changed to {$m[2]}.";
        error_log("[{$timestamp}] -> Password changed: {$user}");
    }
} elseif (preg_match('/^account\s+delete\s+(\S+)$/i', $command, $m)) {
    // account delete {username}
    $user = strtoupper($m[1]);
    if (!isset($accounts[$user])) {
        $resultMessage = "Account not exist: {$m[1]}";
    } else {
        unset($accounts[$user]);
        file_put_contents($dataFile, json_encode($accounts, JSON_PRETTY_PRINT));
        $resultMessage = "Account {$m[1]} deleted.";
        error_log("[{$timestamp}] -> Deleted: {$user}");
    }
} elseif (preg_match('/^account\s+list$/i', $command)) {
    // account list (custom debug command)
    if (empty($accounts)) {
        $resultMessage = "No accounts exist.";
    } else {
        $lines = ["Accounts (" . count($accounts) . "):"];
        foreach ($accounts as $u => $a) {
            $lines[] = "  - {$u} | exp={$a['expansion']} | pw={$a['password']} | created={$a['created']}";
        }
        $resultMessage = implode("\n", $lines);
    }
} elseif (preg_match('/^account\s+exists\s+(\S+)$/i', $command, $m)) {
    // account exists {username}
    $user = strtoupper($m[1]);
    if (isset($accounts[$user])) {
        $resultMessage = "Account exists: {$m[1]}";
    } else {
        $resultMessage = "Account not exist: {$m[1]}";
    }
} elseif (preg_match('/^server\s+info$/i', $command)) {
    $resultMessage = "Mock AzerothCore Server | Accounts: " . count($accounts) . " | Uptime: test mode";
} elseif (preg_match('/^help$/i', $command)) {
    $resultMessage = "Available commands:\n" .
        "  account create {user} {pass}\n" .
        "  account set addon {user} {exp}\n" .
        "  account set password {user} {pass} {pass}\n" .
        "  account delete {user}\n" .
        "  account list\n" .
        "  server info\n" .
        "  help";
} else {
    $resultMessage = "Unknown command: {$command}";
}

// ---- Build SOAP response ----
$responseXml = '<?xml version="1.0" encoding="UTF-8"?>' .
    '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"' .
    ' xmlns:ns1="urn:MaNGOS"' .
    ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"' .
    ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' .
    ' xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/"' .
    ' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">' .
    '<SOAP-ENV:Body>' .
    '<ns1:executeCommandResponse>' .
    '<result xsi:type="xsd:string">' . htmlspecialchars($resultMessage) . '</result>' .
    '</ns1:executeCommandResponse>' .
    '</SOAP-ENV:Body>' .
    '</SOAP-ENV:Envelope>';

// ---- Send response ----
header('Content-Type: text/xml; charset=utf-8');
header('Content-Length: ' . strlen($responseXml));
echo $responseXml;
