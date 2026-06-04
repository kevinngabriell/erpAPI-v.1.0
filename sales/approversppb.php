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

// Check if the request method is GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get the SPPBNumber from the GET request
    $sppb_number = $_GET['sppb_number'] ?? null;

    if (!$sppb_number) {
        send_error_response(400, "Missing required field: sppb_number");
    }

    try {
        // Query to get the datetime when the SPPB was created
        $created_query = "SELECT A1.InsertDt
                          FROM salesSPPB A1
                          WHERE A1.SPPBNumber = ?";
        $stmt_created = mysqli_prepare($connect, $created_query);
        mysqli_stmt_bind_param($stmt_created, 's', $sppb_number);
        mysqli_stmt_execute($stmt_created);
        $result_created = mysqli_stmt_get_result($stmt_created);
        $created_data = mysqli_fetch_assoc($result_created);

        // Query to get the person who approved the SPPB
        $approver_query = "SELECT A3.first_name, A3.last_name, A2.ActionDt
                           FROM salesOrderHistory A2
                           LEFT JOIN user A3 ON A2.ActionBy = A3.username
                           WHERE A2.SONumber = ? AND A2.Action = 'Draft SPPB telah disetujui'";
        $stmt_approver = mysqli_prepare($connect, $approver_query);
        mysqli_stmt_bind_param($stmt_approver, 's', $sppb_number);
        mysqli_stmt_execute($stmt_approver);
        $result_approver = mysqli_stmt_get_result($stmt_approver);
        $approver_data = mysqli_fetch_assoc($result_approver);

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
    send_error_response(404, "Invalid request method. Only GET and OPTIONS requests are allowed.");
}

// Close the database connection
mysqli_close($connect);
?>
