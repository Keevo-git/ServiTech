<?php
$host = "localhost";
$db   = "your_database";
$user = "tonny1";          // database user you created
$pass = "PASSWORD_HERE";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}
