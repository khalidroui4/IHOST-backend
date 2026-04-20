<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../middleware/authMiddleware.php';

$userAuth = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($parts[1]) ? $parts[1] : '';

if ($method === 'GET') {
    // Only admins can get all users, or user can get themselves
    if ($action === '') {
        requireAdmin();
        $result = $conn->query("SELECT idU, nameU, email, roleU, emailVerified, createdAt FROM users");
        $users = [];
        while($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $users]);
    } else {
        $id = intval($action);
        if ($userAuth['roleU'] !== 'admin' && $userAuth['idU'] !== $id) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Forbidden"]);
            exit;
        }
        $stmt = $conn->prepare("SELECT idU, nameU, email, roleU, emailVerified, createdAt FROM users WHERE idU = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            echo json_encode(["status" => "success", "data" => $result->fetch_assoc()]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "User not found"]);
        }
    }
} elseif ($method === 'POST') {
    if ($action === 'update') {
        $data = json_decode(file_get_contents("php://input"));
        $id = $userAuth['idU'];
        
        $fname = $data->first_name ?? null;
        $lname = $data->last_name ?? null;
        $uname = $data->username ?? null;
        $loc = $data->location ?? null;
        $web = $data->website ?? null;
        $bio = $data->bio ?? null;
        $ints = $data->interests ?? null;
        $insta = $data->instagram ?? null;
        $twit = $data->twitter ?? null;

        $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, username=?, location=?, website=?, bio=?, interests=?, instagram=?, twitter=? WHERE idU=?");
        $stmt->bind_param("sssssssssi", $fname, $lname, $uname, $loc, $web, $bio, $ints, $insta, $twit, $id);
        
        if ($stmt->execute()) {
            $res = $conn->query("SELECT idU, nameU, email, roleU, emailVerified, createdAt, first_name, last_name, username, location, website, bio, interests, instagram, twitter, avatar FROM users WHERE idU=$id");
            $user = $res->fetch_assoc();
            $user['id'] = $user['idU'];
            $user['name'] = $user['nameU'];
            $user['role'] = $user['roleU'];
            
            logActivity($conn, $id, 'system', "Mise à jour du profil (" . trim($fname . ' ' . $lname) . ")", 'info');
            
            // Notification
            $notifMsg = "Votre profil a été mis à jour avec succès.";
            $notifStmt = $conn->prepare("INSERT INTO notification (userId, message, isRead) VALUES (?, ?, 0)");
            $notifStmt->bind_param("is", $id, $notifMsg);
            $notifStmt->execute();

            echo json_encode(["status" => "success", "user" => $user, "message" => "Profile updated successfully"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Database update failed: " . $conn->error]);
        }
    } elseif ($action === 'avatar') {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['avatar']['tmp_name'];
            $name = time() . '_' . preg_replace("/[^a-zA-Z0-9.\-_]/", "", basename($_FILES['avatar']['name']));
            $target = __DIR__ . '/../uploads/' . $name;
            
            if (move_uploaded_file($tmp, $target)) {
                $avatarUrl = '/IHOST-backend/uploads/' . $name;
                $id = $userAuth['idU'];
                $conn->query("UPDATE users SET avatar='$avatarUrl' WHERE idU=$id");
                
                $res = $conn->query("SELECT idU, nameU, email, roleU, emailVerified, createdAt, first_name, last_name, username, location, website, bio, interests, instagram, twitter, avatar FROM users WHERE idU=$id");
                $user = $res->fetch_assoc();
                $user['id'] = $user['idU'];
                $user['name'] = $user['nameU'];
                $user['role'] = $user['roleU'];
                
                logActivity($conn, $id, 'system', "Mise à jour de l'avatar", 'info');

                // Notification
                $notifMsg = "Votre photo de profil a été mise à jour.";
                $notifStmt = $conn->prepare("INSERT INTO notification (userId, message, isRead) VALUES (?, ?, 0)");
                $notifStmt->bind_param("is", $id, $notifMsg);
                $notifStmt->execute();

                echo json_encode(["status" => "success", "avatar" => $avatarUrl, "user" => $user, "message" => "Avatar uploaded successfully"]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Upload failed on the server."]);
            }
        } else {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "No file uploaded or file upload error."]);
        }
    } elseif ($action === 'password') {
        $data = json_decode(file_get_contents("php://input"));
        $old = $data->old_password ?? '';
        $new = $data->new_password ?? '';
        $id = $userAuth['idU'];
        
        $res = $conn->query("SELECT passwordU FROM users WHERE idU=$id");
        $user = $res->fetch_assoc();
        
        if (password_verify($old, $user['passwordU'])) {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $conn->query("UPDATE users SET passwordU='$hashed' WHERE idU=$id");
            
            logActivity($conn, $id, 'system', "Mot de passe modifié", 'warning');

            // Notification
            $notifMsg = "Votre mot de passe a été modifié avec succès.";
            $notifStmt = $conn->prepare("INSERT INTO notification (userId, message, isRead) VALUES (?, ?, 0)");
            $notifStmt->bind_param("is", $id, $notifMsg);
            $notifStmt->execute();

            echo json_encode(["status" => "success", "message" => "Password updated successfully"]);
        } else {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Le mot de passe actuel est incorrect"]);
        }
    } elseif ($action === 'email') {
        $data = json_decode(file_get_contents("php://input"));
        if(!isset($data->email) || empty(trim($data->email))) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "L'adresse email est requise"]);
            exit;
        }
        $email = $conn->real_escape_string(trim($data->email));
        $id = $userAuth['idU'];
        
        $chk = $conn->query("SELECT idU FROM users WHERE email='$email' AND idU != $id");
        if ($chk && $chk->num_rows > 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Cet email est déjà utilisé par un autre compte"]);
        } else {
            $conn->query("UPDATE users SET email='$email' WHERE idU=$id");
            $res = $conn->query("SELECT idU, nameU, email, roleU, emailVerified, createdAt, first_name, last_name, username, location, website, bio, interests, instagram, twitter, avatar FROM users WHERE idU=$id");
            $user = $res->fetch_assoc();
            $user['id'] = $user['idU'];
            $user['name'] = $user['nameU'];
            $user['role'] = $user['roleU'];
            
            logActivity($conn, $id, 'system', "Adresse email modifiée", 'info');

            // Notification
            $notifMsg = "Votre adresse email a été mise à jour : $email";
            $notifStmt = $conn->prepare("INSERT INTO notification (userId, message, isRead) VALUES (?, ?, 0)");
            $notifStmt->bind_param("is", $id, $notifMsg);
            $notifStmt->execute();

            echo json_encode(["status" => "success", "message" => "Email updated successfully", "user" => $user]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid POST action"]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}
?>
