<?php
require_once __DIR__ . '/permission.php';

// Shared Owner + Treasury 2-signer gate for any table carrying the standard
// transaction_status/approved_by_owner_*/approved_by_treasury_* columns.

function applyDualApproval($conn, $table, $entity_id, $company_id, $username, $app_role_id) {
    $entity_id = mysqli_real_escape_string($conn, $entity_id);

    $check = mysqli_query($conn, "SELECT transaction_status, approved_by_owner_id, approved_by_treasury_id
            FROM $table WHERE id = '$entity_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$check || mysqli_num_rows($check) === 0) {
        return ['code' => 404, 'message' => 'Record not found'];
    }
    $row = mysqli_fetch_assoc($check);

    if (in_array($row['transaction_status'], ['posted', 'rejected'], true)) {
        return ['code' => 409, 'message' => "Record is already {$row['transaction_status']}"];
    }

    $is_owner_open           = $row['approved_by_owner_id'] === null;
    $is_treasury_open        = $row['approved_by_treasury_id'] === null;
    $has_owner_permission    = userHasPermission($conn, $app_role_id, 'keuangan.approve_owner');
    $has_treasury_permission = userHasPermission($conn, $app_role_id, 'keuangan.approve_treasury');

    if ($is_owner_open && $has_owner_permission && $row['approved_by_treasury_id'] !== $username) {
        $slot = 'owner';
    } elseif ($is_treasury_open && $has_treasury_permission && $row['approved_by_owner_id'] !== $username) {
        $slot = 'treasury';
    } elseif ((!$is_owner_open && $row['approved_by_owner_id'] === $username)
           || (!$is_treasury_open && $row['approved_by_treasury_id'] === $username)) {
        return ['code' => 409, 'message' => 'You have already signed this record'];
    } else {
        return ['code' => 403, 'message' => 'You do not have permission to approve this record'];
    }

    $now                = date('Y-m-d H:i:s');
    $other_slot_signed  = $slot === 'owner' ? !$is_treasury_open : !$is_owner_open;
    $transaction_status = $other_slot_signed ? 'posted' : 'partially_approved';
    $column_id          = $slot === 'owner' ? 'approved_by_owner_id' : 'approved_by_treasury_id';
    $column_at          = $slot === 'owner' ? 'approved_by_owner_at' : 'approved_by_treasury_at';

    $updated = mysqli_query($conn, "UPDATE $table
            SET $column_id = '$username', $column_at = '$now',
                transaction_status = '$transaction_status', updated_by = '$username', updated_at = '$now'
            WHERE id = '$entity_id' AND company_id = '$company_id'");

    if (!$updated) {
        return ['code' => 500, 'message' => 'Failed to approve record', 'error' => mysqli_error($conn)];
    }

    return ['code' => 200, 'message' => 'Approved successfully', 'slot' => $slot, 'transaction_status' => $transaction_status];
}

function rejectDualApproval($conn, $table, $entity_id, $company_id, $username, $app_role_id) {
    $entity_id = mysqli_real_escape_string($conn, $entity_id);

    $check = mysqli_query($conn, "SELECT transaction_status FROM $table WHERE id = '$entity_id' AND company_id = '$company_id' AND deleted_at IS NULL LIMIT 1");
    if (!$check || mysqli_num_rows($check) === 0) {
        return ['code' => 404, 'message' => 'Record not found'];
    }
    $row = mysqli_fetch_assoc($check);

    if (in_array($row['transaction_status'], ['posted', 'rejected'], true)) {
        return ['code' => 409, 'message' => "Record is already {$row['transaction_status']}"];
    }

    if (!userHasPermission($conn, $app_role_id, 'keuangan.approve_owner') && !userHasPermission($conn, $app_role_id, 'keuangan.approve_treasury')) {
        return ['code' => 403, 'message' => 'You do not have permission to reject this record'];
    }

    $now     = date('Y-m-d H:i:s');
    $updated = mysqli_query($conn, "UPDATE $table
            SET transaction_status = 'rejected', updated_by = '$username', updated_at = '$now'
            WHERE id = '$entity_id' AND company_id = '$company_id'");

    if (!$updated) {
        return ['code' => 500, 'message' => 'Failed to reject record', 'error' => mysqli_error($conn)];
    }

    return ['code' => 200, 'message' => 'Rejected successfully'];
}
