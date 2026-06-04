<?php
// Header access is required

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $currentMonth = date('F');
    
    // Define your queries
    $query_total_draft = "SELECT COUNT(*) as total_draft
    FROM purchaseOrder A1
    WHERE A1.POStatus = 'd7ab6134-d157-11ee-8';";

    $query_total_approved = "SELECT COUNT(*) as total_approved
    FROM purchaseOrder A1
    WHERE A1.POStatus = 'e71e4fc4-d157-11ee-8';";

    $query_total_received = "SELECT COUNT(*) as total_received
    FROM purchaseOrder A1
    WHERE A1.POStatus = 'e73d9d9c-1438-11ef-9';";

    $query_total_invoice = "SELECT COUNT(*) as total_invoice
    FROM purchaseOrder A1
    WHERE A1.POStatus = 'e4376c01-1438-11ef-9';";

    // Execute each query and store the results
    $result_total_draft = mysqli_query($connect, $query_total_draft);
    $result_total_approved = mysqli_query($connect, $query_total_approved);
    $result_total_received = mysqli_query($connect, $query_total_received);
    $result_total_invoice = mysqli_query($connect, $query_total_invoice);

    // Initialize array to store results
    $data = array(
        'CurrentMonth' => $currentMonth,
        'total_draft' => 0,
        'total_approved' => 0,
        'total_received' => 0,
        'total_invoice' => 0
    );

    // Fetch and store each result
    if ($result_total_draft) {
        $row = mysqli_fetch_assoc($result_total_draft);
        $data['total_draft'] = $row['total_draft'];
    }
    if ($result_total_approved) {
        $row = mysqli_fetch_assoc($result_total_approved);
        $data['total_approved'] = $row['total_approved'];
    }
    if ($result_total_received) {
        $row = mysqli_fetch_assoc($result_total_received);
        $data['total_received'] = $row['total_received'];
    }
    if ($result_total_invoice) {
        $row = mysqli_fetch_assoc($result_total_invoice);
        $data['total_invoice'] = $row['total_invoice'];
    }

    // Output the results
    echo json_encode(
        array(
            'StatusCode' => 200,
            'Status' => 'Success',
            'Data' => $data
        )
    );

} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            'Status' => 'Error',
            "message" => "Error: Invalid method. Only GET requests are allowed."
        )
    );
}
?>
