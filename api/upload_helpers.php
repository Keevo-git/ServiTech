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
  if (function_exists("servitech_lifecycle_policy")) {
    $policy = servitech_lifecycle_policy();
    return (int)($policy["temporary_upload_hours"] ?? 24);
  }
  return 24;
}

function servitech_upload_closed_retention_days(): int {
  if (function_exists("servitech_lifecycle_policy")) {
    $policy = servitech_lifecycle_policy();
    return (int)($policy["closed_upload_days"] ?? 30);
  }
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
  $contentTypes = $zip->getFromName("[Content_Types].xml");
  $expectedMainType = $prefix === "word/"
    ? "application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"
    : ($prefix === "ppt/"
      ? "application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"
      : ($prefix === "xl/"
        ? "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"
        : ""));
  $requiredMainPart = $prefix === "word/"
    ? "word/document.xml"
    : ($prefix === "ppt/"
      ? "ppt/presentation.xml"
      : ($prefix === "xl/" ? "xl/workbook.xml" : ""));
  $valid = is_string($contentTypes)
    && $contentTypes !== ""
    && ($expectedMainType === "" || stripos($contentTypes, $expectedMainType) !== false)
    && ($requiredMainPart === "" || $zip->locateName($requiredMainPart) !== false);
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

function servitech_upload_is_compound_binary_file(string $path): bool {
  $handle = @fopen($path, "rb");
  if (!is_resource($handle)) return false;
  $signature = fread($handle, 8);
  fclose($handle);
  return $signature === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
}

function servitech_upload_assert_unlocked(string $path, string $extension): void {
  $contents = servitech_upload_read_accessible_contents($path);

  if ($extension === "pdf" && servitech_upload_pdf_is_encrypted($contents)) {
    throw new DomainException("is password-protected. Please upload an unlocked PDF.");
  }

  if (in_array($extension, ["doc", "docx", "ppt", "pptx", "xls", "xlsx"], true) && servitech_upload_office_is_encrypted($contents)) {
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
    "xls" => ["application/vnd.ms-excel", "application/cdfv2", "application/x-ole-storage", "application/octet-stream"],
    "docx" => ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip", "application/x-zip-compressed", "application/octet-stream"],
    "pptx" => ["application/vnd.openxmlformats-officedocument.presentationml.presentation", "application/zip", "application/x-zip-compressed", "application/octet-stream"],
    "xlsx" => ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip", "application/x-zip-compressed", "application/octet-stream"],
  ];

  if (in_array($extension, ["docx", "pptx", "xlsx"], true) && in_array($mime, ["application/cdfv2", "application/x-ole-storage", "application/vnd.ms-office"], true)) {
    servitech_upload_assert_unlocked($path, $extension);
  }

  if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
    throw new DomainException("has invalid file content.");
  }
  servitech_upload_assert_unlocked($path, $extension);
  if (in_array($extension, ["doc", "ppt", "xls"], true) && !servitech_upload_is_compound_binary_file($path)) {
    throw new DomainException("is not a valid legacy Office document.");
  }
  if ($extension === "docx" && !servitech_upload_ooxml_has_prefix($path, "word/")) {
    throw new DomainException("is not a valid DOCX document.");
  }
  if ($extension === "pptx" && !servitech_upload_ooxml_has_prefix($path, "ppt/")) {
    throw new DomainException("is not a valid PPTX presentation.");
  }
  if ($extension === "xlsx" && !servitech_upload_ooxml_has_prefix($path, "xl/")) {
    throw new DomainException("is not a valid XLSX spreadsheet.");
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

function servitech_document_text_metrics(string $text): array {
  $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, "UTF-8");
  $text = preg_replace('/\s+/u', ' ', trim($text)) ?: '';
  $words = $text === '' ? 0 : preg_match_all('/[\p{L}\p{N}]+(?:[\'\x{2019}-][\p{L}\p{N}]+)*/u', $text, $unused);
  $characters = function_exists("mb_strlen") ? mb_strlen($text, "UTF-8") : strlen($text);
  return ["words" => is_int($words) ? $words : 0, "characters" => max(0, $characters)];
}

function servitech_document_estimate_docx_pages(string $path): int {
  if (!class_exists("ZipArchive") || !is_file($path)) return 0;
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return 0;
  $documentXml = $zip->getFromName("word/document.xml");
  $appXml = $zip->getFromName("docProps/app.xml");
  $zip->close();
  if (!is_string($documentXml) || $documentXml === "") return 0;

  $metadataPages = 0;
  if (is_string($appXml) && preg_match('/<Pages>(\d+)<\/Pages>/i', $appXml, $match)) {
    $metadataPages = max(0, (int)$match[1]);
  }
  $manualBreaks = preg_match_all('/<w:br\b[^>]*w:type=["\']page["\'][^>]*\/?\s*>/i', $documentXml, $unused);
  $renderedBreaks = preg_match_all('/<w:lastRenderedPageBreak\b[^>]*\/?\s*>/i', $documentXml, $unused);
  $paragraphs = preg_match_all('/<w:p\b/i', $documentXml, $unused);
  $tableRows = preg_match_all('/<w:tr\b/i', $documentXml, $unused);
  $drawings = preg_match_all('/<(?:w:drawing|w:pict)\b/i', $documentXml, $unused);
  preg_match_all('/<w:t\b[^>]*>([\s\S]*?)<\/w:t>/i', $documentXml, $textMatches);
  $metrics = servitech_document_text_metrics(implode(' ', $textMatches[1] ?? []));

  $explicitPages = max(
    is_int($manualBreaks) ? $manualBreaks + 1 : 1,
    is_int($renderedBreaks) ? $renderedBreaks + 1 : 1
  );
  $wordPages = (int)ceil($metrics["words"] / 425);
  $characterPages = (int)ceil($metrics["characters"] / 2400);
  $layoutUnits = (is_int($paragraphs) ? $paragraphs : 0)
    + ((is_int($tableRows) ? $tableRows : 0) * 1.5)
    + ((is_int($drawings) ? $drawings : 0) * 6);
  $layoutPages = (int)ceil($layoutUnits / 32);
  $densityPages = max(1, $wordPages, $characterPages, $layoutPages);
  if ($metadataPages > 0) {
    $estimate = max($metadataPages, $explicitPages);
    if ($densityPages > max($metadataPages + 2, $metadataPages * 2)) {
      $estimate = max($estimate, $densityPages);
    }
    return $estimate;
  }
  return max(1, $explicitPages, $densityPages);
}

function servitech_document_estimate_doc_pages(string $path): int {
  $contents = @file_get_contents($path);
  if (!is_string($contents) || $contents === "") return 0;
  preg_match_all('/[\x20-\x7E]{4,}/', $contents, $asciiMatches);
  preg_match_all('/(?:[\x20-\x7E]\x00){4,}/', $contents, $unicodeMatches);
  $asciiText = implode(' ', $asciiMatches[0] ?? []);
  $unicodeParts = [];
  foreach ($unicodeMatches[0] ?? [] as $part) {
    $decoded = @iconv('UTF-16LE', 'UTF-8//IGNORE', $part);
    if (is_string($decoded)) $unicodeParts[] = $decoded;
  }
  $unicodeText = implode(' ', $unicodeParts);
  $asciiMetrics = servitech_document_text_metrics($asciiText);
  $unicodeMetrics = servitech_document_text_metrics($unicodeText);
  $words = max($asciiMetrics["words"], $unicodeMetrics["words"]);
  $characters = max($asciiMetrics["characters"], $unicodeMetrics["characters"]);
  $formFeedPages = min(substr_count($contents, "\x0C") + 1, max(1, (int)ceil(strlen($contents) / (20 * 1024))));
  $sizePages = (int)ceil(strlen($contents) / (80 * 1024));
  return max(1, $formFeedPages, (int)ceil($words / 425), (int)ceil($characters / 2400), $sizePages);
}

function servitech_document_spreadsheet_column_number(string $letters): int {
  $number = 0;
  foreach (str_split(strtoupper($letters)) as $letter) {
    if ($letter < 'A' || $letter > 'Z') continue;
    $number = ($number * 26) + (ord($letter) - 64);
  }
  return $number;
}

function servitech_document_estimate_sheet_pages(int $rows, int $columns, bool $landscape = false, int $rowBreaks = 0, int $columnBreaks = 0, int $fitWidth = 0, int $fitHeight = 0): int {
  $rows = max(1, $rows);
  $columns = max(1, $columns);
  $pagesWide = (int)ceil($columns / ($landscape ? 15 : 10));
  $pagesTall = (int)ceil($rows / ($landscape ? 38 : 45));
  if ($fitWidth > 0) $pagesWide = $fitWidth;
  if ($fitHeight > 0) $pagesTall = $fitHeight;
  return max(1, $pagesWide * $pagesTall, ($rowBreaks + 1) * ($columnBreaks + 1));
}

function servitech_document_estimate_xlsx_pages(string $path): int {
  if (!class_exists("ZipArchive") || !is_file($path)) return 0;
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return 0;
  $totalPages = 0;
  $worksheetCount = 0;
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string)$zip->getNameIndex($i);
    if (!preg_match('/^xl\/worksheets\/sheet\d+\.xml$/i', $name)) continue;
    $xml = $zip->getFromIndex($i);
    if (!is_string($xml) || $xml === "") continue;
    $worksheetCount++;
    $rows = 0;
    $columns = 0;
    if (preg_match_all('/<c\b[^>]*\br=["\']([A-Z]{1,3})(\d+)["\']/i', $xml, $cells, PREG_SET_ORDER)) {
      foreach ($cells as $cell) {
        $columns = max($columns, servitech_document_spreadsheet_column_number($cell[1]));
        $rows = max($rows, (int)$cell[2]);
      }
    }
    if ($rows < 1) {
      $rowCount = preg_match_all('/<row\b/i', $xml, $unused);
      $rows = is_int($rowCount) ? $rowCount : 0;
    }
    $landscape = preg_match('/<pageSetup\b[^>]*orientation=["\']landscape["\']/i', $xml) === 1;
    $fitToPage = preg_match('/<pageSetUpPr\b[^>]*fitToPage=["\'](?:1|true)["\']/i', $xml) === 1;
    $fitWidth = $fitToPage && preg_match('/<pageSetup\b[^>]*fitToWidth=["\'](\d+)["\']/i', $xml, $fit) ? (int)$fit[1] : 0;
    $fitHeight = $fitToPage && preg_match('/<pageSetup\b[^>]*fitToHeight=["\'](\d+)["\']/i', $xml, $fit) ? (int)$fit[1] : 0;
    $rowBreaks = 0;
    $columnBreaks = 0;
    if (preg_match('/<rowBreaks\b[\s\S]*?<\/rowBreaks>/i', $xml, $breakBlock)) {
      $count = preg_match_all('/<brk\b/i', $breakBlock[0], $unused);
      $rowBreaks = is_int($count) ? $count : 0;
    }
    if (preg_match('/<colBreaks\b[\s\S]*?<\/colBreaks>/i', $xml, $breakBlock)) {
      $count = preg_match_all('/<brk\b/i', $breakBlock[0], $unused);
      $columnBreaks = is_int($count) ? $count : 0;
    }
    $totalPages += servitech_document_estimate_sheet_pages(
      $rows,
      $columns,
      $landscape,
      $rowBreaks,
      $columnBreaks,
      $fitWidth,
      $fitHeight
    );
  }
  $zip->close();
  return $worksheetCount > 0 ? max($worksheetCount, $totalPages) : 0;
}

function servitech_document_estimate_xls_pages(string $path): int {
  $contents = @file_get_contents($path);
  if (!is_string($contents) || $contents === "") return 0;
  $totalPages = 0;
  $dimensionRecords = 0;
  $offset = 0;
  $length = strlen($contents);
  while (($recordOffset = strpos($contents, "\x00\x02", $offset)) !== false) {
    if ($recordOffset + 18 <= $length) {
      $recordLength = unpack('vlength', substr($contents, $recordOffset + 2, 2));
      $payloadLength = (int)($recordLength['length'] ?? 0);
      if ($payloadLength >= 10 && $payloadLength <= 32 && $recordOffset + 4 + $payloadLength <= $length) {
        $payload = substr($contents, $recordOffset + 4, $payloadLength);
        if ($payloadLength >= 14) {
          $values = unpack('VfirstRow/VlastRow/vfirstCol/vlastCol', substr($payload, 0, 12));
        } else {
          $values = unpack('vfirstRow/vlastRow/vfirstCol/vlastCol', substr($payload, 0, 8));
        }
        $lastRow = (int)($values['lastRow'] ?? 0);
        $lastCol = (int)($values['lastCol'] ?? 0);
        if ($lastRow > 0 && $lastRow <= 1048576 && $lastCol > 0 && $lastCol <= 16384) {
          $dimensionRecords++;
          $totalPages += servitech_document_estimate_sheet_pages($lastRow, $lastCol);
        }
      }
    }
    $offset = $recordOffset + 2;
  }
  if ($dimensionRecords > 0) return max($dimensionRecords, $totalPages);
  $sheetRecords = preg_match_all('/\x85\x00[\x08-\xFF]\x00/s', $contents, $unused);
  $sheetCount = is_int($sheetRecords) ? $sheetRecords : 0;
  return max(1, $sheetCount, (int)ceil($length / (100 * 1024)));
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
