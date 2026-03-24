<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$user = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'GET') {
    if ($action === 'user') {
        $userId = isset($parts[2]) ? intval($parts[2]) : $user['idU'];
        if ($user['roleU'] !== 'admin' && $userId !== $user['idU']) {
            http_response_code(403); exit;
        }
        
        // Fetch subscriptions and join with service to get name, price etc
        $stmt = $conn->prepare("SELECT sub.*, s.nameService, s.descriptionS, s.price FROM subscription sub JOIN service s ON sub.serviceId = s.idService WHERE sub.userId = ? ORDER BY sub.endDate ASC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subs = [];
        while($row = $result->fetch_assoc()) {
            $subs[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $subs]);
    } else {
        // Admin gets all subs
        requireAdmin();
        $result = $conn->query("SELECT sub.*, s.nameService, u.nameU as clientName FROM subscription sub JOIN service s ON sub.serviceId = s.idService JOIN users u ON sub.userId = u.idU ORDER BY sub.startDate DESC");
        $subs = [];
        while($row = $result->fetch_assoc()) {
            $subs[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $subs]);
    }
}
?>
