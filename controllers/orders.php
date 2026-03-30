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
            http_response_code(403);
            exit;
        }
        $stmt = $conn->prepare("SELECT * FROM orders WHERE userId = ? ORDER BY createdAt DESC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = [];
        while($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $orders]);
    } else {
        requireAdmin();
        $result = $conn->query("
            SELECT o.*, u.nameU, u.email 
            FROM orders o 
            JOIN users u ON o.userId = u.idU 
            ORDER BY o.createdAt DESC
        ");
        $orders = [];
        while($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $orders]);
    }
} elseif ($method === 'POST') {
    // Create new order from user's current cart
    $userId = $user['idU'];

    // 1. Fetch cart items
    $cartStmt = $conn->prepare("
        SELECT c.idCart, c.serviceId, c.durationMonths, c.domainName, s.price 
        FROM cart c 
        JOIN service s ON c.serviceId = s.idService 
        WHERE c.userId = ?
    ");
    $cartStmt->bind_param("i", $userId);
    $cartStmt->execute();
    $cartRes = $cartStmt->get_result();
    
    if ($cartRes->num_rows === 0) {
        http_response_code(400);
        die(json_encode(["status" => "error", "message" => "Cart is empty"]));
    }

    $cartItems = [];
    $totalAmount = 0;
    while($row = $cartRes->fetch_assoc()) {
        $cartItems[] = $row;
        // If it's a domain, price is annual. durationMonths is total months.
        if ($row['domainName']) {
            $totalAmount += (float)$row['price'] * ((int)$row['durationMonths'] / 12);
        } else {
            $totalAmount += (float)$row['price'] * (int)$row['durationMonths'];
        }
    }

    // TVA removal: Total is now exactly the sum of item prices
    $totalFinal = $totalAmount;

    $conn->begin_transaction();
    try {
        // 2. Create the main Order
        $stmt = $conn->prepare("INSERT INTO orders (userId, totalAmount, statusOrder) VALUES (?, ?, 'pending')");
        $stmt->bind_param("id", $userId, $totalFinal);
        $stmt->execute();
        $orderId = $conn->insert_id;

        // 3. Create Order Items
        $itemStmt = $conn->prepare("INSERT INTO order_items (orderId, serviceId, durationMonths, price, domainName) VALUES (?, ?, ?, ?, ?)");
        foreach($cartItems as $item) {
            $itemStmt->bind_param("iiids", $orderId, $item['serviceId'], $item['durationMonths'], $item['price'], $item['domainName']);
            $itemStmt->execute();
        }

        // 4. Create Invoice (Facture)
        $invNumber = "INV-" . time() . "-" . $orderId;
        $invStmt = $conn->prepare("INSERT INTO facture (orderId, invoiceNumber, amount, statusFacture) VALUES (?, ?, ?, 'unpaid')");
        $invStmt->bind_param("isd", $orderId, $invNumber, $totalFinal);
        $invStmt->execute();

        // 5. Clear the User's Cart
        $clearStmt = $conn->prepare("DELETE FROM cart WHERE userId = ?");
        $clearStmt->bind_param("i", $userId);
        $clearStmt->execute();

        $conn->commit();
        echo json_encode(["status" => "success", "message" => "Order created successfully", "orderId" => $orderId, "invoiceNumber" => $invNumber]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Transaction failed: " . $e->getMessage()]);
    }
} elseif ($method === 'PUT') {
    requireAdmin();
    $id = intval($action);
    $data = json_decode(file_get_contents("php://input"));
    $status = $data->statusOrder;
    $stmt = $conn->prepare("UPDATE orders SET statusOrder=? WHERE idOrder=?");
    $stmt->bind_param("si", $status, $id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Order updated"]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}
?>
