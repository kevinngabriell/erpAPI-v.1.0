<?php
require_once __DIR__ . '/../config.php';

$GLOBALS['_request_id']      = '';
$GLOBALS['_request_start']   = microtime(true);
$GLOBALS['_request_endpoint'] = '';
$GLOBALS['_request_actor']    = null;
$GLOBALS['_request_exception'] = null;
$GLOBALS['_request_http_code'] = 200;
$GLOBALS['_request_error_code'] = null;
$GLOBALS['_request_error_message'] = null;

function generateRequestId(): string {
    $bytes = random_bytes(16);
    $hex = bin2hex($bytes);
    $time = time();
    $ts_hex = sprintf('%010x', $time);
    return substr($ts_hex, 0, 8) . '-' . substr($hex, 0, 4) . '-7' . substr($hex, 4, 3) . '-8' . substr($hex, 7, 3) . '-' . substr($hex, 10, 12);
}

function initRequestLogger(string $endpoint): void {
    $GLOBALS['_request_id']      = generateRequestId();
    $GLOBALS['_request_start']   = microtime(true);
    $GLOBALS['_request_endpoint'] = $endpoint;

    if (!headers_sent()) {
        header('X-Request-ID: ' . $GLOBALS['_request_id']);
    }

    register_shutdown_function('flushRequestLog');
}

function requestId(): string {
    return $GLOBALS['_request_id'];
}

function setRequestActor(?array $payload): void {
    $GLOBALS['_request_actor'] = $payload;
}

function setRequestOutcome(int $http_code, ?string $error_code = null, ?string $error_message = null): void {
    $GLOBALS['_request_http_code']    = $http_code;
    $GLOBALS['_request_error_code']   = $error_code;
    $GLOBALS['_request_error_message'] = $error_message;
}

function defaultErrorCode(int $code): string {
    return match (true) {
        $code === 400 => 'ERR_BAD_REQUEST',
        $code === 401 => 'ERR_AUTH',
        $code === 403 => 'ERR_FORBIDDEN',
        $code === 404 => 'ERR_NOT_FOUND',
        $code === 405 => 'ERR_METHOD_NOT_ALLOWED',
        $code === 409 => 'ERR_CONFLICT',
        $code === 422 => 'ERR_VALIDATION',
        $code === 429 => 'ERR_RATE_LIMIT',
        $code >= 500  => 'ERR_INTERNAL',
        default       => 'ERR_UNKNOWN',
    };
}

function setRequestException(Throwable $e): void {
    $GLOBALS['_request_exception'] = [
        'class'     => $e::class,
        'message'   => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
        'trace'     => $e->getTraceAsString(),
    ];
}

function flushRequestLog(): void {
    $duration_ms  = (int) round((microtime(true) - $GLOBALS['_request_start']) * 1000);
    $http_code    = $GLOBALS['_request_http_code'];
    $outcome      = $http_code < 400 ? 'success' : 'error';
    $level        = match (true) {
        $http_code >= 500 => 'error',
        $http_code >= 400 => 'warning',
        default           => 'info',
    };

    $actor        = $GLOBALS['_request_actor'] ?? [];
    $user_id      = $actor['user_id'] ?? $actor['sub'] ?? null;
    $username     = $actor['username'] ?? null;
    $company_id   = $actor['company_id'] ?? null;
    $app_id       = APP_ID;
    $ip           = getUserIP();
    $ua           = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $method       = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $endpoint     = substr($GLOBALS['_request_endpoint'], 0, 255);
    $request_id   = $GLOBALS['_request_id'];
    $client_rid   = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
    $error_code   = $GLOBALS['_request_error_code'];
    $error_msg    = $GLOBALS['_request_error_message'] ? mb_substr($GLOBALS['_request_error_message'], 0, 500) : null;
    $exc          = $GLOBALS['_request_exception'];
    $retryable    = $http_code >= 500 ? 0 : 1;
    $client_type  = 'unknown';
    $memory_peak  = (int) round(memory_get_peak_usage(true) / 1024);

    $exc_class    = $exc ? substr($exc['class'] ?? '', 0, 150) : null;
    $exc_message  = $exc ? $exc['message'] ?? null : null;
    $exc_file     = $exc ? substr($exc['file'] ?? '', 0, 255) : null;
    $exc_line     = $exc ? (int)($exc['line'] ?? 0) : null;
    $exc_trace    = $exc ? $exc['trace'] ?? null : null;

    try {
        $conn = getConn();

        $stmt = $conn->prepare(
            "INSERT INTO " . CORE_SCHEMA . ".log_request
                (request_id, client_request_id, timestamp, api_version, endpoint, http_method,
                 status_code, outcome, level, error_code, error_message, retryable,
                 client_type, username, app_id, company_id,
                 ip_address, user_agent, duration_ms, db_connected, memory_peak_kb,
                 exception_class, exception_message, exception_file, exception_line, stack_trace)
             VALUES
                (?, ?, NOW(3), 'v2', ?, ?,
                 ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?, 1, ?,
                 ?, ?, ?, ?, ?)"
        );

        if ($stmt) {
            $stmt->bind_param(
                'ssssissssissssssiisssis',
                $request_id,
                $client_rid,
                $endpoint,
                $method,
                $http_code,
                $outcome,
                $level,
                $error_code,
                $error_msg,
                $retryable,
                $client_type,
                $username,
                $app_id,
                $company_id,
                $ip,
                $ua,
                $duration_ms,
                $memory_peak,
                $exc_class,
                $exc_message,
                $exc_file,
                $exc_line,
                $exc_trace
            );
            if (!$stmt->execute()) {
                throw new Exception('log_request execute failed: [' . mysqli_errno($conn) . '] ' . mysqli_error($conn));
            }
            $stmt->close();
        } else {
            throw new Exception('log_request prepare failed: [' . mysqli_errno($conn) . '] ' . mysqli_error($conn));
        }
    } catch (Throwable $e) {
        $row = [
            'request_id'  => $request_id,
            'endpoint'    => $endpoint,
            'status_code' => $http_code,
            'error_code'  => $error_code,
            'duration_ms' => $duration_ms,
            'ip'          => $ip,
            'message'     => $exc_message ?? $error_msg,
            'log_error'   => $e->getMessage(),
        ];
        $line = json_encode($row, JSON_UNESCAPED_UNICODE);
        $date = date('Y-m-d');
        $dir  = __DIR__ . '/../storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents("$dir/request-$date.jsonl", $line . "\n", FILE_APPEND);
    }
}

function errorResponse(int $code, string $message, array $details = []): void {
    $request_id = requestId();

    $error_code = $GLOBALS['_request_error_code'] !== null
        ? $GLOBALS['_request_error_code']
        : defaultErrorCode($code);
    setRequestOutcome($code, $error_code, $message);

    if (!headers_sent()) {
        http_response_code($code);
        header('X-Request-ID: ' . $request_id);
    }

    $body = [
        'status_code'    => $code,
        'status_message' => $message,
        'data'           => [],
        'error_id'       => $request_id,
    ];

    if (!empty($details)) {
        $body['error'] = $details;
    }

    if (APP_ENV === 'development' && !empty($details['exception'])) {
        $body['debug'] = $details['exception'];
    }

    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function logException(Throwable $e, string $context = ''): array {
    setRequestException($e);

    $error_id = requestId();

    $details = [
        'exception' => [
            'class'   => $e::class,
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ],
    ];

    if ($context !== '') {
        $details['context'] = $context;
    }

    return $details;
}

function safeExceptionResponse(Throwable $e, string $context = ''): array {
    return logException($e, $context);
}

function handleDbError($conn, string $operation = ''): void {
    $error = mysqli_error($conn);
    $errno = mysqli_errno($conn);

    $request_id = requestId();

    $exception = new Exception("Database error during $operation: [$errno] $error");
    setRequestException($exception);

    $user_message = match (true) {
        $errno === 1062 => 'A record with this value already exists',
        $errno === 1452 => 'Referenced record does not exist',
        $errno === 1451 => 'Cannot delete: this record is referenced by other records',
        $errno === 1054 => 'Database column mismatch — contact support with error ID',
        $errno === 1146 => 'Database table not found — contact support with error ID',
        $errno === 2002, $errno === 2003, $errno === 2006 => 'Database connection lost — please try again',
        default => 'A database error occurred. Reference ID: ' . $request_id,
    };

    setRequestOutcome(500, 'ERR_DATABASE', $user_message);
    errorResponse(500, $user_message, [
        'db_errno' => $errno,
        'operation' => $operation,
    ]);
}

function handleModuleException(Throwable $e, string $module = ''): void {
    $details = logException($e, $module);

    $code = $e->getCode();
    $http_code = is_int($code) && $code >= 400 && $code < 600 ? $code : 500;

    $request_id = requestId();

    if ($http_code === 500) {
        $message = 'An unexpected error occurred in ' . ($module ?: 'the application') . '. Reference ID: ' . $request_id;
    } else {
        $message = $e->getMessage();
    }

    setRequestOutcome($http_code, 'ERR_MODULE', $message);
    errorResponse($http_code, $message, $details);
}
