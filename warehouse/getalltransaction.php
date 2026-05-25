<?php
// Header access is required
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $startdate = isset($_GET['startdate']) ? $_GET['startdate'] : null;
    $enddate = isset($_GET['enddate']) ? $_GET['enddate'] : null;
    $kodeBarang = isset($_GET['kodeBarang']) ? $_GET['kodeBarang'] : '';

    $query = "SELECT 
                p.skuID AS Kode_Barang,
                p.productName AS Nama_Barang,
                wt.transactionDate AS Tanggal_Transaksi,
                wt.lot as Lot,
                CASE 
                    WHEN wt.transactionType = '452c5015-e80f-4e8a-8' THEN 'IN'
                    ELSE COALESCE(wt.customer, wt.keterangan)
                END AS Keterangan_Customer,
                CASE 
                    WHEN wt.transactionType = '452c5015-e80f-4e8a-8' THEN wt.quantity * u.conversionFactor
                    ELSE 0
                END AS Jumlah_Barang_Masuk,
                CASE 
                    WHEN wt.transactionType = '9cafab5b-d975-41e8-8' THEN wt.quantity * u.conversionFactor
                    ELSE 0
                END AS Jumlah_Barang_Keluar
              FROM 
                warehouseTransaction wt
              LEFT JOIN 
                product p ON wt.product = p.skuID
              LEFT JOIN 
                unitOfMeasure u ON wt.unitOfMeasureID = u.uomID
              WHERE 
                p.skuID = '$kodeBarang'";

    if ($startdate && $enddate) {
        $query .= " AND wt.transactionDate BETWEEN '$startdate' AND '$enddate'";
    }

    $query .= " ORDER BY wt.insertDt";

    $result = mysqli_query($connect, $query);
    $transactions = array();
    $kodeBarang = '';
    $namaBarang = '';
    $sisaBarang = 0;

    while ($row = mysqli_fetch_array($result)) {
        $kodeBarang = $row['Kode_Barang'];
        $namaBarang = $row['Nama_Barang'];

        // Format the quantities to remove unnecessary decimals
        $jumlahBarangMasuk = rtrim(rtrim(number_format($row['Jumlah_Barang_Masuk'], 5), '0'), '.');
        $jumlahBarangKeluar = rtrim(rtrim(number_format($row['Jumlah_Barang_Keluar'], 5), '0'), '.');

        // Calculate sisa barang
        $sisaBarang += $row['Jumlah_Barang_Masuk'] - $row['Jumlah_Barang_Keluar'];

        $transactions[] = array(
            'Tanggal Transaksi' => $row['Tanggal_Transaksi'],
            'Keterangan/Customer' => $row['Keterangan_Customer'],
            'Lot' => $row['Lot'],
            'Jumlah Barang Masuk' => $jumlahBarangMasuk,
            'Jumlah Barang Keluar' => $jumlahBarangKeluar,
            'Sisa Barang' => rtrim(rtrim(number_format($sisaBarang, 5), '0'), '.')
        );
    }

    if ($transactions) {
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Message' => 'Data retrieved successfully',
                'Data' => array(
                    'Kode Barang' => $kodeBarang,
                    'Nama Barang' => $namaBarang,
                    'Transaksi' => $transactions
                )
            )
        );
    } else {
        http_response_code(400);
        echo json_encode(
            array(
                'StatusCode' => 400,
                'Status' => 'Error',
                'Message' => 'No transactions found for the given criteria'
            )
        );
    }

} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            "Status" => "Error",
            "Message" => "API method not supported"
        )
    );
}

mysqli_close($connect);
?>
