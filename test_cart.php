<?php
$data = json_encode(['serviceId' => 1, 'durationMonths' => 1]);
$token = base64_encode(json_encode(['idU' => 1, 'roleU' => 'client']));

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\nAuthorization: Bearer $token\r\n",
        'method'  => 'POST',
        'content' => $data,
        'ignore_errors' => true
    ]
];
$context  = stream_context_create($options);
$result = file_get_contents('http://localhost/IHOST-backend/cart/add', false, $context);
echo "Result: " . $result . "\nStatus: " . $http_response_header[0];
