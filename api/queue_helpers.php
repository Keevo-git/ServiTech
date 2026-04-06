<?php

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
