<?php
// Header access is required
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../../connection/connection.php');

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['company_id'])) {
        $company_id = $_GET['company_id'];

        // Get current year
        $current_year = date('Y');

        // Prepare the SQL query to prevent SQL injection
        $stmt = $connect->prepare("SELECT targeting_id, target_year, target_value FROM targeting WHERE company = ? ORDER BY target_year ASC");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $targeting_array = [];
        while ($row = $result->fetch_assoc()) {
            $targeting_array[] = array(
                'targeting_id' => $row['targeting_id'],
                'target_year' => $row['target_year'],
                'target_value' => $row['target_value']
            );
        }

        if (!empty($targeting_array)) {
            echo json_encode([
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $targeting_array
            ]);
        } else {
            http_response_code(404);
            echo json_encode([
                'StatusCode' => 404,
                'Status' => 'Error',
                'Message' => 'No data found for the specified company.'
            ]);
        }
    } else {
        http_response_code(400);
        echo json_encode([
            "StatusCode" => 400,
            'Status' => 'Error',
            "Message" => "Error: Missing required parameter company_id."
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        "StatusCode" => 405,
        'Status' => 'Error',
        "Message" => "Error: Invalid method. Only GET requests are allowed."
    ]);
}
?>
