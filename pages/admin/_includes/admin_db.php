<?php
// Reuse the same Supabase PDO connection used by customer-side runtime.
require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../api/queue_state_machine.php";

try {
  servitech_ensure_queue_lifecycle_schema($pdo);
} catch (Throwable $exception) {
  error_log("queue admin schema check failed: " . $exception->getMessage());
}

function admin_format_queue_submitted_at($value, string $format): string {
  $submittedAt = trim((string)$value);
  if ($submittedAt === "") {
    return "-";
  }

  try {
    $date = new DateTimeImmutable($submittedAt);
    return $date->setTimezone(new DateTimeZone("Asia/Manila"))->format($format);
  } catch (Exception $exception) {
    return "-";
  }
}

function admin_queue_has_timestamp($value): bool {
  return trim((string)$value) !== "";
}

function admin_queue_submitted_date($value): string {
  return admin_format_queue_submitted_at($value, "M d, Y");
}

function admin_queue_submitted_time($value): string {
  return admin_format_queue_submitted_at($value, "h:i A");
}

function admin_queue_completed_date($value): string {
  return admin_format_queue_submitted_at($value, "M d, Y");
}

function admin_queue_completed_time($value): string {
  return admin_format_queue_submitted_at($value, "h:i A");
}
