<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Place Queueing Customer</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png">
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h14">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h16">
</head>
<body class="customer-layout customer-page--queue">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page queue-join-page">
  <div class="form-page-shell queue-join-shell">
    <div class="form-page-intro queue-join-intro">
      <h2 class="page-title">Join Queue</h2>
      <p class="page-subtitle">Fill in the details to proceed with your service request.</p>
    </div>

    <form class="form-card queue-join-card" id="queueJoinForm" novalidate>
      <div class="queue-join-card__header">
        <p class="queue-join-card__eyebrow">Queue Service</p>
        <h3 class="step-title">Choose a Service</h3>
        <p class="queue-join-card__copy">Select the service you want to queue for and continue to the next step.</p>
      </div>

      <div class="queue-service-options" role="radiogroup" aria-label="Select a service to join the queue">
        <label class="queue-service-option" for="queueServicePrinting">
          <input id="queueServicePrinting" type="radio" name="queue_service" value="printing">
          <span class="queue-service-option__media">
            <img src="/assets/images/CARD_PRINTING.png" alt="" aria-hidden="true">
          </span>
          <span class="queue-service-option__title">Printing</span>
        </label>

        <label class="queue-service-option" for="queueServiceRepair">
          <input id="queueServiceRepair" type="radio" name="queue_service" value="repair">
          <span class="queue-service-option__media">
            <img src="/assets/images/CARD_REPAIR.png" alt="" aria-hidden="true">
          </span>
          <span class="queue-service-option__title">Repair</span>
        </label>

        <label class="queue-service-option" for="queueServiceInstallation">
          <input id="queueServiceInstallation" type="radio" name="queue_service" value="installation">
          <span class="queue-service-option__media">
            <img src="/assets/images/CARD_INSTALLATION.png" alt="" aria-hidden="true">
          </span>
          <span class="queue-service-option__title">Installation</span>
        </label>
      </div>

      <div class="form-actions queue-join-actions">
        <a href="/pages/customer/customer_dash.php" class="btn-back">Back to Dashboard</a>
        <button type="submit" class="btn-next queue-join-submit" id="queueJoinSubmit" disabled>Continue to Queue</button>
      </div>
    </form>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
  const queueJoinForm = document.getElementById("queueJoinForm");
  const queueJoinSubmit = document.getElementById("queueJoinSubmit");
  const queueServiceInputs = Array.from(document.querySelectorAll('input[name="queue_service"]'));

  function updateQueueJoinState() {
    const selectedService = document.querySelector('input[name="queue_service"]:checked');
    queueJoinSubmit.disabled = !selectedService;
  }

  queueServiceInputs.forEach((input) => {
    input.addEventListener("change", updateQueueJoinState);
  });

  queueJoinForm.addEventListener("submit", (event) => {
    event.preventDefault();

    const selectedService = document.querySelector('input[name="queue_service"]:checked');
    if (!selectedService) {
      const firstInput = queueServiceInputs[0];
      if (firstInput) {
        firstInput.focus();
      }
      return;
    }

    const routes = {
      printing: "/pages/customer/custo1_printing_option.php",
      repair: "/pages/customer/custo1_repair_option.php",
      installation: "/pages/customer/custo1_installation_option.php"
    };

    window.location.href = routes[selectedService.value] || "/pages/customer/custo_place_queueing.php";
  });
</script>

</body>
</html>
