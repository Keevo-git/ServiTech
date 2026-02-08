<?php
require_once __DIR__ . "/session_check.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  echo json_encode(["ok" => false, "error" => "Not logged in"]);
  exit();
}

try {
  $stmt = $pdo->prepare("
    SELECT id, queue_code, category, service_label, details, status, created_at, updated_at
    FROM queues
    WHERE user_id = :uid
    ORDER BY created_at DESC
  ");
  $stmt->execute([":uid" => $user_id]);
  $rows = $stmt->fetchAll();

  $out = [];
  foreach ($rows as $r) {
    $details = [];
    if (!empty($r["details"])) {
      $d = json_decode($r["details"], true);
      if (is_array($d)) $details = $d;
    }

    $out[] = [
      "id" => (int)$r["id"],
      "queue_code" => $r["queue_code"],
      "category" => $r["category"],
      "service_label" => $r["service_label"],
      "status" => $r["status"],
      "created_at" => $r["created_at"],
      "updated_at" => $r["updated_at"],
      "details" => $details
    ];
  }

  echo json_encode(["ok" => true, "queues" => $out]);
  exit();

} catch (PDOException $e) {
  error_log("queue_list error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
  exit();
}
