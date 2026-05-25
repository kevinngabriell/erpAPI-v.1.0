<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $product_name = isset($_GET['product_name']) ? mysqli_real_escape_string($connect, $_GET['product_name']) : '';
    $sku_id       = isset($_GET['sku_id'])        ? mysqli_real_escape_string($connect, $_GET['sku_id'])        : '';
    $page         = isset($_GET['page'])          ? max(1, (int)$_GET['page'])  : 1;
    $limit        = isset($_GET['limit'])         ? max(1, (int)$_GET['limit']) : 50;
    $offset       = ($page - 1) * $limit;

    $where = "WHERE 1=1";
    if ($product_name) $where .= " AND A2.productName LIKE '%$product_name%'";
    if ($sku_id)       $where .= " AND A1.product = '$sku_id'";

    // Total count
    $countQuery = "
        SELECT COUNT(DISTINCT A1.product) AS total
        FROM warehouse A1
        LEFT JOIN product A2 ON A1.product = A2.skuID
        $where
    ";
    $countResult = mysqli_query($connect, $countQuery);
    if (!$countResult) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }
    $totalItems = (int)mysqli_fetch_assoc($countResult)['total'];

    // Stock per product — aggregate across all lots
    $stockQuery = "
        SELECT
            A2.skuID                        AS kode_produk,
            A2.productName                  AS nama_produk,
            COUNT(A1.lot)                   AS jumlah_lot,
            SUM(A1.endbalance)              AS total_stok,
            MIN(A1.endbalance)              AS stok_min_lot,
            MAX(A1.endbalance)              AS stok_max_lot
        FROM warehouse A1
        LEFT JOIN product A2 ON A1.product = A2.skuID
        $where
        GROUP BY A2.skuID, A2.productName
        ORDER BY A2.productName ASC
        LIMIT $limit OFFSET $offset
    ";

    $stockResult = mysqli_query($connect, $stockQuery);
    if (!$stockResult) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $stockData = mysqli_fetch_all($stockResult, MYSQLI_ASSOC);

    // For each product, also fetch lot-level breakdown
    foreach ($stockData as &$product) {
        $kode = mysqli_real_escape_string($connect, $product['kode_produk']);

        $lotQuery = "
            SELECT
                A1.lot,
                A1.endbalance           AS stok,
                A3.expiredDt            AS exp_date
            FROM warehouse A1
            LEFT JOIN warehouseTransaction A3 ON A1.lot = A3.lot AND A3.transactionType = '452c5015-e80f-4e8a-8'
            WHERE A1.product = '$kode'
            ORDER BY A3.expiredDt ASC
        ";
        $lotResult = mysqli_query($connect, $lotQuery);
        $lots = mysqli_fetch_all($lotResult, MYSQLI_ASSOC);

        foreach ($lots as &$lot) {
            $lot['stok'] = (float)$lot['stok'];
        }

        $product['total_stok']   = (float)$product['total_stok'];
        $product['stok_min_lot'] = (float)$product['stok_min_lot'];
        $product['stok_max_lot'] = (float)$product['stok_max_lot'];
        $product['jumlah_lot']   = (int)$product['jumlah_lot'];
        $product['lots']         = $lots;
    }

    echo json_encode([
        'StatusCode' => 200,
        'Status'     => 'Success',
        'totalItems' => $totalItems,
        'page'       => $page,
        'limit'      => $limit,
        'Data'       => $stockData
    ]);
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
