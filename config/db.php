<?php
// config/db.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ihost";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["status" => "error", "message" => "Connection failed: " . $conn->connect_error]));
}
$conn->set_charset("utf8");

function logActivity($conn, $userId, $type, $title, $status) {
    if (!$userId) return;
    $actionLog = json_encode([
        "type" => $type,
        "title" => $title,
        "status" => $status
    ], JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("INSERT INTO log (userId, actionLog) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("is", $userId, $actionLog);
        $stmt->execute();
    }
}
?>
