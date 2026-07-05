<?php
require_once __DIR__ . '/../config.php';

function sendWhatsAppText($chatId, $text, $session = WAHA_SESSION) {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'curl_init MISSING'];
    }

    $url = rtrim(WAHA_BASE_URL, '/') . '/api/sendText';

    $payload = [
        'chatId'  => $chatId,   // e.g. "6281234567890@c.us"
        'text'    => $text,
        'session' => $session,
    ];

    $ch = curl_init($url);

    $headers = ['Content-Type: application/json'];
    if (!empty(WAHA_API_KEY)) {
        $headers[] = 'X-Api-Key: ' . WAHA_API_KEY;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
    ]);

    $responseBody = curl_exec($ch);
    $errno        = curl_errno($ch);
    $error        = curl_error($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        error_log('WAHA CURL error: ' . $error);
        return [
            'success'  => false,
            'httpCode' => $httpCode,
            'error'    => $error,
            'raw'      => $responseBody,
        ];
    }

    return [
        'success'  => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'data'     => json_decode($responseBody, true),
        'raw'      => $responseBody,
    ];
}

// Sanitizes an Indonesian phone number (handles +62/08 formats, spaces, dashes)
// into the "<digits>@c.us" chatId WAHA expects for an individual chat.
function buildWhatsAppChatId(string $rawPhone): string {
    $digits = preg_replace('/[^0-9]/', '', $rawPhone);
    if (str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    }
    return $digits . '@c.us';
}
