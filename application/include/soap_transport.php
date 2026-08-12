<?php
/**
 * SOAP Transport Module
 *
 * PHP's built-in network functions (fsockopen, curl, SoapClient) are restricted
 * in this environment. This module communicates with the SOAP Bridge Python
 * server (soap_bridge.py) which has unrestricted network access.
 *
 * The bridge server runs locally on port 7999 and forwards SOAP commands
 * to the remote AzerothCore worldserver.
 *
 * Usage:
 *   require_once __DIR__ . '/soap_transport.php';
 *   $result = soap_send_command("account create USER PASS");
 */

/**
 * Get the SOAP bridge server URL.
 * Override with SOAP_BRIDGE_URL environment variable if needed.
 */
function soap_bridge_url()
{
    return getenv('SOAP_BRIDGE_URL') ?: 'http://127.0.0.1:7999';
}

/**
 * Execute a SOAP command via the Python bridge server.
 *
 * @param string $command  The SOAP command text
 * @return array ['success' => bool, 'message' => string]
 */
function soap_send_command($command)
{
    if (empty($command)) {
        return ['success' => false, 'message' => 'empty command'];
    }

    $url = soap_bridge_url() . '/';

    $postData = json_encode(['command' => $command]);

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $postData,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false || $response === '') {
        return ['success' => false, 'message' => 'SOAP Bridge unavailable'];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return ['success' => false, 'message' => 'Invalid bridge response'];
    }

    if (!empty($data['success'])) {
        $message = str_replace(["&#xD;", "&#xA;"], ["\r", "\n"], $data['message'] ?? '');
        return ['success' => true, 'message' => $message];
    }

    return ['success' => false, 'message' => $data['message'] ?? 'Unknown error'];
}

/**
 * Check if the SOAP bridge server is running and reachable.
 *
 * @return bool
 */
function soap_bridge_available()
{
    $context = stream_context_create([
        'http' => ['timeout' => 3, 'ignore_errors' => true],
    ]);

    $response = @file_get_contents(soap_bridge_url() . '/', false, $context);
    return $response !== false && strpos($response, 'SOAP Bridge') !== false;
}
