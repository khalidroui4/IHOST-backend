<?php
$file = 'C:\\xampp\\apache\\logs\\access.log';
if (file_exists($file)) {
    $lines = file($file);
    $matches = [];
    foreach($lines as $line) {
        if(strpos($line, 'cart/add') !== false) {
            $matches[] = $line;
        }
    }
    echo implode("", array_slice($matches, -20));
} else {
    echo "No log file found.";
}
