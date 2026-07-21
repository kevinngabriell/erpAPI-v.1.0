<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/notification.php';
require_once __DIR__ . '/../helpers/audit_log.php';

const NOTIFICATION_WORKING_DAYS_KEY = 'notification.working_days';
const NOTIFICATION_VALID_DAYS       = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

function getAllPublicHolidays($conn, $company_id, $params) {
    $where = "(company_id = '$company_id' OR company_id IS NULL) AND deleted_at IS NULL";
    if (isset($params['year']) && trim($params['year']) !== '') {
        $year   = (int)$params['year'];
        $where .= " AND YEAR(holiday_date) = $year";
    }

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".public_holiday WHERE $where ORDER BY holiday_date ASC");
    jsonResponse(200, 'Public holidays found', $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : []);
}

function createPublicHoliday($conn, $input, $username, $company_id) {
    $required = ['holiday_date', 'label'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $holiday_date = mysqli_real_escape_string($conn, $input['holiday_date']);
    $label        = mysqli_real_escape_string($conn, trim($input['label']));
    $company_scope = !empty($input['company_specific']);

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".public_holiday
            WHERE holiday_date = '$holiday_date' AND " . ($company_scope ? "company_id = '$company_id'" : 'company_id IS NULL') . " AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'A holiday already exists for this date');
        return;
    }

    $id             = generateUUID();
    $now            = date('Y-m-d H:i:s');
    $company_id_sql = $company_scope ? "'$company_id'" : 'NULL';

    if (mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".public_holiday (id, company_id, holiday_date, label, created_by, created_at)
            VALUES ('$id', $company_id_sql, '$holiday_date', '$label', '$username', '$now')")) {
        insertAuditLog($conn, $company_id, 'public_holiday', $id, 'created', $username);
        jsonResponse(201, 'Public holiday created successfully', ['id' => $id]);
    } else {
        jsonResponse(500, 'Failed to create public holiday', ['error' => mysqli_error($conn)]);
    }
}

function deletePublicHoliday($conn, $holiday_id, $username, $company_id) {
    $holiday_id = mysqli_real_escape_string($conn, $holiday_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".public_holiday
            WHERE id = '$holiday_id' AND (company_id = '$company_id' OR company_id IS NULL) AND deleted_at IS NULL LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Public holiday not found');
        return;
    }

    $now = date('Y-m-d H:i:s');
    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".public_holiday SET deleted_at = '$now' WHERE id = '$holiday_id'")) {
        insertAuditLog($conn, $company_id, 'public_holiday', $holiday_id, 'deleted', $username);
        jsonResponse(200, 'Public holiday deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete public holiday', ['error' => mysqli_error($conn)]);
    }
}

function getWorkingDays($conn, $company_id) {
    $working_days = json_decode(getCompanySetting($conn, $company_id, NOTIFICATION_WORKING_DAYS_KEY, '["MON","TUE","WED","THU","FRI"]'), true);
    jsonResponse(200, 'Working days retrieved', ['working_days' => $working_days]);
}

function updateWorkingDays($conn, $input, $username, $company_id) {
    if (!isset($input['working_days']) || !is_array($input['working_days']) || count($input['working_days']) === 0) {
        jsonResponse(400, 'working_days must be a non-empty array');
        return;
    }

    $working_days = array_values(array_unique(array_map('strtoupper', $input['working_days'])));
    foreach ($working_days as $day) {
        if (!in_array($day, NOTIFICATION_VALID_DAYS, true)) {
            jsonResponse(400, 'working_days must only contain ' . implode(', ', NOTIFICATION_VALID_DAYS));
            return;
        }
    }

    setCompanySetting($conn, $company_id, NOTIFICATION_WORKING_DAYS_KEY, json_encode($working_days), $username);
    insertAuditLog($conn, $company_id, 'company_setting', NOTIFICATION_WORKING_DAYS_KEY, 'updated', $username);
    jsonResponse(200, 'Working days updated successfully', ['working_days' => $working_days]);
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

$setting    = !empty($action) ? $action : null;
$sub_action = $parts[4] ?? null;

try {
    $conn = getConn();

    if ($setting === 'public-holidays') {
        switch ($method) {
            case 'GET':
                getAllPublicHolidays($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createPublicHoliday($conn, $input, $username, $company_id);
                break;
            case 'DELETE':
                if (!$sub_action) { jsonResponse(400, 'Holiday id is required'); }
                deletePublicHoliday($conn, $sub_action, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }

    } elseif ($setting === 'working-days') {
        switch ($method) {
            case 'GET':
                getWorkingDays($conn, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateWorkingDays($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }

    } else {
        jsonResponse(404, 'Route not found');
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
