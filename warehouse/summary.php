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

// Get current datetime in Indonesia/Jakarta timezone
$currentDateTime = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

// Calculate the date 30 days ago and 30 days in the future
$date30DaysAgo = date('Y-m-d', strtotime('-30 days', strtotime($currentDateTimeString)));
$dateIn30Days = date('Y-m-d', strtotime('+30 days', strtotime($currentDateTimeString)));

// Query to get total count of items in warehouse
$total_items_query = "SELECT COUNT(*) AS totalItems FROM warehouse";
$total_items_result = mysqli_query($connect, $total_items_query);

if (!$total_items_result) {
    http_response_code(500);
    echo json_encode(array(
        "StatusCode" => 500,
        "Status" => "Error",
        "message" => "Error: Failed to fetch total items."
    ));
    exit;
}

$total_items = mysqli_fetch_assoc($total_items_result)['totalItems'];

// Query to get count of items expiring in the next 30 days
$expiring_items_query = "SELECT COUNT(*) AS expiringItems FROM warehouseTransaction WHERE expiredDt BETWEEN '$currentDateTimeString' AND '$dateIn30Days'";
$expiring_items_result = mysqli_query($connect, $expiring_items_query);

if (!$expiring_items_result) {
    http_response_code(500);
    echo json_encode(array(
        "StatusCode" => 500,
        "Status" => "Error",
        "message" => "Error: Failed to fetch expiring items."
    ));
    exit;
}

$expiring_items = mysqli_fetch_assoc($expiring_items_result)['expiringItems'];

// Query to get total quantity of items received in the last 30 days
$received_items_query = "SELECT SUM(quantity) AS receivedItems FROM warehouseTransaction WHERE transactionType = '452c5015-e80f-4e8a-8' AND transactionDate BETWEEN '$date30DaysAgo' AND '$currentDateTimeString'";
$received_items_result = mysqli_query($connect, $received_items_query);

if (!$received_items_result) {
    http_response_code(500);
    echo json_encode(array(
        "StatusCode" => 500,
        "Status" => "Error",
        "message" => "Error: Failed to fetch received items."
    ));
    exit;
}

$received_items = mysqli_fetch_assoc($received_items_result)['receivedItems'];

// Query to get total quantity of items issued in the last 30 days
$issued_items_query = "SELECT SUM(quantity) AS issuedItems FROM warehouseTransaction WHERE transactionType = '9cafab5b-d975-41e8-8' AND transactionDate BETWEEN '$date30DaysAgo' AND '$currentDateTimeString'";
$issued_items_result = mysqli_query($connect, $issued_items_query);

if (!$issued_items_result) {
    http_response_code(500);
    echo json_encode(array(
        "StatusCode" => 500,
        "Status" => "Error",
        "message" => "Error: Failed to fetch issued items."
    ));
    exit;
}

$issued_items = mysqli_fetch_assoc($issued_items_result)['issuedItems'];

// Combine results into a summary report
$summary_report = array(
    "totalItems" => (int)$total_items,
    "expiringItems" => (int)$expiring_items,
    "receivedItems" => (int)$received_items,
    "issuedItems" => (int)$issued_items
);

// If successful
http_response_code(200);
echo json_encode(array(
    "StatusCode" => 200,
    "Status" => "Success",
    "message" => "Summary report generated successfully.",
    "data" => $summary_report
));

// Close database connection
mysqli_close($connect);
?>
