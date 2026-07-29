<?php

// Mirrors legacy's behavior of seeding a baseline financeItem row (paid_amount=0,
// due_amount=<invoice total>) at invoice creation — see purchase/invoice/insertinvoice.php
// and sales/insertsalesinvoice.php. Without this, an invoice that never receives a manual
// payment never appears in any finance_payment-derived outstanding calculation (ar-ap-report,
// dashboard AR/AP widgets, notification digest, supplier/customer has_outstanding).
// Seeded on approval rather than creation (v2 has a Draft/Approved/Rejected workflow legacy
// purchase invoices didn't), so a still-editable draft never counts as a payable/receivable.
// transaction_status is set directly to 'posted' — this is a system-generated marker row, not
// a real disbursement/receipt, so it must not appear in owner/treasury approval queues.
function seedFinancePaymentBaseline($conn, $company_id, $invoice_number, $due_amount, $customer_id, $supplier_id, $username) {
    $invoice_number = mysqli_real_escape_string($conn, $invoice_number);
    $due_amount     = (float)$due_amount;
    $now            = date('Y-m-d H:i:s');

    // A revised-then-reapproved invoice (reject -> revise -> approve again) hits this a second
    // time with a possibly-changed total. Since the baseline row always has paid_amount=0, its
    // due_amount is safe to refresh in place rather than insert a second baseline row.
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

    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".finance_payment
            (id, company_id, invoice_number, paid_amount, due_amount, customer_id, supplier_id, transaction_status, created_by, created_at)
            VALUES ('$finance_payment_id', '$company_id', '$invoice_number', 0, $due_amount, $customer_id_sql, $supplier_id_sql, 'posted', '$username', '$now')");
}
