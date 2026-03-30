<?php
header('Content-Type: text/plain');
$conn = new mysqli("127.0.0.1", "root", "", "ihost");
$res = $conn->query("DESCRIBE cart");
while($row = $res->fetch_assoc()) {
    echo "'" . $row['Field'] . "'\n";
}
?>
