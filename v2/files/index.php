<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/object_storage.php';

const FILE_MAX_BYTES = 10 * 1024 * 1024;
const FILE_ALLOWED_EXT = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'csv' => 'text/csv',
    'txt' => 'text/plain',
];

// Keys are always "{company_id}/{folder}/{uuid}.{ext}" so a company can only ever
// touch its own objects.
function validOwnedKey(string $key, string $company_id): bool {
    return $key !== ''
        && !str_contains($key, '..')
        && str_starts_with($key, $company_id . '/');
}

function uploadFile(string $company_id): void {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, 'file is required (multipart/form-data field "file")');
        return;
    }
    $file = $_FILES['file'];
    if ($file['size'] > FILE_MAX_BYTES) {
        jsonResponse(400, 'File is too large (max 10 MB)');
        return;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset(FILE_ALLOWED_EXT[$ext])) {
        jsonResponse(400, 'File type is not allowed');
        return;
    }
    $folder = preg_replace('/[^a-z0-9_-]/', '', strtolower($_POST['folder'] ?? 'general')) ?: 'general';
    $key    = "$company_id/$folder/" . generateUUID() . ".$ext";

    s3PutObject($key, file_get_contents($file['tmp_name']), FILE_ALLOWED_EXT[$ext]);

    jsonResponse(201, 'File uploaded successfully', [
        'key'          => $key,
        'file_name'    => basename($file['name']),
        'file_size'    => (int)$file['size'],
        'content_type' => FILE_ALLOWED_EXT[$ext],
    ]);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

try {
    switch ($method) {
        case 'POST':
            uploadFile($company_id);
            break;
        case 'GET':
            $key = (string)($_GET['key'] ?? '');
            if (!validOwnedKey($key, $company_id)) { jsonResponse(400, 'A valid key is required'); break; }
            jsonResponse(200, 'File URL generated', ['url' => s3PresignGetUrl($key), 'expires_in' => 300]);
            break;
        case 'DELETE':
            $key = (string)($_GET['key'] ?? '');
            if (!validOwnedKey($key, $company_id)) { jsonResponse(400, 'A valid key is required'); break; }
            s3DeleteObject($key);
            jsonResponse(200, 'File deleted successfully');
            break;
        default:
            methodNotAllowed(['GET', 'POST', 'DELETE']);
    }
} catch (RuntimeException $e) {
    error_log('files endpoint: ' . $e->getMessage());
    jsonResponse(503, $e->getMessage());
}
