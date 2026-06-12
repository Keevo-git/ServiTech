<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
servitech_redirect_completed_join_queue();
require_once __DIR__ . "/../../config/db.php";

$rushPricing = [
  "package1" => 40.0,
  "package2" => 30.0,
  "package3" => 30.0,
  "package4" => 50.0,
  "package5" => 30.0,
  "package6" => 50.0,
];

try {
  $rushStmt = $pdo->prepare("
    SELECT price, pricing_json::text AS pricing_json
    FROM services
    WHERE category = 'printing'
      AND LOWER(name) LIKE '%rush%'
      AND LOWER(name) LIKE '%id%'
      AND active = TRUE
    ORDER BY sort_order ASC, id ASC
    LIMIT 1
  ");
  $rushStmt->execute();
  $rushService = $rushStmt->fetch(PDO::FETCH_ASSOC);

  if (is_array($rushService)) {
    $storedPricing = json_decode((string)($rushService["pricing_json"] ?? ""), true);
    if (is_array($storedPricing)) {
      foreach ($rushPricing as $key => $fallback) {
        if (isset($storedPricing[$key]) && is_numeric($storedPricing[$key])) {
          $rushPricing[$key] = max(0, (float)$storedPricing[$key]);
        }
      }
    }
  }
} catch (Throwable $e) {
  // Keep the Rush ID form usable if service pricing cannot be loaded.
}

function rush_price(array $pricing, string $key): string {
  return number_format((float)($pricing[$key] ?? 0), 2, ".", "");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Rush ID</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260612header-brand-hit-area">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260612-sticky-order-summary">
  <link rel="stylesheet" href="/assets/css/upload-progress.css?v=20260611-per-file-state">
  <style>
    .printing-upload-card .form-file {
      margin-bottom: 0.45rem;
    }

    .file-upload-status {
      color: #646464;
      font-size: 0.9rem;
      margin: 0.1rem 0 0;
      min-height: 1.35rem;
    }

    #fileAnalysisPanel {
      background: #faf7f5;
      border: 1px solid rgba(95, 14, 15, 0.14);
      border-radius: 18px;
      margin-top: 0.8rem;
      padding: 0.95rem 1rem;
    }

    #fileAnalysisPanel > .file-note:first-child {
      margin: 0 0 0.2rem;
    }

    #fileAnalysisPanel strong {
      color: #5f0e0f;
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

    body.customer-page--custo2 .form-actions {
      align-items: stretch !important;
      display: flex !important;
      flex-direction: column !important;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin: 8px auto 0;
      max-width: 860px;
      width: 100%;
    }

    body.customer-page--custo2 .form-actions .btn-back,
    body.customer-page--custo2 .form-actions .btn-next {
      align-items: center !important;
      border-radius: 10px !important;
      box-sizing: border-box;
      display: inline-flex !important;
      flex: 0 0 auto !important;
      font-size: 16px;
      font-weight: 600;
      height: auto !important;
      justify-content: center !important;
      line-height: 1.2;
      min-height: 52px !important;
      padding: 14px 24px !important;
      text-align: center !important;
      text-decoration: none !important;
      width: 100% !important;
    }

    body.customer-page--custo2 .form-actions .btn-back {
      background: #000 !important;
      border: 1px solid #000 !important;
      color: #fff !important;
    }

    body.customer-page--custo2 .form-actions .btn-next {
      background: #fbbf24 !important;
      border: 1px solid #000 !important;
      color: #000 !important;
    }

    body.customer-page--custo2 .form-actions .btn-back:hover,
    body.customer-page--custo2 .form-actions .btn-next:hover {
      transform: translateY(-2px);
    }

    @media (max-width: 720px) {
      body.customer-page--custo2 .form-actions .btn-back,
      body.customer-page--custo2 .form-actions .btn-next {
        flex: 0 0 auto;
        width: 100%;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--forms customer-page--custo2" data-service="printing" data-service-label="Rush ID">

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
            <label>Service Type<span class="required">*</span></label>
            <p class="static-text">Rush ID</p>

            <label for="packageSelect">Select Package<span class="required">*</span></label>
            <select id="packageSelect" class="form-select">
              <option value="" selected disabled>Select a Package</option>
              <option value="package1" data-price="<?= rush_price($rushPricing, "package1") ?>">Package 1: 1x1 (4pcs.), 2x2 (2pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package1") ?></option>
              <option value="package2" data-price="<?= rush_price($rushPricing, "package2") ?>">Package 2: 1x1 (6pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package2") ?></option>
              <option value="package3" data-price="<?= rush_price($rushPricing, "package3") ?>">Package 3: 2x2 (4pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package3") ?></option>
              <option value="package4" data-price="<?= rush_price($rushPricing, "package4") ?>">Package 4: 2x2 (4pcs.), 1x1 (4pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package4") ?></option>
              <option value="package5" data-price="<?= rush_price($rushPricing, "package5") ?>">Package 5: Passport size (4pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package5") ?></option>
              <option value="package6" data-price="<?= rush_price($rushPricing, "package6") ?>">Package 6: 1x1 (10pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package6") ?></option>
            </select>

            <div class="two-col-fields">
              <div>
                <label>Additional Edit 1</label>
                <p class="field-hint">Provide the name in the additional instructions box</p>
                <div class="radio-vertical">
                  <label><input type="radio" name="edit1"> With name</label>
                  <label><input type="radio" name="edit1"> With no name</label>
                </div>
              </div>

              <div>
                <label>Additional Edit 2</label>
                <p class="field-hint">Staffs will edit your picture to be in formal attire</p>
                <div class="radio-vertical">
                  <label><input type="radio" name="edit2"> Formal Attire</label>
                  <label><input type="radio" name="edit2"> No Formal Attire</label>
                </div>
              </div>
            </div>

            <label for="notes">Additional Instructions / Edit Request</label>
            <textarea class="form-textarea" id="notes"></textarea>
          </div>

          <div>
            <label for="qtyInput">Quantity / Copies<span class="required">*</span></label>
            <input type="number" min="1" value="1" id="qtyInput" class="form-input">
          </div>
        </div>
      </div>

      <div class="form-card form-card--secondary printing-upload-card">
        <h3 class="step-title">3. UPLOAD FILES</h3>

        <div class="printing-field">
          <label for="fileUpload">Upload your photo</label>
          <input type="file" id="fileUpload" class="form-file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" multiple>
          <p id="fileUploadStatus" class="file-upload-status" aria-live="polite"></p>
          <p class="file-note">Accepted formats: JPG, JPEG, PNG. Up to 5 photos, 25 MB each, 100 MB total.</p>
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
          <strong>RUSH ID</strong>
        </div>

        <div class="summary-row">
          <span>PACKAGE:</span>
          <strong id="summaryPackage">Not Selected</strong>
        </div>

        <div class="summary-row">
          <span>QUANTITY:</span>
          <strong id="summaryQty">1</strong>
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
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<?php include __DIR__ . "/../../components/queue_modal.php"; ?>
<?php
$joinQueueBackUrl = "/pages/customer/custo1_printing_option.php";
include __DIR__ . "/../../components/join_queue_leave_guard.php";
?>

<script src="/assets/js/csrf.js"></script>
<script src="/assets/js/upload_progress.js?v=20260612-upload-limits"></script>
<script src="/assets/js/rush_id_upload.js?v=20260612-upload-limits"></script>
<script src="/assets/js/main.js?v=20260611-queue-success"></script>

</body>
</html>



