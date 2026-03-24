<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$user = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'GET') {
    if ($action === 'user') {
        $userId = isset($parts[2]) ? intval($parts[2]) : $user['idU'];
        $stmt = $conn->prepare("SELECT * FROM domaine WHERE userId = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $domains = [];
        while($row = $result->fetch_assoc()) {
            $domains[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $domains]);
    }
    elseif ($action === 'check') {
        $domain = isset($parts[2]) ? $parts[2] : '';
        if (empty($domain)) {
            echo json_encode(["status" => "error", "message" => "Domain name required"]);
            exit;
        }
        
        // Use DNS check as a real/mock proxy for availability since pure WHOIS via shell on Windows is prone to failure.
        $hasDns = checkdnsrr($domain, 'ANY');
        if ($hasDns) {
            echo json_encode(["status" => "success", "available" => false, "domain" => $domain]);
        } else {
            echo json_encode(["status" => "success", "available" => true, "domain" => $domain]);
        }
    }
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    $domain = $conn->real_escape_string($data->domainName);
    $stmt = $conn->prepare("INSERT INTO domaine (userId, domainName, expirationDate, statusDomaine) VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'active')");
    $stmt->bind_param("is", $user['idU'], $domain);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Domain registered"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Domain already exists"]);
    }
}
?>
