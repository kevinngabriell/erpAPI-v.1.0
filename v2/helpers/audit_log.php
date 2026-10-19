<?php

function insertAuditLog($conn, $company_id, $module, $reference_id, $action, $username, $notes = null) {
    $log_id          = generateUUID();
    $now             = date('Y-m-d H:i:s');
    $company_id_sql  = mysqli_real_escape_string($conn, $company_id);
    $module_sql      = mysqli_real_escape_string($conn, $module);
    $reference_id_sql = mysqli_real_escape_string($conn, $reference_id);
    $action_sql      = mysqli_real_escape_string($conn, $action);
    $username_sql    = mysqli_real_escape_string($conn, $username);
    $notes_sql       = $notes !== null
        ? "'" . mysqli_real_escape_string($conn, $notes) . "'"
        : 'NULL';

    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".audit_log
        (id, company_id, module, reference_id, action, action_by, action_at, notes)
        VALUES ('$log_id', '$company_id_sql', '$module_sql', '$reference_id_sql', '$action_sql', '$username_sql', '$now', $notes_sql)");
}
