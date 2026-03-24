<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$user = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'GET') {
    if ($action === 'user') {
        $userId = isset($parts[2]) ? intval($parts[2]) : $user['idU'];
        $stmt = $conn->prepare("SELECT * FROM support WHERE userId = ? ORDER BY createdAt DESC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = [];
        while($row = $result->fetch_assoc()) {
            $tId = $row['idSupport'];
            $msgRes = $conn->query("SELECT * FROM support_messages WHERE ticketId=$tId ORDER BY createdAt ASC");
            $msgs = [];
            while($m = $msgRes->fetch_assoc()) $msgs[] = $m;
            $row['messages'] = $msgs;
            $tickets[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $tickets]);
    } else {
        requireAdmin();
        $result = $conn->query("SELECT * FROM support ORDER BY createdAt DESC");
        $tickets = [];
        while($row = $result->fetch_assoc()) {
            $tId = $row['idSupport'];
            $msgRes = $conn->query("SELECT * FROM support_messages WHERE ticketId=$tId ORDER BY createdAt ASC");
            $msgs = [];
            while($m = $msgRes->fetch_assoc()) $msgs[] = $m;
            $row['messages'] = $msgs;
            $tickets[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $tickets]);
    }
} elseif ($method === 'POST') {
    if ($action === 'message') {
        $data = json_decode(file_get_contents("php://input"));
        $tId = intval($data->ticketId);
        $msg = $conn->real_escape_string($data->message);
        $sender = $user['roleU'] === 'admin' ? 'admin' : 'client';
        $stmt = $conn->prepare("INSERT INTO support_messages (ticketId, sender, message) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $tId, $sender, $msg);
        if($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Message sent"]);
        }
    } else {
        $data = json_decode(file_get_contents("php://input"));
        $subject = $conn->real_escape_string($data->subjectSupport);
        $stmt = $conn->prepare("INSERT INTO support (userId, subjectSupport, statusSupport) VALUES (?, ?, 'open')");
        $stmt->bind_param("is", $user['idU'], $subject);
        if($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Ticket created"]);
        }
    }
}
?>
