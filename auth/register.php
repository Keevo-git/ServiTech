<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";

servitech_enforce_same_origin(false);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . servitech_url("/auth/regis.html"));
    exit();
}

$fullname = trim($_POST["fullname"] ?? "");
$contact  = trim($_POST["contact"] ?? $_POST["contacts"] ?? "");
$email    = trim($_POST["email"] ?? "");
$password_raw = (string)($_POST["password"] ?? "");

if ($fullname === "" || $email === "" || $password_raw === "") {
    header("Location: " . servitech_url("/auth/regis.html?error=1"));
    exit();
}

$password_hash = password_hash($password_raw, PASSWORD_DEFAULT);

try {
    // prevent duplicate email
    $check = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $check->execute([":email" => $email]);
    if ($check->fetch()) {
        header("Location: " . servitech_url("/auth/log_in.html?registered=exists"));
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

    header("Location: " . servitech_url("/auth/log_in.html?registered=1"));
    exit();

} catch (PDOException $e) {
    error_log("register error: " . $e->getMessage());
    header("Location: " . servitech_url("/auth/regis.html?error=1"));
    exit();
}
