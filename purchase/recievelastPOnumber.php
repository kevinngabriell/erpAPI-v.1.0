<?php
// Header access is required

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Convert month number to Roman numeral
function monthToRoman($num) {
    $map = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    return $map[$num - 1];
}

// Generate new PONumber
function generatePONumber($lastPONumber) {
    // Current date components
    $currentYear = date('y');  // Ensure this matches the format of the year in PONumber (last two digits)
    $currentMonthRoman = monthToRoman(date('n'));
    
    // Decompose last PONumber
    if ($lastPONumber && preg_match('/VIK\/(\d{2})\/([IVXLCDM]+)\/(\d{4})/', $lastPONumber, $matches)) {
        list(, $lastYear, $lastMonthRoman, $lastSequence) = $matches;
        if ($currentYear == $lastYear) {
            $newSequence = sprintf('%04d', (int)$lastSequence + 1);
        } else {
            $newSequence = '0001';
        }
    } else {
        // Default case if no valid last number found or no match
        $newSequence = '0001';
    }

    return "VIK/{$currentYear}/{$currentMonthRoman}/{$newSequence}";
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lastPO_query = "SELECT PONumber FROM purchaseOrder ORDER BY PONumber DESC LIMIT 1;";
    $lastPO_result = mysqli_query($connect, $lastPO_query);
    $lastPONumber = mysqli_fetch_array($lastPO_result)['PONumber'] ?? '';

    $newPONumber = generatePONumber($lastPONumber);

    if ($newPONumber) {
        echo json_encode([
            'StatusCode' => 200,
            'Status' => 'Success',
            'Data' => ['PONumber' => $newPONumber]
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'StatusCode' => 400,
            'Status' => 'Error Bad Request, Result not found !'
        ]);
    }
} else {
    http_response_code(404);
    echo json_encode([
        "StatusCode" => 404,
        'Status' => 'Error',
        "message" => "Error: Invalid method. Only GET requests are allowed."
    ]);
}
?>
