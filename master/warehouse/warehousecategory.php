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

//Function to generate a UUID
function generate_uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

//Checking call API method
if($_SERVER['REQUEST_METHOD'] === 'GET'){

    $query = "SELECT id, category_name FROM warehouseCategory;";
    $result = mysqli_query($connect, $query);

    $array = array();
    while($row = mysqli_fetch_array($result)){
        array_push(
            $array,
            array(
                'id' => $row['id'],
                'category_name' => $row['category_name']
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

} elseif($_SERVER['REQUEST_METHOD'] === 'POST'){

    // Check if category_name is sent via POST parameters
    if(isset($_POST['category_name'])) {
        $category_name = mysqli_real_escape_string($connect, $_POST['category_name']);
        $id = generate_uuid();

        $query = "INSERT INTO warehouseCategory (id, category_name) VALUES ('$id', '$category_name');";

        if(mysqli_query($connect, $query)){
            echo json_encode(
                array(
                    'StatusCode' => 201,
                    'Status' => 'Success',
                    'Message' => 'Category inserted successfully',
                    'Data' => array(
                        'id' => $id,
                        'category_name' => $category_name
                    )
                )
            );
        } else {
            http_response_code(500);
            echo json_encode(
                array(
                    'StatusCode' => 500,
                    'Status' => 'Error',
                    'Message' => 'Internal Server Error. Could not insert the category.'
                )
            );
        }
    } else {
        http_response_code(400);
        echo json_encode(
            array(
                'StatusCode' => 400,
                'Status' => 'Error',
                'Message' => 'Bad Request. Category name is required.'
            )
        );
    }

} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            'Status' => 'Error',
            "Message" => "Error: Invalid method. Only GET and POST requests are allowed."
        )
    );
}
?>
