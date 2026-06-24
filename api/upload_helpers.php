<?php

function servitech_upload_max_file_bytes(): int {
  return 25 * 1024 * 1024;
}

function servitech_upload_max_total_bytes(): int {
  return 100 * 1024 * 1024;
}

function servitech_upload_max_file_count(): int {
  return 5;
}

function servitech_upload_temporary_retention_hours(): int {
  return 24;
}

function servitech_upload_closed_retention_days(): int {
  return 30;
}

function servitech_upload_assert_limits(array $files, string $sizeKey = "byte_size"): void {
  if (count($files) > servitech_upload_max_file_count()) {
    throw new DomainException("You can upload up to 5 files only.");
  }

  $totalBytes = 0;
  foreach ($files as $file) {
    if (!is_array($file)) {
      throw new DomainException("An uploaded file is invalid. Please upload it again.");
    }

    $size = (int)($file[$sizeKey] ?? 0);
    if ($size > servitech_upload_max_file_bytes()) {
      throw new DomainException("Maximum file size is 25 MB per file.");
    }
    if ($size > 0) {
      $totalBytes += $size;
    }
  }

  if ($totalBytes > servitech_upload_max_total_bytes()) {
    throw new DomainException("Total upload size must not exceed 100 MB.");
  }
}

function servitech_upload_private_dir(): string {
  $configured = trim((string)getenv("SERVITECH_PRIVATE_UPLOAD_DIR"));
  $path = $configured !== ""
    ? rtrim($configured, "\\/")
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "private_uploads";

  $strictPrivateRoot = function_exists("servitech_supabase_env_bool")
    ? servitech_supabase_env_bool(
        "SERVITECH_REQUIRE_PRIVATE_UPLOAD_ROOT",
        function_exists("servitech_supabase_auth_enabled") && servitech_supabase_auth_enabled()
      )
    : false;
  if (!$strictPrivateRoot) {
    return $path;
  }

  if ($configured === "" || !preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\\/)/', $path)) {
    throw new RuntimeException("SERVITECH_PRIVATE_UPLOAD_DIR must be an absolute private path.");
  }
  if (strcasecmp(basename(str_replace("\\", "/", $path)), "ServiTech_Uploads") !== 0) {
    throw new RuntimeException("The private upload directory must end with ServiTech_Uploads.");
  }

  $normalizedPath = strtolower(rtrim(str_replace("\\", "/", $path), "/"));
  $documentRoot = realpath((string)($_SERVER["DOCUMENT_ROOT"] ?? ""));
  if (is_string($documentRoot) && $documentRoot !== "") {
    $normalizedDocumentRoot = strtolower(rtrim(str_replace("\\", "/", $documentRoot), "/"));
    if (
      $normalizedPath === $normalizedDocumentRoot
      || str_starts_with($normalizedPath . "/", $normalizedDocumentRoot . "/")
    ) {
      throw new RuntimeException("ServiTech_Uploads must be outside the public web root.");
    }
  }
  return $path;
}

function servitech_upload_request_id(string $value): string {
  $value = strtolower(trim($value));
  if (!preg_match('/^[a-z0-9][a-z0-9-]{15,79}$/', $value)) {
    throw new DomainException("Invalid upload request.");
  }
  return $value;
}

function servitech_upload_request_state_dir(): string {
  return servitech_upload_private_dir() . DIRECTORY_SEPARATOR . ".request_state";
}

function servitech_upload_request_state_path(string $uploadId): string {
  $uploadId = servitech_upload_request_id($uploadId);
  return servitech_upload_request_state_dir() . DIRECTORY_SEPARATOR . hash("sha256", $uploadId) . ".json";
}

function servitech_upload_cleanup_request_states(int $maximumAgeHours = 24): int {
  $stateDir = servitech_upload_request_state_dir();
  if (!is_dir($stateDir)) return 0;
  $cutoff = time() - (max(1, $maximumAgeHours) * 3600);
  $deleted = 0;
  foreach (glob($stateDir . DIRECTORY_SEPARATOR . "*.json") ?: [] as $path) {
    $modifiedAt = @filemtime($path);
    if (is_int($modifiedAt) && $modifiedAt < $cutoff && @unlink($path)) {
      $deleted++;
    }
  }
  return $deleted;
}

function servitech_upload_request_open(string $uploadId) {
  $stateDir = servitech_upload_request_state_dir();
  if (!is_dir($stateDir) && !mkdir($stateDir, 0750, true) && !is_dir($stateDir)) {
    throw new RuntimeException("Unable to create upload request state directory.");
  }
  if (mt_rand(1, 100) === 1) {
    servitech_upload_cleanup_request_states();
  }

  $handle = @fopen(servitech_upload_request_state_path($uploadId), "c+");
  if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) fclose($handle);
    throw new RuntimeException("Unable to lock upload request state.");
  }
  return $handle;
}

function servitech_upload_request_read($handle): array {
  rewind($handle);
  $raw = stream_get_contents($handle);
  if (!is_string($raw) || trim($raw) === "") return [];
  $state = json_decode($raw, true);
  return is_array($state) ? $state : [];
}

function servitech_upload_request_write($handle, array $state): void {
  $state["updated_at"] = gmdate("c");
  $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($encoded)) {
    throw new RuntimeException("Unable to encode upload request state.");
  }
  rewind($handle);
  if (!ftruncate($handle, 0) || fwrite($handle, $encoded) === false) {
    throw new RuntimeException("Unable to save upload request state.");
  }
  fflush($handle);
}

function servitech_upload_request_close($handle): void {
  if (!is_resource($handle)) return;
  flock($handle, LOCK_UN);
  fclose($handle);
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

function servitech_upload_read_accessible_contents(string $path): string {
  if (!is_file($path) || !is_readable($path)) {
    throw new DomainException("is locked or unreadable. Please remove file protection and try again.");
  }

  $contents = @file_get_contents($path);
  if (!is_string($contents)) {
    throw new DomainException("is locked or unreadable. Please remove file protection and try again.");
  }

  return $contents;
}

function servitech_upload_pdf_is_encrypted(string $contents): bool {
  return preg_match('/\/Encrypt\s+(?:\d+\s+\d+\s+R|<<)/i', $contents) === 1;
}

function servitech_upload_office_is_encrypted(string $contents): bool {
  $normalized = str_replace("\0", "", $contents);
  return stripos($normalized, "EncryptedPackage") !== false
    || stripos($normalized, "EncryptionInfo") !== false;
}

function servitech_upload_assert_unlocked(string $path, string $extension): void {
  $contents = servitech_upload_read_accessible_contents($path);

  if ($extension === "pdf" && servitech_upload_pdf_is_encrypted($contents)) {
    throw new DomainException("is password-protected. Please upload an unlocked PDF.");
  }

  if (in_array($extension, ["doc", "docx", "ppt", "pptx"], true) && servitech_upload_office_is_encrypted($contents)) {
    throw new DomainException("is locked or protected. Please remove file protection and try again.");
  }
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

  if (in_array($extension, ["docx", "pptx"], true) && in_array($mime, ["application/cdfv2", "application/x-ole-storage", "application/vnd.ms-office"], true)) {
    servitech_upload_assert_unlocked($path, $extension);
  }

  if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
    throw new DomainException("has invalid file content.");
  }
  servitech_upload_assert_unlocked($path, $extension);
  if ($extension === "docx" && !servitech_upload_ooxml_has_prefix($path, "word/")) {
    throw new DomainException("is not a valid DOCX document.");
  }
  if ($extension === "pptx" && !servitech_upload_ooxml_has_prefix($path, "ppt/")) {
    throw new DomainException("is not a valid PPTX presentation.");
  }

  return ["extension" => $extension, "mime_type" => $mime];
}

function servitech_document_pdf_decoded_streams(string $contents): array {
  $decodedStreams = [];
  $totalDecodedBytes = 0;
  $maximumDecodedBytes = 128 * 1024 * 1024;

  $pattern = '/(<<[\s\S]{0,8192}?\/Filter\s*(?:\/FlateDecode|\[[^\]]*\/FlateDecode[^\]]*\])[\s\S]{0,8192}?>>)\s*stream(?:\r\n|\n|\r)([\s\S]*?)(?:\r\n|\n|\r)?endstream/';
  if (!preg_match_all($pattern, $contents, $matches)) return [];

  foreach ($matches[2] as $index => $compressed) {
    $dictionary = (string)($matches[1][$index] ?? "");
    if (!preg_match('/\/Type\s*\/ObjStm\b/i', $dictionary)) continue;
    if (!is_string($compressed) || $compressed === "") continue;
    $compressed = rtrim($compressed, "\r\n");
    $remainingBytes = $maximumDecodedBytes - $totalDecodedBytes;
    if ($remainingBytes <= 0) break;

    $decoded = @gzuncompress($compressed, $remainingBytes);
    if (!is_string($decoded)) $decoded = @gzinflate($compressed, $remainingBytes);
    if (!is_string($decoded)) $decoded = @gzdecode($compressed, $remainingBytes);
    if (!is_string($decoded) || $decoded === "") continue;

    $decodedStreams[] = $decoded;
    $totalDecodedBytes += strlen($decoded);
  }

  return $decodedStreams;
}

function servitech_document_pdf_page_count_from_segment(string $segment): array {
  $leafPages = preg_match_all('/\/Type\s*\/Page\b/i', $segment, $unused);
  $treeCounts = [];
  if (preg_match_all('/<<[^<>]{0,8192}\/Type\s*\/Pages\b[^<>]{0,8192}>>/i', $segment, $dictionaries)) {
    foreach ($dictionaries[0] as $dictionary) {
      if (preg_match('/\/Count\s+(\d+)/i', $dictionary, $match)) {
        $treeCounts[] = max(0, (int)$match[1]);
      }
    }
  }

  return [
    "leaf_pages" => is_int($leafPages) ? $leafPages : 0,
    "tree_pages" => $treeCounts ? max($treeCounts) : 0,
  ];
}

function servitech_document_count_pdf_pages(string $path): int {
  $contents = @file_get_contents($path);
  if (!is_string($contents) || $contents === "") return 0;

  $structuralContents = preg_replace(
    '/stream(?:\r\n|\n|\r)[\s\S]*?(?:\r\n|\n|\r)?endstream/i',
    "stream\nendstream",
    $contents
  );
  $segments = array_merge(
    [is_string($structuralContents) ? $structuralContents : $contents],
    servitech_document_pdf_decoded_streams($contents)
  );
  $leafPages = 0;
  $treePages = 0;
  foreach ($segments as $segment) {
    $counts = servitech_document_pdf_page_count_from_segment($segment);
    $leafPages += (int)$counts["leaf_pages"];
    $treePages = max($treePages, (int)$counts["tree_pages"]);
  }

  $pages = $treePages > 0 ? $treePages : $leafPages;
  if ($pages < 1) error_log("PDF page counting failed for " . basename($path) . ": no readable PDF page tree was found.");
  return $pages;
}

function servitech_document_find_executable(array $candidates): string {
  foreach ($candidates as $candidate) {
    $candidate = trim((string)$candidate);
    if ($candidate !== "" && is_file($candidate)) return $candidate;
  }
  return "";
}

function servitech_document_soffice_path(): string {
  $configured = trim((string)getenv("SERVITECH_SOFFICE_PATH"));
  $candidates = [$configured];
  if (PHP_OS_FAMILY === "Windows") {
    $candidates[] = "C:\\Program Files\\LibreOffice\\program\\soffice.exe";
    $candidates[] = "C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe";
  } else {
    $candidates[] = "/usr/bin/libreoffice";
    $candidates[] = "/usr/bin/soffice";
    $candidates[] = "/usr/local/bin/libreoffice";
    $candidates[] = "/usr/local/bin/soffice";
  }
  return servitech_document_find_executable($candidates);
}

function servitech_document_word_path(): string {
  if (PHP_OS_FAMILY !== "Windows") return "";
  return servitech_document_find_executable([
    getenv("SERVITECH_WORD_PATH"),
    "C:\\Program Files\\Microsoft Office\\root\\Office16\\WINWORD.EXE",
    "C:\\Program Files (x86)\\Microsoft Office\\root\\Office16\\WINWORD.EXE",
  ]);
}

function servitech_document_powershell_path(): string {
  if (PHP_OS_FAMILY !== "Windows") return "";
  return servitech_document_find_executable([
    getenv("SERVITECH_POWERSHELL_PATH"),
    "C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe",
  ]);
}

function servitech_document_word_renderer_available(): bool {
  if (servitech_document_soffice_path() !== "") return true;
  $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . "scripts" . DIRECTORY_SEPARATOR . "render_office_to_pdf.ps1";
  return servitech_document_word_path() !== ""
    && servitech_document_powershell_path() !== ""
    && is_file($script);
}

function servitech_document_process_timeout_seconds(): int {
  $configured = (int)getenv("SERVITECH_DOCUMENT_RENDER_TIMEOUT_SECONDS");
  return $configured > 0 ? min(180, max(15, $configured)) : 60;
}

function servitech_document_run_process(array $command, int $timeoutSeconds): array {
  $descriptors = [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"],
  ];
  $pipes = [];
  $process = @proc_open($command, $descriptors, $pipes, null, null, ["bypass_shell" => true]);
  if (!is_resource($process)) throw new RuntimeException("Unable to start the document renderer process.");

  fclose($pipes[0]);
  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);
  $stdout = "";
  $stderr = "";
  $startedAt = microtime(true);
  $exitCode = -1;
  $timedOut = false;

  while (true) {
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    $status = proc_get_status($process);
    if (empty($status["running"])) {
      $exitCode = (int)($status["exitcode"] ?? -1);
      break;
    }
    if ((microtime(true) - $startedAt) >= $timeoutSeconds) {
      $timedOut = true;
      proc_terminate($process);
      break;
    }
    usleep(50000);
  }

  $stdout .= (string)stream_get_contents($pipes[1]);
  $stderr .= (string)stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $closeCode = proc_close($process);
  if ($exitCode < 0 && $closeCode >= 0) $exitCode = $closeCode;

  return [
    "ok" => !$timedOut && $exitCode === 0,
    "exit_code" => $exitCode,
    "timed_out" => $timedOut,
    "stdout" => $stdout,
    "stderr" => $stderr,
  ];
}

function servitech_document_remove_temp_directory(string $directory): void {
  if ($directory === "" || !is_dir($directory)) return;
  foreach (scandir($directory) ?: [] as $item) {
    if ($item === "." || $item === "..") continue;
    $path = $directory . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path) && !is_link($path)) {
      servitech_document_remove_temp_directory($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($directory);
}

function servitech_document_file_url(string $path): string {
  $normalized = str_replace("\\", "/", $path);
  $segments = array_map("rawurlencode", explode("/", ltrim($normalized, "/")));
  $encoded = implode("/", $segments);
  if (preg_match('/^[A-Za-z]%3A\//', $encoded)) $encoded = substr($encoded, 0, 1) . ":" . substr($encoded, 4);
  return "file:///" . $encoded;
}

function servitech_document_render_word_to_pdf(string $path, string $extension): array {
  if (!is_file($path) || !is_readable($path)) throw new RuntimeException("The source document is unreadable.");
  $extension = strtolower(trim($extension));
  if (!in_array($extension, ["doc", "docx"], true)) throw new RuntimeException("Unsupported rendered document type.");

  $temporaryDirectory = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . "servitech-office-" . bin2hex(random_bytes(12));
  if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    throw new RuntimeException("Unable to create the document rendering workspace.");
  }

  $sourcePath = $temporaryDirectory . DIRECTORY_SEPARATOR . "source." . $extension;
  $pdfPath = $temporaryDirectory . DIRECTORY_SEPARATOR . "source.pdf";
  if (!copy($path, $sourcePath)) {
    servitech_document_remove_temp_directory($temporaryDirectory);
    throw new RuntimeException("Unable to prepare the document for rendering.");
  }

  $attemptErrors = [];
  $soffice = servitech_document_soffice_path();
  if ($soffice !== "") {
    $profilePath = $temporaryDirectory . DIRECTORY_SEPARATOR . "libreoffice-profile";
    mkdir($profilePath, 0700, true);
    $result = servitech_document_run_process([
      $soffice,
      "--headless",
      "--nologo",
      "--nodefault",
      "--nolockcheck",
      "--nofirststartwizard",
      "-env:UserInstallation=" . servitech_document_file_url($profilePath),
      "--convert-to",
      "pdf:writer_pdf_Export",
      "--outdir",
      $temporaryDirectory,
      $sourcePath,
    ], servitech_document_process_timeout_seconds());
    if (!empty($result["ok"]) && is_file($pdfPath) && filesize($pdfPath) > 0) {
      return ["temporary_directory" => $temporaryDirectory, "pdf_path" => $pdfPath, "renderer" => "libreoffice"];
    }
    $attemptErrors[] = "LibreOffice: " . trim((string)($result["stderr"] ?: $result["stdout"] ?: "conversion failed"));
    if (is_file($pdfPath)) @unlink($pdfPath);
  }

  $wordPath = servitech_document_word_path();
  $powershell = servitech_document_powershell_path();
  $wordScript = dirname(__DIR__) . DIRECTORY_SEPARATOR . "scripts" . DIRECTORY_SEPARATOR . "render_office_to_pdf.ps1";
  if ($wordPath !== "" && $powershell !== "" && is_file($wordScript)) {
    $result = servitech_document_run_process([
      $powershell,
      "-NoLogo",
      "-NoProfile",
      "-NonInteractive",
      "-ExecutionPolicy",
      "Bypass",
      "-File",
      $wordScript,
      "-InputPath",
      $sourcePath,
      "-OutputPath",
      $pdfPath,
    ], servitech_document_process_timeout_seconds());
    if (!empty($result["ok"]) && is_file($pdfPath) && filesize($pdfPath) > 0) {
      return ["temporary_directory" => $temporaryDirectory, "pdf_path" => $pdfPath, "renderer" => "microsoft-word"];
    }
    $attemptErrors[] = "Microsoft Word: " . trim((string)($result["stderr"] ?: $result["stdout"] ?: "conversion failed"));
  }

  servitech_document_remove_temp_directory($temporaryDirectory);
  if (!$attemptErrors) {
    throw new RuntimeException("No DOC/DOCX renderer is configured. Install LibreOffice and set SERVITECH_SOFFICE_PATH.");
  }
  throw new RuntimeException(implode(" | ", $attemptErrors));
}

function servitech_document_count_word_pages(string $path, string $extension): int {
  $rendered = null;
  try {
    $rendered = servitech_document_render_word_to_pdf($path, $extension);
    $pages = servitech_document_count_pdf_pages((string)$rendered["pdf_path"]);
    if ($pages < 1) throw new RuntimeException("The rendered PDF did not contain a readable page tree.");
    return $pages;
  } catch (Throwable $e) {
    error_log("Document page rendering failed for " . basename($path) . ": " . substr($e->getMessage(), 0, 2000));
    return 0;
  } finally {
    if (is_array($rendered)) {
      servitech_document_remove_temp_directory((string)($rendered["temporary_directory"] ?? ""));
    }
  }
}

function servitech_document_count_docx_pages(string $path): int {
  return servitech_document_count_word_pages($path, "docx");
}

function servitech_document_count_doc_pages(string $path): int {
  return servitech_document_count_word_pages($path, "doc");
}

function servitech_document_count_pptx_slides(string $path): int {
  if (!class_exists("ZipArchive") || !is_file($path)) return 0;

  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return 0;
  $slides = 0;
  for ($i = 0; $i < $zip->numFiles; $i++) {
    if (preg_match('/^ppt\/slides\/slide\d+\.xml$/i', (string)$zip->getNameIndex($i))) $slides++;
  }
  $zip->close();
  if ($slides < 1) error_log("PPTX slide counting failed for " . basename($path) . ": no slide XML parts were found.");
  return $slides;
}

function servitech_document_count_ppt_slides(string $path): int {
  $contents = @file_get_contents($path);
  if (!is_string($contents) || $contents === "") return 0;

  $slides = 0;
  $searchOffset = 2;
  $contentLength = strlen($contents);
  while (($typeOffset = strpos($contents, "\xEE\x03", $searchOffset)) !== false) {
    $headerOffset = $typeOffset - 2;
    if ($headerOffset >= 0 && $typeOffset + 6 <= $contentLength) {
      $recordVersion = ord($contents[$headerOffset]) & 0x0F;
      $recordLength = unpack("Vlength", substr($contents, $typeOffset + 2, 4));
      $payloadLength = (int)($recordLength["length"] ?? 0);
      if ($recordVersion === 0x0F && $payloadLength > 0 && $payloadLength <= $contentLength - ($headerOffset + 8)) {
        $slides++;
      }
    }
    $searchOffset = $typeOffset + 2;
  }

  if ($slides < 1) error_log("PPT slide counting failed for " . basename($path) . ": no valid slide container records were found.");
  return $slides;
}

function servitech_upload_assert_rush_id_file_count(array $uploadedFiles): void {
  if (count($uploadedFiles) !== 1) {
    throw new DomainException("Rush ID accepts one image file only.");
  }
}

function servitech_upload_assert_rush_id_photo_extension(string $extension): void {
  $extension = strtolower(trim($extension));
  if ($extension === "webp") {
    throw new DomainException("Rush ID only accepts JPG, JPEG, and PNG photo files. WEBP files are not allowed.");
  }
  if (!in_array($extension, ["jpg", "jpeg", "png"], true)) {
    throw new DomainException("Rush ID only accepts photo files in JPG, JPEG, or PNG format.");
  }
}

function servitech_upload_assert_rush_id_uploaded_files(array $uploadedFiles): void {
  servitech_upload_assert_rush_id_file_count($uploadedFiles);
  foreach ($uploadedFiles as $file) {
    if (!is_array($file)) {
      throw new DomainException("An uploaded file is invalid. Please upload it again.");
    }
    servitech_upload_assert_rush_id_photo_extension((string)($file["file_type"] ?? ""));
  }
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
    "upload_purpose" => (string)($row["upload_purpose"] ?? "service_request"),
    "visibility" => (string)($row["visibility"] ?? "private"),
    "status" => (string)($row["upload_status"] ?? "active"),
    "download_url" => servitech_upload_download_path($token),
  ];
}

function servitech_upload_token_is_available(PDO $pdo, string $token): bool {
  static $availability = [];

  $token = strtolower(trim($token));
  if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
  if (array_key_exists($token, $availability)) return $availability[$token];

  $stmt = $pdo->prepare("
    SELECT storage_key
    FROM uploads
    WHERE upload_token = :upload_token
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $stmt->execute([":upload_token" => $token]);
  $storageKey = trim((string)($stmt->fetchColumn() ?: ""));
  if ($storageKey === "") {
    $availability[$token] = false;
    return false;
  }

  try {
    $availability[$token] = is_file(servitech_upload_storage_path($storageKey));
  } catch (Throwable $e) {
    $availability[$token] = false;
  }
  return $availability[$token];
}

function servitech_upload_owned_row(PDO $pdo, int $userId, string $token, bool $requireOrphan = true): array {
  $sql = "
    SELECT upload_token, user_id, queue_id, original_name, storage_key, file_extension, mime_type,
           byte_size, checksum_sha256,
           COALESCE(NULLIF(to_jsonb(uploads)->>'upload_purpose', ''), 'service_request') AS upload_purpose,
           COALESCE(NULLIF(to_jsonb(uploads)->>'visibility', ''), 'private') AS visibility,
           COALESCE(NULLIF(to_jsonb(uploads)->>'upload_status', ''), 'active') AS upload_status
    FROM uploads
    WHERE upload_token = :upload_token
      AND user_id = :user_id
      AND deleted_at IS NULL
      AND COALESCE(NULLIF(to_jsonb(uploads)->>'upload_status', ''), 'active') = 'active'
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
  servitech_upload_assert_limits($resolved);
  return $resolved;
}

function servitech_upload_apply_metadata_to_details(array $details, array $uploadedFiles): array {
  servitech_upload_assert_limits($uploadedFiles);
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

function servitech_upload_cancel_owned_orphans(PDO $pdo, int $userId, array $uploadedFiles): array {
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
  $mark = $pdo->prepare("
    UPDATE uploads
    SET deleted_at = NOW()
    WHERE upload_token = :upload_token
      AND user_id = :user_id
      AND queue_id IS NULL
      AND deleted_at IS NULL
  ");

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
      $mark->execute([":upload_token" => $token, ":user_id" => $userId]);
      if ($mark->rowCount() !== 1) continue;
      $deleted[] = $token;
    } catch (Throwable $e) {
      $errors[] = trim((string)($file["upload_token"] ?? ""));
    }
  }

  return ["deleted_tokens" => $deleted, "errors" => $errors];
}

function servitech_upload_delete_linked_files(
  PDO $pdo,
  int $userId,
  int $queueId,
  array $uploadedFiles
): array {
  $deleted = [];
  $errors = [];
  $select = $pdo->prepare("
    SELECT upload_token, storage_key
    FROM uploads
    WHERE upload_token = :upload_token
      AND user_id = :user_id
      AND queue_id = :queue_id
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $mark = $pdo->prepare("
    UPDATE uploads
    SET deleted_at = NOW()
    WHERE upload_token = :upload_token
      AND user_id = :user_id
      AND queue_id = :queue_id
      AND deleted_at IS NULL
  ");

  foreach ($uploadedFiles as $file) {
    if (!is_array($file)) continue;
    try {
      $token = servitech_upload_token_from_metadata($file);
      $select->execute([
        ":upload_token" => $token,
        ":user_id" => $userId,
        ":queue_id" => $queueId,
      ]);
      $row = $select->fetch(PDO::FETCH_ASSOC);
      if (!is_array($row)) continue;

      $path = servitech_upload_storage_path((string)$row["storage_key"]);
      if (is_file($path) && !@unlink($path)) {
        $errors[] = $token;
        continue;
      }
      $mark->execute([
        ":upload_token" => $token,
        ":user_id" => $userId,
        ":queue_id" => $queueId,
      ]);
      if ($mark->rowCount() === 1) $deleted[] = $token;
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
    try {
      $path = servitech_upload_storage_path((string)$row["storage_key"]);
      if (is_file($path) && !@unlink($path)) {
        $errors[] = $token;
        continue;
      }
      $mark->execute([":upload_token" => $token]);
      $deleted += $mark->rowCount();
    } catch (Throwable $e) {
      $errors[] = $token;
    }
  }

  return ["deleted" => $deleted, "errors" => $errors];
}

function servitech_cleanup_closed_uploads(PDO $pdo, int $retentionDays = 30): array {
  $retentionDays = max(1, $retentionDays);
  $stmt = $pdo->prepare("
    SELECT u.upload_token, u.storage_key, u.queue_id
    FROM uploads u
    INNER JOIN queues q ON q.id = u.queue_id
    WHERE u.queue_id IS NOT NULL
      AND u.deleted_at IS NULL
      AND UPPER(TRIM(COALESCE(q.status, ''))) IN (
        'DONE', 'COMPLETED', 'CANCEL', 'CANCELLED', 'CANCELED'
      )
      AND q.closed_at IS NOT NULL
      AND q.closed_at <= NOW() - (CAST(:retention_days AS INTEGER) * INTERVAL '1 day')
    ORDER BY q.closed_at ASC, u.created_at ASC
  ");
  $stmt->execute([":retention_days" => $retentionDays]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $mark = $pdo->prepare("
    UPDATE uploads
    SET deleted_at = NOW()
    WHERE upload_token = :upload_token
      AND deleted_at IS NULL
  ");
  $deleted = 0;
  $errors = [];

  foreach ($rows as $row) {
    $token = (string)$row["upload_token"];
    try {
      $path = servitech_upload_storage_path((string)$row["storage_key"]);
      if (is_file($path) && !@unlink($path)) {
        $errors[] = $token;
        continue;
      }
      $mark->execute([":upload_token" => $token]);
      $deleted += $mark->rowCount();
    } catch (Throwable $e) {
      $errors[] = $token;
    }
  }

  return ["deleted" => $deleted, "errors" => $errors];
}

function servitech_cleanup_upload_retention(
  PDO $pdo,
  int $temporaryHours = 24,
  int $closedDays = 30
): array {
  $requestStatesDeleted = servitech_upload_cleanup_request_states($temporaryHours);
  $temporary = servitech_cleanup_orphan_uploads($pdo, $temporaryHours);
  $closed = servitech_cleanup_closed_uploads($pdo, $closedDays);

  return [
    "request_states_deleted" => $requestStatesDeleted,
    "temporary_deleted" => (int)$temporary["deleted"],
    "closed_deleted" => (int)$closed["deleted"],
    "errors" => array_values(array_unique(array_merge(
      (array)$temporary["errors"],
      (array)$closed["errors"]
    ))),
  ];
}
