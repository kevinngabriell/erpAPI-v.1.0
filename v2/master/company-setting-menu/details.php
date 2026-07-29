<?php

function verifySettingMenu($conn, $setting_menu_id, $company_id) {
    $setting_menu_id = mysqli_real_escape_string($conn, $setting_menu_id);
    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".company_setting_menu WHERE id = '$setting_menu_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    return $check && mysqli_num_rows($check) > 0;
}

function getAllCompanySettingDetails($conn, $setting_menu_id, $company_id, $params) {
    if (!verifySettingMenu($conn, $setting_menu_id, $company_id)) {
        jsonResponse(404, 'Setting menu not found');
        return;
    }

    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $setting_menu_id = mysqli_real_escape_string($conn, $setting_menu_id);
    $where = "csd.setting_menu_id = '$setting_menu_id' AND csd.deleted_at IS NULL";

    $from = APP_SCHEMA . ".company_setting_detail csd
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = csd.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = csd.updated_by";

    $result       = mysqli_query($conn, "SELECT csd.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE $where ORDER BY csd.created_at DESC LIMIT $limit OFFSET $offset");
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".company_setting_detail csd WHERE $where");
    $total        = $count_result ? (int)mysqli_fetch_assoc($count_result)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $company_setting_details = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($company_setting_details as &$company_setting_detail) {
            $company_setting_detail['is_tab']     = (bool)(int)$company_setting_detail['is_tab'];
            $company_setting_detail['is_data']    = (bool)(int)$company_setting_detail['is_data'];
            $company_setting_detail['is_can_new'] = (bool)(int)$company_setting_detail['is_can_new'];
        }
        jsonResponse(200, 'Company setting details found', [
            'data'       => $company_setting_details,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No company setting details found');
    }
}

function createCompanySettingDetail($conn, $setting_menu_id, $input, $username, $company_id) {
    if (!verifySettingMenu($conn, $setting_menu_id, $company_id)) {
        jsonResponse(404, 'Setting menu not found');
        return;
    }

    $required = ['data_url'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $setting_menu_id = mysqli_real_escape_string($conn, $setting_menu_id);
    $data_url        = trim(mysqli_real_escape_string($conn, $input['data_url']));

    $is_tab     = !empty($input['is_tab']) ? 1 : 0;
    $tab_count  = isset($input['tab_count']) ? (int)$input['tab_count'] : 0;
    $is_data    = !empty($input['is_data']) ? 1 : 0;
    $is_can_new = !empty($input['is_can_new']) ? 1 : 0;

    $company_setting_detail_id = generateUUID();
    $now                       = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".company_setting_detail
            (id, setting_menu_id, is_tab, tab_count, is_data, data_url, is_can_new, created_by, created_at)
            VALUES ('$company_setting_detail_id', '$setting_menu_id', $is_tab, $tab_count, $is_data, '$data_url', $is_can_new, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Company setting detail created successfully', ['company_setting_detail_id' => $company_setting_detail_id]);
    } else {
        jsonResponse(500, 'Failed to create company setting detail', ['error' => mysqli_error($conn)]);
    }
}

function getDetailCompanySettingDetail($conn, $setting_menu_id, $company_setting_detail_id, $company_id) {
    if (!verifySettingMenu($conn, $setting_menu_id, $company_id)) {
        jsonResponse(404, 'Setting menu not found');
        return;
    }

    $setting_menu_id           = mysqli_real_escape_string($conn, $setting_menu_id);
    $company_setting_detail_id = mysqli_real_escape_string($conn, $company_setting_detail_id);

    $from   = APP_SCHEMA . ".company_setting_detail csd
            LEFT JOIN " . CORE_SCHEMA . ".app_user cu ON cu.user_id COLLATE utf8mb4_general_ci = csd.created_by
            LEFT JOIN " . CORE_SCHEMA . ".app_user uu ON uu.user_id COLLATE utf8mb4_general_ci = csd.updated_by";
    $result = mysqli_query($conn, "SELECT csd.*,
            CONCAT(cu.first_name, ' ', cu.last_name) AS created_by,
            CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by
            FROM $from WHERE csd.id = '$company_setting_detail_id' AND csd.setting_menu_id = '$setting_menu_id' AND csd.deleted_at IS NULL LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Company setting detail not found');
        return;
    }

    $company_setting_detail = mysqli_fetch_assoc($result);
    $company_setting_detail['is_tab']     = (bool)(int)$company_setting_detail['is_tab'];
    $company_setting_detail['is_data']    = (bool)(int)$company_setting_detail['is_data'];
    $company_setting_detail['is_can_new'] = (bool)(int)$company_setting_detail['is_can_new'];

    jsonResponse(200, 'Company setting detail found', $company_setting_detail);
}

function updateCompanySettingDetail($conn, $setting_menu_id, $company_setting_detail_id, $input, $username, $company_id) {
    if (!verifySettingMenu($conn, $setting_menu_id, $company_id)) {
        jsonResponse(404, 'Setting menu not found');
        return;
    }

    $setting_menu_id           = mysqli_real_escape_string($conn, $setting_menu_id);
    $company_setting_detail_id = mysqli_real_escape_string($conn, $company_setting_detail_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".company_setting_detail WHERE id = '$company_setting_detail_id' AND setting_menu_id = '$setting_menu_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Company setting detail not found');
        return;
    }

    $updates = [];

    if (isset($input['is_tab'])) {
        $is_tab = !empty($input['is_tab']) ? 1 : 0;
        $updates[] = "is_tab = $is_tab";
    }

    if (isset($input['tab_count'])) {
        $tab_count = (int)$input['tab_count'];
        $updates[] = "tab_count = $tab_count";
    }

    if (isset($input['is_data'])) {
        $is_data = !empty($input['is_data']) ? 1 : 0;
        $updates[] = "is_data = $is_data";
    }

    if (isset($input['data_url'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['data_url']));
        if ($val === '') { jsonResponse(400, 'data_url cannot be empty'); return; }
        $updates[] = "data_url = '$val'";
    }

    if (isset($input['is_can_new'])) {
        $is_can_new = !empty($input['is_can_new']) ? 1 : 0;
        $updates[] = "is_can_new = $is_can_new";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".company_setting_detail SET " . implode(', ', $updates) . " WHERE id = '$company_setting_detail_id' AND setting_menu_id = '$setting_menu_id'")) {
        jsonResponse(200, 'Company setting detail updated successfully');
    } else {
        jsonResponse(500, 'Failed to update company setting detail', ['error' => mysqli_error($conn)]);
    }
}

function deleteCompanySettingDetail($conn, $setting_menu_id, $company_setting_detail_id, $username, $company_id) {
    if (!verifySettingMenu($conn, $setting_menu_id, $company_id)) {
        jsonResponse(404, 'Setting menu not found');
        return;
    }

    $setting_menu_id           = mysqli_real_escape_string($conn, $setting_menu_id);
    $company_setting_detail_id = mysqli_real_escape_string($conn, $company_setting_detail_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".company_setting_detail WHERE id = '$company_setting_detail_id' AND setting_menu_id = '$setting_menu_id' AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Company setting detail not found');
        return;
    }

    $now = date('Y-m-d H:i:s');

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".company_setting_detail SET deleted_at = '$now', updated_by = '$username', updated_at = '$now' WHERE id = '$company_setting_detail_id' AND setting_menu_id = '$setting_menu_id'")) {
        jsonResponse(200, 'Company setting detail deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete company setting detail', ['error' => mysqli_error($conn)]);
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
// Reached via company-setting-menu/index.php as {module}/{setting_menu_id}/details[/{detail_id}]
// $conn, $method, $company_setting_menu_id, $company_id, $username, $parts are inherited from index.php

$company_setting_detail_id = $parts[5] ?? null;

if ($company_setting_detail_id) {
    switch ($method) {
        case 'GET':
            getDetailCompanySettingDetail($conn, $company_setting_menu_id, $company_setting_detail_id, $company_id);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateCompanySettingDetail($conn, $company_setting_menu_id, $company_setting_detail_id, $input, $username, $company_id);
            break;
        case 'DELETE':
            deleteCompanySettingDetail($conn, $company_setting_menu_id, $company_setting_detail_id, $username, $company_id);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} else {
    switch ($method) {
        case 'GET':
            getAllCompanySettingDetails($conn, $company_setting_menu_id, $company_id, $_GET);
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createCompanySettingDetail($conn, $company_setting_menu_id, $input, $username, $company_id);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
}
