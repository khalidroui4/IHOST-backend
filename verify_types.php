<?php
require 'config/db.php';
$result = $conn->query("SELECT idService, nameService, typeService FROM service LIMIT 10");
while($row = $result->fetch_assoc()) {
    echo "ID: " . $row['idService'] . " | Name: " . $row['nameService'] . " | Type: " . $row['typeService'] . "\n";
}
?>
