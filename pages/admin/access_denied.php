<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/url.php";

$roleLabel = servitech_role_label();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Access Denied | ServiTech Admin</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260626-roles') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260626-roles') ?>">
</head>
<body>
<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<main class="admin-owner-shell">
  <section class="admin-owner-hero">
    <span class="admin-owner-kicker">Access Denied</span>
    <h1>Owner access is required</h1>
    <p>Your current role is <?= htmlspecialchars($roleLabel, ENT_QUOTES, "UTF-8") ?>. This page is reserved for Super Admin accounts.</p>
  </section>

  <section class="admin-owner-panel">
    <p class="admin-owner-muted">If you need this page for your work, ask a Super Admin owner to update your permissions.</p>
    <a class="admin-owner-button" href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>">Return to Dashboard</a>
  </section>
</main>
</body>
</html>
