<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/IHOST-backend/', '', $uri);
$parts = explode('/', trim($path, '/'));

$controller = $parts[0] ?? 'home';
$action = $parts[1] ?? null;

$GLOBALS['route_parts'] = $parts;
$GLOBALS['route_action'] = $action;

$file = __DIR__ . "/controllers/{$controller}.php";

if (file_exists($file)) {
    require $file;
} else {
    http_response_code(404);
    echo json_encode([
        "status" => "error",
        "message" => "Endpoint not found"
    ]);
}