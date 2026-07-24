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
    $startdate = isset($_GET['startdate']) ? $_GET['startdate'] : '';
    $enddate = isset($_GET['enddate']) ? $_GET['enddate'] : '';
    $SONumber = isset($_GET['SONumber']) ? $_GET['SONumber'] : '';

    $query = "SELECT A1.SONumber, A1.SODate, A2.company_name, A3.SO_Status_Name
              FROM salesOrder A1
              LEFT JOIN customer A2 ON A1.SOCustomer = A2.company_id
              LEFT JOIN salesStatus A3 ON A1.SOStatus = A3.SO_Status_ID";

    $conditions = array();

    if ($startdate && $enddate) {
        $conditions[] = "A1.SODate BETWEEN '$startdate' AND '$enddate'";
    }

    if ($SONumber) {
        $conditions[] = "A1.SONumber LIKE '%$SONumber%'";
    }

    if (!empty($conditions)) {
        $query .= " WHERE " . implode(' AND ', $conditions);
    }

    $query .= " ORDER BY A1.InsertDt DESC";

    $result = mysqli_query($connect, $query);

    if (!$result) {
        http_response_code(500);
        echo json_encode(
            array(
                'StatusCode' => 500,
                'Status' => 'Error',
                'message' => 'Error executing query: ' . mysqli_error($connect)
            )
        );
        exit();
    }

    $array = array();
    while($row = mysqli_fetch_array($result)){
        array_push(
            $array,
            array(
                'SONumber' => $row['SONumber'],
                'SODate' => $row['SODate'],
                'company_name' => $row['company_name'],
                'SO_Status_Name' => $row['SO_Status_Name']
            )
        );
    }

    if($array){
        echo json_encode(
            array(
                'StatusCode' => 200,
                'Status' => 'Success',
                'Data' => $array
            )
        );
    } else {
        http_response_code(400);
        echo json_encode(
            array(
                'StatusCode' => 400,
                'Status' => 'Error Bad Request, Result not found!'
            )
        );
    }
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
