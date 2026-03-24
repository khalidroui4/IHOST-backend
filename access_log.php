<?php
$file = 'C:\\xampp\\apache\\logs\\access.log';
if (file_exists($file)) {
    $content = file_get_contents($file);
    echo substr($content, -3000);
} else {
    echo "No log file found.";
}
