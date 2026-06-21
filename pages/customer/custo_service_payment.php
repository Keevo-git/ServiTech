<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/app.php";
require_once __DIR__ . "/../../config/contact.php";

function service_payment_esc($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function service_payment_money($value): string {
  return is_numeric($value) ? "PHP " . number_format((float)$value, 2) : "For assessment";
}

function service_payment_details($raw): array {
  if (is_array($raw)) return $raw;
  $decoded = json_decode((string)$raw, true);
  return is_array($decoded) ? $decoded : [];
}

function service_payment_add_row(array &$rows, string $label, $value): void {
  if (is_array($value)) return;
  $value = trim((string)$value);
  if ($value !== "") $rows[] = ["label" => $label, "value" => $value];
}

function service_payment_rows(array $details): array {
  $service = strtolower(trim((string)($details["service_label"] ?? "")));
  $rows = [];
  if (str_contains($service, "document") && str_contains($service, "print")) {
    service_payment_add_row($rows, "Attached files", implode(", ", array_map("strval", (array)($details["file_names"] ?? []))));
    service_payment_add_row($rows, "Paper size", $details["paper_size"] ?? "");
    service_payment_add_row($rows, "Color option", $details["color_option"] ?? "");
    service_payment_add_row($rows, "Number of copies", $details["quantity"] ?? "");
    service_payment_add_row($rows, "Number of pages", $details["total_pages"] ?? "");
    service_payment_add_row($rows, "Additional instructions", $details["notes"] ?? "");
  } elseif (str_contains($service, "xerox") || str_contains($service, "photocopy")) {
    service_payment_add_row($rows, "Paper size", $details["paper_size"] ?? "");
    service_payment_add_row($rows, "Color option", $details["color_option"] ?? "");
    service_payment_add_row($rows, "Number of copies", $details["quantity"] ?? "");
    service_payment_add_row($rows, "Additional instructions", $details["notes"] ?? "");
  } elseif (str_contains($service, "rush") && str_contains($service, "id")) {
    service_payment_add_row($rows, "Package and inclusions", $details["package_label"] ?? "");
    service_payment_add_row($rows, "Quantity", $details["quantity"] ?? "");
    $addons = [];
    foreach ((array)($details["add_ons_snapshot"] ?? []) as $addon) {
      if (is_array($addon) && trim((string)($addon["name"] ?? "")) !== "") $addons[] = trim((string)$addon["name"]);
    }
    service_payment_add_row($rows, "Add-ons", implode(", ", $addons));
    service_payment_add_row($rows, "Additional instructions", $details["notes"] ?? "");
  } elseif (str_contains($service, "laminat")) {
    service_payment_add_row($rows, "Lamination type", $details["lamination_type"] ?? ($details["option_name_snapshot"] ?? ""));
    service_payment_add_row($rows, "Size", $details["paper_size"] ?? "");
    service_payment_add_row($rows, "Quantity", $details["quantity"] ?? "");
    service_payment_add_row($rows, "Additional instructions", $details["notes"] ?? "");
  } elseif (str_contains($service, "scan")) {
    service_payment_add_row($rows, "Paper size", $details["paper_size"] ?? "");
    service_payment_add_row($rows, "Number of pages", $details["quantity"] ?? "");
    service_payment_add_row($rows, "Additional instructions", $details["notes"] ?? "");
  } elseif (isset($details["installation_type"])) {
    service_payment_add_row($rows, "Device", $details["device_type"] ?? "");
    service_payment_add_row($rows, "Installation service", $details["installation_type"] ?? "");
    service_payment_add_row($rows, "Instructions", $details["notes"] ?? "");
  } elseif (isset($details["repair_type"]) || isset($details["service_type_snapshot"])) {
    service_payment_add_row($rows, "Device", $details["device_type"] ?? "");
    service_payment_add_row($rows, "Repair service", $details["repair_type"] ?? ($details["service_type_snapshot"] ?? ""));
    service_payment_add_row($rows, "Issue description", $details["customer_issue_description"] ?? ($details["notes"] ?? ""));
  } else {
    service_payment_add_row($rows, "Selected option", $details["option_name_snapshot"] ?? ($details["option_details_snapshot"] ?? ""));
    service_payment_add_row($rows, "Quantity", $details["quantity"] ?? "");
    service_payment_add_row($rows, "Instructions", $details["notes"] ?? "");
  }
  return $rows;
}

$userId = (int)($_SESSION["user_id"] ?? 0);
$queueId = (int)($_GET["queue_id"] ?? 0);
$draftToken = trim((string)($_GET["draft_token"] ?? ""));
$paymentDraft = servitech_service_payment_draft();
$isDraft = is_array($paymentDraft);

if ($isDraft && !servitech_service_payment_draft_matches($draftToken, $paymentDraft)) {
  header("Location: " . servitech_service_payment_draft_url($paymentDraft, (string)($_GET["incomplete"] ?? "") === "1"));
  exit();
}

if ($isDraft) {
  $nameStmt = $pdo->prepare("SELECT COALESCE(NULLIF(fullname, ''), email, 'Customer') FROM users WHERE id = :user_id LIMIT 1");
  $nameStmt->execute([":user_id" => $userId]);
  $draftDetails = is_array($paymentDraft["details"] ?? null) ? $paymentDraft["details"] : [];
  $queue = [
    "id" => 0,
    "queue_code" => "Assigned after payment submission",
    "status" => "PENDING PAYMENT DETAILS",
    "details" => $draftDetails,
    "price" => $draftDetails["estimated_total"] ?? null,
    "fullname" => trim((string)($nameStmt->fetchColumn() ?: "Customer")),
    "payment_method" => "gcash",
    "reference_number" => "",
    "payment_status" => "AWAITING DETAILS",
    "amount" => $draftDetails["estimated_total"] ?? null,
  ];
} else {
  $stmt = $pdo->prepare("
    SELECT q.id, q.queue_code, q.status, q.details, q.price, u.fullname,
      p.payment_method, p.reference_number, p.status AS payment_status, p.amount
    FROM queues q
    JOIN users u ON u.id = q.user_id
    JOIN LATERAL (
      SELECT payment_method, reference_number, status, amount
      FROM payments WHERE queue_id = q.id ORDER BY id DESC LIMIT 1
    ) p ON TRUE
    WHERE q.id = :queue_id AND q.user_id = :user_id
    LIMIT 1
  ");
  $stmt->execute([":queue_id" => $queueId, ":user_id" => $userId]);
  $queue = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!is_array($queue) || strtolower(trim((string)$queue["payment_method"])) !== "gcash") {
    header("Location: " . servitech_url("/pages/customer/customer_dash.php"));
    exit();
  }
}

$details = service_payment_details($queue["details"] ?? null);
$serviceName = trim((string)($details["service_label"] ?? ($details["catalog_service_name"] ?? "Service")));
$detailRows = service_payment_rows($details);
$total = $queue["price"] ?? ($details["estimated_total"] ?? ($queue["amount"] ?? null));
$flashError = trim((string)($_SESSION["service_payment_flash_error"] ?? ""));
unset($_SESSION["service_payment_flash_error"]);
$incompleteRedirect = $isDraft && (string)($_GET["incomplete"] ?? "") === "1";
$submitted = (string)($_GET["submitted"] ?? "") === "1"
  && is_array($_SESSION["service_payment_confirmation"] ?? null)
  && (int)($_SESSION["service_payment_confirmation"]["queue_id"] ?? 0) === $queueId;
if ($submitted) unset($_SESSION["service_payment_confirmation"]);
$paymentStatus = strtoupper(trim((string)($queue["payment_status"] ?? "PENDING")));
$reviewed = in_array($paymentStatus, ["APPROVED", "PAID", "CANCELLED"], true);
$paymentDetailsSubmitted = !$isDraft
  && $paymentStatus === "PENDING"
  && trim((string)($queue["reference_number"] ?? "")) !== "";
$paymentCancelled = $paymentStatus === "CANCELLED";
$gcashAccountName = servitech_gcash_account_name();
$gcashAccountNumber = servitech_gcash_account_number();
$csrfToken = servitech_csrf_token();

header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: GCash Payment</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260616-footer-hover">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260410d1">
  <link rel="stylesheet" href="/assets/css/customer-payment.css?v=20260621c">
</head>
<body class="customer-layout customer-page--print-order printing-page customer-payment-page">
<?php include __DIR__ . "/../../components/header.php"; ?>
<section class="form-page form-page--confirmation">
  <div class="form-page-shell">
  <?php if ($submitted || $reviewed || $paymentDetailsSubmitted): ?>
    <section class="form-card customer-payment-confirmation">
      <span class="customer-payment-confirmation__icon<?= $paymentCancelled ? ' is-cancelled' : '' ?>" aria-hidden="true"><?= $paymentCancelled ? '&times;' : '&#10003;' ?></span>
      <h2><?= $paymentStatus === "APPROVED" || $paymentStatus === "PAID" ? "GCash Payment Approved" : ($paymentStatus === "CANCELLED" ? "Payment Cancelled" : "GCash Payment Submitted") ?></h2>
      <p><?php if ($paymentStatus === "APPROVED" || $paymentStatus === "PAID"): ?>Your GCash payment for Queue <?= service_payment_esc($queue["queue_code"]) ?> has been approved.<?php elseif ($paymentStatus === "CANCELLED"): ?>This payment and queue/order have been cancelled.<?php else: ?>Your GCash details for Queue <?= service_payment_esc($queue["queue_code"]) ?> were submitted and are waiting for admin review.<?php endif; ?></p>
      <div class="form-actions form-actions--compact"><a class="btn-next" href="<?= service_payment_esc(servitech_url('/pages/customer/custo_service_status.php')) ?>">View Queue Status</a></div>
    </section>
  <?php else: ?>
    <div class="form-page-intro">
      <h1 class="page-title">Complete your GCash Payment</h1>
      <p class="page-subtitle">Review your order, send the exact amount through GCash, then submit your reference number for approval.</p>
    </div>
    <?php if ($incompleteRedirect): ?><p class="customer-payment-error" role="alert">Complete or cancel this GCash payment before continuing. Your queue has not been joined yet.</p><?php endif; ?>

    <form id="servicePaymentForm" class="customer-payment-form" method="post" action="<?= service_payment_esc(servitech_url('/api/service_payment_create.php')) ?>">
      <input type="hidden" name="csrf_token" value="<?= service_payment_esc($csrfToken) ?>">
      <?php if ($isDraft): ?>
        <input type="hidden" name="draft_token" value="<?= service_payment_esc($draftToken) ?>">
      <?php else: ?>
        <input type="hidden" name="queue_id" value="<?= (int)$queueId ?>">
      <?php endif; ?>

      <div class="form-card customer-payment-card">
        <div class="customer-payment-step">
          <p class="customer-payment-step__label">Payment</p>
          <span class="customer-payment-status"><?= $isDraft ? "Payment details required" : "Waiting for admin review" ?></span>
        </div>

        <div class="customer-payment-total">
          <p class="customer-payment-total__label">Amount to Send</p>
          <p class="customer-payment-total__note">Send this exact amount using the GCash QR code below.</p>
          <strong><?= service_payment_esc(service_payment_money($total)) ?></strong>
        </div>

        <div class="customer-payment-block">
          <h2 class="customer-payment-block__title">Order Details</h2>
          <div class="customer-payment-meta">
            <div class="customer-payment-meta__item"><span>Queue / Order Number</span><strong><?= service_payment_esc($queue["queue_code"]) ?></strong></div>
            <div class="customer-payment-meta__item"><span>Customer Name</span><strong><?= service_payment_esc($queue["fullname"]) ?></strong></div>
            <div class="customer-payment-meta__item"><span>Selected Service</span><strong><?= service_payment_esc($serviceName) ?></strong></div>
            <div class="customer-payment-meta__item"><span>Payment Method</span><strong>GCash</strong></div>
          </div>
          <?php if ($detailRows): ?>
            <div class="customer-payment-details">
              <?php foreach ($detailRows as $row): ?>
                <div class="customer-payment-detail"><span><?= service_payment_esc($row["label"]) ?></span><strong><?= service_payment_esc($row["value"]) ?></strong></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="customer-payment-block customer-payment-gcash">
          <h2 class="customer-payment-block__title">Pay with GCash</h2>
          <?php if ($flashError !== ""): ?><p class="customer-payment-error" role="alert"><?= service_payment_esc($flashError) ?></p><?php endif; ?>
          <div class="customer-payment-qr-layout">
            <div class="customer-payment-qr-box">
              <img src="/assets/images/gcash-qr.jpg" alt="JC Shop GCash QR code" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
              <div class="customer-payment-qr-fallback">GCash QR unavailable</div>
            </div>
            <div class="customer-payment-instructions">
              <ol>
                <li>Open GCash and scan the QR code.</li>
                <li>Send the exact amount shown above.</li>
                <li>Copy the transaction reference number.</li>
                <li>Enter the reference below and submit it for review.</li>
              </ol>
              <?php if ($gcashAccountName !== "" || $gcashAccountNumber !== ""): ?>
                <div class="customer-payment-account" aria-label="GCash account details">
                  <?php if ($gcashAccountName !== ""): ?><p><span>Account name</span><strong><?= service_payment_esc($gcashAccountName) ?></strong></p><?php endif; ?>
                  <?php if ($gcashAccountNumber !== ""): ?><p><span>GCash number</span><strong><?= service_payment_esc($gcashAccountNumber) ?></strong></p><?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="customer-payment-reference">
                <label for="referenceNumberInput">GCash Reference Number<span class="required">*</span></label>
                <input class="form-input" id="referenceNumberInput" name="reference_number" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="120" autocomplete="off" value="<?= service_payment_esc($queue["reference_number"] ?? "") ?>" placeholder="Enter your GCash reference number" aria-describedby="referenceNumberHelp" required>
                <p class="customer-payment-help" id="referenceNumberHelp">Enter the reference number from your GCash receipt.</p>
              </div>
              <p class="customer-payment-reminder"><strong>Important:</strong> <?= $isDraft ? "Your queue will only be created after you submit the required GCash details." : "Your order remains pending until an admin approves the GCash payment." ?></p>
            </div>
          </div>
        </div>
      </div>

      <div class="form-actions form-actions--compact">
        <?php if ($isDraft): ?>
          <button class="btn-back" type="submit" formaction="<?= service_payment_esc(servitech_url('/api/service_payment_cancel.php')) ?>" formnovalidate>Cancel Payment</button>
        <?php else: ?>
          <a class="btn-back" href="<?= service_payment_esc(servitech_url('/pages/customer/custo_service_status.php')) ?>">Back to Queue Status</a>
        <?php endif; ?>
        <button class="btn-next" type="submit"><?= $isDraft ? "Submit Payment & Join Queue" : "Submit Payment for Review" ?></button>
      </div>
    </form>
  <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . "/../../components/footer.php"; ?>
<?php include __DIR__ . "/../../components/queue_modal.php"; ?>
<script>
(function () {
  "use strict";

  var referenceInput = document.getElementById("referenceNumberInput");
  var paymentForm = document.getElementById("servicePaymentForm");
  var digitsOnlyMessage = "Please enter numbers only for the GCash reference number.";

  function sanitizeReference() {
    if (!referenceInput) return;
    var original = referenceInput.value || "";
    var cleaned = original.replace(/\D+/g, "");
    if (original !== cleaned) {
      referenceInput.value = cleaned;
      referenceInput.setCustomValidity(digitsOnlyMessage);
      referenceInput.reportValidity();
    } else {
      referenceInput.setCustomValidity("");
    }
  }

  referenceInput?.addEventListener("input", sanitizeReference);
  referenceInput?.addEventListener("paste", function () {
    window.setTimeout(sanitizeReference, 0);
  });
  paymentForm?.addEventListener("submit", function (event) {
    sanitizeReference();
    if (referenceInput && !/^\d+$/.test(referenceInput.value.trim())) {
      referenceInput.setCustomValidity(digitsOnlyMessage);
      referenceInput.reportValidity();
      event.preventDefault();
      return;
    }
    referenceInput?.setCustomValidity("");
  });

  <?php if ($submitted): ?>
  document.addEventListener("DOMContentLoaded", function () {
    if (window.servitechJoinQueuePostSuccess) {
      window.servitechJoinQueuePostSuccess.markComplete(<?= json_encode((string)($queue["queue_code"] ?? "")) ?>);
    }
    if (typeof window.openQueueSuccessModal === "function") {
      window.openQueueSuccessModal(<?= json_encode((string)($queue["queue_code"] ?? "")) ?>, {
        title: "Queue Successfully Joined",
        message: "Your queue has been submitted successfully. Your GCash payment is now waiting for admin review.",
        service: <?= json_encode($serviceName) ?>,
        note: "You can view your queue while the shop reviews your GCash payment."
      });
    }
  });
  <?php endif; ?>
})();
</script>
</body>
</html>
