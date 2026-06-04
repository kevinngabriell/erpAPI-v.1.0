<?php
// Penyusutan Aset Tetap (Fixed Asset Depreciation)
// Requires table: fixed_assets
// CREATE TABLE fixed_assets (
//   asset_id       VARCHAR(36)    PRIMARY KEY,
//   asset_code     VARCHAR(50)    NOT NULL UNIQUE,
//   asset_name     VARCHAR(255)   NOT NULL,
//   category       VARCHAR(100),
//   purchase_date  DATE           NOT NULL,
//   purchase_price DECIMAL(18,2)  NOT NULL,
//   residual_value DECIMAL(18,2)  NOT NULL DEFAULT 0,
//   useful_life    INT            NOT NULL,  -- in months
//   method         ENUM('straight-line','declining-balance') NOT NULL DEFAULT 'straight-line',
//   is_active      TINYINT(1)     NOT NULL DEFAULT 1,
//   insert_by      VARCHAR(100),
//   insert_dt      DATETIME
// );

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $as_of_date = isset($_GET['as_of_date']) ? mysqli_real_escape_string($connect, $_GET['as_of_date']) : date('Y-m-d');
    $category   = isset($_GET['category'])   ? mysqli_real_escape_string($connect, $_GET['category'])   : '';

    $cat_filter = $category ? "AND category = '$category'" : '';

    // Check if fixed_assets table exists
    $tableCheck = mysqli_query($connect, "SHOW TABLES LIKE 'fixed_assets'");
    if (mysqli_num_rows($tableCheck) === 0) {
        http_response_code(404);
        echo json_encode([
            'StatusCode' => 404,
            'Status'     => 'Table Not Found',
            'message'    => 'Table fixed_assets does not exist. Please create it first.',
            'schema'     => [
                'sql' => "CREATE TABLE fixed_assets (
  asset_id       VARCHAR(36)    PRIMARY KEY,
  asset_code     VARCHAR(50)    NOT NULL UNIQUE,
  asset_name     VARCHAR(255)   NOT NULL,
  category       VARCHAR(100),
  purchase_date  DATE           NOT NULL,
  purchase_price DECIMAL(18,2)  NOT NULL,
  residual_value DECIMAL(18,2)  NOT NULL DEFAULT 0,
  useful_life    INT            NOT NULL COMMENT 'in months',
  method         ENUM('straight-line','declining-balance') NOT NULL DEFAULT 'straight-line',
  is_active      TINYINT(1)     NOT NULL DEFAULT 1,
  insert_by      VARCHAR(100),
  insert_dt      DATETIME
);"
            ]
        ]);
        exit;
    }

    $assetQuery = "
        SELECT asset_id, asset_code, asset_name, category,
               purchase_date, purchase_price, residual_value,
               useful_life, method, is_active
        FROM fixed_assets
        WHERE is_active = 1
          $cat_filter
        ORDER BY category, purchase_date
    ";

    $result = mysqli_query($connect, $assetQuery);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => mysqli_error($connect)]);
        exit;
    }

    $assets = mysqli_fetch_all($result, MYSQLI_ASSOC);
    $total_nilai_buku          = 0.0;
    $total_akumulasi_penyusutan = 0.0;
    $total_penyusutan_periode   = 0.0;

    foreach ($assets as &$asset) {
        $purchase_price  = (float)$asset['purchase_price'];
        $residual_value  = (float)$asset['residual_value'];
        $useful_life     = (int)$asset['useful_life'];
        $purchase_date   = $asset['purchase_date'];
        $method          = $asset['method'];

        // Months elapsed since purchase up to as_of_date
        $dt_purchase = new DateTime($purchase_date);
        $dt_as_of    = new DateTime($as_of_date);
        $months_elapsed = (int)$dt_purchase->diff($dt_as_of)->days / 30.4375;
        $months_elapsed = min((int)round($months_elapsed), $useful_life);

        $depreciable_amount = $purchase_price - $residual_value;

        if ($method === 'straight-line') {
            $monthly_dep    = $useful_life > 0 ? $depreciable_amount / $useful_life : 0;
            $akumulasi_dep  = $monthly_dep * $months_elapsed;
            $dep_periode    = $monthly_dep * 12; // annual
        } else {
            // Declining balance: rate = 2 / useful_life_years
            $annual_rate   = $useful_life > 0 ? 2 / ($useful_life / 12) : 0;
            $book_val      = $purchase_price;
            $akumulasi_dep = 0.0;
            $years_elapsed = (int)($months_elapsed / 12);
            for ($y = 0; $y < $years_elapsed; $y++) {
                $dep = $book_val * $annual_rate;
                $akumulasi_dep += $dep;
                $book_val -= $dep;
            }
            $dep_periode = $book_val * $annual_rate;
        }

        $nilai_buku = max($purchase_price - $akumulasi_dep, $residual_value);

        $asset['purchase_price']          = $purchase_price;
        $asset['residual_value']          = $residual_value;
        $asset['depreciable_amount']      = (float)$depreciable_amount;
        $asset['months_elapsed']          = $months_elapsed;
        $asset['akumulasi_penyusutan']    = round($akumulasi_dep, 2);
        $asset['penyusutan_per_tahun']    = round($dep_periode, 2);
        $asset['penyusutan_per_bulan']    = round($dep_periode / 12, 2);
        $asset['nilai_buku']              = round($nilai_buku, 2);

        $total_nilai_buku           += $nilai_buku;
        $total_akumulasi_penyusutan += $akumulasi_dep;
        $total_penyusutan_periode   += $dep_periode;
    }

    echo json_encode([
        'StatusCode' => 200,
        'Status'     => 'Success',
        'as_of_date' => $as_of_date,
        'summary' => [
            'total_aset'                  => count($assets),
            'total_harga_perolehan'       => (float)array_sum(array_column($assets, 'purchase_price')),
            'total_akumulasi_penyusutan'  => round($total_akumulasi_penyusutan, 2),
            'total_penyusutan_per_tahun'  => round($total_penyusutan_periode, 2),
            'total_nilai_buku'            => round($total_nilai_buku, 2)
        ],
        'Data' => $assets
    ]);
} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Method Not Allowed']);
}
?>
