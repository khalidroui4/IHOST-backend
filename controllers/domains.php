<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$method  = $_SERVER['REQUEST_METHOD'];
$parts   = $GLOBALS['route_parts'];
$action  = isset($parts[1]) ? $parts[1] : '';
$param   = isset($parts[2]) ? $parts[2] : '';

// ─── Helper: verify the domain belongs to the authenticated user ─────────────
function ownedDomain($conn, $idDomaine, $userId) {
    $stmt = $conn->prepare("SELECT * FROM domaine WHERE idDomaine = ? AND userId = ?");
    $stmt->bind_param("ii", $idDomaine, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Domain not found or access denied"]);
        exit;
    }
    return $row;
}

// ══════════════════════════════════════════════════════════════
//  GET
// ══════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // GET /domains/user/{userId}
    if ($action === 'user') {
        $user   = authenticate();
        $userId = $param ? intval($param) : $user['idU'];
        
        // --- DYNAMIC AUTO-RENEW EXPIRY CHECK & TRANSACTION SIMULATION ---
        // Find all domains for this user that are active, have auto_renew enabled, and are expired (expirationDate <= CURDATE())
        $expiredQuery = $conn->prepare("
            SELECT idDomaine, domainName, expirationDate 
            FROM domaine 
            WHERE userId = ? AND statusDomaine = 'active' AND auto_renew = 1 AND expirationDate <= CURDATE()
        ");
        $expiredQuery->bind_param("i", $userId);
        $expiredQuery->execute();
        $expiredRes = $expiredQuery->get_result();
        
        while ($expiredDom = $expiredRes->fetch_assoc()) {
            $domId = $expiredDom['idDomaine'];
            $domName = $expiredDom['domainName'];
            
            // Resolve price and serviceId based on domain extension
            $parts_dom = explode('.', $domName);
            $ext = "." . end($parts_dom);
            
            $serviceQuery = $conn->prepare("SELECT idService, price FROM service WHERE typeService='domain' AND LOWER(nameService) = ? AND isActive=1 LIMIT 1");
            $serviceQuery->bind_param("s", $ext);
            $serviceQuery->execute();
            $serviceRes = $serviceQuery->get_result();
            
            if ($serviceRes && $serviceRes->num_rows > 0) {
                $serviceRow = $serviceRes->fetch_assoc();
                $serviceId = $serviceRow['idService'];
                $price = (float)$serviceRow['price'];
            } else {
                $serviceId = 15; // default .COM
                $price = 120.00;
            }
            $serviceQuery->close();
            
            $conn->begin_transaction();
            try {
                // 1. Create the Order
                $orderStmt = $conn->prepare("
                    INSERT INTO orders (userId, totalAmount, statusOrder, shipping_address, city, postal_code, payment_method) 
                    VALUES (?, ?, 'paid', 'Auto-Renouvellement', 'Casablanca', '20000', 'credit_card')
                ");
                $orderStmt->bind_param("id", $userId, $price);
                $orderStmt->execute();
                $orderId = $conn->insert_id;
                $orderStmt->close();
                
                // 2. Create Order Item
                $itemStmt = $conn->prepare("
                    INSERT INTO order_items (orderId, serviceId, durationMonths, price, domainName) 
                    VALUES (?, ?, 12, ?, ?)
                ");
                $itemStmt->bind_param("iids", $orderId, $serviceId, $price, $domName);
                $itemStmt->execute();
                $itemStmt->close();
                
                // 3. Create Invoice (Facture) with 'paid' status
                $invNumber = "INV-AUTO-" . time() . "-" . $orderId;
                $invStmt = $conn->prepare("
                    INSERT INTO facture (orderId, invoiceNumber, amount, statusFacture) 
                    VALUES (?, ?, ?, 'paid')
                ");
                $invStmt->bind_param("isd", $orderId, $invNumber, $price);
                $invStmt->execute();
                $invStmt->close();
                
                // 4. Create Payment record
                $payStmt = $conn->prepare("
                    INSERT INTO payement (orderId, method, amount, statusPay, paidAt) 
                    VALUES (?, 'credit_card', ?, 'success', CURRENT_TIMESTAMP)
                ");
                $payStmt->bind_param("id", $orderId, $price);
                $payStmt->execute();
                $payStmt->close();
                
                // 5. Update domain expiration Date (+1 year)
                $updateDom = $conn->prepare("
                    UPDATE domaine 
                    SET expirationDate = DATE_ADD(expirationDate, INTERVAL 1 YEAR), statusDomaine = 'active' 
                    WHERE idDomaine = ?
                ");
                $updateDom->bind_param("i", $domId);
                $updateDom->execute();
                $updateDom->close();
                
                // 6. Log activity
                logActivity($conn, $userId, 'domain_autorenewed', "Renouvellement automatique: " . $domName, 'active');
                
                // 7. Insert notification
                $notifMsg = "Renouvellement automatique réussi pour le domaine $domName. Facture $invNumber générée.";
                $notifStmt = $conn->prepare("INSERT INTO notification (userId, message, isRead) VALUES (?, ?, 0)");
                $notifStmt->bind_param("is", $userId, $notifMsg);
                $notifStmt->execute();
                $notifStmt->close();
                
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Auto-renew failed for domain ID $domId: " . $e->getMessage());
            }
        }
        $expiredQuery->close();

        // Query final list of domains
        $stmt   = $conn->prepare("SELECT * FROM domaine WHERE userId = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result  = $stmt->get_result();
        $domains = [];
        while ($row = $result->fetch_assoc()) $domains[] = $row;
        echo json_encode(["status" => "success", "data" => $domains]);
        exit;
    }

    // GET /domains/dns/{domaineId}
    if ($action === 'dns') {
        $user      = authenticate();
        $domaineId = intval($param);
        ownedDomain($conn, $domaineId, $user['idU']);
        $stmt = $conn->prepare("SELECT * FROM dns_records WHERE domaineId = ? ORDER BY type, name");
        $stmt->bind_param("i", $domaineId);
        $stmt->execute();
        $result  = $stmt->get_result();
        $records = [];
        while ($row = $result->fetch_assoc()) $records[] = $row;
        echo json_encode(["status" => "success", "data" => $records]);
        exit;
    }

    // GET /domains/check/{name}
    if ($action === 'check') {
        $domain = $param;
        if (empty($domain)) {
            echo json_encode(["status" => "error", "message" => "Domain name required"]); exit;
        }
        if (strpos($domain, '.') === false) $domain .= ".com";

        // Check if domain is registered by any user in our system
        $checkStmt = $conn->prepare("SELECT idDomaine FROM domaine WHERE LOWER(domainName) = ?");
        $lowDomain = strtolower($domain);
        $checkStmt->bind_param("s", $lowDomain);
        $checkStmt->execute();
        $checkStmt->store_result();
        if ($checkStmt->num_rows > 0) {
            $checkStmt->close();
            echo json_encode(["status" => "success", "available" => false, "domain" => $domain, "source" => "local_database"]);
            exit;
        }
        $checkStmt->close();

        $url = "https://rdap.org/domain/" . urlencode($domain);
        $ch  = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'IHOST-Registry-Checker/2.0');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200) {
            echo json_encode(["status" => "success", "available" => false, "domain" => $domain, "source" => "rdap"]);
        } elseif ($httpCode === 404) {
            $taken = checkdnsrr($domain,'A') || checkdnsrr($domain,'MX') || checkdnsrr($domain,'NS');
            echo json_encode(["status" => "success", "available" => !$taken, "domain" => $domain, "source" => "rdap_confirm"]);
        } else {
            $isTaken = checkdnsrr($domain,'A') || checkdnsrr($domain,'MX') || checkdnsrr($domain,'NS') || (gethostbyname($domain) !== $domain);
            echo json_encode(["status" => "success", "available" => !$isTaken, "domain" => $domain, "source" => "dns_fallback"]);
        }
        exit;
    }

    // GET /domains/whois/{domainName}
    if ($action === 'whois') {
        $domain = $param;
        if (empty($domain)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Domain name required"]);
            exit;
        }
        $stmt = $conn->prepare("SELECT d.*, u.idU, u.nameU, u.email as user_email, u.username, u.avatar, u.first_name, u.last_name FROM domaine d JOIN users u ON d.userId = u.idU WHERE d.domainName = ?");
        $stmt->bind_param("s", $domain);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $privacy = (bool)$row['whois_privacy'];
            echo json_encode([
                "status" => "success",
                "registered" => true,
                "data" => [
                    "domainName" => $row['domainName'],
                    "expirationDate" => $row['expirationDate'],
                    "statusDomaine" => $row['statusDomaine'],
                    "whois_privacy" => $privacy,
                    "is_locked" => (bool)$row['is_locked'],
                    "owner_id" => $privacy ? null : $row['idU'],
                    "owner_name" => $privacy ? "Redacted for Privacy" : $row['nameU'],
                    "owner_first_name" => $privacy ? "Redacted" : $row['first_name'],
                    "owner_last_name" => $privacy ? "for Privacy" : $row['last_name'],
                    "owner_username" => $privacy ? null : $row['username'],
                    "owner_avatar" => $privacy ? null : $row['avatar'],
                    "owner_email" => $privacy ? "whoisprivacy@ihost.ma" : $row['user_email']
                ]
            ]);
        } else {
            echo json_encode([
                "status" => "success",
                "registered" => false,
                "message" => "Domain not registered on our platform"
            ]);
        }
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
//  POST
// ══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $user = authenticate();
    $data = json_decode(file_get_contents("php://input"));

    // POST /domains/dns/{domaineId}
    if ($action === 'dns') {
        $domaineId = intval($param);
        ownedDomain($conn, $domaineId, $user['idU']);
        $type     = $conn->real_escape_string($data->type     ?? '');
        $name     = $conn->real_escape_string($data->name     ?? '');
        $value    = $conn->real_escape_string($data->value    ?? '');
        $priority = isset($data->priority) ? intval($data->priority) : null;
        $ttl      = isset($data->ttl)      ? intval($data->ttl)      : 3600;
        if (!in_array($type, ['A','CNAME','MX']) || empty($name) || empty($value)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Type, name and value are required"]);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO dns_records (domaineId, type, name, value, priority, ttl) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("isssii", $domaineId, $type, $name, $value, $priority, $ttl);
        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            logActivity($conn, $user['idU'], 'dns_record_added', "DNS {$type} ajouté pour domaine #{$domaineId}", 'active');
            echo json_encode(["status" => "success", "message" => "DNS record added", "id" => $newId]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Failed to add record"]);
        }
        exit;
    }

    // POST /domains — register domain
    $domain = $conn->real_escape_string($data->domainName);
    $stmt   = $conn->prepare("INSERT INTO domaine (userId, domainName, expirationDate, statusDomaine) VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'active')");
    $stmt->bind_param("is", $user['idU'], $domain);
    if ($stmt->execute()) {
        logActivity($conn, $user['idU'], 'domain_registered', "Domaine enregistré: " . $domain, 'active');
        echo json_encode(["status" => "success", "message" => "Domain registered"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Domain already exists"]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
//  PUT
// ══════════════════════════════════════════════════════════════
if ($method === 'PUT') {
    $user = authenticate();
    $data = json_decode(file_get_contents("php://input"));

    // PUT /domains/renew/{id}
    if ($action === 'renew') {
        $id  = intval($param);
        $dom = ownedDomain($conn, $id, $user['idU']);
        
        // 1. Resolve price and serviceId based on domain extension
        $parts_dom = explode('.', $dom['domainName']);
        $ext = "." . end($parts_dom);
        
        $serviceQuery = $conn->prepare("SELECT idService FROM service WHERE typeService='domain' AND LOWER(nameService) = ? AND isActive=1 LIMIT 1");
        $serviceQuery->bind_param("s", $ext);
        $serviceQuery->execute();
        $serviceRes = $serviceQuery->get_result();
        
        if ($serviceRes && $serviceRes->num_rows > 0) {
            $serviceRow = $serviceRes->fetch_assoc();
            $serviceId = $serviceRow['idService'];
        } else {
            $serviceId = 15; // default .COM
        }
        $serviceQuery->close();
        
        // 2. Add to user's cart (durationMonths = 12, domainName = $dom['domainName'])
        $checkCart = $conn->prepare("SELECT idCart FROM cart WHERE userId = ? AND serviceId = ? AND domainName = ?");
        $checkCart->bind_param("iis", $user['idU'], $serviceId, $dom['domainName']);
        $checkCart->execute();
        $checkCartRes = $checkCart->get_result();
        
        if ($checkCartRes->num_rows === 0) {
            $addCart = $conn->prepare("INSERT INTO cart (userId, serviceId, durationMonths, domainName) VALUES (?, ?, 12, ?)");
            $addCart->bind_param("iis", $user['idU'], $serviceId, $dom['domainName']);
            $addCart->execute();
            $addCart->close();
        }
        $checkCart->close();
        
        echo json_encode([
            "status" => "success",
            "addedToCart" => true,
            "message" => "Domain renewal added to cart"
        ]);
        exit;
    }

    // PUT /domains/toggle-autorenew/{id}
    if ($action === 'toggle-autorenew') {
        $id  = intval($param);
        $dom = ownedDomain($conn, $id, $user['idU']);
        $new = $dom['auto_renew'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE domaine SET auto_renew = ? WHERE idDomaine = ?");
        $stmt->bind_param("ii", $new, $id);
        $stmt->execute();
        logActivity($conn, $user['idU'], 'domain_autorenew_toggled', "Auto-renouvellement " . ($new ? 'activé' : 'désactivé') . ": " . $dom['domainName'], 'active');
        echo json_encode(["status" => "success", "auto_renew" => (bool)$new]);
        exit;
    }

    // PUT /domains/toggle-lock/{id}
    if ($action === 'toggle-lock') {
        $id  = intval($param);
        $dom = ownedDomain($conn, $id, $user['idU']);
        $new = $dom['is_locked'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE domaine SET is_locked = ? WHERE idDomaine = ?");
        $stmt->bind_param("ii", $new, $id);
        $stmt->execute();
        logActivity($conn, $user['idU'], 'domain_lock_toggled', "Verrouillage " . ($new ? 'activé' : 'désactivé') . ": " . $dom['domainName'], 'active');
        echo json_encode(["status" => "success", "is_locked" => (bool)$new]);
        exit;
    }

    // PUT /domains/toggle-privacy/{id}
    if ($action === 'toggle-privacy') {
        $id  = intval($param);
        $dom = ownedDomain($conn, $id, $user['idU']);
        $new = $dom['whois_privacy'] ? 0 : 1;

        // If turning ON, check if they have a valid active subscription for WHOIS protection
        if ($new === 1) {
            $checkSub = $conn->prepare("
                SELECT sub.idSub 
                FROM subscription sub 
                JOIN service s ON sub.serviceId = s.idService 
                WHERE sub.userId = ? AND sub.domainName = ? AND s.nameService = 'Protection WHOIS' AND sub.endDate >= CURDATE()
                LIMIT 1
            ");
            $checkSub->bind_param("is", $user['idU'], $dom['domainName']);
            $checkSub->execute();
            $subRes = $checkSub->get_result();
            if ($subRes->num_rows === 0) {
                http_response_code(402);
                echo json_encode(["status" => "error", "message" => "Veuillez acheter l'extension Protection WHOIS pour activer cette fonctionnalité."]);
                exit;
            }
            $checkSub->close();
        }

        $stmt = $conn->prepare("UPDATE domaine SET whois_privacy = ? WHERE idDomaine = ?");
        $stmt->bind_param("ii", $new, $id);
        $stmt->execute();
        logActivity($conn, $user['idU'], 'domain_privacy_toggled', "WHOIS Privacy " . ($new ? 'activé' : 'désactivé') . ": " . $dom['domainName'], 'active');
        echo json_encode(["status" => "success", "whois_privacy" => (bool)$new]);
        exit;
    }

    // PUT /domains/transfer/{id}
    if ($action === 'transfer') {
        $id  = intval($param);
        $dom = ownedDomain($conn, $id, $user['idU']);
        $epp = trim($data->eppCode ?? '');
        if (empty($epp)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "EPP code is required"]);
            exit;
        }
        if ($dom['is_locked']) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Domain is locked. Disable lock before transferring."]);
            exit;
        }
        $stmt = $conn->prepare("UPDATE domaine SET statusDomaine = 'expired' WHERE idDomaine = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        logActivity($conn, $user['idU'], 'domain_transfer_requested', "Transfert demandé: " . $dom['domainName'], 'expired');
        echo json_encode(["status" => "success", "message" => "Transfer request submitted"]);
        exit;
    }

    // PUT /domains/dns/record/{recordId}
    if ($action === 'dns' && $param === 'record') {
        $recordId = isset($parts[3]) ? intval($parts[3]) : 0;
        $check = $conn->prepare("SELECT r.idRecord FROM dns_records r JOIN domaine d ON r.domaineId = d.idDomaine WHERE r.idRecord = ? AND d.userId = ?");
        $check->bind_param("ii", $recordId, $user['idU']);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Record not found or access denied"]);
            exit;
        }
        $name     = $conn->real_escape_string($data->name  ?? '');
        $value    = $conn->real_escape_string($data->value ?? '');
        $priority = isset($data->priority) ? intval($data->priority) : null;
        $ttl      = isset($data->ttl)      ? intval($data->ttl)      : 3600;
        $stmt = $conn->prepare("UPDATE dns_records SET name=?, value=?, priority=?, ttl=? WHERE idRecord=?");
        $stmt->bind_param("ssiii", $name, $value, $priority, $ttl, $recordId);
        $stmt->execute();
        echo json_encode(["status" => "success", "message" => "DNS record updated"]);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
//  DELETE
// ══════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    $user = authenticate();

    // DELETE /domains/dns/record/{recordId}
    if ($action === 'dns' && $param === 'record') {
        $recordId = isset($parts[3]) ? intval($parts[3]) : 0;
        $check = $conn->prepare("SELECT r.idRecord FROM dns_records r JOIN domaine d ON r.domaineId = d.idDomaine WHERE r.idRecord = ? AND d.userId = ?");
        $check->bind_param("ii", $recordId, $user['idU']);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Record not found or access denied"]);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM dns_records WHERE idRecord = ?");
        $stmt->bind_param("i", $recordId);
        $stmt->execute();
        echo json_encode(["status" => "success", "message" => "DNS record deleted"]);
        exit;
    }
}
?>
