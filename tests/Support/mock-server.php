<?php

/**
 * Lightweight mock AllStak ingest backend for the PHP SDK test suite.
 *
 * Boot with the PHP built-in server, passing a log-file path via the
 * ALLSTAK_MOCK_LOG environment variable:
 *
 *     ALLSTAK_MOCK_LOG=/tmp/log.json php -S 127.0.0.1:0 tests/Support/mock-server.php
 *
 * Every received ingest request is appended (as JSON) to the log file so a
 * test can read it back and assert what the SDK actually sent on the wire.
 * Mirrors the response contract of the real /ingest/v1/** endpoints.
 */

$logFile = getenv('ALLSTAK_MOCK_LOG') ?: (sys_get_temp_dir() . '/allstak-mock-log.json');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$rawHeaders = function_exists('getallheaders') ? getallheaders() : [];
$headers = [];
foreach ($rawHeaders as $k => $v) {
    $headers[strtolower($k)] = $v;
}

$body = file_get_contents('php://input');
$payload = json_decode($body, true);

$entry = [
    'timestamp' => date('c'),
    'method' => $method,
    'path' => $path,
    'apiKey' => $headers['x-allstak-key'] ?? null,
    'payload' => $payload,
];

// Append atomically-ish: read, push, write. The SDK sends errors synchronously
// one at a time so contention is not a concern for the test suite.
$log = file_exists($logFile) ? (json_decode((string) file_get_contents($logFile), true) ?: []) : [];
$log[] = $entry;
file_put_contents($logFile, json_encode($log, JSON_UNESCAPED_SLASHES));

header('Content-Type: application/json');

$apiKey = $headers['x-allstak-key'] ?? '';
if ($method === 'POST' && str_starts_with((string) $path, '/ingest/') && $apiKey === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_API_KEY']]);
    return;
}

switch (true) {
    case $path === '/ingest/v1/errors' && $method === 'POST':
        http_response_code(202);
        echo json_encode(['success' => true, 'data' => ['id' => 'err-' . bin2hex(random_bytes(8))]]);
        break;

    case $path === '/ingest/v1/logs' && $method === 'POST':
        http_response_code(202);
        echo json_encode(['success' => true, 'data' => ['id' => 'log-' . bin2hex(random_bytes(8))]]);
        break;

    case $path === '/ingest/v1/http-requests' && $method === 'POST':
        http_response_code(202);
        echo json_encode(['ok' => true]);
        break;

    case $path === '/ingest/v1/spans' && $method === 'POST':
        http_response_code(202);
        echo json_encode(['ok' => true]);
        break;

    case $path === '/ingest/v1/heartbeat' && $method === 'POST':
        http_response_code(202);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND']]);
        break;
}
