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

function build_print_order_file_items(array $draft): array {
  $uploadedFiles = isset($draft["uploaded_files"]) && is_array($draft["uploaded_files"]) ? $draft["uploaded_files"] : [];
  $fileNames = isset($draft["file_names"]) && is_array($draft["file_names"]) ? array_values($draft["file_names"]) : [];
  $fileAnalysis = isset($draft["file_analysis"]) && is_array($draft["file_analysis"]) ? array_values($draft["file_analysis"]) : [];

  if (empty($fileNames) && !empty($draft["file_name"])) {
    $fileNames = [(string)$draft["file_name"]];
  }

  $totalItems = max(count($uploadedFiles), count($fileNames), count($fileAnalysis));
  $items = [];

  for ($index = 0; $index < $totalItems; $index++) {
    $uploaded = isset($uploadedFiles[$index]) && is_array($uploadedFiles[$index]) ? $uploadedFiles[$index] : [];
    $analysis = isset($fileAnalysis[$index]) && is_array($fileAnalysis[$index]) ? $fileAnalysis[$index] : [];

    $name = trim((string)($analysis["file_name"] ?? ($uploaded["original_name"] ?? ($fileNames[$index] ?? ""))));
    $path = trim((string)($uploaded["saved_path"] ?? ""));
    $type = strtoupper(trim((string)($analysis["file_type"] ?? ($uploaded["file_type"] ?? pathinfo($name, PATHINFO_EXTENSION)))));

    $countLabel = "";
    if (isset($analysis["slide_count"])) {
      $count = max(0, (int)$analysis["slide_count"]);
      if ($count > 0) {
        $countLabel = $count . " slide" . ($count === 1 ? "" : "s");
      }
    } elseif (isset($analysis["page_count"])) {
      $count = max(0, (int)$analysis["page_count"]);
      if ($count > 0) {
        $countLabel = $count . " page" . ($count === 1 ? "" : "s");
      }
    }

    if ($name === "" && $path === "") {
      continue;
    }

    $metaParts = [];
    if ($type !== "") {
      $metaParts[] = $type;
    }
    if ($countLabel !== "") {
      $metaParts[] = $countLabel;
    }

    $items[] = [
      "name" => $name !== "" ? $name : basename($path),
      "path" => $path,
      "meta" => implode(" | ", $metaParts),
    ];
  }

  return $items;
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
$fileItems = build_print_order_file_items($draft);
$totalFilesDisplay = count($fileItems) ?: max(0, (int)($draft["total_files"] ?? 0));

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

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
      --printing-border: rgba(95, 14, 15, 0.14);
      --printing-surface: #ffffff;
      --printing-surface-soft: #faf7f5;
      --printing-text-soft: #646464;
      --printing-shadow: 0 12px 30px rgba(95, 14, 15, 0.05);
      --printing-accent-soft: #f6e5df;
      --printing-accent-wash: #fbf1ec;
      --printing-file-surface: #fcf7f3;
      --payment-content-width: 860px;
    }

    .printing-page .form-page-shell {
      display: grid;
      gap: 1.25rem;
      margin: 0 auto;
      max-width: 1080px;
      width: 100%;
    }

    .printing-page .form-page-intro {
      margin: 0;
      padding: 0;
    }

    .printing-page .page-title {
      margin-bottom: 0.2rem;
    }

    .printing-page .page-subtitle {
      color: var(--printing-text-soft);
      margin: 0;
      max-width: 720px;
    }

    .printing-page .form-card {
      background: var(--printing-surface);
      border: 1px solid var(--printing-border);
      border-radius: 22px;
      box-shadow: var(--printing-shadow);
      margin: 0;
      padding: 1.45rem;
      width: 100%;
    }

    .printing-page .form-page-intro,
    .printing-page .print-payment-form,
    .printing-page .confirmation-card {
      margin: 0 auto !important;
      max-width: var(--payment-content-width) !important;
      width: 100% !important;
    }

    .print-payment-form {
      display: grid;
      gap: 0.9rem;
      justify-self: center;
      margin: 0 auto !important;
      max-width: var(--payment-content-width) !important;
      width: 100% !important;
    }

    .printing-page .step-title {
      color: var(--printing-accent);
      letter-spacing: 0.02em;
      margin: 0 0 0.55rem;
    }

    .print-payment-card {
      display: grid;
      gap: 1rem;
      margin: 0 !important;
      width: 100% !important;
    }

    .print-payment-block,
    .print-payment-payment-box {
      background: #fff;
      border: 1px solid var(--printing-border);
      border-radius: 18px;
      padding: 1rem;
    }

    .print-payment-block--accent {
      background: linear-gradient(135deg, #f8e7e0 0%, #f3ddd3 100%);
      border: 1px solid rgba(95, 14, 15, 0.12);
      border-radius: 16px;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.45);
      padding: 0.9rem 1.15rem;
    }

    .print-payment-title {
      color: var(--printing-accent);
      font-size: 0.98rem;
      font-weight: 700;
      margin: 0 0 0.6rem;
    }

    .print-payment-estimate {
      display: grid;
      gap: 0.2rem;
    }

    .print-payment-estimate span,
    .print-payment-note,
    .print-payment-input-note {
      color: #775d58;
      font-size: 0.92rem;
      margin: 0;
    }

    .print-payment-estimate strong {
      color: #431112;
      font-size: clamp(1.75rem, 3vw, 2.15rem);
      line-height: 1.05;
      text-shadow: 0 1px 0 rgba(255, 255, 255, 0.35);
    }

    .print-payment-grid {
      display: grid;
      gap: 0.9rem;
      grid-template-columns: 1fr;
    }

    .print-payment-details {
      display: grid;
      gap: 0.8rem;
    }

    .print-payment-detail {
      background: #fff;
      border: 1px solid var(--printing-border);
      border-radius: 16px;
      margin: 0;
      padding: 0.9rem 1rem;
    }

    .print-payment-detail--files {
      background: var(--printing-file-surface);
      border-color: rgba(95, 14, 15, 0.16);
    }

    .print-payment-detail strong {
      color: var(--printing-accent);
      display: block;
      font-size: 0.76rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      margin: 0 0 0.35rem;
      text-transform: uppercase;
    }

    .print-payment-files {
      display: grid;
      gap: 0.45rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .print-payment-files li {
      align-items: center;
      background: #fffdfb;
      border: 1px solid rgba(95, 14, 15, 0.14);
      border-left: 4px solid #c98f75;
      border-radius: 13px;
      display: flex;
      gap: 0.75rem;
      justify-content: space-between;
      padding: 0.7rem 0.85rem;
    }

    .print-payment-file-main {
      display: grid;
      gap: 0.1rem;
      min-width: 0;
    }

    .print-payment-file-name {
      color: #4f1717;
      font-weight: 700;
      min-width: 0;
      overflow-wrap: anywhere;
    }

    .print-payment-file-meta {
      color: #8a6b64;
      font-size: 0.82rem;
    }

    .print-payment-file-link {
      background: #f6e5df;
      border: 1px solid rgba(95, 14, 15, 0.12);
      border-radius: 999px;
      color: var(--printing-accent);
      font-size: 0.82rem;
      font-weight: 700;
      padding: 0.35rem 0.8rem;
      text-decoration: none;
      transition: background 0.2s ease, border-color 0.2s ease;
      white-space: nowrap;
    }

    .print-payment-file-link:hover {
      background: #efdad2;
      border-color: rgba(95, 14, 15, 0.2);
      text-decoration: none;
    }

    .print-payment-detail-list {
      display: grid;
      gap: 0.32rem;
      margin-top: 0.1rem;
    }

    .print-payment-detail-line {
      color: #202020;
      line-height: 1.35;
      margin: 0;
    }

    .print-payment-detail-line strong {
      color: #1f1f1f;
      display: inline;
      font-size: 1rem;
      font-weight: 600;
      letter-spacing: 0;
      margin: 0;
      text-transform: none;
    }

    .print-payment-meta {
      display: grid;
      gap: 0.65rem;
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .print-payment-meta-row {
      background: #fff;
      border: 1px solid var(--printing-border);
      border-radius: 14px;
      display: grid;
      gap: 0.15rem;
      padding: 0.75rem 0.85rem;
    }

    .print-payment-meta-row span {
      color: var(--printing-text-soft);
      font-size: 0.74rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .print-payment-meta-row strong {
      color: #202020;
      font-size: 0.98rem;
    }

    .print-payment-payment-box {
      border-top: 2px solid rgba(95, 14, 15, 0.15);
      padding-top: 1rem;
    }

    .print-payment-qr-heading {
      color: var(--printing-accent);
      font-size: 0.98rem;
      font-weight: 700;
      margin: 0 0 0.85rem;
    }

    .print-payment-qr {
      display: grid;
      gap: 1.1rem;
      grid-template-columns: 210px minmax(0, 1fr);
      align-items: center;
    }

    .print-payment-qr-box {
      align-items: center;
      background: #fff;
      border: 2px solid var(--printing-accent);
      border-radius: 14px;
      display: flex;
      height: 190px;
      justify-content: center;
      overflow: hidden;
      width: 190px;
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

    .print-payment-reference {
      display: grid;
      gap: 0.45rem;
      max-width: 680px;
      width: 100%;
    }

    .printing-page label {
      color: #1f1f1f;
      display: block;
      font-weight: 600;
      margin-bottom: 0.35rem;
    }

    .printing-page .form-input {
      border-radius: 14px;
      min-height: 52px;
      width: 100%;
    }

    #referenceNumberInput {
      font-size: 1rem;
      max-width: none;
      min-width: 0;
      width: 100%;
    }

    .print-payment-cash-note {
      background: #fff;
      border: 1px solid rgba(95, 14, 15, 0.12);
      border-radius: 14px;
      color: #9a3412;
      margin: 0;
      padding: 0.9rem 1rem;
    }

    .printing-page .form-feedback {
      margin: 0;
    }

    .printing-page .form-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 0;
      width: 100%;
    }

    .print-payment-form > .form-actions {
      padding-top: 0.15rem;
    }

    .printing-page .form-actions .btn-back,
    .printing-page .form-actions .btn-next {
      align-items: center;
      border-radius: 10px;
      display: inline-flex;
      font-size: 16px;
      font-weight: 600;
      justify-content: center;
      line-height: 1.2;
      min-height: 48px;
      padding: 13px 22px;
      text-align: center;
      width: 100%;
    }

    .printing-page .form-actions .btn-next {
      appearance: none;
      -webkit-appearance: none;
      background: #fbbf24;
      border: none;
      box-shadow: 0 6px 12px rgba(0, 0, 0, 0.25);
      color: #000;
      cursor: pointer;
      flex: 1 1 240px;
      margin: 0;
    }

    .printing-page .form-actions .btn-back {
      flex: 1 1 240px;
    }

    .confirmation-card {
      display: grid;
      gap: 0.9rem;
      max-width: 640px;
    }

    @media (max-width: 960px) {
      .printing-page .form-card {
        padding: 1.25rem;
      }

      .print-payment-meta {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .print-payment-qr {
        grid-template-columns: 180px minmax(0, 1fr);
      }

      .print-payment-qr-box {
        height: 170px;
        width: 170px;
      }
    }

    @media (max-width: 767px) {
      .printing-page .form-card {
        padding: 1.05rem;
      }

      .print-payment-files li {
        align-items: flex-start;
        flex-direction: column;
      }

      .print-payment-meta {
        grid-template-columns: 1fr;
      }

      .print-payment-qr {
        grid-template-columns: 1fr;
        align-items: start;
      }

      .print-payment-qr-box {
        height: 160px;
        width: 160px;
      }

      .printing-page .form-actions {
        flex-direction: column;
      }

      .printing-page .form-actions .btn-next,
      .printing-page .form-actions .btn-back {
        flex: 0 0 auto;
        min-height: 48px;
        width: 100%;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--print-order printing-page" data-payment-method="<?= esc_print_order($paymentMethod) ?>" data-confirmed-queue="<?= esc_print_order($isConfirmed ? ($queue ?: "") : "") ?>" data-queue-home-url="/pages/customer/customer_dash.php" data-queue-status-url="/pages/customer/custo_service_status.php">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--confirmation">
  <div class="form-page-shell">
    <?php if ($isConfirmed): ?>
      <div class="form-page-intro">
        <h2 class="page-title">PRINT ORDER CONFIRMATION</h2>
      </div>


    <?php else: ?>
      <div class="form-page-intro">
        <h2 class="page-title">PAYMENT</h2>
        <p class="page-subtitle">Review the same print order details from the previous page, then place the order.</p>
      </div>

      <form id="printOrderPaymentForm" class="print-payment-form" method="post" action="/api/print_order_create.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= esc_print_order($_SESSION["csrf_token"] ?? "") ?>">

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
                  <strong>Attached Files</strong>
                  <?php if (!empty($fileItems)): ?>
                    <ul class="print-payment-files">
                      <?php foreach ($fileItems as $fileItem): ?>
                        <li>
                          <div class="print-payment-file-main">
                            <span class="print-payment-file-name"><?= esc_print_order($fileItem["name"] ?? "-") ?></span>
                            <?php if (!empty($fileItem["meta"])): ?>
                              <span class="print-payment-file-meta"><?= esc_print_order($fileItem["meta"]) ?></span>
                            <?php endif; ?>
                          </div>
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
                <div class="print-payment-detail">
                  <div class="print-payment-detail-list">
                    <p class="print-payment-detail-line"><strong>Paper Size:</strong> <?= esc_print_order($draft["paper_size"] ?? "-") ?></p>
                    <p class="print-payment-detail-line"><strong>Quantity/Copies:</strong> <?= esc_print_order((string)($draft["quantity"] ?? "-")) ?></p>
                    <p class="print-payment-detail-line"><strong>Color Option:</strong> <?= esc_print_order($draft["color_option"] ?? "-") ?></p>
                    <p class="print-payment-detail-line"><strong>Notes:</strong> <?= esc_print_order(($draft["notes"] ?? "") !== "" ? $draft["notes"] : "None") ?></p>
                    <p class="print-payment-detail-line"><strong>Payment:</strong> <?= esc_print_order(print_order_payment_label($paymentMethod)) ?></p>
                  </div>
                </div>
              </div>
            </div>

            <div class="print-payment-meta">
              <div class="print-payment-meta-row">
                <span>Total Files</span>
                <strong><?= esc_print_order((string)$totalFilesDisplay) ?></strong>
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

          <div class="print-payment-payment-box">
            <?php if ($paymentMethod === "gcash"): ?>
              <p class="print-payment-qr-heading">JC SHOP GCASH QR:</p>
              <div class="print-payment-qr">
                <div class="print-payment-qr-box">
                  <img src="/assets/img/qr-placeholder.png" alt="Temporary GCash QR code" onerror="this.style.display='none'; var fallback = this.parentNode.querySelector('.print-payment-qr-fallback'); if (fallback) { fallback.style.display = 'flex'; }">
                  <div class="print-payment-qr-fallback">QR Placeholder</div>
                </div>
                <div class="print-payment-reference">
                  <label for="referenceNumberInput">Reference Number<span class="required">*</span></label>
                  <input type="text" class="form-input" id="referenceNumberInput" name="reference_number" value="<?= esc_print_order($referenceNumber) ?>" placeholder="Enter the transaction number" autocomplete="off">
                  <p class="print-payment-input-note">This is to be verified by employees of the shop.</p>
                </div>
              </div>
            <?php elseif ($paymentMethod === "cash"): ?>
              <p class="print-payment-cash-note">You selected cash. Please go to the store to complete payment before printing starts.</p>
            <?php else: ?>
              <p class="print-payment-note">No payment method selected.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="form-actions form-actions--compact">
          <a href="/pages/customer/custo2_docu_printing.php" class="btn-back">Back</a>
          <button type="submit" class="btn-next" id="placePrintOrderBtn">Place Print Order</button>
        </div>
      </form>
    <?php endif; ?>
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
      if (!queueModal || !modalQueueNo) {
        return;
      }
      modalQueueNo.textContent = <?= json_encode($queue ?: "") ?>;
      queueModal.style.display = "flex";
      document.body.classList.add("modal-open");
      if (goHomeBtn) {
        goHomeBtn.onclick = function () {
          window.location.href = "/pages/customer/customer_dash.php";
        };
      }
      if (viewQueueBtn) {
        viewQueueBtn.onclick = function () {
          window.location.href = "/pages/customer/custo_service_status.php";
        };
      }
    })();
  </script>
<?php endif; ?>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<script src="/assets/js/custo_print_order_payment.js?v=20260406b2"></script>
</body>
</html>















