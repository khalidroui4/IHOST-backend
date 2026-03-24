<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$user = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'GET') {
    $orderId = intval($action); 
    $stmt = $conn->prepare("SELECT * FROM payement WHERE orderId = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $payments = [];
    while($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    echo json_encode(["status" => "success", "data" => $payments]);
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    if (!isset($data->orderId)) {
        http_response_code(400);
        die(json_encode(["status" => "error", "message" => "orderId is required"]));
    }

    $orderId = intval($data->orderId);
    $amount = isset($data->amount) ? floatval($data->amount) : 0;
    $payMethod = isset($data->method) ? $conn->real_escape_string($data->method) : 'credit_card';

    $conn->begin_transaction();
    try {
        // 1. Log Payment
        $stmt = $conn->prepare("INSERT INTO payement (orderId, method, amount, statusPay, paidAt) VALUES (?, ?, ?, 'success', CURRENT_TIMESTAMP)");
        $stmt->bind_param("isd", $orderId, $payMethod, $amount);
        $stmt->execute();

        // 2. Update Order Status
        $conn->query("UPDATE orders SET statusOrder='paid' WHERE idOrder=$orderId");

        // trigger payment_success sets facture to paid automatically

        // 3. Activate subscriptions for all items in the order
        $uId = $user['idU'];
        $itemsRes = $conn->query("SELECT serviceId FROM order_items WHERE orderId=$orderId");
        if ($itemsRes && $itemsRes->num_rows > 0) {
            $callStmt = $conn->prepare("CALL activate_subscription(?, ?)");
            while($item = $itemsRes->fetch_assoc()) {
                $sId = $item['serviceId'];
                $callStmt->bind_param("ii", $uId, $sId);
                $callStmt->execute();
            }
        }

        $conn->commit();
        echo json_encode(["status" => "success", "message" => "Payment successful. Subscriptions activated."]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Payment failed: " . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}
?>
