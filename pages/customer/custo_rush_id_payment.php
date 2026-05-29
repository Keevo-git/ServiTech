<?php
require_once __DIR__ . "/../../components/auth_guard.php";

function esc_rush_payment($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function rush_payment_money($amount): string {
  return "&#8369;" . number_format((float)$amount, 2);
}

$queue = trim((string)($_GET["queue"] ?? ""));
$confirmation = $_SESSION["rush_id_confirmation"] ?? null;
$flashError = trim((string)($_SESSION["rush_id_flash_error"] ?? ""));
$formState = is_array($_SESSION["rush_id_form"] ?? null) ? $_SESSION["rush_id_form"] : [];
unset($_SESSION["rush_id_flash_error"], $_SESSION["rush_id_form"]);

$isConfirmed = $queue !== "" && is_array($confirmation) && trim((string)($confirmation["queue_code"] ?? "")) === $queue;
$draft = $_SESSION["rush_id_draft"] ?? null;
if (!$isConfirmed && !is_array($draft)) {
  header("Location: /pages/customer/custo2_rush_id.php");
  exit();
}

$draft = is_array($draft) ? $draft : [];
$referenceNumber = trim((string)($formState["reference_number"] ?? ""));
$fileNames = isset($draft["file_names"]) && is_array($draft["file_names"]) ? $draft["file_names"] : [];
if (empty($fileNames) && !empty($draft["file_name"])) {
  $fileNames = [(string)$draft["file_name"]];
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Rush ID Payment</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260410d1">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260410d1">
  <style>
    .rush-payment-page .form-page-shell,
    .rush-payment-form {
      display: grid;
      gap: 1rem;
      margin: 0 auto;
      max-width: 860px;
      width: 100%;
    }

    .rush-payment-card {
      display: grid;
      gap: 1rem;
      margin: 0 !important;
      width: 100% !important;
    }

    .rush-payment-block,
    .rush-payment-box {
      background: #fff;
      border: 1px solid rgba(95, 14, 15, 0.14);
      border-radius: 18px;
      padding: 1rem;
    }

    .rush-payment-title {
      color: #5f0e0f;
      font-weight: 800;
      margin: 0 0 0.55rem;
    }

    .rush-payment-estimate strong {
      color: #431112;
      display: block;
      font-size: 2rem;
      line-height: 1.05;
    }

    .rush-payment-details {
      display: grid;
      gap: 0.35rem;
      margin: 0;
    }

    .rush-payment-details p {
      margin: 0;
    }

    .rush-payment-files {
      display: grid;
      gap: 0.45rem;
      list-style: none;
      margin: 0.45rem 0 0;
      padding: 0;
    }

    .rush-payment-files li {
      background: #fffdfb;
      border: 1px solid rgba(95, 14, 15, 0.14);
      border-radius: 13px;
      color: #4f1717;
      font-weight: 700;
      padding: 0.7rem 0.85rem;
      overflow-wrap: anywhere;
    }

    .rush-payment-qr {
      align-items: center;
      display: grid;
      gap: 1.1rem;
      grid-template-columns: minmax(180px, 220px) minmax(0, 1fr);
    }

    .rush-payment-qr-box {
      align-items: center;
      border: 2px solid #5f0e0f;
      border-radius: 14px;
      display: flex;
      justify-content: center;
      min-height: 190px;
      padding: 0.75rem;
      width: min(100%, 220px);
    }

    .rush-payment-qr-box img {
      display: block;
      height: auto;
      max-width: 220px;
      width: 100%;
    }

    .rush-payment-reference {
      display: grid;
      gap: 0.45rem;
    }

    .rush-payment-note {
      color: #775d58;
      font-size: 0.92rem;
      margin: 0;
    }

    .rush-payment-page .form-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 0;
      width: 100%;
    }

    .rush-payment-page .form-actions .btn-back,
    .rush-payment-page .form-actions .btn-next {
      align-items: center;
      border-radius: 10px;
      display: inline-flex;
      flex: 1 1 240px;
      font-size: 16px;
      font-weight: 600;
      justify-content: center;
      line-height: 1.2;
      min-height: 48px;
      padding: 13px 22px;
      text-align: center;
      width: 100%;
    }

    @media (max-width: 860px) {
      .rush-payment-qr {
        grid-template-columns: 1fr;
      }

      .rush-payment-qr-box {
        justify-self: center;
      }

      .rush-payment-page .form-actions {
        flex-direction: column;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--forms rush-payment-page" data-confirmed-queue="<?= esc_rush_payment($isConfirmed ? $queue : "") ?>">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--confirmation">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">RUSH ID PAYMENT</h2>
      <p class="page-subtitle">Review your Rush ID order, scan the GCash QR, and submit your reference number for verification.</p>
    </div>

    <form id="rushIdPaymentForm" class="rush-payment-form" method="post" action="/api/rush_id_create.php" novalidate>
      <input type="hidden" name="csrf_token" value="<?= esc_rush_payment($_SESSION["csrf_token"] ?? "") ?>">
      <div class="form-card rush-payment-card">
        <div class="rush-payment-block rush-payment-estimate">
          <p class="rush-payment-title">Estimated Price</p>
          <strong><?= rush_payment_money((float)($draft["estimated_total"] ?? 0)) ?></strong>
        </div>

        <div class="rush-payment-block">
          <p class="rush-payment-title">Rush ID Details</p>
          <div class="rush-payment-details">
            <p><strong>Package:</strong> <?= esc_rush_payment($draft["package_label"] ?? "-") ?></p>
            <p><strong>Quantity/Copies:</strong> <?= esc_rush_payment((string)($draft["quantity"] ?? "-")) ?></p>
            <p><strong>Notes:</strong> <?= esc_rush_payment(($draft["notes"] ?? "") !== "" ? $draft["notes"] : "None") ?></p>
            <p><strong>Payment:</strong> GCash</p>
          </div>
          <?php if (!empty($fileNames)): ?>
            <ul class="rush-payment-files">
              <?php foreach ($fileNames as $fileName): ?>
                <li><?= esc_rush_payment($fileName) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <?php if ($flashError !== ""): ?>
          <p class="form-feedback error" role="alert"><?= esc_rush_payment($flashError) ?></p>
        <?php else: ?>
          <p class="form-feedback" role="alert" aria-live="polite"></p>
        <?php endif; ?>

        <div class="rush-payment-box">
          <p class="rush-payment-title">Scan GCash QR</p>
          <div class="rush-payment-qr">
            <div class="rush-payment-qr-box">
              <img src="/assets/images/gcash-qr.jpg" alt="JC Shop GCash QR code">
            </div>
            <div class="rush-payment-reference">
              <label for="referenceNumberInput">Reference Number<span class="required">*</span></label>
              <input type="text" class="form-input" id="referenceNumberInput" name="reference_number" value="<?= esc_rush_payment($referenceNumber) ?>" placeholder="Enter the 13-digit transaction number" autocomplete="off" inputmode="numeric" pattern="[0-9]{13}" minlength="13" maxlength="13" required>
              <p class="rush-payment-note">Payment status: Pending Verification. The shop/admin will verify this before marking it paid.</p>
            </div>
          </div>
        </div>
      </div>

      <div class="form-actions">
        <a href="/pages/customer/custo2_rush_id.php" class="btn-back">Back</a>
        <button type="submit" class="btn-next">Submit Payment &amp; Join Queue</button>
      </div>
    </form>
  </div>
</section>

<?php if ($isConfirmed): ?>
  <?php include __DIR__ . "/../../components/queue_modal.php"; ?>
  <script>
    (function () {
      var queueModal = document.getElementById("queueModal");
      var modalQueueNo = document.getElementById("modalQueueNo");
      var goHomeBtn = document.getElementById("goHomeBtn");
      var viewQueueBtn = document.getElementById("viewQueueBtn");
      if (!queueModal || !modalQueueNo) return;
      modalQueueNo.textContent = <?= json_encode($queue) ?>;
      queueModal.style.display = "flex";
      document.body.classList.add("modal-open");
      if (goHomeBtn) goHomeBtn.onclick = function () { window.location.href = "/pages/customer/customer_dash.php"; };
      if (viewQueueBtn) viewQueueBtn.onclick = function () { window.location.href = "/pages/customer/custo_service_status.php"; };
    })();
  </script>
<?php endif; ?>

<?php include __DIR__ . "/../../components/footer.php"; ?>
</body>
</html>
