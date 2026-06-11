<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
servitech_redirect_completed_join_queue();
require_once __DIR__ . "/../../config/db.php";

$sessionPrintDraft = $_SESSION["print_order_draft"] ?? null;
$requestedOrderType = strtolower(trim((string)($_GET["order_type"] ?? "")));
if (!in_array($requestedOrderType, ["online", "walkin"], true)) {
  $requestedOrderType = is_array($sessionPrintDraft)
    && strtolower(trim((string)($sessionPrintDraft["order_type"] ?? ""))) === "online"
      ? "online"
      : "";
}
if ($requestedOrderType === "") {
  header("Location: /pages/customer/custo1_printing_option.php");
  exit();
}
$documentPrintingLabel = $requestedOrderType === "online"
  ? "Online Document Printing"
  : "Walk-In Document Printing";
$printDraft = [];
$printPricing = [
  "long_full_price" => 10.0,
  "long_half_price" => 5.0,
  "short_full_price" => 10.0,
  "short_half_price" => 5.0,
  "a4_full_price" => 10.0,
  "a4_half_price" => 5.0,
  "a3_full_price" => 10.0,
  "a3_half_price" => 5.0,
];

function document_printing_extract_price(string $description, string $option): ?float {
  $pattern = "/\\b" . preg_quote($option, "/") . "\\s*[-\\x{2013}\\x{2014}]?\\s*₱?\\s*([0-9]+(?:\\.[0-9]+)?)/iu";
  if (preg_match($pattern, $description, $matches)) {
    return max(0, (float)$matches[1]);
  }

  return null;
}

function document_printing_extract_block_price(string $description, string $blockName, string $option): ?float {
  $blocks = preg_split("/\\r?\\n\\s*\\r?\\n/", $description) ?: [];
  foreach ($blocks as $block) {
    if (stripos($block, $blockName) === false) {
      continue;
    }

    $pattern = "/\\b" . preg_quote($option, "/") . "\\s*(?:\\/\\s*B&W)?\\s*[-\\x{2013}\\x{2014}]?\\s*\\x{20B1}?\\s*([0-9]+(?:\\.[0-9]+)?)/iu";
    if (preg_match($pattern, $block, $matches)) {
      return max(0, (float)$matches[1]);
    }
  }

  return null;
}

function document_printing_extract_price_range(string $priceRange): array {
  if (!preg_match_all("/[0-9]+(?:\\.[0-9]+)?/", $priceRange, $matches) || empty($matches[0])) {
    return [];
  }

  $prices = array_map(static fn($value) => max(0, (float)$value), $matches[0]);
  sort($prices, SORT_NUMERIC);

  return $prices;
}

try {
  $serviceStmt = $pdo->prepare("
    SELECT description, price, price_range, pricing_json::text AS pricing_json
    FROM services
    WHERE category = 'printing'
      AND LOWER(name) LIKE '%document%printing%'
      AND active = TRUE
    ORDER BY sort_order ASC, id ASC
    LIMIT 1
  ");
  $serviceStmt->execute();
  $documentPrintingService = $serviceStmt->fetch(PDO::FETCH_ASSOC);

  if (is_array($documentPrintingService)) {
    $description = (string)($documentPrintingService["description"] ?? "");
    $storedPricing = json_decode((string)($documentPrintingService["pricing_json"] ?? ""), true);
    $rangePrices = document_printing_extract_price_range((string)($documentPrintingService["price_range"] ?? ""));
    $defaultPrice = $rangePrices[0] ?? (isset($documentPrintingService["price"]) ? max(0, (float)$documentPrintingService["price"]) : 5.0);
    $halfPrice = document_printing_extract_price($description, "Half") ?? $defaultPrice;
    $fullPrice = document_printing_extract_price($description, "Full") ?? ($rangePrices[count($rangePrices) - 1] ?? max($halfPrice, $defaultPrice));

    $printPricing = [
      "long_full_price" => isset($storedPricing["longFull"]) ? (float)$storedPricing["longFull"] : (document_printing_extract_block_price($description, "Long Bond", "Full") ?? $fullPrice),
      "long_half_price" => isset($storedPricing["longHalf"]) ? (float)$storedPricing["longHalf"] : (document_printing_extract_block_price($description, "Long Bond", "Half") ?? $halfPrice),
      "short_full_price" => isset($storedPricing["shortFull"]) ? (float)$storedPricing["shortFull"] : (document_printing_extract_block_price($description, "Short Bond", "Full") ?? $fullPrice),
      "short_half_price" => isset($storedPricing["shortHalf"]) ? (float)$storedPricing["shortHalf"] : (document_printing_extract_block_price($description, "Short Bond", "Half") ?? $halfPrice),
      "a4_full_price" => isset($storedPricing["a4Full"]) ? (float)$storedPricing["a4Full"] : (document_printing_extract_block_price($description, "A4", "Full") ?? $fullPrice),
      "a4_half_price" => isset($storedPricing["a4Half"]) ? (float)$storedPricing["a4Half"] : (document_printing_extract_block_price($description, "A4", "Half") ?? $halfPrice),
      "a3_full_price" => isset($storedPricing["a3Full"]) ? (float)$storedPricing["a3Full"] : (document_printing_extract_block_price($description, "A3", "Full") ?? $fullPrice),
      "a3_half_price" => isset($storedPricing["a3Half"]) ? (float)$storedPricing["a3Half"] : (document_printing_extract_block_price($description, "A3", "Half") ?? $halfPrice),
    ];
  }
} catch (Throwable $e) {
  // Keep the order form usable if the service table is unavailable.
}

if ($requestedOrderType === "online" && is_array($sessionPrintDraft) && strtolower(trim((string)($sessionPrintDraft["order_type"] ?? ""))) === "online") {
  $printDraft = [
    "order_type" => "online",
    "paper_size" => trim((string)($sessionPrintDraft["paper_size"] ?? "")),
    "quantity" => max(1, (int)($sessionPrintDraft["quantity"] ?? 1)),
    "color_option" => trim((string)($sessionPrintDraft["color_option"] ?? "")),
    "payment_method" => strtolower(trim((string)($sessionPrintDraft["payment_method"] ?? ""))),
    "notes" => trim((string)($sessionPrintDraft["notes"] ?? "")),
    "file_name" => trim((string)($sessionPrintDraft["file_name"] ?? "")),
    "file_names" => isset($sessionPrintDraft["file_names"]) && is_array($sessionPrintDraft["file_names"]) ? array_values($sessionPrintDraft["file_names"]) : [],
    "total_files" => max(0, (int)($sessionPrintDraft["total_files"] ?? 0)),
    "total_images" => max(0, (int)($sessionPrintDraft["total_images"] ?? 0)),
    "total_pages" => max(0, (int)($sessionPrintDraft["total_pages"] ?? 0)),
    "price_per_page" => max(0, (float)($sessionPrintDraft["price_per_page"] ?? 0)),
    "estimated_total" => max(0, (float)($sessionPrintDraft["estimated_total"] ?? 0)),
    "file_analysis" => isset($sessionPrintDraft["file_analysis"]) && is_array($sessionPrintDraft["file_analysis"]) ? $sessionPrintDraft["file_analysis"] : [],
    "uploaded_files" => isset($sessionPrintDraft["uploaded_files"]) && is_array($sessionPrintDraft["uploaded_files"]) ? $sessionPrintDraft["uploaded_files"] : [],
  ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: <?= htmlspecialchars($documentPrintingLabel, ENT_QUOTES, "UTF-8") ?></title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260610fixed-header-all">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260524-queue-modal">
  <link rel="stylesheet" href="/assets/css/upload-progress.css?v=20260611-per-file-state">
  <style>
    .printing-page {
      --printing-accent: #5f0e0f;
      --printing-accent-soft: #fdf1ea;
      --printing-border: rgba(95, 14, 15, 0.14);
      --printing-surface: #ffffff;
      --printing-surface-soft: #faf7f5;
      --printing-text-soft: #646464;
      --printing-shadow: 0 14px 34px rgba(95, 14, 15, 0.06);
    }

    .printing-page .form-page-shell {
      display: grid;
      gap: 1.5rem;
      grid-template-columns: minmax(0, 1.45fr) minmax(280px, 340px);
      align-items: start;
    }

    .printing-page .form-page-intro,
    .printing-page .form-actions {
      grid-column: 1 / -1;
    }

    .printing-page .page-title {
      margin-bottom: 0.35rem;
    }

    .printing-page .page-subtitle {
      color: var(--printing-text-soft);
      margin: 0;
      max-width: 700px;
    }

    .printing-page .form-content-stack {
      display: grid;
      gap: 1rem;
    }

    .printing-page .form-card,
    .printing-page .summary-card {
      background: var(--printing-surface);
      border: 1px solid var(--printing-border);
      border-radius: 24px;
      box-shadow: var(--printing-shadow);
    }

    .printing-page .form-card {
      padding: 1.5rem;
    }

    .printing-page .summary-card {
      padding: 1.35rem;
      position: sticky;
      top: 1rem;
    }

    .printing-page .step-title,
    .printing-page .summary-title {
      color: var(--printing-accent);
      letter-spacing: 0.02em;
      margin: 0 0 0.9rem;
    }

    .printing-page .form-grid {
      display: grid;
      gap: 1.1rem;
      grid-template-columns: minmax(0, 1.25fr) minmax(220px, 0.95fr);
    }

    .printing-field {
      margin-bottom: 0.95rem;
    }

    .printing-field:last-child {
      margin-bottom: 0;
    }

    .printing-page label {
      color: #1f1f1f;
      display: block;
      font-weight: 600;
      margin-bottom: 0.45rem;
    }

    body.customer-layout.printing-page .static-text,
    .printing-page .radio-group,
    #fileAnalysisPanel {
      background: var(--printing-surface-soft);
      border: 1px solid var(--printing-border);
    }

    body.customer-layout.printing-page .static-text {
      border-radius: 16px;
      box-sizing: border-box;
      display: flex;
      align-items: center;
      min-height: 56px;
      line-height: 1.4;
      margin: 0;
      padding: 0 14px;
      width: 100%;
    }

    body.customer-layout.printing-page .service-type-display {
      display: flex;
      align-items: center;
      box-sizing: border-box;
      height: 56px;
      min-height: 56px;
      justify-content: flex-start;
      line-height: 1;
      margin: 0;
      padding: 0 14px;
    }

    body.customer-layout.printing-page .service-type-display > span {
      display: block;
      line-height: 1.1;
      transform: translateY(1px);
    }

    .printing-page .form-select,
    .printing-page .form-input,
    .printing-page .form-textarea,
    .printing-page .form-file {
      border-radius: 16px;
      min-height: 56px;
    }

    .printing-page .form-input {
      width: 100%;
    }

    .printing-page .form-textarea {
      min-height: 118px;
      resize: vertical;
    }

    .printing-page .radio-group {
      border-radius: 18px;
      display: grid;
      gap: 0.8rem;
      padding: 1rem 1.05rem;
    }

    .printing-page .radio-group label {
      align-items: center;
      display: flex;
      font-weight: 500;
      gap: 0.6rem;
      margin: 0;
    }

    #paymentSection[hidden],
    #cashPaymentNote[hidden] {
      display: none !important;
    }

    .payment-section {
      background: var(--printing-accent-soft);
      border: 1px solid rgba(95, 14, 15, 0.1);
      border-radius: 20px;
      margin: 0 0 1rem;
      padding: 1rem;
    }

    .payment-section__label {
      color: var(--printing-accent);
      display: block;
      font-size: 0.82rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      margin-bottom: 0.75rem;
      text-transform: uppercase;
    }

    .payment-section__hint,
    .printing-upload-card .file-note,
    #fileAnalysisMeta,
    .file-upload-status {
      color: var(--printing-text-soft);
    }

    .payment-section__hint {
      font-size: 0.95rem;
      margin: 0.6rem 0 0;
    }

    .printing-upload-card .form-file {
      margin-bottom: 0.45rem;
    }

    .file-upload-status {
      font-size: 0.9rem;
      margin: 0.1rem 0 0;
      min-height: 1.35rem;
    }

    #fileAnalysisPanel {
      border-radius: 18px;
      margin-top: 0.8rem;
      padding: 0.95rem 1rem;
    }

    #fileAnalysisPanel > .file-note:first-child {
      margin: 0 0 0.2rem;
    }

    #fileAnalysisPanel strong {
      color: var(--printing-accent);
      display: block;
      margin-bottom: 0.55rem;
    }

    #fileAnalysisList {
      list-style: none;
      margin: 0;
      padding: 0;
    }

    #fileAnalysisList li {
      align-items: center;
      background: #fff;
      border: 1px solid rgba(95, 14, 15, 0.08);
      border-radius: 14px;
      display: flex;
      gap: 0.75rem;
      justify-content: space-between;
      margin-bottom: 0.45rem;
      padding: 0.7rem 0.85rem;
    }

    #fileAnalysisList li:last-child {
      margin-bottom: 0;
    }

    #fileAnalysisList li span {
      flex: 1;
      min-width: 0;
      word-break: break-word;
    }

    #fileAnalysisList button {
      background: #fff7ed;
      border: 1px solid #f08a00;
      border-radius: 999px;
      color: #d9480f;
      cursor: pointer;
      flex-shrink: 0;
      font-size: 0.8rem;
      font-weight: 700;
      padding: 0.3rem 0.75rem;
    }

    #fileAnalysisList button:hover {
      background: #ffedd5;
    }

    #fileAnalysisMeta {
      margin: 0.75rem 0 0;
    }

    .printing-page .summary-title {
      margin-bottom: 0.55rem;
    }

    .printing-page .summary-row,
    .printing-page .summary-total {
      align-items: center;
      display: flex;
      gap: 1rem;
      justify-content: space-between;
      padding: 0.7rem 0;
    }

    .printing-page .summary-row {
      border-bottom: 1px solid rgba(95, 14, 15, 0.08);
    }

    .printing-page .summary-row span,
    .printing-page .summary-total span {
      color: var(--printing-text-soft);
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .printing-page .summary-row strong,
    .printing-page .summary-total strong {
      color: #1f1f1f;
      text-align: right;
    }

    .printing-page .summary-total {
      padding-top: 0.95rem;
    }

    .printing-page .summary-total strong {
      color: var(--printing-accent);
      font-size: 1.18rem;
    }

    .printing-page .summary-divider {
      display: none;
    }

    .printing-page .form-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 0;
    }

    @media (max-width: 980px) {
      .printing-page .form-page-shell {
        grid-template-columns: 1fr;
      }

      .printing-page .summary-card {
        position: static;
      }
    }

    @media (max-width: 767px) {
      .printing-page .form-card,
      .printing-page .summary-card {
        padding: 1.2rem;
      }

      .printing-page .form-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
      }

      #fileAnalysisList li,
      .printing-page .summary-row,
      .printing-page .summary-total {
        align-items: flex-start;
        flex-direction: column;
      }

      .printing-page .summary-row strong,
      .printing-page .summary-total strong {
        text-align: left;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--forms customer-page--custo2 printing-page" data-service="printing">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--single">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">JC PRINTING SERVICES</h2>
      <p class="page-subtitle">Place your print, copy, or ID photo order below.</p>
    </div>

    <div class="form-content-stack">
      <div class="form-card form-card--primary">
        <h3 class="step-title">2. ENTER DETAILS</h3>

        <div class="form-grid">
          <div>
            <div class="printing-field">
              <label>Service Type<span class="required">*</span></label>
              <div class="static-text service-type-display"><span><?= htmlspecialchars($documentPrintingLabel, ENT_QUOTES, "UTF-8") ?></span></div>
            </div>

            <input type="hidden" id="orderTypeSelect" value="<?= htmlspecialchars($requestedOrderType, ENT_QUOTES, "UTF-8") ?>">

            <div id="paymentSection" class="payment-section printing-field" hidden>
              <span class="payment-section__label">Online Order Payment</span>
              <label for="paymentMethodSelect">Payment Method<span class="required">*</span></label>
              <select class="form-select" id="paymentMethodSelect">
                <option value="" selected>Select payment method</option>
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
              </select>
              <p id="cashPaymentNote" class="payment-section__hint" hidden>You must go to the store to complete payment before printing.</p>
            </div>

            <div class="printing-field">
              <label for="paperSizeSelect">Paper Size<span class="required">*</span></label>
              <select class="form-select" id="paperSizeSelect">
                <option value="" selected>Select paper size</option>
                <option>Short Bond (8.5 x 11)</option>
                <option>Long Bond (8.5 x 13)</option>
                <option>A4</option>
                <option>A3</option>
              </select>
            </div>

            <div class="printing-field">
              <label for="qtyInput">Quantity / Copies<span class="required">*</span></label>
              <input type="number" min="1" value="1" class="form-input" id="qtyInput">
            </div>

            <div class="printing-field">
              <label for="notes">Additional Instructions / Edit Request</label>
              <textarea class="form-textarea" id="notes"></textarea>
            </div>
          </div>

          <div>
            <label>Color Option<span class="required">*</span></label>
            <div class="radio-group">
              <label><input type="radio" name="color" value="Black & White"> Black & White</label>
              <label><input type="radio" name="color" value="Colored Full"> Colored (Full)</label>
              <label><input type="radio" name="color" value="Colored Half"> Colored (Half)</label>
            </div>
          </div>
        </div>
      </div>

      <div class="form-card form-card--secondary printing-upload-card">
        <h3 class="step-title">3. UPLOAD FILES</h3>

        <div class="printing-field">
          <label for="fileUpload">Upload your document</label>
          <input type="file" id="fileUpload" class="form-file" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png" multiple>
          <p id="fileUploadStatus" class="file-upload-status" aria-live="polite"></p>
          <p class="file-note">Accepted formats: PDF, DOC, DOCX, PPT, PPTX, JPG, PNG</p>
        </div>

        <div class="file-note" id="fileAnalysisPanel">
          <p class="file-note">Use Remove to take a file out before submitting.</p>
          <strong>Uploaded Files</strong>
          <ul id="fileAnalysisList"></ul>
          <p id="fileAnalysisMeta">No files uploaded yet.</p>
        </div>
      </div>
    </div>

    <aside class="summary-card">
      <h3 class="summary-title">ORDER SUMMARY</h3>

      <div class="summary-row">
        <span>SERVICE:</span>
        <strong><?= htmlspecialchars(strtoupper($documentPrintingLabel), ENT_QUOTES, "UTF-8") ?></strong>
      </div>

      <div class="summary-row">
        <span>PAPER SIZE:</span>
        <strong id="summaryPaperSize">Not Selected</strong>
      </div>

      <div class="summary-row">
        <span>QUANTITY:</span>
        <strong id="summaryQty">1</strong>
      </div>

      <div class="summary-row">
        <span>TOTAL PAGES:</span>
        <strong id="summaryTotalPages">0</strong>
      </div>

      <div class="summary-row">
        <span>PRICE / PAGE:</span>
        <strong id="summaryPricePerPage">&#8369;0.00</strong>
      </div>

      <div class="summary-divider"></div>

      <div class="summary-total">
        <span>Estimated Total:</span>
        <strong id="summaryTotal">&#8369;0.00</strong>
      </div>
    </aside>


    <div class="form-actions">
      <a href="/pages/customer/custo1_printing_option.php" class="btn-back">Back</a>
      <button type="button" class="btn-next" id="joinQueueBtn">Join Queue</button>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<?php include __DIR__ . "/../../components/queue_modal.php"; ?>
<?php
$joinQueueBackUrl = "/pages/customer/custo1_printing_option.php";
include __DIR__ . "/../../components/join_queue_leave_guard.php";
?>

<script src="/assets/js/csrf.js"></script>
<script src="/assets/js/main.js?v=20260611-queue-success"></script>
<script src="/assets/js/upload_progress.js?v=20260611-per-file-state"></script>
<script>
  window.servitechPrintOrderDraft = <?= json_encode($printDraft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.servitechDocumentPrintPricing = <?= json_encode($printPricing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/custo2_docu_printing.js?v=20260611-file-errors-only"></script>
</body>
</html>

