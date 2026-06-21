<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
servitech_start_new_join_queue_if_requested();
servitech_redirect_completed_join_queue();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/store_availability.php";
require_once __DIR__ . "/../../api/service_catalog.php";
servitech_store_send_no_cache_headers();
$storeAvailability = servitech_store_current_availability($pdo);

$rushCatalogServiceId = 0;
$rushPackageRules = [];
$rushAddonRules = [];

try {
  $rushService = servitech_catalog_fetch_service_by_kind($pdo, "rush_id", true);

  if (is_array($rushService)) {
    $rushCatalogServiceId = (int)($rushService["id"] ?? 0);
    $catalog = servitech_catalog_fetch($pdo, $rushCatalogServiceId, true);
    foreach (($catalog["rules"] ?? []) as $rule) {
      if (!empty($rule["option_value_keys"]["package"])) $rushPackageRules[] = $rule;
      if (!empty($rule["option_value_keys"]["addon"])) $rushAddonRules[] = $rule;
    }
  }
} catch (Throwable $e) {
  // Keep the Rush ID form usable if service pricing cannot be loaded.
}
?>

<!DOCTYPE html>
<html lang="en" class="customer-order-summary-page">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Rush ID</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260621-global-ui-polish">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260621-join-form-wrap">
  <link rel="stylesheet" href="/assets/css/upload-progress.css?v=20260611-per-file-state">
  <link rel="stylesheet" href="/assets/css/store-availability.css?v=20260615">
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

    .rush-addon-list {
      display: grid;
      gap: 0.55rem;
      margin: 0.5rem 0 1rem;
    }

    body.customer-layout .form-card label.rush-addon-option {
      align-items: start;
      border: 1px solid rgba(95, 14, 15, 0.14);
      border-radius: 12px;
      box-sizing: border-box;
      cursor: pointer;
      display: grid;
      grid-template-columns: max-content minmax(0, 1fr) max-content;
      column-gap: 0.7rem;
      line-height: 1.35;
      margin: 0;
      padding: 0.75rem 0.85rem;
      width: 100%;
    }

    body.customer-layout .form-card label.rush-addon-option > input[type="checkbox"] {
      align-self: start;
      grid-column: 1;
      height: 1rem;
      margin: 0.18rem 0 0;
      width: 1rem;
    }

    body.customer-layout .form-card label.rush-addon-option > .rush-addon-copy {
      display: block;
      grid-column: 2;
      min-width: 0;
    }

    body.customer-layout .form-card label.rush-addon-option .rush-addon-copy strong {
      display: block;
      overflow-wrap: anywhere;
    }

    body.customer-layout .form-card label.rush-addon-option > .rush-addon-price {
      align-self: end;
      font-size: 0.95rem;
      font-style: italic;
      font-weight: 700;
      grid-column: 3;
      justify-self: end;
      margin-left: 0.4rem;
      min-width: 5.2rem;
      text-align: right;
      white-space: nowrap;
    }

    @media (max-width: 420px) {
      body.customer-layout .form-card label.rush-addon-option {
        column-gap: 0.6rem;
        padding: 0.7rem 0.75rem;
      }

      body.customer-layout .form-card label.rush-addon-option > .rush-addon-price {
        margin-left: 0.15rem;
        min-width: 4.75rem;
      }
    }

  </style>
</head>
<body class="customer-layout customer-page--forms customer-page--custo2 customer-page--order-summary" data-service="printing" data-service-label="Rush ID" data-catalog-service-id="<?= (int)$rushCatalogServiceId ?>">

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
              <?php foreach ($rushPackageRules as $rule):
                $price = ($rule["price_type"] ?? "") === "fixed" && isset($rule["price"]) && is_numeric($rule["price"]) ? (float)$rule["price"] : null;
                $priceLabel = $price !== null ? "&#8369;" . htmlspecialchars(number_format($price, 2), ENT_QUOTES, "UTF-8") : "For assessment";
              ?>
                <option value="<?= htmlspecialchars((string)$rule["rule_key"], ENT_QUOTES, "UTF-8") ?>"
                        data-rule-id="<?= (int)($rule["id"] ?? 0) ?>"
                        data-value-id="<?= (int)($rule["option_value_ids"]["package"] ?? 0) ?>"
                        data-value-key="<?= htmlspecialchars((string)($rule["option_value_keys"]["package"] ?? ""), ENT_QUOTES, "UTF-8") ?>"
                        data-price="<?= $price !== null ? htmlspecialchars(number_format($price, 2, ".", ""), ENT_QUOTES, "UTF-8") : "" ?>">
                  <?= htmlspecialchars((string)($rule["label"] ?? "Package"), ENT_QUOTES, "UTF-8") ?>:
                  <?= htmlspecialchars((string)($rule["description"] ?? ""), ENT_QUOTES, "UTF-8") ?>
                  &mdash; <?= $priceLabel ?>
                </option>
              <?php endforeach; ?>
              <?php if (!$rushPackageRules): ?>
                <option value="" disabled>No active Rush ID packages available</option>
              <?php endif; ?>
            </select>

            <label>Optional Add-Ons</label>
            <div class="rush-addon-list" id="rushAddonList">
              <?php foreach ($rushAddonRules as $rule):
                $addonPrice = ($rule["price_type"] ?? "") === "fixed" && isset($rule["price"]) && is_numeric($rule["price"])
                  ? (float)$rule["price"]
                  : null;
              ?>
                <label class="rush-addon-option">
                  <input type="checkbox" name="rushAddon"
                         value="<?= htmlspecialchars((string)($rule["rule_key"] ?? ""), ENT_QUOTES, "UTF-8") ?>"
                         data-rule-id="<?= (int)($rule["id"] ?? 0) ?>"
                         data-value-id="<?= (int)($rule["option_value_ids"]["addon"] ?? 0) ?>"
                         data-value-key="<?= htmlspecialchars((string)($rule["option_value_keys"]["addon"] ?? ""), ENT_QUOTES, "UTF-8") ?>"
                         data-price="<?= $addonPrice !== null ? htmlspecialchars(number_format($addonPrice, 2, ".", ""), ENT_QUOTES, "UTF-8") : "" ?>">
                  <span class="rush-addon-copy">
                    <strong><?= htmlspecialchars((string)($rule["option_labels"]["addon"] ?? $rule["label"] ?? "Add-On"), ENT_QUOTES, "UTF-8") ?></strong>
                  </span>
                  <em class="rush-addon-price"><?= $addonPrice !== null ? "&#8369;" . htmlspecialchars(number_format($addonPrice, 2), ENT_QUOTES, "UTF-8") : "For assessment" ?></em>
                </label>
              <?php endforeach; ?>
              <?php if (!$rushAddonRules): ?>
                <p class="field-hint">No add-ons are currently available.</p>
              <?php endif; ?>
            </div>

            <label for="paymentMethodSelect">Payment Method<span class="required">*</span></label>
            <select class="form-select" id="paymentMethodSelect">
              <option value="" selected disabled>Select payment method</option>
              <option value="cash">Cash</option>
              <option value="gcash">GCash</option>
            </select>

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
          <span>ADD-ONS:</span>
          <strong id="summaryAddons">None</strong>
        </div>

        <div class="summary-row">
          <span>QUANTITY:</span>
          <strong id="summaryQty">1</strong>
        </div>

        <div class="summary-row">
          <span>PAYMENT:</span>
          <strong id="summaryPayment">Not Selected</strong>
        </div>

        <div class="summary-divider"></div>

        <div class="summary-total">
          <span>Estimated Total:</span>
          <strong id="summaryTotal">&#8369;0.00</strong>
        </div>
      </aside>

      <div class="customer-form-actions is-sidebar">
        <a href="/pages/customer/custo1_printing_option.php" class="btn-back">Back</a>
        <button type="button" class="btn-next btn-primary-action" id="joinQueueBtn" <?= $storeAvailability["regular_queue_allowed"] ? "" : 'disabled data-availability-locked="true"' ?>>Join Queue</button>
        <?php if (!$storeAvailability["regular_queue_allowed"]): ?><p class="queue-unavailable-note"><?= htmlspecialchars($storeAvailability["message"], ENT_QUOTES, "UTF-8") ?></p><?php endif; ?>
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
<script>
window.servitechCatalogServiceId = <?= (int)$rushCatalogServiceId ?>;
window.servitechCatalogRules = <?= json_encode(array_merge($rushPackageRules, $rushAddonRules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/service_catalog_client.js?v=20260621-option-ids"></script>
<script src="/assets/js/main.js?v=20260621-option-ids"></script>

</body>
</html>




