<?php
require_once __DIR__ . "/../../components/auth_guard.php";

$sessionPrintDraft = $_SESSION["print_order_draft"] ?? null;
$printDraft = [];

if (is_array($sessionPrintDraft) && strtolower(trim((string)($sessionPrintDraft["order_type"] ?? ""))) === "online") {
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
  <title>ServiTech: Document Printing</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h9">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h15">
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
    .printing-page .form-feedback,
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

    .printing-page .static-text,
    .printing-page .radio-group,
    #fileAnalysisPanel {
      background: var(--printing-surface-soft);
      border: 1px solid var(--printing-border);
    }

    .printing-page .static-text {
      border-radius: 16px;
      margin: 0;
      min-height: 56px;
      padding: 0.95rem 1rem;
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

    .printing-page .form-feedback {
      margin: 0;
    }

    .printing-page .form-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 0;
    }

    .queue-success-modal {
      width: min(100%, 420px);
    }

    .queue-success-modal__actions {
      align-items: stretch !important;
      display: grid !important;
      gap: 12px !important;
      grid-template-columns: 1fr;
    }

    .queue-success-modal__actions > * {
      flex: 0 0 auto !important;
      min-height: 48px;
      width: 100% !important;
    }

    @media (min-width: 768px) {
      .queue-success-modal__actions {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
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
              <p class="static-text">Document Printing</p>
            </div>

            <div class="printing-field">
              <label for="orderTypeSelect">Order Type<span class="required">*</span></label>
              <select class="form-select" id="orderTypeSelect">
                <option value="" selected>Select order type</option>
                <option value="walkin">Walk-in</option>
                <option value="online">Online Print Order</option>
              </select>
            </div>

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
        <strong>DOCUMENT PRINTING</strong>
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

    <p id="formFeedback" class="form-feedback" role="alert" aria-live="polite"></p>

    <div class="form-actions">
      <a href="/pages/customer/custo1_printing_option.php" class="btn-back">Back</a>
      <button type="button" class="btn-next" id="joinQueueBtn">Join Queue</button>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<?php include __DIR__ . "/../../components/queue_modal.php"; ?>

<script src="/assets/js/csrf.js"></script>
<script src="/assets/js/main.js?v=20260326c4"></script>
<script>
  window.servitechPrintOrderDraft = <?= json_encode($printDraft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/custo2_docu_printing.js?v=20260406a4"></script>
</body>
</html>

