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

function servitech_generate_queue_code(PDO $pdo, string $prefix): string {
  $prefix = strtoupper(trim($prefix));
  if ($prefix === "" || !preg_match('/^[A-Z]+$/', $prefix)) {
    throw new InvalidArgumentException("Invalid queue prefix.");
  }

  $pdo->exec("LOCK TABLE queues IN EXCLUSIVE MODE");

  $regex = '^' . preg_quote($prefix, '/') . '[0-9]+$';
  $stmt = $pdo->prepare("
    SELECT queue_code
    FROM queues
    WHERE queue_code ~ :regex
    ORDER BY CAST(SUBSTRING(queue_code FROM '[0-9]+$') AS INTEGER) DESC, id DESC
    LIMIT 1
  ");
  $stmt->execute([":regex" => $regex]);
  $row = $stmt->fetch();

  $next = 1;
  if ($row && !empty($row["queue_code"]) && preg_match('/^' . preg_quote($prefix, '/') . '(\\d+)$/', (string)$row["queue_code"], $matches)) {
    $next = ((int)$matches[1]) + 1;
  }

  return $prefix . str_pad((string)$next, 4, "0", STR_PAD_LEFT);
}

function servitech_cleanup_uploaded_print_files(array $uploadedFiles): void {
  if (empty($uploadedFiles)) {
    return;
  }

  $projectRoot = dirname(__DIR__);
  $uploadDir = $projectRoot . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "printing";
  $uploadDirReal = realpath($uploadDir);
  if ($uploadDirReal === false) {
    return;
  }

  $seen = [];

  foreach ($uploadedFiles as $file) {
    if (!is_array($file)) {
      continue;
    }

    $savedPath = trim((string)($file["saved_path"] ?? ""));
    if ($savedPath === "" || strpos($savedPath, "/uploads/printing/") !== 0) {
      continue;
    }

    $basename = basename($savedPath);
    if ($basename === "" || isset($seen[$basename])) {
      continue;
    }
    $seen[$basename] = true;

    $targetPath = $uploadDirReal . DIRECTORY_SEPARATOR . $basename;
    $targetReal = realpath($targetPath);
    if ($targetReal === false) {
      continue;
    }

    if (strpos($targetReal, $uploadDirReal . DIRECTORY_SEPARATOR) !== 0) {
      continue;
    }

    if (is_file($targetReal)) {
      @unlink($targetReal);
    }
  }
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
