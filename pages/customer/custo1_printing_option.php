<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
servitech_redirect_completed_join_queue();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Printing Options</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260610fixed-header-all">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260410d1">
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
        <option value="online-document-printing">Online Document Printing</option>
        <option value="walkin-document-printing">Walk-In Document Printing</option>
        <option value="xerox">Xerox</option>
        <option value="rush-id">Rush ID</option>
        <option value="laminating">Laminating</option>
      </select>
    </div>

    <div class="form-actions">
      <a href="/pages/customer/custo_place_queueing.php" class="btn-back">Back</a>
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
      "online-document-printing": "custo2_docu_printing.php?order_type=online",
      "walkin-document-printing": "custo2_docu_printing.php?order_type=walkin",
      "xerox": "custo2_xerox.php",
      "rush-id": "custo2_rush_id.php",
      "laminating": "custo2_laminating.php"
    };

    window.location.href = routes[service] || "custo1_printing_option.php";
  });
</script>

</body>
</html>

