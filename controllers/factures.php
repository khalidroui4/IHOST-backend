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
} elseif ($method === 'POST') {
    // Process Payment for Invoice
    if ($action === 'pay') {
        $data = json_decode(file_get_contents("php://input"));
        $idFacture = intval($data->idFacture);
        $userId = $user['idU'];

        // 1. Verify invoice belongs to user
        $stmt = $conn->prepare("
            SELECT f.*, o.idOrder, o.userId as orderUserId 
            FROM facture f 
            JOIN orders o ON f.orderId = o.idOrder 
            WHERE f.idFacture = ? AND o.userId = ? AND f.statusFacture = 'unpaid'
        ");
        $stmt->bind_param("ii", $idFacture, $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            http_response_code(404);
            die(json_encode(["status" => "error", "message" => "Invoice not found or already paid"]));
        }

        $facture = $res->fetch_assoc();
        $orderId = $facture['idOrder'];
        $amount = $facture['amount'];

        $conn->begin_transaction();
        try {
            // 2. Create Payment Record
            $payStmt = $conn->prepare("INSERT INTO payement (orderId, amount, methodPay, statusPay) VALUES (?, ?, 'balance', 'completed')");
            $payStmt->bind_param("id", $orderId, $amount);
            $payStmt->execute();

            // 3. Update Invoice Status
            $upfStmt = $conn->prepare("UPDATE facture SET statusFacture = 'paid' WHERE idFacture = ?");
            $upfStmt->bind_param("i", $idFacture);
            $upfStmt->execute();

            // 4. Update Order Status
            $upoStmt = $conn->prepare("UPDATE orders SET statusOrder = 'paid' WHERE idOrder = ?");
            $upoStmt->bind_param("i", $orderId);
            $upoStmt->execute();

            // 5. Create Subscriptions and Provision Domains from Order Items
            $itemStmt = $conn->prepare("SELECT serviceId, durationMonths, domainName FROM order_items WHERE orderId = ?");
            $itemStmt->bind_param("i", $orderId);
            $itemStmt->execute();
            $items = $itemStmt->get_result();

            $subStmt = $conn->prepare("INSERT INTO subscription (userId, serviceId, startDate, endDate, statusSub) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), 'active')");
            $domStmt = $conn->prepare("INSERT INTO domaine (userId, domainName, expirationDate, statusDomaine) VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL ? MONTH), 'active')");
            
            while ($item = $items->fetch_assoc()) {
                if ($item['domainName']) {
                    $domStmt->bind_param("isi", $userId, $item['domainName'], $item['durationMonths']);
                    $domStmt->execute();
                } else {
                    $subStmt->bind_param("iii", $userId, $item['serviceId'], $item['durationMonths']);
                    $subStmt->execute();
                }
            }

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Payment processed and services activated"]);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Transaction failed: " . $e->getMessage()]);
        }
    }
}
?>
