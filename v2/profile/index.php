<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

function getMyProfile($conn, $app_user_id, $company_id) {
    $result = mysqli_query($conn, "SELECT
            u.user_id AS app_user_id, u.first_name, u.last_name, u.email, u.phone_number,
            u.position_id, p.position_name, u.account_status,
            ep.id AS employee_profile_id, ep.gender, ep.birth_date, ep.address,
            ep.emergency_contact_name, ep.emergency_contact_phone,
            ep.employee_code, ep.department, ep.employment_type, ep.join_date,
            ep.supervisor_id, CONCAT(su.first_name, ' ', su.last_name) AS supervisor_name,
            ep.work_location, ep.created_at, ep.updated_at
        FROM " . CORE_SCHEMA . ".app_user u
        LEFT JOIN " . CORE_SCHEMA . ".app_position p ON p.position_id = u.position_id
        LEFT JOIN " . APP_SCHEMA . ".employee_profile ep ON u.user_id COLLATE utf8mb4_general_ci = ep.app_user_id AND ep.deleted_at IS NULL
        LEFT JOIN " . CORE_SCHEMA . ".app_user su ON su.user_id COLLATE utf8mb4_general_ci = ep.supervisor_id
        WHERE u.user_id = '$app_user_id' AND u.company_id = '$company_id'
        LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Profile not found');
        return;
    }

    $row = mysqli_fetch_assoc($result);
    $row['is_active']        = $row['account_status'] === 'verified';
    $row['supervisor_name']  = $row['supervisor_id'] ? $row['supervisor_name'] : null;

    jsonResponse(200, 'Profile found', $row);
}

function updateMyProfile($conn, $app_user_id, $input, $username, $company_id) {
    $check = mysqli_query($conn, "SELECT 1 FROM " . CORE_SCHEMA . ".app_user WHERE user_id = '$app_user_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Profile not found');
        return;
    }

    $app_user_updates = [];

    if (isset($input['first_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['first_name']));
        if (!preg_match('/^[a-zA-Z\s\-]{2,100}$/', $val)) {
            jsonResponse(400, 'first_name must be 2-100 characters and contain letters, spaces, or hyphens only');
            return;
        }
        $app_user_updates[] = "first_name = '$val'";
    }
    if (isset($input['last_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['last_name']));
        if (!preg_match('/^[a-zA-Z\s\-]{2,100}$/', $val)) {
            jsonResponse(400, 'last_name must be 2-100 characters and contain letters, spaces, or hyphens only');
            return;
        }
        $app_user_updates[] = "last_name = '$val'";
    }
    if (isset($input['phone_number'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['phone_number']));
        if (!preg_match('/^(\+62|08)[0-9]{8,13}$/', $val)) {
            jsonResponse(400, 'phone_number must be a valid Indonesian number starting with 08 or +62');
            return;
        }
        $app_user_updates[] = "phone_number = '$val'";
    }

    $profile_fields = [];

    if (isset($input['gender'])) {
        $val = trim((string)$input['gender']);
        if ($val === '') {
            $profile_fields['gender'] = 'NULL';
        } elseif (!in_array($val, ['male', 'female'], true)) {
            jsonResponse(400, 'gender must be male or female');
            return;
        } else {
            $profile_fields['gender'] = "'$val'";
        }
    }
    if (isset($input['birth_date'])) {
        $val = trim((string)$input['birth_date']);
        if ($val === '') {
            $profile_fields['birth_date'] = 'NULL';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            jsonResponse(400, 'birth_date must be YYYY-MM-DD format');
            return;
        } else {
            $profile_fields['birth_date'] = "'$val'";
        }
    }
    if (isset($input['address'])) {
        $val = trim(mysqli_real_escape_string($conn, (string)$input['address']));
        $profile_fields['address'] = $val !== '' ? "'$val'" : 'NULL';
    }
    if (isset($input['emergency_contact_name'])) {
        $val = trim(mysqli_real_escape_string($conn, (string)$input['emergency_contact_name']));
        if ($val === '') {
            $profile_fields['emergency_contact_name'] = 'NULL';
        } elseif (!preg_match('/^[a-zA-Z\s\-]{2,200}$/', $val)) {
            jsonResponse(400, 'emergency_contact_name must be 2-200 characters and contain letters, spaces, or hyphens only');
            return;
        } else {
            $profile_fields['emergency_contact_name'] = "'$val'";
        }
    }
    if (isset($input['emergency_contact_phone'])) {
        $val = trim(mysqli_real_escape_string($conn, (string)$input['emergency_contact_phone']));
        if ($val === '') {
            $profile_fields['emergency_contact_phone'] = 'NULL';
        } elseif (!preg_match('/^(\+62|08)[0-9]{8,13}$/', $val)) {
            jsonResponse(400, 'emergency_contact_phone must be a valid Indonesian number starting with 08 or +62');
            return;
        } else {
            $profile_fields['emergency_contact_phone'] = "'$val'";
        }
    }

    if (empty($app_user_updates) && empty($profile_fields)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');

    $conn->begin_transaction();

    try {
        if (!empty($app_user_updates)) {
            $app_user_updates[] = "updated_by = '$username'";
            $app_user_updates[] = "updated_at = '$now'";
            mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user SET " . implode(', ', $app_user_updates) . " WHERE user_id = '$app_user_id' AND company_id = '$company_id'");
        }

        if (!empty($profile_fields)) {
            $profile_check = mysqli_query($conn, "SELECT id FROM " . APP_SCHEMA . ".employee_profile WHERE app_user_id = '$app_user_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
            $profile_row   = $profile_check ? mysqli_fetch_assoc($profile_check) : null;

            if ($profile_row) {
                $updates = [];
                foreach ($profile_fields as $col => $val) {
                    $updates[] = "$col = $val";
                }
                $updates[] = "updated_by = '$username'";
                $updates[] = "updated_at = '$now'";
                mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".employee_profile SET " . implode(', ', $updates) . " WHERE id = '" . $profile_row['id'] . "'");
            } else {
                $employee_profile_id = generateUUID();
                $columns = array_merge(['id', 'company_id', 'app_user_id'], array_keys($profile_fields), ['created_by', 'created_at']);
                $values  = array_merge(["'$employee_profile_id'", "'$company_id'", "'$app_user_id'"], array_values($profile_fields), ["'$username'", "'$now'"]);
                mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".employee_profile (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")");
            }
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to update profile', ['error' => $e->getMessage()]);
        return;
    }

    jsonResponse(200, 'Profile updated successfully');
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser    = requireAuth();
$method      = $_SERVER['REQUEST_METHOD'];
$company_id  = $authUser['company_id'] ?? null;
$app_user_id = $authUser['user_id'] ?? null;
$username    = $authUser['user_id'] ?? null;

if (!$company_id || !$app_user_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

try {
    $conn = getConn();

    switch ($method) {
        case 'GET':
            getMyProfile($conn, $app_user_id, $company_id);
            break;
        case 'PUT':
        case 'PATCH':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateMyProfile($conn, $app_user_id, $input, $username, $company_id);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
