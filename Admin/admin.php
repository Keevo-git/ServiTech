<?php
// Admin/admin.php (process login)
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

require_once __DIR__ . "/_includes/admin_db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /ServiTech/Admin/main.php");
    exit();
}

$email = trim((string)($_POST["email"] ?? ""));
$password = (string)($_POST["password"] ?? "");

if ($email === "" || $password === "") {
    header("Location: /ServiTech/Admin/main.php");
    exit();
}

// 1) Try DB admin account
$stmt = $pdo->prepare("SELECT id, email, password FROM users WHERE role='admin' AND email = ? LIMIT 1");
$stmt->execute([$email]);
$admin = $stmt->fetch();

$ok = false;

if ($admin && !empty($admin["password"]) && $admin["password"] !== '$2y$10$REPLACE_WITH_HASH') {
    $ok = password_verify($password, $admin["password"]);
}

// 2) Dev fallback (optional) - remove this block in production
if (!$ok) {
    $dev_email = "admin@servitech.com";
    $dev_password = "admin123";
    if ($email === $dev_email && $password === $dev_password) {
        $ok = true;
    }
}

if ($ok) {
    $_SESSION["admin_logged_in"] = true;
    $_SESSION["admin_email"] = $email;
    header("Location: /ServiTech/Admin/admin_dashboard.php");
    exit();
}

echo "<script>alert('Invalid login credentials'); window.location.href='/ServiTech/Admin/main.php';</script>";
exit();
