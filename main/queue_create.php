<?php
require_once __DIR__ . "/session_check.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  echo json_encode(["ok" => false, "error" => "Not logged in"]);
  exit();
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!is_array($data)) {
  echo json_encode(["ok" => false, "error" => "Invalid JSON"]);
  exit();
}

$category = strtolower(trim((string)($data["category"] ?? "printing")));
$service_label = trim((string)($data["service_label"] ?? ""));
if ($service_label === "") {
  echo json_encode(["ok" => false, "error" => "Service label required"]);
  exit();
}

$allowed = ["printing","repair","installation","walkin"];
if (!in_array($category, $allowed, true)) $category = "printing";

// details JSON (optional fields)
$details = [
  "paper_size" => $data["paper_size"] ?? null,
  "quantity" => isset($data["quantity"]) ? max(1, (int)$data["quantity"]) : null,
  "color_option" => $data["color_option"] ?? null,
  "package_label" => $data["package_label"] ?? null,
  "lamination_type" => $data["lamination_type"] ?? null,
  "device_type" => $data["device_type"] ?? null,
  "notes" => $data["notes"] ?? null,
  "file_name" => $data["file_name"] ?? null,
];

foreach ($details as $k => $v) {
  if ($v === null) unset($details[$k]);
  if (is_string($v) && trim($v) === "") unset($details[$k]);
}
$details_json = empty($details) ? null : json_encode($details, JSON_UNESCAPED_UNICODE);

$prefix = "P";
if ($category === "repair") $prefix = "R";
if ($category === "installation") $prefix = "I";
if ($category === "walkin") $prefix = "W";

try {
  $stmt = $pdo->prepare("SELECT queue_code FROM queues WHERE queue_code LIKE :like ORDER BY id DESC LIMIT 1");
  $stmt->execute([":like" => $prefix . "%"]);
  $row = $stmt->fetch();

  $next = 1;
  if ($row && !empty($row["queue_code"]) && preg_match('/^' . preg_quote($prefix, "/") . '(\d+)$/', $row["queue_code"], $m)) {
    $next = (int)$m[1] + 1;
  }
  $queue_code = $prefix . str_pad((string)$next, 4, "0", STR_PAD_LEFT);

  $ins = $pdo->prepare("
    INSERT INTO queues (user_id, queue_code, category, service_label, details)
    VALUES (:user_id, :queue_code, :category, :service_label, :details)
  ");
  $ins->execute([
    ":user_id" => $user_id,
    ":queue_code" => $queue_code,
    ":category" => $category,
    ":service_label" => $service_label,
    ":details" => $details_json
  ]);

  echo json_encode(["ok" => true, "queue_code" => $queue_code]);
  exit();

} catch (PDOException $e) {
  error_log("queue_create error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
  exit();
}
