<?php
// /main/login.php

// ✅ MUST match includes/auth.php (same session name + cookie params)
session_name("SERVITECHSESSID");
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/ServiTech/main/",
    "domain"   => "",
    "secure"   => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

ini_set("session.use_strict_mode", "1");
ini_set("session.use_only_cookies", "1");
ini_set("session.use_cookies", "1");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$conn = new mysqli("localhost", "root", "", "servitech_db");
if ($conn->connect_error) {
    die("Database connection failed");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /ServiTech/main/log_in.html");
    exit();
}

$email = $_POST["email"] ?? "";
$password = $_POST["password"] ?? "";

$sql = "SELECT id, password FROM users WHERE email = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows === 1) {
    $user = $result->fetch_assoc();

    if (password_verify($password, $user["password"])) {
        $_SESSION["user_id"] = (int)$user["id"];

        // ✅ redirect to dashboard
        header("Location: /ServiTech/main/customer_dash.php");
        exit();
    }
}

header("Location: /ServiTech/main/log_in.html?login=fail");
exit();
