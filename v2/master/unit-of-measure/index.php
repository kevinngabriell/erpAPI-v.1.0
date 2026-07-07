<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllUnitOfMeasures($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "deleted_at IS NULL";
    if ($search) {
        $where .= " AND uom_name LIKE '%$search%'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".unit_of_measure WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".unit_of_measure WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Unit of measures found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No unit of measures found');
    }
}

function createUnitOfMeasure($conn, $input, $username) {
    $required = ['uom_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $uom_name = trim(mysqli_real_escape_string($conn, $input['uom_name']));

    $conversion_factor_sql = isset($input['conversion_factor']) && $input['conversion_factor'] !== ''
        ? (float)$input['conversion_factor']
        : 'NULL';

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".unit_of_measure WHERE uom_name = '$uom_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Unit of measure already exists');
        return;
    }

    $unit_of_measure_id = generateUUID();
    $now                = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".unit_of_measure (id, uom_name, conversion_factor, created_by, created_at)
            VALUES ('$unit_of_measure_id', '$uom_name', $conversion_factor_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Unit of measure created successfully', ['unit_of_measure_id' => $unit_of_measure_id]);
    } else {
        jsonResponse(500, 'Failed to create unit of measure', ['error' => mysqli_error($conn)]);
    }
}

function getDetailUnitOfMeasure($conn, $unit_of_measure_id) {
    $unit_of_measure_id = mysqli_real_escape_string($conn, $unit_of_measure_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".unit_of_measure WHERE id = '$unit_of_measure_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Unit of measure not found');
        return;
    }

    jsonResponse(200, 'Unit of measure found', mysqli_fetch_assoc($result));
}

function updateUnitOfMeasure($conn, $unit_of_measure_id, $input, $username) {
    $unit_of_measure_id = mysqli_real_escape_string($conn, $unit_of_measure_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".unit_of_measure WHERE id = '$unit_of_measure_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Unit of measure not found');
        return;
    }

    $updates = [];

    if (isset($input['uom_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['uom_name']));
        if ($val === '') { jsonResponse(400, 'uom_name cannot be empty'); return; }
        $updates[] = "uom_name = '$val'";
    }

    if (isset($input['conversion_factor'])) {
        $conversion_factor_sql = $input['conversion_factor'] !== '' ? (float)$input['conversion_factor'] : 'NULL';
        $updates[] = "conversion_factor = $conversion_factor_sql";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".unit_of_measure SET " . implode(', ', $updates) . " WHERE id = '$unit_of_measure_id'")) {
        jsonResponse(200, 'Unit of measure updated successfully');
    } else {
        jsonResponse(500, 'Failed to update unit of measure', ['error' => mysqli_error($conn)]);
    }
}

function deleteUnitOfMeasure($conn, $unit_of_measure_id, $username) {
    $unit_of_measure_id = mysqli_real_escape_string($conn, $unit_of_measure_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".unit_of_measure WHERE id = '$unit_of_measure_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Unit of measure not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".unit_of_measure SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$unit_of_measure_id'")) {
        jsonResponse(200, 'Unit of measure deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete unit of measure', ['error' => mysqli_error($conn)]);
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

$unit_of_measure_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($unit_of_measure_id) {
        switch ($method) {
            case 'GET':
                getDetailUnitOfMeasure($conn, $unit_of_measure_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateUnitOfMeasure($conn, $unit_of_measure_id, $input, $username);
                break;
            case 'DELETE':
                deleteUnitOfMeasure($conn, $unit_of_measure_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllUnitOfMeasures($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createUnitOfMeasure($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
