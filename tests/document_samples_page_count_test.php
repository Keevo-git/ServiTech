<?php
require_once __DIR__ . "/../api/upload_helpers.php";

function document_sample_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

$samples = [
  __DIR__ . "/../document/Office_Printer_User_Manual.docx" => 4,
  __DIR__ . "/../document/09_Student_Internship_Application_Form.docx" => 1,
];
$missingSamples = array_filter(array_keys($samples), fn(string $path): bool => !is_file($path));
if ($missingSamples) {
  echo "Supplied DOCX sample test skipped because the local document fixtures are not present.\n";
  exit(0);
}

document_sample_assert(servitech_document_soffice_path() !== "", "LibreOffice must be installed for unattended DOCX page counting.");
$detectedTotal = 0;

foreach ($samples as $samplePath => $expectedPages) {
  document_sample_assert(is_file($samplePath), basename($samplePath) . " is missing.");
  $type = servitech_upload_validate_type($samplePath, basename($samplePath));
  document_sample_assert($type["extension"] === "docx", basename($samplePath) . " must pass secure DOCX validation.");
  $detectedPages = servitech_document_count_docx_pages($samplePath);
  document_sample_assert($detectedPages === $expectedPages, basename($samplePath) . " must render as {$expectedPages} page(s), detected {$detectedPages}.");
  $detectedTotal += $detectedPages;
  echo basename($samplePath) . ": {$detectedPages} page(s)\n";
}

document_sample_assert($detectedTotal === 5, "The two supplied DOCX files must aggregate to five rendered pages.");
echo "Supplied DOCX sample page counting tests passed. Total: {$detectedTotal} pages.\n";
