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
      <h3 class="step-title">1. SERVICE AND DETAILS</h3>

      <div class="form-grid">
        <div>
          <label for="installationTypeSelect">Select Installation Type<span class="required">*</span></label>
          <select id="installationTypeSelect" class="form-select">
            <option value="" selected disabled>Select Installation/Software Service</option>
            <option value="reprogram" data-min="1000" data-max="4000">Reprogram Service &mdash; &#8369;1000 - &#8369;4000</option>
            <option value="hang_logo_fix" data-min="1000" data-max="3500">Hang Logo Fix Service &mdash; &#8369;1000 - &#8369;3500</option>
            <option value="boot_loop_fix" data-min="1000" data-max="5000">Boot Loop Fix Service &mdash; &#8369;1000 - &#8369;5000</option>
            <option value="openline" data-min="3500" data-max="6000">Openline Samsung & iPhone &mdash; &#8369;3500 - &#8369;6000</option>
            <option value="bypass_google" data-min="500" data-max="2000">Bypass Google Account &mdash; &#8369;500 - &#8369;2000</option>
            <option value="bypass_password" data-min="1000" data-max="3000">Bypass Password &mdash; &#8369;1000 - &#8369;3000</option>
            <option value="other_installation_request">Other Installation Request &mdash; Price to be assessed</option>
          </select>

          <label for="installationNotes">Additional Information/Other Request:</label>
          <textarea id="installationNotes" class="form-textarea"></textarea>
        </div>

        <div>
          <p class="form-note">Provide as much detail as possible to help our technicians.</p>
        </div>
      </div>
    </div>

    <p id="formFeedback" class="form-feedback" role="alert" aria-live="polite"></p>

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
<script src="/assets/js/main.js?v=20260524-queue-fix"></script>

</body>
</html>


