<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllDocumentCenters($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "company_id = '$company_id' AND deleted_at IS NULL";
    if ($search) {
        $where .= " AND document_name LIKE '%$search%'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".document_center WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".document_center WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Documents found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No documents found');
    }
}

function createDocumentCenter($conn, $input, $username, $company_id) {
    $required = ['document_name', 'document_file_size'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $document_name      = trim(mysqli_real_escape_string($conn, $input['document_name']));
    $document_file_size = (float)$input['document_file_size'];

    $document_center_id = generateUUID();
    $now                = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".document_center (id, company_id, document_name, document_file_size, created_by, created_at)
            VALUES ('$document_center_id', '$company_id', '$document_name', $document_file_size, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Document created successfully', ['document_center_id' => $document_center_id]);
    } else {
        jsonResponse(500, 'Failed to create document', ['error' => mysqli_error($conn)]);
    }
}

function getDetailDocumentCenter($conn, $document_center_id, $company_id) {
    $document_center_id = mysqli_real_escape_string($conn, $document_center_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".document_center WHERE id = '$document_center_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Document not found');
        return;
    }

    jsonResponse(200, 'Document found', mysqli_fetch_assoc($result));
}

function updateDocumentCenter($conn, $document_center_id, $input, $username, $company_id) {
    $document_center_id = mysqli_real_escape_string($conn, $document_center_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".document_center WHERE id = '$document_center_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Document not found');
        return;
    }

    $updates = [];

    if (isset($input['document_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['document_name']));
        if ($val === '') { jsonResponse(400, 'document_name cannot be empty'); return; }
        $updates[] = "document_name = '$val'";
    }

    if (isset($input['document_file_size'])) {
        $document_file_size = (float)$input['document_file_size'];
        $updates[] = "document_file_size = $document_file_size";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".document_center SET " . implode(', ', $updates) . " WHERE id = '$document_center_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Document updated successfully');
    } else {
        jsonResponse(500, 'Failed to update document', ['error' => mysqli_error($conn)]);
    }
}

function deleteDocumentCenter($conn, $document_center_id, $username, $company_id) {
    $document_center_id = mysqli_real_escape_string($conn, $document_center_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".document_center WHERE id = '$document_center_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Document not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".document_center SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$document_center_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Document deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete document', ['error' => mysqli_error($conn)]);
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

$document_center_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($document_center_id) {
        switch ($method) {
            case 'GET':
                getDetailDocumentCenter($conn, $document_center_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateDocumentCenter($conn, $document_center_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteDocumentCenter($conn, $document_center_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllDocumentCenters($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createDocumentCenter($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
