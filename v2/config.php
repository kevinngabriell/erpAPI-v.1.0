<?php
function loadEnv(string $path): void {
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, '"\'');
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

loadEnv(__DIR__ . '/.env');

date_default_timezone_set('Asia/Jakarta');

if (!defined('APP_ENV'))     define('APP_ENV',     getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'development'));
if (!defined('CORE_SCHEMA')) define('CORE_SCHEMA', $_ENV['CORE_SCHEMA'] ?? 'movira_core_dev');
if (!defined('APP_SCHEMA'))  define('APP_SCHEMA',  $_ENV['APP_SCHEMA']  ?? 'aluria_dev');
if (!defined('APP_ID'))      define('APP_ID',      $_ENV['APP_ID']      ?? '');

if (!defined('JWT_SECRET')) {
    $jwt_secret = $_ENV['JWT_SECRET'] ?? '';
    if ($jwt_secret === '' || strlen($jwt_secret) < 32) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'status_code'    => 500,
            'status_message' => 'JWT_SECRET must be configured with a minimum 32-character secret',
            'data'           => []
        ]);
        exit;
    }
    define('JWT_SECRET', $jwt_secret);
}

if (!defined('OTP_PEPPER')) {
    $otp_pepper = $_ENV['OTP_PEPPER'] ?? '';
    if ($otp_pepper === '' || $otp_pepper === 'fallback-pepper') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'status_code'    => 500,
            'status_message' => 'OTP_PEPPER must be configured in .env',
            'data'           => []
        ]);
        exit;
    }
    define('OTP_PEPPER', $otp_pepper);
}

if (!defined('WAHA_BASE_URL')) define('WAHA_BASE_URL', $_ENV['WAHA_BASE_URL'] ?? '');
if (!defined('WAHA_SESSION'))  define('WAHA_SESSION',  $_ENV['WAHA_SESSION']  ?? '');
if (!defined('WAHA_API_KEY'))  define('WAHA_API_KEY',  $_ENV['WAHA_API_KEY']  ?? '');

if (!defined('WS_PORT'))          define('WS_PORT',          (int)($_ENV['WS_PORT'] ?? 9502));
if (!defined('WS_PUBLISH_PORT'))  define('WS_PUBLISH_PORT',  (int)($_ENV['WS_PUBLISH_PORT'] ?? 9503));
if (!defined('APPROVAL_BASE_URL')) define('APPROVAL_BASE_URL', $_ENV['APPROVAL_BASE_URL'] ?? '');

if (!defined('SUMOPOD_BASE_URL'))       define('SUMOPOD_BASE_URL',       $_ENV['SUMOPOD_BASE_URL']       ?? 'https://api-pay.sumopod.com');
if (!defined('SUMOPOD_API_KEY'))        define('SUMOPOD_API_KEY',        $_ENV['SUMOPOD_API_KEY']        ?? '');
if (!defined('SUMOPOD_WEBHOOK_SECRET')) define('SUMOPOD_WEBHOOK_SECRET', $_ENV['SUMOPOD_WEBHOOK_SECRET'] ?? '');
if (!defined('SUMOPOD_SUCCESS_URL'))    define('SUMOPOD_SUCCESS_URL',    $_ENV['SUMOPOD_SUCCESS_URL']    ?? '');
if (!defined('SUMOPOD_CANCEL_URL'))     define('SUMOPOD_CANCEL_URL',     $_ENV['SUMOPOD_CANCEL_URL']     ?? '');
