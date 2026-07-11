<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';

function getAllCompanySettingMenus($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "csm.company_id = '$company_id' AND csm.deleted_at IS NULL";
    if ($search) {
        $where .= " AND csm.setting_name LIKE '%$search%'";
    }

    $from = APP_SCHEMA . ".company_setting_menu csm
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = csm.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = csm.updated_by";

    $result       = mysqli_query($conn, "SELECT csm.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY csm.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".company_setting_menu csm WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $company_setting_menus = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($company_setting_menus as &$company_setting_menu) {
            $company_setting_menu['setting_image'] = $company_setting_menu['setting_image'] !== null
                ? base64_encode($company_setting_menu['setting_image'])
                : null;
        }
        jsonResponse(200, 'Company setting menus found', [
            'data'       => $company_setting_menus,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No company setting menus found');
    }
}

function createCompanySettingMenu($conn, $input, $username, $company_id) {
    $required = ['setting_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $setting_name = trim(mysqli_real_escape_string($conn, $input['setting_name']));

    $setting_caption_sql = isset($input['setting_caption']) && trim($input['setting_caption']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['setting_caption'])) . "'"
        : 'NULL';

    $setting_image = null;
    if (isset($input['setting_image']) && trim((string)$input['setting_image']) !== '') {
        $setting_image = base64_decode($input['setting_image'], true);
        if ($setting_image === false) {
            jsonResponse(400, 'setting_image must be a valid base64 encoded string');
            return;
        }
    }

    $company_setting_menu_id = generateUUID();
    $now                     = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "INSERT INTO " . APP_SCHEMA . ".company_setting_menu (id, company_id, setting_image, setting_name, setting_caption, created_by, created_at)
         VALUES (?, ?, ?, ?, " . $setting_caption_sql . ", ?, ?)"
    );
    if (!$stmt) {
        jsonResponse(500, 'Failed to create company setting menu', ['error' => mysqli_error($conn)]);
        return;
    }

    $stmt->bind_param('sssss', $company_setting_menu_id, $company_id, $setting_image, $setting_name, $username, $now);

    if ($stmt->execute()) {
        $stmt->close();
        jsonResponse(201, 'Company setting menu created successfully', ['company_setting_menu_id' => $company_setting_menu_id]);
    } else {
        $error = $stmt->error;
        $stmt->close();
        jsonResponse(500, 'Failed to create company setting menu', ['error' => $error]);
    }
}

function getDetailCompanySettingMenu($conn, $company_setting_menu_id, $company_id) {
    $company_setting_menu_id = mysqli_real_escape_string($conn, $company_setting_menu_id);

    $from   = APP_SCHEMA . ".company_setting_menu csm
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = csm.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = csm.updated_by";
    $result = mysqli_query($conn, "SELECT csm.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE csm.id = '$company_setting_menu_id' AND csm.company_id = '$company_id' AND csm.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Company setting menu not found');
        return;
    }

    $company_setting_menu = mysqli_fetch_assoc($result);
    $company_setting_menu['setting_image'] = $company_setting_menu['setting_image'] !== null
        ? base64_encode($company_setting_menu['setting_image'])
        : null;

    jsonResponse(200, 'Company setting menu found', $company_setting_menu);
}

function updateCompanySettingMenu($conn, $company_setting_menu_id, $input, $username, $company_id) {
    $company_setting_menu_id = mysqli_real_escape_string($conn, $company_setting_menu_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".company_setting_menu WHERE id = '$company_setting_menu_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Company setting menu not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (array_key_exists('setting_image', $input)) {
        $setting_image = null;
        if ($input['setting_image'] !== null && trim((string)$input['setting_image']) !== '') {
            $setting_image = base64_decode($input['setting_image'], true);
            if ($setting_image === false) {
                jsonResponse(400, 'setting_image must be a valid base64 encoded string');
                return;
            }
        }

        $setting_name_sql = isset($input['setting_name'])
            ? "'" . trim(mysqli_real_escape_string($conn, $input['setting_name'])) . "'"
            : 'setting_name';

        $setting_caption_sql = array_key_exists('setting_caption', $input)
            ? (isset($input['setting_caption']) && trim($input['setting_caption']) !== ''
                ? "'" . mysqli_real_escape_string($conn, trim($input['setting_caption'])) . "'"
                : 'NULL')
            : 'setting_caption';

        if (isset($input['setting_name']) && trim($input['setting_name']) === '') {
            jsonResponse(400, 'setting_name cannot be empty');
            return;
        }

        $stmt = $conn->prepare(
            "UPDATE " . APP_SCHEMA . ".company_setting_menu
             SET setting_image = ?, setting_name = $setting_name_sql, setting_caption = $setting_caption_sql, updated_by = ?, updated_at = ?
             WHERE id = ? AND company_id = ?"
        );
        if (!$stmt) {
            jsonResponse(500, 'Failed to update company setting menu', ['error' => mysqli_error($conn)]);
            return;
        }

        $stmt->bind_param('sssss', $setting_image, $username, $now, $company_setting_menu_id, $company_id);

        if ($stmt->execute()) {
            $stmt->close();
            jsonResponse(200, 'Company setting menu updated successfully');
        } else {
            $error = $stmt->error;
            $stmt->close();
            jsonResponse(500, 'Failed to update company setting menu', ['error' => $error]);
        }
        return;
    }

    $updates = [];

    if (isset($input['setting_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['setting_name']));
        if ($val === '') { jsonResponse(400, 'setting_name cannot be empty'); return; }
        $updates[] = "setting_name = '$val'";
    }

    if (array_key_exists('setting_caption', $input)) {
        $val = isset($input['setting_caption']) && trim($input['setting_caption']) !== ''
            ? "'" . mysqli_real_escape_string($conn, trim($input['setting_caption'])) . "'"
            : 'NULL';
        $updates[] = "setting_caption = $val";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".company_setting_menu SET " . implode(', ', $updates) . " WHERE id = '$company_setting_menu_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Company setting menu updated successfully');
    } else {
        jsonResponse(500, 'Failed to update company setting menu', ['error' => mysqli_error($conn)]);
    }
}

function deleteCompanySettingMenu($conn, $company_setting_menu_id, $username, $company_id) {
    $company_setting_menu_id = mysqli_real_escape_string($conn, $company_setting_menu_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".company_setting_menu WHERE id = '$company_setting_menu_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Company setting menu not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".company_setting_menu SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$company_setting_menu_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Company setting menu deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete company setting menu', ['error' => mysqli_error($conn)]);
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

$company_setting_menu_id = !empty($action) ? $action : null;
$sub_action              = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($company_setting_menu_id && $sub_action === 'details') {
        require __DIR__ . '/details.php';
    } elseif ($company_setting_menu_id) {
        switch ($method) {
            case 'GET':
                getDetailCompanySettingMenu($conn, $company_setting_menu_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateCompanySettingMenu($conn, $company_setting_menu_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteCompanySettingMenu($conn, $company_setting_menu_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllCompanySettingMenus($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createCompanySettingMenu($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
