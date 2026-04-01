<?php
require_once __DIR__ . "/../../config/db.php";

function safe_count(PDO $pdo, string $sql): int {
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// DATA
$customers = safe_count($pdo, "SELECT COUNT(*) FROM users");

$onlineOrders = safe_count(
    $pdo,
    "SELECT COUNT(*) FROM queues 
     WHERE LOWER(TRIM(status)) != 'cancelled'"
);

$activeQueue = safe_count(
    $pdo,
    "SELECT COUNT(*) FROM queues 
     WHERE LOWER(TRIM(status)) IN ('pending','for pick-up','processing')"
);

// RESPONSE
header("Content-Type: application/json");
echo json_encode([
    "customers" => $customers,
    "onlineOrders" => $onlineOrders,
    "activeQueue" => $activeQueue
]);