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

function parse_queue_details($details): array {
  if (is_array($details)) return $details;
  if (is_string($details) && trim($details) !== "") {
    $decoded = json_decode($details, true);
    if (is_array($decoded)) return $decoded;
  }
  return [];
}

function format_status_label($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "FOR PICK-UP") return "For Pick-up";
  return ucwords(strtolower($s));
}

function queue_status_tone($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "ONGOING") return "ongoing";
  if ($s === "FOR PICK-UP") return "pickup";
  if ($s === "DONE") return "done";
  if ($s === "CANCELLED") return "cancelled";
  return "pending";
}

function queue_category_meta(string $categoryKey): array {
  return match ($categoryKey) {
    "online_print" => [
      "label" => "Online Printing",
      "sql" => "q.category = :category_printing AND COALESCE(q.details->>'service_label', '') = :online_service_label",
      "params" => [
        ":category_printing" => "printing",
        ":online_service_label" => "Online Print Order",
      ],
    ],
    "printing" => [
      "label" => "Printing",
      "sql" => "q.category = :category_printing AND COALESCE(q.details->>'service_label', '') <> :online_service_label",
      "params" => [
        ":category_printing" => "printing",
        ":online_service_label" => "Online Print Order",
      ],
    ],
    "installation" => [
      "label" => "Installation",
      "sql" => "q.category = :category_installation",
      "params" => [":category_installation" => "installation"],
    ],
    default => [
      "label" => "Repair",
      "sql" => "q.category = :category_repair",
      "params" => [":category_repair" => "repair"],
    ],
  };
}

function normalize_service_label(string $serviceLabel, string $fallbackLabel): string {
  $serviceLabel = trim($serviceLabel);
  if ($serviceLabel === "") return $fallbackLabel;
  if (strcasecmp($serviceLabel, "Online Print Order") === 0) return "Online Printing";
  return $serviceLabel;
}

function build_short_details(array $details): string {
  $parts = [];

  if (!empty($details["paper_size"])) {
    $parts[] = trim((string)$details["paper_size"]);
  }

  if (!empty($details["quantity"])) {
    $qty = max(1, (int)$details["quantity"]);
    $parts[] = $qty . " " . ($qty === 1 ? "copy" : "copies");
  }

  if (!empty($details["color_option"])) {
    $parts[] = trim((string)$details["color_option"]);
  }

  if (!empty($details["package_label"])) {
    $parts[] = trim((string)$details["package_label"]);
  }

  if (!empty($details["device_type"])) {
    $parts[] = trim((string)$details["device_type"]);
  }

  if (!empty($details["lamination_type"])) {
    $parts[] = ucfirst(strtolower(trim((string)$details["lamination_type"]))) . " Lamination";
  }

  if (!count($parts) && !empty($details["notes"])) {
    $parts[] = trim((string)$details["notes"]);
  }

  if (!count($parts)) return "No extra details";

  $parts = array_slice($parts, 0, 3);
  return implode(" | ", $parts);
}

function fetch_user_queue_items(PDO $pdo, int $userId, string $categoryKey, int $limit, bool $activeOnly): array {
  $limit = max(1, $limit);
  $meta = queue_category_meta($categoryKey);
  $statusSql = $activeOnly ? "AND q.status NOT IN ('DONE', 'CANCELLED')" : "";

  $sql = "
    SELECT q.queue_code, q.status, q.details, q.created_at
    FROM queues q
    WHERE q.user_id = :user_id
      AND {$meta['sql']}
      {$statusSql}
    ORDER BY q.created_at DESC
    LIMIT {$limit}
  ";

  $params = array_merge([":user_id" => $userId], $meta["params"]);
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $items = [];
  foreach ($stmt->fetchAll() as $row) {
    $details = parse_queue_details($row["details"] ?? null);
    $createdAt = trim((string)($row["created_at"] ?? ""));
    $status = strtoupper(trim((string)($row["status"] ?? "PENDING")));
    $serviceLabel = normalize_service_label((string)($details["service_label"] ?? ""), $meta["label"]);

    $items[] = [
      "queue_code" => trim((string)($row["queue_code"] ?? "")),
      "status" => $status,
      "status_label" => format_status_label($status),
      "status_tone" => queue_status_tone($status),
      "category_label" => $meta["label"],
      "service_label" => $serviceLabel,
      "details_label" => build_short_details($details),
      "created_at" => $createdAt,
      "created_at_label" => $createdAt !== "" ? date("M d, Y h:i A", strtotime($createdAt)) : "",
    ];
  }

  return $items;
}

$queueCategories = ["online_print", "printing", "installation", "repair"];
$activeQueues = [];
$recentQueues = [];

foreach ($queueCategories as $categoryKey) {
  $activeQueues[$categoryKey] = fetch_user_queue_items($pdo, $user_id, $categoryKey, 1, true);
  $recentQueues[$categoryKey] = fetch_user_queue_items($pdo, $user_id, $categoryKey, 1, false);
}

try {
  echo json_encode([
    "active" => $activeQueues,
    "recent" => $recentQueues,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit();
} catch (PDOException $e) {
  error_log("get_latest_queues error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["error" => "DB error"]);
  exit();
}