<?php
$conn = new mysqli("127.0.0.1", "root", "", "ihost");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Add domainName to cart
$conn->query("ALTER TABLE cart ADD COLUMN domainName VARCHAR(255) NULL");
if ($conn->error) {
    echo "Cart alter error: " . $conn->error . "\n";
} else {
    echo "Cart updated successfully.\n";
}

// Add domainName to order_items
$conn->query("ALTER TABLE order_items ADD COLUMN domainName VARCHAR(255) NULL");
if ($conn->error) {
    echo "Order items alter error: " . $conn->error . "\n";
} else {
    echo "Order items updated successfully.\n";
}

$conn->close();
?>
