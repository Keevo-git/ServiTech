<?php
// Admin/admin.php
session_name("SERVITECH_ADMIN");
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/ServiTech/",
    "domain"   => "",
    "secure"   => false,
    "httponly" => true,
    "samesite" => "Lax"
]);
if (session_status() === PHP_SESSION_NONE) session_start();

$admin_email = "admin@servitech.com";
$admin_password = "admin123";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /ServiTech/Admin/main.php");
    exit();
}

$email = $_POST["email"] ?? "";
$password = $_POST["password"] ?? "";

if ($email === $admin_email && $password === $admin_password) {
    $_SESSION["admin_logged_in"] = true;
    $_SESSION["admin_email"] = $email;

    header("Location: /ServiTech/Admin/admin_dashboard.php");
    exit();
}

echo "<script>alert('Invalid login credentials'); window.location.href='/ServiTech/Admin/main.php';</script>";
exit();
