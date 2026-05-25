<?php
require_once 'TCPDF-main/tcpdf.php';

// Path to the TTF font file
$fontFile = '../tahoma.ttf'; // Make sure this path is correct

// Create a new TCPDF instance
$tcpdf = new TCPDF();

try {
    // Add TTF font to TCPDF and generate required font files
    $tcpdf->addTTFfont($fontFile, 'TrueTypeUnicode', '', 96);
    echo "Font added successfully. Check the TCPDF/fonts directory for generated files.";
} catch (Exception $e) {
    echo "Error adding font: " . $e->getMessage();
}
?>