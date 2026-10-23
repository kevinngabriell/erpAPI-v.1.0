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

// Signer names printed under the "DIBUAT / MENGETAHUI / DISETUJUI OLEH" blocks are configured per
// company in document_signature. A slot holds one or more names separated by commas; each name is
// rendered as "( NAME )". An unconfigured slot renders an empty "(        )" box to sign by hand.
function formatSignatureNames(?string $names): string {
    $list = array_filter(array_map('trim', explode(',', (string)$names)), 'strlen');
    if (!$list) return '(                    )';

    return implode('    ', array_map(fn($name) => '( ' . mb_strtoupper($name) . ' )', $list));
}

function getDocumentSignatures($conn, string $company_id, string $document_type): array {
    $signatures = ['created' => null, 'acknowledged' => null, 'approved' => null];

    $company_id    = mysqli_real_escape_string($conn, $company_id);
    $document_type = mysqli_real_escape_string($conn, $document_type);
    $result = @mysqli_query($conn, "SELECT created_by_names, acknowledged_by_names, approved_by_names
            FROM " . APP_SCHEMA . ".document_signature
            WHERE company_id = '$company_id' AND document_type = '$document_type' AND deleted_at IS NULL LIMIT 1");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        $signatures = [
            'created'      => $row['created_by_names'],
            'acknowledged' => $row['acknowledged_by_names'],
            'approved'     => $row['approved_by_names'],
        ];
    }

    return array_map('formatSignatureNames', $signatures);
}
