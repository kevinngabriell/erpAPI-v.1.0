<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/permission.php';

// Documents whose .xlsx export prints a signature block.
const DOCUMENT_SIGNATURE_TYPES = ['sales_order', 'sales_sppb', 'sales_profit'];

function getAllDocumentSignatures($conn, $company_id) {
    $result = mysqli_query($conn, "SELECT document_type, created_by_names, acknowledged_by_names, approved_by_names, updated_at
            FROM " . APP_SCHEMA . ".document_signature
            WHERE company_id = '$company_id' AND deleted_at IS NULL");
    if (!$result) {
        jsonResponse(500, 'Failed to load document signatures', safeDbError($conn));
        return;
    }

    $configured = [];
    foreach (mysqli_fetch_all($result, MYSQLI_ASSOC) as $row) {
        $configured[$row['document_type']] = $row;
    }

    // One entry per supported document type, so the client never has to guess which exist.
    $data = [];
    foreach (DOCUMENT_SIGNATURE_TYPES as $document_type) {
        $row    = $configured[$document_type] ?? [];
        $data[] = [
            'document_type'         => $document_type,
            'created_by_names'      => $row['created_by_names'] ?? null,
            'acknowledged_by_names' => $row['acknowledged_by_names'] ?? null,
            'approved_by_names'     => $row['approved_by_names'] ?? null,
            'updated_at'            => $row['updated_at'] ?? null,
        ];
    }

    jsonResponse(200, 'Document signatures found', ['data' => $data]);
}

function updateDocumentSignature($conn, $document_type, $input, $username, $company_id) {
    if (!in_array($document_type, DOCUMENT_SIGNATURE_TYPES, true)) {
        jsonResponse(404, 'Unknown document type');
        return;
    }

    $fields = ['created_by_names', 'acknowledged_by_names', 'approved_by_names'];
    $values = [];
    foreach ($fields as $field) {
        $val = isset($input[$field]) ? trim((string)$input[$field]) : '';
        if (mb_strlen($val) > 255) {
            jsonResponse(400, "$field must be 255 characters or fewer");
            return;
        }
        $values[$field] = $val === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, $val) . "'";
    }

    $now = date('Y-m-d H:i:s');
    $id  = 'dsg_' . uniqid();

    $query = "INSERT INTO " . APP_SCHEMA . ".document_signature
              (id, company_id, document_type, created_by_names, acknowledged_by_names, approved_by_names, created_by, created_at, updated_by, updated_at)
              VALUES ('$id', '$company_id', '$document_type', {$values['created_by_names']}, {$values['acknowledged_by_names']}, {$values['approved_by_names']}, '$username', '$now', '$username', '$now')
              ON DUPLICATE KEY UPDATE created_by_names = VALUES(created_by_names), acknowledged_by_names = VALUES(acknowledged_by_names),
                approved_by_names = VALUES(approved_by_names), deleted_at = NULL, updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)";
    if (!mysqli_query($conn, $query)) {
        jsonResponse(500, 'Failed to save document signature', safeDbError($conn));
        return;
    }

    jsonResponse(200, 'Document signature saved successfully');
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser    = requireAuth();
$method      = $_SERVER['REQUEST_METHOD'];
$company_id  = $authUser['company_id'] ?? null;
$username    = $authUser['sub'] ?? $authUser['user_id'] ?? null;
$app_role_id = $authUser['app_role_id'] ?? null;

$document_type = !empty($action) ? $action : null;

try {
    $conn       = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $username   = mysqli_real_escape_string($conn, $username);

    if ($document_type) {
        switch ($method) {
            case 'PUT':
                if (!userHasPermission($conn, $app_role_id, 'master.document_signature.manage')) {
                    jsonResponse(403, 'You do not have permission to manage this master data');
                }
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateDocumentSignature($conn, $document_type, $input, $username, $company_id);
                break;
            default:
                methodNotAllowed(['PUT']);
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllDocumentSignatures($conn, $company_id);
                break;
            default:
                methodNotAllowed(['GET']);
        }
    }

} catch (Exception $e) {
    handleModuleException($e);
}
