<?php
require __DIR__ . '/config/db.php';
$res = $conn->query("SELECT * FROM service");
$data = [];
while($row = $res->fetch_assoc()) $data[] = $row;
print_r($data);
    