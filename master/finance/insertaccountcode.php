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
require_once('../../connection/connection.php');

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    
    $input = json_decode(file_get_contents("php://input"), true);
    
    $account_name = $input['account_name'] ?? null;
    $code = $input['code'] ?? null;
    $account_name_alias = $input['account_name_alias'] ?? null;

    //Search is origin name is already exist 
    $search_query = "SELECT account_name FROM account_code WHERE code = '$code' OR account_name_alias = '$account_name_alias'";
    $result = $connect->query($search_query);
    $row = $result->fetch_assoc();

    //If origin name is exist 
    if($row){
        http_response_code(203);
        echo json_encode(
            array(
                "StatusCode" => 203,
                'Status' => 'Error',
                "message" => "Error: Account Code has been already exist in database"
            )
        );
    //If origin name is not exist
    } else {
        $origin_query = "INSERT INTO account_code (code, account_name, account_name_alias) 
                        VALUES ('$code', '$account_name', '$account_name_alias')";
        
        if(mysqli_query($connect, $origin_query)){
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