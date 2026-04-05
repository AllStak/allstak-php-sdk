<?php

/**
 * Lightweight mock AllStak backend for integration testing.
 * Run with: php -S localhost:8765 mock-server.php
 *
 * Records all received payloads to /tmp/allstak-mock-log.json
 * and serves appropriate responses matching the real AllStak API contract.
 */

$logFile = sys_get_temp_dir() . '/allstak-mock-log.json';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$rawHeaders = getallheaders();
// Normalize header keys to lowercase for consistent lookup
$headers = [];
foreach ($rawHeaders as $k => $v) {
    $headers[strtolower($k)] = $v;
}
$body = file_get_contents('php://input');
$payload = json_decode($body, true);

// Log every request
$entry = [
    'timestamp' => date('c'),
    'method' => $method,
    'path' => $path,
    'apiKey' => $headers['x-allstak-key'] ?? null,
    'payload' => $payload,
];

$log = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
$log[] = $entry;
file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

header('Content-Type: application/json');

// ─── Auth Check ──────────────────────────────────────────────────
$apiKey = $headers['x-allstak-key'] ?? '';

if ($method === 'POST' && str_starts_with($path, '/ingest/')) {
    if ($apiKey === '') {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'INVALID_API_KEY', 'message' => 'Invalid or missing API key'],
        ]);
        return;
    }

    // Simulate invalid key
    if ($apiKey === 'allstak_live_INVALID') {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'INVALID_API_KEY', 'message' => 'Invalid or missing API key'],
        ]);
        return;
    }
}

// ─── Route Handling ──────────────────────────────────────────────
switch (true) {
    // Health check
    case $path === '/actuator/health':
        echo json_encode(['status' => 'UP']);
        break;

    // Error ingestion
    case $path === '/ingest/v1/errors' && $method === 'POST':
        if (empty($payload['exceptionClass']) || empty($payload['message'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'exceptionClass and message are required'],
            ]);
            break;
        }
        http_response_code(202);
        echo json_encode([
            'success' => true,
            'data' => ['id' => 'err-' . bin2hex(random_bytes(8))],
        ]);
        break;

    // Log ingestion
    case $path === '/ingest/v1/logs' && $method === 'POST':
        if (empty($payload['level']) || empty($payload['message'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'level and message are required'],
            ]);
            break;
        }
        $validLevels = ['debug', 'info', 'warn', 'error', 'fatal'];
        if (!in_array($payload['level'], $validLevels, true)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid log level'],
            ]);
            break;
        }
        http_response_code(202);
        echo json_encode([
            'success' => true,
            'data' => ['id' => 'log-' . bin2hex(random_bytes(8))],
        ]);
        break;

    // HTTP request ingestion
    case $path === '/ingest/v1/http-requests' && $method === 'POST':
        $requests = $payload['requests'] ?? [];
        if (empty($requests) || count($requests) > 100) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'requests must have 1-100 items'],
            ]);
            break;
        }
        http_response_code(202);
        echo json_encode(['ok' => true, 'accepted' => count($requests)]);
        break;

    // Heartbeat
    case $path === '/ingest/v1/heartbeat' && $method === 'POST':
        if (empty($payload['slug']) || empty($payload['status'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'slug and status are required'],
            ]);
            break;
        }
        http_response_code(202);
        echo json_encode(['ok' => true, 'monitorId' => 'mon-' . bin2hex(random_bytes(8))]);
        break;

    // Feature flag evaluation (all flags)
    case $path === '/api/v1/flags/evaluate' && $method === 'GET':
        echo json_encode([
            'success' => true,
            'data' => [
                'flags' => [
                    'dark-mode' => ['enabled' => true, 'value' => 'true'],
                    'new-checkout' => ['enabled' => false, 'value' => 'false'],
                    'max-items' => ['enabled' => true, 'value' => '50'],
                ],
            ],
        ]);
        break;

    // Single flag evaluation
    case preg_match('#^/api/v1/flags/([^/]+)/evaluate$#', $path, $m) && $method === 'GET':
        $key = $m[1];
        echo json_encode([
            'success' => true,
            'data' => [
                'key' => $key,
                'enabled' => true,
                'value' => 'variant-b',
                'ruleApplied' => 'beta-users',
            ],
        ]);
        break;

    // Simulate 500 for testing
    case $path === '/ingest/v1/errors-500':
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => ['code' => 'INTERNAL', 'message' => 'Simulated failure']]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => "Unknown route: {$method} {$path}"]]);
        break;
}
