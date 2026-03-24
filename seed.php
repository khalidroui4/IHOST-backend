<?php
require __DIR__ . '/config/db.php';

$services = [
    ['CLOUD STARTER', 'Architecture mutualisée pour projets émergents.', 39.00],
    ['VPS PRO-X', 'Serveur privé virtuel haute performance.', 129.00],
    ['BUSINESS CORE', 'Solutions web complexes et E-commerce.', 99.00],
    ['Starter', '1 Site, 10 GB SSD, SSL Gratuit', 29.00],
    ['Pro', '10 Sites, 50 GB SSD, Backup Auto', 59.00],
    ['Business', 'Sites illimités, 100 GB SSD, Vitesse Max', 99.00],
    ['Cloud Basic', '2 vCPU, 4 GB RAM, 80 GB SSD', 149.00],
    ['Cloud Pro', '4 vCPU, 8 GB RAM, 160 GB SSD', 249.00],
    ['Cloud Ent.', '8 vCPU, 16 GB RAM, 320 GB SSD', 449.00],
    ['.MA', 'Identité Marocaine, DNS Manager, Privacy Incluse', 150.00],
    ['.COM', 'Standard Mondial, Full Control, 24/7 Support', 120.00],
    ['.ONLINE', 'Promo Limitée, Nouveau & Moderne, Instant Active', 40.00],
    ['Certificat DV', 'Validation de domaine rapide', 190.00],
    ['Wildcard SSL', 'Sécurisez tous vos sous-domaines', 890.00],
    ['EV SSL (Entreprise)', 'Validation étendue pour e-commerce', 1490.00],
    ['Email Starter', '5 Go Stockage, Protection Anti-spam, Webmail intuitif', 20.00],
    ['Webmail Pro', '25 Go Stockage, Calendrier partagé, Sync Mobile ActiveSync', 50.00],
    ['Exchange Enterprise', '50 Go Stockage, Microsoft Exchange, Teams & Office Web', 120.00]
];

$stmt = $conn->prepare("INSERT INTO service (nameService, descriptionS, price, durationMonths, isActive) VALUES (?, ?, ?, 1, 1)");

foreach($services as $s) {
    // only insert if it doesn't already exist
    $chk = $conn->prepare("SELECT idService FROM service WHERE nameService = ?");
    $chk->bind_param("s", $s[0]);
    $chk->execute();
    if($chk->get_result()->num_rows == 0) {
        $stmt->bind_param("ssd", $s[0], $s[1], $s[2]);
        $stmt->execute();
        echo "Inserted: " . $s[0] . "\n";
    }
}

echo "Seeding completed.";
