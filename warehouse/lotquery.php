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

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['find'])) {
        $find = $_GET['find'];
        $find = "%$find%";

        // Prepare the SQL statement
        $query = "SELECT A1.lot, A2.skuID, A2.productName, A1.endbalance
                  FROM warehouse A1
                  LEFT JOIN product A2 ON A1.product = A2.skuID
                  WHERE A1.lot LIKE ? OR A2.skuID LIKE ? OR A2.productName LIKE ?";
        $stmt = $connect->prepare($query);
        $stmt->bind_param("sss", $find, $find, $find);

        // Execute the statement
        $stmt->execute();
        $result = $stmt->get_result();

        // Fetch the results
        $products = $result->fetch_all(MYSQLI_ASSOC);

        // Send the response
        http_response_code(200);
        echo json_encode(
            array(
                "StatusCode" => 200,
                "Status" => "Success",
                "products" => $products
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
