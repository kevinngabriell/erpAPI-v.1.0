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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lot = $_POST['lot'];
    $kodeProduk = $_POST['kodeProduk'];
    $jumlahBarang = $_POST['jumlahBarang'];
    $unitOfMeasureID = $_POST['unitOfMeasureID'];
    $keteranganBarang = $_POST['keteranganBarang'];
    $expDate = $_POST['expDate'];
    $username = $_POST['username'];

    $date_parts_sodate = explode(' ', $expDate);
    $date_string_sodate = $date_parts_sodate[1] . ' ' . $date_parts_sodate[2] . ' ' . $date_parts_sodate[3];
    $sales_order_date_obj = DateTime::createFromFormat('M d Y', $date_string_sodate);
    $formatted_exp_date = $sales_order_date_obj->format("Y-m-d");
    
    $date = $_POST["date"];

    $date_parts = explode(' ', $date);
    $date_string = $date_parts[1] . ' ' . $date_parts[2] . ' ' . $date_parts[3];
    $date_obj = DateTime::createFromFormat('M d Y', $date_string);
    $formatted_date = $date_obj->format("Y-m-d");

    // Get current datetime in Indonesia/Jakarta timezone
    $currentDateTime = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

    // Retrieve the conversion factor from unitOfMeasure table
    $uom_query = "SELECT conversionFactor FROM unitOfMeasure WHERE uomID = '$unitOfMeasureID'";
    $uom_result = mysqli_query($connect, $uom_query);
    if (!$uom_result) {
        http_response_code(500);
        echo json_encode(array(
            "StatusCode" => 500,
            "Status" => "Error",
            "message" => "Error: Failed to retrieve conversion factor. " . mysqli_error($connect)
        ));
        exit;
    }
    $uom_row = mysqli_fetch_assoc($uom_result);
    $conversionFactor = $uom_row['conversionFactor'];

    // Calculate end balance in standard unit
    $end_balance = $jumlahBarang * $conversionFactor;

    // Check if lot number already exists in warehouse
    $check_lot_query = "SELECT COUNT(*) AS count FROM warehouse WHERE lot = '$lot'";
    $check_lot_result = mysqli_query($connect, $check_lot_query);

    if (!$check_lot_result) {
        http_response_code(500);
        echo json_encode(array(
            "StatusCode" => 500,
            "Status" => "Error",
            "message" => "Error: Failed to check lot number. " . mysqli_error($connect)
        ));
        exit;
    }

    $row = mysqli_fetch_assoc($check_lot_result);
    $lotExists = $row['count'] > 0;

    // Insert or update warehouse table based on lot existence
    if ($lotExists) {
        // Lot number already exists, return 400 Bad Request
        http_response_code(400);
        echo json_encode(array(
            "StatusCode" => 400,
            "Status" => "Error",
            "message" => "Error: Lot number already exists in warehouse."
        ));
    } else {
        // Lot number does not exist, perform insert
        $beginning_balance = 0; // Assume beginning balance starts from 0 for new entries

        // Insert warehouse table with new lot entry
        $insert_warehouse_query = "INSERT INTO warehouse (lot, product, date, beginningBalance, endBalance, insert_by, insert_dt, update_by, update_dt)
                                   VALUES ('$lot', '$kodeProduk', '$formatted_date', '$beginning_balance', '$end_balance', '$username', '$currentDateTimeString', '$username', '$currentDateTimeString')";

        $insert_warehouse_result = mysqli_query($connect, $insert_warehouse_query);

        if (!$insert_warehouse_result) {
            http_response_code(500);
            echo json_encode(array(
                "StatusCode" => 500,
                "Status" => "Error",
                "message" => "Error: Failed to insert warehouse entry. " . mysqli_error($connect)
            ));
            exit;
        }

        // Insert transaction into warehouse_transaction table
        $insert_transaction_query = "INSERT INTO warehouseTransaction (id, lot, product, transactionDate, transactionType, quantity, unitOfMeasureID, conversionFactor, insertBy, insertDt, expiredDt)
                                    VALUES (UUID(), '$lot', '$kodeProduk', '$formatted_date', '452c5015-e80f-4e8a-8', '$jumlahBarang', '$unitOfMeasureID', '$conversionFactor', '$username', '$currentDateTimeString', '$formatted_exp_date')";

        $insert_result = mysqli_query($connect, $insert_transaction_query);

        if (!$insert_result) {
            http_response_code(500);
            echo json_encode(array(
                "StatusCode" => 500,
                "Status" => "Error",
                "message" => "Error: Failed to insert transaction into database. " . mysqli_error($connect)
            ));
            exit;
        }

        // If successful
        http_response_code(200);
        echo json_encode(array(
            "StatusCode" => 200,
            "Status" => "Success",
            "message" => "Transaction recorded and warehouse entry created successfully."
        ));
    }

} else {
    http_response_code(404);
    echo json_encode(
        array(
            "StatusCode" => 404,
            "Status" => "Error",
            "message" => "Error: Invalid method. Only POST requests are allowed."
        )
    );
}

// Close database connection
mysqli_close($connect);
?>
