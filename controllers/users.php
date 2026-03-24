<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../middleware/authMiddleware.php';

$userAuth = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'GET') {
    // Only admins can get all users, or user can get themselves
    if ($action === '') {
        requireAdmin();
        $result = $conn->query("SELECT idU, nameU, email, roleU, emailVerified, createdAt FROM users");
        $users = [];
        while($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $users]);
    } else {
        $id = intval($action);
        if ($userAuth['roleU'] !== 'admin' && $userAuth['idU'] !== $id) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Forbidden"]);
            exit;
        }
        $stmt = $conn->prepare("SELECT idU, nameU, email, roleU, emailVerified, createdAt FROM users WHERE idU = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            echo json_encode(["status" => "success", "data" => $result->fetch_assoc()]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "User not found"]);
        }
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}
?>
