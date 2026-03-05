<?php
require_once __DIR__ . "/session_check.php";
require_once __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /ServiTech/main/log_in.html");
    exit();
}

$email = trim($_POST["email"] ?? "");
$password = (string)($_POST["password"] ?? "");

try {
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([":email" => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user["password_hash"])) {
        $_SESSION["user_id"] = (int)$user["id"];
        header("Location: /ServiTech/main/customer_dash.php");
        exit();
    }

    header("Location: /ServiTech/main/log_in.html?login=fail");
    exit();

} catch (PDOException $e) {
    error_log("login error: " . $e->getMessage());
    header("Location: /ServiTech/main/log_in.html?login=fail");
    exit();
}