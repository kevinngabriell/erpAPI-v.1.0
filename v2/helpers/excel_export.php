<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function formatIndonesianDate($date) {
    if (!$date) return '-';

    $month_names = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    $timestamp = strtotime($date);
    $day       = date('j', $timestamp);
    $month     = $month_names[(int)date('n', $timestamp)];
    $year      = date('Y', $timestamp);

    return "$day $month $year";
}

function sanitizeFilename(string $value): string {
    return preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
}

function streamXlsx($spreadsheet, string $filename): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
