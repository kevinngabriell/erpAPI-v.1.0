<?php

if (!defined('APP_ENV')) {
    define('APP_ENV', $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development');
}

if (APP_ENV === 'development') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Error reporting
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function logApiError(int $httpStatus, string $message, string $file = '', int $line = 0): void {
    $conn = $GLOBALS['_log_conn'] ?? null;
    if (!$conn) return;

    $level     = $httpStatus >= 500 ? 'critical' : ($httpStatus === 404 ? 'warning' : 'error');
    $errorId   = bin2hex(random_bytes(16));
    $endpoint  = mysqli_real_escape_string($conn, $_SERVER['REQUEST_URI'] ?? '');
    $method    = mysqli_real_escape_string($conn, $_SERVER['REQUEST_METHOD'] ?? '');
    $msg       = mysqli_real_escape_string($conn, $message);
    $fileSafe  = mysqli_real_escape_string($conn, $file);
    $userId    = mysqli_real_escape_string($conn, $GLOBALS['_log_user'] ?? '');
    $companyId = mysqli_real_escape_string($conn, $GLOBALS['_log_company_id'] ?? '');
    $requestId = mysqli_real_escape_string($conn, $GLOBALS['_log_request_id'] ?? '');
    $ip        = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR'] ?? '');
    $ua        = mysqli_real_escape_string($conn, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));

    mysqli_query($conn,
        "INSERT INTO api_error_log
         (error_id, error_level, http_status, endpoint, method, error_message,
          file, line, user_identifier, company_id, request_id, ip_address, user_agent)
         VALUES ('$errorId','$level',$httpStatus,'$endpoint','$method','$msg',
                 '$fileSafe',$line,'$userId','$companyId','$requestId','$ip','$ua')"
    );
}

function jsonResponse(int $code, string $message, mixed $data = null): void {
    if ($code >= 400) {
        $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $trace[1] ?? $trace[0];
        logApiError($code, $message, $caller['file'] ?? '', (int)($caller['line'] ?? 0));
    }
    http_response_code($code);
    header('Content-Type: application/json');
    $response = ['StatusCode' => $code, 'Status' => $message];
    if ($data !== null) $response['Data'] = $data;
    echo json_encode($response);
    exit;
}

function getCurrentDateTimeJakarta(): string {
    $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    return $dt->format('Y-m-d H:i:s');
}

function normalizeDate(string $dateStr): string {
    if (empty($dateStr)) return '';
    try {
        $dt = new DateTime($dateStr);
        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return substr($dateStr, 0, 10);
    }
}

function cleanInput($conn, $value, string $default = '-', array $rejects = ['0', '']): string {
    if (!isset($value) || in_array(trim((string)$value), $rejects, true)) {
        return $default;
    }
    return mysqli_real_escape_string($conn, trim((string)$value));
}

function generateUUID(): string {
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}