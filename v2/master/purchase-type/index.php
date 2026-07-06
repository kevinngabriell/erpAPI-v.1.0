<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllPurchaseTypes($conn, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "deleted_at IS NULL";
    if ($search) {
        $where .= " AND type_name LIKE '%$search%'";
    }

    $result       = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".purchase_type WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".purchase_type WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $purchase_types = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($purchase_types as &$purchase_type) {
            $purchase_type['vat_applicable'] = (bool)(int)$purchase_type['vat_applicable'];
        }
        jsonResponse(200, 'Purchase types found', [
            'data'       => $purchase_types,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No purchase types found');
    }
}

function createPurchaseType($conn, $input, $username) {
    $required = ['type_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $type_name       = trim(mysqli_real_escape_string($conn, $input['type_name']));
    $vat_applicable  = isset($input['vat_applicable']) ? (!empty($input['vat_applicable']) ? 1 : 0) : 1;

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_type WHERE type_name = '$type_name' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Purchase type already exists');
        return;
    }

    $purchase_type_id = generateUUID();
    $now               = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".purchase_type (id, type_name, vat_applicable, created_by, created_at)
            VALUES ('$purchase_type_id', '$type_name', $vat_applicable, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Purchase type created successfully', ['purchase_type_id' => $purchase_type_id]);
    } else {
        jsonResponse(500, 'Failed to create purchase type', ['error' => mysqli_error($conn)]);
    }
}

function getDetailPurchaseType($conn, $purchase_type_id) {
    $purchase_type_id = mysqli_real_escape_string($conn, $purchase_type_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".purchase_type WHERE id = '$purchase_type_id' AND deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Purchase type not found');
        return;
    }

    $purchase_type = mysqli_fetch_assoc($result);
    $purchase_type['vat_applicable'] = (bool)(int)$purchase_type['vat_applicable'];

    jsonResponse(200, 'Purchase type found', $purchase_type);
}

function updatePurchaseType($conn, $purchase_type_id, $input, $username) {
    $purchase_type_id = mysqli_real_escape_string($conn, $purchase_type_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_type WHERE id = '$purchase_type_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase type not found');
        return;
    }

    $updates = [];

    if (isset($input['type_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['type_name']));
        if ($val === '') { jsonResponse(400, 'type_name cannot be empty'); return; }
        $updates[] = "type_name = '$val'";
    }

    if (isset($input['vat_applicable'])) {
        $vat_applicable = !empty($input['vat_applicable']) ? 1 : 0;
        $updates[] = "vat_applicable = $vat_applicable";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_type SET " . implode(', ', $updates) . " WHERE id = '$purchase_type_id'")) {
        jsonResponse(200, 'Purchase type updated successfully');
    } else {
        jsonResponse(500, 'Failed to update purchase type', ['error' => mysqli_error($conn)]);
    }
}

function deletePurchaseType($conn, $purchase_type_id, $username) {
    $purchase_type_id = mysqli_real_escape_string($conn, $purchase_type_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".purchase_type WHERE id = '$purchase_type_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Purchase type not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".purchase_type SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$purchase_type_id'")) {
        jsonResponse(200, 'Purchase type deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete purchase type', ['error' => mysqli_error($conn)]);
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

$purchase_type_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($purchase_type_id) {
        switch ($method) {
            case 'GET':
                getDetailPurchaseType($conn, $purchase_type_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updatePurchaseType($conn, $purchase_type_id, $input, $username);
                break;
            case 'DELETE':
                deletePurchaseType($conn, $purchase_type_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllPurchaseTypes($conn, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createPurchaseType($conn, $input, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
