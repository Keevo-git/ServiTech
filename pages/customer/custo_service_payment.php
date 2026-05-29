<?php
require_once __DIR__ . "/../../components/auth_guard.php";

function esc_service_payment($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function service_payment_money($amount): string {
  return "&#8369;" . number_format((float)$amount, 2);
}

function service_payment_back_url(array $draft): string {
  $label = strtolower(trim((string)($draft["service_label"] ?? "")));
  if ($label === "rush id") return "/pages/customer/custo2_rush_id.php";
  if ($label === "laminating") return "/pages/customer/custo2_laminating.php";
  if ($label === "xerox") return "/pages/customer/custo2_xerox.php";
  return "/pages/customer/custo1_printing_option.php";
}

$queue = trim((string)($_GET["queue"] ?? ""));
$confirmation = $_SESSION["service_payment_confirmation"] ?? null;
$flashError = trim((string)($_SESSION["service_payment_flash_error"] ?? ""));
$formState = is_array($_SESSION["service_payment_form"] ?? null) ? $_SESSION["service_payment_form"] : [];
unset($_SESSION["service_payment_flash_error"], $_SESSION["service_payment_form"]);

$isConfirmed = $queue !== "" && is_array($confirmation) && trim((string)($confirmation["queue_code"] ?? "")) === $queue;
$draft = $_SESSION["service_payment_draft"] ?? null;
if (!$isConfirmed && !is_array($draft)) {
  header("Location: /pages/customer/custo1_printing_option.php");
  exit();
}

$draft = is_array($draft) ? $draft : [];
$serviceLabel = trim((string)($draft["service_label"] ?? "Service"));
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
  <title>ServiTech: <?= esc_service_payment($serviceLabel) ?> Payment</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260410d1">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260410d1">
  <style>
    .service-payment-page {
      --printing-accent: #5f0e0f;
      --printing-border: rgba(95, 14, 15, 0.14);
      --printing-surface: #ffffff;
      --printing-text-soft: #646464;
      --printing-shadow: 0 12px 30px rgba(95, 14, 15, 0.05);
      --payment-content-width: 860px;
    }

    .service-payment-page .form-page-shell,
    .service-payment-form {
      display: grid;
      gap: 1rem;
      margin: 0 auto;
      max-width: var(--payment-content-width);
      width: 100%;
    }

    .service-payment-page .form-card {
      background: var(--printing-surface);
      border: 1px solid var(--printing-border);
      border-radius: 22px;
      box-shadow: var(--printing-shadow);
      margin: 0;
      padding: 1.45rem;
      width: 100%;
    }

    .service-payment-card {
      display: grid;
      gap: 1rem;
      margin: 0 !important;
      width: 100% !important;
    }

    .service-payment-block,
    .service-payment-box {
      background: #fff;
      border: 1px solid var(--printing-border);
      border-radius: 18px;
      padding: 1rem;
    }

    .service-payment-block--accent {
      background: linear-gradient(135deg, #f8e7e0 0%, #f3ddd3 100%);
      border-radius: 16px;
    }

    .service-payment-title {
      color: var(--printing-accent);
      font-size: 0.98rem;
      font-weight: 700;
      margin: 0 0 0.6rem;
    }

    .service-payment-estimate span,
    .service-payment-note,
    .service-payment-input-note {
      color: #775d58;
      font-size: 0.92rem;
      margin: 0;
    }

    .service-payment-estimate strong {
      color: #431112;
      display: block;
      font-size: clamp(1.75rem, 3vw, 2.15rem);
      line-height: 1.05;
      margin-top: 0.2rem;
    }

    .service-payment-details {
      display: grid;
      gap: 0.35rem;
    }

    .service-payment-detail-line {
      color: #202020;
      line-height: 1.35;
      margin: 0;
    }

    .service-payment-files {
      display: grid;
      gap: 0.45rem;
      list-style: none;
      margin: 0.6rem 0 0;
      padding: 0;
    }

    .service-payment-files li {
      background: #fffdfb;
      border: 1px solid rgba(95, 14, 15, 0.14);
      border-left: 4px solid #c98f75;
      border-radius: 13px;
      color: #4f1717;
      font-weight: 700;
      padding: 0.7rem 0.85rem;
      overflow-wrap: anywhere;
    }

    .service-payment-qr-heading {
      color: var(--printing-accent);
      font-size: 0.98rem;
      font-weight: 700;
      margin: 0 0 0.85rem;
    }

    .service-payment-qr {
      align-items: center;
      display: grid;
      gap: 1.1rem;
      grid-template-columns: minmax(180px, 220px) minmax(0, 1fr);
    }

    .service-payment-qr-box {
      align-items: center;
      background: #fff;
      border: 2px solid var(--printing-accent);
      border-radius: 14px;
      display: flex;
      justify-content: center;
      min-height: 190px;
      overflow: hidden;
      padding: 0.75rem;
      width: min(100%, 220px);
    }

    .service-payment-qr-box img {
      display: block;
      height: auto;
      max-width: 220px;
      object-fit: contain;
      width: 100%;
    }

    .service-payment-reference {
      display: grid;
      gap: 0.45rem;
      max-width: 680px;
      width: 100%;
    }

    .service-payment-page .form-input {
      border-radius: 14px;
      min-height: 52px;
      width: 100%;
    }

    .service-payment-page .form-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 0;
      width: 100%;
    }

    .service-payment-page .form-actions .btn-back,
    .service-payment-page .form-actions .btn-next {
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
      .service-payment-qr {
        align-items: start;
        grid-template-columns: 1fr;
      }

      .service-payment-qr-box {
        justify-self: center;
      }

      .service-payment-page .form-actions {
        flex-direction: column;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--forms service-payment-page" data-confirmed-queue="<?= esc_service_payment($isConfirmed ? $queue : "") ?>">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--confirmation">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">PAYMENT</h2>
      <p class="page-subtitle">Review your <?= esc_service_payment($serviceLabel) ?> order, then submit your payment details for verification.</p>
    </div>

    <form id="servicePaymentForm" class="service-payment-form" method="post" action="/api/service_payment_create.php" novalidate>
      <input type="hidden" name="csrf_token" value="<?= esc_service_payment($_SESSION["csrf_token"] ?? "") ?>">
      <div class="form-card service-payment-card">
        <div class="service-payment-block service-payment-block--accent">
          <p class="service-payment-title">Estimated Price</p>
          <div class="service-payment-estimate">
            <span>Estimated total based on your selected service settings.</span>
            <strong><?= service_payment_money((float)($draft["estimated_total"] ?? 0)) ?></strong>
          </div>
        </div>

        <div class="service-payment-block">
          <p class="service-payment-title">Order Details</p>
          <div class="service-payment-details">
            <p class="service-payment-detail-line"><strong>Service:</strong> <?= esc_service_payment($serviceLabel) ?></p>
            <?php if (!empty($draft["package_label"])): ?>
              <p class="service-payment-detail-line"><strong>Package:</strong> <?= esc_service_payment($draft["package_label"]) ?></p>
            <?php endif; ?>
            <?php if (!empty($draft["paper_size"])): ?>
              <p class="service-payment-detail-line"><strong>Paper Size:</strong> <?= esc_service_payment($draft["paper_size"]) ?></p>
            <?php endif; ?>
            <?php if (!empty($draft["lamination_type"])): ?>
              <p class="service-payment-detail-line"><strong>Lamination:</strong> <?= esc_service_payment($draft["lamination_type"]) ?></p>
            <?php endif; ?>
            <p class="service-payment-detail-line"><strong>Quantity/Copies:</strong> <?= esc_service_payment((string)($draft["quantity"] ?? "-")) ?></p>
            <p class="service-payment-detail-line"><strong>Notes:</strong> <?= esc_service_payment(($draft["notes"] ?? "") !== "" ? $draft["notes"] : "None") ?></p>
            <p class="service-payment-detail-line"><strong>Payment:</strong> GCash</p>
          </div>
          <?php if (!empty($fileNames)): ?>
            <ul class="service-payment-files">
              <?php foreach ($fileNames as $fileName): ?>
                <li><?= esc_service_payment($fileName) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <?php if ($flashError !== ""): ?>
          <p class="form-feedback error" role="alert"><?= esc_service_payment($flashError) ?></p>
        <?php else: ?>
          <p class="form-feedback" role="alert" aria-live="polite"></p>
        <?php endif; ?>

        <div class="service-payment-box">
          <p class="service-payment-qr-heading">Scan GCash QR:</p>
          <div class="service-payment-qr">
            <div class="service-payment-qr-box">
              <img src="/assets/images/gcash-qr.jpg" alt="JC Shop GCash QR code">
            </div>
            <div class="service-payment-reference">
              <label for="referenceNumberInput">Reference Number<span class="required">*</span></label>
              <input type="text" class="form-input" id="referenceNumberInput" name="reference_number" value="<?= esc_service_payment($referenceNumber) ?>" placeholder="Enter the 13-digit transaction number" autocomplete="off" inputmode="numeric" pattern="[0-9]{13}" minlength="13" maxlength="13" required>
              <p class="service-payment-input-note">This is to be verified by employees of the shop.</p>
            </div>
          </div>
        </div>
      </div>

      <div class="form-actions">
        <a href="<?= esc_service_payment(service_payment_back_url($draft)) ?>" class="btn-back">Back</a>
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
