<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/db.php";

header("Content-Type: application/json; charset=utf-8");

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Not logged in"]);
  exit();
}

try {
  $stmt = $pdo->prepare("
    SELECT id, queue_code, category, status, details, created_at, updated_at
    FROM queues
    WHERE user_id = :uid
    ORDER BY created_at DESC
  ");
  $stmt->execute([":uid" => $user_id]);
  $rows = $stmt->fetchAll();

  $out = [];
  foreach ($rows as $r) {
    $details = [];
    if (isset($r["details"])) {
      if (is_array($r["details"])) {
        $details = $r["details"];
      } else if (is_string($r["details"]) && $r["details"] !== "") {
        $d = json_decode($r["details"], true);
        if (is_array($d)) $details = $d;
      }
    }

    $out[] = [
      "id" => (int)$r["id"],
      "queue_code" => $r["queue_code"],
      "category" => $r["category"],
      "status" => $r["status"],
      "created_at" => $r["created_at"],
      "updated_at" => $r["updated_at"],
      "service_label" => $details["service_label"] ?? null,
      "paper_size" => $details["paper_size"] ?? null,
      "quantity" => $details["quantity"] ?? null,
      "color_option" => $details["color_option"] ?? null,
      "package_label" => $details["package_label"] ?? null,
      "lamination_type" => $details["lamination_type"] ?? null,
      "device_type" => $details["device_type"] ?? null,
      "notes" => $details["notes"] ?? null,
      "file_name" => $details["file_name"] ?? null,
      "file_names" => $details["file_names"] ?? null,
      "uploaded_files" => $details["uploaded_files"] ?? null,
      "details" => $details,
    ];
  }

  echo json_encode(["ok" => true, "queues" => $out]);
  exit();

} catch (PDOException $e) {
  error_log("queue_list error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
  exit();
}
