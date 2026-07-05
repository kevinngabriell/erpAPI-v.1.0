<?php

function isCommonPassword(string $password): bool {
    $lower = strtolower($password);
    $file  = __DIR__ . '/common_passwords.txt';
    if (file_exists($file)) {
        $list = array_map('trim', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        return in_array($lower, $list, true);
    }
    $common = [
        'password', 'password1', 'password123', '12345678', '123456789',
        '1234567890', 'qwerty123', 'iloveyou', 'admin123', 'welcome1',
        'monkey123', 'dragon123', 'master123', 'sunshine1', 'princess1',
        'letmein1', 'football', 'baseball', 'basketball', 'superman1',
        'shadow123', 'michael1', 'jessica1', 'charlie1', 'qwerty1234',
        'password12', 'abc123456', 'abc12345', 'password!', 'pass@word1',
    ];
    return in_array($lower, $common, true);
}

function validatePasswordPolicy(string $password, string $first_name, string $last_name, string $email): ?string {
    if (strlen($password) < 8 || strlen($password) > 128
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password)
        || !preg_match('/[!@#$%^&*()\-_=+\[\]{};\':",.<>?\/\\\\|]/', $password)
    ) {
        return 'Password must be at least 8 characters and contain uppercase, lowercase, number, and special character.';
    }
    if (isCommonPassword($password)) {
        return 'Password is too common. Please choose a more unique password.';
    }
    if ($first_name !== '' && stripos($password, $first_name) !== false) {
        return 'Password must not contain your name or email address.';
    }
    if ($last_name !== '' && stripos($password, $last_name) !== false) {
        return 'Password must not contain your name or email address.';
    }
    $email_local = explode('@', $email)[0] ?? '';
    if ($email_local !== '' && stripos($password, $email_local) !== false) {
        return 'Password must not contain your name or email address.';
    }
    return null;
}
