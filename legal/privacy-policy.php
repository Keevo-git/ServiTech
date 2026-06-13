<?php
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/mail.php";

$supportEmail = servitech_smtp_public_from_email();
$effectiveDate = "June 13, 2026";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Privacy Policy - ServiTech</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(servitech_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <style>
    body {
      background: #f8f5f2;
      color: #24120f;
      font-family: Arial, Helvetica, sans-serif;
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
      border-radius: 999px;
      color: #fff;
      display: inline-block;
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

      <p>ServiTech respects user privacy and protects personal information handled by the system. This Privacy Policy explains what information ServiTech collects, how it is used, where it is stored, who can access it, and what rights users have.</p>

      <h2>Information We Collect</h2>
      <ul>
        <li>Account details such as full name, email address, contact number, account role, consent timestamp, and account status.</li>
        <li>Authentication-related details such as password hashes, Google account ID for Google sign-in, password reset tokens, and related timestamps.</li>
        <li>Service request, queue, order, payment-detail, notification, feedback, and status-history records created while using ServiTech.</li>
        <li>Uploaded file metadata such as original filename, private storage key, file extension, MIME type, file size, checksum, upload token, linked request, and deletion date.</li>
        <li>Technical and security information such as session cookies, CSRF tokens, timestamps, request logs, and failed-login throttle records where applicable.</li>
      </ul>

      <h2>How We Use Information</h2>
      <p>ServiTech uses collected information to create and manage accounts, process service requests, manage queue and order records, review payment details, provide notifications, protect private uploaded files, support customer/admin workflows, troubleshoot issues, and maintain system security.</p>

      <h2>Authentication and Roles</h2>
      <p>ServiTech uses Supabase Auth for account authentication when enabled. Customer and admin access is separated by role. Customers can view and manage only their own allowed records, while admins can access operational records needed to manage requests, orders, payments, notifications, and uploaded file references.</p>

      <h2>Uploaded Files</h2>
      <p>Uploaded files are stored in private Hostinger file storage and are not intended to be directly public. Supabase stores only metadata and references. Files are accessed through protected ServiTech endpoints that check login, ownership, and admin authorization.</p>

      <h2>Data Retention</h2>
      <p>Files linked to active requests remain available while operationally needed. Files linked to done or cancelled requests may be retained for 30 days for rechecking, disputes, and authorized review, then deleted. Temporary failed, cancelled, abandoned, or unlinked uploads may be deleted within 24 hours. Queue, payment, notification, and status-history records may remain so service history stays accurate.</p>

      <h2>Sharing and Access</h2>
      <p>ServiTech does not sell user information. Access is limited to the user, authorized admins, project operators, and service providers needed to operate the website, database, authentication, email, and file-storage systems.</p>

      <h2>User Rights</h2>
      <p>Under the Philippine Data Privacy Act of 2012, users may request access, correction, deletion where appropriate, a copy of their personal data, or review of privacy concerns.</p>

      <h2>Contact</h2>
      <p>For privacy questions or requests, contact ServiTech at
        <?php if ($supportEmail !== ""): ?>
          <a href="mailto:<?= htmlspecialchars($supportEmail, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($supportEmail, ENT_QUOTES, "UTF-8") ?></a>.
        <?php else: ?>
          the official ServiTech support channel.
        <?php endif; ?>
      </p>

      <div class="legal-actions">
        <a class="legal-button" href="<?= htmlspecialchars(servitech_url('/terms-of-service.php'), ENT_QUOTES, 'UTF-8') ?>">Read Terms of Service</a>
        <a class="legal-button legal-button--light" href="<?= htmlspecialchars(servitech_url('/index.php'), ENT_QUOTES, 'UTF-8') ?>">Back to ServiTech</a>
      </div>
    </article>
  </main>
</body>
</html>
