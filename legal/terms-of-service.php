<?php
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/contact.php";

$supportEmail = servitech_contact_email();
$effectiveDate = "June 13, 2026";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Terms of Service - ServiTech</title>
  <?= servitech_favicon_link() ?>
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
      <h1>Terms of Service</h1>
      <p><strong>Effective date:</strong> <?= htmlspecialchars($effectiveDate, ENT_QUOTES, "UTF-8") ?></p>

      <p>By creating an account and using ServiTech, users agree to follow these Terms of Service. Users who do not agree should not create an account or use the platform.</p>

      <h2>Service Description</h2>
      <p>ServiTech provides account registration, login, customer queueing, Document Printing order submission, uploaded file handling, service status tracking, notifications, payment-detail recording, profile editing, and administrator management for printing, repair, installation, laminating, rush ID, and related services.</p>

      <h2>Academic and Operational Purpose</h2>
      <p>ServiTech is an academic capstone/project system developed for educational, demonstration, testing, evaluation, and service-management workflows. Users must use the system according to applicable school, project, and service requirements.</p>

      <h2>User Accounts</h2>
      <p>Users are responsible for the accuracy of their account details and for keeping their login credentials secure. Google sign-in may link the account to a Google-issued identity and verified Google email.</p>

      <h2>Permitted Uses</h2>
      <ul>
        <li>Create and manage legitimate service requests.</li>
        <li>Upload files required for requested services.</li>
        <li>View queue, order, payment, notification, and service status records.</li>
        <li>Update profile information and password where allowed.</li>
        <li>Use the system for approved printing, repair, installation, laminating, rush ID, and related service workflows.</li>
      </ul>

      <h2>Prohibited Uses</h2>
      <ul>
        <li>Submitting false, misleading, or incomplete information.</li>
        <li>Uploading harmful, illegal, offensive, executable, or unauthorized copyrighted files.</li>
        <li>Sharing, lending, or misusing another person's account.</li>
        <li>Spamming requests, abusing forms, or overloading the system.</li>
        <li>Attempting to bypass login, CSRF protection, role checks, file restrictions, or other security controls.</li>
        <li>Accessing another user's account, queue, payment details, notifications, or uploaded files without authorization.</li>
      </ul>

      <h2>Requests, Status, and Cancellations</h2>
      <p>ServiTech creates queue and order records for submitted service requests. Requests move through the status flow managed by the system and administrators. Customers may cancel only their own pending requests where the workflow allows it. Administrators may update statuses, send requests back for editing, and record operational notes.</p>

      <h2>Payments and Transaction Details</h2>
      <p>ServiTech records payment method, order amount, paid amount, and reference details for review and tracking. ServiTech does not process payment through an external payment gateway.</p>

      <h2>Uploaded Files and User Content</h2>
      <p>Users are responsible for files and content they upload. Upload-enabled services accept only allowed file types and sizes. Uploaded files are stored privately and linked to the user's request or order. Users must have the right to upload, print, use, or reproduce submitted files.</p>

      <h2>Admin Access</h2>
      <p>Administrators can access operational records needed to manage customers, queues, orders, payment review, service listings, announcements, notifications, and uploaded file references. Admin actions are logged or reflected in status history where applicable.</p>

      <h2>Availability and Changes</h2>
      <p>ServiTech depends on hosting, database, authentication, email, file storage, internet connection, and configured third-party services. The system does not guarantee uninterrupted access or error-free operation. ServiTech may update these terms to match system, academic, security, or workflow changes.</p>

      <h2>Contact</h2>
      <p>For questions about these terms, contact ServiTech at
        <?php if ($supportEmail !== ""): ?>
          <a href="mailto:<?= htmlspecialchars($supportEmail, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($supportEmail, ENT_QUOTES, "UTF-8") ?></a>.
        <?php else: ?>
          the official ServiTech support channel.
        <?php endif; ?>
      </p>

      <div class="legal-actions">
        <a class="legal-button" href="<?= htmlspecialchars(servitech_url('/privacy-policy.php'), ENT_QUOTES, 'UTF-8') ?>">Read Privacy Policy</a>
        <a class="legal-button legal-button--light" href="<?= htmlspecialchars(servitech_url('/privacy-policy.php#privacy-settings'), ENT_QUOTES, 'UTF-8') ?>" data-privacy-settings-open>Cookie Preferences</a>
        <a class="legal-button legal-button--light" href="<?= htmlspecialchars(servitech_url('/index.php'), ENT_QUOTES, 'UTF-8') ?>">Back to ServiTech</a>
      </div>
    </article>
  </main>
  <?php require_once __DIR__ . "/../components/cookie_consent.php"; ?>
</body>
</html>
