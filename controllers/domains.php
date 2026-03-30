<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';


$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'GET') {
    if ($action === 'user') {
        $user = authenticate();
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
        
        $url = "https://rdap.org/domain/" . urlencode($domain);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'IHOST-Backend/1.0 (Real Domain Search)');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            echo json_encode(["status" => "success", "available" => false, "domain" => $domain]);
        } elseif ($httpCode === 404) {
            echo json_encode(["status" => "success", "available" => true, "domain" => $domain]);
        } else {
            $hasDns = checkdnsrr($domain, 'ANY');
            echo json_encode([
                "status" => "success", 
                "available" => !$hasDns, 
                "domain" => $domain,
                "via" => "dns_fallback",
                "code" => $httpCode
            ]);
        }
    }
} elseif ($method === 'POST') {
    $user = authenticate();
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
