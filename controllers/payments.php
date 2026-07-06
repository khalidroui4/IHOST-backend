<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$user = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'GET') {
    $orderId = intval($action); 
    $stmt = $conn->prepare("SELECT * FROM payement WHERE orderId = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $payments = [];
    while($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    echo json_encode(["status" => "success", "data" => $payments]);
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    if (!isset($data->orderId)) {
        http_response_code(400);
        die(json_encode(["status" => "error", "message" => "orderId is required"]));
    }

    $orderId = intval($data->orderId);
    $amount = isset($data->amount) ? floatval($data->amount) : 0;
    $payMethod = isset($data->method) ? $conn->real_escape_string($data->method) : 'credit_card';

    $conn->begin_transaction();
    try {
        // 1. Log Payment
        $stmt = $conn->prepare("INSERT INTO payement (orderId, method, amount, statusPay, paidAt) VALUES (?, ?, ?, 'success', CURRENT_TIMESTAMP)");
        $stmt->bind_param("isd", $orderId, $payMethod, $amount);
        $stmt->execute();

        // 2. Update Order + Facture Status
        $conn->query("UPDATE orders SET statusOrder='paid', payment_method='$payMethod' WHERE idOrder=$orderId");
        $conn->query("UPDATE facture SET statusFacture='paid' WHERE orderId=$orderId");

        // 3. Activate subscriptions and register domains for all items in the order
        $uId = $user['idU'];
        $itemsRes = $conn->query("
            SELECT oi.serviceId, oi.durationMonths, oi.domainName, oi.whois_privacy, s.typeService 
            FROM order_items oi 
            JOIN service s ON oi.serviceId = s.idService 
            WHERE oi.orderId=$orderId
        ");
        if ($itemsRes && $itemsRes->num_rows > 0) {
            $domStmt = $conn->prepare("INSERT IGNORE INTO domaine (userId, domainName, expirationDate, statusDomaine, whois_privacy) VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 12 MONTH), 'active', ?)");

            while($item = $itemsRes->fetch_assoc()) {
                $sId = $item['serviceId'];
                $dName = $item['domainName'] ?? null;
                $sType = $item['typeService'];
                $whoisPrivacy = intval($item['whois_privacy']);
                $duration = isset($item['durationMonths']) && intval($item['durationMonths']) > 0 ? intval($item['durationMonths']) : 1;
                
                // Activate subscription directly in PHP instead of calling a stored procedure
                $subInsert = $conn->prepare("
                    INSERT INTO subscription (userId, serviceId, startDate, endDate, statusSub, domainName) 
                    VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), 'active', ?)
                ");
                $subInsert->bind_param("iiis", $uId, $sId, $duration, $dName);
                $subInsert->execute();
                $subInsert->close();

                // If it's a domain, populate the domaine table or extend the expiration date if it already exists!
                if ($sType === 'domain' && $dName) {
                    $checkDom = $conn->prepare("SELECT idDomaine FROM domaine WHERE domainName = ?");
                    $checkDom->bind_param("s", $dName);
                    $checkDom->execute();
                    $checkRes = $checkDom->get_result();
                    if ($checkRes->num_rows > 0) {
                        // Domain already exists: EXTEND expiration date by 1 year!
                        $updateDom = $conn->prepare("UPDATE domaine SET expirationDate = DATE_ADD(expirationDate, INTERVAL 12 MONTH), statusDomaine = 'active', whois_privacy = ? WHERE domainName = ?");
                        $updateDom->bind_param("is", $whoisPrivacy, $dName);
                        $updateDom->execute();
                        $updateDom->close();
                        logActivity($conn, $uId, 'domain_renewed', "Domaine renouvelé: " . $dName, 'active');
                    } else {
                        // Register new domain
                        $domStmt->bind_param("isi", $uId, $dName, $whoisPrivacy);
                        $domStmt->execute();
                        logActivity($conn, $uId, 'domain_registered', "Domaine enregistré: " . $dName, 'active');
                    }
                    $checkDom->close();

                    // If WHOIS privacy was purchased with the domain, also register a WHOIS protection subscription!
                    if ($whoisPrivacy === 1) {
                        $whoisServiceQuery = $conn->query("SELECT idService FROM service WHERE nameService = 'Protection WHOIS' LIMIT 1");
                        if ($whoisServiceQuery && $whoisServiceQuery->num_rows > 0) {
                            $whoisService = $whoisServiceQuery->fetch_assoc();
                            $whoisServiceId = $whoisService['idService'];
                            
                            $whoisSub = $conn->prepare("
                                INSERT INTO subscription (userId, serviceId, startDate, endDate, statusSub, domainName) 
                                VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), 'active', ?)
                            ");
                            $whoisSub->bind_param("iiis", $uId, $whoisServiceId, $duration, $dName);
                            $whoisSub->execute();
                            $whoisSub->close();
                        }
                    }
                }
            }
            $domStmt->close();
        }

        logActivity($conn, $uId, 'payment', "Paiement de " . $amount . " DH", 'success');

        // Build notification message from order items
        $itemsForNotif = $conn->query("SELECT oi.domainName, s.nameService FROM order_items oi JOIN service s ON oi.serviceId = s.idService WHERE oi.orderId=$orderId");
        $labels = [];
        while($ni = $itemsForNotif->fetch_assoc()) {
            $labels[] = $ni['domainName'] ? $ni['domainName'] . ' (Domaine)' : $ni['nameService'];
        }
        $itemList   = implode(', ', $labels);
        $notifMsg   = "Votre commande a été confirmée : $itemList — Montant: " . number_format($amount, 2) . " DH";
        $notifStmt  = $conn->prepare("INSERT INTO notification (userId, message, isRead) VALUES (?, ?, 0)");
        $notifStmt->bind_param("is", $uId, $notifMsg);
        $notifStmt->execute();

        $conn->commit();
        echo json_encode(["status" => "success", "message" => "Payment successful. Subscriptions activated."]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Payment failed: " . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}
?>
