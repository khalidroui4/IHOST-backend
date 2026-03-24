<?php
require __DIR__ . '/config/db.php';

$queries = [
    "ALTER TABLE users ADD COLUMN first_name VARCHAR(50) NULL AFTER nameU;",
    "ALTER TABLE users ADD COLUMN last_name VARCHAR(50) NULL AFTER first_name;",
    "ALTER TABLE users ADD COLUMN username VARCHAR(50) NULL UNIQUE AFTER last_name;",
    "ALTER TABLE users ADD COLUMN location VARCHAR(100) NULL AFTER emailVerified;",
    "ALTER TABLE users ADD COLUMN website VARCHAR(255) NULL AFTER location;",
    "ALTER TABLE users ADD COLUMN bio TEXT NULL AFTER website;",
    "ALTER TABLE users ADD COLUMN interests VARCHAR(255) NULL AFTER bio;",
    "ALTER TABLE users ADD COLUMN instagram VARCHAR(50) NULL AFTER interests;",
    "ALTER TABLE users ADD COLUMN twitter VARCHAR(50) NULL AFTER instagram;",
    "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER twitter;"
];

foreach ($queries as $q) {
    if ($conn->query($q) === TRUE) {
        echo "Executed: $q\n";
    } else {
        echo "Error or already exists for: $q (" . $conn->error . ")\n";
    }
}

echo "Database migration complete.\n";
