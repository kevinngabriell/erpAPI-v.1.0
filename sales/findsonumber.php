<?php
// Header access is required

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

if($_SERVER['REQUEST_METHOD'] === 'GET'){
    if (isset($_GET['find'])) {
        $find = $_GET['find'];
        $find = "%$find%";

        // Prepare the SQL statement
        $query = "SELECT SONumber
                    FROM salesOrder
                    WHERE SONumber LIKE ?";
        $stmt = $connect->prepare($query);
        $stmt->bind_param("s", $find);

        // Execute the statement
        $stmt->execute();
        $result = $stmt->get_result();

        // Fetch the results
        $deliveries = $result->fetch_all(MYSQLI_ASSOC);

        // Send the response
        http_response_code(200);
        echo json_encode(
            array(
                "StatusCode" => 200,
                "Status" => "Success",
                "Data" => $deliveries
            )
        );
    } else {
        http_response_code(400);
        echo json_encode(
            array(
                "StatusCode" => 400,
                "Status" => "Error",
                "message" => "Error: Missing 'find' parameter."
            )
        );
    }
} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            "Status" => "Error",
            "message" => "Error: Invalid method. Only GET requests are allowed."
        )
    );
}

?>