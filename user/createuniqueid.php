<?php
//Header access is required
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

//Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

//Connection access
require_once('../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $company_id = $_POST['company_id'];
    $limit_user = $_POST['limit_user'];

    //Check the company name based on the company_id
    $search_company_name = "SELECT company_name FROM company WHERE company_id = '$company_id'";
    $result = $connect->query($search_company_name);
    $row = $result->fetch_assoc();
    $companyNameString = json_encode($row['company_name']);

    //Check if the company name is exist or not
    if($row){
        //Random characters generator method
        $inputString = str_replace(' ', '', $companyNameString);
        $inputLength = strlen($inputString);
        $characterPool = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $poolLength = strlen($characterPool);
        $randomString = '';

        for ($i = 0; $i < 6; $i++) {
            $randomIndex = mt_rand(0, $poolLength - 1);
            $randomString .= $characterPool[$randomIndex];
        }

        //Insert process
        $insert_refferal_query = "INSERT IGNORE INTO refferal (refferal_id, company, limit_user) VALUES ('$randomString', '$company_id', '$limit_user');";
        
        if(mysqli_query($connect, $insert_refferal_query)){
            http_response_code(200);
            echo json_encode(
                array(
                    "StatusCode" => 200,
                    'Status' => 'Success',
                    "message" => "Success: Data inserted successfully"
                )
            );
        } else {
            http_response_code(500);
            echo json_encode(
                array(
                    "StatusCode" => 500,
                    'Status' => 'Error',
                    "message" => "Error: Unable to update data - " . mysqli_error($connect)
                )
            );
        }
    } else {
        http_response_code(404);
        echo json_encode(
            array(
                "StatusCode" => 404,
                'Status' => 'Not Found',
                "message" => "Error: The company name cannot be found please check your id"
            )
        );
    }

} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            'Status' => 'Error',
            "message" => "Error: Invalid method. Only POST requests are allowed."
        )
    );
}

?>