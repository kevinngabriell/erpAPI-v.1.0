<?php
// Header access is required

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
    $username = $_POST['username'];
    $date = $_POST["date"];

    $date_parts = explode(' ', $date);
    $date_string = $date_parts[1] . ' ' . $date_parts[2] . ' ' . $date_parts[3];
    $date_obj = DateTime::createFromFormat('M d Y', $date_string);
    $formatted_date = $date_obj->format("Y-m-d");

    // Get current datetime in Indonesia/Jakarta timezone
    $currentDateTime = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $currentDateTimeString = $currentDateTime->format("Y-m-d H:i:s");

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

    if (!$lotExists) {
        http_response_code(400);
        echo json_encode(array(
            "StatusCode" => 400,
            "Status" => "Error",
            "message" => "Error: Lot number does not exist in warehouse."
        ));
        exit;
    } else {
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

        // Calculate the quantity in standard unit
        $quantityInStandardUnit = $jumlahBarang * $conversionFactor;

        // Insert transaction into warehouseTransaction table
        $insert_transaction_query = "INSERT INTO warehouseTransaction (id, lot, product, transactionDate, transactionType, quantity, unitOfMeasureID, conversionFactor, insertBy, insertDt, keterangan)
                                    VALUES (UUID(), '$lot', '$kodeProduk', '$formatted_date', '9cafab5b-d975-41e8-8', '$jumlahBarang', '$unitOfMeasureID', '$conversionFactor', '$username', '$currentDateTimeString', '$keteranganBarang')";

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

        // Update warehouse table with new end balance for the lot
        $update_warehouse_query = "UPDATE warehouse 
                                   SET endBalance = endBalance - '$quantityInStandardUnit',
                                       update_by = '$username',
                                       update_dt = '$currentDateTimeString'
                                   WHERE lot = '$lot'";

        $update_warehouse_result = mysqli_query($connect, $update_warehouse_query);

        if (!$update_warehouse_result) {
            http_response_code(500);
            echo json_encode(array(
                "StatusCode" => 500,
                "Status" => "Error",
                "message" => "Error: Failed to update warehouse entry. " . mysqli_error($connect)
            ));
            exit;
        }

        http_response_code(200);
        echo json_encode(array(
            "StatusCode" => 200,
            "Status" => "Success",
            "message" => "Transaction recorded and warehouse entry updated successfully."
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

mysqli_close($connect);
?>
