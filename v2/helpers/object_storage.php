<?php
// Minimal S3-compatible object storage client (AWS Signature V4, path-style) for
// the Cloudeka object storage. No SDK dependency — uses curl only.
// Config comes from .env (never hard-code credentials):
//   S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY

function s3Config(): array {
    $cfg = [
        'endpoint' => rtrim($_ENV['S3_ENDPOINT'] ?? '', '/'),
        'region'   => $_ENV['S3_REGION'] ?? '',
        'bucket'   => $_ENV['S3_BUCKET'] ?? '',
        'key'      => $_ENV['S3_ACCESS_KEY_ID'] ?? '',
        'secret'   => $_ENV['S3_SECRET_ACCESS_KEY'] ?? '',
    ];
    foreach ($cfg as $v) {
        if ($v === '') throw new RuntimeException('Object storage is not configured');
    }
    return $cfg;
}

function s3EncodePath(string $key): string {
    return implode('/', array_map('rawurlencode', explode('/', $key)));
}

// Returns [url, headers[]] for a signed request. $payload is the raw body ('' for none).
function s3SignRequest(string $method, string $key, string $payload = '', array $extraHeaders = []): array {
    $c        = s3Config();
    $host     = parse_url($c['endpoint'], PHP_URL_HOST);
    $port     = parse_url($c['endpoint'], PHP_URL_PORT);
    $hostHdr  = $port ? "$host:$port" : $host;
    $path     = '/' . rawurlencode($c['bucket']) . '/' . s3EncodePath($key);
    $amzDate  = gmdate('Ymd\THis\Z');
    $date     = gmdate('Ymd');
    $payHash  = hash('sha256', $payload);

    $headers = array_change_key_case($extraHeaders, CASE_LOWER);
    $headers['host']                 = $hostHdr;
    $headers['x-amz-content-sha256'] = $payHash;
    $headers['x-amz-date']           = $amzDate;
    ksort($headers);

    $canonHeaders = '';
    foreach ($headers as $k => $v) $canonHeaders .= $k . ':' . trim($v) . "\n";
    $signedHeaders = implode(';', array_keys($headers));
    $canonical = "$method\n$path\n\n$canonHeaders\n$signedHeaders\n$payHash";

    $scope  = "$date/{$c['region']}/s3/aws4_request";
    $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
    $kDate  = hash_hmac('sha256', $date, 'AWS4' . $c['secret'], true);
    $kReg   = hash_hmac('sha256', $c['region'], $kDate, true);
    $kSvc   = hash_hmac('sha256', 's3', $kReg, true);
    $kSign  = hash_hmac('sha256', 'aws4_request', $kSvc, true);
    $sig    = hash_hmac('sha256', $toSign, $kSign);

    $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$c['key']}/$scope, SignedHeaders=$signedHeaders, Signature=$sig";
    $lines = [];
    foreach ($headers as $k => $v) $lines[] = "$k: $v";
    return [$c['endpoint'] . $path, $lines];
}

function s3Request(string $method, string $key, string $payload = '', array $extraHeaders = []): array {
    [$url, $headers] = s3SignRequest($method, $key, $payload, $extraHeaders);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($payload !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException("Object storage request failed: $err");
    return [$code, (string)$body];
}

function s3PutObject(string $key, string $content, string $contentType): void {
    // Cloudeka's gateway intermittently returns 5xx under load; a retry usually succeeds.
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        [$code, $body] = s3Request('PUT', $key, $content, ['content-type' => $contentType]);
        if ($code >= 200 && $code < 300) return;
        error_log("S3 PUT $key failed ($code, attempt $attempt): $body");
        if ($code < 500) break;
        if ($attempt < 3) usleep(500000 * $attempt);
    }
    throw new RuntimeException("Failed to upload file to object storage (HTTP $code)");
}

function s3DeleteObject(string $key): void {
    [$code, $body] = s3Request('DELETE', $key);
    if ($code !== 204 && $code !== 200 && $code !== 404) {
        error_log("S3 DELETE $key failed ($code): $body");
        throw new RuntimeException('Failed to delete file from object storage');
    }
}

// Time-limited GET link (query-string SigV4) so the browser can download directly.
function s3PresignGetUrl(string $key, int $expires = 300): string {
    $c       = s3Config();
    $host    = parse_url($c['endpoint'], PHP_URL_HOST);
    $port    = parse_url($c['endpoint'], PHP_URL_PORT);
    $hostHdr = $port ? "$host:$port" : $host;
    $path    = '/' . rawurlencode($c['bucket']) . '/' . s3EncodePath($key);
    $amzDate = gmdate('Ymd\THis\Z');
    $date    = gmdate('Ymd');
    $scope   = "$date/{$c['region']}/s3/aws4_request";

    $q = [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => "{$c['key']}/$scope",
        'X-Amz-Date'          => $amzDate,
        'X-Amz-Expires'       => (string)$expires,
        'X-Amz-SignedHeaders' => 'host',
    ];
    ksort($q);
    $qs = http_build_query($q, '', '&', PHP_QUERY_RFC3986);

    $canonical = "GET\n$path\n$qs\nhost:$hostHdr\n\nhost\nUNSIGNED-PAYLOAD";
    $toSign    = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $c['secret'], true);
    $kReg  = hash_hmac('sha256', $c['region'], $kDate, true);
    $kSvc  = hash_hmac('sha256', 's3', $kReg, true);
    $kSign = hash_hmac('sha256', 'aws4_request', $kSvc, true);
    $sig   = hash_hmac('sha256', $toSign, $kSign);

    return $c['endpoint'] . $path . '?' . $qs . '&X-Amz-Signature=' . $sig;
}
