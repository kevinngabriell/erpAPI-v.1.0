<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

/*
 * Stores beginning balance per account code as a special entry in financeTransaction
 * with memo = '__SALDO_AWAL__'. The stored amount is an ADJUSTMENT
 * (desired_balance − current_computed_balance), so all existing balance SUM queries
 * automatically produce the correct result without changes.
 *
 * Amount convention for the POST request:
 *   Positive (+) → Debit balance  (Aset, Biaya)
 *   Negative (−) → Credit balance (Liabilitas, Ekuitas, Akumulasi Penyusutan)
 *
 * No new table required.
 */

const CAT_DR_ACCT = '174c61e8-226d-11ef-a'; // Penerimaan / Debit
const CAT_CR_ACCT = '1d604104-226d-11ef-a'; // Pembayaran / Credit
const SALDO_AWAL  = '__SALDO_AWAL__';

function gen_uuid_acct(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function compute_account_balance(mysqli $db, string $code, string $before_date): float {
    $row = mysqli_fetch_assoc(mysqli_query($db,
        "SELECT SUM(IF(finance_category = '" . CAT_DR_ACCT . "', amount, -amount)) AS bal
         FROM financeTransaction
         WHERE accountcode = '$code'
           AND (memo IS NULL OR memo != '" . SALDO_AWAL . "')
           AND DATE(date) < '$before_date'"
    ));
    return (float)($row['bal'] ?? 0);
}

// ─── GET: retrieve beginning balance entries for one or all account codes ─────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $account_code = isset($_GET['account_code']) ? mysqli_real_escape_string($connect, trim($_GET['account_code'])) : '';
    $as_of_date   = isset($_GET['as_of_date'])   ? mysqli_real_escape_string($connect, trim($_GET['as_of_date']))   : '';

    $where = ["A1.memo = '" . SALDO_AWAL . "'", "A1.bank_account = ''"];
    if ($account_code !== '') $where[] = "A1.accountcode = '$account_code'";
    if ($as_of_date   !== '') $where[] = "DATE(A1.date) = '$as_of_date'";

    $result = mysqli_query($connect,
        "SELECT A1.id_transaction, A1.accountcode AS account_code,
                A2.account_name, A2.account_code_name_alias,
                DATE(A1.date) AS as_of_date, A1.finance_category, A1.amount,
                A1.insertby, A1.insertdt
         FROM financeTransaction A1
         LEFT JOIN account_code A2 ON A1.accountcode = A2.code
         WHERE " . implode(' AND ', $where) . "
         ORDER BY A1.date DESC, A1.accountcode ASC"
    );

    if (!$result) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['amount'] = $row['finance_category'] === CAT_DR_ACCT
            ? (float)$row['amount']
            : -(float)$row['amount'];
        unset($row['finance_category']);
    }

    echo json_encode([
        'StatusCode'    => 200,
        'Status'        => 'Success',
        'total_records' => count($rows),
        'data'          => $rows,
    ]);

// ─── POST: set beginning balance ──────────────────────────────────────────────
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $now         = date('Y-m-d H:i:s');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson      = str_contains($contentType, 'application/json');

    if ($isJson) {
        // ── BULK mode: JSON body ─────────────────────────────────────────────
        $body = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['StatusCode' => 400, 'Status' => 'Bad Request', 'message' => 'Invalid JSON body']);
            exit;
        }

        $as_of_date = isset($body['as_of_date']) ? trim($body['as_of_date']) : '';
        $insert_by  = isset($body['insert_by'])  ? trim($body['insert_by'])  : '';
        $balances   = $body['balances'] ?? [];

        $errors = [];
        if ($as_of_date === '') $errors[] = 'as_of_date is required';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of_date)) $errors[] = 'as_of_date must be YYYY-MM-DD';
        if (!is_array($balances) || count($balances) === 0) $errors[] = 'balances array is required and must not be empty';

        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['StatusCode' => 400, 'Status' => 'Bad Request', 'message' => implode('; ', $errors)]);
            exit;
        }

        $as_of_esc  = mysqli_real_escape_string($connect, $as_of_date);
        $by_esc     = mysqli_real_escape_string($connect, $insert_by);
        $memo       = SALDO_AWAL;

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed  = [];

        foreach ($balances as $i => $item) {
            $code    = isset($item['account_code']) ? mysqli_real_escape_string($connect, trim($item['account_code'])) : '';
            $desired = isset($item['amount'])       ? (float)$item['amount'] : null;

            if ($code === '' || $desired === null) {
                $failed[] = ['index' => $i, 'reason' => 'account_code and amount are required'];
                continue;
            }

            // Remove existing __SALDO_AWAL__ for this code + date before computing current balance
            $existingRow = mysqli_fetch_assoc(mysqli_query($connect,
                "SELECT id_transaction FROM financeTransaction
                 WHERE accountcode = '$code' AND DATE(date) = '$as_of_esc' AND memo = '$memo' AND bank_account = ''"
            ));
            $action = $existingRow ? 'updated' : 'created';

            if ($existingRow) {
                mysqli_query($connect,
                    "DELETE FROM financeTransaction
                     WHERE accountcode = '$code' AND DATE(date) = '$as_of_esc' AND memo = '$memo' AND bank_account = ''"
                );
            }

            $current    = compute_account_balance($connect, $code, $as_of_date);
            $adjustment = $desired - $current;

            if ($adjustment == 0) {
                $skipped++;
                continue;
            }

            $cat     = $adjustment > 0 ? CAT_DR_ACCT : CAT_CR_ACCT;
            $abs_adj = abs($adjustment);
            $uuid    = gen_uuid_acct();

            $ok = mysqli_query($connect,
                "INSERT INTO financeTransaction
                    (id_transaction, bank_account, voucher_no, date, memo, amount, accountcode, accountamount, accountmemo, insertby, insertdt, finance_category)
                 VALUES
                    ('$uuid', '', '', '$as_of_esc', '$memo', $abs_adj, '$code', 0, 'Saldo Awal', '$by_esc', '$now', '$cat')"
            );

            if ($ok) {
                $action === 'updated' ? $updated++ : $created++;
            } else {
                $failed[] = ['index' => $i, 'account_code' => $code, 'reason' => mysqli_error($connect)];
            }
        }

        $statusCode = empty($failed) ? 200 : (($created + $updated > 0) ? 207 : 500);
        echo json_encode([
            'StatusCode'    => $statusCode,
            'Status'        => empty($failed) ? 'Success' : 'Partial Success',
            'as_of_date'    => $as_of_date,
            'total_created' => $created,
            'total_updated' => $updated,
            'total_skipped' => $skipped,
            'total_failed'  => count($failed),
            'failed'        => $failed,
        ]);

    } else {
        // ── SINGLE mode: form-data ───────────────────────────────────────────
        $account_code = isset($_POST['account_code']) ? mysqli_real_escape_string($connect, trim($_POST['account_code'])) : '';
        $as_of_date   = isset($_POST['as_of_date'])   ? mysqli_real_escape_string($connect, trim($_POST['as_of_date']))   : '';
        $desired      = isset($_POST['amount'])        ? (float)$_POST['amount']                                           : null;
        $insert_by    = isset($_POST['insert_by'])     ? mysqli_real_escape_string($connect, trim($_POST['insert_by']))     : '';

        $errors = [];
        if ($account_code === '') $errors[] = 'account_code is required';
        if ($as_of_date   === '') $errors[] = 'as_of_date is required (YYYY-MM-DD)';
        if ($desired === null)    $errors[] = 'amount is required';
        if ($as_of_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of_date)) {
            $errors[] = 'as_of_date must be in YYYY-MM-DD format';
        }

        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['StatusCode' => 400, 'Status' => 'Bad Request', 'message' => implode('; ', $errors)]);
            exit;
        }

        $memo = SALDO_AWAL;

        // Remove existing __SALDO_AWAL__ before computing current balance
        $existingRow = mysqli_fetch_assoc(mysqli_query($connect,
            "SELECT id_transaction FROM financeTransaction
             WHERE accountcode = '$account_code' AND DATE(date) = '$as_of_date' AND memo = '$memo' AND bank_account = ''"
        ));
        $action = $existingRow ? 'updated' : 'created';

        if ($existingRow) {
            mysqli_query($connect,
                "DELETE FROM financeTransaction
                 WHERE accountcode = '$account_code' AND DATE(date) = '$as_of_date' AND memo = '$memo' AND bank_account = ''"
            );
        }

        $current    = compute_account_balance($connect, $account_code, $as_of_date);
        $adjustment = $desired - $current;

        if ($adjustment == 0) {
            echo json_encode([
                'StatusCode'     => 200,
                'Status'         => 'Success',
                'action'         => 'no_change',
                'account_code'   => $account_code,
                'as_of_date'     => $as_of_date,
                'desired_amount' => $desired,
                'adjustment'     => 0,
            ]);
            exit;
        }

        $cat     = $adjustment > 0 ? CAT_DR_ACCT : CAT_CR_ACCT;
        $abs_adj = abs($adjustment);
        $uuid    = gen_uuid_acct();

        $ok = mysqli_query($connect,
            "INSERT INTO financeTransaction
                (id_transaction, bank_account, voucher_no, date, memo, amount, accountcode, accountamount, accountmemo, insertby, insertdt, finance_category)
             VALUES
                ('$uuid', '', '', '$as_of_date', '$memo', $abs_adj, '$account_code', 0, 'Saldo Awal', '$insert_by', '$now', '$cat')"
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
            'account_code'   => $account_code,
            'as_of_date'     => $as_of_date,
            'desired_amount' => $desired,
            'adjustment'     => $adjustment,
        ]);
    }

} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
