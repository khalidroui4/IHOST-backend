<?php
$conn = new mysqli("127.0.0.1", "root", "", "ihost");
$res = $conn->query("SHOW COLUMNS FROM cart");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
