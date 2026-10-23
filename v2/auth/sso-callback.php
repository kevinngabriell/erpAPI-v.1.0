<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/auth_response.php';
require_once __DIR__ . '/../helpers/auth_log.php';
require_once __DIR__ . '/../helpers/error_handler.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function exchangeSsoCode(string $code): array {
    $movira_api_url = $_ENV['SSO_MOVIRA_API_URL'] ?? '';
    $client_id      = $_ENV['SSO_CLIENT_ID'] ?? '';
    $client_secret  = $_ENV['SSO_CLIENT_SECRET'] ?? '';

    if ($movira_api_url === '' || $client_id === '' || $client_secret === '') {
        errorResponse(500, 'SSO is not configured. Contact your administrator.', [
            'missing_config' => array_filter([
                !$movira_api_url ? 'SSO_MOVIRA_API_URL' : null,
                !$client_id ? 'SSO_CLIENT_ID' : null,
                !$client_secret ? 'SSO_CLIENT_SECRET' : null,
            ]),
        ]);
    }

    $exchange_url = rtrim($movira_api_url, '/') . '/api/v2/sso/exchange';

    $payload = json_encode(['code' => $code]);

    $ch = curl_init($exchange_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Client-Id: ' . $client_id,
            'X-Client-Secret: ' . $client_secret,
            'X-Request-ID: ' . requestId(),
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
    ]);

    $response_body = curl_exec($ch);
    $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error    = curl_error($ch);
    curl_close($ch);

    if ($response_body === false) {
        $exception = new Exception("SSO exchange cURL error: $curl_error");
        setRequestException($exception);
        errorResponse(502, 'Unable to reach the identity provider (Movira). Please try again.', [
            'curl_error' => $curl_error,
        ]);
    }

    $decoded = json_decode($response_body, true);
    if (!is_array($decoded)) {
        $exception = new Exception("SSO exchange returned invalid JSON: " . substr($response_body, 0, 500));
        setRequestException($exception);
        errorResponse(502, 'Identity provider returned an invalid response. Please try again.', [
            'raw_response' => APP_ENV === 'development' ? substr($response_body, 0, 500) : null,
        ]);
    }

    if ($http_code !== 200 || !isset($decoded['success']) || $decoded['success'] !== true) {
        $error_code    = $decoded['error']['code'] ?? 'ERR_SSO_UNKNOWN';
        $error_message = $decoded['error']['message'] ?? 'SSO exchange failed';
        $error_details = $decoded['error']['details'] ?? [];

        $user_friendly = match ($error_code) {
            'ERR_SSO_004' => 'The SSO code is invalid. Please try logging in again.',
            'ERR_SSO_005' => 'The SSO code has expired. Please try logging in again.',
            'ERR_SSO_006' => 'This SSO code has already been used. Please try logging in again.',
            'ERR_SSO_007' => 'This SSO code was not issued for this application.',
            'ERR_SSO_001' => 'You do not have access to this application.',
            'ERR_SSO_003' => 'Your account is not approved or has been suspended.',
            'ERR_SSO_008' => 'Your company subscription is not active or has expired.',
            'ERR_SSO_010' => 'Application credentials are invalid. Contact your administrator.',
            'ERR_SSO_011' => 'Application does not have permission for this action.',
            default       => 'SSO authentication failed: ' . $error_message,
        };

        setRequestOutcome($http_code >= 400 && $http_code < 600 ? $http_code : 502, $error_code, $error_message);

        errorResponse($http_code >= 400 && $http_code < 600 ? $http_code : 502, $user_friendly, [
            'sso_error_code' => $error_code,
            'sso_error_message' => $error_message,
            'details' => $error_details,
        ]);
    }

    return $decoded['data'];
}

function verifySsoUserInApp($conn, array $sso_data): array {
    $app_user_id = $sso_data['app_user']['app_user_id'] ?? '';
    $company_id  = $sso_data['company']['company_id'] ?? '';

    if ($app_user_id === '') {
        errorResponse(401, 'SSO response did not include a valid app user ID.');
    }

    $stmt = $conn->prepare(
        "SELECT u.user_id, u.username, u.email, u.first_name, u.last_name,
                u.account_status, u.app_role_id, u.company_id, u.position_id, u.language,
                c.company_name, c.status AS company_status,
                p.position_name,
                GREATEST(COALESCE(DATEDIFF(s.next_billing_date, NOW()), 0), 0) AS days_remaining
         FROM " . CORE_SCHEMA . ".app_user u
         JOIN " . CORE_SCHEMA . ".app_company c ON c.company_id = u.company_id
         LEFT JOIN " . CORE_SCHEMA . ".app_position p ON p.position_id = u.position_id
         LEFT JOIN " . CORE_SCHEMA . ".app_subscription s
               ON s.app_company_id = u.company_id
              AND s.app_id = u.app_id
              AND s.subscription_status = 'active'
         WHERE u.user_id = ? AND u.app_id = '" . APP_ID . "'
         LIMIT 1"
    );

    if (!$stmt) {
        errorResponse(500, 'Failed to verify SSO user. Reference ID: ' . requestId());
    }

    $stmt->bind_param('s', $app_user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        writeAuthLog($conn, 'sso_login_failed', $app_user_id, $sso_data['identity']['email'] ?? '');
        errorResponse(401, 'Your account does not exist in this application. Contact your administrator.', [
            'app_user_id' => $app_user_id,
        ]);
    }

    $account_status = $row['account_status'];
    if ($account_status !== 'verified') {
        $status_messages = [
            'pending'          => 'Account is not verified yet',
            'waiting_payment'  => 'Account is pending payment verification',
            'suspended'        => 'Account has been suspended. Contact your administrator.',
            'disabled'         => 'Account has been disabled.',
        ];
        $message = $status_messages[$account_status] ?? 'Account is not active.';
        writeAuthLog($conn, 'sso_login_failed', $row['user_id'], $row['email']);
        errorResponse(403, $message);
    }

    if ($row['company_status'] === 'inactive') {
        writeAuthLog($conn, 'sso_login_failed', $row['user_id'], $row['email']);
        errorResponse(403, 'Your company account is not active.');
    }

    return $row;
}

function ssoLogin($conn, $input): void {
    if (!isset($input['code']) || trim((string)$input['code']) === '') {
        authResponse(400, 'The code field is required.');
        return;
    }

    if (isset($input['error']) && $input['error'] !== '') {
        $error_code    = $input['error_code'] ?? $input['error'];
        $error_message = $input['error_description'] ?? $input['error'] ?? 'SSO authentication was denied';
        writeAuthLog($conn, 'sso_login_error', null, '');
        authResponse(401, $error_message, 'SSO_ERR');
        return;
    }

    $code = trim((string)$input['code']);

    $sso_data = exchangeSsoCode($code);

    $row = verifySsoUserInApp($conn, $sso_data);

    $days_remaining = (int)$row['days_remaining'];
    $claims = [
        'user_id'        => $row['user_id'],
        'username'       => $row['username'],
        'email'          => $row['email'],
        'first_name'     => $row['first_name'],
        'last_name'      => $row['last_name'],
        'app_id'         => APP_ID,
        'app_role_id'    => $row['app_role_id'],
        'company_id'     => $row['company_id'],
        'position_id'    => $row['position_id'],
        'days_remaining' => $days_remaining,
        'language'       => $row['language'] ?? 'id',
        'sso'            => true,
    ];

    $token = JWT::encode($claims, JWT::ACCESS_TTL);

    $now  = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE " . CORE_SCHEMA . ".app_user SET updated_at = ? WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param('ss', $now, $row['user_id']);
        $stmt->execute();
        $stmt->close();
    }

    writeAuthLog($conn, 'sso_login_success', $row['user_id'], $row['email']);

    $permissions = $sso_data['app_user']['permissions'] ?? [];

    authResponse(200, 'SSO login successful', null, [
        'token'   => $token,
        'user'    => [
            'user_id'       => $row['user_id'],
            'first_name'    => $row['first_name'],
            'last_name'     => $row['last_name'],
            'email'         => $row['email'],
            'position_id'   => $row['position_id'],
            'position_name' => $row['position_name'],
            'language'      => $row['language'] ?? 'id',
        ],
        'company' => [
            'company_id'     => $row['company_id'],
            'company_name'   => $row['company_name'],
            'days_remaining' => $days_remaining,
        ],
        'permissions' => $permissions,
        'sso'         => true,
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            ssoLogin($conn, $input);
            break;
        case 'GET':
            $params = $_GET;
            if (empty($params)) {
                authResponse(400, 'No SSO parameters provided.');
            }
            ssoLogin($conn, $params);
            break;
        default:
            methodNotAllowed(['POST', 'GET']);
    }

} catch (Exception $e) {
    authResponse(500, 'An unexpected error occurred during SSO login. Reference ID: ' . requestId(), null,
        safeErrorResponse($e)
    );
}
