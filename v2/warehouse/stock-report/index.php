<?php

require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../helpers/permission.php';
require_once __DIR__ . '/../../helpers/excel_export.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

const STOCK_REPORT_PERIODS = ['weekly', 'monthly', 'yearly'];
const STOCK_REPORT_PERMISSIONS = ['warehouse.stock.view', 'warehouse.report_weekly.view', 'warehouse.report_movement.view'];

const STOCK_REPORT_MONTH_NAMES = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];

function resolveStockReportPeriod($params) {
    $period = $params['period'] ?? 'weekly';
    if (!in_array($period, STOCK_REPORT_PERIODS, true)) {
        jsonResponse(400, 'period must be one of ' . implode(', ', STOCK_REPORT_PERIODS));
        return;
    }

    $year = isset($params['year']) && trim($params['year']) !== '' ? (int)$params['year'] : (int)date('Y');
    if ($year < 2000 || $year > 2100) {
        jsonResponse(400, 'year must be between 2000 and 2100');
        return;
    }

    if ($period === 'weekly') {
        $date_to   = isset($params['date_to']) && trim($params['date_to']) !== '' ? $params['date_to'] : date('Y-m-d');
        $date_from = isset($params['date_from']) && trim($params['date_from']) !== '' ? $params['date_from'] : date('Y-m-d', strtotime("$date_to -6 days"));
        foreach (['date_from' => $date_from, 'date_to' => $date_to] as $field => $value) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !strtotime($value)) {
                jsonResponse(400, "$field must be YYYY-MM-DD format");
                return;
            }
        }
        if (strtotime($date_from) > strtotime($date_to)) {
            jsonResponse(400, 'date_from must be before or equal to date_to');
            return;
        }
        if ((strtotime($date_to) - strtotime($date_from)) / 86400 > 31) {
            jsonResponse(400, 'weekly period cannot span more than 31 days');
            return;
        }
        $label = formatIndonesianDate($date_from) . ' - ' . formatIndonesianDate($date_to);
        return [$period, $date_from, $date_to, 'day', $label];
    }

    if ($period === 'monthly') {
        $month = isset($params['month']) && trim($params['month']) !== '' ? (int)$params['month'] : (int)date('n');
        if ($month < 1 || $month > 12) {
            jsonResponse(400, 'month must be between 1 and 12');
            return;
        }
        $date_from = sprintf('%04d-%02d-01', $year, $month);
        $date_to   = date('Y-m-t', strtotime($date_from));
        return [$period, $date_from, $date_to, 'day', STOCK_REPORT_MONTH_NAMES[$month] . " $year"];
    }

    return [$period, "$year-01-01", "$year-12-31", 'month', (string)$year];
}

function buildStockReportBuckets($date_from, $date_to, $granularity) {
    $buckets = [];
    if ($granularity === 'month') {
        for ($month = 1; $month <= 12; $month++) {
            $key = substr($date_from, 0, 4) . sprintf('-%02d', $month);
            $buckets[$key] = STOCK_REPORT_MONTH_NAMES[$month];
        }
        return $buckets;
    }

    for ($ts = strtotime($date_from); $ts <= strtotime($date_to); $ts = strtotime('+1 day', $ts)) {
        $buckets[date('Y-m-d', $ts)] = formatIndonesianDate(date('Y-m-d', $ts));
    }
    return $buckets;
}

// Lot balances can be imported without matching transaction history, so the
// opening balance is derived backwards from today's lot balance rather than
// summed forward from zero: balance(at D) = current - movements dated after D.
// Transfers move stock between lots of the same product, so they shift the
// balance math but are not reported as company-level in/out.
function buildStockReport($conn, $company_id, $date_from, $date_to, $granularity) {
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to   = mysqli_real_escape_string($conn, $date_to);
    $buckets   = buildStockReportBuckets($date_from, $date_to, $granularity);

    $products = [];
    $result = mysqli_query($conn, "SELECT p.id AS product_id, p.product_code, p.product_name, SUM(wl.end_balance) AS current_balance
        FROM " . APP_SCHEMA . ".warehouse_lot wl
        JOIN " . APP_SCHEMA . ".product p ON p.id = wl.product_id
        WHERE wl.company_id = '$company_id' AND wl.deleted_at IS NULL
        GROUP BY p.id, p.product_code, p.product_name
        ORDER BY p.product_name ASC");
    while ($row = mysqli_fetch_assoc($result)) {
        $products[$row['product_id']] = [
            'product_id'        => $row['product_id'],
            'product_code'      => $row['product_code'],
            'product_name'      => $row['product_name'],
            'current_balance'   => bcadd($row['current_balance'] ?? '0', '0', 3),
            'delta_from'        => '0',
            'delta_after_to'    => '0',
            'total_in'          => '0',
            'total_out'         => '0',
            'movements'         => [],
        ];
    }

    $bucket_sql = $granularity === 'month' ? "DATE_FORMAT(wt.transaction_date, '%Y-%m')" : "DATE_FORMAT(wt.transaction_date, '%Y-%m-%d')";
    $delta_sql  = "CASE wt.transaction_type WHEN 'stock_in' THEN ABS(wti.quantity) WHEN 'stock_out' THEN -ABS(wti.quantity) ELSE wti.quantity END";

    $result = mysqli_query($conn, "SELECT wl.product_id,
            $bucket_sql AS bucket,
            wt.transaction_date > '$date_to' AS is_after_period,
            SUM($delta_sql) AS delta,
            SUM(CASE WHEN wt.transaction_type = 'transfer' THEN 0 WHEN ($delta_sql) > 0 THEN ($delta_sql) ELSE 0 END) AS quantity_in,
            SUM(CASE WHEN wt.transaction_type = 'transfer' THEN 0 WHEN ($delta_sql) < 0 THEN -($delta_sql) ELSE 0 END) AS quantity_out
        FROM " . APP_SCHEMA . ".warehouse_transaction wt
        JOIN " . APP_SCHEMA . ".warehouse_transaction_item wti ON wti.warehouse_transaction_id = wt.id AND wti.deleted_at IS NULL
        JOIN " . APP_SCHEMA . ".warehouse_lot wl ON wl.id = wti.warehouse_lot_id AND wl.company_id = wt.company_id AND wl.deleted_at IS NULL
        WHERE wt.company_id = '$company_id' AND wt.deleted_at IS NULL AND wt.transaction_date >= '$date_from'
        GROUP BY wl.product_id, bucket, is_after_period");
    while ($row = mysqli_fetch_assoc($result)) {
        if (!isset($products[$row['product_id']])) continue;
        $product = &$products[$row['product_id']];
        $delta   = bcadd($row['delta'] ?? '0', '0', 3);

        $product['delta_from'] = bcadd($product['delta_from'], $delta, 3);
        if ((int)$row['is_after_period'] === 1) {
            $product['delta_after_to'] = bcadd($product['delta_after_to'], $delta, 3);
        } else {
            $quantity_in  = bcadd($row['quantity_in'] ?? '0', '0', 3);
            $quantity_out = bcadd($row['quantity_out'] ?? '0', '0', 3);
            $product['total_in']  = bcadd($product['total_in'], $quantity_in, 3);
            $product['total_out'] = bcadd($product['total_out'], $quantity_out, 3);
            $product['movements'][$row['bucket']] = ['in' => (float)$quantity_in, 'out' => (float)$quantity_out];
        }
        unset($product);
    }

    $data = [];
    foreach ($products as $product) {
        $movements = [];
        foreach ($buckets as $bucket => $label) {
            $movements[] = [
                'bucket' => $bucket,
                'label'  => $label,
                'in'     => $product['movements'][$bucket]['in'] ?? 0,
                'out'    => $product['movements'][$bucket]['out'] ?? 0,
            ];
        }
        $data[] = [
            'product_id'        => $product['product_id'],
            'product_code'      => $product['product_code'],
            'product_name'      => $product['product_name'],
            'beginning_balance' => (float)bcsub($product['current_balance'], $product['delta_from'], 3),
            'total_in'          => (float)$product['total_in'],
            'total_out'         => (float)$product['total_out'],
            'ending_balance'    => (float)bcsub($product['current_balance'], $product['delta_after_to'], 3),
            'movements'         => $movements,
        ];
    }

    return [$data, $buckets];
}

function getStockReport($conn, $company_id, $params) {
    [$period, $date_from, $date_to, $granularity, $label] = resolveStockReportPeriod($params);
    [$data] = buildStockReport($conn, $company_id, $date_from, $date_to, $granularity);

    jsonResponse(200, 'Stock report found', [
        'period'       => $period,
        'period_label' => $label,
        'date_from'    => $date_from,
        'date_to'      => $date_to,
        'granularity'  => $granularity,
        'data'         => $data,
    ]);
}

function exportStockReport($conn, $company_id, $params) {
    // Export returns binary .xlsx content — bypasses jsonResponse()
    [$period, $date_from, $date_to, $granularity, $label] = resolveStockReportPeriod($params);
    [$data, $buckets] = buildStockReport($conn, $company_id, $date_from, $date_to, $granularity);

    $titles = ['weekly' => 'LAPORAN STOK MINGGUAN', 'monthly' => 'LAPORAN STOK BULANAN', 'yearly' => 'LAPORAN STOK TAHUNAN'];
    $fixed_headers = ['No', 'Kode Produk', 'Nama Produk', 'Saldo Awal', 'Masuk', 'Keluar', 'Saldo Akhir'];
    $fixed_count   = count($fixed_headers);
    $last_column   = Coordinate::stringFromColumnIndex($fixed_count + count($buckets) * 2);

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Stok');

    $sheet->setCellValue('A1', $titles[$period]);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->mergeCells("A1:{$last_column}1");
    $sheet->setCellValue('A2', "Periode: $label");
    $sheet->mergeCells("A2:{$last_column}2");

    foreach ($fixed_headers as $index => $header) {
        $column = Coordinate::stringFromColumnIndex($index + 1);
        $sheet->setCellValue("{$column}4", $header);
        $sheet->mergeCells("{$column}4:{$column}5");
    }
    $column_index = $fixed_count + 1;
    foreach ($buckets as $bucket_label) {
        $in_column  = Coordinate::stringFromColumnIndex($column_index);
        $out_column = Coordinate::stringFromColumnIndex($column_index + 1);
        $sheet->setCellValue("{$in_column}4", $bucket_label);
        $sheet->mergeCells("{$in_column}4:{$out_column}4");
        $sheet->setCellValue("{$in_column}5", 'Masuk');
        $sheet->setCellValue("{$out_column}5", 'Keluar');
        $column_index += 2;
    }

    $header_range = "A4:{$last_column}5";
    $sheet->getStyle($header_range)->getFont()->setBold(true);
    $sheet->getStyle($header_range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->getStyle($header_range)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('EFF6F5');

    $row    = 6;
    $no     = 1;
    $totals = array_fill(0, 4 + count($buckets) * 2, 0);
    foreach ($data as $product) {
        $values = [$product['beginning_balance'], $product['total_in'], $product['total_out'], $product['ending_balance']];
        foreach ($product['movements'] as $movement) {
            $values[] = $movement['in'];
            $values[] = $movement['out'];
        }
        $sheet->fromArray(array_merge([$no, $product['product_code'] ?? '-', $product['product_name']], $values), null, "A$row", true);
        foreach ($values as $index => $value) {
            $totals[$index] += $value;
        }
        $row++;
        $no++;
    }

    $sheet->setCellValue("C$row", 'TOTAL');
    $sheet->fromArray($totals, null, "D$row", true);
    $sheet->getStyle("A$row:{$last_column}$row")->getFont()->setBold(true);

    $sheet->getStyle("A4:{$last_column}$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("D6:{$last_column}$row")->getNumberFormat()->setFormatCode('#,##0.###');
    $sheet->getColumnDimension('A')->setWidth(6);
    $sheet->getColumnDimension('B')->setWidth(16);
    $sheet->getColumnDimension('C')->setWidth(40);
    for ($index = 4; $index <= $fixed_count + count($buckets) * 2; $index++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(12);
    }
    $sheet->freezePane('D6');

    streamXlsx($spreadsheet, 'laporan_stok_' . $period . '_' . sanitizeFilename("{$date_from}_{$date_to}") . '.xlsx');
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser    = requireAuth();
$method      = $_SERVER['REQUEST_METHOD'];
$company_id  = $authUser['company_id'] ?? null;
$app_role_id = $authUser['app_role_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}
if ($method !== 'GET') {
    methodNotAllowed(['GET']);
    exit;
}

try {
    $conn = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $can_view = false;
    foreach (STOCK_REPORT_PERMISSIONS as $permission_key) {
        if (userHasPermission($conn, $app_role_id, $permission_key)) {
            $can_view = true;
            break;
        }
    }
    if (!$can_view) {
        jsonResponse(403, 'You do not have permission to view the stock report');
        return;
    }

    if ($action === 'export') {
        exportStockReport($conn, $company_id, $_GET);
    } else {
        getStockReport($conn, $company_id, $_GET);
    }
} catch (Exception $e) {
    handleModuleException($e);
}
