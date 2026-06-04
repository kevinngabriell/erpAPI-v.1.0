<?php
// HPP (Harga Pokok Penjualan / Cost of Goods Sold)
// Formula: HPP = Persediaan Awal + Pembelian Periode - Persediaan Akhir
// Purchase value comes from purchaseOrderItem (POQuantity * POUnitPrice)
// Inventory value comes from warehouse.endbalance × average purchase cost per product

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $start_date = isset($_GET['start_date']) ? mysqli_real_escape_string($connect, $_GET['start_date']) : null;
    $end_date   = isset($_GET['end_date'])   ? mysqli_real_escape_string($connect, $_GET['end_date'])   : null;

    if (!$start_date || !$end_date) {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Bad Request', 'message' => 'start_date and end_date are required']);
        exit;
    }

    // Average purchase cost per product (from all-time purchases, used as cost basis)
    $avgCostQuery = "
        SELECT
            A2.POProductName          AS nama_produk,
            SUM(A2.POQuantity)        AS total_qty_beli,
            SUM(A2.POQuantity * A2.POUnitPrice) AS total_nilai_beli,
            CASE WHEN SUM(A2.POQuantity) > 0
                 THEN SUM(A2.POQuantity * A2.POUnitPrice) / SUM(A2.POQuantity)
                 ELSE 0 END           AS harga_rata_rata
        FROM purchaseOrder A1
        LEFT JOIN purchaseOrderItem A2 ON A1.PONumber = A2.PONumber
        WHERE A2.POProductName IS NOT NULL
        GROUP BY A2.POProductName
    ";

    // Purchases during the period
    $pembelianQuery = "
        SELECT
            A2.POProductName          AS nama_produk,
            SUM(A2.POQuantity)        AS qty_beli,
            SUM(A2.POQuantity * A2.POUnitPrice) AS nilai_pembelian
        FROM purchaseOrder A1
        LEFT JOIN purchaseOrderItem A2 ON A1.PONumber = A2.PONumber
        WHERE A1.PODate BETWEEN '$start_date' AND '$end_date'
          AND A2.POProductName IS NOT NULL
        GROUP BY A2.POProductName
        ORDER BY nilai_pembelian DESC
    ";

    // Current warehouse stock (closing inventory) per product
    $stokAkhirQuery = "
        SELECT
            A2.productName            AS nama_produk,
            SUM(A1.endbalance)        AS stok_akhir
        FROM warehouse A1
        LEFT JOIN product A2 ON A1.product = A2.skuID
        WHERE A2.productName IS NOT NULL
        GROUP BY A2.productName
    ";

    // Goods sold during the period (from salesInvoiceItem)
    $penjualanQuery = "
        SELECT
            A2.productName,
            SUM(A2.productQuantity)                      AS qty_terjual,
            SUM(A2.productQuantity * A2.unitPrice)       AS nilai_penjualan
        FROM salesInvoice A1
        LEFT JOIN salesInvoiceItem A2 ON A1.invoiceNumber = A2.DONumber
        WHERE A1.invoiceDate BETWEEN '$start_date' AND '$end_date'
          AND A2.productName IS NOT NULL
        GROUP BY A2.productName
        ORDER BY nilai_penjualan DESC
    ";

    $avgCostResult   = mysqli_query($connect, $avgCostQuery);
    $pembelianResult = mysqli_query($connect, $pembelianQuery);
    $stokAkhirResult = mysqli_query($connect, $stokAkhirQuery);
    $penjualanResult = mysqli_query($connect, $penjualanQuery);

    if (!$avgCostResult || !$pembelianResult || !$stokAkhirResult || !$penjualanResult) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    // Build lookup maps
    $avgCostMap   = [];
    while ($row = mysqli_fetch_assoc($avgCostResult)) {
        $avgCostMap[$row['nama_produk']] = (float)$row['harga_rata_rata'];
    }

    $stokAkhirMap = [];
    while ($row = mysqli_fetch_assoc($stokAkhirResult)) {
        $stokAkhirMap[$row['nama_produk']] = (float)$row['stok_akhir'];
    }

    $pembelian = mysqli_fetch_all($pembelianResult, MYSQLI_ASSOC);
    $penjualan = mysqli_fetch_all($penjualanResult, MYSQLI_ASSOC);

    // Total purchases value in period
    $total_pembelian = array_sum(array_column($pembelian, 'nilai_pembelian'));

    // HPP per product = qty_terjual × average purchase cost
    $hpp_per_produk = [];
    $total_hpp = 0.0;
    foreach ($penjualan as $item) {
        $nama_produk   = $item['productName'];
        $qty_terjual   = (float)$item['qty_terjual'];
        $harga_rata    = $avgCostMap[$nama_produk] ?? 0.0;
        $hpp_produk    = $qty_terjual * $harga_rata;
        $stok_akhir    = $stokAkhirMap[$nama_produk] ?? 0.0;
        $total_hpp    += $hpp_produk;

        $hpp_per_produk[] = [
            'nama_produk'       => $nama_produk,
            'qty_terjual'       => $qty_terjual,
            'harga_pokok_rata'  => $harga_rata,
            'hpp'               => $hpp_produk,
            'nilai_penjualan'   => (float)$item['nilai_penjualan'],
            'gross_profit'      => (float)$item['nilai_penjualan'] - $hpp_produk,
            'stok_akhir'        => $stok_akhir
        ];
    }

    // Total current closing inventory value
    $total_nilai_stok_akhir = 0.0;
    foreach ($stokAkhirMap as $produk => $qty) {
        $avg = $avgCostMap[$produk] ?? 0.0;
        $total_nilai_stok_akhir += $qty * $avg;
    }

    $total_nilai_penjualan = array_sum(array_column($penjualan, 'nilai_penjualan'));
    $gross_profit = $total_nilai_penjualan - $total_hpp;

    echo json_encode([
        'StatusCode' => 200,
        'Status'     => 'Success',
        'period'     => ['start_date' => $start_date, 'end_date' => $end_date],
        'summary' => [
            'total_pembelian_periode'   => (float)$total_pembelian,
            'total_nilai_stok_akhir'    => (float)$total_nilai_stok_akhir,
            'total_hpp'                 => (float)$total_hpp,
            'total_nilai_penjualan'     => (float)$total_nilai_penjualan,
            'gross_profit'              => (float)$gross_profit
        ],
        'pembelian_periode' => array_map(fn($r) => [
            'nama_produk'    => $r['nama_produk'],
            'qty_beli'       => (float)$r['qty_beli'],
            'nilai_pembelian'=> (float)$r['nilai_pembelian']
        ], $pembelian),
        'hpp_per_produk' => $hpp_per_produk
    ]);
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
