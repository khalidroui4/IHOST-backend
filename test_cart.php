<?php
$conn = new mysqli("127.0.0.1", "root", "", "ihost");
$res = $conn->query("SELECT * FROM cart");
if ($res->num_rows > 0) {
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Cart is empty.";
}
?>
