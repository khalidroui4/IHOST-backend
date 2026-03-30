<?php
require 'config/db.php';
$pass = password_hash('admin123', PASSWORD_DEFAULT);
$conn->query("UPDATE users SET passwordU = '$pass' WHERE email = 'admin@gmail.com'");
echo "Password for admin@gmail.com reset to admin123.\n";
?>
