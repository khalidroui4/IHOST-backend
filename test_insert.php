<?php
$conn = new mysqli("127.0.0.1", "root", "", "ihost");
$userId = 1; // Assuming user 1 exists
$serviceId = 3; // Business
$duration = 1;
$domainName = null;

$stmt = $conn->prepare("INSERT INTO cart (userId, serviceId, durationMonths, domainName) VALUES (?, ?, ?, ?)");
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("iiis", $userId, $serviceId, $duration, $domainName);
if ($stmt->execute()) {
    echo "Inserted successfully";
} else {
    echo "Execute failed: " . $stmt->error;
}
?>
