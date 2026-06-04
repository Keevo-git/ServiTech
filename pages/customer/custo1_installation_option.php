<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ServiTech: Installation Services</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260410d1">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260524-queue-modal">
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
            <option value="reprogram" data-min="1000" data-max="4000">Reprogram Service</option>
            <option value="hang_logo_fix" data-min="1000" data-max="3500">Hang Logo Fix Service</option>
            <option value="boot_loop_fix" data-min="1000" data-max="5000">Boot Loop Fix Service</option>
            <option value="openline" data-min="3500" data-max="6000">Openline Samsung & iPhone</option>
            <option value="bypass_google" data-min="500" data-max="2000">Bypass Google Account</option>
            <option value="bypass_password" data-min="1000" data-max="3000">Bypass Password</option>
            <option value="other_installation_request" data-price-range="Price to be assessed">Other Installation Request</option>
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


    <div class="form-actions">
      <a href="/pages/customer/custo_place_queueing.php" class="btn-back">Back</a>
      <button type="button" class="btn-next" id="joinQueueBtn">Join Queue</button>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<?php include __DIR__ . "/../../components/queue_modal.php"; ?>

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
<script src="/assets/js/main.js?v=20260604-form-state"></script>

</body>
</html>


