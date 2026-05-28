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
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260501q1">
</head>
<body class="customer-layout customer-page--queue">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page queue-join-page">
  <div class="form-page-shell queue-join-shell">
    <div class="queue-join-intro">
      <h1 class="queue-join-page-title">Join Queue</h1>
      <p class="queue-join-page-copy">Fill in the details to proceed with your service request.</p>
    </div>

    <form class="form-card queue-join-card" id="queueJoinForm" novalidate>
      <div class="queue-join-card__header">
        <p class="queue-join-kicker">Queue Service</p>
        <h2 class="queue-join-title">Choose a Service</h2>
        <p class="queue-join-subtitle">Select the service you want to queue for and continue to the next step.</p>
      </div>

      <div class="queue-service-options" role="group" aria-label="Choose a service">
        <button type="button" class="queue-service-card" data-service="printing" aria-pressed="false">
          <span class="queue-service-card__media">
            <img src="/assets/images/CARD_PRINTING.png" alt="" aria-hidden="true">
          </span>
          <span class="queue-service-card__label">Printing</span>
        </button>

        <button type="button" class="queue-service-card" data-service="repair" aria-pressed="false">
          <span class="queue-service-card__media">
            <img src="/assets/images/CARD_REPAIR.png" alt="" aria-hidden="true">
          </span>
          <span class="queue-service-card__label">Repair</span>
        </button>

        <button type="button" class="queue-service-card" data-service="installation" aria-pressed="false">
          <span class="queue-service-card__media">
            <img src="/assets/images/CARD_INSTALLATION.png" alt="" aria-hidden="true">
          </span>
          <span class="queue-service-card__label">Installation</span>
        </button>
      </div>

      <div class="form-actions queue-join-actions">
        <a href="/pages/customer/customer_dash.php" class="btn-back">Back to Home</a>
        <button type="submit" class="btn-next queue-join-submit" id="queueJoinSubmit" disabled>Continue to Queue</button>
      </div>
    </form>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
  const queueJoinForm = document.getElementById("queueJoinForm");
  const queueJoinSubmit = document.getElementById("queueJoinSubmit");
  const queueServiceCards = Array.from(document.querySelectorAll(".queue-service-card"));
  let selectedService = "";

  function updateQueueJoinState() {
    queueJoinSubmit.disabled = !selectedService;
  }

  function setSelectedService(service) {
    selectedService = service;
    queueServiceCards.forEach((card) => {
      const isActive = card.dataset.service === service;
      card.classList.toggle("is-selected", isActive);
      card.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
    updateQueueJoinState();
  }

  queueServiceCards.forEach((card) => {
    card.addEventListener("click", () => {
      setSelectedService(card.dataset.service || "");
    });
  });

  queueJoinForm.addEventListener("submit", (event) => {
    event.preventDefault();

    if (!selectedService) {
      const firstCard = queueServiceCards[0];
      if (firstCard) {
        firstCard.focus();
      }
      return;
    }

    const routes = {
      printing: "/pages/customer/custo1_printing_option.php",
      repair: "/pages/customer/custo1_repair_option.php",
      installation: "/pages/customer/custo1_installation_option.php"
    };

    window.location.href = routes[selectedService] || "/pages/customer/custo_place_queueing.php";
  });
</script>

</body>
</html>
