<?php
require_once __DIR__ . "/../api/upload_helpers.php";

function document_estimate_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

$samples = [
  __DIR__ . "/../documents/Office_Printer_User_Manual.docx",
  __DIR__ . "/../documents/09_Student_Internship_Application_Form.docx",
];
$availableSamples = array_values(array_filter($samples, "is_file"));
if (!$availableSamples) {
  echo "Supplied DOCX estimate test skipped because the local document fixtures are not present.\n";
  exit(0);
}

$totalEstimate = 0;
foreach ($availableSamples as $samplePath) {
  $type = servitech_upload_validate_type($samplePath, basename($samplePath));
  document_estimate_assert($type["extension"] === "docx", basename($samplePath) . " must pass secure DOCX validation.");
  $estimate = servitech_document_estimate_docx_pages($samplePath);
  document_estimate_assert($estimate > 0, basename($samplePath) . " must produce a useful page estimate.");
  $totalEstimate += $estimate;
  echo basename($samplePath) . ": estimated {$estimate} page(s)\n";
}

document_estimate_assert($totalEstimate >= count($availableSamples), "Multiple DOCX estimates must aggregate.");
echo "Supplied DOCX page estimation tests passed. Estimated total: {$totalEstimate} pages.\n";
