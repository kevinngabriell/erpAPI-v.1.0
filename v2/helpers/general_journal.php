<?php

// Non-cash accrual posting, decoupled from the bank-account-centric
// finance_transaction/finance_transaction_detail cash book (that table requires
// a real bank_account_id — see v33 migration doc for why a separate mechanism
// was needed). Sign convention matches finance_transaction_detail exactly:
// positive amount increases the account's own natural balance (debit-normal
// for asset/expense, credit-normal for liability/equity/revenue).

function getDefaultAccountCode($conn, $company_id, string $flag_column): ?array {
    $result = mysqli_query($conn, "SELECT id, account_code, account_code_name
            FROM " . APP_SCHEMA . ".account_code
            WHERE company_id = '$company_id' AND $flag_column = 1 AND is_active = 1 AND deleted_at IS NULL LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ?: null;
}

function postGeneralJournalEntry($conn, $company_id, $transaction_date, $memo, $source_module, $source_document_id, array $lines, $username): string {
    if (count($lines) < 2) {
        throw new Exception('A general journal entry requires at least 2 lines');
    }

    $account_code_ids = array_map(fn($line) => "'" . mysqli_real_escape_string($conn, $line['account_code_id']) . "'", $lines);
    $types_result = mysqli_query($conn, "SELECT id, account_type FROM " . APP_SCHEMA . ".account_code
            WHERE id IN (" . implode(',', $account_code_ids) . ") AND company_id = '$company_id' AND deleted_at IS NULL");
    $account_types = [];
    while ($row = mysqli_fetch_assoc($types_result)) {
        $account_types[$row['id']] = $row['account_type'];
    }

    $debit_equivalent_sum = 0;
    foreach ($lines as $line) {
        if (!isset($account_types[$line['account_code_id']])) {
            throw new Exception("Account code not found: {$line['account_code_id']}");
        }
        $is_debit_normal        = in_array($account_types[$line['account_code_id']], ['asset', 'expense'], true);
        $debit_equivalent_sum  += $is_debit_normal ? (float)$line['amount'] : -(float)$line['amount'];
    }

    if (round($debit_equivalent_sum, 2) !== 0.0) {
        throw new Exception("Unbalanced general journal entry: debit-equivalent sum is $debit_equivalent_sum, expected 0");
    }

    $general_journal_id = generateUUID();
    $now                = date('Y-m-d H:i:s');
    $transaction_date   = mysqli_real_escape_string($conn, $transaction_date);
    $memo_sql           = $memo !== null ? "'" . mysqli_real_escape_string($conn, $memo) . "'" : 'NULL';
    $source_module_sql  = $source_module !== null ? "'" . mysqli_real_escape_string($conn, $source_module) . "'" : 'NULL';
    $source_document_id_sql = $source_document_id !== null ? "'" . mysqli_real_escape_string($conn, $source_document_id) . "'" : 'NULL';

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO " . APP_SCHEMA . ".general_journal
                (id, company_id, transaction_date, memo, source_module, source_document_id, transaction_status, created_by, created_at)
                VALUES ('$general_journal_id', '$company_id', '$transaction_date', $memo_sql, $source_module_sql, $source_document_id_sql, 'posted', '$username', '$now')";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }

        foreach ($lines as $line) {
            $detail_id       = generateUUID();
            $account_code_id = mysqli_real_escape_string($conn, $line['account_code_id']);
            $amount          = (float)$line['amount'];
            $line_memo_sql   = isset($line['memo']) && trim($line['memo']) !== '' ? "'" . mysqli_real_escape_string($conn, $line['memo']) . "'" : 'NULL';

            $detail_sql = "INSERT INTO " . APP_SCHEMA . ".general_journal_detail
                    (id, general_journal_id, account_code_id, amount, memo, created_by, created_at)
                    VALUES ('$detail_id', '$general_journal_id', '$account_code_id', $amount, $line_memo_sql, '$username', '$now')";
            if (!mysqli_query($conn, $detail_sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }

        $conn->commit();
        return $general_journal_id;
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}
