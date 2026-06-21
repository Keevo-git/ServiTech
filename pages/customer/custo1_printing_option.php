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
$printingServices = [];
try {
  $stmt = $pdo->prepare("
    SELECT id, name
    FROM services
    WHERE category = 'printing'
      AND active = TRUE
      AND archived_at IS NULL
    ORDER BY sort_order ASC, id ASC
  ");
  $stmt->execute();
  $printingServices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $printingServices = [];
}

function printing_service_route(string $name): string {
  $label = strtolower(trim($name));
  if (str_contains($label, "document") && (str_contains($label, "print") || str_contains($label, "printing"))) return "custo2_docu_printing.php";
  if (str_contains($label, "photocopy") || str_contains($label, "xerox")) return "custo2_photocopy.php";
  if (str_contains($label, "rush") && str_contains($label, "id")) return "custo2_rush_id.php";
  if (str_contains($label, "laminat")) return "custo2_laminating.php";
  return "";
}

function printing_service_display_name(string $name): string {
  return strcasecmp(trim($name), "xerox") === 0 ? "Photocopy" : $name;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Print Options</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260616-footer-hover">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260620-step1-equal-actions">
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
        <?php foreach ($printingServices as $service):
          $route = printing_service_route((string)$service["name"]);
          if ($route === "") continue;
          $requiresRegularQueue = !str_contains(strtolower((string)$service["name"]), "document");
          $disabled = $requiresRegularQueue && !$storeAvailability["regular_queue_allowed"];
        ?>
          <option value="<?= htmlspecialchars($route, ENT_QUOTES, "UTF-8") ?>" <?= $disabled ? "disabled" : "" ?>>
            <?= htmlspecialchars(printing_service_display_name((string)$service["name"]), ENT_QUOTES, "UTF-8") ?><?= $disabled ? " - unavailable now" : "" ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!$storeAvailability["regular_queue_allowed"]): ?>
        <p class="queue-unavailable-note"><?= htmlspecialchars($storeAvailability["message"], ENT_QUOTES, "UTF-8") ?></p>
      <?php endif; ?>
    </div>

    <div class="customer-form-actions customer-step-actions">
      <a href="/pages/customer/customer_dash.php" class="btn-back">Back</a>
      <button type="button" class="btn-next btn-primary-action" id="nextBtn" disabled>Continue to Queue</button>
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
    const route = serviceSelect.value;
    if (!route) {
      alert("Please select a service first.");
      serviceSelect.focus();
      return;
    }

    window.location.href = route || "custo1_printing_option.php";
  });
</script>
<?php if (servitech_consume_new_join_queue_started()): ?>
<script>window.SERVITECH_JOIN_QUEUE_NEW_REQUEST = true;</script>
<?php endif; ?>
<script src="/assets/js/join_queue_post_success.js?v=20260619-new-request"></script>

</body>
</html>

