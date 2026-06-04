<?php
// Header access is required

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

function send_success_response($message) {
    http_response_code(200);
    echo json_encode(
        array(
            "StatusCode" => 200,
            'Status' => 'Success',
            "message" => $message
        )
    );
    exit();
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the sales_order_number from the POST request
    $sales_order_number = $_POST['sales_order_number'] ?? null;

    if (!$sales_order_number) {
        send_error_response(400, "Missing required field: sales_order_number");
    }

    // Begin transaction
    mysqli_begin_transaction($connect);

    try {
        // Delete from salesOrderItem table
        $delete_items_query = "DELETE FROM salesOrderItem WHERE salesOrderNumber = ?";
        $stmt = mysqli_prepare($connect, $delete_items_query);
        mysqli_stmt_bind_param($stmt, 's', $sales_order_number);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Error deleting from salesOrderItem table: " . mysqli_error($connect));
        }

        // Delete from salesOrderHistory table
        $delete_history_query = "DELETE FROM salesOrderHistory WHERE SONumber = ?";
        $stmt = mysqli_prepare($connect, $delete_history_query);
        mysqli_stmt_bind_param($stmt, 's', $sales_order_number);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Error deleting from salesOrderHistory table: " . mysqli_error($connect));
        }

        // Delete from salesOrder table
        $delete_order_query = "DELETE FROM salesOrder WHERE SONumber = ?";
        $stmt = mysqli_prepare($connect, $delete_order_query);
        mysqli_stmt_bind_param($stmt, 's', $sales_order_number);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Error deleting from salesOrder table: " . mysqli_error($connect));
        }

        // Commit transaction
        mysqli_commit($connect);

        send_success_response("Sales order deleted successfully");

    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($connect);
        send_error_response(500, $e->getMessage());
    } finally {
        // Close the statement
        mysqli_stmt_close($stmt);
    }
} else {
    send_error_response(404, "Invalid request method. Only POST requests are allowed.");
}

// Close the database connection
mysqli_close($connect);
?>
