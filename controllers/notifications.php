<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$user = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';
$sub    = isset($parts[2]) ? $parts[2] : '';

// ── GET /{userId}  →  fetch notifications for a user ──────────────────────
if ($method === 'GET') {
    $userId = intval($action);
    if ($user['roleU'] !== 'admin' && $userId !== $user['idU']) {
        http_response_code(403); exit;
    }
    $stmt = $conn->prepare("SELECT * FROM notification WHERE userId = ? ORDER BY createdAt DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $notes = [];
    while($row = $result->fetch_assoc()) {
        $notes[] = $row;
    }
    echo json_encode(["status" => "success", "data" => $notes]);

// ── PUT /{userId}/read-all  →  mark all notifications as read ─────────────
} elseif ($method === 'PUT' && $sub === 'read-all') {
    $userId = intval($action);
    if ($user['roleU'] !== 'admin' && $userId !== $user['idU']) {
        http_response_code(403); exit;
    }
    $stmt = $conn->prepare("UPDATE notification SET isRead = 1 WHERE userId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    echo json_encode(["status" => "success", "message" => "All notifications marked as read"]);
}
?>
