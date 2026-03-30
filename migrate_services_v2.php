<?php
require 'config/db.php';
$query = "ALTER TABLE service ADD COLUMN typeService VARCHAR(50) DEFAULT 'hosting' AFTER isActive";
if ($conn->query($query)) {
    echo "Column typeService added successfully.\n";
    
    // Seed some initial types based on names
    $conn->query("UPDATE service SET typeService = 'hosting' WHERE nameService IN ('Starter', 'Pro', 'Business')");
    $conn->query("UPDATE service SET typeService = 'cloud' WHERE nameService LIKE '%Cloud%'");
    $conn->query("UPDATE service SET typeService = 'email' WHERE nameService LIKE '%Mail%' OR nameService LIKE '%Exchange%'");
    $conn->query("UPDATE service SET typeService = 'ssl' WHERE nameService LIKE '%SSL%'");
    $conn->query("UPDATE service SET typeService = 'domain' WHERE nameService LIKE '%.MA%' OR nameService LIKE '%.COM%' OR nameService LIKE '%.ONLINE%'");
} else {
    echo "Error: " . $conn->error . "\n";
}
?>
