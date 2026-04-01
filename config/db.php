<?php
// Clean Supabase connection

ini_set("display_errors", 0);
error_reporting(0);

$host = "db.gxepuopnghgpqnldjrjda.supabase.co";
$port = "5432";
$db   = "postgres";
$user = "postgres";
$pass = "REMOVED_DB_PASSWORD"; // your real password

$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("DB connection failed");
}