<?php
// Mock server router. Invoked by `php -S` when MockServer starts a child process.
// Reads route configuration from a JSON file (RF_MOCK_CONFIG) and appends every
// incoming request to a recordings file (RF_MOCK_RECORDINGS) so tests can assert.

$configPath = getenv('RF_MOCK_CONFIG');
$recordingsPath = getenv('RF_MOCK_RECORDINGS');

if (!$configPath || !$recordingsPath) {
    http_response_code(500);
    echo 'router: RF_MOCK_CONFIG and RF_MOCK_RECORDINGS env vars are required';
    return;
}

$config = [];
if (is_file($configPath)) {
    $raw = file_get_contents($configPath);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];
// Decode the path so route patterns and recordings use human-readable strings
// regardless of whether the client URL-encoded spaces, etc.
$rawPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rawurldecode($rawPath);
$query = $_SERVER['QUERY_STRING'] ?? '';

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $name = strtolower(str_replace('_', '-', substr($k, 5)));
        $headers[$name] = $v;
    }
}

$requestBody = file_get_contents('php://input');

// Record the incoming request before responding.
$recordings = [];
if (is_file($recordingsPath)) {
    $raw = file_get_contents($recordingsPath);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $recordings = $decoded;
        }
    }
}
$recordings[] = [
    'method' => $method,
    'path' => $path,
    'query' => $query,
    'headers' => $headers,
    'body' => $requestBody,
    'timestamp' => microtime(true),
];
file_put_contents($recordingsPath, json_encode($recordings));

// Look up route. Exact path match first, then '*' suffix patterns.
$route = null;
if (isset($config[$path])) {
    $route = $config[$path];
} else {
    foreach ($config as $pattern => $cfg) {
        if (substr($pattern, -1) === '*') {
            $prefix = substr($pattern, 0, -1);
            if (strpos($path, $prefix) === 0) {
                $route = $cfg;
                break;
            }
        }
    }
}

if ($route === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'no route configured', 'path' => $path]);
    return;
}

$delayMs = $route['delayMs'] ?? 0;
if ($delayMs > 0) {
    usleep((int) ($delayMs * 1000));
}

http_response_code($route['status'] ?? 200);
header('Content-Type: ' . ($route['contentType'] ?? 'application/json'));

$body = $route['body'] ?? '';
if (is_array($body) || is_object($body)) {
    echo json_encode($body);
} else {
    echo $body;
}
