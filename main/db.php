<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$host = "aws-1-ap-southeast-1.pooler.supabase.com";
$port = "5432";
$db   = "postgres";
$user = "postgres.gxepuopnghgpqnldrjda";
$pass = "cb9D9tvfZaJFygPZ";

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("DB ERROR: " . $e->getMessage());
}