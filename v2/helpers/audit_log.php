<?php

function insertAuditLog($conn, $company_id, $module, $reference_id, $action, $username, $notes = null) {
    $log_id    = generateUUID();
    $now       = date('Y-m-d H:i:s');
    $notes_sql = $notes !== null
        ? "'" . mysqli_real_escape_string($conn, $notes) . "'"
        : 'NULL';

    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".audit_log
        (id, company_id, module, reference_id, action, action_by, action_at, notes)
        VALUES ('$log_id', '$company_id', '$module', '$reference_id', '$action', '$username', '$now', $notes_sql)");
}
