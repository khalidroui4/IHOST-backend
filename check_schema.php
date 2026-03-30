<?php
require 'config/db.php';
$result = $conn->query("SHOW COLUMNS FROM service");
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
?>
