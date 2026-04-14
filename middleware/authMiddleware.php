<?php
function authenticate() {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    
    if($headers) {
        $token = str_replace('Bearer ', '', $headers);
        $payload = json_decode(base64_decode($token), true);
        if ($payload && isset($payload['idU'])) {
            return $payload;
        }
    }
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

function requireAdmin() {
    $user = authenticate();
    if ($user['roleU'] !== 'admin') {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Forbidden - Admin access required"]);
        exit;
    }
    return $user;
}
?>
