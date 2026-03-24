<?php
function authenticate() {
    $headers = apache_request_headers();
    if(isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
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
