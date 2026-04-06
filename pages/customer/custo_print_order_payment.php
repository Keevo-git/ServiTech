<?php
require_once __DIR__ . "/../../components/auth_guard.php";

function esc_print_order($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function print_order_money($amount): string {
  return "&#8369;" . number_format((float)$amount, 2);
}

function print_order_payment_label(string $method): string {
  $method = strtolower(trim($method));
  if ($method === "gcash") {
    return "GCash";
  }
  if ($method === "cash") {
    return "Cash";
  }
  return "-";
}

$queue = trim((string)($_GET["queue"] ?? ""));
$confirmation = $_SESSION["print_order_confirmation"] ?? null;
$flashError = trim((string)($_SESSION["print_order_flash_error"] ?? ""));
$formState = is_array($_SESSION["print_order_form"] ?? null) ? $_SESSION["print_order_form"] : [];

unset($_SESSION["print_order_flash_error"], $_SESSION["print_order_form"]);

$isConfirmed = $queue !== ""
  && is_array($confirmation)
  && trim((string)($confirmation["queue_code"] ?? "")) === $queue;

$draft = $_SESSION["print_order_draft"] ?? null;
if (!$isConfirmed && !is_array($draft)) {
  header("Location: /pages/customer/custo2_docu_printing.php");
  exit();
}

$draft = is_array($draft) ? $draft : [];
$paymentMethod = strtolower(trim((string)($draft["payment_method"] ?? "")));
$uploadedFiles = isset($draft["uploaded_files"]) && is_array($draft["uploaded_files"]) ? $draft["uploaded_files"] : [];
$firstUploadedFile = (!empty($uploadedFiles) && is_array($uploadedFiles[0])) ? $uploadedFiles[0] : null;
$referenceNumber = trim((string)($formState["reference_number"] ?? ""));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Print Order Payment</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h9">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h15">
  <style>
    .print-payment-card {
      display: grid;
      gap: 1.5rem;
    }

    .print-payment-estimate {
      background: #efefef;
      border-radius: 18px;
      padding: 1.25rem 1.5rem;
    }

    .print-payment-estimate p {
      margin: 0;
    }

    .print-payment-estimate strong {
      display: block;
      font-size: 2rem;
      margin-top: 0.35rem;
    }

    .print-payment-title {
      color: #4a130f;
      font-size: 1.05rem;
      font-weight: 700;
      margin: 0 0 0.75rem;
    }

    .print-payment-details {
      display: grid;
      gap: 0.55rem;
      margin: 0;
    }

    .print-payment-detail {
      line-height: 1.5;
      margin: 0;
    }

    .print-payment-divider {
      border-top: 2px solid rgba(0, 0, 0, 0.35);
      margin: 0;
    }

    .print-payment-qr {
      display: grid;
      gap: 1.25rem;
      grid-template-columns: 180px minmax(0, 1fr);
      align-items: center;
    }

    .print-payment-qr-box {
      align-items: center;
      border: 3px solid #5f0e0f;
      border-radius: 14px;
      display: flex;
      justify-content: center;
      min-height: 180px;
      overflow: hidden;
      width: 180px;
    }

    .print-payment-qr-box img {
      display: block;
      height: 100%;
      object-fit: cover;
      width: 100%;
    }

    .print-payment-qr-fallback {
      color: #5f0e0f;
      display: none;
      font-weight: 700;
      padding: 1rem;
      text-align: center;
    }

    .print-payment-input-note {
      color: #5f5f5f;
      font-style: italic;
      margin: 0.5rem 0 0;
    }

    .print-payment-cash-note {
      background: #fff7ed;
      border: 1px solid #f08a00;
      border-radius: 14px;
      color: #9a3412;
      margin: 0;
      padding: 0.9rem 1rem;
    }

    @media (max-width: 768px) {
      .print-payment-qr {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--print-order" data-payment-method="<?= esc_print_order($paymentMethod) ?>">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--confirmation">
  <div class="form-page-shell">
    <?php if ($isConfirmed): ?>
      <div class="form-page-intro">
        <h2 class="page-title">PRINT ORDER CONFIRMATION</h2>
      </div>

      <div class="form-card confirmation-card">
        <h3 class="step-title">You're in the queue!</h3>
        <p>Your print order has been saved.</p>
        <p><strong>Queue Code:</strong> <?= esc_print_order($queue ?: "-") ?></p>

        <div class="form-actions form-actions--compact">
          <a href="/pages/customer/customer_dash.php" class="btn-back">Back to Dashboard</a>
          <a href="/pages/customer/custo_service_status.php" class="btn-next">View Status</a>
        </div>
      </div>
    <?php else: ?>
      <div class="form-page-intro">
        <h2 class="page-title">PAYMENT</h2>
        <p class="page-subtitle">Finalize your print order and make payment.</p>
      </div>

      <div class="form-card print-payment-card">
        <div>
          <p class="print-payment-title">Estimated Price</p>
          <div class="print-payment-estimate">
            <p>Estimated Price</p>
            <strong><?= print_order_money((float)($draft["estimated_total"] ?? 0)) ?></strong>
          </div>
        </div>

        <div>
          <p class="print-payment-title">Print Order Details</p>
          <div class="print-payment-details">
            <p class="print-payment-detail"><strong>Attached File:</strong>
              <?php if ($firstUploadedFile && !empty($firstUploadedFile["saved_path"])): ?>
                <a href="<?= esc_print_order($firstUploadedFile["saved_path"]) ?>" target="_blank" rel="noopener">
                  <?= esc_print_order($draft["file_name"] ?? "-") ?>
                </a>
              <?php else: ?>
                <?= esc_print_order($draft["file_name"] ?? "-") ?>
              <?php endif; ?>
            </p>
            <p class="print-payment-detail"><strong>Paper Size:</strong> <?= esc_print_order($draft["paper_size"] ?? "-") ?></p>
            <p class="print-payment-detail"><strong>Quantity/Copies:</strong> <?= esc_print_order((string)($draft["quantity"] ?? "-")) ?></p>
            <p class="print-payment-detail"><strong>Color Option:</strong> <?= esc_print_order($draft["color_option"] ?? "-") ?></p>
            <p class="print-payment-detail"><strong>Notes:</strong> <?= esc_print_order(($draft["notes"] ?? "") !== "" ? $draft["notes"] : "None") ?></p>
            <p class="print-payment-detail"><strong>Payment:</strong> <?= esc_print_order(print_order_payment_label($paymentMethod)) ?></p>
          </div>
        </div>

        <hr class="print-payment-divider">

        <?php if ($flashError !== ""): ?>
          <p id="printPaymentFeedback" class="form-feedback error" role="alert"><?= esc_print_order($flashError) ?></p>
        <?php else: ?>
          <p id="printPaymentFeedback" class="form-feedback" role="alert" aria-live="polite"></p>
        <?php endif; ?>

        <form id="printOrderPaymentForm" method="post" action="/api/print_order_create.php" novalidate>
          <input type="hidden" name="csrf_token" value="<?= esc_print_order($_SESSION["csrf_token"] ?? "") ?>">
          <?php if ($paymentMethod === "gcash"): ?>
            <div>
              <p class="print-payment-title">JC SHOP GCASH QR:</p>
              <div class="print-payment-qr">
                <div class="print-payment-qr-box">
                  <img src="/assets/img/qr-placeholder.png" alt="Temporary GCash QR code" onerror="this.style.display='none'; var fallback = this.parentNode.querySelector('.print-payment-qr-fallback'); if (fallback) { fallback.style.display = 'flex'; }">
                  <div class="print-payment-qr-fallback">QR Placeholder</div>
                </div>
                <div>
                  <label for="referenceNumberInput">Reference Number<span class="required">*</span></label>
                  <input type="text" class="form-input" id="referenceNumberInput" name="reference_number" value="<?= esc_print_order($referenceNumber) ?>" placeholder="Enter the transaction number" autocomplete="off">
                  <p class="print-payment-input-note">This is to be verified by employees of the shop.</p>
                </div>
              </div>
            </div>
          <?php elseif ($paymentMethod === "cash"): ?>
            <p class="print-payment-cash-note">You must go to the store to complete payment before printing.</p>
          <?php endif; ?>

          <div class="form-actions form-actions--compact">
            <a href="/pages/customer/custo2_docu_printing.php" class="btn-back">Back</a>
            <button type="submit" class="btn-next" id="placePrintOrderBtn">Place Print Order</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<script src="/assets/js/custo_print_order_payment.js?v=20260406a1"></script>
</body>
</html>

