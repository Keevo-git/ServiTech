<?php
require_once __DIR__ . "/includes/auth.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Printing Options</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include "includes/header.php"; ?>

<section class="form-page">

  <h2 class="page-title">JC PRINTING SERVICES</h2>
  <p class="page-subtitle">Place your print, copy, or ID photo order below.</p>

  <div class="form-card">
    <h3 class="step-title">1. SERVICE</h3>

    <label for="serviceType">
      Select Service Type<span class="required">*</span>
    </label>

    <select id="serviceType" class="form-select">
      <option value="" selected disabled>Select A Service</option>
      <option value="printing">Document Printing</option>
      <option value="xerox">Xerox</option>
      <option value="rush-id">Rush ID</option>
      <option value="laminating">Laminating</option>
    </select>
  </div>

  <div class="form-actions">
    <a href="custo_place_queueing.php" class="btn-back">Back</a>
    <button type="button" class="btn-next" id="nextBtn" disabled>Next</button>
  </div>

</section>

<?php include "includes/footer.php"; ?>

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
      "printing": "custo2_docu_printing.php",
      "xerox": "custo2_xerox.php",
      "rush-id": "custo2_rush_id.php",
      "laminating": "custo2_laminating.php"
    };

    window.location.href = routes[service] || "custo1_printing_option.php";
  });
</script>

</body>
</html>
