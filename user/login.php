<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('../connection/connection.php');
require_once('../vendor/autoload.php');
require_once('../auth/JWTConfig.php');

use Firebase\JWT\JWT;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $login_query = "SELECT A1.username, A1.first_name, A1.last_name, A1.password, A2.permission_access, A4.company_name, A4.company_id
                    FROM user A1
                    JOIN permission A2 ON A2.permission_id = A1.permission_id
                    JOIN refferal A3 ON A3.refferal_id = A1.unique_id
                    JOIN company A4 ON A4.company_id = A3.company
                    WHERE A1.username = '$username';";
    $result = $connect->query($login_query);
    $row = $result->fetch_assoc();

    if ($row) {
        $firstName        = $row['first_name'];
        $lastName         = $row['last_name'];
        $username         = $row['username'];
        $passwordResult   = $row['password'];
        $permissionAccess = $row['permission_access'];
        $companyName      = $row['company_name'];
        $companyId        = $row['company_id'];

        if (password_verify($password, $passwordResult)) {
            $now = time();
            $payload = [
                'iat'              => $now,
                'exp'              => $now + JWT_EXPIRY_SECONDS,
                'sub'              => $username,
                'firstName'        => $firstName,
                'lastName'         => $lastName,
                'permissionAccess' => $permissionAccess,
                'companyName'      => $companyName,
                'companyId'        => $companyId,
            ];

            $token = JWT::encode($payload, JWT_SECRET, JWT_ALGORITHM);

            http_response_code(200);
            echo json_encode([
                'StatusCode'       => 200,
                'Status'           => 'Success',
                'token'            => $token,
                'expiresIn'        => JWT_EXPIRY_SECONDS,
                'firstName'        => $firstName,
                'lastName'         => $lastName,
                'username'         => $username,
                'permissionAccess' => $permissionAccess,
                'companyNameString'=> $companyName,
                'companyId'        => $companyId,
            ]);
        } else {
            http_response_code(204);
            echo json_encode([
                'StatusCode' => 204,
                'Status'     => 'Error',
                'message'    => 'Error: Password is not match'
            ]);
        }
    } else {
        http_response_code(203);
        echo json_encode([
            'StatusCode' => 203,
            'Status'     => 'Error',
            'message'    => 'Error: Username cannot be found in systems'
        ]);
    }
} else {
    http_response_code(404);
    echo json_encode([
        'StatusCode' => 404,
        'Status'     => 'Error',
        'message'    => 'Error: Invalid method. Only POST requests are allowed.'
    ]);
}
