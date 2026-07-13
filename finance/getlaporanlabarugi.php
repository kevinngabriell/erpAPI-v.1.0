<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : null;

    if (!$start_date || !$end_date) {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status'     => 'Bad Request',
            'message'    => 'start_date and end_date are required'
        ]);
        exit;
    }

    $start_date = mysqli_real_escape_string($connect, $start_date);
    $end_date   = mysqli_real_escape_string($connect, $end_date);

    // Pendapatan: invoice penjualan yang diinput pada periode ini (sebelum PPN)
    $penjualanQuery = "
        SELECT COALESCE(SUM(A2.productQuantity * A2.unitPrice), 0) AS total
        FROM salesInvoice A1
        LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
        WHERE A1.invoiceDate BETWEEN '$start_date' AND '$end_date'
    ";

    // Retur/diskon penjualan: dijurnal di financeTransaction, dikenali dari nama akun
    $returDiskonQuery = "
        SELECT
            A2.account_code,
            A2.account_code_name_alias AS account_name,
            SUM(A1.amount) AS total
        FROM financeTransaction A1
        LEFT JOIN account_code A2 ON A1.accountcode = A2.account_code
        WHERE A1.date BETWEEN '$start_date' AND '$end_date'
          AND (
                A2.account_code_name LIKE '%retur%' OR A2.account_code_name LIKE '%diskon%'
                OR A2.account_code_name_alias LIKE '%retur%' OR A2.account_code_name_alias LIKE '%diskon%'
              )
        GROUP BY A2.account_code, A2.account_code_name_alias
        ORDER BY A2.account_code
    ";

    // HPP: rata-rata harga beli per produk (all-time), dipakai sebagai cost basis
    $avgCostQuery = "
        SELECT
            A2.POProductName AS nama_produk,
            CASE WHEN SUM(A2.POQuantity) > 0
                 THEN SUM(A2.POQuantity * A2.POUnitPrice) / SUM(A2.POQuantity)
                 ELSE 0 END AS harga_rata_rata
        FROM purchaseOrder A1
        LEFT JOIN purchaseOrderItem A2 ON A1.PONumber = A2.PONumber
        WHERE A2.POProductName IS NOT NULL
        GROUP BY A2.POProductName
    ";

    // Barang terjual pada periode ini (dipakai untuk hitung HPP periode berjalan)
    $barangTerjualQuery = "
        SELECT
            A2.productName,
            SUM(A2.productQuantity) AS qty_terjual
        FROM salesInvoice A1
        LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
        WHERE A1.invoiceDate BETWEEN '$start_date' AND '$end_date'
          AND A2.productName IS NOT NULL
        GROUP BY A2.productName
    ";

    // Biaya/beban usaha: jurnal pembayaran akun 5xx/6xx, kecuali akun pajak (dipisah di bawah)
    $biayaUsahaQuery = "
        SELECT
            A2.account_code,
            A2.account_code_name_alias AS account_name,
            SUM(A1.amount) AS total
        FROM financeTransaction A1
        LEFT JOIN account_code A2 ON A1.accountcode = A2.account_code
        WHERE A1.finance_category = '1d604104-226d-11ef-a'
          AND A1.date BETWEEN '$start_date' AND '$end_date'
          AND (A2.account_code LIKE '5%' OR A2.account_code LIKE '6%')
          AND A2.account_code_name NOT LIKE '%pajak%' AND A2.account_code_name NOT LIKE '%pph%'
          AND A2.account_code_name_alias NOT LIKE '%pajak%' AND A2.account_code_name_alias NOT LIKE '%pph%'
        GROUP BY A2.account_code, A2.account_code_name_alias
        ORDER BY A2.account_code
    ";

    // Pajak penghasilan: dijurnal di financeTransaction, dikenali dari nama akun
    $pajakQuery = "
        SELECT
            A2.account_code,
            A2.account_code_name_alias AS account_name,
            SUM(A1.amount) AS total
        FROM financeTransaction A1
        LEFT JOIN account_code A2 ON A1.accountcode = A2.account_code
        WHERE A1.date BETWEEN '$start_date' AND '$end_date'
          AND (
                A2.account_code_name LIKE '%pajak%' OR A2.account_code_name LIKE '%pph%'
                OR A2.account_code_name_alias LIKE '%pajak%' OR A2.account_code_name_alias LIKE '%pph%'
              )
        GROUP BY A2.account_code, A2.account_code_name_alias
        ORDER BY A2.account_code
    ";

    $penjualanResult      = mysqli_query($connect, $penjualanQuery);
    $returDiskonResult    = mysqli_query($connect, $returDiskonQuery);
    $avgCostResult        = mysqli_query($connect, $avgCostQuery);
    $barangTerjualResult  = mysqli_query($connect, $barangTerjualQuery);
    $biayaUsahaResult     = mysqli_query($connect, $biayaUsahaQuery);
    $pajakResult          = mysqli_query($connect, $pajakQuery);

    if (!$penjualanResult || !$returDiskonResult || !$avgCostResult || !$barangTerjualResult || !$biayaUsahaResult || !$pajakResult) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    // Pendapatan
    $penjualanRow  = mysqli_fetch_assoc($penjualanResult);
    $penjualan     = (float)$penjualanRow['total'];

    $retur_diskon_jurnal = mysqli_fetch_all($returDiskonResult, MYSQLI_ASSOC);
    $total_retur_diskon  = array_sum(array_column($retur_diskon_jurnal, 'total'));

    $total_pendapatan = $penjualan - $total_retur_diskon;

    // HPP = qty terjual pada periode x harga rata-rata beli (all-time)
    $avgCostMap = [];
    while ($row = mysqli_fetch_assoc($avgCostResult)) {
        $avgCostMap[$row['nama_produk']] = (float)$row['harga_rata_rata'];
    }

    $total_hpp = 0.0;
    while ($row = mysqli_fetch_assoc($barangTerjualResult)) {
        $harga_rata = $avgCostMap[$row['productName']] ?? 0.0;
        $total_hpp += (float)$row['qty_terjual'] * $harga_rata;
    }

    $laba_rugi_kotor = $total_pendapatan - $total_hpp;

    // Biaya usaha
    $biaya_usaha_jurnal = mysqli_fetch_all($biayaUsahaResult, MYSQLI_ASSOC);
    $total_biaya_usaha  = array_sum(array_column($biaya_usaha_jurnal, 'total'));

    $laba_sebelum_pajak = $laba_rugi_kotor - $total_biaya_usaha;

    // Pajak penghasilan
    $pajak_jurnal      = mysqli_fetch_all($pajakResult, MYSQLI_ASSOC);
    $total_pajak       = array_sum(array_column($pajak_jurnal, 'total'));

    $laba_bersih_setelah_pajak = $laba_sebelum_pajak - $total_pajak;

    echo json_encode([
        'StatusCode' => 200,
        'Status'     => 'Success',
        'period'     => ['start_date' => $start_date, 'end_date' => $end_date],
        'Data'       => [
            'pendapatan' => [
                'penjualan'        => $penjualan,
                'retur_diskon'     => [
                    'jurnal' => array_map(fn($r) => array_merge($r, ['total' => (float)$r['total']]), $retur_diskon_jurnal),
                    'total'  => (float)$total_retur_diskon
                ],
                'total_pendapatan' => $total_pendapatan
            ],
            'harga_pokok_penjualan' => $total_hpp,
            'laba_rugi_kotor'       => $laba_rugi_kotor,
            'biaya_usaha' => [
                'jurnal' => array_map(fn($r) => array_merge($r, ['total' => (float)$r['total']]), $biaya_usaha_jurnal),
                'total'  => $total_biaya_usaha
            ],
            'laba_sebelum_pajak' => $laba_sebelum_pajak,
            'pajak_penghasilan' => [
                'jurnal' => array_map(fn($r) => array_merge($r, ['total' => (float)$r['total']]), $pajak_jurnal),
                'total'  => $total_pajak
            ],
            'laba_bersih_setelah_pajak' => $laba_bersih_setelah_pajak
        ]
    ]);
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
