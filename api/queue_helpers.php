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

function servitech_queue_cycle_date(): string {
  return (new DateTimeImmutable("now", new DateTimeZone("Asia/Manila")))->format("Y-m-d");
}

function servitech_rollover_expired_queue_cycles(PDO $pdo): void {
  // Moving a record into Order Management must never complete its workflow.
  $stmt = $pdo->prepare("
    UPDATE queues
    SET lifecycle_stage = 'ORDER'
    WHERE UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'QUEUE'
      AND queue_cycle_date < :cycle_date
  ");
  $stmt->execute([":cycle_date" => servitech_queue_cycle_date()]);
}

function servitech_ensure_queue_write_schema(PDO $pdo): void {
  static $verified = false;
  if ($verified) return;

  $requiredQueueColumns = [
    "price", "paid_amount", "completed_at", "closed_at", "lifecycle_stage",
    "queue_cycle_date", "daily_sequence", "customer_edit_required",
    "send_back_message", "send_back_at", "send_back_by", "updated_at",
  ];
  $stmt = $pdo->query("
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'queues'
  ");
  $available = array_fill_keys(array_map("strval", $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
  $missing = array_values(array_filter(
    $requiredQueueColumns,
    static fn(string $column): bool => !isset($available[$column])
  ));
  if ($missing) {
    throw new RuntimeException(
      "Required database migrations are missing: queues." . implode(", queues.", $missing)
    );
  }
  $verified = true;
}

function servitech_ensure_queue_lifecycle_schema(PDO $pdo): void {
  static $ensured = false;
  if ($ensured) return;

  servitech_ensure_queue_write_schema($pdo);
  $rlsEnforced = function_exists("servitech_supabase_env_bool")
    && servitech_supabase_env_bool("SERVITECH_DB_ENFORCE_RLS", false);
  $isAdmin = function_exists("servitech_is_admin") && servitech_is_admin();
  if (!$rlsEnforced || $isAdmin) {
    servitech_rollover_expired_queue_cycles($pdo);
  }
  $ensured = true;
}

function servitech_generate_queue_identity(PDO $pdo, string $prefix): array {
  $prefix = strtoupper(trim($prefix));
  if ($prefix === "" || !preg_match('/^[A-Z]+$/', $prefix)) {
    throw new InvalidArgumentException("Invalid queue prefix.");
  }

  servitech_ensure_queue_lifecycle_schema($pdo);
  $rlsEnforced = function_exists("servitech_supabase_env_bool")
    && servitech_supabase_env_bool("SERVITECH_DB_ENFORCE_RLS", false);
  if ($rlsEnforced) {
    $stmt = $pdo->prepare("
      SELECT queue_code, queue_cycle_date, daily_sequence
      FROM public.servitech_next_queue_identity(:prefix)
    ");
    $stmt->execute([":prefix" => $prefix]);
    $identity = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($identity)) {
      throw new RuntimeException("Queue identity could not be generated.");
    }
    return [
      "queue_code" => (string)$identity["queue_code"],
      "queue_cycle_date" => (string)$identity["queue_cycle_date"],
      "daily_sequence" => (int)$identity["daily_sequence"],
    ];
  }

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

function servitech_add_notification(PDO $pdo, int $userId, string $type, ?int $referenceId, string $message, string $eventKey = "", bool $dedupeDeleted = false): void {
  $type = trim($type) !== "" ? trim($type) : "queue";
  $message = trim($message);
  $eventKey = trim($eventKey);
  if ($userId <= 0 || $message === "") {
    return;
  }

  $rlsEnforced = function_exists("servitech_supabase_env_bool")
    && servitech_supabase_env_bool("SERVITECH_DB_ENFORCE_RLS", false);
  if ($rlsEnforced) {
    $stmt = $pdo->prepare("
      SELECT public.servitech_add_notification_secure(
        :user_id, :type, :reference_id, :message, :event_key, :dedupe_deleted
      )
    ");
    $stmt->execute([
      ":user_id" => $userId,
      ":type" => $type,
      ":reference_id" => $referenceId,
      ":message" => $message,
      ":event_key" => $eventKey,
      ":dedupe_deleted" => $dedupeDeleted,
    ]);
    return;
  }

  $stmt = $pdo->prepare("
    WITH notification_input AS (
      SELECT
        :user_id::integer AS user_id,
        :type::text AS type,
        :reference_id::bigint AS reference_id,
        :message::text AS message,
        :event_key::text AS raw_event_key
    ),
    notification_event AS (
      SELECT
        user_id,
        type,
        reference_id,
        message,
        NULLIF(raw_event_key, '') AS event_key,
        COALESCE(NULLIF(TRIM(raw_event_key), ''), MD5(TRIM(message))) AS event_identity
      FROM notification_input
    ),
    notification_lock AS (
      SELECT pg_advisory_xact_lock(hashtext(CONCAT_WS(
        '|',
        user_id::text,
        LOWER(TRIM(COALESCE(type, 'queue'))),
        COALESCE(reference_id, 0)::text,
        event_identity
      )))
      FROM notification_event
    )
    INSERT INTO notifications (user_id, type, reference_id, message, event_key, is_read, created_at)
    SELECT notification_event.user_id, notification_event.type, notification_event.reference_id,
      notification_event.message, notification_event.event_key, FALSE, NOW()
    FROM notification_event, notification_lock
    WHERE NOT EXISTS (
      SELECT 1
      FROM notifications
      WHERE user_id = notification_event.user_id
        AND LOWER(TRIM(COALESCE(type, 'queue'))) = LOWER(TRIM(notification_event.type))
        AND COALESCE(reference_id, 0) = COALESCE(notification_event.reference_id, 0)
        AND COALESCE(NULLIF(TRIM(event_key), ''), MD5(TRIM(COALESCE(message, ''))))
          = notification_event.event_identity
        AND (CAST(:dedupe_deleted AS INTEGER) = 1 OR deleted_at IS NULL)
    )
    ON CONFLICT DO NOTHING
  ");
  $stmt->execute([
    ":user_id" => $userId,
    ":type" => $type,
    ":reference_id" => $referenceId,
    ":message" => $message,
    ":event_key" => $eventKey,
    ":dedupe_deleted" => $dedupeDeleted ? 1 : 0,
  ]);
}

function servitech_notify_admins(PDO $pdo, string $type, ?int $referenceId, string $message, string $eventKey = "", bool $dedupeDeleted = false): void {
  if (trim($message) === "") {
    return;
  }

  $rlsEnforced = function_exists("servitech_supabase_env_bool")
    && servitech_supabase_env_bool("SERVITECH_DB_ENFORCE_RLS", false);
  if ($rlsEnforced) {
    $stmt = $pdo->prepare("
      SELECT public.servitech_notify_admin_secure(
        :type, :reference_id, :message, :event_key, :dedupe_deleted
      )
    ");
    $stmt->execute([
      ":type" => $type,
      ":reference_id" => $referenceId,
      ":message" => $message,
      ":event_key" => $eventKey,
      ":dedupe_deleted" => $dedupeDeleted,
    ]);
    return;
  }

  $stmt = $pdo->query("
    SELECT id
    FROM users
    WHERE LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) = 'admin'
    ORDER BY id ASC
    LIMIT 1
  ");

  $adminId = (int)($stmt->fetchColumn() ?: 0);
  if ($adminId > 0) {
    servitech_add_notification($pdo, $adminId, $type, $referenceId, $message, $eventKey, $dedupeDeleted);
  }
}
