<?php
require 'config/db.php';
$result = $conn->query("SELECT email, roleU FROM users WHERE roleU = 'admin'");
while($row = $result->fetch_assoc()) {
    echo "Email: " . $row['email'] . " | Role: " . $row['roleU'] . "\n";
}
?>
