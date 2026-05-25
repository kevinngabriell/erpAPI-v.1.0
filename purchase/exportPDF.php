<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once('../connection/connection.php');

$order_id = $_GET['PONumber']; 

$check_query = "SELECT POType FROM purchaseOrder WHERE PONumber = '$order_id';";
$checkResult = mysqli_query($connect, $check_query);

if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
    die('No purchase order found for the given ID.');
}

$checkData = mysqli_fetch_assoc($checkResult);
$POType = $checkData['POType'];

//Import Template
if($POType == '705da1d4-d157-11ee-8'){

    //Loading Template
    $templatePath = 'template.docx';

    //Order Query
    $orderQuery = "SELECT PO.PONumber, PO.PODate, S.supplier_name, S.supplier_address, S.supplier_pic_name, T.term_name, SS.shipment_name, P.payment_name, O.origin_name, PO.POShippingMarks, PO.PORemarks
        FROM purchaseOrder PO
        LEFT JOIN supplier S ON PO.POSupplier = S.supplier_id
        LEFT JOIN term T ON PO.POTerm = T.term_id
        LEFT JOIN shipment SS ON PO.POShipment = SS.shipment_id
        LEFT JOIN payment P ON PO.POPayment = P.payment_id
        LEFT JOIN origin O ON PO.POOrigin = O.origin_id
        WHERE PO.PONumber = '$order_id';";
    
    //Receive Order Result
    $orderResult = mysqli_query($connect, $orderQuery);
    $orderData = mysqli_fetch_assoc($orderResult);
    $PODate = $orderData['PODate'];
    $formattedPODate = date('j F Y', strtotime($PODate));
    $SupplierName = $orderData['supplier_name'];
    $SupplierAddress = $orderData['supplier_address'];
    $PICName = $orderData['supplier_pic_name'];
    $Term = $orderData['term_name'];
    $Shipment = $orderData['shipment_name'];
    $Payment = $orderData['payment_name'];
    $Origin = $orderData['origin_name'];
    $POShippingMarks = $orderData['POShippingMarks'];
    $PORemarks = $orderData['PORemarks'];
    
    // Fetch product items
    $productQuery = "
        SELECT POProductName, POQuantity, POPackagingSize, POUnitPrice
        FROM purchaseOrderItem 
        WHERE PONumber = '$order_id';
    ";
    $productResult = mysqli_query($connect, $productQuery);
    
    // Initialize product, grand total, and tax placeholders
    $productData = []; // Array to hold product details
    $grandTotal = 0; 
    $tax = 0; 
    $rowNumber = 1; 
    
    while ($row = mysqli_fetch_assoc($productResult)) {
        // Calculate the total for the current item
        $itemTotal = $row['POQuantity'] * $row['POUnitPrice'];
        $grandTotal += $itemTotal; // Add to grand total
    
        // Add the item data, including the calculated total
        $productData[] = [
            'No' => $rowNumber++,
            'POProductName' => $row['POProductName'],
            'POQuantity' => number_format($row['POQuantity'],0),
            'POPackagingSize' => $row['POPackagingSize'],
            'POUnitPrice' => number_format($row['POUnitPrice'],2),
            'ItemTotal' => number_format($itemTotal, 2), 
        ];
    }
    
    // Ensure there are exactly 5 placeholders, pad with blanks if needed
    $totalProducts = count($productData);
    for ($i = $totalProducts; $i < 5; $i++) {
        $productData[] = [
            'No' => '',
            'POProductName' => '',
            'POQuantity' => '',
            'POPackagingSize' => '',
            'POUnitPrice' => '',
            'ItemTotal' => '',
        ];
    }

    // Read the original template into memory
    $templateContent = file_get_contents($templatePath);
    if ($templateContent === false) {
        echo "Failed to read the template file.\n";
        exit;
    }

    // Create a new temporary file for manipulation
    $tempFile = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($tempFile, $templateContent);

    // Open the temporary file as a ZIP archive
    $zip = new ZipArchive();

    if ($zip->open($tempFile) === TRUE) {

        // Extract the XML content of the main document
        $xmlFile = 'word/document.xml';
        $xmlContent = $zip->getFromName($xmlFile);

        if ($xmlContent === false) {
            echo "Failed to read the XML content of the document.\n";
            unlink($tempFile); // Clean up the temporary file
            exit;
        }

        // Replace placeholders with actual values
        $xmlContent = str_replace('{name}', 'John Doe', $xmlContent); 
        $xmlContent = str_replace('{customername}', $SupplierName, $xmlContent); 
        $xmlContent = str_replace('{customeraddress}', $SupplierAddress, $xmlContent); 
        $xmlContent = str_replace('{picname}', $PICName, $xmlContent); 
        $xmlContent = str_replace('{ponumber}', $order_id, $xmlContent); 
        $xmlContent = str_replace('{podate}', $formattedPODate, $xmlContent);

        // $xmlContent = str_replace('{items}', $itemRows, $xmlContent);
        
        for ($i = 0; $i < 5; $i++) {
            $noPlaceholder = "{no" . ($i + 1) . "}";
            $productNamePlaceholder = "{productname" . ($i + 1) . "}";
            $quantityPlaceholder = "{quantity" . ($i + 1) . "}";
            $packingPlaceholder = "{packing" . ($i + 1) . "}";
            $unitPricePlaceholder = "{unitprice" . ($i + 1) . "}";
            $totalPlaceholder = "{total" . ($i + 1) . "}";
            
            $xmlContent = str_replace($noPlaceholder, $productData[$i]['No'], $xmlContent);
            $xmlContent = str_replace($productNamePlaceholder, $productData[$i]['POProductName'], $xmlContent);
            $xmlContent = str_replace($quantityPlaceholder, $productData[$i]['POQuantity'], $xmlContent);
            $xmlContent = str_replace($packingPlaceholder, $productData[$i]['POPackagingSize'], $xmlContent);
            $xmlContent = str_replace($unitPricePlaceholder, $productData[$i]['POUnitPrice'], $xmlContent);
            $xmlContent = str_replace($totalPlaceholder, $productData[$i]['ItemTotal'], $xmlContent);
        }
        
        $xmlContent = str_replace('{grandtotal}', number_format($grandTotal, 2), $xmlContent); 
    
        $xmlContent = str_replace('{term}', $Term, $xmlContent); 
        $xmlContent = str_replace('{origin}', $Origin, $xmlContent); 
        $xmlContent = str_replace('{shipment}', $Shipment, $xmlContent); 
        $xmlContent = str_replace('{payment}', $Payment, $xmlContent); 
        $xmlContent = str_replace('{shippingremarks}', $POShippingMarks, $xmlContent); 
        $xmlContent = str_replace('{remarks}', $PORemarks, $xmlContent); 
        $xmlContent = str_replace('{documents}', 'Documents', $xmlContent); 

        // Update the XML content in the ZIP archive
        $zip->addFromString($xmlFile, $xmlContent);
        $zip->close();

        // Set headers for file download
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename=' . 'POImport-' . $order_id . '.docx');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tempFile));

        // Output the modified file to the user
        readfile($tempFile);

        // Clean up the temporary fileo
        unlink($tempFile);
        exit;

    } else {
        echo "Failed to open the template file. Ensure the file path is correct.\n";
        unlink($tempFile); // Clean up the temporary file
        exit;
    }

//Local Template
} else if ($POType == '741ead94-d157-11ee-8'){

    //Loading Template
    $templatePath = 'local_template.docx';

    //Order Query
    $orderQuery = "SELECT S.supplier_name, S.supplier_address, PO.PONumber, PO.PODate, S.supplier_pic_name, PO.POShipmentDate, P.payment_name, SP.PPNPercentage
    FROM purchaseOrder PO
    LEFT JOIN supplier S ON PO.POSupplier = S.supplier_id
    LEFT JOIN payment P ON PO.POPayment = P.payment_id
    LEFT JOIN salesPPNType SP ON PO.POPPN = SP.PPNType_id
    WHERE PO.PONumber = '$order_id'";

    //Receive Order Result
    $orderResult = mysqli_query($connect, $orderQuery);
    $orderData = mysqli_fetch_assoc($orderResult);
    $SupplierName = $orderData['supplier_name'];
    $SupplierAddress = $orderData['supplier_address'];
    $PONumber = $orderData['PONumber'];
    $PODate = $orderData['PODate'];
    $formattedPODate = date('j F Y', strtotime($PODate));
    $PICName = $orderData['supplier_pic_name'];
    $DeliveryDate = $orderData['POShipmentDate'];
    $formattedDeliveryDate = date('j F Y', strtotime($DeliveryDate));
    $Payment = $orderData['payment_name'];
    $PPN = $orderData['PPNPercentage'];
    
    // Fetch product items
    $productQuery = "
        SELECT POProductName, POQuantity, POPackagingSize, POUnitPrice
        FROM purchaseOrderItem 
        WHERE PONumber = '$order_id';
    ";
    $productResult = mysqli_query($connect, $productQuery);
    
    // Initialize product, grand total, and tax placeholders
    $productData = []; // Array to hold product details
    $grandTotal = 0; 
    $tax = 0; 
    $rowNumber = 1; 
    
    while ($row = mysqli_fetch_assoc($productResult)) {
        // Calculate the total for the current item
        $itemTotal = $row['POQuantity'] * $row['POUnitPrice'];
        $grandTotal += $itemTotal; // Add to grand total
    
        // Add the item data, including the calculated total
        $productData[] = [
            'No' => $rowNumber++,
            'POProductName' => $row['POProductName'],
            'POQuantity' => number_format($row['POQuantity'],0),
            'POPackagingSize' => $row['POPackagingSize'],
            'POUnitPrice' => number_format($row['POUnitPrice'],2),
            'ItemTotal' => number_format($itemTotal, 2), 
        ];
    }
    
    // Ensure there are exactly 5 placeholders, pad with blanks if needed
    $totalProducts = count($productData);
    for ($i = $totalProducts; $i < 5; $i++) {
        $productData[] = [
            'No' => '',
            'POProductName' => '',
            'POQuantity' => '',
            'POPackagingSize' => '',
            'POUnitPrice' => '',
            'ItemTotal' => '',
        ];
    }

    // Read the original template into memory
    $templateContent = file_get_contents($templatePath);
    if ($templateContent === false) {
        echo "Failed to read the template file.\n";
        exit;
    }

    // Create a new temporary file for manipulation
    $tempFile = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($tempFile, $templateContent);

    // Open the temporary file as a ZIP archive
    $zip = new ZipArchive();

    if ($zip->open($tempFile) === TRUE) {
        // Extract the XML content of the main document
        $xmlFile = 'word/document.xml';
        $xmlContent = $zip->getFromName($xmlFile);

        if ($xmlContent === false) {
            echo "Failed to read the XML content of the document.\n";
            unlink($tempFile); // Clean up the temporary file
            exit;
        }

        // Replace placeholders with actual values
        $xmlContent = str_replace('{suppliername}', $SupplierName, $xmlContent); 
        $xmlContent = str_replace('{supplieraddress}', $SupplierAddress, $xmlContent); 
        $xmlContent = str_replace('{ponumber}', $PONumber, $xmlContent); 
        $xmlContent = str_replace('{podate}', $formattedPODate, $xmlContent); 
        $xmlContent = str_replace('{picname}', $PICName, $xmlContent); 
        $xmlContent = str_replace('{payment}', $Payment, $xmlContent);
        $xmlContent = str_replace('{delivery}', $formattedDeliveryDate, $xmlContent);
        
        for ($i = 0; $i < 5; $i++) {
            $noPlaceholder = "{no" . ($i + 1) . "}";
            $productNamePlaceholder = "{productname" . ($i + 1) . "}";
            $quantityPlaceholder = "{quantity" . ($i + 1) . "}";
            $packingPlaceholder = "{packing" . ($i + 1) . "}";
            $unitPricePlaceholder = "{unitprice" . ($i + 1) . "}";
            $totalPlaceholder = "{total" . ($i + 1) . "}";
            
            $xmlContent = str_replace($noPlaceholder, $productData[$i]['No'], $xmlContent);
            $xmlContent = str_replace($productNamePlaceholder, $productData[$i]['POProductName'], $xmlContent);
            $xmlContent = str_replace($quantityPlaceholder, $productData[$i]['POQuantity'], $xmlContent);
            $xmlContent = str_replace($packingPlaceholder, $productData[$i]['POPackagingSize'], $xmlContent);
            $xmlContent = str_replace($unitPricePlaceholder, $productData[$i]['POUnitPrice'], $xmlContent);
            $xmlContent = str_replace($totalPlaceholder, $productData[$i]['ItemTotal'], $xmlContent);
        }
        
        $tax = $PPN != 0 ? $grandTotal * ($PPN / 100) : 0;
$grandTotal += $tax; // Add tax to the grand total

$xmlContent = str_replace('{vat}', number_format($tax, 2), $xmlContent);
$xmlContent = str_replace('{total}', number_format($grandTotal, 2), $xmlContent);


        // Update the XML content in the ZIP archive
        $zip->addFromString($xmlFile, $xmlContent);
        $zip->close();
    
        // Set headers for file download
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename=' . 'POLocal-' . $order_id . '.docx');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tempFile));
    
        // Output the modified file to the user
        readfile($tempFile);
    
        // Clean up the temporary fileo
        unlink($tempFile);
        exit;
    } else {
        echo "Failed to open the template file. Ensure the file path is correct.\n";
        unlink($tempFile); // Clean up the temporary file
        exit;
    }

} else {
    die('Purchase Type is not detected. Please call IT Support');
}



?>