<?php
// Reuse the same Supabase PDO connection used by customer-side runtime.
require_once __DIR__ . "/../../../config/db.php";

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

function admin_queue_submitted_date($value): string {
  return admin_format_queue_submitted_at($value, "M d, Y");
}

function admin_queue_submitted_time($value): string {
  return admin_format_queue_submitted_at($value, "h:i A");
}
