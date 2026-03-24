<?php
require __DIR__ . '/config/db.php';
$res = $conn->query("SHOW COLUMNS FROM users");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
