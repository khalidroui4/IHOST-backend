<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/authMiddleware.php';

$user = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'GET') {
    if ($action === 'user') {
        $userId = isset($parts[2]) ? intval($parts[2]) : $user['idU'];
        if ($user['roleU'] !== 'admin' && $userId !== $user['idU']) {
            http_response_code(403);
            exit;
        }
        $stmt = $conn->prepare("SELECT * FROM orders WHERE userId = ? ORDER BY createdAt DESC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = [];
        while($row = $result->fetch_assoc()) {
            // Fetch order items with service details
            $itemStmt = $conn->prepare("
                SELECT oi.*, s.nameService, s.typeService 
                FROM order_items oi 
                JOIN service s ON oi.serviceId = s.idService 
                WHERE oi.orderId = ?
            ");
            $itemStmt->bind_param("i", $row['idOrder']);
            $itemStmt->execute();
            $itemResult = $itemStmt->get_result();
            $items = [];
            $labels = [];
            while($item = $itemResult->fetch_assoc()) {
                $items[] = $item;
                // Build a human-readable label
                if ($item['domainName']) {
                    $labels[] = $item['domainName'] . ' - Domaine';
                } else {
                    $labels[] = $item['nameService'];
                }
            }
            $row['items'] = $items;
            $row['label'] = implode(', ', $labels);
            $orders[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $orders]);
    } else {
        requireAdmin();
        $result = $conn->query("
            SELECT o.*, u.nameU, u.email 
            FROM orders o 
            JOIN users u ON o.userId = u.idU 
            ORDER BY o.createdAt DESC
        ");
        $orders = [];
        while($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $orders]);
    }
} elseif ($method === 'POST') {
    // Create new order from user's current cart
    $userId = $user['idU'];

    // Decode request data for billing/shipping details
    $data = json_decode(file_get_contents("php://input"));
    $shipping_address = isset($data->shipping_address) ? $conn->real_escape_string($data->shipping_address) : '';
    $city = isset($data->city) ? $conn->real_escape_string($data->city) : '';
    $postal_code = isset($data->postal_code) ? $conn->real_escape_string($data->postal_code) : '';
    $payment_method = isset($data->payment_method) ? $conn->real_escape_string($data->payment_method) : '';

    // 1. Fetch cart items
    $cartStmt = $conn->prepare("
        SELECT c.idCart, c.serviceId, c.durationMonths, c.domainName, c.whois_privacy, s.price 
        FROM cart c 
        JOIN service s ON c.serviceId = s.idService 
        WHERE c.userId = ?
    ");
    $cartStmt->bind_param("i", $userId);
    $cartStmt->execute();
    $cartRes = $cartStmt->get_result();
    
    if ($cartRes->num_rows === 0) {
        http_response_code(400);
        die(json_encode(["status" => "error", "message" => "Cart is empty"]));
    }

    $cartItems = [];
    $totalAmount = 0;
    while($row = $cartRes->fetch_assoc()) {
        $cartItems[] = $row;
        // If it's a domain, price is annual. durationMonths is total months.
        if ($row['domainName']) {
            $itemTotal = (float)$row['price'] * ((int)$row['durationMonths'] / 12);
            if ((int)$row['whois_privacy'] === 1) {
                $itemTotal += 50.0 * ((int)$row['durationMonths'] / 12);
            }
            $totalAmount += $itemTotal;
        } else {
            $totalAmount += (float)$row['price'] * (int)$row['durationMonths'];
        }
    }

    // TVA removal: Total is now exactly the sum of item prices
    $totalFinal = $totalAmount;

    $conn->begin_transaction();
    try {
        // 2. Create the main Order
        $stmt = $conn->prepare("INSERT INTO orders (userId, totalAmount, statusOrder, shipping_address, city, postal_code, payment_method) VALUES (?, ?, 'pending', ?, ?, ?, ?)");
        $stmt->bind_param("idssss", $userId, $totalFinal, $shipping_address, $city, $postal_code, $payment_method);
        $stmt->execute();
        $orderId = $conn->insert_id;

        // 3. Create Order Items
        $itemStmt = $conn->prepare("INSERT INTO order_items (orderId, serviceId, durationMonths, price, domainName, whois_privacy) VALUES (?, ?, ?, ?, ?, ?)");
        foreach($cartItems as $item) {
            $itemStmt->bind_param("iiidsi", $orderId, $item['serviceId'], $item['durationMonths'], $item['price'], $item['domainName'], $item['whois_privacy']);
            $itemStmt->execute();
        }

        // 4. Create Invoice (Facture)
        $invNumber = "INV-" . time() . "-" . $orderId;
        $invStmt = $conn->prepare("INSERT INTO facture (orderId, invoiceNumber, amount, statusFacture) VALUES (?, ?, ?, 'unpaid')");
        $invStmt->bind_param("isd", $orderId, $invNumber, $totalFinal);
        $invStmt->execute();

        // 5. Clear the User's Cart
        $clearStmt = $conn->prepare("DELETE FROM cart WHERE userId = ?");
        $clearStmt->bind_param("i", $userId);
        $clearStmt->execute();

        logActivity($conn, $userId, 'order', "Commande #" . $orderId, 'unpaid');

        $conn->commit();
        echo json_encode(["status" => "success", "message" => "Order created successfully", "orderId" => $orderId, "invoiceNumber" => $invNumber]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Transaction failed: " . $e->getMessage()]);
    }
} elseif ($method === 'PUT') {
    requireAdmin();
    $id = intval($action);
    $data = json_decode(file_get_contents("php://input"));
    $status = $data->statusOrder;

    if ($status === 'paid') {
        $conn->begin_transaction();
        try {
            $orderQuery = $conn->prepare("SELECT userId, totalAmount FROM orders WHERE idOrder = ?");
            $orderQuery->bind_param("i", $id);
            $orderQuery->execute();
            $orderRow = $orderQuery->get_result()->fetch_assoc();
            $orderQuery->close();
            
            if ($orderRow) {
                $uId = $orderRow['userId'];
                $amount = floatval($orderRow['totalAmount']);
                
                // 1. Log Payment
                $stmt = $conn->prepare("INSERT INTO payement (orderId, method, amount, statusPay, paidAt) VALUES (?, 'admin_approval', ?, 'success', CURRENT_TIMESTAMP)");
                $stmt->bind_param("id", $id, $amount);
                $stmt->execute();
                $stmt->close();
                
                // 2. Update Order + Facture Status
                $conn->query("UPDATE orders SET statusOrder='paid', payment_method='admin_approval' WHERE idOrder=$id");
                $conn->query("UPDATE facture SET statusFacture='paid' WHERE orderId=$id");
                
                // 3. Activate subscriptions and register domains for all items in the order
                $itemsRes = $conn->query("
                    SELECT oi.serviceId, oi.durationMonths, oi.domainName, oi.whois_privacy, s.typeService 
                    FROM order_items oi 
                    JOIN service s ON oi.serviceId = s.idService 
                    WHERE oi.orderId=$id
                ");
                if ($itemsRes && $itemsRes->num_rows > 0) {
                    $domStmt = $conn->prepare("INSERT IGNORE INTO domaine (userId, domainName, expirationDate, statusDomaine, whois_privacy) VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 12 MONTH), 'active', ?)");

                    while($item = $itemsRes->fetch_assoc()) {
                        $sId = $item['serviceId'];
                        $dName = $item['domainName'] ?? null;
                        $sType = $item['typeService'];
                        $whoisPrivacy = intval($item['whois_privacy']);
                        $duration = isset($item['durationMonths']) && intval($item['durationMonths']) > 0 ? intval($item['durationMonths']) : 1;
                        
                        // Activate subscription directly in PHP
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
                
                logActivity($conn, $uId, 'payment', "Paiement de " . $amount . " DH (Approbation Admin)", 'success');

                // Build notification message from order items
                $itemsForNotif = $conn->query("SELECT oi.domainName, s.nameService FROM order_items oi JOIN service s ON oi.serviceId = s.idService WHERE oi.orderId=$id");
                $labels = [];
                while($ni = $itemsForNotif->fetch_assoc()) {
                    $labels[] = $ni['domainName'] ? $ni['domainName'] . ' (Domaine)' : $ni['nameService'];
                }
                $itemList   = implode(', ', $labels);
                $notifMsg   = "Votre commande #$id a été approuvée par l'administrateur : $itemList — Montant: " . number_format($amount, 2) . " DH";
                $notifStmt  = $conn->prepare("INSERT INTO notification (userId, message, isRead) VALUES (?, ?, 0)");
                $notifStmt->bind_param("is", $uId, $notifMsg);
                $notifStmt->execute();
                $notifStmt->close();
            }

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Order approved and items activated successfully"]);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Approval failed: " . $e->getMessage()]);
        }
    } else {
        $stmt = $conn->prepare("UPDATE orders SET statusOrder=? WHERE idOrder=?");
        $stmt->bind_param("si", $status, $id);
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Order updated"]);
        }
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}
?>
