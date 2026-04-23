<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";

servitech_enforce_same_origin(false);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /auth/regis.html");
    exit();
}

$fullname = trim($_POST["fullname"] ?? "");
$contact  = trim($_POST["contact"] ?? $_POST["contacts"] ?? "");
$email    = strtolower(trim($_POST["email"] ?? ""));
$password_raw = (string)($_POST["password"] ?? "");
$confirm_password = (string)($_POST["confirm_password"] ?? "");
$privacy_consent = (string)($_POST["privacy_consent"] ?? "");

if ($fullname === "" || $contact === "" || $email === "" || $password_raw === "" || $confirm_password === "") {
    header("Location: /auth/regis.html?error=required");
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: /auth/regis.html?error=invalid_email");
    exit();
}

if ($password_raw !== $confirm_password) {
    header("Location: /auth/regis.html?error=mismatch");
    exit();
}

if ($privacy_consent !== "1") {
    header("Location: /auth/regis.html?error=privacy");
    exit();
}

$password_hash = password_hash($password_raw, PASSWORD_DEFAULT);

try {
    // prevent duplicate email
    $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $check->execute([":email" => $email]);
    if ($check->fetch()) {
        header("Location: /auth/log_in.html?registered=exists");
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
        // Compatibility fallback for schemas using `contacts`.
        $ins = $pdo->prepare("
            INSERT INTO users (fullname, email, contacts, password_hash)
            VALUES (:fullname, :email, :contact, :password_hash)
        ");
        $ins->execute($params);
    }

    header("Location: /auth/log_in.html?registered=1");
    exit();

} catch (PDOException $e) {
    error_log("register error: " . $e->getMessage());
    header("Location: /auth/regis.html?error=error");
    exit();
}
