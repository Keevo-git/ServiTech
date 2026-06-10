<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/account.php";
$csrfToken = servitech_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Register</title>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=20260610steady-header") ?>">
</head>
<body class="auth-page auth-page--register">

<?php render_auth_header("register-header-menu", "/auth/log_in.php", "Login"); ?>

  <main class="auth-shell">
    <section class="auth-card auth-card--register" aria-labelledby="register-title">
      <div class="auth-card__header">
        <p class="auth-card__eyebrow">Welcome to ServiTech</p>
        <h1 id="register-title">Create Account</h1>
        <p class="auth-card__subtitle">Set up your customer account to access queueing, service status updates, and online print orders.</p>
      </div>

      <div id="serverErrorMessage" class="form-alert form-alert--error" role="alert" hidden></div>

      <form id="registerForm" action="<?= auth_url("/auth/register.php") ?>" method="POST" class="register-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
        <section class="form-section" aria-labelledby="personal-info-title">
          <div class="form-section__header">
            <h2 id="personal-info-title">Personal Info</h2>
            <p>Provide your main contact details so we can identify your account.</p>
          </div>

          <div class="registration-field-stack">
            <div class="form-field">
              <label for="fullname">Full Name</label>
              <input id="fullname" name="fullname" type="text" placeholder="Enter your full name" autocomplete="name" required>
              <p class="field-error" id="fullnameError" aria-live="polite"></p>
            </div>

            <div class="form-field">
              <label for="contactMobile">Contact Number</label>
              <div class="contact-number-control">
                <span class="contact-number-prefix" aria-label="Philippine country code">+63</span>
                <input id="contactMobile" type="tel" inputmode="numeric" placeholder="9XXXXXXXXX" autocomplete="tel-national" maxlength="10" pattern="9[0-9]{9}" title="Enter the 10-digit Philippine mobile number after +63, starting with 9." aria-describedby="contactHint contactError" required>
              </div>
              <input id="contact" name="contact" type="hidden">
              <p class="field-hint" id="contactHint">Enter the 10-digit mobile number after +63, starting with 9.</p>
              <p class="field-error" id="contactError" aria-live="polite"></p>
            </div>

            <div class="form-field">
              <label for="email">Email Address</label>
              <input id="email" name="email" type="email" placeholder="Enter your email address" autocomplete="email" required>
              <p class="field-error" id="emailError" aria-live="polite"></p>
            </div>
          </div>
        </section>

        <section class="form-section" aria-labelledby="account-info-title">
          <div class="form-section__header">
            <h2 id="account-info-title">Account Info</h2>
            <p>Create a secure password for your account.</p>
          </div>

          <div class="registration-field-stack">
            <div class="form-field">
              <label for="password">Password</label>
              <div class="password-input-wrap">
                <input id="password" name="password" type="password" placeholder="Create a password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" required>
                <button type="button" class="password-toggle" id="registrationPasswordToggle" aria-label="Show password" aria-pressed="false" aria-hidden="true" tabindex="-1"></button>
              </div>
              <p class="field-error" id="passwordError" aria-live="polite"></p>
            </div>

            <div class="form-field">
              <label for="confirmPassword">Confirm Password</label>
              <input id="confirmPassword" name="confirm_password" type="password" placeholder="Re-enter your password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" required>
              <p class="field-error" id="confirmPasswordError" aria-live="polite"></p>
            </div>
          </div>
        </section>

        <section class="form-section form-section--compact form-section--consent">
          <div class="consent-card">
            <label class="agreement-row" for="privacyConsent">
              <input id="privacyConsent" class="agreement-row__native" name="privacy_consent" type="checkbox" value="1" required>
              <span class="agreement-row__box" aria-hidden="true"></span>
              <span class="agreement-row__text">I agree to the <button type="button" class="text-link" data-doc-trigger="privacy">Data Privacy Policy</button> and <button type="button" class="text-link" data-doc-trigger="terms">Terms &amp; Conditions</button>.</span>
            </label>
            <p class="field-error agreement-row__error" id="privacyConsentError" aria-live="polite"></p>
          </div>
        </section>

        <button type="submit" id="registerSubmit" class="auth-submit" disabled>Create Account</button>
      </form>

      <div class="auth-divider" aria-hidden="true">
        <span>OR</span>
      </div>

      <div class="social-auth">
        <div class="google-container google-button-shell">
          <div id="registerGoogleSignInSlot" class="google-signin-slot" aria-live="polite"></div>
          <button type="button" id="registerGoogleFallbackButton" class="google-btn google-button google-button--fallback" disabled>
            <span class="google-button__icon" aria-hidden="true">
              <svg viewBox="0 0 18 18" width="18" height="18" role="img" focusable="false">
                <path fill="#EA4335" d="M9 7.03v3.41h4.84c-.21 1.1-.84 2.03-1.8 2.66l2.91 2.25c1.7-1.56 2.68-3.86 2.68-6.6 0-.63-.06-1.24-.18-1.82H9z"></path>
                <path fill="#34A853" d="M9 18c2.43 0 4.47-.81 5.96-2.2l-2.91-2.25c-.81.54-1.84.86-3.05.86-2.34 0-4.33-1.58-5.04-3.71H.96v2.33A9 9 0 0 0 9 18z"></path>
                <path fill="#4A90E2" d="M3.96 10.7A5.41 5.41 0 0 1 3.68 9c0-.59.1-1.16.28-1.7V4.97H.96A9 9 0 0 0 0 9c0 1.45.35 2.82.96 4.03l3-2.33z"></path>
                <path fill="#FBBC05" d="M9 3.58c1.32 0 2.5.45 3.43 1.33l2.57-2.57C13.46.9 11.42 0 9 0A9 9 0 0 0 .96 4.97l3 2.33C4.67 5.16 6.66 3.58 9 3.58z"></path>
              </svg>
            </span>
            <span id="registerGoogleFallbackLabel" class="google-button__label">Continue with Google Account</span>
          </button>
        </div>
        <p id="registerGoogleSignInHint" class="auth-note">Checking Google sign-in availability...</p>
      </div>

      <a href="<?= auth_url("/auth/log_in.php") ?>" class="back-login">Already have an account? Log in</a>
    </section>
  </main>

  <div class="policy-modal" id="policyModal" hidden>
    <div class="policy-modal__backdrop" data-close-modal></div>
    <div class="policy-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="policyModalTitle" aria-describedby="policyModalContent" tabindex="-1">
      <button type="button" class="policy-modal__close" data-close-modal aria-label="Close modal">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M6.75 6.75l10.5 10.5m0-10.5-10.5 10.5"></path>
        </svg>
      </button>
      <div class="policy-modal__header">
        <div class="policy-modal__brand">
          <img src="<?= auth_url("/assets/images/LOGO_SERVITECH.png") ?>" alt="" class="policy-modal__logo" aria-hidden="true">
          <p class="policy-modal__eyebrow">Account Policies</p>
        </div>
        <h2 id="policyModalTitle">ServiTech Data Privacy Policy</h2>
      </div>
      <div class="policy-modal__content" id="policyModalContent"></div>
    </div>
  </div>

<?php render_auth_footer(); ?>

  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <script src="<?= auth_url("/assets/js/csrf.js") ?>" defer></script>
  <script>
    const registerPageUrl = <?= auth_json_url("/auth/regis.php") ?>;
    const googleLoginUrl = <?= auth_json_url("/auth/google_login.php") ?>;
    const googleConfigUrl = <?= auth_json_url("/auth/google_config.php") ?>;
    const defaultCustomerRedirectUrl = <?= auth_json_url("/pages/customer/customer_dash.php") ?>;
    const passwordMinLength = <?= SERVITECH_PASSWORD_MIN_LENGTH ?>;
    const passwordMaxLength = <?= SERVITECH_PASSWORD_MAX_BYTES ?>;

    const registerForm = document.getElementById("registerForm");
    const submitButton = document.getElementById("registerSubmit");
    const serverErrorMessage = document.getElementById("serverErrorMessage");
    const policyModal = document.getElementById("policyModal");
    const policyModalDialog = policyModal.querySelector(".policy-modal__dialog");
    const policyModalTitle = document.getElementById("policyModalTitle");
    const policyModalContent = document.getElementById("policyModalContent");
    const authCard = document.querySelector(".auth-card--register");
    const registrationPasswordToggle = document.getElementById("registrationPasswordToggle");
    const contactInput = document.getElementById("contact");
    let activePolicyTrigger = null;

    const fields = {
      fullname: {
        input: document.getElementById("fullname"),
        error: document.getElementById("fullnameError"),
        validate: (value) => value.trim() ? "" : "Full name is required."
      },
      contact: {
        input: document.getElementById("contactMobile"),
        error: document.getElementById("contactError"),
        validate: (value) => {
          const trimmedValue = value.trim();
          if (!trimmedValue) {
            return "Contact number is required.";
          }

          const philippineMobilePattern = /^9\d{9}$/;
          return philippineMobilePattern.test(trimmedValue)
            ? ""
            : "Enter a valid 10-digit Philippine mobile number after +63, starting with 9.";
        }
      },
      email: {
        input: document.getElementById("email"),
        error: document.getElementById("emailError"),
        validate: (value) => {
          const trimmedValue = value.trim();
          if (!trimmedValue) {
            return "Email address is required.";
          }

          const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
          return emailPattern.test(trimmedValue) ? "" : "Enter a valid email address.";
        }
      },
      password: {
        input: document.getElementById("password"),
        error: document.getElementById("passwordError"),
        validate: (value) => {
          if (!value) {
            return "Password is required.";
          }

          if (value.length < passwordMinLength) {
            return `Password must be at least ${passwordMinLength} characters.`;
          }

          if (value.length > passwordMaxLength) {
            return `Password must not exceed ${passwordMaxLength} characters.`;
          }

          return "";
        }
      },
      confirmPassword: {
        input: document.getElementById("confirmPassword"),
        error: document.getElementById("confirmPasswordError"),
        validate: (value) => {
          if (!value) {
            return "Please confirm your password.";
          }

          return value === fields.password.input.value ? "" : "Passwords do not match.";
        }
      },
      privacyConsent: {
        input: document.getElementById("privacyConsent"),
        error: document.getElementById("privacyConsentError"),
        validate: (checked) => checked ? "" : "You must agree to the Data Privacy Policy before creating an account."
      }
    };

    const policyDocuments = {
      privacy: {
        title: "ServiTech Data Privacy Policy",
        body: `
          <section class="policy-section">
            <h3>Overview</h3>
            <p>ServiTech respects user privacy and protects personal information handled by the system. This Privacy Policy explains what ServiTech collects, how the information is used, where it is stored, who can access it, and what rights users have.</p>
          </section>
          <section class="policy-section">
            <h3>Information Collected</h3>
            <p>ServiTech collects and stores the following information:</p>
            <ul>
              <li>Account details: full name, email address, contact number, role, account creation date, and update date.</li>
              <li>Authentication details: password hashes, Google account ID for Google sign-in, email verification tokens and timestamps, password reset tokens and expiry dates, consent date, and consent version.</li>
              <li>Queue and service request details: queue code, service category, service label, order type, paper size, quantity, color option, package label, lamination type, device type, notes, uploaded file references, estimated total, price, paid amount, status, lifecycle stage, creation date, update date, and completion date.</li>
              <li>Payment transaction details for online print orders: payment method, amount, GCash reference number for GCash payments, payment status, and payment record dates.</li>
              <li>Uploaded file records: original file name, private storage key, file extension, MIME type, file size, SHA-256 checksum, upload token, linked queue, upload date, linked date, and deletion date.</li>
              <li>Notification records: message, notification type, related queue or order reference, read status, deletion date, and creation date.</li>
              <li>Login security records: hashed email and hashed IP-based login attempt records used for throttling failed login attempts.</li>
              <li>Session and CSRF data: ServiTech uses the SERVITECHSESSID session cookie to keep users signed in and protect forms from unauthorized requests.</li>
            </ul>
          </section>
          <section class="policy-section">
            <h3>How Information is Collected</h3>
            <p>ServiTech collects information through these current system flows:</p>
            <ul>
              <li>Registration, login, Google sign-in, email verification, password reset, and profile update forms.</li>
              <li>Queue, service request, online print order, repair, installation, laminating, rush ID, and document printing forms.</li>
              <li>File upload forms used for documents, images, presentations, and related service files.</li>
              <li>Payment selection and GCash reference submission for online print orders.</li>
              <li>Admin actions for queue status updates, cancellation reasons, price updates, paid amount updates, service management, announcements, and customer record viewing.</li>
              <li>Automatic security checks for sessions, CSRF tokens, failed login throttling, notifications, and queue status history.</li>
            </ul>
          </section>
          <section class="policy-section">
            <h3>Purpose of Data Usage</h3>
            <p>ServiTech uses this information for the following purposes:</p>
            <ul>
              <li>Create, verify, update, and secure user accounts.</li>
              <li>Authenticate users through email/password login or Google sign-in.</li>
              <li>Create queue numbers and manage service requests from submission to completion or cancellation.</li>
              <li>Process printing, online print orders, repair, installation, laminating, rush ID, and related service requests.</li>
              <li>Store uploaded files and make them available to the file owner and authorized administrators for service processing.</li>
              <li>Record cash or GCash payment details, price, paid amount, and payment review information for online print orders.</li>
              <li>Send customer and admin notifications about new requests, payment review, price updates, cancellations, and status changes.</li>
              <li>Protect the system through CSRF checks, session controls, role-based access checks, and failed login throttling.</li>
              <li>Support academic evaluation, demonstration, testing, reporting, and system improvement for the ServiTech capstone project.</li>
            </ul>
          </section>
          <section class="policy-section">
            <h3>Storage and Access</h3>
            <p>ServiTech stores account, queue, payment, notification, upload metadata, login attempt, and status history records in the project database. Uploaded files are stored in the private upload storage directory using random storage keys. Passwords are stored as password hashes. Failed login throttling stores hashed email and hashed IP-based values instead of plain login-attempt identifiers.</p>
            <p>Customers can access their own account, queue status, notifications, and uploaded files. Administrators can access customer records, queues, orders, payment details, uploaded files, notifications, service records, announcements, and status history needed to operate and evaluate the system. ServiTech does not sell user data.</p>
          </section>
          <section class="policy-section">
            <h3>Data Protection</h3>
            <p>ServiTech protects user data through these implemented controls:</p>
            <ul>
              <li>Role-based access for customers and administrators.</li>
              <li>HTTP-only session cookies with SameSite=Lax.</li>
              <li>CSRF tokens for protected form and API requests.</li>
              <li>Password hashing for stored passwords.</li>
              <li>Hashed login attempt records for failed login throttling.</li>
              <li>Private upload storage, random file storage keys, upload tokens, checksum records, file type validation, size limits, and restricted file downloads.</li>
              <li>Admin-only pages protected by login and role checks.</li>
              <li>Status history records that show queue changes and admin notes.</li>
            </ul>
          </section>
          <section class="policy-section">
            <h3>Data Retention</h3>
            <p>ServiTech keeps account records, queue records, payment records, linked upload records, notifications, and status history in the database for system operation, order history, academic checking, and project evaluation. Queue records are not permanently deleted through the admin delete endpoint; cancelled requests remain as order history. Customer-owned unlinked uploaded files are removed through the upload cleanup flow. Failed login attempt records older than one day are deleted during login throttling cleanup.</p>
          </section>
          <section class="policy-section">
            <h3>User Rights</h3>
            <p>Under the Philippine Data Privacy Act of 2012, users can exercise the following rights over their personal data:</p>
            <ul>
              <li>Access their personal data stored in ServiTech.</li>
              <li>Request correction of inaccurate account or request information.</li>
              <li>Request deletion of personal data that the project no longer needs for account records, order history, security, academic evaluation, or legal compliance.</li>
              <li>Request a copy of their personal data.</li>
              <li>Raise privacy questions, concerns, or complaints for review.</li>
            </ul>
          </section>
          <section class="policy-section">
            <h3>Academic Purpose</h3>
            <p>ServiTech is an academic capstone/project system. The system records and displays data needed for educational demonstration, testing, evaluation, and service management workflows.</p>
          </section>
          <section class="policy-section">
            <h3>Contact Information</h3>
            <p>The project owner must replace these contact details before publication:</p>
            <ul>
              <li>Support email: [Insert ServiTech support email]</li>
              <li>School/business/project address: [Insert official address]</li>
              <li>Official contact person: [Insert official contact person]</li>
            </ul>
          </section>
        `
      },
      terms: {
        title: "ServiTech Terms & Conditions",
        body: `
          <section class="policy-section">
            <h3>Overview</h3>
            <p>By creating an account and using ServiTech, users agree to follow these Terms &amp; Conditions. Users who do not agree should not create an account or use the platform.</p>
          </section>
          <section class="policy-section">
            <h3>Service Description</h3>
            <p>ServiTech provides account registration, login, customer queueing, online print order submission, uploaded file handling, service status tracking, notifications, payment detail recording, profile editing, and administrator management for printing, repair, installation, laminating, rush ID, and related services.</p>
          </section>
          <section class="policy-section">
            <h3>Academic Purpose</h3>
            <p>ServiTech is an academic capstone/project system developed for educational, demonstration, testing, and evaluation purposes. Users must use the system according to school, project, and service requirements.</p>
          </section>
          <section class="policy-section">
            <h3>User Accounts</h3>
            <p>Users create accounts using their full name, contact number, email address, password, and privacy consent. Users can also sign in with Google, which links the account to a Google ID and verified Google email. Users are responsible for keeping their login credentials secure and for activities made through their account.</p>
          </section>
          <section class="policy-section">
            <h3>Permitted Uses</h3>
            <p>Users can use ServiTech for legitimate service-related purposes:</p>
            <ul>
              <li>Create and manage service requests.</li>
              <li>Upload files required for requested services.</li>
              <li>View queue, order, payment, and service status.</li>
              <li>Receive notifications about requests, payment review, price updates, cancellations, and status changes.</li>
              <li>Update their profile information and password.</li>
              <li>Use the system for approved printing, repair, installation, laminating, rush ID, and related service workflows.</li>
            </ul>
          </section>
          <section class="policy-section">
            <h3>Prohibited Uses</h3>
            <p>Users must not misuse ServiTech. Prohibited actions include:</p>
            <ul>
              <li>Submitting false, misleading, or incomplete information.</li>
              <li>Uploading harmful, illegal, offensive, or copyrighted files without permission.</li>
              <li>Uploading files that are not needed for the requested service.</li>
              <li>Sharing, lending, or misusing another person's account.</li>
              <li>Spamming requests, abusing forms, or overloading the system.</li>
              <li>Attempting to bypass login, CSRF protection, role checks, file restrictions, or other security controls.</li>
              <li>Accessing another user's account, queue, payment details, notifications, or uploaded files without authorization.</li>
              <li>Using the system for illegal activities.</li>
            </ul>
          </section>
          <section class="policy-section">
            <h3>Requests, Status, and Cancellations</h3>
            <p>ServiTech creates queue records for submitted service requests. Requests start as pending and move through the status flow managed by the system and administrators. Online print orders move from pending to approved, ongoing, for pick-up, and done. Other service requests move from pending to ongoing, for pick-up or done, and then finalized.</p>
            <p>Customers can cancel only their own pending requests. A cancelled request becomes an order history record, its paid amount is reset to zero, and administrators receive a cancellation notification. Administrators can cancel requests through the status update flow and must provide a cancellation reason. Completed and cancelled records are finalized and cannot continue through the normal status flow.</p>
          </section>
          <section class="policy-section">
            <h3>Payments and Transaction Details</h3>
            <p>For online print orders, ServiTech records the selected payment method, order amount, paid amount, and GCash reference number for GCash payments. ServiTech also supports cash selection. Administrators review payment details, update price and paid amount, and approve or continue processing the order through the admin status workflow. ServiTech records transaction details for tracking and review; it does not process payment through an external payment gateway.</p>
          </section>
          <section class="policy-section">
            <h3>Uploaded Files and User Content</h3>
            <p>Users are responsible for the files and content they upload. ServiTech accepts PDF, JPG, PNG, DOC, PPT, DOCX, and PPTX files up to 20MB per file through the current upload handler. Uploaded files are stored in private upload storage and are linked to the user's queue or order. ServiTech uses uploaded files only to review and process the requested service. Users must have the right to upload, print, use, or reproduce submitted files.</p>
          </section>
          <section class="policy-section">
            <h3>Administrator Access and Actions</h3>
            <p>Administrators can view registered customers, queues, orders, service details, payment details, uploaded files, notifications, status history, services, and announcements. Administrators can update request status, add cancellation notes, update prices and paid amounts, manage service listings, and manage announcements. Queue records cannot be permanently deleted through the admin delete endpoint; administrators must cancel the order instead.</p>
          </section>
          <section class="policy-section">
            <h3>Intellectual Property</h3>
            <p>The ServiTech name, system design, logo, source code, documentation, and related project materials belong to the project owners or developers. Users must not copy, modify, redistribute, or reuse the system without permission.</p>
          </section>
          <section class="policy-section">
            <h3>Service Availability</h3>
            <p>ServiTech is a capstone/project system and depends on its hosting, database, PHP runtime, file storage, internet connection, and configured services. The system does not guarantee uninterrupted access, instant processing, or error-free operation.</p>
          </section>
          <section class="policy-section">
            <h3>Misuse and Account Restrictions</h3>
            <p>ServiTech restricts access by customer and admin roles. Accounts and requests can be restricted, cancelled, or blocked from normal processing when users submit harmful content, misuse the system, violate these terms, or compromise system security.</p>
          </section>
          <section class="policy-section">
            <h3>Limitation of Responsibility</h3>
            <p>Users are responsible for the accuracy of their account details, service request details, payment reference details, and uploaded files. ServiTech is not responsible for problems caused by user-submitted incorrect information, unauthorized account sharing, unsupported files, internet connection problems, device problems, or misuse of the system.</p>
          </section>
          <section class="policy-section">
            <h3>Changes to Terms</h3>
            <p>The project owners can update these Terms &amp; Conditions to match changes in the ServiTech system, school requirements, academic evaluation, or service workflow. Users should review the current terms shown in the account policy modal.</p>
          </section>
          <section class="policy-section">
            <h3>Philippine Law and School Rules</h3>
            <p>Users must follow applicable Philippine laws, the Philippine Data Privacy Act of 2012, school rules, project requirements, and service policies while using ServiTech.</p>
          </section>
        `
      }
    };

    function setFieldState(fieldConfig, message) {
      const isCheckbox = fieldConfig.input.type === "checkbox";
      const target = isCheckbox
        ? fieldConfig.input.closest(".consent-card")
        : fieldConfig.input.closest(".contact-number-control") || fieldConfig.input;

      fieldConfig.error.textContent = message;
      target.classList.toggle("is-invalid", Boolean(message));
      fieldConfig.input.setAttribute("aria-invalid", message ? "true" : "false");
    }

    function getFieldMessage(fieldName) {
      const field = fields[fieldName];
      const value = field.input.type === "checkbox" ? field.input.checked : field.input.value;
      return field.validate(value);
    }

    function validateField(fieldName, showMessage = true) {
      const field = fields[fieldName];
      const message = getFieldMessage(fieldName);

      if (showMessage) {
        setFieldState(field, message);
      }

      return !message;
    }

    function validateForm(showMessages = true) {
      const results = Object.keys(fields).map((fieldName) => validateField(fieldName, showMessages));
      const formIsValid = results.every(Boolean);
      submitButton.disabled = !formIsValid;
      return formIsValid;
    }

    function applyServerErrorFromQuery() {
      const params = new URLSearchParams(window.location.search);
      const errorMap = {
        required: "Please complete all required fields before creating your account.",
        invalid_email: "Please enter a valid email address.",
        invalid_contact: "Please enter a valid Philippine mobile number after +63, starting with 9.",
        mismatch: "Passwords do not match.",
        password: `Password must be ${passwordMinLength} to ${passwordMaxLength} characters.`,
        privacy: "You must agree to the Data Privacy Policy before creating an account.",
        error: "We could not create your account right now. Please try again."
      };

      const errorCode = params.get("error");
      if (!errorCode || !errorMap[errorCode]) {
        return;
      }

      serverErrorMessage.textContent = errorMap[errorCode];
      serverErrorMessage.hidden = false;
      window.history.replaceState({}, document.title, registerPageUrl);
    }

    function openPolicyModal(type) {
      const documentConfig = policyDocuments[type];
      if (!documentConfig) {
        return;
      }

      activePolicyTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      policyModalTitle.textContent = documentConfig.title;
      policyModalContent.innerHTML = documentConfig.body;
      policyModal.hidden = false;
      document.body.classList.add("modal-open");
      document.documentElement.classList.add("modal-open");
      policyModalContent.scrollTop = 0;
      policyModalDialog.focus();
    }

    function closePolicyModal() {
      policyModal.hidden = true;
      document.body.classList.remove("modal-open");
      document.documentElement.classList.remove("modal-open");

      if (activePolicyTrigger) {
        activePolicyTrigger.focus();
        activePolicyTrigger = null;
      }
    }

    function trapPolicyModalFocus(event) {
      if (event.key !== "Tab" || policyModal.hidden) {
        return;
      }

      const focusableElements = policyModalDialog.querySelectorAll(
        'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
      );
      const firstFocusable = focusableElements[0];
      const lastFocusable = focusableElements[focusableElements.length - 1];

      if (!firstFocusable || !lastFocusable) {
        event.preventDefault();
        policyModalDialog.focus();
        return;
      }

      if (event.shiftKey && document.activeElement === firstFocusable) {
        event.preventDefault();
        lastFocusable.focus();
      } else if (!event.shiftKey && document.activeElement === lastFocusable) {
        event.preventDefault();
        firstFocusable.focus();
      }
    }

    function setRegisterGoogleLoading(isLoading) {
      const fallbackButton = document.getElementById("registerGoogleFallbackButton");
      const fallbackLabel = document.getElementById("registerGoogleFallbackLabel");

      if (!fallbackButton || !fallbackLabel) {
        return;
      }

      fallbackButton.disabled = false;
      fallbackLabel.textContent = isLoading ? "Connecting to Google account..." : "Continue with Google Account";
      fallbackButton.classList.toggle("is-loading", isLoading);
    }

    function setRegisterError(message) {
      serverErrorMessage.textContent = message;
      serverErrorMessage.hidden = false;
    }

    function sanitizePhilippineMobileInput(value) {
      let digits = value.replace(/\D/g, "");

      if (digits.startsWith("63")) {
        digits = digits.slice(2);
      }

      if (digits.startsWith("0")) {
        digits = digits.slice(1);
      }

      return digits.slice(0, 10);
    }

    function syncPhilippineMobileInput() {
      fields.contact.input.value = sanitizePhilippineMobileInput(fields.contact.input.value);
      contactInput.value = fields.contact.input.value ? `+63${fields.contact.input.value}` : "";
    }

    function updateRegistrationPasswordToggleVisibility() {
      const hasPassword = Boolean(fields.password.input.value);
      registrationPasswordToggle.classList.toggle("has-value", hasPassword);
      registrationPasswordToggle.tabIndex = hasPassword ? 0 : -1;
      registrationPasswordToggle.setAttribute("aria-hidden", hasPassword ? "false" : "true");

      if (!hasPassword) {
        fields.password.input.type = "password";
        registrationPasswordToggle.classList.remove("is-visible");
        registrationPasswordToggle.setAttribute("aria-label", "Show password");
        registrationPasswordToggle.setAttribute("aria-pressed", "false");
      }
    }

    async function handleRegisterGoogleCredential(response) {
      if (!response || !response.credential) {
        setRegisterError("Google account sign-in did not return a valid credential. Please try again.");
        return;
      }

      if (!fields.privacyConsent.input.checked) {
        validateField("privacyConsent");
        setRegisterError("You must agree to the Data Privacy Policy before creating an account.");
        return;
      }

      setRegisterGoogleLoading(true);

      try {
        const result = await fetch(googleLoginUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": window.servitechCsrfToken ? window.servitechCsrfToken() : ""
          },
          credentials: "same-origin",
          body: JSON.stringify({ credential: response.credential, privacy_consent: "1" })
        });

        const payload = await result.json();

        if (!result.ok || !payload.ok) {
          throw new Error(payload.error || "Google account sign-in failed.");
        }

        window.location.href = payload.redirect || defaultCustomerRedirectUrl;
      } catch (error) {
        setRegisterError(error.message || "Google account sign-in failed. Please try again.");
        setRegisterGoogleLoading(false);
      }
    }

    async function loadRegisterGoogleSignIn() {
      const signInHint = document.getElementById("registerGoogleSignInHint");
      const signInSlot = document.getElementById("registerGoogleSignInSlot");
      const fallbackButton = document.getElementById("registerGoogleFallbackButton");

      if (!signInHint || !signInSlot || !fallbackButton) {
        return;
      }

      try {
        const response = await fetch(googleConfigUrl, {
          credentials: "same-origin",
          headers: {
            "Accept": "application/json"
          }
        });
        const config = await response.json();

        if (!response.ok || !config.googleEnabled || !config.googleClientId) {
          signInHint.hidden = true;
          signInSlot.style.display = "none";
          fallbackButton.disabled = true;
          return;
        }

        if (!window.google || !google.accounts || !google.accounts.id) {
          signInHint.textContent = "Google account sign-in could not be loaded. Please refresh the page.";
          signInHint.hidden = false;
          fallbackButton.disabled = true;
          return;
        }

        const preferredButtonWidth = signInSlot.parentElement?.clientWidth
          || signInSlot.clientWidth
          || authCard.clientWidth
          || 360;

        google.accounts.id.initialize({
          client_id: config.googleClientId,
          callback: handleRegisterGoogleCredential,
          ux_mode: "popup",
          auto_select: false,
          context: "signup"
        });

        google.accounts.id.renderButton(signInSlot, {
          theme: "outline",
          size: "large",
          text: "continue_with",
          shape: "rectangular",
          width: preferredButtonWidth
        });

        signInHint.textContent = "Use your Google account to create or access your ServiTech account instantly.";
        signInHint.hidden = true;
      } catch (error) {
        signInHint.textContent = "Google account sign-in is currently unavailable. Please use the form above.";
        signInHint.hidden = false;
        fallbackButton.disabled = true;
      }
    }

    Object.keys(fields).forEach((fieldName) => {
      const field = fields[fieldName];
      const eventName = field.input.type === "checkbox" ? "change" : "input";

      field.input.addEventListener(eventName, () => {
        if (fieldName === "contact") {
          syncPhilippineMobileInput();
        }

        validateField(fieldName);

        if (fieldName === "password" && fields.confirmPassword.input.value) {
          validateField("confirmPassword");
        }

        if (fieldName === "password") {
          updateRegistrationPasswordToggleVisibility();
        }

        validateForm(false);
      });

      field.input.addEventListener("blur", () => {
        validateField(fieldName);
        validateForm(false);
      });
    });

    registerForm.addEventListener("submit", (event) => {
      syncPhilippineMobileInput();

      if (!validateForm()) {
        event.preventDefault();
      }
    });

    registrationPasswordToggle.addEventListener("click", () => {
      const showPassword = fields.password.input.type === "password";
      fields.password.input.type = showPassword ? "text" : "password";
      registrationPasswordToggle.setAttribute("aria-label", showPassword ? "Hide password" : "Show password");
      registrationPasswordToggle.setAttribute("aria-pressed", showPassword ? "true" : "false");
      registrationPasswordToggle.classList.toggle("is-visible", showPassword);
    });

    document.querySelectorAll("[data-doc-trigger]").forEach((button) => {
      button.addEventListener("click", () => openPolicyModal(button.dataset.docTrigger));
    });

    policyModal.querySelectorAll("[data-close-modal]").forEach((element) => {
      element.addEventListener("click", closePolicyModal);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !policyModal.hidden) {
        closePolicyModal();
        return;
      }

      trapPolicyModalFocus(event);
    });

    applyServerErrorFromQuery();
    syncPhilippineMobileInput();
    validateForm(false);
    window.addEventListener("pageshow", () => {
      syncPhilippineMobileInput();
      updateRegistrationPasswordToggleVisibility();
      validateForm(false);
    });
    updateRegistrationPasswordToggleVisibility();

    let registerGoogleLoadAttempts = 0;
    const registerGoogleLoadTimer = setInterval(() => {
      registerGoogleLoadAttempts += 1;

      if (window.google && google.accounts && google.accounts.id) {
        clearInterval(registerGoogleLoadTimer);
        loadRegisterGoogleSignIn();
      } else if (registerGoogleLoadAttempts >= 20) {
        clearInterval(registerGoogleLoadTimer);
        const signInHint = document.getElementById("registerGoogleSignInHint");
        if (signInHint) {
          signInHint.textContent = "Google account sign-in could not be loaded. Please refresh the page.";
          signInHint.hidden = false;
        }
      }
    }, 300);
  </script>

  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>

</body>
</html>
