<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/admin_db.php";

header("Content-Type: application/json; charset=utf-8");

$id = (int)($_POST["id"] ?? 0);
$action = $_POST["action"] ?? "";

if ($id <= 0) {
    echo json_encode(["ok" => false, "error" => "Invalid ID"]);
    exit();
}

if ($action === "delete") {
    $stmt = $pdo->prepare("DELETE FROM queues WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(["ok" => true]);
    exit();
}

$statusMap = [
    "start" => "In Progress",
    "hold"  => "On Hold"
];

if (!isset($statusMap[$action])) {
    echo json_encode(["ok" => false, "error" => "Invalid action"]);
    exit();
}

$newStatus = $statusMap[$action];

$stmt = $pdo->prepare("UPDATE queues SET status = ? WHERE id = ?");
$stmt->execute([$newStatus, $id]);

echo json_encode(["ok" => true, "status" => $newStatus]);
