<?php
// Admin/_includes/admin_db.php

$host = "localhost";
$db   = "servitech_db";
$user = "tonny1";
$pass = "Tonny123!";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("DB ERROR: " . $e->getMessage());
}
