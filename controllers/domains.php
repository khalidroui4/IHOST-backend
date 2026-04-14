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

        if (strpos($domain, '.') === false) {
            $domain .= ".com";
        }
        
        $url = "https://rdap.org/domain/" . urlencode($domain);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'IHOST-Registry-Checker/2.0 (Compatible; RealCheck)');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 200) {
            // Domain is definitely registered
            echo json_encode(["status" => "success", "available" => false, "domain" => $domain, "source" => "rdap"]);
        } elseif ($httpCode === 404) {
            // Domain is likely available, but let's double check via DNS as secondary verification
            if (checkdnsrr($domain, 'A') || checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'NS')) {
                echo json_encode(["status" => "success", "available" => false, "domain" => $domain, "source" => "dns_check"]);
            } else {
                echo json_encode(["status" => "success", "available" => true, "domain" => $domain, "source" => "rdap_confirm"]);
            }
        } else {
            // Fallback to purely DNS check if RDAP is down or restricted
            $isTaken = checkdnsrr($domain, 'A') || checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'NS') || (gethostbyname($domain) !== $domain);
            echo json_encode([
                "status" => "success", 
                "available" => !$isTaken, 
                "domain" => $domain,
                "source" => "dns_fallback",
                "debug_code" => $httpCode
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
