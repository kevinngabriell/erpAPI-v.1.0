<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../../connection/connection.php');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// GET /master/term/term.php → list all terms
if ($method === 'GET') {
    $result = mysqli_query($connect, "SELECT * FROM term");
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = ['term_id' => $row['term_id'], 'Term' => $row['term_name']];
    }

    if ($data) {
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Data' => $data]);
    } else {
        http_response_code(400);
        echo json_encode(['StatusCode' => 400, 'Status' => 'Error Bad Request, Result not found !']);
    }

// POST /master/term/term.php → insert new term
} elseif ($method === 'POST') {
    $term_name = $_POST['term_name'];

    $stmt = $connect->prepare("INSERT INTO term (term_id, term_name) VALUES (UUID(), ?)");
    $stmt->bind_param('s', $term_name);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'message' => 'Success: Data inserted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'message' => 'Error: Unable to insert data - ' . $connect->error]);
    }

// DELETE /master/term/term.php → delete a term
} elseif ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $term_id = $body['term_id'];

    $stmt = $connect->prepare("DELETE FROM term WHERE term_id = ?");
    $stmt->bind_param('s', $term_id);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['StatusCode' => 200, 'Status' => 'Success', 'Message' => 'Term has been successfully deleted']);
    } else {
        http_response_code(500);
        echo json_encode(['StatusCode' => 500, 'Status' => 'Error', 'Message' => 'Error: Term cannot be deleted']);
    }

} else {
    http_response_code(405);
    echo json_encode(['StatusCode' => 405, 'Status' => 'Error', 'message' => 'Method not allowed. Allowed: GET, POST, DELETE']);
}
