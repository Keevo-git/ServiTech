<?php
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/contact.php";
require_once __DIR__ . "/../components/privacy_policy_content.php";

$effectiveDate = "June 13, 2026";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Privacy Policy - ServiTech</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(servitech_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <style>
    body {
      background: #f8f5f2;
      color: #24120f;
      font-family: var(--site-font-sans);
      margin: 0;
    }

    .legal-page {
      max-width: 920px;
      margin: 0 auto;
      padding: 40px 20px 56px;
    }

    .legal-card {
      background: #fff;
      border: 1px solid #eadbd3;
      border-radius: 12px;
      box-shadow: 0 18px 45px rgba(74, 5, 5, 0.08);
      padding: 34px;
    }

    .legal-eyebrow {
      color: #7c130d;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0.08em;
      margin: 0 0 8px;
      text-transform: uppercase;
    }

    h1 {
      color: #4a0505;
      font-size: clamp(30px, 5vw, 44px);
      line-height: 1.05;
      margin: 0 0 8px;
    }

    h2 {
      color: #4a0505;
      font-size: 21px;
      margin: 30px 0 10px;
    }

    p,
    li {
      font-size: 16px;
      line-height: 1.65;
    }

    ul {
      padding-left: 22px;
    }

    a {
      color: #7c130d;
      font-weight: 700;
    }

    .legal-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 32px;
    }

    .legal-button {
      background: #4a0505;
      border: 0;
      border-radius: 999px;
      color: #fff;
      cursor: pointer;
      display: inline-block;
      font: inherit;
      font-weight: 700;
      padding: 12px 18px;
      text-decoration: none;
    }

    .legal-button--light {
      background: #fff7ed;
      color: #4a0505;
    }
  </style>
</head>
<body>
  <main class="legal-page">
    <article class="legal-card">
      <p class="legal-eyebrow">ServiTech Legal</p>
      <h1>Privacy Policy</h1>
      <p><strong>Effective date:</strong> <?= htmlspecialchars($effectiveDate, ENT_QUOTES, "UTF-8") ?></p>

      <?= servitech_privacy_policy_html("page") ?>

      <div class="legal-actions">
        <a class="legal-button" href="<?= htmlspecialchars(servitech_url('/terms-of-service.php'), ENT_QUOTES, 'UTF-8') ?>">Read Terms of Service</a>
        <a class="legal-button legal-button--light" href="#privacy-settings" data-privacy-settings-open>Cookie Preferences</a>
        <a class="legal-button legal-button--light" href="<?= htmlspecialchars(servitech_url('/index.php'), ENT_QUOTES, 'UTF-8') ?>">Back to ServiTech</a>
      </div>
    </article>
  </main>
  <?php require_once __DIR__ . "/../components/cookie_consent.php"; ?>
</body>
</html>
