<?php
require_once __DIR__ . '/general.php';

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = trim($uri, '/');
$parts  = explode('/', $uri);

// Expect: api / v2 / {module} / {action} [/ {sub_action} [/ {sub_id}]]
$prefix  = $parts[0] ?? '';
$version = $parts[1] ?? '';
$module  = $parts[2] ?? '';
$action  = $parts[3] ?? '';

if ($prefix !== 'api' || $version !== 'v2') {
    jsonResponse(404, 'Route not found');
}

switch ($module) {

    case 'account':
        $actionMap = [
            'login'            => 'login.php',
            'register'         => 'register.php',
            'send-otp'         => 'send-otp.php',
            'forgot-password'  => 'forgot-password.php',
            'reset-password'   => 'reset-password.php',
            'my-permissions'   => 'my-permissions.php',
        ];
        $file = isset($actionMap[$action]) ? __DIR__ . '/auth/' . $actionMap[$action] : null;
        if (!$file) jsonResponse(404, 'Route not found');
        require $file;
        break;

    case 'dashboard':
        require __DIR__ . '/dashboard/index.php';
        break;

    default:
        jsonResponse(404, 'Route not found');
}
