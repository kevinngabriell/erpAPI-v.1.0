<?php
//Header access is required

// Display error message
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Connection access
require_once('../connection/connection.php');

// Handling preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Checking call API method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $employee_id = $_POST['employee_id'];
        
        if ($employee_id) {
            $query = "DELETE FROM employee WHERE employee_id = '$employee_id'";
            if (mysqli_query($connect, $query)) {
                http_response_code(200);
                echo json_encode(
                    array(
                        "StatusCode" => 200,
                        'Status' => 'Success',
                        "message" => "Success: Data deleted successfully"
                    )
                );
            } else {
                http_response_code(500);
                echo json_encode(
                    array(
                        "StatusCode" => 500,
                        'Status' => 'Error',
                        "message" => "Error: Unable to delete data - " . mysqli_error($connect)
                    )
                );
            }
        } else {
            http_response_code(400);
            echo json_encode(
                array(
                    "StatusCode" => 400,
                    'Status' => 'Error',
                    "message" => "Error: employee_id is required"
                )
            );
        }
    } else {
        // Your existing POST logic for creating an employee
        $employee_name = $_POST["employee_name"];
        $employee_position = $_POST["employee_position"];
        $employee_gender = $_POST["employee_gender"];
        $employee_pob = $_POST["employee_pob"];
        $employee_dob = $_POST["employee_dob"];
        $employee_start_date = $_POST["employee_start_date"];

        $query = "INSERT INTO employee (employee_id, employee_name, employee_pob, employee_dob, hire_date, gender, position) 
        VALUES (UUID(), '$employee_name', '$employee_pob', '$employee_dob', '$employee_start_date', '$employee_gender', '$employee_position')";

        if (mysqli_query($connect, $query)) {
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
                    "message" => "Error: Unable to insert data - " . mysqli_error($connect)
                )
            );
        }
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $employee_id = isset($_GET['employee_id']) ? $_GET['employee_id'] : null;

    if ($employee_id) {
        $query = "SELECT * FROM employee A1 LEFT JOIN gender A2 ON A1.gender = A2.id WHERE employee_id = '$employee_id'";
    } else {
        $query = "SELECT * FROM employee A1 LEFT JOIN gender A2 ON A1.gender = A2.id";
    }

    $result = mysqli_query($connect, $query);

    if ($result) {
        $employees = array();
        while ($row = mysqli_fetch_assoc($result)) {
            $employees[] = $row;
        }
        http_response_code(200);
        echo json_encode(
            array(
                "StatusCode" => 200,
                'Status' => 'Success',
                "Data" => $employees
            )
        );
    } else {
        http_response_code(500);
        echo json_encode(
            array(
                "StatusCode" => 500,
                'Status' => 'Error',
                "message" => "Error: Unable to fetch data - " . mysqli_error($connect)
            )
        );
    }
} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            'Status' => 'Error',
            "message" => "Error: Invalid method. Only POST and GET requests are allowed."
        )
    );
}
