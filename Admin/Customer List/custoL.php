<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";

$stmt = $pdo->prepare("
  SELECT id, fullname, email, contacts
  FROM users
  WHERE role='customer'
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

  <link rel="stylesheet" href="../../main/style.css">
  <link rel="stylesheet" href="../admin.css">
  <link rel="stylesheet" href="custoL.css">
</head>

<body>
  <header class="navbar">
    <a href="../admin_dashboard.php" class="logo">
      <img src="../../main/IMAGES/LOGO_SERVITECH.png" alt="ServiTech Logo" class="servitech-logo">
      <h1>ServiTech: JC Repair Shop</h1>
    </a>
    <nav>
      <a href="../admin_dashboard.php">Dashboard</a>
      <a href="../logout.php">Logout</a>
    </nav>
  </header>

  <main class="cl-main">
    <div class="cl-wrap">
      <div class="cl-head">
        <h2 class="cl-title">Customer List</h2>
        <a class="cl-btn cl-btn--maroon" href="../Queue%20List/printing.php">View Queue</a>
      </div>

      <div class="cl-card">
        <div class="cl-toolbar">
          <div class="cl-search">
            <span class="cl-searchIcon">🔍</span>
            <input id="searchInput" type="text" placeholder="Search customers by name, email, or contact..." />
          </div>
        </div>

        <div class="cl-tableWrap">
          <table class="cl-table" id="customersTable">
            <thead>
              <tr>
                <th>Customer ID</th>
                <th>Customer Name</th>
                <th>Email</th>
                <th>Contact</th>
                <th class="cl-thActions">Actions</th>
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
                  ?>
                  <tr class="cl-row">
                    <td><span class="cl-idPill"><?= htmlspecialchars($code) ?></span></td>
                    <td class="cl-name"><?= htmlspecialchars($name) ?></td>
                    <td class="cl-email"><?= htmlspecialchars($email) ?></td>
                    <td class="cl-contact"><?= htmlspecialchars($contact) ?></td>
                    <td class="cl-tdActions">
                      <button class="cl-msgBtn" type="button"
                        data-code="<?= htmlspecialchars($code) ?>"
                        data-name="<?= htmlspecialchars($name) ?>"
                        data-email="<?= htmlspecialchars($email) ?>"
                        data-contact="<?= htmlspecialchars($contact) ?>"
                      >Message</button>
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

  <div class="cl-modalOverlay" id="msgModal">
    <div class="cl-modalCard" role="dialog" aria-modal="true">
      <button class="cl-modalX" type="button" id="closeModal">×</button>

      <div class="cl-modalHead">
        <h3>Send Message to Customer</h3>
        <span class="cl-pill" id="mCode">C-000</span>
      </div>

      <div class="cl-infoCard">
        <p class="cl-infoTitle">Customer Contact Information:</p>

        <div class="cl-infoGrid">
          <div>
            <small>Name</small>
            <div class="cl-infoVal" id="mName">—</div>
          </div>

          <div>
            <small>Contact Number</small>
            <div class="cl-copyRow">
              <div class="cl-infoVal" id="mContact">—</div>
              <button class="cl-copyBtn" type="button" data-copy="mContact">Copy</button>
            </div>
          </div>

          <div>
            <small>Email</small>
            <div class="cl-copyRow">
              <div class="cl-infoVal cl-underline" id="mEmail">—</div>
              <button class="cl-copyBtn" type="button" data-copy="mEmail">Copy</button>
            </div>
          </div>
        </div>
      </div>

      <div class="cl-section">
        <p class="cl-sectionTitle">Select Message Template</p>
        <div class="cl-tplGrid">
          <button class="cl-tplBtn" type="button" data-tpl="Ready for Pick-Up">Ready for Pick-Up</button>
          <button class="cl-tplBtn" type="button" data-tpl="Cancellation Confirmation">Cancellation Confirmation</button>
          <button class="cl-tplBtn" type="button" data-tpl="No Available Repair Part">No Available Repair Part</button>
          <button class="cl-tplBtn" type="button" data-tpl="No Available Repairman">No Available Repairman</button>
        </div>
      </div>

      <div class="cl-section">
        <p class="cl-sectionTitle">Message</p>
        <textarea class="cl-textarea" id="mMessage" placeholder="Type your message here..."></textarea>
      </div>

      <div class="cl-actions">
        <button class="cl-btn cl-btn--light" type="button" id="cancelBtn">Cancel</button>
        <a class="cl-btn cl-btn--maroon" id="sendEmailLink" href="#">Send Email</a>
      </div>
    </div>
  </div>

  <footer class="footer">
    <div class="footer-container">
      <div class="footer-left">
        <h3>Contact Us:</h3>

        <div class="contact-item">
          <img src="../../main/IMAGES/FOOTER_FB.png" alt="Facebook">
          <a href="https://www.facebook.com/" target="_blank">JC Store</a>
        </div>

        <div class="contact-item">
          <img src="../../main/IMAGES/FOOTER_EMAIL.png" alt="Email">
          <a href="mailto:servitech@gmail.com">servitech@gmail.com</a>
        </div>

        <div class="contact-item">
          <img src="../../main/IMAGES/FOOTER_PHONE.png" alt="Phone">
          <span>+63 912 393 4321</span>
        </div>
      </div>

      <div class="footer-right">
        <a href="../../main/landing.html" class="footer-logo-link">
          <img src="../../main/IMAGES/LOGO_SERVITECH.png" alt="ServiTech Logo" class="footer-servitech-logo">
          <h1>ServiTech: JC Store</h1>
        </a>
      </div>
    </div>

    <p class="footer-bottom">© 2026 ServiTech: JC Store</p>
  </footer>

  <script>
    const searchInput = document.getElementById('searchInput');
    const rows = Array.from(document.querySelectorAll('#customersTable tbody tr.cl-row'));
    searchInput?.addEventListener('input', () => {
      const q = (searchInput.value || '').toLowerCase();
      rows.forEach(r => r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none');
    });

    const modal = document.getElementById('msgModal');
    const close = () => modal.style.display = 'none';
    document.getElementById('closeModal')?.addEventListener('click', close);
    document.getElementById('cancelBtn')?.addEventListener('click', close);
    modal?.addEventListener('click', (e) => { if(e.target === modal) close(); });

    const templates = {
      "Ready for Pick-Up":
`Good day, our dear customer, mabuhay! This is ServiTech. We are pleased to inform you that your order is now ready for pickup. You may claim your item at our JC Store at your most convenient time. Thank you for trusting our service!`,
      "No Available Repair Part":
`Good day, our dear customer, mabuhay! This is ServiTech. We would like to inform you that the required part for your device repair is currently unavailable. We apologize for the inconvenience. Kindly advise if you prefer to wait or proceed with cancellation. Thank you!`,
      "Cancellation Confirmation":
`Good day, our dear customer, mabuhay! This is ServiTech. We would like to confirm your cancellation request. Please reply YES to finalize the cancellation. Thank you, and we apologize for any inconvenience this may have caused.`,
      "No Available Repairman":
`Good day, our dear customer, mabuhay! This is ServiTech. We would like to notify you that there is currently no available repairman to process your repair request. We sincerely apologize for the inconvenience. We will update you as soon as a technician becomes available. Thank you for your patience.`
    };

    document.querySelectorAll('.cl-msgBtn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.getElementById('mCode').textContent = btn.dataset.code || '';
        document.getElementById('mName').textContent = btn.dataset.name || '';
        document.getElementById('mEmail').textContent = btn.dataset.email || '';
        document.getElementById('mContact').textContent = btn.dataset.contact || '';
        document.getElementById('mMessage').value = '';

        const email = encodeURIComponent(btn.dataset.email || '');
        document.getElementById('sendEmailLink').href =
          `mailto:${email}?subject=` + encodeURIComponent('ServiTech Notification');

        modal.style.display = 'flex';
      });
    });

    document.querySelectorAll('.cl-tplBtn').forEach(b => {
      b.addEventListener('click', () => {
        const key = b.dataset.tpl;
        const msg = templates[key] || '';
        const ta = document.getElementById('mMessage');
        ta.value = msg + "\n\n";
        ta.focus();
        ta.selectionStart = ta.selectionEnd = ta.value.length;
      });
    });

    document.querySelectorAll('.cl-copyBtn').forEach(b => {
      b.addEventListener('click', async () => {
        const id = b.dataset.copy;
        const txt = document.getElementById(id)?.textContent || '';
        try { await navigator.clipboard.writeText(txt); } catch(e) {}
      });
    });
  </script>
</body>
</html>
