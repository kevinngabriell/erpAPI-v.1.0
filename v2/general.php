<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/error_handler.php';

// Production CORS is handled at the web server (nginx/Apache) level.
if (APP_ENV === 'development') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Request-ID');
    header('Access-Control-Expose-Headers: X-Request-ID');
    header('Access-Control-Max-Age: 86400');

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

header('Content-Type: application/json');
header('X-API-Version: 2.0');

require_once __DIR__ . '/connection/db.php';
require_once __DIR__ . '/helpers/jwt.php';

$conn = getConn();

$uri_for_log = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
initRequestLogger($uri_for_log);

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof Exception && ($e->getCode() === 401 || $e->getCode() === 403)) {
        $http_code = $e->getCode() < 400 ? 500 : $e->getCode();
        setRequestException($e);
        setRequestOutcome($http_code, $http_code === 401 ? 'ERR_AUTH' : 'ERR_FORBIDDEN', $e->getMessage());
        errorResponse($http_code, $e->getMessage());
    }
    setRequestException($e);
    setRequestOutcome(500, 'ERR_INTERNAL', $e->getMessage());
    errorResponse(500, 'An unexpected error occurred. Reference ID: ' . requestId(), [
        'exception' => [
            'class'   => $e::class,
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ],
    ]);
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function jsonResponse($code, $message, $data = []): void {
    if ($code >= 400) {
        $error_code = $GLOBALS['_request_error_code'] !== null
            ? $GLOBALS['_request_error_code']
            : defaultErrorCode($code);
        setRequestOutcome($code, $error_code, $message);
    } else {
        setRequestOutcome($code);
    }

    if (!headers_sent()) {
        http_response_code($code);
        header('X-Request-ID: ' . requestId());
    }

    $body = [
        'status_code'    => $code,
        'status_message' => $message,
        'data'           => $data,
    ];

    if ($code >= 400) {
        $body['error_id'] = requestId();
    }

    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanInput(string $value): string {
    global $conn;
    return mysqli_real_escape_string($conn, trim($value));
}

function input(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function requireAuth(): array {
    try {
        $payload = JWT::fromRequest();
        setRequestActor($payload);
        return $payload;
    } catch (Exception $e) {
        $code = $e->getCode();
        $http_code = is_int($code) && $code >= 400 && $code < 600 ? $code : 401;
        setRequestException($e);
        setRequestOutcome($http_code, 'ERR_AUTH', $e->getMessage());
        errorResponse($http_code, $e->getMessage());
    }
}

function generateUUID(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function getUserIP(): string {
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
    $trusted_proxies = ['127.0.0.1', '::1'];
    if (in_array($remote_addr, $trusted_proxies, true)) {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            return $parts[0];
        }
    }
    return $remote_addr;
}

function methodNotAllowed(array $allowed): void {
    header('Allow: ' . implode(', ', $allowed));
    jsonResponse(405, 'Method Not Allowed');
}

function safeErrorResponse(Exception $e): array {
    return logException($e);
}

function safeDbError($conn): array {
    $error = mysqli_error($conn);
    $errno = mysqli_errno($conn);
    if ($error !== '') {
        $exception = new Exception("Database error: [$errno] $error");
        setRequestException($exception);
        setRequestOutcome($GLOBALS['_request_http_code'], 'ERR_DATABASE', $exception->getMessage());
    }
    return APP_ENV === 'development' ? ['db_error' => $error, 'db_errno' => $errno] : [];
}
