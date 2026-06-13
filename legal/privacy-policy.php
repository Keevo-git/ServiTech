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

      <p>ServiTech respects user privacy and protects personal information handled by the system. This Privacy Policy explains what information ServiTech collects, how it is used, where it is stored, who can access it, how long it is kept, and how users can raise privacy concerns.</p>

      <h2>Information We Collect</h2>
      <ul>
        <li>Account details such as full name, email address, contact number, account role, consent timestamp, and account status.</li>
        <li>Authentication-related details such as password hashes, Google account ID for Google sign-in, password reset tokens, and related timestamps.</li>
        <li>Service request, queue, order, payment-detail, notification, admin message, feedback, send-back, and status-history records created while using ServiTech.</li>
        <li>Uploaded file metadata such as original filename, private storage key, file extension, MIME type, file size, checksum, upload token, linked request, and deletion date.</li>
        <li>Technical and security information such as session cookies, CSRF tokens, timestamps, request logs, and failed-login throttle records where applicable.</li>
      </ul>

      <h2>Where Information Comes From</h2>
      <p>ServiTech collects information through registration, login, Google sign-in, email verification, password reset, profile updates, queue and service request forms, upload forms, online print order payment forms, customer notifications, and administrator actions such as status updates, send-back messages, customer messages, payment review, service management, and announcements.</p>

      <h2>How We Use Information</h2>
      <p>ServiTech uses collected information to create and manage accounts, process service requests, manage queue and order records, review payment details, provide notifications, protect private uploaded files, support customer/admin workflows, troubleshoot issues, and maintain system security.</p>

      <h2>Legal Basis and Consent</h2>
      <p>ServiTech asks users to acknowledge this Privacy Policy and the Terms of Service during account creation. The system stores the consent date and policy version for account traceability. Most processing is used to provide the requested service, manage the customer account, protect the system, keep operational records, and support the academic capstone/project purpose. Any future use beyond these purposes, such as marketing or unrelated sharing, requires a separate review and an appropriate notice or consent flow.</p>

      <h2>Authentication and Roles</h2>
      <p>ServiTech uses Supabase Auth for account authentication when enabled. Customer and admin access is separated by role. Customers can view and manage only their own allowed records, while admins can access operational records needed to manage requests, orders, payments, notifications, and uploaded file references.</p>

      <h2>Uploaded Files</h2>
      <p>Uploaded files are stored in private server-side upload storage using random storage keys and are not intended to be directly public. The database stores metadata and file references. Files are accessed through protected ServiTech endpoints that check login, ownership, active file status, and admin authorization. Users should upload only files needed for the requested service and should avoid including unnecessary personal or sensitive information.</p>

      <h2>Data Retention</h2>
      <p>Files linked to active requests remain available while operationally needed. Files linked to Done or Cancelled requests may be retained for 30 days for rechecking, disputes, and authorized review, then deleted by the upload-retention cleanup job. Temporary failed, cancelled, abandoned, or unlinked uploads may be deleted within 24 hours. Queue, payment, notification, and status-history records may remain after file deletion so service history stays accurate, but deleted or expired file content should no longer be accessible.</p>

      <h2>Sharing and Access</h2>
      <p>ServiTech does not sell user information. Access is limited to the user, authorized administrators, project operators, and service providers needed to operate the website, database, authentication, email, and file-storage systems. Depending on configuration, these providers may include the hosting provider, Supabase for database/authentication services, Google for Google sign-in, and email/SMTP services for account or notification emails.</p>

      <h2>Security Measures</h2>
      <p>ServiTech uses role-based access checks, customer ownership checks, admin-only page guards, password hashing, CSRF tokens, SameSite/HTTP-only session cookies, failed-login throttling with hashed identifiers, file type and size validation, private upload storage, random file storage keys, protected download endpoints, and status-history records for queue actions. These controls reduce risk but do not replace organizational safeguards such as limiting administrator access, protecting hosting credentials, securing backups, and reviewing logs.</p>

      <h2>Cookies and Browser Storage</h2>
      <p>ServiTech uses strictly necessary cookies and browser storage for login sessions, Google authentication when selected, CSRF protection, security checks, upload continuity, form safety, notifications, and short-lived workflow messages. These are required for the website or requested authentication flow to operate and cannot be disabled through the cookie preferences tool.</p>
      <p>Optional functional enhancements are controlled by the user's cookie preference. The current optional enhancement is Supabase realtime notification delivery. If functional enhancements are rejected, regular notification polling remains available.</p>
      <p>ServiTech does not currently use analytics, advertising, or marketing tracking cookies. Users can change their cookie choice later through the Cookie Preferences link in the footer.</p>

      <h2>User Rights</h2>
      <p>Under the Philippine Data Privacy Act of 2012, users may request access, correction, deletion or blocking where appropriate, a copy of their personal data where feasible, objection or withdrawal where processing is based on consent, or review of privacy concerns. The customer profile page supports correction of account details. Other requests may require manual review because service history, payment review, security, academic evaluation, or legal requirements may require some records to be retained.</p>

      <h2>Security Incidents</h2>
      <p>If ServiTech discovers a possible personal data breach or security incident, the project operators should contain the incident, preserve relevant logs and evidence, assess affected data and users, document remediation, notify responsible personnel, and determine whether notification to affected users or the National Privacy Commission is required.</p>

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
        <button type="button" class="legal-button legal-button--light" data-cookie-preferences-open>Cookie Preferences</button>
        <a class="legal-button legal-button--light" href="<?= htmlspecialchars(servitech_url('/index.php'), ENT_QUOTES, 'UTF-8') ?>">Back to ServiTech</a>
      </div>
    </article>
  </main>
  <?php require_once __DIR__ . "/../components/cookie_consent.php"; ?>
</body>
</html>
