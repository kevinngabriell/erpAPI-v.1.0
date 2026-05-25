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
    $purchase_order_number = $_POST['purchase_order_number'];
    //Product 1
    $purchase_order_product_name_1 = $_POST['purchase_order_product_name_1'];
    $purchase_order_product_quantity_1 = $_POST['purchase_order_product_quantity_1'];
    $purchase_order_product_packaging_size_1 = $_POST['purchase_order_product_packaging_size_1'];
    $purchase_order_product_unit_price_1 = $_POST['purchase_order_product_unit_price_1'];
    //Product 2
    $purchase_order_product_name_2 = $_POST['purchase_order_product_name_2'];
    $purchase_order_product_quantity_2 = $_POST['purchase_order_product_quantity_2'];
    $purchase_order_product_packaging_size_2 = $_POST['purchase_order_product_packaging_size_2'];
    $purchase_order_product_unit_price_2 = $_POST['purchase_order_product_unit_price_2'];
    //Product 3
    $purchase_order_product_name_3 = $_POST['purchase_order_product_name_3'];
    $purchase_order_product_quantity_3 = $_POST['purchase_order_product_quantity_3'];
    $purchase_order_product_packaging_size_3 = $_POST['purchase_order_product_packaging_size_3'];
    $purchase_order_product_unit_price_3 = $_POST['purchase_order_product_unit_price_3'];
    //Product 4
    $purchase_order_product_name_4 = $_POST['purchase_order_product_name_4'];
    $purchase_order_product_quantity_4 = $_POST['purchase_order_product_quantity_4'];
    $purchase_order_product_packaging_size_4 = $_POST['purchase_order_product_packaging_size_4'];
    $purchase_order_product_unit_price_4 = $_POST['purchase_order_product_unit_price_4'];
    //Product 5
    $purchase_order_product_name_5 = $_POST['purchase_order_product_name_5'];
    $purchase_order_product_quantity_5 = $_POST['purchase_order_product_quantity_5'];
    $purchase_order_product_packaging_size_5 = $_POST['purchase_order_product_packaging_size_5'];
    $purchase_order_product_unit_price_5 = $_POST['purchase_order_product_unit_price_5'];

    if($purchase_order_product_name_1 != NULL || $purchase_order_product_name_1 != ''){
        $insert_item_one_query = "INSERT INTO purchaseOrderItem (PONumber, POProductName, POQuantity, POPackagingSize, POUnitPrice, POVAT, POTotal) VALUES ('$purchase_order_number','$purchase_order_product_name_1', '$purchase_order_product_quantity_1', '$purchase_order_product_packaging_size_1', '$purchase_order_product_unit_price_1', NULL, NULL);";
        mysqli_query($connect, $insert_item_one_query);
    }

    if($purchase_order_product_name_2 != NULL || $purchase_order_product_name_2 != ''){
        $insert_item_two_query = "INSERT INTO purchaseOrderItem (PONumber, POProductName, POQuantity, POPackagingSize, POUnitPrice, POVAT, POTotal) VALUES ('$purchase_order_number','$purchase_order_product_name_2', '$purchase_order_product_quantity_2', '$purchase_order_product_packaging_size_2', '$purchase_order_product_unit_price_2', NULL, NULL);";
        mysqli_query($connect, $insert_item_two_query);
    }

    if($purchase_order_product_name_3 != NULL || $purchase_order_product_name_3 != ''){
        $insert_item_three_query = "INSERT INTO purchaseOrderItem (PONumber, POProductName, POQuantity, POPackagingSize, POUnitPrice, POVAT, POTotal) VALUES ('$purchase_order_number','$purchase_order_product_name_3', '$purchase_order_product_quantity_3', '$purchase_order_product_packaging_size_3', '$purchase_order_product_unit_price_3', NULL, NULL);";
        mysqli_query($connect, $insert_item_three_query);
    }

    if($purchase_order_product_name_4 != NULL || $purchase_order_product_name_4 != ''){
        $insert_item_four_query = "INSERT INTO purchaseOrderItem (PONumber, POProductName, POQuantity, POPackagingSize, POUnitPrice, POVAT, POTotal) VALUES ('$purchase_order_number','$purchase_order_product_name_4', '$purchase_order_product_quantity_4', '$purchase_order_product_packaging_size_4', '$purchase_order_product_unit_price_4', NULL, NULL);";
        mysqli_query($connect, $insert_item_four_query);
    }

    if($purchase_order_product_name_5 != NULL || $purchase_order_product_name_5 != ''){
        $insert_item_five_query = "INSERT INTO purchaseOrderItem (PONumber, POProductName, POQuantity, POPackagingSize, POUnitPrice, POVAT, POTotal) VALUES ('$purchase_order_number','$purchase_order_product_name_5', '$purchase_order_product_quantity_5', '$purchase_order_product_packaging_size_5', '$purchase_order_product_unit_price_5', NULL, NULL);";
        mysqli_query($connect, $insert_item_five_query);
    }

    http_response_code(200);
    echo json_encode(
        array(
            "StatusCode" => 200,
            'Status' => 'Success',
            "message" => "Success: Data inserted successfully"
        )
    );

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