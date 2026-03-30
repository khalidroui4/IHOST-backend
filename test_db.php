<?php
$conn = new mysqli("127.0.0.1", "root", "", "ihost");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$result = $conn->query("SELECT idService, nameService FROM service");
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo "ID: " . $row["idService"]. " - Name: " . $row["nameService"]. "\n";
    }
} else {
    echo "0 results";
}
$conn->close();
?>
