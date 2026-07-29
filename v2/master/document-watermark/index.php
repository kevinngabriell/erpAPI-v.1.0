<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllDocumentWatermarks($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = "dw.company_id = '$company_id' AND dw.deleted_at IS NULL";

    $from = APP_SCHEMA . ".document_watermark dw
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = dw.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = dw.updated_by";

    $result       = mysqli_query($conn, "SELECT dw.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY dw.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".document_watermark dw WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $document_watermarks = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($document_watermarks as &$document_watermark) {
            $document_watermark['watermark'] = base64_encode($document_watermark['watermark']);
        }
        jsonResponse(200, 'Document watermarks found', [
            'data'       => $document_watermarks,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No document watermarks found');
    }
}

function createDocumentWatermark($conn, $input, $username, $company_id) {
    $required = ['watermark'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $watermark = base64_decode($input['watermark'], true);
    if ($watermark === false) {
        jsonResponse(400, 'watermark must be a valid base64 encoded string');
        return;
    }

    $document_watermark_id = generateUUID();
    $now                   = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "INSERT INTO " . APP_SCHEMA . ".document_watermark (id, company_id, watermark, created_by, created_at) VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        jsonResponse(500, 'Failed to create document watermark', ['error' => mysqli_error($conn)]);
        return;
    }

    $null = null;
    $stmt->bind_param('sssss', $document_watermark_id, $company_id, $null, $username, $now);
    $stmt->send_long_data(2, $watermark);

    if ($stmt->execute()) {
        $stmt->close();
        jsonResponse(201, 'Document watermark created successfully', ['document_watermark_id' => $document_watermark_id]);
    } else {
        $error = $stmt->error;
        $stmt->close();
        jsonResponse(500, 'Failed to create document watermark', ['error' => $error]);
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
    $document_watermark['watermark'] = base64_encode($document_watermark['watermark']);

    jsonResponse(200, 'Document watermark found', $document_watermark);
}

function updateDocumentWatermark($conn, $document_watermark_id, $input, $username, $company_id) {
    $document_watermark_id = mysqli_real_escape_string($conn, $document_watermark_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".document_watermark WHERE id = '$document_watermark_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Document watermark not found');
        return;
    }

    if (!isset($input['watermark']) || trim((string)$input['watermark']) === '') {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $watermark = base64_decode($input['watermark'], true);
    if ($watermark === false) {
        jsonResponse(400, 'watermark must be a valid base64 encoded string');
        return;
    }

    $now = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "UPDATE " . APP_SCHEMA . ".document_watermark SET watermark = ?, updated_by = ?, updated_at = ? WHERE id = ? AND company_id = ?"
    );
    if (!$stmt) {
        jsonResponse(500, 'Failed to update document watermark', ['error' => mysqli_error($conn)]);
        return;
    }

    $null = null;
    $stmt->bind_param('sssss', $null, $username, $now, $document_watermark_id, $company_id);
    $stmt->send_long_data(0, $watermark);

    if ($stmt->execute()) {
        $stmt->close();
        jsonResponse(200, 'Document watermark updated successfully');
    } else {
        $error = $stmt->error;
        $stmt->close();
        jsonResponse(500, 'Failed to update document watermark', ['error' => $error]);
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
        jsonResponse(500, 'Failed to delete document watermark', ['error' => mysqli_error($conn)]);
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['user_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$document_watermark_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($document_watermark_id) {
        switch ($method) {
            case 'GET':
                getDetailDocumentWatermark($conn, $document_watermark_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateDocumentWatermark($conn, $document_watermark_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteDocumentWatermark($conn, $document_watermark_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllDocumentWatermarks($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createDocumentWatermark($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
