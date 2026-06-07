<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/upload_helpers.php";
require_once __DIR__ . "/queue_payment.php";
require_once __DIR__ . "/queue_state_machine.php";

header("Content-Type: application/json; charset=utf-8");

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Not logged in"]);
  exit();
}
if (!servitech_is_customer()) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Customer access required"]);
  exit();
}

function queue_list_upload_path(string $path): string {
  $path = trim($path);
  if ($path === "") return "";

  $pathOnly = parse_url($path, PHP_URL_PATH);
  if (!is_string($pathOnly) || $pathOnly === "") return "";

  $pathOnly = "/" . ltrim($pathOnly, "/");
  $base = servitech_base_path();
  if ($base !== "" && strpos($pathOnly, $base . "/") === 0) {
    $pathOnly = substr($pathOnly, strlen($base));
  }

  $allowedPrefixes = [
    "/uploads/printing/",
    "/uploads/print_orders/",
  ];
  foreach ($allowedPrefixes as $prefix) {
    if (strpos($pathOnly, $prefix) === 0) {
      return $prefix . basename(rawurldecode($pathOnly));
    }
  }

  $basename = basename(rawurldecode($pathOnly));
  return $basename !== "" ? "/uploads/printing/" . $basename : "";
}

function queue_list_upload_url(string $path): string {
  $safePath = queue_list_upload_path($path);
  if ($safePath === "") return "";

  $fullPath = dirname(__DIR__) . str_replace("/", DIRECTORY_SEPARATOR, $safePath);
  if (!is_file($fullPath)) return "";

  return servitech_url("/api/legacy_upload_download.php?path=" . rawurlencode($safePath));
}

function queue_list_normalize_uploaded_files(array $details): array {
  $uploaded = isset($details["uploaded_files"]) && is_array($details["uploaded_files"])
    ? $details["uploaded_files"]
    : [];
  $out = [];

  foreach ($uploaded as $index => $file) {
    if (!is_array($file)) continue;

    $token = strtolower(trim((string)($file["upload_token"] ?? "")));
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
      $file["href"] = servitech_url(servitech_upload_download_path($token));
      $file["available"] = true;
      $out[] = $file;
      continue;
    }

    $path = (string)($file["saved_path"] ?? $file["file_path"] ?? "");
    $href = queue_list_upload_url($path);
    $label = trim((string)($file["original_name"] ?? ""));
    if ($label === "") {
      $label = "File " . ((int)$index + 1);
    }

    $file["original_name"] = $label;
    $file["href"] = $href;
    $file["available"] = $href !== "";
    $out[] = $file;
  }

  return $out;
}

try {
  servitech_ensure_queue_lifecycle_schema($pdo);
  $stmt = $pdo->prepare("
    SELECT
      q.id,
      q.queue_code,
      q.category,
      q.status,
      q.details,
      q.created_at,
      q.updated_at,
      q.price,
      q.paid_amount,
      q.customer_edit_required,
      q.send_back_message,
      q.send_back_at,
      p.payment_method,
      p.reference_number AS payment_reference_number,
      p.amount AS payment_amount
    FROM queues q
    LEFT JOIN LATERAL (
      SELECT payment_method, reference_number, amount
      FROM payments
      WHERE queue_id = q.id
      ORDER BY id DESC
      LIMIT 1
    ) p ON TRUE
    WHERE q.user_id = :uid
    ORDER BY q.created_at DESC
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

    $payment = servitech_queue_payment_values($r + ["details" => $details]);
    $out[] = [
      "id" => (int)$r["id"],
      "queue_code" => $r["queue_code"],
      "category" => $r["category"],
      "status" => $r["status"],
      "created_at" => $r["created_at"],
      "updated_at" => $r["updated_at"],
      "payment_method" => $r["payment_method"] ?? ($details["payment_method"] ?? null),
      "reference_number" => $r["payment_reference_number"] ?? ($details["reference_number"] ?? null),
      "price" => $r["price"] !== null ? (float)$payment["price"] : null,
      "paid_amount" => (float)$payment["paid_amount"],
      "paid_pending" => (float)$payment["paid_pending"],
      "customer_edit_required" => (bool)($r["customer_edit_required"] ?? false),
      "send_back_message" => $r["send_back_message"] ?? null,
      "send_back_at" => $r["send_back_at"] ?? null,
      "service_label" => $details["service_label"] ?? null,
      "paper_size" => $details["paper_size"] ?? null,
      "quantity" => $details["quantity"] ?? null,
      "color_option" => $details["color_option"] ?? null,
      "package_label" => $details["package_label"] ?? null,
      "lamination_type" => $details["lamination_type"] ?? null,
      "device_type" => $details["device_type"] ?? null,
      "notes" => $details["notes"] ?? null,
      "file_name" => $details["file_name"] ?? null,
      "file_href" => queue_list_upload_url((string)($details["file_name"] ?? "")),
      "file_names" => $details["file_names"] ?? null,
      "total_files" => $details["total_files"] ?? null,
      "total_images" => $details["total_images"] ?? null,
      "total_pages" => $details["total_pages"] ?? null,
      "price_per_page" => $details["price_per_page"] ?? null,
      "estimated_total" => $details["estimated_total"] ?? null,
      "file_analysis" => $details["file_analysis"] ?? null,
      "uploaded_files" => queue_list_normalize_uploaded_files($details),
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
