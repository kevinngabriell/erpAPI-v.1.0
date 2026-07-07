<?php

require_once __DIR__ . '/JWTConfig.php';
require_once __DIR__ . '/../v2/helpers/jwt.php';

function verifyToken(): object {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');

    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode([
            'StatusCode' => 401,
            'Status'     => 'Unauthorized',
            'message'    => 'No token provided'
        ]);
        exit;
    }

    $token = substr($authHeader, 7);

    try {
        $payload = JWT::decode($token);
    } catch (Exception $e) {
        $known = [
            'Token expired'                          => 'Session expired. Please log in again.',
            'Invalid token signature'                 => 'Invalid token signature',
        ];
        $message = $known[$e->getMessage()] ?? 'Invalid token';
        if (!isset($known[$e->getMessage()])) {
            error_log('JWT verify failed: ' . $e->getMessage());
        }
        http_response_code(401);
        echo json_encode([
            'StatusCode' => 401,
            'Status'     => 'Unauthorized',
            'message'    => $message
        ]);
        exit;
    }

    // Legacy modules read these camelCase/short field names; alias them onto the
    // current token shape (user_id/username/company_id) so callers don't change.
    $payload['sub']       = $payload['sub']       ?? ($payload['username'] ?? $payload['user_id'] ?? '');
    $payload['companyId'] = $payload['companyId'] ?? ($payload['company_id'] ?? '');

    return (object) $payload;
}
