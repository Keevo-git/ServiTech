<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/_includes/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);
if ($id <= 0) {
  echo json_encode(["ok" => false, "error" => "Invalid ID"]);
  exit();
}

try {
  $stmt = $pdo->prepare("DELETE FROM queues WHERE id = :id");
  $stmt->execute([":id" => $id]);

  echo json_encode(["ok" => true]);
} catch (PDOException $e) {
  error_log("queue_delete error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
}
