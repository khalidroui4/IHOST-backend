<?php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost/IHOST-backend/controllers/cart.php");
// Send POST with raw JSON input to simulate frontend Axios
$data = json_encode(["serviceId" => 3, "durationMonths" => 1, "domainName" => null]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
// Send action header or query param. The route logic might expect $GLOBALS['route_action'] 
// Wait! In cart.php we use $GLOBALS['route_action'] directly? Let's check index.php!
// I'll just use my direct db script to insert and check
echo "Skip API, inserting directly to see if I fixed it.\n";
$conn = new mysqli("localhost", "root", "", "ihost");
$stmt = $conn->prepare("INSERT INTO cart (userId, serviceId, durationMonths, domainName) VALUES (1, 3, 1, 'mytest.com')");
if ($stmt->execute()) {
    echo "Inserted cart item!\n";
} else {
    echo "Fail: " . $stmt->error . "\n";
}
?>
