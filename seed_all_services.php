<?php
$conn = new mysqli("127.0.0.1", "root", "", "ihost");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$services = [
    // Mutualise / Pricing
    ['Starter', '1 Site, 10 GB SSD, SSL Gratuit', 29.00, 1],
    ['Pro', '10 Sites, 50 GB SSD, Backup Auto', 59.00, 1],
    ['Business', 'Sites illimités, 100 GB SSD, Vitesse Max', 99.00, 1],
    
    // Cloud
    ['Cloud Basic', '2 vCPU, 4 GB RAM, 80 GB SSD', 149.00, 1],
    ['Cloud Pro', '4 vCPU, 8 GB RAM, 160 GB SSD', 249.00, 1],
    ['Cloud Ent.', '8 vCPU, 16 GB RAM, 320 GB SSD', 449.00, 1],
    ['Cloud Enterprise', '8 vCPU, 16 GB RAM, 320 GB SSD', 449.00, 1], // variant

    // Ecommerce
    ['Shop Starter', '1 boutique, 50 GB stockage SSD', 79.00, 1],
    ['Shop Pro', '5 boutiques, 100 GB stockage SSD', 149.00, 1],
    ['Shop Business', 'Boutiques illimitées, 200 GB stockage SSD', 249.00, 1],

    // Multisites
    ['Multi Starter', 'Jusqu\'à 5 sites web, 50 GB SSD', 69.00, 1],
    ['Multi Pro', 'Jusqu\'à 20 sites web, 100 GB SSD', 129.00, 1],
    ['Multi Unlimited', 'Sites web illimités, 200 GB SSD', 199.00, 1],

    // Domains
    ['.MA', 'Identité Marocaine, DNS Manager', 150.00, 12],
    ['.COM', 'Standard Mondial, Full Control', 120.00, 12],
    ['.ONLINE', 'Promo Limitée, Instant Active', 40.00, 12],
    ['Domaine .MA', 'Identité Marocaine, DNS Manager', 150.00, 12],
    ['Domaine .COM', 'Standard Mondial, Full Control', 120.00, 12],
    ['Domaine .ONLINE', 'Promo Limitée, Instant Active', 40.00, 12],

    // SSL
    ['Positive SSL', 'Validation de Domaine', 89.00, 12],
    ['Wildcard SSL', 'Sous-domaines illimités', 890.00, 12],
    ['EV SSL', 'Validation Entreprise', 1490.00, 12],
    ['Standard SSL (DV)', 'Validation automatique', 399.00, 12],
    ['Business SSL (OV)', 'Validation identité', 899.00, 12],
    ['Enterprise SSL (EV)', 'Barre verte', 1499.00, 12],

    // Email
    ['Mail Basic', '5 Go Stockage', 19.00, 1],
    ['Mail Pro', '50 Go Stockage', 49.00, 1],
    ['Exchange', '100 Go Stockage', 99.00, 1],
    ['STARTER PRO', '10 Boîtes Email 10Go', 29.00, 1],
    ['BUSINESS ELITE', '50 Boîtes Email 25Go', 79.00, 1],
    ['ENTERPRISE CLOUD', 'Boîtes illimitées 50Go', 149.00, 1]
];

$stmt = $conn->prepare("INSERT INTO service (nameService, descriptionS, price, durationMonths, isActive) VALUES (?, ?, ?, ?, 1)");

$count = 0;
foreach ($services as $s) {
    $check = $conn->prepare("SELECT idService FROM service WHERE LOWER(nameService) = LOWER(?)");
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
