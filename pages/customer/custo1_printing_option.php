<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/store_availability.php";
servitech_start_new_join_queue_if_requested();
servitech_redirect_completed_join_queue();
$storeAvailability = servitech_store_current_availability($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Printing Options</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260613-footer-legal-links">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260410d1">
  <link rel="stylesheet" href="/assets/css/store-availability.css?v=20260615">
</head>
<body class="customer-layout customer-page--forms">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--single">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">JC PRINTING SERVICES</h2>
      <p class="page-subtitle">Place your print, copy, or ID photo order below.</p>
    </div>

    <div class="form-card">
      <h3 class="step-title">2. CHOOSE PRINTING SERVICE</h3>

      <label for="serviceType">
        Select Service Type<span class="required">*</span>
      </label>

      <select id="serviceType" class="form-select">
        <option value="" selected disabled>Select A Service</option>
        <option value="document-printing">Document Printing</option>
        <option value="xerox" <?= $storeAvailability["regular_queue_allowed"] ? "" : "disabled" ?>>Xerox<?= $storeAvailability["regular_queue_allowed"] ? "" : " - unavailable now" ?></option>
        <option value="rush-id" <?= $storeAvailability["regular_queue_allowed"] ? "" : "disabled" ?>>Rush ID<?= $storeAvailability["regular_queue_allowed"] ? "" : " - unavailable now" ?></option>
        <option value="laminating" <?= $storeAvailability["regular_queue_allowed"] ? "" : "disabled" ?>>Laminating<?= $storeAvailability["regular_queue_allowed"] ? "" : " - unavailable now" ?></option>
      </select>
      <?php if (!$storeAvailability["regular_queue_allowed"]): ?>
        <p class="queue-unavailable-note"><?= htmlspecialchars($storeAvailability["message"], ENT_QUOTES, "UTF-8") ?></p>
      <?php endif; ?>
    </div>

    <div class="form-actions">
      <a href="/pages/customer/customer_dash.php" class="btn-back">Back</a>
      <button type="button" class="btn-next" id="nextBtn" disabled>Next</button>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
  const serviceSelect = document.getElementById("serviceType");
  const nextBtn = document.getElementById("nextBtn");

  serviceSelect.addEventListener("change", () => {
    nextBtn.disabled = !serviceSelect.value;
  });

  nextBtn.addEventListener("click", () => {
    const service = serviceSelect.value;
    if (!service) {
      alert("Please select a service first.");
      serviceSelect.focus();
      return;
    }

    const routes = {
      "document-printing": "custo2_docu_printing.php",
      "xerox": "custo2_xerox.php",
      "rush-id": "custo2_rush_id.php",
      "laminating": "custo2_laminating.php"
    };

    window.location.href = routes[service] || "custo1_printing_option.php";
  });
</script>
<script src="/assets/js/join_queue_post_success.js?v=20260611b"></script>

</body>
</html>

