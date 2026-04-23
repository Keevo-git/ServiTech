<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/google_auth.php";

servitech_enforce_same_origin(true);
servitech_enforce_csrf_token(true);

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "Method not allowed."]);
    exit();
}

$rawInput = file_get_contents("php://input");
$decodedInput = json_decode($rawInput ?: "{}", true);
$credential = trim((string)($decodedInput["credential"] ?? $_POST["credential"] ?? ""));

if ($credential === "") {
    http_response_code(422);
    echo json_encode(["ok" => false, "error" => "Google credential is required."]);
    exit();
}

$verification = servitech_google_verify_id_token($credential);
if (empty($verification["ok"])) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => (string)($verification["error"] ?? "Google authentication failed.")]);
    exit();
}

$payload = (array)($verification["payload"] ?? []);
$googleId = trim((string)($payload["sub"] ?? ""));
$email = strtolower(trim((string)($payload["email"] ?? "")));
$fullName = trim((string)($payload["name"] ?? ""));

if ($googleId === "" || $email === "") {
    http_response_code(422);
    echo json_encode(["ok" => false, "error" => "Google account details are incomplete."]);
    exit();
}

if ($fullName === "") {
    $fullName = trim((string)($payload["given_name"] ?? "Google User"));
}

try {
    $findUser = $pdo->prepare("
        SELECT id, email,
               COALESCE(NULLIF(to_jsonb(users)->>'fullname', ''), :full_name) AS fullname,
               COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role,
               COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') AS google_id
        FROM users
        WHERE COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') = :google_id
           OR LOWER(email) = LOWER(:email)
        ORDER BY CASE
            WHEN COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') = :google_id THEN 0
            ELSE 1
        END
        LIMIT 1
    ");
    $findUser->execute([
        ":full_name" => $fullName,
        ":google_id" => $googleId,
        ":email" => $email,
    ]);
    $user = $findUser->fetch();

    if ($user) {
        $existingGoogleId = trim((string)($user["google_id"] ?? ""));
        if ($existingGoogleId !== "" && $existingGoogleId !== $googleId) {
            http_response_code(409);
            echo json_encode(["ok" => false, "error" => "This email is already linked to a different Google account."]);
            exit();
        }

        $updateUser = $pdo->prepare("
            UPDATE users
            SET fullname = :fullname,
                email = :email,
                google_id = :google_id
            WHERE id = :id
        ");
        $updateUser->execute([
            ":fullname" => $fullName,
            ":email" => $email,
            ":google_id" => $googleId,
            ":id" => (int)$user["id"],
        ]);

        $userId = (int)$user["id"];
        $role = strtolower((string)($user["role"] ?? "customer"));
    } else {
        try {
            $insertUser = $pdo->prepare("
                INSERT INTO users (fullname, email, contact, password_hash, google_id)
                VALUES (:fullname, :email, NULL, NULL, :google_id)
                RETURNING id, COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role
            ");
            $insertUser->execute([
                ":fullname" => $fullName,
                ":email" => $email,
                ":google_id" => $googleId,
            ]);
        } catch (PDOException $e) {
            $insertUser = $pdo->prepare("
                INSERT INTO users (fullname, email, contacts, password_hash, google_id)
                VALUES (:fullname, :email, NULL, NULL, :google_id)
                RETURNING id, COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role
            ");
            $insertUser->execute([
                ":fullname" => $fullName,
                ":email" => $email,
                ":google_id" => $googleId,
            ]);
        }

        $inserted = $insertUser->fetch();
        $userId = (int)($inserted["id"] ?? 0);
        $role = strtolower((string)($inserted["role"] ?? "customer"));
    }

    if ($userId <= 0) {
        throw new RuntimeException("Could not resolve authenticated user.");
    }

    session_regenerate_id(true);
    $_SESSION["user_id"] = $userId;
    $_SESSION["role"] = ($role === "admin") ? "admin" : "customer";

    $redirect = ($_SESSION["role"] === "admin")
        ? "/pages/admin/admin_dashboard.php"
        : "/pages/customer/customer_dash.php";

    if ($_SESSION["role"] === "admin") {
        $_SESSION["admin_logged_in"] = true;
        $_SESSION["admin_email"] = $email;
    } else {
        unset($_SESSION["admin_logged_in"], $_SESSION["admin_email"]);
    }

    echo json_encode([
        "ok" => true,
        "redirect" => $redirect,
    ]);
    exit();

} catch (PDOException $e) {
    error_log("google login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Google sign-in could not be completed. Please confirm the users table includes a nullable google_id column.",
    ]);
    exit();
} catch (Throwable $e) {
    error_log("google login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Google sign-in could not be completed right now. Please try again.",
    ]);
    exit();
}
