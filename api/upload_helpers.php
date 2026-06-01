<?php

function servitech_upload_private_dir(): string {
  $configured = trim((string)getenv("SERVITECH_PRIVATE_UPLOAD_DIR"));
  return $configured !== ""
    ? rtrim($configured, "\\/")
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "private_uploads";
}

function servitech_upload_storage_path(string $storageKey): string {
  $storageKey = trim($storageKey);
  if ($storageKey === "" || basename($storageKey) !== $storageKey || !preg_match('/^[a-f0-9]{64}\.[a-z0-9]+$/', $storageKey)) {
    throw new RuntimeException("Invalid private upload storage key.");
  }
  return servitech_upload_private_dir() . DIRECTORY_SEPARATOR . $storageKey;
}

function servitech_upload_download_path(string $uploadToken, bool $inline = false): string {
  $path = "/api/upload_download.php?token=" . rawurlencode($uploadToken);
  return $inline ? $path . "&disposition=inline" : $path;
}

function servitech_upload_extension(string $name): string {
  return strtolower((string)preg_replace('/[^a-z0-9]+/', '', pathinfo($name, PATHINFO_EXTENSION)));
}

function servitech_upload_ooxml_has_prefix(string $path, string $prefix): bool {
  if (!class_exists("ZipArchive")) return false;
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return false;
  $valid = $zip->locateName("[Content_Types].xml") !== false;
  for ($i = 0; $valid && $i < $zip->numFiles; $i++) {
    if (strpos((string)$zip->getNameIndex($i), $prefix) === 0) {
      $zip->close();
      return true;
    }
  }
  $zip->close();
  return false;
}

function servitech_upload_validate_type(string $path, string $originalName): array {
  if (!class_exists("finfo")) {
    throw new RuntimeException("PHP fileinfo extension is required for secure uploads.");
  }

  $extension = servitech_upload_extension($originalName);
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = strtolower(trim((string)$finfo->file($path)));
  $allowed = [
    "pdf" => ["application/pdf"],
    "jpg" => ["image/jpeg"],
    "jpeg" => ["image/jpeg"],
    "png" => ["image/png"],
    "doc" => ["application/msword", "application/cdfv2", "application/x-ole-storage"],
    "ppt" => ["application/vnd.ms-powerpoint", "application/cdfv2", "application/x-ole-storage"],
    "docx" => ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip"],
    "pptx" => ["application/vnd.openxmlformats-officedocument.presentationml.presentation", "application/zip"],
  ];

  if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
    throw new DomainException("has invalid file content.");
  }
  if ($extension === "docx" && !servitech_upload_ooxml_has_prefix($path, "word/")) {
    throw new DomainException("is not a valid DOCX document.");
  }
  if ($extension === "pptx" && !servitech_upload_ooxml_has_prefix($path, "ppt/")) {
    throw new DomainException("is not a valid PPTX presentation.");
  }

  return ["extension" => $extension, "mime_type" => $mime];
}

function servitech_upload_token_from_metadata(array $file): string {
  $token = strtolower(trim((string)($file["upload_token"] ?? "")));
  if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    throw new DomainException("An uploaded file is invalid. Please upload it again.");
  }
  return $token;
}

function servitech_upload_public_metadata(array $row): array {
  $token = strtolower(trim((string)($row["upload_token"] ?? "")));
  return [
    "upload_token" => $token,
    "original_name" => (string)($row["original_name"] ?? ""),
    "file_type" => (string)($row["file_extension"] ?? ""),
    "mime_type" => (string)($row["mime_type"] ?? ""),
    "byte_size" => (int)($row["byte_size"] ?? 0),
    "checksum_sha256" => (string)($row["checksum_sha256"] ?? ""),
    "download_url" => servitech_upload_download_path($token),
  ];
}

function servitech_upload_owned_row(PDO $pdo, int $userId, string $token, bool $requireOrphan = true): array {
  $sql = "
    SELECT upload_token, user_id, queue_id, original_name, storage_key, file_extension, mime_type, byte_size, checksum_sha256
    FROM uploads
    WHERE upload_token = :upload_token
      AND user_id = :user_id
      AND deleted_at IS NULL
  ";
  if ($requireOrphan) {
    $sql .= " AND queue_id IS NULL";
  }
  $sql .= " LIMIT 1";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([":upload_token" => $token, ":user_id" => $userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) {
    throw new DomainException("An uploaded file is unavailable or does not belong to your account. Please upload it again.");
  }

  if (!is_file(servitech_upload_storage_path((string)$row["storage_key"]))) {
    throw new DomainException("An uploaded file is missing. Please upload it again.");
  }

  return $row;
}

function servitech_upload_resolve_owned_metadata(PDO $pdo, int $userId, array $uploadedFiles, bool $requireOrphan = true): array {
  $resolved = [];
  $seen = [];
  foreach ($uploadedFiles as $file) {
    if (!is_array($file)) {
      throw new DomainException("An uploaded file is invalid. Please upload it again.");
    }
    $token = servitech_upload_token_from_metadata($file);
    if (isset($seen[$token])) {
      throw new DomainException("An uploaded file was submitted more than once.");
    }
    $seen[$token] = true;
    $resolved[] = servitech_upload_public_metadata(servitech_upload_owned_row($pdo, $userId, $token, $requireOrphan));
  }
  return $resolved;
}

function servitech_upload_apply_metadata_to_details(array $details, array $uploadedFiles): array {
  $details["uploaded_files"] = array_values($uploadedFiles);
  if (empty($uploadedFiles)) return $details;

  $names = [];
  $analysis = [];
  $totalImages = 0;
  foreach ($uploadedFiles as $file) {
    $name = trim((string)($file["original_name"] ?? ""));
    $extension = strtolower(trim((string)($file["file_type"] ?? "")));
    $names[] = $name;
    $analysis[] = ["file_name" => $name, "file_type" => $extension];
    if (in_array($extension, ["jpg", "jpeg", "png"], true)) {
      $totalImages++;
    }
  }

  $details["file_name"] = $names[0] ?? "";
  $details["file_names"] = $names;
  $details["file_analysis"] = $analysis;
  $details["total_files"] = count($uploadedFiles);
  $details["total_images"] = $totalImages;
  return $details;
}

function servitech_upload_link_to_queue(PDO $pdo, int $userId, int $queueId, array $uploadedFiles): void {
  if (empty($uploadedFiles)) return;

  $stmt = $pdo->prepare("
    UPDATE uploads
    SET queue_id = :queue_id, linked_at = NOW()
    WHERE upload_token = :upload_token
      AND user_id = :user_id
      AND queue_id IS NULL
      AND deleted_at IS NULL
  ");
  foreach ($uploadedFiles as $file) {
    if (!is_array($file)) {
      throw new DomainException("An uploaded file is invalid. Please upload it again.");
    }
    $stmt->execute([
      ":queue_id" => $queueId,
      ":upload_token" => servitech_upload_token_from_metadata($file),
      ":user_id" => $userId,
    ]);
    if ($stmt->rowCount() !== 1) {
      throw new DomainException("An uploaded file could not be linked to this order. Please upload it again.");
    }
  }
}

function servitech_upload_delete_owned_orphans(PDO $pdo, int $userId, array $uploadedFiles): array {
  $deleted = [];
  $errors = [];
  $select = $pdo->prepare("
    SELECT upload_token, storage_key
    FROM uploads
    WHERE upload_token = :upload_token
      AND user_id = :user_id
      AND queue_id IS NULL
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $mark = $pdo->prepare("UPDATE uploads SET deleted_at = NOW() WHERE upload_token = :upload_token AND deleted_at IS NULL");

  foreach ($uploadedFiles as $file) {
    if (!is_array($file)) continue;
    try {
      $token = servitech_upload_token_from_metadata($file);
      $select->execute([":upload_token" => $token, ":user_id" => $userId]);
      $row = $select->fetch(PDO::FETCH_ASSOC);
      if (!is_array($row)) continue;

      $path = servitech_upload_storage_path((string)$row["storage_key"]);
      if (is_file($path) && !@unlink($path)) {
        $errors[] = $token;
        continue;
      }
      $mark->execute([":upload_token" => $token]);
      $deleted[] = $token;
    } catch (Throwable $e) {
      $errors[] = trim((string)($file["upload_token"] ?? ""));
    }
  }

  return ["deleted_tokens" => $deleted, "errors" => $errors];
}

function servitech_cleanup_orphan_uploads(PDO $pdo, int $minimumAgeHours = 24): array {
  $minimumAgeHours = max(1, $minimumAgeHours);
  $stmt = $pdo->prepare("
    SELECT upload_token, storage_key
    FROM uploads
    WHERE queue_id IS NULL
      AND deleted_at IS NULL
      AND created_at < NOW() - (CAST(:minimum_age_hours AS INTEGER) * INTERVAL '1 hour')
    ORDER BY created_at ASC
  ");
  $stmt->execute([":minimum_age_hours" => $minimumAgeHours]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $mark = $pdo->prepare("UPDATE uploads SET deleted_at = NOW() WHERE upload_token = :upload_token AND deleted_at IS NULL");
  $deleted = 0;
  $errors = [];

  foreach ($rows as $row) {
    $token = (string)$row["upload_token"];
    $path = servitech_upload_storage_path((string)$row["storage_key"]);
    if (is_file($path) && !@unlink($path)) {
      $errors[] = $token;
      continue;
    }
    $mark->execute([":upload_token" => $token]);
    $deleted++;
  }

  return ["deleted" => $deleted, "errors" => $errors];
}
