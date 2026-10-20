<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/permission.php';

// Keys issued by POST /api/v2/files are "{company_id}/{folder}/{uuid}.{ext}"; a company may
// only reference its own objects. Mirrors validOwnedKey() in files/index.php.
function isOwnedFileKey(string $key, string $company_id): bool {
    return $key !== ''
        && strlen($key) <= 512
        && !str_contains($key, '..')
        && str_starts_with($key, $company_id . '/');
}

function getAllDocumentWatermarks($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(15, max(1, (int)($params['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;

    $where = "dw.company_id = '$company_id' AND dw.deleted_at IS NULL";

    $from = APP_SCHEMA . ".document_watermark dw
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = dw.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = dw.updated_by";

    $result       = mysqli_query($conn, "SELECT dw.id, dw.company_id, dw.watermark_name, dw.file_key, dw.created_by, dw.created_at, dw.updated_by, dw.updated_at,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY dw.created_at DESC LIMIT $limit OFFSET $offset");
    if (!$result) {
        jsonResponse(500, 'Failed to load document watermarks', safeDbError($conn));
        return;
    }
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".document_watermark dw WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    $data = mysqli_fetch_all($result, MYSQLI_ASSOC);

    jsonResponse(200, 'Document watermarks found', [
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function createDocumentWatermark($conn, $input, $username, $company_id) {
    if (!isset($input['watermark_name']) || trim((string)$input['watermark_name']) === '') {
        jsonResponse(400, 'watermark_name is required');
        return;
    }

    $watermark_name = trim((string)$input['watermark_name']);
    if (mb_strlen($watermark_name) > 255) {
        jsonResponse(400, 'watermark_name must be 255 characters or fewer');
        return;
    }

    // The image arrives either as object-storage `file_key` (new uploads, see /api/v2/files)
    // or as legacy inline base64 `watermark`. Exactly one is required.
    $file_key  = isset($input['file_key']) ? trim((string)$input['file_key']) : '';
    $has_b64   = isset($input['watermark']) && trim((string)$input['watermark']) !== '';
    $watermark = null;

    if ($file_key !== '') {
        if (!isOwnedFileKey($file_key, $company_id)) {
            jsonResponse(400, 'file_key is not a valid file of this company');
            return;
        }
    } elseif ($has_b64) {
        $watermark = base64_decode($input['watermark'], true);
        if ($watermark === false) {
            jsonResponse(400, 'watermark must be a valid base64 encoded string');
            return;
        }
    } else {
        jsonResponse(400, 'watermark or file_key is required');
        return;
    }

    $document_watermark_id = generateUUID();
    $now                   = date('Y-m-d H:i:s');
    $file_key_value        = $file_key !== '' ? $file_key : null;

    $stmt = $conn->prepare(
        "INSERT INTO " . APP_SCHEMA . ".document_watermark (id, company_id, watermark_name, watermark, file_key, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        jsonResponse(500, 'Failed to create document watermark', safeDbError($conn));
        return;
    }

    $null = null;
    $stmt->bind_param('sssbsss', $document_watermark_id, $company_id, $watermark_name, $null, $file_key_value, $username, $now);
    if ($watermark !== null) {
        $stmt->send_long_data(3, $watermark);
    }

    if ($stmt->execute()) {
        $stmt->close();
        jsonResponse(201, 'Document watermark created successfully', ['document_watermark_id' => $document_watermark_id]);
    } else {
        $stmt->close();
        jsonResponse(500, 'Failed to create document watermark', safeDbError($conn));
    }
}

function getDetailDocumentWatermark($conn, $document_watermark_id, $company_id) {
    $document_watermark_id = mysqli_real_escape_string($conn, $document_watermark_id);

    $from   = APP_SCHEMA . ".document_watermark dw
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = dw.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = dw.updated_by";
    $result = mysqli_query($conn, "SELECT dw.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE dw.id = '$document_watermark_id' AND dw.company_id = '$company_id' AND dw.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Document watermark not found');
        return;
    }

    $document_watermark = mysqli_fetch_assoc($result);
    // Rows created through object storage have no inline image (watermark is NULL, file_key set).
    $document_watermark['watermark'] = $document_watermark['watermark'] !== null && $document_watermark['watermark'] !== ''
        ? base64_encode($document_watermark['watermark'])
        : null;

    jsonResponse(200, 'Document watermark found', $document_watermark);
}

function updateDocumentWatermark($conn, $document_watermark_id, $input, $username, $company_id) {
    $document_watermark_id = mysqli_real_escape_string($conn, $document_watermark_id);

    $updates         = [];
    $values          = [];
    $watermark       = null;
    $watermark_index = null;

    if (isset($input['watermark_name'])) {
        $watermark_name = trim((string)$input['watermark_name']);
        if ($watermark_name === '') {
            jsonResponse(400, 'watermark_name cannot be empty');
            return;
        }
        if (mb_strlen($watermark_name) > 255) {
            jsonResponse(400, 'watermark_name must be 255 characters or fewer');
            return;
        }
        $updates[] = 'watermark_name = ?';
        $values[]  = $watermark_name;
    }

    $file_key = isset($input['file_key']) ? trim((string)$input['file_key']) : '';
    if ($file_key !== '') {
        if (!isOwnedFileKey($file_key, $company_id)) {
            jsonResponse(400, 'file_key is not a valid file of this company');
            return;
        }
        // Switching to object storage: drop any inline image so the two never disagree.
        $updates[] = 'file_key = ?';
        $values[]  = $file_key;
        $updates[] = 'watermark = NULL';
    } elseif (isset($input['watermark']) && trim((string)$input['watermark']) !== '') {
        $watermark = base64_decode($input['watermark'], true);
        if ($watermark === false) {
            jsonResponse(400, 'watermark must be a valid base64 encoded string');
            return;
        }
        $updates[]       = 'watermark = ?';
        $watermark_index = count($values);
        $values[]        = null;
        $updates[]       = 'file_key = NULL';
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".document_watermark WHERE id = '$document_watermark_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Document watermark not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    $updates[] = 'updated_by = ?';
    $values[]  = $username;
    $updates[] = 'updated_at = ?';
    $values[]  = $now;
    $values[]  = $document_watermark_id;
    $values[]  = $company_id;

    $stmt = $conn->prepare(
        "UPDATE " . APP_SCHEMA . ".document_watermark SET " . implode(', ', $updates) . " WHERE id = ? AND company_id = ?"
    );
    if (!$stmt) {
        jsonResponse(500, 'Failed to update document watermark', safeDbError($conn));
        return;
    }

    $types = str_repeat('s', count($values));
    if ($watermark_index !== null) {
        $types[$watermark_index] = 'b';
    }
    $stmt->bind_param($types, ...$values);
    if ($watermark_index !== null) {
        $stmt->send_long_data($watermark_index, $watermark);
    }

    if ($stmt->execute()) {
        $stmt->close();
        jsonResponse(200, 'Document watermark updated successfully');
    } else {
        $stmt->close();
        jsonResponse(500, 'Failed to update document watermark', safeDbError($conn));
    }
}

function deleteDocumentWatermark($conn, $document_watermark_id, $username, $company_id) {
    $document_watermark_id = mysqli_real_escape_string($conn, $document_watermark_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".document_watermark WHERE id = '$document_watermark_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Document watermark not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".document_watermark SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$document_watermark_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Document watermark deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete document watermark', safeDbError($conn));
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser    = requireAuth();
$method      = $_SERVER['REQUEST_METHOD'];
$company_id  = $authUser['company_id'] ?? null;
$username    = $authUser['sub'] ?? $authUser['user_id'] ?? null;
$app_role_id = $authUser['app_role_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$document_watermark_id = !empty($action) ? $action : null;

try {
    $conn = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $username = mysqli_real_escape_string($conn, $username);

    if ($document_watermark_id) {
        switch ($method) {
            case 'GET':
                getDetailDocumentWatermark($conn, $document_watermark_id, $company_id);
                break;
            case 'PUT':
                if (!userHasPermission($conn, $app_role_id, 'master.document_watermark.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateDocumentWatermark($conn, $document_watermark_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                if (!userHasPermission($conn, $app_role_id, 'master.document_watermark.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                deleteDocumentWatermark($conn, $document_watermark_id, $username, $company_id);
                break;
            default:
                methodNotAllowed(['GET', 'PUT', 'DELETE']);
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllDocumentWatermarks($conn, $company_id, $_GET);
                break;
            case 'POST':
                if (!userHasPermission($conn, $app_role_id, 'master.document_watermark.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createDocumentWatermark($conn, $input, $username, $company_id);
                break;
            default:
                methodNotAllowed(['GET', 'POST']);
        }
    }

} catch (Exception $e) {
    handleModuleException($e);
}
