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
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260424q1">
</head>
<body class="customer-layout customer-page--queue">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page queue-join-page">
  <div class="form-page-shell queue-join-shell">
    <form class="form-card queue-join-card" id="queueJoinForm" novalidate>
      <div class="queue-join-card__header">
        <h2 class="queue-join-title">CHOOSE A SERVICE</h2>
      </div>

      <div class="queue-service-options" role="group" aria-label="Choose a service">
        <button type="button" class="queue-service-card" data-service="printing" aria-pressed="false">
          <img src="/assets/images/CARD_PRINTING.png" alt="" aria-hidden="true">
          <span>PRINTING</span>
        </button>

        <button type="button" class="queue-service-card" data-service="repair" aria-pressed="false">
          <img src="/assets/images/CARD_REPAIR.png" alt="" aria-hidden="true">
          <span>REPAIR</span>
        </button>

        <button type="button" class="queue-service-card" data-service="installation" aria-pressed="false">
          <img src="/assets/images/CARD_INSTALLATION.png" alt="" aria-hidden="true">
          <span>INSTALLATION</span>
        </button>
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
