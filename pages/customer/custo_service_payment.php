<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/app.php";

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
    service_payment_add_row($rows, "Paper size", $details["paper_size"] ?? "");
    service_payment_add_row($rows, "Color", $details["color_option"] ?? "");
    service_payment_add_row($rows, "Copies", $details["quantity"] ?? "");
    service_payment_add_row($rows, "Total pages", $details["total_pages"] ?? "");
    service_payment_add_row($rows, "Files", implode(", ", array_map("strval", (array)($details["file_names"] ?? []))));
  } elseif (str_contains($service, "xerox") || str_contains($service, "photocopy")) {
    service_payment_add_row($rows, "Paper size", $details["paper_size"] ?? "");
    service_payment_add_row($rows, "Color", $details["color_option"] ?? "");
    service_payment_add_row($rows, "Copies", $details["quantity"] ?? "");
  } elseif (str_contains($service, "rush") && str_contains($service, "id")) {
    service_payment_add_row($rows, "ID package", $details["package_label"] ?? "");
    service_payment_add_row($rows, "Sets", $details["quantity"] ?? "");
    $addons = [];
    foreach ((array)($details["add_ons_snapshot"] ?? []) as $addon) {
      if (is_array($addon) && trim((string)($addon["name"] ?? "")) !== "") $addons[] = trim((string)$addon["name"]);
    }
    service_payment_add_row($rows, "Add-ons", implode(", ", $addons));
  } elseif (str_contains($service, "laminat")) {
    service_payment_add_row($rows, "Lamination type", $details["lamination_type"] ?? ($details["option_name_snapshot"] ?? ""));
    service_payment_add_row($rows, "Items", $details["quantity"] ?? "");
  } elseif (str_contains($service, "scan")) {
    service_payment_add_row($rows, "Paper size", $details["paper_size"] ?? "");
    service_payment_add_row($rows, "Items", $details["quantity"] ?? "");
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

$queueId = (int)($_GET["queue_id"] ?? ($_SESSION["service_payment_queue_id"] ?? 0));
$userId = (int)($_SESSION["user_id"] ?? 0);
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

$details = service_payment_details($queue["details"] ?? null);
$serviceName = trim((string)($details["service_label"] ?? ($details["catalog_service_name"] ?? "Service")));
$detailRows = service_payment_rows($details);
$total = $queue["price"] ?? ($details["estimated_total"] ?? ($queue["amount"] ?? null));
$flashError = trim((string)($_SESSION["service_payment_flash_error"] ?? ""));
unset($_SESSION["service_payment_flash_error"]);
$submitted = (string)($_GET["submitted"] ?? "") === "1"
  && is_array($_SESSION["service_payment_confirmation"] ?? null)
  && (int)($_SESSION["service_payment_confirmation"]["queue_id"] ?? 0) === $queueId;
if ($submitted) unset($_SESSION["service_payment_confirmation"]);
$paymentStatus = strtoupper(trim((string)($queue["payment_status"] ?? "PENDING")));
$reviewed = in_array($paymentStatus, ["APPROVED", "PAID", "CANCELLED"], true);
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
  <style>
    .service-payment-page{background:#faf8f7}.service-payment-shell{display:grid;gap:1.25rem;margin:0 auto;max-width:900px;padding:clamp(1rem,3vw,2rem);width:100%}.service-payment-card{background:#fff;border:1px solid rgba(95,14,15,.14);border-radius:22px;box-shadow:0 12px 30px rgba(95,14,15,.06);padding:clamp(1rem,3vw,1.6rem)}.service-payment-head{display:flex;align-items:flex-start;gap:1rem;justify-content:space-between}.service-payment-badge{background:#e8f0ff;border-radius:999px;color:#1260b4;font-weight:800;padding:.45rem .8rem;white-space:nowrap}.service-payment-grid{display:grid;gap:.75rem;grid-template-columns:repeat(2,minmax(0,1fr));margin-top:1rem}.service-payment-row{background:#faf7f5;border-radius:14px;padding:.8rem}.service-payment-row span{color:#6d625f;display:block;font-size:.78rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}.service-payment-row strong{display:block;margin-top:.2rem;overflow-wrap:anywhere}.service-payment-total{background:#f6e5df;border:1px solid rgba(95,14,15,.12);border-radius:16px;color:#5f0e0f;margin-top:1rem;padding:1rem}.service-payment-total strong{display:block;font-size:clamp(1.7rem,5vw,2.2rem)}.service-payment-instructions{display:grid;gap:1rem;grid-template-columns:minmax(150px,220px) 1fr}.service-payment-qr{border:2px solid #5f0e0f;border-radius:16px;max-width:220px;padding:.6rem;width:100%}.service-payment-qr img{display:block;height:auto;width:100%}.service-payment-steps{margin:.5rem 0 1rem;padding-left:1.25rem}.service-payment-form label{display:block;font-weight:700;margin-bottom:.4rem}.service-payment-form input{border:1px solid #bdb4b1;border-radius:12px;font-size:1rem;min-height:50px;padding:.75rem;width:100%}.service-payment-actions{display:flex;gap:.75rem;justify-content:flex-end;margin-top:1rem}.service-payment-error{background:#fff0f0;border:1px solid #e5aaaa;border-radius:12px;color:#9b1c1c;padding:.75rem}.service-payment-success{text-align:center}.service-payment-success h2{color:#286b3a}.service-payment-note{color:#675d5a}.service-payment-page .btn-next,.service-payment-page .btn-back{align-items:center;display:inline-flex;justify-content:center;text-decoration:none}@media(max-width:640px){.service-payment-grid{grid-template-columns:1fr}.service-payment-head{display:grid}.service-payment-instructions{grid-template-columns:1fr}.service-payment-qr{margin:auto}.service-payment-actions{display:grid}.service-payment-actions>*{width:100%}}
  </style>
</head>
<body class="customer-layout service-payment-page">
<?php include __DIR__ . "/../../components/header.php"; ?>
<main class="service-payment-shell">
  <?php if ($submitted || $reviewed): ?>
    <section class="service-payment-card service-payment-success">
      <h2><?= $paymentStatus === "APPROVED" || $paymentStatus === "PAID" ? "GCash payment approved" : ($paymentStatus === "CANCELLED" ? "Payment cancelled" : "GCash payment submitted") ?></h2>
      <p><?php if ($paymentStatus === "APPROVED" || $paymentStatus === "PAID"): ?>Your GCash payment for Queue <?= service_payment_esc($queue["queue_code"]) ?> has been approved.<?php elseif ($paymentStatus === "CANCELLED"): ?>This payment and queue/order have been cancelled.<?php else: ?>Your payment for Queue <?= service_payment_esc($queue["queue_code"]) ?> is pending admin review. It will not be treated as paid until approved.<?php endif; ?></p>
      <div class="service-payment-actions"><a class="btn-next" href="<?= service_payment_esc(servitech_url('/pages/customer/custo_service_status.php')) ?>">View queue status</a></div>
    </section>
  <?php else: ?>
    <header>
      <h1 class="page-title">Complete your GCash payment</h1>
      <p class="page-subtitle">Review your order, send the exact total through GCash, then enter the reference number.</p>
    </header>
    <section class="service-payment-card">
      <div class="service-payment-head"><div><h2><?= service_payment_esc($serviceName) ?></h2><p class="service-payment-note">Payment status: Pending review</p></div><span class="service-payment-badge">GCash</span></div>
      <div class="service-payment-grid">
        <div class="service-payment-row"><span>Queue / order number</span><strong><?= service_payment_esc($queue["queue_code"]) ?></strong></div>
        <div class="service-payment-row"><span>Customer name</span><strong><?= service_payment_esc($queue["fullname"]) ?></strong></div>
        <div class="service-payment-row"><span>Selected service</span><strong><?= service_payment_esc($serviceName) ?></strong></div>
        <div class="service-payment-row"><span>Payment method</span><strong>GCash</strong></div>
        <?php foreach ($detailRows as $row): ?><div class="service-payment-row"><span><?= service_payment_esc($row["label"]) ?></span><strong><?= service_payment_esc($row["value"]) ?></strong></div><?php endforeach; ?>
      </div>
      <div class="service-payment-total"><span>Total amount</span><strong><?= service_payment_esc(service_payment_money($total)) ?></strong></div>
    </section>
    <section class="service-payment-card">
      <div class="service-payment-instructions">
        <div class="service-payment-qr"><img src="/assets/images/gcash-qr.jpg" alt="JC Shop GCash QR code"></div>
        <div>
          <h2>GCash instructions</h2>
          <ol class="service-payment-steps"><li>Scan the QR code in your GCash app.</li><li>Send the exact total shown above.</li><li>Copy the 13-digit GCash reference number.</li><li>Submit it below for admin review.</li></ol>
          <?php if ($flashError !== ""): ?><p class="service-payment-error" role="alert"><?= service_payment_esc($flashError) ?></p><?php endif; ?>
          <form class="service-payment-form" method="post" action="<?= service_payment_esc(servitech_url('/api/service_payment_create.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= service_payment_esc($csrfToken) ?>">
            <input type="hidden" name="queue_id" value="<?= (int)$queueId ?>">
            <label for="referenceNumber">GCash reference number</label>
            <input id="referenceNumber" name="reference_number" inputmode="numeric" pattern="[0-9]{13}" maxlength="13" value="<?= service_payment_esc($queue["reference_number"] ?? "") ?>" placeholder="Enter 13 digits" required>
            <p class="service-payment-note">Your queue remains pending until an admin approves this payment.</p>
            <div class="service-payment-actions"><button class="btn-next" type="submit">Submit for review</button></div>
          </form>
        </div>
      </div>
    </section>
  <?php endif; ?>
</main>
<?php include __DIR__ . "/../../components/footer.php"; ?>
</body>
</html>
