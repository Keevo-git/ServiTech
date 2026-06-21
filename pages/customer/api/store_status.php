<?php
require_once __DIR__ . "/../../../config/session_check.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!servitech_is_logged_in() || !servitech_is_customer()) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Unauthorized"]);
  exit;
}

require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../config/store_availability.php";

function servitech_customer_store_status_tone(string $status): string {
  return [
    "open" => "open",
    "closed" => "closed",
    "closed_today" => "closed",
    "holiday" => "closed",
    "outside_hours" => "closed",
    "past_cutoff" => "closed",
    "paused" => "paused",
    "fully_booked" => "fully-booked",
    "full_booked" => "fully-booked",
  ][$status] ?? "closed";
}

function servitech_customer_store_status_text(string $status): string {
  return [
    "open" => "Currently Open",
    "paused" => "Currently Paused",
    "fully_booked" => "Fully Booked",
    "full_booked" => "Fully Booked",
  ][$status] ?? "Currently Closed";
}

try {
  $now = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
  $availability = servitech_store_current_availability($pdo, $now);
  $statusKey = strtolower((string)($availability["effective_status"] ?? $availability["reason_code"] ?? "closed"));

  $payload = [
    "ok" => true,
    "generated_at" => $now->format(DateTimeInterface::ATOM),
    "status" => $statusKey,
    "status_label" => servitech_customer_store_status_text($statusKey),
    "status_class" => servitech_customer_store_status_tone($statusKey),
    "today_hours" => (string)($availability["today_hours"] ?? "Closed"),
    "queue_until" => (string)($availability["queue_cutoff_label"] ?? "Not set"),
    "document_printing" => !empty($availability["document_printing_allowed"]) ? "Available" : "Unavailable",
    "message" => (string)($availability["customer_message"] ?? $availability["message"] ?? ""),
    "regular_queue_allowed" => !empty($availability["regular_queue_allowed"]),
    "document_printing_allowed" => !empty($availability["document_printing_allowed"]),
    "reason_code" => (string)($availability["reason_code"] ?? $availability["reason"] ?? $statusKey),
  ];

  $payload["version"] = hash("sha256", implode("|", [
    $payload["status"],
    $payload["today_hours"],
    $payload["queue_until"],
    $payload["document_printing"],
    $payload["message"],
  ]));

  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
  error_log("customer store status endpoint error: " . $exception->getMessage());
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Unable to load store status."]);
}
