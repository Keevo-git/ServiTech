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

function admin_table_has_columns(PDO $pdo, string $table, array $columns): bool {
  $table = strtolower(trim($table));
  $columns = array_values(array_unique(array_filter(array_map(
    static fn($column) => strtolower(trim((string)$column)),
    $columns
  ))));

  if ($table === "" || !$columns || !preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
    return false;
  }

  static $cache = [];
  $cacheKey = $table . ":" . implode(",", $columns);
  if (array_key_exists($cacheKey, $cache)) {
    return $cache[$cacheKey];
  }

  try {
    $placeholders = implode(",", array_fill(0, count($columns), "?"));
    $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT column_name)
      FROM information_schema.columns
      WHERE table_schema = ANY(current_schemas(false))
        AND table_name = ?
        AND column_name IN ({$placeholders})
    ");
    $stmt->execute(array_merge([$table], $columns));
    $cache[$cacheKey] = (int)$stmt->fetchColumn() === count($columns);
  } catch (Throwable $exception) {
    error_log("admin schema capability check failed: " . $exception->getMessage());
    $cache[$cacheKey] = false;
  }

  return $cache[$cacheKey];
}

function admin_order_recycle_schema_ready(PDO $pdo): bool {
  static $ensured = false;
  if (!$ensured) {
    try {
      $pdo->exec("
        ALTER TABLE queues
          ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL,
          ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL,
          ADD COLUMN IF NOT EXISTS permanently_hidden_at TIMESTAMPTZ NULL,
          ADD COLUMN IF NOT EXISTS permanently_hidden_by INTEGER NULL,
          ADD COLUMN IF NOT EXISTS delete_reason TEXT NULL
      ");
      $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_queues_order_recycle_bin
          ON queues (deleted_at DESC, id DESC)
          WHERE deleted_at IS NOT NULL
            AND permanently_hidden_at IS NULL
            AND UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'ORDER'
      ");
      $ensured = true;
    } catch (Throwable $exception) {
      error_log("admin order recycle schema ensure failed: " . $exception->getMessage());
    }
  }

  return admin_table_has_columns($pdo, "queues", ["deleted_at", "deleted_by", "permanently_hidden_at", "permanently_hidden_by", "delete_reason"]);
}

function admin_order_soft_delete_column_ready(PDO $pdo): bool {
  static $ensured = false;
  if (!$ensured) {
    try {
      $pdo->exec("
        ALTER TABLE queues
          ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL,
          ADD COLUMN IF NOT EXISTS permanently_hidden_at TIMESTAMPTZ NULL
      ");
      $ensured = true;
    } catch (Throwable $exception) {
      error_log("admin order soft-delete column ensure failed: " . $exception->getMessage());
    }
  }

  return admin_table_has_columns($pdo, "queues", ["deleted_at", "permanently_hidden_at"]);
}
