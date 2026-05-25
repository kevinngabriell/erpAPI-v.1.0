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

function send_error_response($code, $message) {
    http_response_code($code);
    echo json_encode(
        array(
            "StatusCode" => $code,
            'Status' => 'Error',
            "message" => $message
        )
    );
    exit();
}

function send_success_response($data) {
    http_response_code(200);
    echo json_encode(
        array(
            "StatusCode" => 200,
            'Status' => 'Success',
            "Data" => $data
        )
    );
    exit();
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get the sales_order_number from the POST request
    $sales_order_number = $_GET['sales_order_number'] ?? null;

    if (!$sales_order_number) {
        send_error_response(400, "Missing required field: sales_order_number");
    }

    try {
        // Query to get the person who approved the sales order
        $approver_query = "SELECT A1.ActionDt, A2.first_name, A2.last_name
                           FROM salesOrderHistory A1
                           LEFT JOIN user A2 ON A1.ActionBy = A2.username
                           WHERE A1.SONumber = ? AND A1.Action = 'Draft sales order telah disetujui'";
        $stmt_approver = mysqli_prepare($connect, $approver_query);
        mysqli_stmt_bind_param($stmt_approver, 's', $sales_order_number);
        mysqli_stmt_execute($stmt_approver);
        $result_approver = mysqli_stmt_get_result($stmt_approver);
        $approver_data = mysqli_fetch_assoc($result_approver);
        
        // Query to get the datetime when the sales order was created
        $created_query = "SELECT A1.InsertDt
                          FROM salesOrder A1
                          WHERE A1.SONumber = ?";
        $stmt_created = mysqli_prepare($connect, $created_query);
        mysqli_stmt_bind_param($stmt_created, 's', $sales_order_number);
        mysqli_stmt_execute($stmt_created);
        $result_created = mysqli_stmt_get_result($stmt_created);
        $created_data = mysqli_fetch_assoc($result_created);

        // Combine the results
        $response_data = [
            'approver' => $approver_data,
            'created' => $created_data
        ];

        send_success_response($response_data);

    } catch (Exception $e) {
        send_error_response(500, $e->getMessage());
    } finally {
        // Close the statements
        mysqli_stmt_close($stmt_approver);
        mysqli_stmt_close($stmt_created);
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Handle preflight request
    http_response_code(200);
} else {
    send_error_response(404, "Invalid request method. Only POST and OPTIONS requests are allowed.");
}

// Close the database connection
mysqli_close($connect);
?>
