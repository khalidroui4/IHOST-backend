<?php
require 'config/db.php';
$conn->query("UPDATE users SET roleU = 'admin' WHERE email = 'admin@gmail.com'");
echo "Account admin@gmail.com is now an Admin.\n";
?>
