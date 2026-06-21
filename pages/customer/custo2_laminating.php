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

$laminatingCatalogServiceId = 0;
$laminatingCatalogRules = [];
$laminatingServiceName = "Laminating";

function laminating_rule_price_attr(array $rule): string {
  if (($rule["price_type"] ?? "") === "assessment" || !isset($rule["price"]) || !is_numeric($rule["price"])) {
    return "";
  }
  return number_format(max(0, (float)$rule["price"]), 2, ".", "");
}

function laminating_rule_option_value(array $rule): string {
  return (string)($rule["option_value_keys"]["lamination_type"] ?? $rule["rule_key"] ?? "");
}

function laminating_rule_label(array $rule): string {
  $label = trim((string)($rule["option_labels"]["lamination_type"] ?? $rule["label"] ?? ""));
  return $label !== "" ? $label : "Lamination option";
}

try {
  $laminatingService = servitech_catalog_fetch_service_by_kind($pdo, "laminating", true);
  if ($laminatingService) {
    $laminatingCatalogServiceId = (int)($laminatingService["id"] ?? 0);
    $laminatingServiceName = trim((string)($laminatingService["name"] ?? "")) ?: "Laminating";
    $catalog = servitech_catalog_fetch($pdo, $laminatingCatalogServiceId, true);
    $laminatingCatalogRules = $catalog["rules"] ?? [];
  }
} catch (Throwable $e) {
  // Keep the Laminating form usable if service pricing cannot be loaded.
}
?>

<!DOCTYPE html>
<html lang="en" class="customer-order-summary-page">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: <?= htmlspecialchars($laminatingServiceName, ENT_QUOTES, "UTF-8") ?></title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260616-footer-hover">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260620-customer-form-actions">
  <link rel="stylesheet" href="/assets/css/store-availability.css?v=20260615">
</head>
<body class="customer-layout customer-page--forms customer-page--custo2 customer-page--order-summary" data-service="printing" data-service-label="<?= htmlspecialchars($laminatingServiceName, ENT_QUOTES, "UTF-8") ?>" data-catalog-service-id="<?= (int)$laminatingCatalogServiceId ?>">

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
            <p class="static-text"><?= htmlspecialchars($laminatingServiceName, ENT_QUOTES, "UTF-8") ?></p>

            <label for="lamTypeSelect">Lamination Type<span class="required">*</span></label>
            <select class="form-select" id="lamTypeSelect">
              <option value="" selected disabled>Select lamination type</option>
              <?php foreach ($laminatingCatalogRules as $rule): ?>
                <?php
                  $ruleValue = laminating_rule_option_value($rule);
                  $rulePrice = laminating_rule_price_attr($rule);
                  $ruleText = laminating_rule_label($rule);
                  $priceLabel = $rulePrice !== "" ? ("PHP " . $rulePrice) : "For assessment";
                ?>
                <option value="<?= htmlspecialchars($ruleValue, ENT_QUOTES, "UTF-8") ?>" data-rule-id="<?= (int)($rule["id"] ?? 0) ?>" data-price="<?= htmlspecialchars($rulePrice, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($ruleText . " - " . $priceLabel, ENT_QUOTES, "UTF-8") ?></option>
              <?php endforeach; ?>
              <?php if (!$laminatingCatalogRules): ?>
                <option value="" disabled>No active lamination options available</option>
              <?php endif; ?>
            </select>

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
            <input type="number" min="1" value="1" class="form-input" id="qtyInput">
            <p class="form-note">Note: Laminating price shown is an estimate per item.</p>
          </div>
        </div>
      </div>

    </div>

    <div class="order-summary-panel">
      <aside class="summary-card">
        <h3 class="summary-title">ORDER SUMMARY</h3>

        <div class="summary-row">
          <span>SERVICE:</span>
          <strong><?= htmlspecialchars(strtoupper($laminatingServiceName), ENT_QUOTES, "UTF-8") ?></strong>
        </div>

        <div class="summary-row">
          <span>TYPE/SIZE:</span>
          <strong id="summaryLamType">Not Selected</strong>
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
<script src="/assets/js/main.js?v=20260620-dynamic-catalog"></script>

</body>
</html>




