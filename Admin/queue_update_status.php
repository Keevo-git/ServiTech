<?php
require_once __DIR__ . "/../inc/admin_auth.php";
require_once __DIR__ . "/../inc/db.php";

header("Content-Type: application/json; charset=utf-8");

$id = (int)($_POST["id"] ?? 0);
$status = trim($_POST["status"] ?? "");

$allowed = ["Pending", "In Progress", "On Hold", "Completed"];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  echo json_encode(["ok" => false, "error" => "Invalid request"]);
  exit();
}

try {
  $stmt = $pdo->prepare("UPDATE queues SET status = :status WHERE id = :id");
  $stmt->execute([":status" => $status, ":id" => $id]);

  echo json_encode(["ok" => true]);
} catch (PDOException $e) {
  error_log("queue_update_status error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
}
