<?php
session_start();

$admin_email = "admin@servitech.com";
$admin_password = "admin123";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = $_POST["email"];
    $password = $_POST["password"];

    if ($email === $admin_email && $password === $admin_password) {
        $_SESSION["admin_logged_in"] = true;
        $_SESSION["admin_email"] = $email;

        header("Location: admin_dashboard.php");
        exit();
    } else {
        echo "<script>alert('Invalid login credentials'); window.location.href='main.php';</script>";
    }
}
?>
