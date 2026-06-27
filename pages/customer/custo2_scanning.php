<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
servitech_start_new_join_queue_if_requested();
servitech_redirect_completed_join_queue();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/store_availability.php";
require_once __DIR__ . "/../../config/operational_controls.php";
require_once __DIR__ . "/../../api/service_catalog.php";
servitech_store_send_no_cache_headers();
$storeAvailability = servitech_store_current_availability($pdo);
$paymentOptions = servitech_operational_customer_payment_options($pdo);

$scanningCatalogServiceId = 0;
$scanningCatalogRules = [];
$scanningServiceName = "Scanning";

try {
  $catalog = servitech_catalog_fetch_customer_catalog_by_kind($pdo, "scanning");
  if (is_array($catalog)) {
    $scanningService = $catalog["service"] ?? [];
    $scanningCatalogServiceId = (int)($scanningService["id"] ?? 0);
    $scanningServiceName = trim((string)($scanningService["name"] ?? "")) ?: "Scanning";
    $scanningCatalogRules = array_values(array_filter(
      $catalog["rules"] ?? [],
      static fn($rule) => !empty($rule["option_value_keys"]["paper_size"])
    ));
  }
} catch (Throwable $e) {
  $catalog = null;
  $scanningCatalogRules = [];
}
if (!is_array($catalog ?? null)) {
  $_SESSION["servitech_customer_toast"] = ["type" => "error", "message" => "Scanning is currently unavailable."];
  header("Location: /pages/customer/custo1_printing_option.php");
  exit;
}
servitech_operational_redirect_customer_unavailable_service($pdo, "printing", $scanningServiceName, "/pages/customer/custo1_printing_option.php", $scanningCatalogServiceId);
?>
<!DOCTYPE html>
<html lang="en" class="customer-order-summary-page">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: <?= htmlspecialchars($scanningServiceName, ENT_QUOTES, "UTF-8") ?></title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260621-global-ui-polish">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260621-join-form-wrap">
  <link rel="stylesheet" href="/assets/css/store-availability.css?v=20260615">
</head>
<body class="customer-layout customer-page--forms customer-page--custo2 customer-page--order-summary"
      data-service="printing" data-service-kind="scanning"
      data-service-label="<?= htmlspecialchars($scanningServiceName, ENT_QUOTES, "UTF-8") ?>"
      data-catalog-service-id="<?= $scanningCatalogServiceId ?>">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--single form-page--order-summary">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">JC PRINTING SERVICES</h2>
      <p class="page-subtitle">Place your scanning request below.</p>
    </div>

    <div class="form-content-stack">
      <div class="form-card form-card--primary">
        <h3 class="step-title">2. ENTER DETAILS</h3>
        <div class="form-grid">
          <div>
            <label>Service Type<span class="required">*</span></label>
            <p class="static-text"><?= htmlspecialchars($scanningServiceName, ENT_QUOTES, "UTF-8") ?></p>

            <label for="paperSizeSelect">Paper Size<span class="required">*</span></label>
            <select class="form-select" id="paperSizeSelect">
              <option value="" selected disabled>Select paper size</option>
              <?php foreach ($scanningCatalogRules as $rule):
                $label = trim((string)($rule["option_labels"]["paper_size"] ?? $rule["label"] ?? ""));
                if ($label === "") continue;
              ?>
                <option value="<?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?>"
                        data-value-id="<?= (int)($rule["option_value_ids"]["paper_size"] ?? 0) ?>"
                        data-value-key="<?= htmlspecialchars((string)$rule["option_value_keys"]["paper_size"], ENT_QUOTES, "UTF-8") ?>">
                  <?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?>
                </option>
              <?php endforeach; ?>
              <?php if (!$scanningCatalogRules): ?>
                <option value="" disabled>No active scanning paper sizes available</option>
              <?php endif; ?>
            </select>

            <label for="qtyInput">Quantity<span class="required">*</span></label>
            <input type="number" min="1" value="1" class="form-input" id="qtyInput">

            <label for="paymentMethodSelect">Payment Method<span class="required">*</span></label>
            <select class="form-select" id="paymentMethodSelect">
              <option value="" selected disabled>Select payment method</option>
              <?php foreach ($paymentOptions as $option): ?>
                <option value="<?= htmlspecialchars($option["value"], ENT_QUOTES, "UTF-8") ?>" <?= !empty($option["enabled"]) ? "" : "disabled" ?>>
                  <?= htmlspecialchars($option["label"], ENT_QUOTES, "UTF-8") ?><?= !empty($option["enabled"]) ? "" : " - unavailable" ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label for="notes">Additional Instructions</label>
            <textarea class="form-textarea" id="notes" maxlength="1000"></textarea>
          </div>
          <div>
            <p class="form-note">Scanning prices are based on the selected paper size.</p>
          </div>
        </div>
      </div>
    </div>

    <div class="order-summary-panel">
      <aside class="summary-card">
        <h3 class="summary-title">ORDER SUMMARY</h3>
        <div class="summary-row"><span>SERVICE:</span><strong><?= htmlspecialchars(strtoupper($scanningServiceName), ENT_QUOTES, "UTF-8") ?></strong></div>
        <div class="summary-row"><span>PAPER SIZE:</span><strong id="summaryPaperSize">Not Selected</strong></div>
        <div class="summary-row"><span>QUANTITY:</span><strong id="summaryQty">1</strong></div>
        <div class="summary-divider"></div>
        <div class="summary-total"><span>Estimated Total:</span><strong id="summaryTotal">PHP 0.00</strong></div>
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
window.servitechCatalogRules = <?= json_encode($scanningCatalogRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/service_catalog_client.js?v=20260621-option-ids"></script>
<script src="/assets/js/main.js?v=20260621-option-ids"></script>
</body>
</html>
