<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";

$stmt = $pdo->prepare("
  SELECT
    id,
    fullname,
    email,
    COALESCE(
      NULLIF(to_jsonb(users)->>'contacts', ''),
      NULLIF(to_jsonb(users)->>'contact', '')
    ) AS contacts
  FROM users
  WHERE LOWER(
    COALESCE(
      NULLIF(to_jsonb(users)->>'role', ''),
      'customer'
    )
  ) = 'customer'
  ORDER BY id ASC
");
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

function customer_code_from_id(int $id): string {
  return "C-" . str_pad((string)$id, 3, "0", STR_PAD_LEFT);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Customer List</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/assets/css/style.css?v=20260621-global-ui-polish') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260619-hero-actions') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/customer_list/custoL.css?v=20260621-global-ui-polish') ?>">
</head>

<body>

  <?php
  $adminHeaderMenuId = "admin-customer-header-menu";
  $adminHeaderVariant = "special";
  require __DIR__ . "/../_includes/admin_header.php";
  ?>

  <div class="admin-wrapper">
    <section class="admin-hero admin-hero--actions">
      <div class="admin-hero-text">
        <h1>Customer List</h1>
        <p>View registered customers and search account details.</p>
      </div>
      <div class="admin-hero-actions" aria-label="Customer List actions">
        <button type="button" class="hero-btn hero-btn-secondary" onclick="goAdminBack()">Back</button>
        <a class="hero-btn hero-btn-primary" href="<?= admin_url('/pages/admin/queue_list/printing.php') ?>">View Queue</a>
        <a class="hero-btn hero-btn-primary" href="<?= admin_url('/pages/admin/order_management/printM.php') ?>">View Orders</a>
      </div>
    </section>

  <main class="admin-container cl-main">
    <div class="cl-wrap">
      <div class="cl-card">
        <div class="cl-toolbar">
          <div class="cl-search">
            <input id="searchInput" type="text" placeholder="Search customers by name, email, or contact..." />
          </div>
        </div>

        <div class="cl-tableWrap table-scroll-wrapper">
          <table class="cl-table table-content" id="customersTable">
            <thead>
              <tr>
                <th>Customer ID</th>
                <th>Customer Name</th>
                <th>Email</th>
                <th>Contact</th>
                <th>Action</th>
              </tr>
            </thead>

            <tbody>
              <?php if (!$customers): ?>
                <tr>
                  <td colspan="5" class="cl-empty">No registered customers yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($customers as $c): ?>
                  <?php
                    $code = customer_code_from_id((int)$c["id"]);
                    $name = (string)($c["fullname"] ?? "");
                    $email = (string)($c["email"] ?? "");
                    $contact = (string)($c["contacts"] ?? "");
                    $detailsUrl = admin_url('/pages/admin/customer_list/customer_details.php?id=' . (int)$c["id"]);
                  ?>
                  <tr
                    class="cl-row"
                    data-details-url="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, "UTF-8") ?>"
                    data-customer-id="<?= (int)$c["id"] ?>"
                    data-customer-code="<?= htmlspecialchars($code, ENT_QUOTES, "UTF-8") ?>"
                    data-customer-name="<?= htmlspecialchars($name, ENT_QUOTES, "UTF-8") ?>"
                    data-customer-email="<?= htmlspecialchars($email, ENT_QUOTES, "UTF-8") ?>"
                  >
                    <td><span class="cl-idPill"><?= htmlspecialchars($code) ?></span></td>
                    <td class="cl-name">
                      <a class="cl-nameLink" href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($name) ?></a>
                    </td>
                    <td class="cl-email"><?= htmlspecialchars($email) ?></td>
                    <td class="cl-contact"><?= htmlspecialchars($contact) ?></td>
                    <td>
                      <div class="cl-row-actions">
                        <button class="cl-btn cl-btn--message" type="button" data-message-customer>Message</button>
                        <a class="cl-btn cl-btn--details" href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, "UTF-8") ?>">View Details</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>
  </div>

  <?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>

  <div class="cl-modalOverlay" id="customerMessageModal" aria-hidden="true">
    <div class="cl-modalCard cl-messageModalCard" role="dialog" aria-modal="true" aria-labelledby="customerMessageTitle">
      <div class="cl-modalBody cl-messageModalBody">
        <div class="cl-modalHead cl-messageModalHead">
          <div class="cl-messageModalTitleBlock">
            <h3 id="customerMessageTitle">Message Customer</h3>
            <span class="cl-pill cl-pill--inline" id="messageCustomerCode">C-000</span>
          </div>
          <button class="cl-modalX" type="button" id="customerMessageClose" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>

        <div class="cl-infoCard cl-messageInfoCard">
          <p class="cl-infoTitle">Customer</p>
          <div class="cl-infoGrid">
            <div>
              <small>Name</small>
              <div class="cl-infoVal" id="messageCustomerName">-</div>
            </div>
            <div>
              <small>Email</small>
              <div class="cl-infoVal" id="messageCustomerEmail">-</div>
            </div>
          </div>
        </div>

        <div class="cl-section cl-messageSection">
          <label class="cl-sectionTitle" for="customerMessageText">Message</label>
          <textarea class="cl-textarea" id="customerMessageText" rows="6" placeholder="Type your message to this customer..."></textarea>
          <p class="cl-msgStatus" id="customerMessageStatus" aria-live="polite"></p>
        </div>

        <div class="cl-actions modal-actions cl-messageActions">
          <button class="cl-btn cl-btn--light" type="button" id="customerMessageCancel">Cancel</button>
          <button class="cl-btn cl-btn--maroon" type="button" id="customerMessageSend">Send Message</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    const searchInput = document.getElementById('searchInput');
    const rows = Array.from(document.querySelectorAll('#customersTable tbody tr.cl-row'));
    searchInput?.addEventListener('input', () => {
      const q = (searchInput.value || '').toLowerCase();
      rows.forEach(r => r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none');
    });

    const messageModal = document.getElementById('customerMessageModal');
    const messageClose = document.getElementById('customerMessageClose');
    const messageCancel = document.getElementById('customerMessageCancel');
    const messageSend = document.getElementById('customerMessageSend');
    const messageText = document.getElementById('customerMessageText');
    const messageStatus = document.getElementById('customerMessageStatus');
    const messageCustomerCode = document.getElementById('messageCustomerCode');
    const messageCustomerName = document.getElementById('messageCustomerName');
    const messageCustomerEmail = document.getElementById('messageCustomerEmail');
    const sendEndpoint = <?= json_encode(admin_url_raw('/pages/admin/customer_list/send_customer_message.php')) ?>;

    function setMessageStatus(text, tone = '') {
      if (!messageStatus) return;
      messageStatus.textContent = text || '';
      messageStatus.dataset.tone = tone;
    }

    function openMessageModal(row) {
      if (!messageModal || !row) return;
      messageModal.dataset.customerId = row.dataset.customerId || '';
      messageCustomerCode.textContent = row.dataset.customerCode || 'C-000';
      messageCustomerName.textContent = row.dataset.customerName || '-';
      messageCustomerEmail.textContent = row.dataset.customerEmail || '-';
      messageText.value = '';
      setMessageStatus('');
      messageModal.style.display = 'flex';
      messageModal.setAttribute('aria-hidden', 'false');
      setTimeout(() => messageText?.focus(), 40);
    }

    function closeMessageModal() {
      if (!messageModal) return;
      messageModal.style.display = 'none';
      messageModal.setAttribute('aria-hidden', 'true');
      delete messageModal.dataset.customerId;
    }

    document.querySelectorAll('[data-message-customer]').forEach(button => {
      button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        openMessageModal(button.closest('tr.cl-row'));
      });
    });

    rows.forEach(row => {
      row.addEventListener('click', event => {
        if (event.target.closest('a, button, input, textarea, select')) return;
        const url = row.dataset.detailsUrl || '';
        if (url) window.location.href = url;
      });
    });

    messageClose?.addEventListener('click', closeMessageModal);
    messageCancel?.addEventListener('click', closeMessageModal);
    messageModal?.addEventListener('click', event => {
      if (event.target === messageModal) closeMessageModal();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && messageModal?.getAttribute('aria-hidden') === 'false') {
        closeMessageModal();
      }
    });

    messageSend?.addEventListener('click', async () => {
      const customerId = messageModal?.dataset.customerId || '';
      const message = (messageText?.value || '').trim();
      if (!customerId) {
        setMessageStatus('Customer is missing.', 'error');
        return;
      }
      if (!message) {
        setMessageStatus('Please type a message before sending.', 'error');
        messageText?.focus();
        return;
      }

      const formData = new FormData();
      formData.append('customer_id', customerId);
      formData.append('message', message);
      formData.append('csrf_token', typeof window.servitechCsrfToken === 'function' ? window.servitechCsrfToken() : '');

      messageSend.disabled = true;
      setMessageStatus('Sending message...', 'pending');
      try {
        const response = await fetch(sendEndpoint, {
          method: 'POST',
          body: formData,
          headers: { 'X-CSRF-Token': typeof window.servitechCsrfToken === 'function' ? window.servitechCsrfToken() : '' },
          credentials: 'same-origin'
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) {
          throw new Error(data.error || 'Message could not be sent.');
        }
        setMessageStatus(data.message || 'Message sent to customer notifications.', 'success');
        setTimeout(closeMessageModal, 900);
      } catch (error) {
        setMessageStatus(error.message || 'Message could not be sent.', 'error');
      } finally {
        messageSend.disabled = false;
      }
    });
  </script>

  <script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
