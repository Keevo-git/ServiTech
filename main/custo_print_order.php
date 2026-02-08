\
<?php
require_once __DIR__ . "/includes/auth.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Print Order</title>
  <link rel="stylesheet" href="style.css">
</head>
<body data-service="printing">

<?php include "includes/header.php"; ?>

<section class="form-page">
  <h2 class="page-title">ONLINE PRINT ORDER</h2>
  <p class="page-subtitle">Upload your file and we'll prepare it for pickup.</p>

  <div class="form-card">
    <h3 class="step-title">ORDER DETAILS</h3>

    <label>Paper Size<span class="required">*</span></label>
    <select class="form-select" id="paperSizeSelect">
      <option value="" selected disabled>Select paper size</option>
      <option>Short Bond (8.5 x 11)</option>
      <option>Long Bond (8.5 x 13)</option>
      <option>A4</option>
      <option>A3</option>
    </select>

    <label>Quantity (Number of Copies)<span class="required">*</span></label>
    <input type="number" min="1" value="1" class="form-input" id="qtyInput">

    <label>Color Option<span class="required">*</span></label>
    <div class="radio-group">
      <label><input type="radio" name="color" value="Black & White"> Black & White</label>
      <label><input type="radio" name="color" value="Colored - Full"> Colored (Full)</label>
      <label><input type="radio" name="color" value="Colored - Half"> Colored (Half)</label>
      <label><input type="radio" name="color" value="Colored - N/A"> Colored (N/A)</label>
    </div>

    <label>Additional Instructions</label>
    <textarea class="form-textarea" id="notes"></textarea>

    <label for="fileUpload">Upload your document</label>
    <input type="file" id="fileUpload" class="form-file">
    <p class="file-note">Accepted formats: PDF, DOC, DOCX, JPG, PNG (name only is saved for now)</p>
  </div>

  <div class="form-card">
    <h3>Pick-up & Payment</h3>
    <p>Pick-up: In-store only</p>

    <label>Payment Method<span class="required">*</span></label>
    <div class="radio-group">
      <label><input type="radio" name="payment" value="Cash"> Cash</label>
      <label><input type="radio" name="payment" value="GCash"> GCash</label>
    </div>
  </div>

  <div class="form-actions">
    <a href="customer_dash.php" class="btn-back">Back</a>
    <button type="button" class="btn-next" id="submitPrintOrderBtn">Proceed</button>
  </div>
</section>

<?php include "includes/footer.php"; ?>

<script>
function getRadio(name){
  const r = document.querySelector('input[name="'+name+'"]:checked');
  return r ? r.value : "";
}

document.getElementById("submitPrintOrderBtn")?.addEventListener("click", async () => {
  const paper = document.getElementById("paperSizeSelect")?.value || "";
  const qty = parseInt(document.getElementById("qtyInput")?.value || "1", 10) || 1;
  const color = getRadio("color");
  const payment = getRadio("payment");
  const notes = document.getElementById("notes")?.value || "";
  const fileUpload = document.getElementById("fileUpload");
  const fileName = (fileUpload && fileUpload.files && fileUpload.files[0]) ? fileUpload.files[0].name : "";

  if (!paper || !color || !payment) {
    alert("Please complete all required fields.");
    return;
  }

  const payload = {
    category: "printing",
    service_label: "Online Print Order",
    paper_size: paper,
    quantity: qty,
    color_option: color,
    notes: (notes ? notes : null),
    file_name: (fileName ? fileName : null),
    // store payment method in details too
    package_label: payment
  };

  const res = await fetch("queue_create.php", {
    method: "POST",
    credentials: "same-origin",
    headers: {"Content-Type":"application/json","Accept":"application/json","X-Requested-With":"XMLHttpRequest"},
    body: JSON.stringify(payload)
  });

  const raw = await res.text();
  let data;
  try { data = JSON.parse(raw); } catch(e){ console.error(raw); alert("Server error (non-JSON)."); return; }

  if (!data.ok) { alert("Failed: " + (data.error || "Unknown")); return; }

  // pass queue code to payment/confirmation page
  window.location.href = "custo_print_order_payment.php?queue=" + encodeURIComponent(data.queue_code);
});
</script>

</body>
</html>
