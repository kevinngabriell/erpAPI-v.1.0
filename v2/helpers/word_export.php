<?php

require_once __DIR__ . '/../../vendor/autoload.php';

\PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);

function streamDocx(\PhpOffice\PhpWord\PhpWord $document, string $filename): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($document, 'Word2007');
    $writer->save('php://output');
    exit;
}

function streamTemplateDocx(\PhpOffice\PhpWord\TemplateProcessor $template, string $filename): void {
    $temp_path = $template->save();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Content-Length: ' . filesize($temp_path));

    readfile($temp_path);
    unlink($temp_path);
    exit;
}
