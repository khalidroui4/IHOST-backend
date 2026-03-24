<?php
require __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'GET') {
    $result = $conn->query("SELECT * FROM service WHERE isActive=1");
    $services = [];
    while($row = $result->fetch_assoc()) {
        $services[] = $row;
    }
    echo json_encode(["status" => "success", "data" => $services]);
} else {
    require_once __DIR__ . '/../middleware/authMiddleware.php';
    requireAdmin();
    // POST, PUT, DELETE for Admin
    if ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $conn->prepare("INSERT INTO service (nameService, descriptionS, price, durationMonths) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssdi", $data->nameService, $data->descriptionS, $data->price, $data->durationMonths);
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Service created"]);
        } else {
            echo json_encode(["status" => "error", "message" => $conn->error]);
        }
    } elseif ($method === 'PUT') {
        $id = intval($action);
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $conn->prepare("UPDATE service SET nameService=?, descriptionS=?, price=?, durationMonths=?, isActive=? WHERE idService=?");
        $active = isset($data->isActive) ? $data->isActive : 1;
        $stmt->bind_param("ssdiii", $data->nameService, $data->descriptionS, $data->price, $data->durationMonths, $active, $id);
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Service updated"]);
        } else {
            echo json_encode(["status" => "error", "message" => $conn->error]);
        }
    } elseif ($method === 'DELETE') {
        $id = intval($action);
        $stmt = $conn->prepare("UPDATE service SET isActive=0 WHERE idService=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Service deleted/deactivated"]);
        }
    }
}
?>
