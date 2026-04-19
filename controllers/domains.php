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
        $stmt = $conn->prepare("UPDATE domaine SET expirationDate = DATE_ADD(expirationDate, INTERVAL 1 YEAR), statusDomaine = 'active' WHERE idDomaine = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        logActivity($conn, $user['idU'], 'domain_renewed', "Domaine renouvelé: " . $dom['domainName'], 'active');
        $stmt2 = $conn->prepare("SELECT expirationDate FROM domaine WHERE idDomaine = ?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        $row = $stmt2->get_result()->fetch_assoc();
        echo json_encode(["status" => "success", "message" => "Domain renewed for 1 year", "newExpiry" => $row['expirationDate']]);
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
