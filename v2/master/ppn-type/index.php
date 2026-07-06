<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllPpnTypes($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "deleted_at IS NULL";
    if ($search) {
        $where .= " AND ppn_name LIKE '%$search%'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".ppn_type WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".ppn_type WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Ppn types found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No ppn types found');
    }
}

function createPpnType($conn, $input, $username) {
    $required = ['ppn_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $ppn_name = trim(mysqli_real_escape_string($conn, $input['ppn_name']));

    $ppn_percentage_sql = isset($input['ppn_percentage']) && $input['ppn_percentage'] !== ''
        ? (float)$input['ppn_percentage']
        : 'NULL';

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".ppn_type WHERE ppn_name = '$ppn_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Ppn type already exists');
        return;
    }

    $ppn_type_id = generateUUID();
    $now         = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".ppn_type (id, ppn_name, ppn_percentage, created_by, created_at)
            VALUES ('$ppn_type_id', '$ppn_name', $ppn_percentage_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Ppn type created successfully', ['ppn_type_id' => $ppn_type_id]);
    } else {
        jsonResponse(500, 'Failed to create ppn type', ['error' => mysqli_error($conn)]);
    }
}

function getDetailPpnType($conn, $ppn_type_id) {
    $ppn_type_id = mysqli_real_escape_string($conn, $ppn_type_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".ppn_type WHERE id = '$ppn_type_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Ppn type not found');
        return;
    }

    jsonResponse(200, 'Ppn type found', mysqli_fetch_assoc($result));
}

function updatePpnType($conn, $ppn_type_id, $input, $username) {
    $ppn_type_id = mysqli_real_escape_string($conn, $ppn_type_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".ppn_type WHERE id = '$ppn_type_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Ppn type not found');
        return;
    }

    $updates = [];

    if (isset($input['ppn_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['ppn_name']));
        if ($val === '') { jsonResponse(400, 'ppn_name cannot be empty'); return; }
        $updates[] = "ppn_name = '$val'";
    }

    if (isset($input['ppn_percentage'])) {
        $ppn_percentage_sql = $input['ppn_percentage'] !== '' ? (float)$input['ppn_percentage'] : 'NULL';
        $updates[] = "ppn_percentage = $ppn_percentage_sql";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".ppn_type SET " . implode(', ', $updates) . " WHERE id = '$ppn_type_id'")) {
        jsonResponse(200, 'Ppn type updated successfully');
    } else {
        jsonResponse(500, 'Failed to update ppn type', ['error' => mysqli_error($conn)]);
    }
}

function deletePpnType($conn, $ppn_type_id, $username) {
    $ppn_type_id = mysqli_real_escape_string($conn, $ppn_type_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".ppn_type WHERE id = '$ppn_type_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Ppn type not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".ppn_type SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$ppn_type_id'")) {
        jsonResponse(200, 'Ppn type deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete ppn type', ['error' => mysqli_error($conn)]);
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

$ppn_type_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($ppn_type_id) {
        switch ($method) {
            case 'GET':
                getDetailPpnType($conn, $ppn_type_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updatePpnType($conn, $ppn_type_id, $input, $username);
                break;
            case 'DELETE':
                deletePpnType($conn, $ppn_type_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllPpnTypes($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createPpnType($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
