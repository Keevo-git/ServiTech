<?php

/**
 * Keep queue prefix/category rules in one place so OP**** never drifts away
 * from online_printorder and P**** always stays tied to printing.
 */
function servitech_get_queue_prefix_for_category(string $category): string {
  $category = strtolower(trim($category));

  return match ($category) {
    "online_printorder" => "OP",
    "printing" => "P",
    "repair" => "R",
    "installation" => "I",
    "walkin" => "W",
    default => "P",
  };
}

function servitech_get_category_from_queue_code(string $code): string {
  $code = strtoupper(trim($code));

  if (strpos($code, "OP") === 0) {
    return "online_printorder";
  }
  if (strpos($code, "P") === 0) {
    return "printing";
  }
  if (strpos($code, "R") === 0) {
    return "repair";
  }
  if (strpos($code, "I") === 0) {
    return "installation";
  }
  if (strpos($code, "W") === 0) {
    return "walkin";
  }

  return "";
}

function servitech_get_print_order_queue_meta(string $orderType): array {
  $orderType = strtolower(trim($orderType));

  return match ($orderType) {
    "online" => [
      "category" => "online_printorder",
      "prefix" => "OP",
      "label" => "ONLINE PRINT ORDER",
    ],
    "walkin" => [
      "category" => "printing",
      "prefix" => "P",
      "label" => "WALK-IN",
    ],
    default => [
      "category" => "",
      "prefix" => "",
      "label" => "",
    ],
  };
}

function servitech_queue_code_matches_category(string $queueCode, string $category): bool {
  $resolvedCategory = servitech_get_category_from_queue_code($queueCode);
  return $resolvedCategory !== "" && $resolvedCategory === strtolower(trim($category));
}

function servitech_queue_lifecycle_stage_for_status(string $status): string {
  $status = strtoupper(trim($status));
  $status = preg_replace('/[\s_-]+/', ' ', $status);

  return in_array($status, ["DONE", "COMPLETED", "CANCELLED", "CANCELED"], true)
    ? "ORDER"
    : "QUEUE";
}

function servitech_queue_cycle_date(): string {
  return (new DateTimeImmutable("now", new DateTimeZone("Asia/Manila")))->format("Y-m-d");
}

function servitech_ensure_queue_lifecycle_schema(PDO $pdo): void {
  static $ensured = false;
  if ($ensured) return;

  $columns = $pdo->query("
    SELECT COUNT(DISTINCT column_name)
    FROM information_schema.columns
    WHERE table_schema = ANY(current_schemas(FALSE))
      AND table_name = 'queues'
      AND column_name IN ('completed_at', 'lifecycle_stage', 'queue_cycle_date', 'daily_sequence')
  ");
  if ((int)$columns->fetchColumn() === 4) {
    $ensured = true;
    return;
  }

  $pdo->exec("ALTER TABLE queues ADD COLUMN IF NOT EXISTS completed_at TIMESTAMPTZ NULL");
  $pdo->exec("ALTER TABLE queues ADD COLUMN IF NOT EXISTS lifecycle_stage VARCHAR(16)");
  $pdo->exec("ALTER TABLE queues ADD COLUMN IF NOT EXISTS queue_cycle_date DATE");
  $pdo->exec("ALTER TABLE queues ADD COLUMN IF NOT EXISTS daily_sequence INTEGER");

  $pdo->exec("
    UPDATE queues
    SET lifecycle_stage = CASE
      WHEN UPPER(TRIM(COALESCE(status, 'PENDING'))) IN ('DONE', 'COMPLETED', 'CANCELLED', 'CANCELED') THEN 'ORDER'
      ELSE 'QUEUE'
    END
    WHERE lifecycle_stage IS NULL
       OR UPPER(TRIM(lifecycle_stage)) NOT IN ('QUEUE', 'ORDER')
       OR (
         UPPER(TRIM(COALESCE(status, 'PENDING'))) IN ('DONE', 'COMPLETED', 'CANCELLED', 'CANCELED')
         AND UPPER(TRIM(lifecycle_stage)) <> 'ORDER'
       )
       OR (
         UPPER(TRIM(COALESCE(status, 'PENDING'))) NOT IN ('DONE', 'COMPLETED', 'CANCELLED', 'CANCELED')
         AND UPPER(TRIM(lifecycle_stage)) <> 'QUEUE'
       )
  ");
  $pdo->exec("
    UPDATE queues
    SET queue_cycle_date = COALESCE(
      (created_at AT TIME ZONE 'Asia/Manila')::date,
      (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date
    )
    WHERE queue_cycle_date IS NULL
  ");
  $pdo->exec("
    UPDATE queues
    SET daily_sequence = COALESCE(
      NULLIF(SUBSTRING(queue_code FROM '([0-9]+)$'), '')::INTEGER,
      0
    )
    WHERE daily_sequence IS NULL
  ");

  $pdo->exec("ALTER TABLE queues ALTER COLUMN lifecycle_stage SET DEFAULT 'QUEUE'");
  $pdo->exec("ALTER TABLE queues ALTER COLUMN lifecycle_stage SET NOT NULL");
  $pdo->exec("ALTER TABLE queues ALTER COLUMN queue_cycle_date SET DEFAULT ((CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date)");
  $pdo->exec("ALTER TABLE queues ALTER COLUMN queue_cycle_date SET NOT NULL");
  $pdo->exec("ALTER TABLE queues ALTER COLUMN daily_sequence SET DEFAULT 0");
  $pdo->exec("ALTER TABLE queues ALTER COLUMN daily_sequence SET NOT NULL");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_queues_lifecycle_stage ON queues(lifecycle_stage)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_queues_cycle_date_code ON queues(queue_cycle_date, queue_code)");

  $ensured = true;
}

function servitech_generate_queue_identity(PDO $pdo, string $prefix): array {
  $prefix = strtoupper(trim($prefix));
  if ($prefix === "" || !preg_match('/^[A-Z]+$/', $prefix)) {
    throw new InvalidArgumentException("Invalid queue prefix.");
  }

  servitech_ensure_queue_lifecycle_schema($pdo);
  $pdo->exec("LOCK TABLE queues IN EXCLUSIVE MODE");

  $cycleDate = servitech_queue_cycle_date();
  $compactDate = str_replace("-", "", $cycleDate);
  $regex = '^' . preg_quote($prefix, '/') . preg_quote($compactDate, '/') . '-[0-9]+$';
  $stmt = $pdo->prepare("
    SELECT COALESCE(MAX(daily_sequence), 0)
    FROM queues
    WHERE queue_cycle_date = :cycle_date
      AND queue_code ~ :regex
  ");
  $stmt->execute([":cycle_date" => $cycleDate, ":regex" => $regex]);
  $next = ((int)$stmt->fetchColumn()) + 1;

  return [
    "queue_code" => $prefix . $compactDate . "-" . str_pad((string)$next, 4, "0", STR_PAD_LEFT),
    "queue_cycle_date" => $cycleDate,
    "daily_sequence" => $next,
  ];
}

function servitech_generate_queue_code(PDO $pdo, string $prefix): string {
  return servitech_generate_queue_identity($pdo, $prefix)["queue_code"];
}

function servitech_cleanup_uploaded_print_files(array $uploadedFiles): void {
  // Uploaded print files are order assets, not session temp files. Keep them
  // available for admin printing unless someone manually removes them.
  return;
}

function servitech_ensure_notifications_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS notifications (
      id BIGSERIAL PRIMARY KEY,
      user_id INTEGER NOT NULL,
      type TEXT NOT NULL DEFAULT 'queue',
      reference_id INTEGER NULL,
      message TEXT NOT NULL,
      is_read BOOLEAN NOT NULL DEFAULT FALSE,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
  ");
}

function servitech_add_notification(PDO $pdo, int $userId, string $type, ?int $referenceId, string $message): void {
  if ($userId <= 0 || trim($message) === "") {
    return;
  }

  servitech_ensure_notifications_table($pdo);
  $stmt = $pdo->prepare("
    INSERT INTO notifications (user_id, type, reference_id, message, is_read, created_at)
    VALUES (:user_id, :type, :reference_id, :message, FALSE, NOW())
  ");
  $stmt->execute([
    ":user_id" => $userId,
    ":type" => trim($type) !== "" ? trim($type) : "queue",
    ":reference_id" => $referenceId,
    ":message" => $message,
  ]);
}

function servitech_notify_admins(PDO $pdo, string $type, ?int $referenceId, string $message): void {
  if (trim($message) === "") {
    return;
  }

  $stmt = $pdo->query("
    SELECT id
    FROM users
    WHERE LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) = 'admin'
  ");

  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
    servitech_add_notification($pdo, (int)$adminId, $type, $referenceId, $message);
  }
}
