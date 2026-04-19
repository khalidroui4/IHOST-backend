<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$user = authenticate();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $userId = $user['idU'];

    // 1. Active Services Count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM subscription WHERE userId = ? AND statusSub = 'active'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $activeServices = $stmt->get_result()->fetch_assoc()['count'];

    // 2. Domains Count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM domaine WHERE userId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $domainsCount = $stmt->get_result()->fetch_assoc()['count'];

    // 3. Unpaid Invoices Count
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM facture f
        JOIN orders o ON f.orderId = o.idOrder
        WHERE o.userId = ? AND f.statusFacture = 'unpaid'
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $unpaidInvoices = $stmt->get_result()->fetch_assoc()['count'];

    // 4. Total Orders Count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM orders WHERE userId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $totalOrders = $stmt->get_result()->fetch_assoc()['count'];

    // 5. Recent Activity (Unified Timeline from Log Table)
    $activityQuery = "SELECT idLog, userId, actionLog, createdAt FROM log WHERE userId = ? ORDER BY createdAt DESC LIMIT 10";
    $stmt = $conn->prepare($activityQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $activityRes = $stmt->get_result();
    $activity = [];
    while($row = $activityRes->fetch_assoc()) {
        $decoded = json_decode($row['actionLog'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $activity[] = [
                'type' => isset($decoded['type']) ? $decoded['type'] : 'system',
                'title' => isset($decoded['title']) ? $decoded['title'] : 'Action',
                'status' => isset($decoded['status']) ? $decoded['status'] : 'info',
                'date' => $row['createdAt']
            ];
        } else {
            $activity[] = [
                'type' => 'system',
                'title' => $row['actionLog'],
                'status' => 'info',
                'date' => $row['createdAt']
            ];
        }
    }

    // 6. Recent Notifications
    $stmt = $conn->prepare("SELECT * FROM notification WHERE userId = ? ORDER BY createdAt DESC LIMIT 5");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $notesRes = $stmt->get_result();
    $notifications = [];
    while($row = $notesRes->fetch_assoc()) {
        $notifications[] = $row;
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "stats" => [
                "activeServices" => $activeServices,
                "domains" => $domainsCount,
                "unpaidInvoices" => $unpaidInvoices,
                "totalOrders" => $totalOrders
            ],
            "recentActivity" => $activity,
            "notifications" => $notifications
        ]
    ]);
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}
?>
