<?php
require_once __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: regis.html");
    exit();
}

$fullname = trim($_POST["fullname"] ?? "");
$contact  = trim($_POST["contacts"] ?? ""); // your form uses "contacts"
$email    = trim($_POST["email"] ?? "");
$password_raw = (string)($_POST["password"] ?? "");

if ($fullname === "" || $email === "" || $password_raw === "") {
    header("Location: regis.html?error=1");
    exit();
}

$password_hash = password_hash($password_raw, PASSWORD_DEFAULT);

try {
    // prevent duplicate email
    $check = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $check->execute([":email" => $email]);
    if ($check->fetch()) {
        header("Location: log_in.html?registered=exists");
        exit();
    }

    $ins = $pdo->prepare("
        INSERT INTO users (fullname, email, contact, password_hash)
        VALUES (:fullname, :email, :contact, :password_hash)
    ");

    $ins->execute([
        ":fullname" => $fullname,
        ":email" => $email,
        ":contact" => ($contact === "" ? null : $contact),
        ":password_hash" => $password_hash,
    ]);

    header("Location: log_in.html?registered=1");
    exit();

} catch (PDOException $e) {
    error_log("register error: " . $e->getMessage());
    header("Location: regis.html?error=1");
    exit();
}