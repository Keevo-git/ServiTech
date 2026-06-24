<?php
require_once __DIR__ . "/../config/contact.php";

if (!function_exists("servitech_privacy_policy_html")) {
    function servitech_privacy_policy_html(string $context = "page"): string
    {
        $headingTag = $context === "modal" ? "h3" : "h2";
        $supportEmail = servitech_contact_email();
        $supportPhone = servitech_contact_phone();
        $safeEmail = htmlspecialchars($supportEmail, ENT_QUOTES, "UTF-8");
        $safePhone = htmlspecialchars($supportPhone, ENT_QUOTES, "UTF-8");

        ob_start();
        ?>
      <p>ServiTech respects your privacy. This policy explains what information we collect, why we collect it, how we use and protect it, when it may be shared, how long we may keep it, and how you can contact us about your privacy rights.</p>

      <<?= $headingTag ?>>Information We Collect</<?= $headingTag ?>>
      <p>We collect only the information needed to create your account, provide requested services, keep records accurate, and protect the website.</p>
      <ul>
        <li>Account and contact details, such as your full name, email address, mobile number, account role, account status, and the date you agreed to this policy.</li>
        <li>Sign-in details, such as your password in protected form, email verification records, password reset records, and Google sign-in details if you choose to use Google.</li>
        <li>Service request details, such as your queue or order number, selected service, service options, notes, price, paid amount, payment method, GCash reference number when provided, request status, staff updates, messages, notifications, feedback, and order history.</li>
        <li>Uploaded files and file details, such as the file name, file type, file size, upload date, and the service request connected to the file.</li>
        <li>Website safety details, such as required cookies, browser storage, form protection records, sign-in attempt records, timestamps, and request records that help us protect accounts and troubleshoot issues.</li>
      </ul>

      <<?= $headingTag ?>>How We Collect Information</<?= $headingTag ?>>
      <p>We collect information when you register, log in, use Google sign-in, verify your email, reset your password, update your profile, submit a service request, upload files, provide payment details, read notifications, or send messages through the website.</p>
      <p>Authorized staff may also add information while helping with your request, such as status updates, cancellation reasons, price updates, payment review notes, send-back messages, service updates, and announcements.</p>

      <<?= $headingTag ?>>Why We Use Your Information</<?= $headingTag ?>>
      <p>We use your information to run ServiTech and provide the services you request. For example, we may use your email address to send account or request updates, and we may use uploaded files only as needed to process your service request.</p>
      <ul>
        <li>Create, verify, update, and secure your account.</li>
        <li>Confirm it is really you when you log in or use Google sign-in.</li>
        <li>Create queue numbers and manage service requests from submission to completion or cancellation.</li>
        <li>Review payment details, including GCash reference numbers for Document Printing orders when you provide them.</li>
        <li>Give customers and staff important updates about requests, payment review, price changes, cancellations, and status changes.</li>
        <li>Protect uploaded files and make them available only to the customer and authorized staff who need them for the service.</li>
        <li>Fix website issues, prevent misuse, keep service history accurate, and support the academic capstone/project purpose of ServiTech.</li>
      </ul>

      <<?= $headingTag ?>>Consent and Lawful Use</<?= $headingTag ?>>
      <p>When you create an account, we ask you to agree to this Privacy Policy and the Terms of Service. We keep a record of your agreement so we can show which policy version applied to your account.</p>
      <p>Most of our use of your information is needed to provide the service you requested, manage your account, protect the website, keep operational records, and support the academic project. If ServiTech later wants to use your information for a new purpose, such as marketing or unrelated sharing, we should give you a separate notice or ask for separate consent when needed.</p>

      <<?= $headingTag ?>>Account Access and Staff Access</<?= $headingTag ?>>
      <p>Customer and staff accounts have different access levels. Customers can view and manage only their own allowed account details, requests, notifications, and uploaded files. Authorized staff can view the customer, queue, order, payment, message, upload, service, announcement, and status records needed to help process requests and operate the website.</p>
      <p>ServiTech may use Supabase for database or account sign-in features when enabled. If you choose Google sign-in, Google helps confirm your Google account. Email services may also be used to send account or notification emails.</p>

      <<?= $headingTag ?>>Uploaded Files</<?= $headingTag ?>>
      <p>Please upload only the files needed for your requested service. Avoid including private or sensitive information that is not needed.</p>
      <p>Uploaded files are kept in private storage and are not meant to be public. The website checks account access before a file can be viewed or downloaded. Files are used only for the service request they belong to, such as printing, repair review, rush ID processing, or another upload-enabled service.</p>

      <<?= $headingTag ?>>How Long We Keep Information</<?= $headingTag ?>>
      <p>We keep information only as long as it is needed for service processing, account records, security, academic evaluation, legal needs, or legitimate business records.</p>
      <ul>
        <li>Files linked to active requests stay available while processing, review, pick-up, or a requested customer edit is ongoing.</li>
        <li>Files linked to Done or Cancelled requests may be kept for 30 days for rechecking, disputes, and authorized review, then deleted by the upload cleanup process.</li>
        <li>Temporary failed, cancelled, abandoned, or unlinked uploads may be deleted within 24 hours.</li>
        <li>Queue, payment, notification, message, and status-history records may remain after file deletion so service history stays accurate.</li>
      </ul>

      <<?= $headingTag ?>>When We May Share Information</<?= $headingTag ?>>
      <p>ServiTech does not sell your information.</p>
      <p>We may share or allow access to information only when needed to run the website, provide services, protect the system, respond to privacy or security concerns, comply with legal requirements, or work with trusted services that help operate ServiTech. These may include hosting, database, account sign-in, Google sign-in, email, and file-storage services.</p>

      <<?= $headingTag ?>>How We Protect Your Information</<?= $headingTag ?>>
      <p>We take steps to protect your information from unauthorized access, misuse, loss, or changes. These steps include limiting access by account role, checking that customers can access only their own records, protecting sign-in sessions and forms, storing passwords in protected form, limiting repeated failed sign-in attempts, checking uploaded file type and size, keeping uploads in private storage, and using protected download links.</p>
      <p>No website can promise perfect security, so ServiTech also needs responsible staff access, protected hosting accounts, secure backups, and regular review of important records.</p>

      <<?= $headingTag ?>>Cookies and Browser Storage</<?= $headingTag ?>>
      <p>ServiTech uses required cookies and browser storage to keep you signed in, protect your account, process forms, continue uploads, manage bookings, show important notifications, remember your cookie choice, and support Google sign-in when you choose it.</p>
      <p>These required settings are always active because the website cannot work properly without them. ServiTech does not currently use analytics, advertising, or marketing tracking cookies. You can open Cookie Preferences from the website footer.</p>

      <<?= $headingTag ?>>Your Privacy Rights</<?= $headingTag ?>>
      <p>Under the Philippine Data Privacy Act of 2012, you may ask to:</p>
      <ul>
        <li>Know what personal information ServiTech has about you and why it is used.</li>
        <li>Access your personal information, where appropriate.</li>
        <li>Correct inaccurate or outdated account or request information.</li>
        <li>Receive a copy of your personal information where feasible.</li>
        <li>Request deletion, blocking, or limited use of information that is no longer needed or was used improperly, where appropriate.</li>
        <li>Withdraw consent or object where the use of your information is based on consent and the request is allowed by law.</li>
        <li>Raise privacy questions, concerns, or complaints for review.</li>
      </ul>
      <p>Some requests may need manual review. For example, ServiTech may need to keep certain records for service history, payment review, security, academic evaluation, legal compliance, or dispute handling.</p>

      <<?= $headingTag ?>>Security Incidents</<?= $headingTag ?>>
      <p>If ServiTech discovers a possible privacy or security incident, the project operators should act to secure the system, review what happened, check which information and users may be affected, keep needed records, fix the issue, and decide whether affected users or the National Privacy Commission must be notified.</p>

      <<?= $headingTag ?>>Contact Us</<?= $headingTag ?>>
      <p>For privacy questions, requests, or complaints, contact ServiTech through the official support channels below.</p>
      <?php if ($supportEmail !== "" || $supportPhone !== ""): ?>
      <ul>
        <?php if ($supportEmail !== ""): ?>
        <li>Email: <a href="mailto:<?= $safeEmail ?>"><?= $safeEmail ?></a></li>
        <?php endif; ?>
        <?php if ($supportPhone !== ""): ?>
        <li>Phone: <?= $safePhone ?></li>
        <?php endif; ?>
      </ul>
      <?php else: ?>
      <p>Please use the official ServiTech support channel for privacy concerns.</p>
      <?php endif; ?>
        <?php

        return trim((string)ob_get_clean());
    }
}
