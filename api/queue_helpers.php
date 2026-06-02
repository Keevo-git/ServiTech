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

function servitech_ensure_queue_lifecycle_schema(PDO $pdo): void {
  static $ensured = false;
  if ($ensured) return;

  servitech_rollover_expired_queue_cycles($pdo);
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

function servitech_add_notification(PDO $pdo, int $userId, string $type, ?int $referenceId, string $message, string $eventKey = ""): void {
  $type = trim($type) !== "" ? trim($type) : "queue";
  $message = trim($message);
  $eventKey = trim($eventKey);
  if ($userId <= 0 || $message === "") {
    return;
  }

  $stmt = $pdo->prepare("
    INSERT INTO notifications (user_id, type, reference_id, message, event_key, is_read, created_at)
    SELECT :user_id, :type, :reference_id, :message, NULLIF(:event_key, ''), FALSE, NOW()
    WHERE NOT EXISTS (
      SELECT 1
      FROM notifications
      WHERE user_id = :existing_user_id
        AND LOWER(TRIM(COALESCE(type, 'queue'))) = LOWER(TRIM(:existing_type))
        AND COALESCE(reference_id, 0) = COALESCE(:existing_reference_id, 0)
        AND COALESCE(NULLIF(TRIM(event_key), ''), MD5(TRIM(COALESCE(message, ''))))
          = COALESCE(NULLIF(TRIM(:existing_event_key), ''), MD5(TRIM(:existing_message)))
        AND deleted_at IS NULL
    )
    ON CONFLICT DO NOTHING
  ");
  $stmt->execute([
    ":user_id" => $userId,
    ":type" => $type,
    ":reference_id" => $referenceId,
    ":message" => $message,
    ":event_key" => $eventKey,
    ":existing_user_id" => $userId,
    ":existing_type" => $type,
    ":existing_reference_id" => $referenceId,
    ":existing_message" => $message,
    ":existing_event_key" => $eventKey,
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
