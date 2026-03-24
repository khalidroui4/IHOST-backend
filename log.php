<?php
$file = 'C:\\xampp\\apache\\logs\\error.log';
if (file_exists($file)) {
    $content = file_get_contents($file);
    echo substr($content, -2000);
} else {
    echo "No log file found.";
}
