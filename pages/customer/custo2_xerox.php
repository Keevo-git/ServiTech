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

$xeroxCatalogServiceId = 0;
$xeroxCatalogRules = [];
$xeroxPaperOptions = [];
$xeroxColorOptions = [];

try {
  $catalog = servitech_catalog_fetch_customer_catalog_by_kind($pdo, "photocopy");
  if (is_array($catalog)) {
    $xeroxService = $catalog["service"] ?? [];
    $xeroxCatalogServiceId = (int)($xeroxService["id"] ?? 0);
    foreach ($catalog["groups"] as $group) {
      if (($group["group_key"] ?? "") === "paper_size") $xeroxPaperOptions = $group["values"] ?? [];
      if (($group["group_key"] ?? "") === "color_option") $xeroxColorOptions = $group["values"] ?? [];
    }
    $xeroxCatalogRules = $catalog["rules"] ?? [];
    $xeroxPaperOptions = servitech_catalog_values_used_by_rules($xeroxPaperOptions, $xeroxCatalogRules, "paper_size");
    $xeroxColorOptions = servitech_catalog_values_used_by_rules($xeroxColorOptions, $xeroxCatalogRules, "color_option");
  }
} catch (Throwable $e) {
  $catalog = null;
}
if (!is_array($catalog ?? null)) {
  $_SESSION["servitech_customer_toast"] = ["type" => "error", "message" => "Photocopy is currently unavailable."];
  header("Location: /pages/customer/custo1_printing_option.php");
  exit;
}

?>

<!DOCTYPE html>
<html lang="en" class="customer-order-summary-page">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Photocopy</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260621-global-ui-polish">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260621-join-form-wrap">
  <link rel="stylesheet" href="/assets/css/store-availability.css?v=20260615">
</head>
<body class="customer-layout customer-page--forms customer-page--custo2 customer-page--order-summary" data-service="printing" data-service-kind="photocopy" data-service-label="Photocopy" data-catalog-service-id="<?= (int)$xeroxCatalogServiceId ?>">

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
            <p class="static-text">Photocopy</p>

            <label for="paperSizeSelect">Paper Size<span class="required">*</span></label>
              <select class="form-select" id="paperSizeSelect">
                <option value="" selected disabled>Select paper size</option>
                <?php foreach ($xeroxPaperOptions as $option): ?>
                  <option value="<?= htmlspecialchars((string)$option["label"], ENT_QUOTES, "UTF-8") ?>"
                          data-value-id="<?= (int)($option["id"] ?? 0) ?>"
                          data-value-key="<?= htmlspecialchars((string)$option["value_key"], ENT_QUOTES, "UTF-8") ?>">
                    <?= htmlspecialchars((string)$option["label"], ENT_QUOTES, "UTF-8") ?>
                  </option>
                <?php endforeach; ?>
                <?php if (!$xeroxPaperOptions): ?>
                  <option value="" disabled>No active paper sizes available</option>
                <?php endif; ?>
              </select>

            <label>Color Option<span class="required">*</span></label>
            <div class="radio-group">
              <?php foreach ($xeroxColorOptions as $option): ?>
                <label>
                  <input type="radio" name="color"
                         value="<?= htmlspecialchars((string)$option["label"], ENT_QUOTES, "UTF-8") ?>"
                         data-value-id="<?= (int)($option["id"] ?? 0) ?>"
                         data-value-key="<?= htmlspecialchars((string)$option["value_key"], ENT_QUOTES, "UTF-8") ?>">
                  <?= htmlspecialchars((string)$option["label"], ENT_QUOTES, "UTF-8") ?>
                </label>
              <?php endforeach; ?>
              <?php if (!$xeroxColorOptions): ?>
                <p class="form-note">No active color options available.</p>
              <?php endif; ?>
            </div>

            <label for="qtyInput">Quantity / Copies<span class="required">*</span></label>
            <input type="number" min="1" value="1" class="form-input" id="qtyInput">

            <label for="paymentMethodSelect">Payment Method<span class="required">*</span></label>
            <select class="form-select" id="paymentMethodSelect">
              <option value="" selected disabled>Select payment method</option>
              <option value="cash">Cash</option>
              <option value="gcash">GCash</option>
            </select>

            <label for="notes">Additional Instructions / Edit Request</label>
            <textarea class="form-textarea" id="notes" maxlength="1000"></textarea>
          </div>

          <div>
            <p class="form-note">
              Photocopy pricing is based on paper size and color option.
            </p>
          </div>
        </div>
      </div>

    </div>

    <div class="order-summary-panel">
      <aside class="summary-card">
        <h3 class="summary-title">ORDER SUMMARY</h3>

        <div class="summary-row">
          <span>SERVICE:</span>
          <strong>PHOTOCOPY</strong>
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
<script>
window.servitechCatalogRules = <?= json_encode($xeroxCatalogRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/service_catalog_client.js?v=20260621-option-ids"></script>
<script src="/assets/js/main.js?v=20260621-option-ids"></script>

</body>
</html>

