<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  http_response_code(401);
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

$allowed = ["printing","repair","installation","walkin","general"];
if (!in_array($category, $allowed, true)) $category = "printing";

// Store all request details in details jsonb (including service_label).
$details = [
  "service_label" => $service_label,
  "paper_size" => $data["paper_size"] ?? null,
  "quantity" => isset($data["quantity"]) ? max(1, (int)$data["quantity"]) : null,
  "color_option" => $data["color_option"] ?? null,
  "package_label" => $data["package_label"] ?? null,
  "lamination_type" => $data["lamination_type"] ?? null,
  "device_type" => $data["device_type"] ?? null,
  "notes" => $data["notes"] ?? null,
  "file_name" => $data["file_name"] ?? null,
  "file_names" => isset($data["file_names"]) && is_array($data["file_names"]) ? $data["file_names"] : null,
  "total_files" => isset($data["total_files"]) ? max(0, (int)$data["total_files"]) : null,
  "total_images" => isset($data["total_images"]) ? max(0, (int)$data["total_images"]) : null,
  "total_pages" => isset($data["total_pages"]) ? max(0, (int)$data["total_pages"]) : null,
  "price_per_page" => isset($data["price_per_page"]) ? max(0, (float)$data["price_per_page"]) : null,
  "estimated_total" => isset($data["estimated_total"]) ? max(0, (float)$data["estimated_total"]) : null,
  "file_analysis" => isset($data["file_analysis"]) && is_array($data["file_analysis"]) ? $data["file_analysis"] : null,
  "uploaded_files" => isset($data["uploaded_files"]) && is_array($data["uploaded_files"]) ? $data["uploaded_files"] : null,
];

foreach ($details as $k => $v) {
  if ($v === null) unset($details[$k]);
  if (is_string($v) && trim($v) === "") unset($details[$k]);
}

$prefix = "P";
if ($category === "repair") $prefix = "R";
if ($category === "installation") $prefix = "I";
if ($category === "walkin") $prefix = "W";

try {
  // get last queue code for this prefix
  $stmt = $pdo->prepare("
    SELECT queue_code
    FROM queues
    WHERE queue_code LIKE :like
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute([":like" => $prefix . "%"]);
  $row = $stmt->fetch();

  $next = 1;
  if ($row && !empty($row["queue_code"]) && preg_match('/^' . preg_quote($prefix, "/") . '(\d+)$/', $row["queue_code"], $m)) {
    $next = (int)$m[1] + 1;
  }
  $queue_code = $prefix . str_pad((string)$next, 4, "0", STR_PAD_LEFT);

  $ins = $pdo->prepare("
    INSERT INTO queues (user_id, queue_code, category, details)
    VALUES (:user_id, :queue_code, :category, :details::jsonb)
  ");
  $ins->execute([
    ":user_id" => $user_id,
    ":queue_code" => $queue_code,
    ":category" => $category,
    ":details" => json_encode($details, JSON_UNESCAPED_UNICODE),
  ]);

  echo json_encode(["ok" => true, "queue_code" => $queue_code]);
  exit();

} catch (PDOException $e) {
  error_log("queue_create error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
  exit();
}
