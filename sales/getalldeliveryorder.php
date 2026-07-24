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
    $DONumber = isset($_GET['DONumber']) ? $_GET['DONumber'] : '';

    $query = "SELECT A1.DONumber, A1.DeliveryDate, A2.company_name, A4.SO_Status_Name, A1.SONumber
              FROM salesDelivery A1
              LEFT JOIN customer A2 ON A1.customerID = A2.company_id
              LEFT JOIN salesOrder A3 ON A1.SONumber = A3.SONumber
              LEFT JOIN salesStatus A4 ON A4.SO_Status_ID = A3.SOStatus";
    
    $conditions = array();

    if ($startdate && $enddate && $startdate !== $enddate) {
        $conditions[] = "A1.DeliveryDate BETWEEN '$startdate' AND '$enddate'";
    }

    if ($DONumber) {
        $conditions[] = "A1.SONumber = '$DONumber'";
    }

    if (!empty($conditions)) {
        $query .= " WHERE " . implode(' AND ', $conditions);
    }

    $result = mysqli_query($connect, $query);

    $array = array();
    while($row = mysqli_fetch_array($result)){
        array_push(
            $array,
            array(
                'DONumber' => $row['DONumber'],
                'DeliveryDate' => $row['DeliveryDate'],
                'company_name' => $row['company_name'],
                'SO_Status_Name' => $row['SO_Status_Name'],
                'SONumber' => $row['SONumber'],
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
