<?php
require_once __DIR__ . "/../admin/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/../admin/_includes/url.php";

$settingsLinks = [
    [
        "title" => "Store Availability",
        "description" => "Manage store status, shop hours, cutoffs, holidays, and service availability.",
        "href" => "/pages/super_admin/super_admin_store_availability.php",
    ],
    [
        "title" => "Service Management",
        "description" => "Update service visibility, options, and customer-facing pricing rules.",
        "href" => "/pages/super_admin/super_admin_service_management.php",
    ],
    [
        "title" => "Announcements",
        "description" => "Publish or hide landing-page notices.",
        "href" => "/pages/super_admin/super_admin_announcement.php",
    ],
    [
        "title" => "Operational Controls",
        "description" => "Temporarily close services or disable payment methods during store operations.",
        "href" => "/pages/super_admin/super_admin_operational_controls.php",
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>System Settings | ServiTech Admin</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260626-roles') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260626-roles') ?>">
</head>
<body>
<?php require __DIR__ . "/../admin/_includes/admin_header.php"; ?>

<main class="admin-owner-shell">
  <section class="admin-owner-hero">
    <span class="admin-owner-kicker">Super Admin</span>
    <h1>System Settings</h1>
    <p>Owner-level configuration links for the existing ServiTech admin system.</p>
  </section>

  <section class="admin-owner-panel">
    <div class="admin-owner-card-grid">
      <?php foreach ($settingsLinks as $link): ?>
        <a class="admin-owner-card-link" href="<?= admin_url($link["href"]) ?>">
          <strong><?= htmlspecialchars($link["title"], ENT_QUOTES, "UTF-8") ?></strong>
          <span><?= htmlspecialchars($link["description"], ENT_QUOTES, "UTF-8") ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
</main>
</body>
</html>
