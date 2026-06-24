<?php
require_once __DIR__ . "/../api/upload_helpers.php";

function upload_rules_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

function upload_rules_expect_domain_exception(callable $callback, string $messagePart, string $label): void {
  try {
    $callback();
  } catch (DomainException $e) {
    upload_rules_assert(str_contains($e->getMessage(), $messagePart), $label . " returned the wrong validation message.");
    return;
  }
  upload_rules_assert(false, $label . " should have been rejected.");
}

$temporaryFiles = [];
try {
  $plainPdf = tempnam(sys_get_temp_dir(), "servitech-pdf-");
  $temporaryFiles[] = $plainPdf;
  file_put_contents($plainPdf, "%PDF-1.4\n1 0 obj << /Type /Pages /Count 2 /Kids [2 0 R 3 0 R] >> endobj\n2 0 obj << /Type /Page /Parent 1 0 R >> endobj\n3 0 obj << /Type /Page /Parent 1 0 R >> endobj\n%%EOF");
  upload_rules_assert(servitech_document_count_pdf_pages($plainPdf) === 2, "An uncompressed two-page PDF must count as two pages.");

  $objectStream = "2 0 << /Type /Pages /Count 3 /Kids [3 0 R 4 0 R 5 0 R] >> "
    . "3 0 << /Type /Page /Parent 2 0 R >> "
    . "4 0 << /Type /Page /Parent 2 0 R >> "
    . "5 0 << /Type /Page /Parent 2 0 R >>";
  $compressed = gzcompress($objectStream);
  $compressedPdf = tempnam(sys_get_temp_dir(), "servitech-pdf-");
  $temporaryFiles[] = $compressedPdf;
  file_put_contents(
    $compressedPdf,
    "%PDF-1.5\n1 0 obj\n<< /Type /ObjStm /N 4 /First 0 /Filter /FlateDecode /Length "
      . strlen($compressed) . " >>\nstream\n" . $compressed . "\nendstream\nendobj\n%%EOF"
  );
  upload_rules_assert(servitech_document_count_pdf_pages($compressedPdf) === 3, "A PDF with a compressed three-page tree must count as three pages.");
  upload_rules_assert(
    servitech_document_count_pdf_pages($plainPdf) + servitech_document_count_pdf_pages($compressedPdf) === 5,
    "Multiple Document Printing files must sum all detected pages."
  );

  $docx = tempnam(sys_get_temp_dir(), "servitech-docx-");
  $temporaryFiles[] = $docx;
  $docxZip = new ZipArchive();
  upload_rules_assert($docxZip->open($docx, ZipArchive::OVERWRITE) === true, "DOCX test archive could not be created.");
  $docxZip->addFromString("docProps/app.xml", "<Properties><Pages>4</Pages></Properties>");
  $docxZip->addFromString("word/document.xml", "<w:document><w:body><w:p>Text</w:p></w:body></w:document>");
  $docxZip->close();
  upload_rules_assert(servitech_document_count_docx_pages($docx) === 4, "DOCX page metadata must be used when available.");

  $pptx = tempnam(sys_get_temp_dir(), "servitech-pptx-");
  $temporaryFiles[] = $pptx;
  $pptxZip = new ZipArchive();
  upload_rules_assert($pptxZip->open($pptx, ZipArchive::OVERWRITE) === true, "PPTX test archive could not be created.");
  foreach ([1, 2, 3] as $slide) {
    $pptxZip->addFromString("ppt/slides/slide{$slide}.xml", "<p:sld/>");
  }
  $pptxZip->close();
  upload_rules_assert(servitech_document_count_pptx_slides($pptx) === 3, "PPTX slides must be counted from slide contents.");

  $ppt = tempnam(sys_get_temp_dir(), "servitech-ppt-");
  $temporaryFiles[] = $ppt;
  $slideRecord = "\x0F\x00\xEE\x03" . pack("V", 4) . "DATA";
  file_put_contents($ppt, "OLE" . $slideRecord . "gap" . $slideRecord . "gap" . $slideRecord);
  upload_rules_assert(servitech_document_count_ppt_slides($ppt) === 3, "Legacy PPT slide records must be counted from file contents.");

  $validPng = tempnam(sys_get_temp_dir(), "servitech-png-");
  $temporaryFiles[] = $validPng;
  file_put_contents($validPng, base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="));
  $validPngType = servitech_upload_validate_type($validPng, "photo.png");
  upload_rules_assert($validPngType["extension"] === "png" && $validPngType["mime_type"] === "image/png", "A valid PNG image must pass secure upload type validation.");
  servitech_upload_assert_rush_id_uploaded_files([["file_type" => $validPngType["extension"]]]);
  upload_rules_expect_domain_exception(
    fn() => servitech_upload_assert_rush_id_uploaded_files([["file_type" => "webp"]]),
    "WEBP files are not allowed",
    "Rush ID WEBP"
  );
  upload_rules_expect_domain_exception(
    fn() => servitech_upload_assert_rush_id_uploaded_files([["file_type" => "jpg"], ["file_type" => "png"]]),
    "one image file only",
    "Rush ID multiple files"
  );
  upload_rules_expect_domain_exception(
    fn() => servitech_upload_assert_rush_id_uploaded_files([["file_type" => "pdf"]]),
    "JPG, JPEG, or PNG",
    "Rush ID document"
  );

  $rushPage = (string)file_get_contents(__DIR__ . "/../pages/customer/custo2_rush_id.php");
  upload_rules_assert(
    preg_match('/id="fileUpload"[^>]*accept="\.jpg,\.jpeg,\.png,image\/jpeg,image\/png"(?![^>]*\bmultiple\b)/', $rushPage) === 1,
    "Rush ID Join Queue must expose a single-file JPG/JPEG/PNG input."
  );
  upload_rules_assert(str_contains($rushPage, "WEBP is not allowed"), "Rush ID Join Queue must show the WEBP restriction.");

  $printingJs = (string)file_get_contents(__DIR__ . "/../assets/js/custo2_docu_printing.js");
  upload_rules_assert(str_contains($printingJs, "qty * totalPages * pricePerPage"), "Document Printing summary must price from detected total pages.");
  upload_rules_assert(str_contains($printingJs, "total + getPageCountFromInfo(fileInfo)"), "Document Printing must aggregate page counts from every file.");
} finally {
  foreach ($temporaryFiles as $path) {
    if (is_string($path) && is_file($path)) @unlink($path);
  }
}

echo "Upload rules and page counting tests passed.\n";
