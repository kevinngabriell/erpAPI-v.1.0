<?php

function seedFinancePaymentBaseline($conn, $company_id, $invoice_number, $due_amount, $customer_id, $supplier_id, $username) {
    $invoice_number = mysqli_real_escape_string($conn, $invoice_number);
    $due_amount     = mysqli_real_escape_string($conn, (string)$due_amount);
    $now            = date('Y-m-d H:i:s');

    $existing = mysqli_query($conn, "SELECT id FROM " . APP_SCHEMA . ".finance_payment
            WHERE company_id = '$company_id' AND invoice_number = '$invoice_number' AND payment_date IS NULL AND deleted_at IS NULL LIMIT 1");
    $existing_row = $existing ? mysqli_fetch_assoc($existing) : null;

    if ($existing_row) {
        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".finance_payment
                SET due_amount = $due_amount, updated_by = '$username', updated_at = '$now'
                WHERE id = '{$existing_row['id']}'");
        return;
    }

    $customer_id_sql = $customer_id ? "'" . mysqli_real_escape_string($conn, $customer_id) . "'" : 'NULL';
    $supplier_id_sql = $supplier_id ? "'" . mysqli_real_escape_string($conn, $supplier_id) . "'" : 'NULL';

    $finance_payment_id = generateUUID();

    $insert_result = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".finance_payment
            (id, company_id, invoice_number, paid_amount, due_amount, customer_id, supplier_id, transaction_status, created_by, created_at)
            VALUES ('$finance_payment_id', '$company_id', '$invoice_number', 0, $due_amount, $customer_id_sql, $supplier_id_sql, 'posted', '$username', '$now')");

    if (!$insert_result && mysqli_errno($conn) === 1062) {
        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".finance_payment
                SET due_amount = $due_amount, updated_by = '$username', updated_at = '$now'
                WHERE company_id = '$company_id' AND invoice_number = '$invoice_number' AND payment_date IS NULL AND deleted_at IS NULL");
    }
}
