<?php
require_once __DIR__ . '/../helpers/error_handler.php';

function authResponse(int $code, string $message, ?string $error_code = null, $data = []): void {
    if ($error_code === null && $code >= 400) {
        $error_code = defaultErrorCode($code);
    }
    setRequestOutcome($code, $error_code, $message);

    if (!headers_sent()) {
        http_response_code($code);
        header('X-Request-ID: ' . requestId());
    }

    $body = [
        'status_code'    => $code,
        'status_message' => $message,
        'data'           => $data ?? [],
    ];

    if ($error_code !== null) {
        $body['error_code'] = $error_code;
    }

    if ($code >= 400) {
        $body['error_id'] = requestId();
    }

    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
