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
$fileNames = isset($draft["file_names"]) && is_array($draft["file_names"]) ? $draft["file_names"] : [];
if (empty($fileNames) && !empty($draft["file_name"])) {
  $fileNames = [(string)$draft["file_name"]];
}

$fileItems = [];
if (!empty($uploadedFiles)) {
  foreach ($uploadedFiles as $index => $file) {
    if (!is_array($file)) {
      continue;
    }
    $name = trim((string)($file["original_name"] ?? ($fileNames[$index] ?? "")));
    $path = trim((string)($file["saved_path"] ?? ""));
    if ($name === "" && $path === "") {
      continue;
    }
    $fileItems[] = [
      "name" => $name !== "" ? $name : basename($path),
      "path" => $path,
    ];
  }
}

if (empty($fileItems)) {
  foreach ($fileNames as $name) {
    $name = trim((string)$name);
    if ($name === "") {
      continue;
    }
    $fileItems[] = [
      "name" => $name,
      "path" => "",
    ];
  }
}

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
    .printing-page {
      --printing-accent: #5f0e0f;
      --printing-accent-soft: #fdf1ea;
      --printing-border: rgba(95, 14, 15, 0.14);
      --printing-surface: #ffffff;
      --printing-text-soft: #646464;
    }

    .printing-page .form-page-shell {
      display: grid;
      gap: 1.5rem;
    }

    .printing-page .form-page-intro {
      margin-bottom: 0;
    }

    .printing-page .page-title {
      margin-bottom: 0.35rem;
    }

    .printing-page .page-subtitle {
      color: var(--printing-text-soft);
      margin: 0;
    }

    .printing-page .form-card {
      background: var(--printing-surface);
      border: 1px solid var(--printing-border);
      border-radius: 24px;
      box-shadow: 0 14px 34px rgba(95, 14, 15, 0.06);
      padding: 1.6rem;
    }

    .printing-page .step-title {
      color: var(--printing-accent);
      letter-spacing: 0.02em;
      margin-bottom: 0.5rem;
    }

    .print-payment-card {
      display: grid;
      gap: 1.25rem;
    }

    .print-payment-block {
      background: #fff;
      border: 1px solid var(--printing-border);
      border-radius: 20px;
      padding: 1.2rem;
    }

    .print-payment-block--accent {
      background: linear-gradient(180deg, #fff8f4 0%, #fff 100%);
    }

    .print-payment-title {
      color: var(--printing-accent);
      font-size: 1rem;
      font-weight: 700;
      margin: 0 0 0.85rem;
    }

    .print-payment-estimate {
      display: grid;
      gap: 0.35rem;
    }

    .print-payment-estimate span {
      color: var(--printing-text-soft);
      font-size: 0.95rem;
    }

    .print-payment-estimate strong {
      color: #1e1e1e;
      font-size: 2.2rem;
      line-height: 1.1;
    }

    .print-payment-grid {
      display: grid;
      gap: 1rem;
      grid-template-columns: minmax(0, 1.4fr) minmax(260px, 0.9fr);
    }

    .print-payment-details {
      display: grid;
      gap: 0.85rem;
    }

    .print-payment-detail {
      margin: 0;
    }

    .print-payment-detail strong {
      color: var(--printing-accent);
      display: inline-block;
      min-width: 124px;
    }

    .print-payment-files {
      display: grid;
      gap: 0.65rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .print-payment-files li {
      align-items: center;
      background: #faf7f5;
      border: 1px solid var(--printing-border);
      border-radius: 14px;
      display: flex;
      gap: 0.75rem;
      justify-content: space-between;
      padding: 0.75rem 0.85rem;
    }

    .print-payment-file-name {
      color: #222;
      min-width: 0;
      overflow-wrap: anywhere;
    }

    .print-payment-file-link {
      color: var(--printing-accent);
      font-size: 0.9rem;
      font-weight: 700;
      text-decoration: none;
      white-space: nowrap;
    }

    .print-payment-file-link:hover {
      text-decoration: underline;
    }

    .print-payment-meta {
      background: #faf7f5;
      border: 1px solid var(--printing-border);
      border-radius: 20px;
      display: grid;
      gap: 0.9rem;
      padding: 1.15rem;
    }

    .print-payment-meta-row {
      display: grid;
      gap: 0.25rem;
    }

    .print-payment-meta-row span {
      color: var(--printing-text-soft);
      font-size: 0.9rem;
      text-transform: uppercase;
    }

    .print-payment-meta-row strong {
      color: #202020;
    }

    .print-payment-note,
    .print-payment-input-note {
      color: var(--printing-text-soft);
      margin: 0;
    }

    .print-payment-payment-box {
      background: var(--printing-accent-soft);
      border: 1px solid rgba(95, 14, 15, 0.1);
      border-radius: 22px;
      display: grid;
      gap: 1rem;
      padding: 1.2rem;
    }

    .print-payment-qr {
      display: grid;
      gap: 1rem;
      grid-template-columns: 180px minmax(0, 1fr);
      align-items: center;
    }

    .print-payment-qr-box {
      align-items: center;
      background: #fff;
      border: 3px solid var(--printing-accent);
      border-radius: 16px;
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
      color: var(--printing-accent);
      display: none;
      font-weight: 700;
      padding: 1rem;
      text-align: center;
    }

    .printing-page label {
      color: #1f1f1f;
      display: block;
      font-weight: 600;
      margin-bottom: 0.45rem;
    }

    .printing-page .form-input {
      border-radius: 16px;
      min-height: 56px;
      width: 100%;
    }

    .print-payment-cash-note {
      background: #fff;
      border: 1px solid rgba(95, 14, 15, 0.12);
      border-radius: 16px;
      color: #9a3412;
      margin: 0;
      padding: 1rem;
    }

    .printing-page .form-feedback {
      margin: 0;
    }

    .printing-page .form-actions {
      margin-top: 0;
    }

    @media (max-width: 900px) {
      .print-payment-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 767px) {
      .printing-page .form-card {
        padding: 1.25rem;
      }

      .print-payment-qr {
        grid-template-columns: 1fr;
      }

      .print-payment-qr-box {
        width: 100%;
        max-width: 180px;
      }

      .print-payment-files li {
        align-items: flex-start;
        flex-direction: column;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--print-order printing-page" data-payment-method="<?= esc_print_order($paymentMethod) ?>">

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
        <p class="page-subtitle">Review the same print order details from the previous page, then place the order.</p>
      </div>

      <div class="form-card print-payment-card">
        <div class="print-payment-block print-payment-block--accent">
          <p class="print-payment-title">Estimated Price</p>
          <div class="print-payment-estimate">
            <span>Estimated total based on your uploaded files and selected print settings.</span>
            <strong><?= print_order_money((float)($draft["estimated_total"] ?? 0)) ?></strong>
          </div>
        </div>

        <div class="print-payment-grid">
          <div class="print-payment-block">
            <p class="print-payment-title">Print Order Details</p>
            <div class="print-payment-details">
              <div class="print-payment-detail">
                <strong>Attached Files:</strong>
                <?php if (!empty($fileItems)): ?>
                  <ul class="print-payment-files">
                    <?php foreach ($fileItems as $fileItem): ?>
                      <li>
                        <span class="print-payment-file-name"><?= esc_print_order($fileItem["name"] ?? "-") ?></span>
                        <?php if (!empty($fileItem["path"])): ?>
                          <a class="print-payment-file-link" href="<?= esc_print_order($fileItem["path"]) ?>" target="_blank" rel="noopener">Open file</a>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <span>No uploaded files found.</span>
                <?php endif; ?>
              </div>
              <p class="print-payment-detail"><strong>Paper Size:</strong> <?= esc_print_order($draft["paper_size"] ?? "-") ?></p>
              <p class="print-payment-detail"><strong>Quantity/Copies:</strong> <?= esc_print_order((string)($draft["quantity"] ?? "-")) ?></p>
              <p class="print-payment-detail"><strong>Color Option:</strong> <?= esc_print_order($draft["color_option"] ?? "-") ?></p>
              <p class="print-payment-detail"><strong>Notes:</strong> <?= esc_print_order(($draft["notes"] ?? "") !== "" ? $draft["notes"] : "None") ?></p>
              <p class="print-payment-detail"><strong>Payment:</strong> <?= esc_print_order(print_order_payment_label($paymentMethod)) ?></p>
            </div>
          </div>

          <div class="print-payment-meta">
            <div class="print-payment-meta-row">
              <span>Total Files</span>
              <strong><?= esc_print_order((string)(count($fileItems) ?: ($draft["total_files"] ?? 0))) ?></strong>
            </div>
            <div class="print-payment-meta-row">
              <span>Total Pages</span>
              <strong><?= esc_print_order((string)($draft["total_pages"] ?? 0)) ?></strong>
            </div>
            <div class="print-payment-meta-row">
              <span>Price Per Page</span>
              <strong><?= print_order_money((float)($draft["price_per_page"] ?? 0)) ?></strong>
            </div>
            <div class="print-payment-meta-row">
              <span>Order Type</span>
              <strong>Online Print Order</strong>
            </div>
          </div>
        </div>

        <?php if ($flashError !== ""): ?>
          <p id="printPaymentFeedback" class="form-feedback error" role="alert"><?= esc_print_order($flashError) ?></p>
        <?php else: ?>
          <p id="printPaymentFeedback" class="form-feedback" role="alert" aria-live="polite"></p>
        <?php endif; ?>

        <form id="printOrderPaymentForm" method="post" action="/api/print_order_create.php" novalidate>
          <input type="hidden" name="csrf_token" value="<?= esc_print_order($_SESSION["csrf_token"] ?? "") ?>">

          <div class="print-payment-payment-box">
            <p class="print-payment-title">Payment Verification</p>
            <?php if ($paymentMethod === "gcash"): ?>
              <div class="print-payment-qr">
                <div class="print-payment-qr-box">
                  <img src="/assets/img/qr-placeholder.png" alt="Temporary GCash QR code" onerror="this.style.display='none'; var fallback = this.parentNode.querySelector('.print-payment-qr-fallback'); if (fallback) { fallback.style.display = 'flex'; }">
                  <div class="print-payment-qr-fallback">QR Placeholder</div>
                </div>
                <div>
                  <label for="referenceNumberInput">Reference Number<span class="required">*</span></label>
                  <input type="text" class="form-input" id="referenceNumberInput" name="reference_number" value="<?= esc_print_order($referenceNumber) ?>" placeholder="Enter the transaction number" autocomplete="off">
                  <p class="print-payment-input-note">Enter the GCash transaction reference so the staff can verify it before printing.</p>
                </div>
              </div>
            <?php elseif ($paymentMethod === "cash"): ?>
              <p class="print-payment-cash-note">You selected cash. Please go to the store to complete payment before printing starts.</p>
            <?php else: ?>
              <p class="print-payment-note">No payment method selected.</p>
            <?php endif; ?>
          </div>

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
