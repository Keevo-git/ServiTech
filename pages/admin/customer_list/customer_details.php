<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";
require_once __DIR__ . "/../_includes/queue_files.php";
require_once __DIR__ . "/../../../api/queue_payment.php";

$customerId = (int)($_GET["id"] ?? 0);

function cd_esc($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function cd_customer_code(int $id): string {
  return "C-" . str_pad((string)$id, 3, "0", STR_PAD_LEFT);
}

function cd_format_date($value): string {
  $value = trim((string)$value);
  if ($value === "") return "-";
  try {
    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone("Asia/Manila"))->format("M d, Y h:i A");
  } catch (Throwable $exception) {
    return "-";
  }
}

function cd_money($value): string {
  if (!is_numeric($value)) return "PHP 0.00";
  return "PHP " . number_format((float)$value, 2);
}

function cd_details_array($details): array {
  if (is_array($details)) return $details;
  if (is_string($details) && trim($details) !== "") {
    $decoded = json_decode($details, true);
    return is_array($decoded) ? $decoded : [];
  }
  return [];
}

function cd_category_label(string $category): string {
  $category = strtolower(trim($category));
  return match ($category) {
    "online_printorder" => "Online Printing",
    "printing" => "Printing",
    "repair" => "Repair",
    "installation" => "Installation",
    "walkin" => "Walk-in Printing",
    default => $category !== "" ? ucwords(str_replace("_", " ", $category)) : "-",
  };
}

function cd_detail_value(array $details, array $keys): string {
  foreach ($keys as $key) {
    $value = trim((string)($details[$key] ?? ""));
    if ($value !== "") return $value;
  }
  return "";
}

function cd_service_type(array $row): string {
  $details = cd_details_array($row["details"] ?? null);
  $label = cd_detail_value($details, ["service_label", "service_name", "service", "document_type", "request_type"]);
  return $label !== "" ? $label : cd_category_label((string)($row["category"] ?? ""));
}

function cd_payment_method(array $row): string {
  $details = cd_details_array($row["details"] ?? null);
  $method = strtolower(trim((string)($row["payment_method"] ?? ($details["payment_method"] ?? ""))));
  return match ($method) {
    "gcash" => "GCash",
    "cash" => "Cash",
    default => $method !== "" ? ucwords($method) : "-",
  };
}

function cd_payment_status(array $row): string {
  $status = strtoupper(trim((string)($row["status"] ?? "")));
  $paymentStatus = trim((string)($row["payment_status"] ?? ""));
  $payment = servitech_queue_payment_values($row);

  if (in_array($status, ["CANCELLED", "CANCELED"], true)) return "Cancelled";
  if (in_array($status, ["DONE", "COMPLETED"], true) || $payment["paid_pending"] <= 0 && $payment["price"] > 0) return "Paid / Verified";
  if ($paymentStatus !== "" && !in_array(strtoupper($paymentStatus), ["PENDING"], true)) return ucwords(strtolower($paymentStatus));
  if (cd_payment_method($row) !== "-" || trim((string)($row["reference_number"] ?? "")) !== "") return "Payment Submitted";
  if ($payment["price"] > 0) return "Pending Payment";
  return "-";
}

$customer = null;
if ($customerId > 0) {
  $stmt = $pdo->prepare("
    SELECT
      id,
      fullname,
      email,
      COALESCE(NULLIF(to_jsonb(users)->>'contacts', ''), NULLIF(to_jsonb(users)->>'contact', '')) AS contacts,
      to_jsonb(users)->>'created_at' AS created_at
    FROM users
    WHERE id = :id
      AND LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) = 'customer'
    LIMIT 1
  ");
  $stmt->execute([":id" => $customerId]);
  $customer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$customer) {
  http_response_code(404);
}

$history = [];
$summary = [
  "pending_payment" => 0,
  "payment_submitted" => 0,
  "paid_verified" => 0,
  "cancelled" => 0,
];
$hasAttachedFiles = false;

if ($customer) {
  $historyStmt = $pdo->prepare("
    SELECT
      q.id,
      q.queue_code,
      q.category,
      q.status,
      q.details,
      q.price,
      q.paid_amount,
      q.created_at,
      q.completed_at,
      p.payment_method,
      p.reference_number,
      p.amount,
      p.status AS payment_status
    FROM queues q
    LEFT JOIN LATERAL (
      SELECT payment_method, reference_number, amount, status
      FROM payments
      WHERE queue_id = q.id
      ORDER BY id DESC
      LIMIT 1
    ) p ON TRUE
    WHERE q.user_id = :user_id
    ORDER BY q.created_at DESC, q.id DESC
  ");
  $historyStmt->execute([":user_id" => $customerId]);
  $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($history as $row) {
    $paymentStatus = cd_payment_status($row);
    if ($paymentStatus === "Cancelled") $summary["cancelled"]++;
    elseif ($paymentStatus === "Paid / Verified") $summary["paid_verified"]++;
    elseif ($paymentStatus === "Payment Submitted") $summary["payment_submitted"]++;
    elseif ($paymentStatus === "Pending Payment") $summary["pending_payment"]++;
    if (!$hasAttachedFiles && admin_queue_file_items($row["details"] ?? null)) {
      $hasAttachedFiles = true;
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Customer Details</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/assets/css/style.css?v=20260315h2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260604-admin-mobile-nav') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/customer_list/custoL.css?v=20260607-customer-actions') ?>">
</head>
<body>
  <?php
  $adminHeaderMenuId = "admin-customer-details-header-menu";
  $adminHeaderVariant = "special";
  require __DIR__ . "/../_includes/admin_header.php";
  ?>

  <div class="admin-wrapper">
    <section class="admin-hero cd-hero">
      <div>
        <span class="cd-kicker">Customer Details</span>
        <h1><?= $customer ? cd_esc($customer["fullname"] ?: "Customer") : "Customer Not Found" ?></h1>
        <p><?= $customer ? "Profile, service history, payments, and uploaded files." : "The selected customer record is unavailable." ?></p>
      </div>
      <a class="cl-btn cl-btn--light cd-backTop" href="<?= admin_url('/pages/admin/customer_list/custoL.php') ?>">Back to Customer List</a>
    </section>

    <main class="admin-container cl-main">
      <div class="cl-wrap cd-wrap">
        <?php if (!$customer): ?>
          <section class="cl-card cd-emptyState">
            <h2>Customer unavailable</h2>
            <p>This account may have been removed or the link is invalid.</p>
            <a class="cl-btn cl-btn--maroon" href="<?= admin_url('/pages/admin/customer_list/custoL.php') ?>">Back to Customer List</a>
          </section>
        <?php else: ?>
          <section class="cd-profileGrid">
            <article class="cl-card cd-profileCard">
              <span class="cd-cardLabel">Customer ID</span>
              <strong><?= cd_esc(cd_customer_code((int)$customer["id"])) ?></strong>
              <small>Internal ID: <?= (int)$customer["id"] ?></small>
            </article>
            <article class="cl-card cd-profileCard">
              <span class="cd-cardLabel">Full Name</span>
              <strong><?= cd_esc($customer["fullname"] ?: "-") ?></strong>
              <small><?= cd_esc($customer["email"] ?: "-") ?></small>
            </article>
            <article class="cl-card cd-profileCard">
              <span class="cd-cardLabel">Contact</span>
              <strong><?= cd_esc($customer["contacts"] ?: "-") ?></strong>
              <small>Account created: <?= cd_esc(cd_format_date($customer["created_at"] ?? "")) ?></small>
            </article>
          </section>

          <section class="cd-summaryGrid">
            <article class="cd-summaryCard cd-summaryCard--pending"><span>Pending Payment</span><strong><?= $summary["pending_payment"] ?></strong></article>
            <article class="cd-summaryCard cd-summaryCard--submitted"><span>Payment Submitted</span><strong><?= $summary["payment_submitted"] ?></strong></article>
            <article class="cd-summaryCard cd-summaryCard--paid"><span>Paid / Verified</span><strong><?= $summary["paid_verified"] ?></strong></article>
            <article class="cd-summaryCard cd-summaryCard--cancelled"><span>Cancelled Orders</span><strong><?= $summary["cancelled"] ?></strong></article>
          </section>

          <section class="cl-card cd-actionsCard">
            <div>
              <h2>Actions</h2>
              <p>Send a direct notification or return to the customer list.</p>
            </div>
            <div class="cd-actionButtons">
              <button
                class="cl-btn cl-btn--maroon"
                type="button"
                data-detail-message
                data-customer-id="<?= (int)$customer["id"] ?>"
                data-customer-code="<?= cd_esc(cd_customer_code((int)$customer["id"])) ?>"
                data-customer-name="<?= cd_esc($customer["fullname"] ?? "") ?>"
                data-customer-email="<?= cd_esc($customer["email"] ?? "") ?>"
              >Message Customer</button>
              <?php if ($hasAttachedFiles): ?>
                <a class="cl-btn cl-btn--light" href="#customerAttachedFiles">View Attached Files</a>
              <?php endif; ?>
              <a class="cl-btn cl-btn--light" href="<?= admin_url('/pages/admin/customer_list/custoL.php') ?>">Back to Customer List</a>
            </div>
          </section>

          <section class="cl-card cd-historyCard">
            <div class="cd-sectionHead">
              <div>
                <h2>Order / Queue History</h2>
                <p><?= count($history) ?> service record<?= count($history) === 1 ? "" : "s" ?> found.</p>
              </div>
            </div>

            <?php if (!$history): ?>
              <div class="cd-noHistory">No queue or order history yet.</div>
            <?php else: ?>
              <div class="cd-historyList">
                <?php $fileAnchorRendered = false; ?>
                <?php foreach ($history as $row): ?>
                  <?php
                    $files = admin_queue_file_items($row["details"] ?? null);
                    $payment = servitech_queue_payment_values($row);
                    $reference = trim((string)($row["reference_number"] ?? ""));
                    $details = cd_details_array($row["details"] ?? null);
                    if ($reference === "") $reference = trim((string)($details["reference_number"] ?? ""));
                  ?>
                  <article class="cd-historyItem">
                    <div class="cd-historyTop">
                      <div>
                        <span class="cl-idPill"><?= cd_esc($row["queue_code"] ?: ("Order #" . $row["id"])) ?></span>
                        <h3><?= cd_esc(cd_service_type($row)) ?></h3>
                      </div>
                      <span class="cd-statusBadge"><?= cd_esc($row["status"] ?: "PENDING") ?></span>
                    </div>

                    <dl class="cd-historyMeta">
                      <div><dt>Service Category</dt><dd><?= cd_esc(cd_category_label((string)$row["category"])) ?></dd></div>
                      <div><dt>Date Submitted</dt><dd><?= cd_esc(cd_format_date($row["created_at"] ?? "")) ?></dd></div>
                      <div><dt>Payment Method</dt><dd><?= cd_esc(cd_payment_method($row)) ?></dd></div>
                      <div><dt>Total Amount</dt><dd><?= cd_esc(cd_money($payment["price"] ?: ($row["amount"] ?? 0))) ?></dd></div>
                      <div><dt>Payment Status</dt><dd><?= cd_esc(cd_payment_status($row)) ?></dd></div>
                      <div><dt>GCash Reference</dt><dd><?= cd_esc($reference !== "" ? $reference : "-") ?></dd></div>
                    </dl>

                    <?php
                      $fileBlockId = "";
                      if (!$fileAnchorRendered && $files) {
                        $fileBlockId = ' id="customerAttachedFiles"';
                        $fileAnchorRendered = true;
                      }
                    ?>
                    <div class="cd-filesBlock"<?= $fileBlockId ?>>
                      <h4>Attached Files</h4>
                      <?php if (!$files): ?>
                        <span class="cd-fileUnavailable">No attached files</span>
                      <?php else: ?>
                        <div class="cd-fileList">
                          <?php foreach ($files as $file): ?>
                            <?php
                              $label = trim((string)($file["label"] ?? "File"));
                              $url = trim((string)($file["url"] ?? ""));
                              $downloadUrl = $url !== "" ? str_replace("disposition=inline", "disposition=attachment", $url) : "";
                            ?>
                            <div class="cd-fileItem">
                              <span><?= cd_esc($label !== "" ? $label : "File") ?></span>
                              <?php if ($url !== ""): ?>
                                <span class="cd-fileActions">
                                  <a href="<?= cd_esc($url) ?>" target="_blank" rel="noopener noreferrer">Open</a>
                                  <a href="<?= cd_esc($downloadUrl) ?>" download>Download</a>
                                </span>
                              <?php else: ?>
                                <span class="cd-fileUnavailable">File unavailable</span>
                              <?php endif; ?>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>

  <?php if ($customer): ?>
    <div class="cl-modalOverlay" id="customerMessageModal" aria-hidden="true">
      <div class="cl-modalCard" role="dialog" aria-modal="true" aria-labelledby="customerMessageTitle">
        <div class="cl-modalBody">
          <div class="cl-modalHead">
            <div>
              <h3 id="customerMessageTitle">Message Customer</h3>
              <span class="cl-pill cl-pill--inline" id="messageCustomerCode"><?= cd_esc(cd_customer_code((int)$customer["id"])) ?></span>
            </div>
            <button class="cl-modalX" type="button" id="customerMessageClose" aria-label="Close">&times;</button>
          </div>
          <div class="cl-infoCard">
            <p class="cl-infoTitle">Customer</p>
            <div class="cl-infoGrid">
              <div><small>Name</small><div class="cl-infoVal" id="messageCustomerName"><?= cd_esc($customer["fullname"] ?? "-") ?></div></div>
              <div><small>Email</small><div class="cl-infoVal" id="messageCustomerEmail"><?= cd_esc($customer["email"] ?? "-") ?></div></div>
            </div>
          </div>
          <div class="cl-section">
            <label class="cl-sectionTitle" for="customerMessageText">Message</label>
            <textarea class="cl-textarea" id="customerMessageText" rows="6" placeholder="Type your message to this customer..."></textarea>
            <p class="cl-msgStatus" id="customerMessageStatus" aria-live="polite"></p>
          </div>
          <div class="cl-actions">
            <button class="cl-btn cl-btn--light" type="button" id="customerMessageCancel">Cancel</button>
            <button class="cl-btn cl-btn--maroon" type="button" id="customerMessageSend">Send Message</button>
          </div>
        </div>
      </div>
    </div>

    <script>
      const modal = document.getElementById('customerMessageModal');
      const messageText = document.getElementById('customerMessageText');
      const messageStatus = document.getElementById('customerMessageStatus');
      const sendBtn = document.getElementById('customerMessageSend');
      const endpoint = <?= json_encode(admin_url_raw('/pages/admin/customer_list/send_customer_message.php')) ?>;
      const customerId = <?= (int)$customer["id"] ?>;

      function setStatus(text, tone = '') {
        messageStatus.textContent = text || '';
        messageStatus.dataset.tone = tone;
      }
      function openModal() {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        setStatus('');
        setTimeout(() => messageText.focus(), 40);
      }
      function closeModal() {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        messageText.value = '';
      }

      document.querySelector('[data-detail-message]')?.addEventListener('click', openModal);
      document.getElementById('customerMessageClose')?.addEventListener('click', closeModal);
      document.getElementById('customerMessageCancel')?.addEventListener('click', closeModal);
      modal?.addEventListener('click', event => { if (event.target === modal) closeModal(); });
      document.addEventListener('keydown', event => { if (event.key === 'Escape' && modal?.getAttribute('aria-hidden') === 'false') closeModal(); });

      sendBtn?.addEventListener('click', async () => {
        const message = (messageText.value || '').trim();
        if (!message) {
          setStatus('Please type a message before sending.', 'error');
          messageText.focus();
          return;
        }
        const formData = new FormData();
        formData.append('customer_id', String(customerId));
        formData.append('message', message);
        formData.append('csrf_token', typeof window.servitechCsrfToken === 'function' ? window.servitechCsrfToken() : '');
        sendBtn.disabled = true;
        setStatus('Sending message...', 'pending');
        try {
          const response = await fetch(endpoint, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': typeof window.servitechCsrfToken === 'function' ? window.servitechCsrfToken() : '' },
            credentials: 'same-origin'
          });
          const data = await response.json().catch(() => ({}));
          if (!response.ok || !data.ok) throw new Error(data.error || 'Message could not be sent.');
          setStatus(data.message || 'Message added to customer notifications.', 'success');
          setTimeout(closeModal, 900);
        } catch (error) {
          setStatus(error.message || 'Message could not be sent.', 'error');
        } finally {
          sendBtn.disabled = false;
        }
      });
    </script>
  <?php endif; ?>
  <script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
