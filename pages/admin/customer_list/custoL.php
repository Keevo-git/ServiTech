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
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/assets/css/style.css?v=20260315h2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260601-clean-notification') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/customer_list/custoL.css?v=20260521responsive') ?>">
</head>

<body>

  <?php
  $adminHeaderMenuId = "admin-customer-header-menu";
  require __DIR__ . "/../_includes/admin_header.php";
  ?>

  <div class="admin-wrapper">
    <section class="admin-hero">
      <h1>Customer List</h1>
      <p>View registered customers and search account details.</p>
    </section>

  <main class="admin-container cl-main">
    <div class="cl-wrap">
      <div class="cl-head">
        <h2 class="cl-title">Customer List</h2>
        <a class="cl-btn cl-btn--maroon" href="<?= admin_url('/pages/admin/queue_list/printing.php') ?>">View Queue</a>
      </div>

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
              </tr>
            </thead>

            <tbody>
              <?php if (!$customers): ?>
                <tr>
                  <td colspan="4" class="cl-empty">No registered customers yet.</td>
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

  <script>
    const searchInput = document.getElementById('searchInput');
    const rows = Array.from(document.querySelectorAll('#customersTable tbody tr.cl-row'));
    searchInput?.addEventListener('input', () => {
      const q = (searchInput.value || '').toLowerCase();
      rows.forEach(r => r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none');
    });
  </script>

  <script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
