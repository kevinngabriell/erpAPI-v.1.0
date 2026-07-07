<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');
require_once('../auth/middleware.php');

/*
 * Stores beginning balance as a special entry in financeTransaction with memo = '__SALDO_AWAL__'.
 * The stored amount is an ADJUSTMENT (desired_balance − current_computed_balance), so all existing
 * balance SUM queries automatically produce the correct result without any other changes.
 *
 * No new table required.
 */

const CAT_DR = '174c61e8-226d-11ef-a'; // Penerimaan (debit / money in)
const CAT_CR = '1d604104-226d-11ef-a'; // Pembayaran (credit / money out)
const SALDO_AWAL_MEMO = '__SALDO_AWAL__';

function gen_uuid(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// ─── GET: retrieve beginning balance entries for a bank account ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $bank_account = isset($_GET['bank_account']) ? mysqli_real_escape_string($connect, trim($_GET['bank_account'])) : '';
    $as_of_date   = isset($_GET['as_of_date'])   ? mysqli_real_escape_string($connect, trim($_GET['as_of_date']))   : '';

    if ($bank_account === '') {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Bad Request', 'message' => 'bank_account is required']);
        exit;
    }

    $date_filter = $as_of_date !== '' ? "AND DATE(date) = '$as_of_date'" : '';

    $result = mysqli_query($connect,
        "SELECT id_transaction, bank_account, DATE(date) AS as_of_date,
                finance_category, amount, insertby, insertdt
         FROM financeTransaction
         WHERE bank_account = '$bank_account'
           AND memo = '" . SALDO_AWAL_MEMO . "'
           $date_filter
         ORDER BY date DESC"
    );

    if (!$result) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['amount'] = $row['finance_category'] === CAT_DR
            ? (float)$row['amount']
            : -(float)$row['amount'];
        unset($row['finance_category']);
    }

    echo json_encode([
        'StatusCode'    => 200,
        'Status'        => 'Success',
        'bank_account'  => $bank_account,
        'total_records' => count($rows),
        'data'          => $rows,
    ]);

// ─── POST: set beginning balance ──────────────────────────────────────────────
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decoded = verifyToken();

    $bank_account   = isset($_POST['bank_account']) ? mysqli_real_escape_string($connect, trim($_POST['bank_account'])) : '';
    $as_of_date     = isset($_POST['as_of_date'])   ? mysqli_real_escape_string($connect, trim($_POST['as_of_date']))   : '';
    $desired_amount = isset($_POST['amount'])        ? (float)$_POST['amount']                                           : null;
    $insert_by      = $decoded->sub;

    $errors = [];
    if ($bank_account === '')  $errors[] = 'bank_account is required';
    if ($as_of_date   === '')  $errors[] = 'as_of_date is required (YYYY-MM-DD)';
    if ($desired_amount === null) $errors[] = 'amount is required';
    if ($as_of_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of_date)) {
        $errors[] = 'as_of_date must be in YYYY-MM-DD format';
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Bad Request', 'message' => implode('; ', $errors)]);
        exit;
    }

    // Delete existing __SALDO_AWAL__ for this bank + date first (so it doesn't skew the computed sum)
    $existing = mysqli_fetch_assoc(mysqli_query($connect,
        "SELECT id_transaction FROM financeTransaction
         WHERE bank_account = '$bank_account' AND DATE(date) = '$as_of_date' AND memo = '" . SALDO_AWAL_MEMO . "'"
    ));
    $action = $existing ? 'updated' : 'created';

    if ($existing) {
        mysqli_query($connect,
            "DELETE FROM financeTransaction
             WHERE bank_account = '$bank_account' AND DATE(date) = '$as_of_date' AND memo = '" . SALDO_AWAL_MEMO . "'"
        );
    }

    // Compute current balance from regular transactions before as_of_date (excluding __SALDO_AWAL__)
    $currentRow = mysqli_fetch_assoc(mysqli_query($connect, "
        SELECT SUM(bal) AS total FROM (
            SELECT CASE
                       WHEN finance_category = '" . CAT_DR . "' THEN amount
                       WHEN finance_category = '" . CAT_CR . "' THEN -amount
                       ELSE 0
                   END AS bal
            FROM financeTransaction
            WHERE bank_account = '$bank_account'
              AND (memo IS NULL OR memo != '" . SALDO_AWAL_MEMO . "')
              AND DATE(date) < '$as_of_date'
            UNION ALL
            SELECT CASE
                       WHEN customer IS NOT NULL THEN paid_amount
                       WHEN supplier IS NOT NULL THEN -paid_amount
                       ELSE 0
                   END AS bal
            FROM financeItem
            WHERE bank = '$bank_account' AND DATE(paymentdate) < '$as_of_date'
        ) AS computed
    "));
    $currentBalance = (float)($currentRow['total'] ?? 0);

    // Adjustment = desired − computed
    $adjustment = $desired_amount - $currentBalance;

    if ($adjustment == 0) {
        // Nothing to insert — balance already matches
        echo json_encode([
            'StatusCode'     => 200,
            'Status'         => 'Success',
            'action'         => 'no_change',
            'bank_account'   => $bank_account,
            'as_of_date'     => $as_of_date,
            'desired_amount' => $desired_amount,
            'adjustment'     => 0,
        ]);
        exit;
    }

    $cat     = $adjustment > 0 ? CAT_DR : CAT_CR;
    $abs_adj = abs($adjustment);
    $uuid    = gen_uuid();
    $now     = date('Y-m-d H:i:s');
    $memo    = SALDO_AWAL_MEMO;

    $ok = mysqli_query($connect,
        "INSERT INTO financeTransaction
            (id_transaction, bank_account, voucher_no, date, memo, amount, accountcode, accountamount, accountmemo, insertby, insertdt, finance_category)
         VALUES
            ('$uuid', '$bank_account', '', '$as_of_date', '$memo', $abs_adj, '', 0, 'Saldo Awal', '$insert_by', '$now', '$cat')"
    );

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    echo json_encode([
        'StatusCode'     => 200,
        'Status'         => 'Success',
        'action'         => $action,
        'bank_account'   => $bank_account,
        'as_of_date'     => $as_of_date,
        'desired_amount' => $desired_amount,
        'adjustment'     => $adjustment,
    ]);

} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
