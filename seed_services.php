<?php
$conn = new mysqli("127.0.0.1", "root", "", "ihost");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$services = [
    ['Starter', '1 Site, 10 GB SSD, SSL Gratuit', 29.00, 1],
    ['Pro', '10 Sites, 50 GB SSD, Backup Auto', 59.00, 1],
    ['Business', 'Sites illimités, 100 GB SSD, Vitesse Max', 99.00, 1],
    ['Cloud Basic', '2 vCPU, 4 GB RAM, 80 GB SSD', 149.00, 1],
    ['Cloud Pro', '4 vCPU, 8 GB RAM, 160 GB SSD', 249.00, 1],
    ['Cloud Ent.', '8 vCPU, 16 GB RAM, 320 GB SSD', 449.00, 1],
    ['Domaine .MA', 'Identité Marocaine, DNS Manager', 150.00, 12],
    ['Domaine .COM', 'Standard Mondial, Full Control', 120.00, 12],
    ['Domaine .ONLINE', 'Promo Limitée, Instant Active', 40.00, 12],
    ['Positive SSL', 'Validation de Domaine, Cadenas Vert', 89.00, 12],
    ['Wildcard SSL', 'Sous-domaines illimités, Sécurité Max', 890.00, 12],
    ['EV SSL', 'Barre verte, Validation Entreprise', 1490.00, 12],
    ['Mail Basic', '5 Go Stockage, Antispam', 19.00, 1],
    ['Mail Pro', '50 Go Stockage, Calendrier partagé', 49.00, 1],
    ['Exchange', '100 Go Stockage, Synchro ActiveSync', 99.00, 1]
];

$stmt = $conn->prepare("INSERT INTO service (nameService, descriptionS, price, durationMonths, isActive) VALUES (?, ?, ?, ?, 1)");

$count = 0;
foreach ($services as $s) {
    // Check if exists
    $check = $conn->prepare("SELECT idService FROM service WHERE nameService = ?");
    $check->bind_param("s", $s[0]);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $stmt->bind_param("ssdi", $s[0], $s[1], $s[2], $s[3]);
        if ($stmt->execute()) {
            $count++;
            echo "Inserted: " . $s[0] . "\n";
        } else {
            echo "Error inserting " . $s[0] . ": " . $stmt->error . "\n";
        }
    } else {
        echo "Already exists: " . $s[0] . "\n";
    }
}

echo "Total inserted: $count\n";
$conn->close();
?>
