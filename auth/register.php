<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";

servitech_enforce_same_origin(false);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . servitech_url("/auth/regis.php"));
    exit();
}

$fullname = trim($_POST["fullname"] ?? "");
$contact = trim($_POST["contact"] ?? $_POST["contacts"] ?? "");
$email = strtolower(trim($_POST["email"] ?? ""));
$password_raw = (string)($_POST["password"] ?? "");
$confirm_password = (string)($_POST["confirm_password"] ?? "");
$privacy_consent = (string)($_POST["privacy_consent"] ?? "");

if ($fullname === "" || $contact === "" || $email === "" || $password_raw === "" || $confirm_password === "") {
    header("Location: " . servitech_url("/auth/regis.php?error=required"));
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: " . servitech_url("/auth/regis.php?error=invalid_email"));
    exit();
}

if ($password_raw !== $confirm_password) {
    header("Location: " . servitech_url("/auth/regis.php?error=mismatch"));
    exit();
}

if ($privacy_consent !== "1") {
    header("Location: " . servitech_url("/auth/regis.php?error=privacy"));
    exit();
}

$password_hash = password_hash($password_raw, PASSWORD_DEFAULT);

try {
    $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $check->execute([":email" => $email]);
    if ($check->fetch()) {
        header("Location: " . servitech_url("/auth/log_in.php?registered=exists"));
        exit();
    }

    $params = [
        ":fullname" => $fullname,
        ":email" => $email,
        ":contact" => ($contact === "" ? null : $contact),
        ":password_hash" => $password_hash,
    ];

    try {
        $ins = $pdo->prepare("
            INSERT INTO users (fullname, email, contact, password_hash)
            VALUES (:fullname, :email, :contact, :password_hash)
        ");
        $ins->execute($params);
    } catch (PDOException $e) {
        $ins = $pdo->prepare("
            INSERT INTO users (fullname, email, contacts, password_hash)
            VALUES (:fullname, :email, :contact, :password_hash)
        ");
        $ins->execute($params);
    }

    header("Location: " . servitech_url("/auth/log_in.php?registered=1"));
    exit();
} catch (PDOException $e) {
    error_log("register error: " . $e->getMessage());
    header("Location: " . servitech_url("/auth/regis.php?error=error"));
    exit();
}
