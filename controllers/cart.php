<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$user = authenticate();
$method = $_SERVER['REQUEST_METHOD'];

$parts = $GLOBALS['route_parts'] ?? [];
$action = $GLOBALS['route_action'] ?? null;

if ($method === 'GET') {

    $userId = $user['idU'];

    $stmt = $conn->prepare("
        SELECT c.idCart, c.serviceId, c.durationMonths, c.domainName, s.nameService, s.descriptionS, s.price 
        FROM cart c 
        JOIN service s ON c.serviceId = s.idService 
        WHERE c.userId = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $cartItems = [];
    $total = 0;

    while ($row = $result->fetch_assoc()) {
        $cartItems[] = $row;
        // If it's a domain, price is annual. durationMonths is total months.
        if ($row['domainName']) {
            $total += (float)$row['price'] * ((int)$row['durationMonths'] / 12);
        } else {
            $total += (float)$row['price'] * (int)$row['durationMonths'];
        }
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "items" => $cartItems,
            "total" => $total
        ]
    ]);

} elseif ($method === 'POST') {

    if ($action !== 'add') {
        error_log("Invalid action: " . $action);
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid action"]);
        exit;
    }

    $raw_input = file_get_contents("php://input");
    error_log("Raw input: " . $raw_input);
    $data = json_decode($raw_input);

    if (!isset($data->serviceId)) {
        error_log("serviceId is required. Decoded Data: " . print_r($data, true));
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "serviceId is required"]);
        exit;
    }

    $serviceId = intval($data->serviceId);
    $duration = isset($data->durationMonths) ? intval($data->durationMonths) : 1;
    $domainName = isset($data->domainName) ? $conn->real_escape_string($data->domainName) : null;
    $userId = $user['idU'];

    // Check if item already in cart (same service AND same domain if applicable)
    $checkSql = "SELECT idCart FROM cart WHERE userId = ? AND serviceId = ?";
    if ($domainName) {
        $checkSql .= " AND domainName = ?";
    } else {
        $checkSql .= " AND domainName IS NULL";
    }

    $stmt = $conn->prepare($checkSql);
    if ($domainName) {
        $stmt->bind_param("iis", $userId, $serviceId, $domainName);
    } else {
        $stmt->bind_param("ii", $userId, $serviceId);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $stmt = $conn->prepare("UPDATE cart SET durationMonths = durationMonths + ? WHERE idCart = ?");
        $stmt->bind_param("ii", $duration, $row['idCart']);
        $stmt->execute();

        echo json_encode(["status" => "success", "message" => "Cart updated"]);
    } else {
        $stmt = $conn->prepare("INSERT INTO cart (userId, serviceId, durationMonths, domainName) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiis", $userId, $serviceId, $duration, $domainName);

        if ($stmt->execute()) {
            echo json_encode([
                "status" => "success",
                "message" => "Added to cart",
                "idCart" => $conn->insert_id
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Insert failed: " . $conn->error]);
        }
    }

} elseif ($method === 'PUT') {

    $raw_input = file_get_contents("php://input");
    $data = json_decode($raw_input);

    if (!isset($data->idCart) || !isset($data->durationMonths)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "idCart and durationMonths are required"]);
        exit;
    }

    $idCart = intval($data->idCart);
    $duration = intval($data->durationMonths);
    $userId = $user['idU'];

    $stmt = $conn->prepare("UPDATE cart SET durationMonths = ? WHERE idCart = ? AND userId = ?");
    $stmt->bind_param("iii", $duration, $idCart, $userId);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Item updated"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Update failed"]);
    }

} elseif ($method === 'DELETE') {

    if ($action === 'clear') {
        $userId = $user['idU'];
        $stmt = $conn->prepare("DELETE FROM cart WHERE userId = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        echo json_encode(["status" => "success", "message" => "Cart cleared"]);
        exit;
    }

    $idCart = intval($action);
    $userId = $user['idU'];

    $stmt = $conn->prepare("DELETE FROM cart WHERE idCart = ? AND userId = ?");
    $stmt->bind_param("ii", $idCart, $userId);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Item removed"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Delete failed"]);
    }

} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}   