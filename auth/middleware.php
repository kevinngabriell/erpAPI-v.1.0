<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/JWTConfig.php';

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

    JWT::$leeway = JWT_LEEWAY_SECONDS;

    try {
        return JWT::decode($token, new Key(JWT_SECRET, JWT_ALGORITHM));
    } catch (ExpiredException $e) {
        http_response_code(401);
        echo json_encode([
            'StatusCode' => 401,
            'Status'     => 'Unauthorized',
            'message'    => 'Session expired. Please log in again.'
        ]);
        exit;
    } catch (SignatureInvalidException $e) {
        http_response_code(401);
        echo json_encode([
            'StatusCode' => 401,
            'Status'     => 'Unauthorized',
            'message'    => 'Invalid token signature'
        ]);
        exit;
    } catch (BeforeValidException $e) {
        error_log('JWT verify failed (clock skew): ' . $e->getMessage());
        http_response_code(401);
        echo json_encode([
            'StatusCode' => 401,
            'Status'     => 'Unauthorized',
            'message'    => 'Token not yet valid, please retry'
        ]);
        exit;
    } catch (Exception $e) {
        error_log('JWT verify failed (' . get_class($e) . '): ' . $e->getMessage());
        http_response_code(401);
        echo json_encode([
            'StatusCode' => 401,
            'Status'     => 'Unauthorized',
            'message'    => 'Invalid token'
        ]);
        exit;
    }
}
