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
        // we join orders to get user id
        $stmt = $conn->prepare("SELECT f.* FROM facture f JOIN orders o ON f.orderId = o.idOrder WHERE o.userId = ? ORDER BY f.createdAt DESC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $factures = [];
        while($row = $result->fetch_assoc()) {
            $factures[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $factures]);
    }
}
?>
