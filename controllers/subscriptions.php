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
} elseif ($method === 'POST' || $method === 'PUT') {
    // Renew subscription
    if ($action === 'renew') {
        $data = json_decode(file_get_contents("php://input"));
        $idSub = intval($data->idSub);
        $userId = $user['idU'];

        // 1. Verify subscription belongs to user
        $stmt = $conn->prepare("SELECT sub.*, s.durationMonths FROM subscription sub JOIN service s ON sub.serviceId = s.idService WHERE sub.idSub = ? AND sub.userId = ?");
        $stmt->bind_param("ii", $idSub, $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            http_response_code(404);
            die(json_encode(["status" => "error", "message" => "Subscription not found"]));
        }

        $sub = $res->fetch_assoc();
        $duration = $sub['durationMonths'];

        // 2. Extend the date
        // If current endDate is in the past, start from today. Otherwise, extend from current endDate.
        $stmt = $conn->prepare("
            UPDATE subscription 
            SET endDate = DATE_ADD(GREATEST(endDate, CURDATE()), INTERVAL ? MONTH),
                statusSub = 'active'
            WHERE idSub = ?
        ");
        $stmt->bind_param("ii", $duration, $idSub);
        
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Subscription renewed successfully"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Renewal failed"]);
        }
    }
}
?>
