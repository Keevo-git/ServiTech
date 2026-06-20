<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/store_availability.php";
require_once __DIR__ . "/../../api/service_catalog.php";
servitech_store_send_no_cache_headers();
servitech_start_new_join_queue_if_requested();
servitech_redirect_completed_join_queue();
$storeAvailability = servitech_store_current_availability($pdo);
$installationServiceId = 0;
$installationRules = [];
try {
  $installationService = servitech_catalog_fetch_service_by_kind($pdo, "installation", true);
  if (is_array($installationService)) {
    $installationServiceId = (int)$installationService["id"];
    $catalog = servitech_catalog_fetch($pdo, $installationServiceId, true);
    $installationRules = $catalog["rules"] ?? [];
  }
} catch (Throwable $e) {
  $installationRules = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ServiTech: Installation Services</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260616-footer-hover">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260620-step1-equal-actions">
  <link rel="stylesheet" href="/assets/css/store-availability.css?v=20260615">
</head>
<body class="customer-layout customer-page--forms" data-service="installation">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--single">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">JC INSTALLATION SERVICES</h2>
      <p class="page-subtitle">Place your installation order below.</p>
    </div>

    <div class="form-card">
      <h3 class="step-title">2. CHOOSE INSTALLATION SERVICE</h3>

      <div class="form-grid">
        <div>
          <label for="installationTypeSelect">Select Installation Type<span class="required">*</span></label>
          <select id="installationTypeSelect" class="form-select">
            <option value="" selected disabled>Select Installation/Software Service</option>
            <?php foreach ($installationRules as $rule):
              $label = (string)($rule["option_labels"]["installation_type"] ?? $rule["label"] ?? "Installation Type");
              $priceRange = ($rule["price_type"] ?? "") === "fixed" && isset($rule["price"]) && is_numeric($rule["price"])
                ? "PHP " . number_format((float)$rule["price"], 2)
                : "For assessment";
            ?>
              <option value="<?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?>"
                      data-catalog-id="<?= (int)$installationServiceId ?>"
                      data-rule-id="<?= (int)($rule["id"] ?? 0) ?>"
                      data-price-range="<?= htmlspecialchars($priceRange, ENT_QUOTES, "UTF-8") ?>">
                <?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <div class="service-form-price-card" aria-live="polite">
            <span class="service-form-price-card__label">Selected Service Price Range</span>
            <strong id="installationPriceRange">Choose an installation service</strong>
          </div>
        </div>
      </div>
    </div>

    <div class="form-card">
      <h3 class="step-title">3. ENTER SERVICE DETAILS</h3>

      <div class="form-grid">
        <div>
          <label for="installationNotes">Additional Information/Other Request:</label>
          <textarea id="installationNotes" class="form-textarea"></textarea>
        </div>

        <div>
          <p class="form-note">Provide as much detail as possible to help our technicians.</p>
        </div>
      </div>
    </div>


    <div class="customer-form-actions customer-step-actions">
      <a href="/pages/customer/customer_dash.php" class="btn-back">Back</a>
      <button type="button" class="btn-next btn-primary-action" id="joinQueueBtn" <?= $storeAvailability["regular_queue_allowed"] ? "" : 'disabled data-availability-locked="true"' ?>>Join Queue</button>
      <?php if (!$storeAvailability["regular_queue_allowed"]): ?><p class="queue-unavailable-note"><?= htmlspecialchars($storeAvailability["message"], ENT_QUOTES, "UTF-8") ?></p><?php endif; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<?php
$servitechJoinQueueNewRequestStarted = servitech_consume_new_join_queue_started();
include __DIR__ . "/../../components/queue_modal.php";
?>
<?php
$joinQueueBackUrl = "/pages/customer/customer_dash.php";
include __DIR__ . "/../../components/join_queue_leave_guard.php";
?>

<script>
  (function(){
    const sel = document.getElementById('installationTypeSelect');
    if (!sel) return;

    // Expose selected installation details so main.js can store correct label/meta
    window.getInstallationDetails = function(){
      const opt = sel.options[sel.selectedIndex];
      return {
        service: opt ? opt.value : '',
        min: opt && opt.dataset ? opt.dataset.min : '',
        max: opt && opt.dataset ? opt.dataset.max : '',
        notes: (document.getElementById('installationNotes')||{value:''}).value
      };
    };
  })();
</script>

<script src="/assets/js/csrf.js"></script>
<script>
  window.servitechCatalogServiceId = <?= (int)$installationServiceId ?>;
  window.servitechCatalogRules = <?= json_encode($installationRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/main.js?v=20260620-service-catalog"></script>

</body>
</html>


