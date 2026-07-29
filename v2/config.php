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
if (!defined('JWT_SECRET'))  define('JWT_SECRET',  $_ENV['JWT_SECRET']  ?? '');
if (!defined('CORE_SCHEMA')) define('CORE_SCHEMA', $_ENV['CORE_SCHEMA'] ?? 'movira_core_dev');
if (!defined('APP_SCHEMA'))  define('APP_SCHEMA',  $_ENV['APP_SCHEMA']  ?? 'aluria_dev');
if (!defined('APP_ID'))      define('APP_ID',      $_ENV['APP_ID']      ?? '');

if (!defined('WAHA_BASE_URL')) define('WAHA_BASE_URL', $_ENV['WAHA_BASE_URL'] ?? '');
if (!defined('WAHA_SESSION'))  define('WAHA_SESSION',  $_ENV['WAHA_SESSION']  ?? '');
if (!defined('WAHA_API_KEY'))  define('WAHA_API_KEY',  $_ENV['WAHA_API_KEY']  ?? '');

if (!defined('WS_PORT'))          define('WS_PORT',          (int)($_ENV['WS_PORT'] ?? 9502));
if (!defined('WS_PUBLISH_PORT'))  define('WS_PUBLISH_PORT',  (int)($_ENV['WS_PUBLISH_PORT'] ?? 9503));
if (!defined('APPROVAL_BASE_URL')) define('APPROVAL_BASE_URL', $_ENV['APPROVAL_BASE_URL'] ?? '');
