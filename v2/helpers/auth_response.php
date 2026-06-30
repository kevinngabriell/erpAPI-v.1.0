<?php

function authResponse(int $code, string $message, ?string $error_code = null, $data = null): void {
    http_response_code($code);
    $body = [
        'status_code'    => $code,
        'status_message' => $message,
        'data'           => $data,
    ];
    if ($error_code !== null) {
        $body['error_code'] = $error_code;
    }
    echo json_encode($body);
    exit;
}
