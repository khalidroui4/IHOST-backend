<?php
require __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
// The routing is /IHOST-backend/auth/login or /IHOST-backend/auth/register
// Because index.php splits by '/', $parts[1] would be 'login' if url is /IHOST-backend/auth/login
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'POST') {
    if ($action === 'login') {
        $data = json_decode(file_get_contents("php://input"));
        if (!$data || !isset($data->email) || !isset($data->password)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing credentials"]);
            exit;
        }

        $email = $conn->real_escape_string($data->email);
        $password = $data->password;

        $stmt = $conn->prepare("SELECT idU, nameU, email, passwordU, roleU FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // Allow raw password comparison for testing if bcrypt isn't used yet, but default to bcrypt
            if (password_verify($password, $user['passwordU']) || $password === $user['passwordU']) {
                $tokenPayload = ["idU" => $user['idU'], "roleU" => $user['roleU'], "nameU" => $user['nameU']];
                $token = base64_encode(json_encode($tokenPayload));

                echo json_encode([
                    "status" => "success",
                    "token" => $token,
                    "user" => [
                        "id" => $user['idU'],
                        "name" => $user['nameU'],
                        "email" => $user['email'],
                        "role" => $user['roleU']
                    ]
                ]);
            } else {
                http_response_code(401);
                echo json_encode(["status" => "error", "message" => "Invalid password"]);
            }
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "User not found"]);
        }
    } elseif ($action === 'register') {
        $data = json_decode(file_get_contents("php://input"));
        if (!$data || !isset($data->first_name) || !isset($data->last_name) || !isset($data->email) || !isset($data->password)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Veuillez remplir tous les champs obligatoires"]);
            exit;
        }

        $name = $conn->real_escape_string($data->first_name . ' ' . $data->last_name);
        $email = $conn->real_escape_string($data->email);
        $password = password_hash($data->password, PASSWORD_BCRYPT);
        
        $stmt = $conn->prepare("INSERT INTO users (nameU, email, passwordU, roleU) VALUES (?, ?, ?, 'client')");
        $stmt->bind_param("sss", $name, $email, $password);
        
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "User registered successfully"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Registration failed: " . $conn->error]);
        }
    } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Auth action not recognized"]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}
?>
