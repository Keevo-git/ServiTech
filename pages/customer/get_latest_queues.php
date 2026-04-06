<?php
require_once __DIR__ . "/../../config/session_check.php";
require_once __DIR__ . "/../../config/db.php";

header("Content-Type: application/json; charset=utf-8");

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  http_response_code(401);
  echo json_encode(["error" => "Not logged in"]);
  exit();
}

function format_status_label($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "FOR PICK-UP") return "For Pick-up";
  return ucwords(strtolower($s));
}

function queue_status_tone($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "ONGOING") return "ongoing";
  if ($s === "FOR PICK-UP") return "ready";
  if ($s === "DONE") return "done";
  if ($s === "CANCELLED") return "cancelled";
  return "pending";
}

function fetch_recent_queues_by_category(PDO $pdo, string $category, int $limit = 5): array {
  $limit = max(1, $limit);
  $stmt = $pdo->prepare("\n    SELECT queue_code, category, status, created_at\n    FROM queues\n    WHERE category = :category\n    ORDER BY created_at DESC\n    LIMIT {$limit}\n  ");
  $stmt->execute([":category" => $category]);

  $items = [];
  foreach ($stmt->fetchAll() as $row) {
    $createdAt = trim((string)($row["created_at"] ?? ""));
    $status = strtoupper(trim((string)($row["status"] ?? "PENDING")));
    $items[] = [
      "queue_code" => trim((string)($row["queue_code"] ?? "")),
      "category" => trim((string)($row["category"] ?? $category)),
      "status" => $status,
      "status_label" => format_status_label($status),
      "status_tone" => queue_status_tone($status),
      "created_at" => $createdAt,
      "created_at_label" => $createdAt !== "" ? date("M d, Y h:i A", strtotime($createdAt)) : "",
    ];
  }

  return $items;
}

try {
  echo json_encode([
    "printing" => fetch_recent_queues_by_category($pdo, "printing", 5),
    "installation" => fetch_recent_queues_by_category($pdo, "installation", 5),
    "repair" => fetch_recent_queues_by_category($pdo, "repair", 5),
    "online_print" => fetch_recent_queues_by_category($pdo, "walkin", 5),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit();
} catch (PDOException $e) {
  error_log("get_latest_queues error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["error" => "DB error"]);
  exit();
}