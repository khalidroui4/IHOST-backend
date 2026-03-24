<?php
require __DIR__ . '/config/db.php';

// Check if cart table exists
$res1 = $conn->query("SHOW TABLES LIKE 'cart'");
echo ($res1 && $res1->num_rows > 0) ? "Cart table exists.\n" : "Cart table DOES NOT exist.\n";

// Fetch services
$res2 = $conn->query("SELECT idService, nameService FROM service");
$services = [];
if ($res2) {
    while($row = $res2->fetch_assoc()) $services[] = $row;
}
echo "Services: " . json_encode($services) . "\n";
