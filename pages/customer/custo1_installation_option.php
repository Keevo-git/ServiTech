<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ServiTech: Installation Services</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body data-service="installation">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page">

  <h2 class="page-title">JC INSTALLATION SERVICES</h2>
  <p class="page-subtitle">Place your installation order below.</p>

  <div class="form-card">
    <h3 class="step-title">1. SERVICE AND DETAILS</h3>

    <div class="form-grid">
      <div>
        <label for="installationTypeSelect">Select Installation Type<span class="required">*</span></label>
        <select id="installationTypeSelect" class="form-select">
          <option value="" selected disabled>Select Installation/Software Service</option>
          <option value="reprogram" data-min="1000" data-max="4000">Reprogram Service â€” â‚±1000 - â‚±4000</option>
          <option value="hang_logo_fix" data-min="1000" data-max="3500">Hang Logo Fix Service â€” â‚±1000 - â‚±3500</option>
          <option value="boot_loop_fix" data-min="1000" data-max="5000">Boot Loop Fix Service â€” â‚±1000 - â‚±5000</option>
          <option value="openline" data-min="3500" data-max="6000">Openline Samsung & iPhone â€” â‚±3500 - â‚±6000</option>
          <option value="bypass_google" data-min="500" data-max="2000">Bypass Google Account â€” â‚±500 - â‚±2000</option>
          <option value="bypass_password" data-min="1000" data-max="3000">Bypass Password â€” â‚±1000 - â‚±3000</option>
        </select><br><br>

        <label for="installationNotes">Additional Information/Other Request:</label>
        <textarea id="installationNotes" class="form-textarea"></textarea>
      </div>

      <div>
        <p style="font-size:13px;color:#555;margin-top:12px;">Provide as much detail as possible to help our technicians.</p>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <a href="/pages/customer/custo_place_queueing.php" class="btn-back">Back</a>
    <a href="#" class="btn-next" id="joinQueueBtn">Join Queue</a>
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
<script src="/assets/js/main.js"></script>

</body>
</html>



