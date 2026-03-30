<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$parts  = $GLOBALS['route_parts'] ?? [];
$action = isset($parts[1]) ? $parts[1] : '';

// ─── Anonymous ticket creation (no auth required) ───────────────────────────
if ($method === 'POST' && $action === 'anonymous') {
    $data    = json_decode(file_get_contents("php://input"));
    $subject = $conn->real_escape_string($data->subjectSupport ?? 'Demande anonyme');
    $msgBody = isset($data->message) ? $conn->real_escape_string($data->message) : '';

    // userId = NULL marks this as anonymous
    $stmt = $conn->prepare("INSERT INTO support (userId, subjectSupport, statusSupport) VALUES (NULL, ?, 'open')");
    $stmt->bind_param("s", $subject);
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        if ($msgBody !== '') {
            $msgStmt = $conn->prepare("INSERT INTO support_messages (ticketId, sender, message) VALUES (?, 'Anonyme', ?)");
            $msgStmt->bind_param("is", $newId, $msgBody);
            $msgStmt->execute();
        }
        echo json_encode(["status" => "success", "message" => "Ticket created (anonymous)", "idTicket" => $newId]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to create ticket"]);
    }
    exit;
}

// ─── All other routes require authentication ────────────────────────────────
$user = authenticate();


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
        $result = $conn->query("
            SELECT s.*, 
                   u.username, u.email, u.first_name, u.last_name, u.avatar,
                   u.idU
            FROM support s
            LEFT JOIN users u ON s.userId = u.idU
            ORDER BY s.createdAt DESC
        ");
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
            $newId = $conn->insert_id;
            // Also insert the user's message as the first support_message so agents see it
            if (isset($data->message)) {
                $msg = $conn->real_escape_string($data->message);
                $msgStmt = $conn->prepare("INSERT INTO support_messages (ticketId, sender, message) VALUES (?, 'client', ?)");
                $msgStmt->bind_param("is", $newId, $msg);
                $msgStmt->execute();
            }
            echo json_encode([
                "status" => "success",
                "message" => "Ticket created",
                "idTicket" => $newId
            ]);
        }
    }
} elseif ($method === 'PUT') {
    // PUT /support/{ticketId}/status  →  change ticket status (admin only)
    requireAdmin();
    $ticketId = isset($parts[1]) ? intval($parts[1]) : 0;
    $data = json_decode(file_get_contents("php://input"));
    $newStatus = in_array($data->status, ['open', 'closed']) ? $data->status : 'open';
    $stmt = $conn->prepare("UPDATE support SET statusSupport = ? WHERE idSupport = ?");
    $stmt->bind_param("si", $newStatus, $ticketId);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Ticket status updated", "newStatus" => $newStatus]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Update failed"]);
    }
}
?>
