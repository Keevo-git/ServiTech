<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";

servitech_enforce_same_origin(false);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /auth/log_in.html");
    exit();
}

$email = trim($_POST["email"] ?? "");
$password = (string)($_POST["password"] ?? "");

try {
    $stmt = $pdo->prepare("
        SELECT id, email, role, password_hash,
               COALESCE(NULLIF(password_hash, ''), NULLIF(to_jsonb(users)->>'password', '')) AS auth_hash
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    $stmt->execute([":email" => $email]);
    $user = $stmt->fetch();

    $storedHash = (string)($user["auth_hash"] ?? "");
    $is_valid = false;
    if ($user && $storedHash !== "") {
        $is_valid = password_verify($password, $storedHash);
    }

    if ($user && $is_valid) {
        session_regenerate_id(true);
        $_SESSION["user_id"] = (int)$user["id"];
        $_SESSION["role"] = strtolower((string)($user["role"] ?? "customer"));

        if ($_SESSION["role"] === "admin") {
            $_SESSION["admin_logged_in"] = true;
            $_SESSION["admin_email"] = (string)($user["email"] ?? $email);
            header("Location: /pages/admin/admin_dashboard.php");
            exit();
        }

        unset($_SESSION["admin_logged_in"], $_SESSION["admin_email"]);
        header("Location: /pages/customer/customer_dash.php");
        exit();
    }

    header("Location: /auth/log_in.html?login=fail");
    exit();

} catch (PDOException $e) {
    error_log("login error: " . $e->getMessage());
    header("Location: /auth/log_in.html?login=fail");
    exit();
}

