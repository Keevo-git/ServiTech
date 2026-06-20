<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
servitech_start_new_join_queue_if_requested();
servitech_redirect_completed_join_queue();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../api/service_catalog.php";

$sessionPrintDraft = $_SESSION["print_order_draft"] ?? null;
$documentPrintingLabel = "Document Print";
$printDraft = [];
$documentCatalogServiceId = 0;
$documentCatalog = null;
$documentPaperOptions = [];
$documentColorOptions = [];
$documentRules = [];

try {
  $documentPrintingService = servitech_catalog_fetch_service_by_kind($pdo, "document_printing", true);

  if (is_array($documentPrintingService)) {
    $documentCatalogServiceId = (int)($documentPrintingService["id"] ?? 0);
    $documentCatalog = servitech_catalog_fetch($pdo, $documentCatalogServiceId, true);
    foreach ($documentCatalog["groups"] as $group) {
      if (($group["group_key"] ?? "") === "paper_size") $documentPaperOptions = $group["values"] ?? [];
      if (($group["group_key"] ?? "") === "color_option") $documentColorOptions = $group["values"] ?? [];
    }
    $documentRules = $documentCatalog["rules"] ?? [];
  }
} catch (Throwable $e) {
  // Keep the order form usable if the service table is unavailable.
}

if (is_array($sessionPrintDraft)) {
  $printDraft = [
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
<html lang="en" class="customer-order-summary-page">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: <?= htmlspecialchars($documentPrintingLabel, ENT_QUOTES, "UTF-8") ?></title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260616-footer-hover">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260620-customer-form-actions">
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

    .printing-page .form-page-intro {
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
      gap: 0.75rem;
      justify-content: space-between;
      margin: 0;
      width: 100%;
    }

    .printing-page .color-option-left {
      align-items: center;
      display: flex;
      gap: 0.6rem;
      min-width: 0;
    }

    .printing-page .color-option-price {
      color: var(--printing-accent);
      flex: 0 0 auto;
      font-weight: 700;
      white-space: nowrap;
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
      min-width: 7.5rem;
    }

    .printing-page .summary-total strong.is-pending-total {
      color: var(--printing-text-soft);
      font-size: 1rem;
    }

    .printing-page .summary-divider {
      display: none;
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
<body class="customer-layout customer-page--forms customer-page--custo2 customer-page--order-summary printing-page" data-service="printing" data-catalog-service-id="<?= (int)$documentCatalogServiceId ?>">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--single form-page--order-summary">
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

            <div id="paymentSection" class="payment-section printing-field">
              <span class="payment-section__label">Document Print Payment</span>
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
                <?php foreach ($documentPaperOptions as $option): ?>
                  <option value="<?= htmlspecialchars((string)$option["label"], ENT_QUOTES, "UTF-8") ?>"
                          data-value-key="<?= htmlspecialchars((string)$option["value_key"], ENT_QUOTES, "UTF-8") ?>">
                    <?= htmlspecialchars((string)$option["label"], ENT_QUOTES, "UTF-8") ?>
                  </option>
                <?php endforeach; ?>
                <?php if (!$documentPaperOptions): ?>
                  <option value="" disabled>No active paper sizes available</option>
                <?php endif; ?>
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
              <?php foreach ($documentColorOptions as $option): ?>
                <label>
                  <span class="color-option-left">
                    <input type="radio" name="color" value="<?= htmlspecialchars((string)$option["label"], ENT_QUOTES, "UTF-8") ?>"
                           data-value-key="<?= htmlspecialchars((string)$option["value_key"], ENT_QUOTES, "UTF-8") ?>">
                    <span><?= htmlspecialchars((string)$option["label"], ENT_QUOTES, "UTF-8") ?></span>
                  </span>
                  <span class="color-option-price" data-doc-color-key="<?= htmlspecialchars((string)$option["value_key"], ENT_QUOTES, "UTF-8") ?>">Price to be confirmed</span>
                </label>
              <?php endforeach; ?>
              <?php if (!$documentColorOptions): ?>
                <p class="form-note">No active color options available.</p>
              <?php endif; ?>
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
          <p class="file-note">Accepted formats: PDF, DOC, DOCX, PPT, PPTX, JPG, PNG. Up to 5 files, 25 MB each, 100 MB total.</p>
        </div>

        <div class="file-note" id="fileAnalysisPanel">
          <p class="file-note">Use Remove to take a file out before submitting.</p>
          <strong>Uploaded Files</strong>
          <ul id="fileAnalysisList"></ul>
          <p id="fileAnalysisMeta">No files uploaded yet.</p>
        </div>
      </div>
    </div>

    <div class="order-summary-panel">
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
          <span>COLOR OPTION:</span>
          <strong id="summaryColorOption">Not Selected</strong>
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
          <strong id="summaryPricePerPage">&mdash;</strong>
        </div>

        <div class="summary-divider"></div>

        <div class="summary-total">
          <span>Estimated Total:</span>
          <strong id="summaryTotal" class="is-pending-total">&mdash;</strong>
        </div>
      </aside>

      <div class="customer-form-actions is-sidebar">
        <a href="/pages/customer/custo1_printing_option.php" class="btn-back">Back</a>
        <button type="button" class="btn-next btn-primary-action" id="joinQueueBtn">Join Queue</button>
      </div>
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
<script src="/assets/js/main.js?v=20260620-dynamic-catalog"></script>
<script src="/assets/js/upload_progress.js?v=20260612-upload-limits"></script>
<script>
  window.servitechPrintOrderDraft = <?= json_encode($printDraft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.servitechCatalogRules = <?= json_encode($documentRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/custo2_docu_printing.js?v=20260620-service-catalog"></script>
</body>
</html>
