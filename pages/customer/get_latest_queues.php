<?php
require_once __DIR__ . "/../../config/session_check.php";
require_once __DIR__ . "/../../config/db.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  http_response_code(401);
  echo json_encode(["error" => "Not logged in"]);
  exit();
}
if (!servitech_is_customer()) {
  http_response_code(403);
  echo json_encode(["error" => "Customer access required"]);
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
    "printing" => [
      "label" => "Print",
      "sql" => "(q.category IN (:category_printing, :category_online_printorder, :category_printing_online, :category_walkin, :category_printing_walkin) OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%')",
      "params" => [
        ":category_printing" => "printing",
        ":category_online_printorder" => "online_printorder",
        ":category_printing_online" => "printing_online",
        ":category_walkin" => "walkin",
        ":category_printing_walkin" => "printing_walkin",
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

function store_today_sql(): string {
  return "COALESCE(q.queue_cycle_date, (q.created_at AT TIME ZONE 'Asia/Manila')::date) = (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date";
}

function normalize_service_label(string $serviceLabel, string $fallbackLabel): string {
  $serviceLabel = trim($serviceLabel);
  if ($serviceLabel === "") return $fallbackLabel;
  $normalized = strtolower($serviceLabel);
  if (
    in_array($normalized, [
    "document printing",
    "document print",
    "walk-in printing",
    "walk-in document printing",
    "walk-in document print",
    ], true)
    || (str_contains($normalized, "document") && str_contains($normalized, "print"))
    || (str_contains($normalized, "print") && str_contains($normalized, "order"))
  ) return "Document Print";
  if (strcasecmp($serviceLabel, "xerox") === 0) return "Photocopy";
  if (strcasecmp($serviceLabel, "lamination") === 0) return "Laminating";
  return $serviceLabel;
}

function build_short_details(array $details, bool $includeNotes = true): string {
  $parts = [];
  $value = static function (array $keys) use ($details): string {
    foreach ($keys as $key) {
      if (isset($details[$key]) && !is_array($details[$key]) && trim((string)$details[$key]) !== "") {
        return trim((string)$details[$key]);
      }
    }
    return "";
  };

  if (($paper = $value(["paper_size_snapshot", "paper_size"])) !== "") {
    $parts[] = $paper;
  }

  if (($quantity = $value(["quantity_snapshot", "quantity"])) !== "") {
    $qty = max(1, (int)$quantity);
    $parts[] = $qty . " " . ($qty === 1 ? "copy" : "copies");
  }

  if (($color = $value(["color_option_snapshot", "color_option"])) !== "") {
    $parts[] = $color;
  }

  if (($package = $value(["package_snapshot", "package_label"])) !== "") {
    $parts[] = $package;
  }

  if (($device = $value(["device_snapshot", "device_type"])) !== "") {
    $parts[] = $device;
  }

  foreach ([["service_type_snapshot", "repair_type"], ["installation_type_snapshot", "installation_type"]] as $keys) {
    if (($selected = $value($keys)) !== "") $parts[] = $selected;
  }

  $addOns = $details["add_ons_snapshot"] ?? [];
  if (is_array($addOns)) {
    $addOnNames = [];
    foreach ($addOns as $addOn) {
      if (is_array($addOn) && trim((string)($addOn["name"] ?? "")) !== "") {
        $addOnNames[] = trim((string)$addOn["name"]);
      }
    }
    if ($addOnNames !== []) {
      $parts[] = "Add-ons: " . implode(", ", $addOnNames);
    }
  }

  if (($lamination = $value(["lamination_type_snapshot", "lamination_type"])) !== "") {
    $parts[] = ucfirst(strtolower($lamination)) . " Lamination";
  }

  if ($includeNotes && !count($parts) && ($notes = $value(["customer_notes_snapshot", "notes"])) !== "") {
    $parts[] = $notes;
  }

  if (!count($parts)) return "No extra details";

  $parts = array_slice($parts, 0, 4);
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
    $serviceLabel = normalize_service_label((string)($details["service_name_snapshot"] ?? ($details["service_label"] ?? "")), $meta["label"]);

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

function fetch_latest_queue_items(PDO $pdo, string $categoryKey, int $limit): array {
  $limit = max(1, $limit);
  $meta = queue_category_meta($categoryKey);

  $sql = "
    SELECT q.queue_code, q.status, q.details, q.created_at
    FROM queues q
    WHERE {$meta['sql']}
      AND " . store_today_sql() . "
    ORDER BY q.created_at DESC
    LIMIT {$limit}
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($meta["params"]);

  $items = [];
  foreach ($stmt->fetchAll() as $row) {
    $details = parse_queue_details($row["details"] ?? null);
    $createdAt = trim((string)($row["created_at"] ?? ""));
    $status = strtoupper(trim((string)($row["status"] ?? "PENDING")));
    $serviceLabel = normalize_service_label((string)($details["service_name_snapshot"] ?? ($details["service_label"] ?? "")), $meta["label"]);

    $items[] = [
      "queue_code" => trim((string)($row["queue_code"] ?? "")),
      "status" => $status,
      "status_label" => format_status_label($status),
      "status_tone" => queue_status_tone($status),
      "category_label" => $meta["label"],
      "service_label" => $serviceLabel,
      "details_label" => build_short_details($details, false),
      "created_at" => $createdAt,
      "created_at_label" => $createdAt !== "" ? date("M d, Y h:i A", strtotime($createdAt)) : "",
    ];
  }

  return $items;
}
$queueCategories = ["printing", "installation", "repair"];
$activeQueues = [];
$recentQueues = [];

foreach ($queueCategories as $categoryKey) {
  $activeQueues[$categoryKey] = fetch_user_queue_items($pdo, $user_id, $categoryKey, 1, true);
  $recentQueues[$categoryKey] = fetch_latest_queue_items($pdo, $categoryKey, 1);
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
