<?php
// ==============================================================================
// Simulated Backend Server for Load Balancer Testing with Sanctum & HMAC
// Returns server identity, validates incoming headers/tokens, and handles data
// ==============================================================================

header('Content-Type: application/json; charset=utf-8');

$serverName = getenv('SERVER_NAME') ?: 'backend-unknown';
$storageFile = sys_get_temp_dir() . '/cluster_test_db_' . $serverName . '.json';

// Initialize storage
if (!file_exists($storageFile)) {
    file_put_contents($storageFile, json_encode([
        ['id' => 1, 'server' => $serverName, 'title' => 'Initial seed record on ' . $serverName, 'created_at' => date('c')]
    ]));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true);

// Collect all received headers
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$headerName] = $value;
    }
}

// Artificial delay simulation for latency & timeout testing (?delay_ms=... or ?delay_s=...)
$delayMs = isset($_GET['delay_ms']) ? (int) $_GET['delay_ms'] : 0;
$delayS = isset($_GET['delay_s']) ? (int) $_GET['delay_s'] : 0;
if ($delayS > 0) {
    sleep($delayS);
} elseif ($delayMs > 0) {
    usleep($delayMs * 1000);
}

// 1. Health check endpoint (Public)
if ($uri === '/health') {
    http_response_code(200);
    echo json_encode([
        'status' => 'UP',
        'server' => $serverName,
        'timestamp' => time(),
    ]);
    exit;
}

// 2. Simulated Login Endpoint (Issues Sanctum token)
if ($uri === '/api/auth/login' && $method === 'POST') {
    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';

    if ($email === 'admin@enterprise.com' && $password === 'Secret123!') {
        $token = 'sanctum_' . bin2hex(random_bytes(20));
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Authenticated on ' . $serverName,
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => [
                'id' => 1,
                'name' => 'Admin Operator',
                'email' => $email,
            ],
            'issued_by' => $serverName,
        ]);
        exit;
    }

    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid credentials provided to ' . $serverName,
    ]);
    exit;
}

// 3. Sanctum Token Guard for Protected Routes
$authHeader = $headers['authorization'] ?? '';
$hasValidBearer = str_starts_with(strtolower($authHeader), 'bearer ') && strlen(trim(substr($authHeader, 7))) > 5;

// 4. Data manipulation endpoint (add / read items in DB)
if ($uri === '/api/items' || $uri === '/api/auth/workload') {
    if (!$hasValidBearer) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Unauthenticated: Valid Sanctum Bearer token is required.',
            'server' => $serverName,
        ]);
        exit;
    }

    $items = json_decode(file_get_contents($storageFile), true) ?: [];

    if ($method === 'POST') {
        $newItem = [
            'id' => count($items) + 1,
            'server' => $serverName,
            'data' => $body ?? ['raw' => $rawInput],
            'authorized_with_token' => substr($authHeader, 0, 15) . '...',
            'hmac_verified' => isset($headers['x-loadbalancer-signature']),
            'created_at' => date('c'),
        ];
        $items[] = $newItem;
        file_put_contents($storageFile, json_encode($items, JSON_PRETTY_PRINT));

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'action' => 'created',
            'handled_by' => $serverName,
            'item' => $newItem,
            'total_items_in_node_db' => count($items),
        ]);
        exit;
    }

    // GET /api/items
    echo json_encode([
        'status' => 'success',
        'handled_by' => $serverName,
        'count' => count($items),
        'items' => $items,
    ]);
    exit;
}

// 5. Default reflection endpoint (shows proxy transparency, bearer token, HMAC signature)
echo json_encode([
    'message' => "Response from {$serverName}",
    'server' => $serverName,
    'method' => $method,
    'path' => $uri,
    'received_auth' => $headers['authorization'] ?? 'none',
    'received_signature' => $headers['x-loadbalancer-signature'] ?? 'none',
    'received_forwarded_for' => $headers['x-forwarded-for'] ?? 'none',
    'body' => $body ?: $rawInput,
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT);
