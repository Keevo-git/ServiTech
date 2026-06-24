<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/guest_guard.php";
servitech_require_guest_page();
$csrfToken = servitech_csrf_token();
$rememberMeRetry = !empty($_SESSION["login_remember_retry"]);
unset($_SESSION["login_remember_retry"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Login</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=20260625-auth-verification") ?>">
</head>
<body class="auth-page auth-page--login">

<?php render_auth_header("login-header-menu", "/auth/regis.php", "Register"); ?>

  <main class="auth-shell">
    <section class="auth-card auth-card--login" aria-labelledby="login-title">
      <div class="auth-card__header">
        <p class="auth-card__eyebrow">Welcome Back</p>
        <h1 id="login-title">Login to ServiTech</h1>
        <p class="auth-card__subtitle">Access your account and manage your services, queue status, and print orders in one place.</p>
      </div>

      <div id="loginMessage" class="form-alert" role="alert" hidden></div>

      <form id="loginForm" action="<?= auth_url("/auth/login.php") ?>" method="POST" class="register-form login-form" novalidate autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
        <div class="form-field">
          <label for="loginEmail">Email Address</label>
          <input
            id="loginEmail"
            name="email"
            type="email"
            placeholder="Enter your email address"
            autocomplete="email"
            required
          >
          <p class="field-error" id="loginEmailError" aria-live="polite"></p>
        </div>

        <div class="form-field">
          <label for="loginPassword">Password</label>
          <div class="password-input-wrap">
            <input
              id="loginPassword"
              name="password"
              type="password"
              placeholder="Enter your password"
              autocomplete="current-password"
              required
            >
            <button type="button" class="password-toggle" id="passwordToggle" aria-label="Show password" aria-pressed="false" aria-hidden="true" tabindex="-1"></button>
          </div>
          <p class="field-error" id="loginPasswordError" aria-live="polite"></p>
          <div class="auth-login-options">
            <label class="remember-me-control" for="rememberMe">
              <input id="rememberMe" name="remember_me" type="checkbox" value="1"<?= $rememberMeRetry ? " checked" : "" ?>>
              <span class="login-option-text">Remember me</span>
            </label>
            <a href="<?= auth_url("/auth/forgot_password.php") ?>" class="forgot-link login-option-text">Forgot Password?</a>
          </div>
        </div>

        <button type="submit" id="loginSubmit" class="auth-submit">Login</button>

        <div id="resendVerificationPrompt" class="auth-verification-resend" hidden>
          <span>Didn't receive the verification email?</span>
          <a id="resendVerificationLink" href="<?= auth_url("/auth/resend_verification.php") ?>">Resend verification</a>
        </div>
      </form>

      <div class="auth-divider" aria-hidden="true">
        <span>OR</span>
      </div>

      <div class="social-auth">
        <div class="google-container google-button-shell">
          <div id="googleSignInSlot" class="google-signin-slot" aria-live="polite"></div>
          <button type="button" id="googleFallbackButton" class="google-btn google-button google-button--fallback" disabled>
            <span class="google-button__icon" aria-hidden="true">
              <svg viewBox="0 0 18 18" width="18" height="18" role="img" focusable="false">
                <path fill="#EA4335" d="M9 7.03v3.41h4.84c-.21 1.1-.84 2.03-1.8 2.66l2.91 2.25c1.7-1.56 2.68-3.86 2.68-6.6 0-.63-.06-1.24-.18-1.82H9z"></path>
                <path fill="#34A853" d="M9 18c2.43 0 4.47-.81 5.96-2.2l-2.91-2.25c-.81.54-1.84.86-3.05.86-2.34 0-4.33-1.58-5.04-3.71H.96v2.33A9 9 0 0 0 9 18z"></path>
                <path fill="#4A90E2" d="M3.96 10.7A5.41 5.41 0 0 1 3.68 9c0-.59.1-1.16.28-1.7V4.97H.96A9 9 0 0 0 0 9c0 1.45.35 2.82.96 4.03l3-2.33z"></path>
                <path fill="#FBBC05" d="M9 3.58c1.32 0 2.5.45 3.43 1.33l2.57-2.57C13.46.9 11.42 0 9 0A9 9 0 0 0 .96 4.97l3 2.33C4.67 5.16 6.66 3.58 9 3.58z"></path>
              </svg>
            </span>
            <span id="googleFallbackLabel" class="google-button__label">Continue with Google Account</span>
          </button>
        </div>
        <p id="googleSignInHint" class="auth-note">Checking Google sign-in availability...</p>
      </div>

      <p class="auth-note auth-policy-note">
        By using this service, you understand and agree to the ServiTech
        <button type="button" class="text-link" data-doc-trigger="privacy">Data Privacy Policy</button>
        and
        <button type="button" class="text-link" data-doc-trigger="terms">Terms &amp; Conditions</button>.
      </p>

      <a href="<?= auth_url("/auth/regis.php") ?>" class="back-login">Don't have an account yet? Create one</a>
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

  <script src="<?= auth_url("/assets/js/csrf.js") ?>" defer></script>
  <script>
    const loginPageUrl = <?= auth_json_url("/auth/log_in.php") ?>;
    const googleLoginUrl = <?= auth_json_url("/auth/google_login.php") ?>;
    const googleConfigUrl = <?= auth_json_url("/auth/google_config.php") ?>;
    const defaultCustomerRedirectUrl = <?= auth_json_url("/pages/customer/customer_dash.php") ?>;

    const loginForm = document.getElementById("loginForm");
    const loginSubmit = document.getElementById("loginSubmit");
    const loginMessage = document.getElementById("loginMessage");
    const resendVerificationPrompt = document.getElementById("resendVerificationPrompt");
    const loginEmail = document.getElementById("loginEmail");
    const loginPassword = document.getElementById("loginPassword");
    const loginEmailError = document.getElementById("loginEmailError");
    const loginPasswordError = document.getElementById("loginPasswordError");
    const passwordToggle = document.getElementById("passwordToggle");
    const googleSignInSlot = document.getElementById("googleSignInSlot");
    const googleFallbackButton = document.getElementById("googleFallbackButton");
    const googleFallbackLabel = document.getElementById("googleFallbackLabel");
    const googleSignInHint = document.getElementById("googleSignInHint");
    const policyModal = document.getElementById("policyModal");
    const policyModalDialog = policyModal.querySelector(".policy-modal__dialog");
    const policyModalTitle = document.getElementById("policyModalTitle");
    const policyModalContent = document.getElementById("policyModalContent");
    const authCard = document.querySelector(".auth-card--login");
    let activePolicyTrigger = null;

    const loginFields = {
      email: {
        input: loginEmail,
        error: loginEmailError,
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
        input: loginPassword,
        error: loginPasswordError,
        validate: (value) => value ? "" : "Password is required."
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
              <li>Payment transaction details for Document Printing orders: payment method, amount, GCash reference number for GCash payments, payment status, and payment record dates.</li>
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
              <li>Queue, service request, Document Printing, repair, installation, laminating, and rush ID forms.</li>
              <li>File upload forms used for documents, images, presentations, and related service files.</li>
              <li>Payment selection and GCash reference submission for Document Printing orders.</li>
              <li>Staff actions for queue status updates, cancellation reasons, price updates, paid amount updates, service management, announcements, and customer record viewing.</li>
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
              <li>Process Document Printing, repair, installation, laminating, rush ID, and related service requests.</li>
              <li>Store uploaded files and make them available to the file owner and authorized staff for service processing.</li>
              <li>Record cash or GCash payment details, price, paid amount, and payment review information for Document Printing orders.</li>
              <li>Send customer and staff notifications about new requests, payment review, price updates, cancellations, and status changes.</li>
              <li>Protect the system through CSRF checks, session controls, role-based access checks, and failed login throttling.</li>
              <li>Support academic evaluation, demonstration, testing, reporting, and system improvement for the ServiTech capstone project.</li>
            </ul>
          </section>
          <section class="policy-section">
            <h3>Storage and Access</h3>
            <p>ServiTech stores account, queue, payment, notification, upload metadata, login attempt, and status history records in the project database. Uploaded files are stored in the private upload storage directory using random storage keys. Passwords are stored as password hashes. Failed login throttling stores hashed email and hashed IP-based values instead of plain login-attempt identifiers.</p>
            <p>Customers can access their own account, queue status, notifications, and uploaded files. Staff can access customer records, queues, orders, payment details, uploaded files, notifications, service records, announcements, and status history needed to operate and evaluate the system. ServiTech does not sell user data.</p>
          </section>
          <section class="policy-section">
            <h3>Data Protection</h3>
            <p>ServiTech protects user data through these implemented controls:</p>
            <ul>
              <li>Role-based access for customers and staff.</li>
              <li>HTTP-only session cookies with SameSite=Lax.</li>
              <li>CSRF tokens for protected form and API requests.</li>
              <li>Password hashing for stored passwords.</li>
              <li>Hashed login attempt records for failed login throttling.</li>
              <li>Private upload storage, random file storage keys, upload tokens, checksum records, file type validation, size limits, and restricted file downloads.</li>
              <li>Staff-only pages protected by login and role checks.</li>
              <li>Status history records that show queue changes and staff notes.</li>
            </ul>
          </section>
          <section class="policy-section">
            <h3>Cookies and Browser Storage</h3>
            <p>ServiTech uses required cookies and browser settings to keep you signed in, protect your account, support forms and uploads, show important notifications, remember your cookie choice, and support Google sign-in when you choose it. ServiTech does not currently use analytics, advertising, or marketing tracking cookies.</p>
          </section>
          <section class="policy-section">
            <h3>Data Retention</h3>
            <p>ServiTech retains uploaded files only as long as operationally needed. Files linked to active requests remain available while processing, review, pick-up, or a requested customer edit is ongoing. When a request becomes Done or Cancelled, its stored file content is retained for 30 days for rechecking, disputes, and authorized review, then automatically deleted. Temporary failed, cancelled, abandoned, or unlinked uploads are deleted within 24 hours. Queue, payment, notification, and status-history records may remain after file deletion so the service history stays accurate; expired attachments are shown as unavailable.</p>
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
            <p>ServiTech provides account registration, login, customer queueing, Document Printing order submission, uploaded file handling, service status tracking, notifications, payment detail recording, profile editing, and staff management for printing, repair, installation, laminating, rush ID, and related services.</p>
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
            <p>ServiTech creates queue records for submitted service requests. Requests start as pending and move through the status flow managed by the system and staff. Online print orders move from pending to approved, ongoing, for pick-up, and done. Other service requests move from pending to ongoing, for pick-up or done, and then finalized.</p>
            <p>Customers can cancel only their own pending requests. A cancelled request becomes an order history record, its paid amount is reset to zero, and staff receive a cancellation notification. Staff can cancel requests through the status update flow and must provide a cancellation reason. Completed and cancelled records are finalized and cannot continue through the normal status flow.</p>
          </section>
          <section class="policy-section">
            <h3>Payments and Transaction Details</h3>
            <p>For Document Printing orders, ServiTech records the selected payment method, order amount, paid amount, and GCash reference number for GCash payments. ServiTech also supports cash selection while regular queues are available. Staff review payment details, update price and paid amount, and approve or continue processing the order through the staff status workflow. ServiTech records transaction details for tracking and review; it does not process payment through an external payment gateway.</p>
          </section>
          <section class="policy-section">
            <h3>Uploaded Files and User Content</h3>
            <p>Users are responsible for the files and content they upload. Upload-enabled services accept up to 5 files per submission, with a maximum of 25 MB per file and 100 MB total, subject to each service's file-type rules. Uploaded files are stored in private upload storage and linked to the user's queue or order. ServiTech uses them only to review and process the requested service. Active-request files remain available while operationally needed, Done or Cancelled request files expire after 30 days, and temporary failed, cancelled, abandoned, or unlinked uploads expire within 24 hours. Users must have the right to upload, print, use, or reproduce submitted files.</p>
          </section>
          <section class="policy-section">
            <h3>Staff Access and Actions</h3>
            <p>Staff can view registered customers, queues, orders, service details, payment details, uploaded files, notifications, status history, services, and announcements. Staff can update request status, add cancellation notes, update prices and paid amounts, manage service listings, and manage announcements. Queue records cannot be permanently deleted through the staff delete endpoint; staff must cancel the order instead.</p>
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
            <p>ServiTech restricts access by customer and staff roles. Accounts and requests can be restricted, cancelled, or blocked from normal processing when users submit harmful content, misuse the system, violate these terms, or compromise system security.</p>
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

    function setFieldState(fieldConfig, message) {
      fieldConfig.error.textContent = message;
      fieldConfig.input.classList.toggle("is-invalid", Boolean(message));
      fieldConfig.input.setAttribute("aria-invalid", message ? "true" : "false");
    }

    function validateField(fieldName, showMessage = true) {
      const field = loginFields[fieldName];
      const message = field.validate(field.input.value);

      if (showMessage) {
        setFieldState(field, message);
      }

      return !message;
    }

    function validateLoginForm(showMessages = true) {
      const results = Object.keys(loginFields).map((fieldName) => validateField(fieldName, showMessages));
      return results.every(Boolean);
    }

    function setMessage(type, text) {
      loginMessage.className = "form-alert " + (type === "success" ? "form-alert--success" : "form-alert--error");
      loginMessage.textContent = text;
      loginMessage.hidden = false;
    }

    function clearMessage() {
      loginMessage.hidden = true;
      loginMessage.textContent = "";
    }

    function setLoginLoading(isLoading, label = "Login") {
      loginSubmit.disabled = isLoading;
      loginSubmit.textContent = isLoading ? "Signing in..." : label;
      loginForm.classList.toggle("is-submitting", isLoading);
    }

    function setGoogleLoading(isLoading) {
      googleFallbackButton.disabled = isLoading;
      googleFallbackLabel.textContent = isLoading ? "Connecting to Google account..." : "Continue with Google Account";
      googleFallbackButton.classList.toggle("is-loading", isLoading);
    }

    function applyPageMessageFromQuery() {
      const params = new URLSearchParams(window.location.search);
      const loginCode = params.get("login");
      const registeredCode = params.get("registered");
      const logoutCode = params.get("logout");

      if (registeredCode === "1") {
        setMessage("success", "Registration successful. You can now log in to your account.");
      } else if (registeredCode === "verify") {
        setMessage("success", "Your account is almost ready. We sent a verification email to your inbox. Confirm your email before logging in.");
        resendVerificationPrompt.hidden = false;
      } else if (registeredCode === "verify_resend") {
        setMessage("error", "Supabase could not deliver a verification email. Request another below. If this is a new email and nothing arrives, wait briefly and register again.");
        resendVerificationPrompt.hidden = false;
      } else if (registeredCode === "exists") {
        setMessage("error", "That email is already registered. Try logging in instead.");
        resendVerificationPrompt.hidden = false;
      } else if (logoutCode === "1") {
        setMessage("success", "You have been logged out.");
      } else if (loginCode === "required") {
        setMessage("error", "Enter your email address and password to continue.");
      } else if (loginCode === "google_required") {
        setMessage("error", "This account uses Google account sign-in. Continue with Google Account to access it.");
      } else if (loginCode === "google_unavailable") {
        setMessage("error", "Google account sign-in is currently unavailable. Please use your email and password.");
      } else if (loginCode === "session_expired") {
        setMessage("error", "Your session expired or your account access changed. Please log in again.");
      } else if (loginCode === "verify_email") {
        setMessage("error", "Verify your email address before logging in. Check your inbox for the verification link.");
        resendVerificationPrompt.hidden = false;
      } else if (loginCode === "throttled") {
        setMessage("error", "Too many failed login attempts. Wait a few minutes before trying again.");
      } else if (loginCode === "fail") {
        setMessage("error", "Invalid email or password.");
      } else if (params.get("verification") === "success") {
        setMessage("success", "Email verified. You can now log in.");
      } else if (params.get("verification") === "invalid") {
        setMessage("error", "That verification link is invalid or has expired.");
      } else if (params.get("reset") === "success") {
        setMessage("success", "Your password has been updated. You can now log in with your new password.");
      }

      if (loginCode || registeredCode || logoutCode || params.get("reset") || params.get("verification")) {
        window.history.replaceState({}, document.title, loginPageUrl);
      }
    }

    async function handleGoogleCredential(response) {
      if (!response || !response.credential) {
        setMessage("error", "Google account sign-in did not return a valid credential. Please try again.");
        return;
      }

      clearMessage();
      setGoogleLoading(true);

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
        setMessage("error", error.message || "Google account sign-in failed. Please try again.");
        setGoogleLoading(false);
      }
    }

    async function loadGoogleSignIn() {
      try {
        const response = await fetch(googleConfigUrl, {
          credentials: "same-origin",
          headers: {
            "Accept": "application/json"
          }
        });
        const config = await response.json();

        if (!response.ok || !config.googleEnabled || !config.googleClientId) {
          googleSignInHint.textContent = "Google account sign-in is not configured yet.";
          googleFallbackButton.disabled = true;
          return;
        }

        if (!window.google || !google.accounts || !google.accounts.id) {
          googleSignInHint.textContent = "Google account sign-in could not be loaded. Please refresh the page.";
          googleFallbackButton.disabled = true;
          return;
        }

        const preferredButtonWidth = googleSignInSlot.parentElement?.clientWidth
          || googleSignInSlot.clientWidth
          || authCard.clientWidth
          || 360;

        google.accounts.id.initialize({
          client_id: config.googleClientId,
          callback: handleGoogleCredential,
          ux_mode: "popup",
          auto_select: false,
          context: "signin"
        });

        google.accounts.id.renderButton(googleSignInSlot, {
          theme: "outline",
          size: "large",
          text: "continue_with",
          shape: "rectangular",
          width: preferredButtonWidth
        });

        googleSignInHint.textContent = "Use your Google account to sign in or create your ServiTech account instantly.";
        googleFallbackButton.disabled = false;
      } catch (error) {
        googleSignInHint.textContent = "Google account sign-in is currently unavailable. Please use your email and password.";
        googleFallbackButton.disabled = true;
      }
    }

    Object.keys(loginFields).forEach((fieldName) => {
      const field = loginFields[fieldName];

      field.input.addEventListener("input", () => validateField(fieldName));
      field.input.addEventListener("blur", () => validateField(fieldName));
    });

    loginForm.addEventListener("submit", (event) => {
      clearMessage();

      if (!validateLoginForm()) {
        event.preventDefault();
        return;
      }

      setLoginLoading(true);
    });

    passwordToggle.addEventListener("click", () => {
      const showPassword = loginPassword.type === "password";
      loginPassword.type = showPassword ? "text" : "password";
      passwordToggle.setAttribute("aria-label", showPassword ? "Hide password" : "Show password");
      passwordToggle.setAttribute("aria-pressed", showPassword ? "true" : "false");
      passwordToggle.classList.toggle("is-visible", showPassword);
    });

    function updatePasswordToggleVisibility() {
      const hasPassword = Boolean(loginPassword.value);
      passwordToggle.classList.toggle("has-value", hasPassword);
      passwordToggle.tabIndex = hasPassword ? 0 : -1;
      passwordToggle.setAttribute("aria-hidden", hasPassword ? "false" : "true");

      if (!hasPassword) {
        loginPassword.type = "password";
        passwordToggle.classList.remove("is-visible");
        passwordToggle.setAttribute("aria-label", "Show password");
        passwordToggle.setAttribute("aria-pressed", "false");
      }
    }

    loginPassword.addEventListener("input", updatePasswordToggleVisibility);
    loginPassword.addEventListener("change", updatePasswordToggleVisibility);
    window.addEventListener("pageshow", updatePasswordToggleVisibility);
    updatePasswordToggleVisibility();

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

    applyPageMessageFromQuery();

    let googleLoadAttempts = 0;
    let googleLoadTimer = null;
    let googleClientScriptLoading = false;
    let googleClientScriptLoaded = Boolean(window.google && google.accounts && google.accounts.id);
    let googleSignInInitialized = false;

    function startGoogleSignInWhenReady() {
      if (googleSignInInitialized) {
        return;
      }
      if (googleLoadTimer) {
        clearInterval(googleLoadTimer);
      }

      googleLoadAttempts = 0;
      googleLoadTimer = setInterval(() => {
        googleLoadAttempts += 1;

        if (window.google && google.accounts && google.accounts.id) {
          clearInterval(googleLoadTimer);
          googleLoadTimer = null;
          googleSignInInitialized = true;
          loadGoogleSignIn();
        } else if (googleLoadAttempts >= 20) {
          clearInterval(googleLoadTimer);
          googleLoadTimer = null;
          googleSignInHint.textContent = "Google account sign-in could not be loaded. Please refresh the page.";
        }
      }, 300);
    }

    function loadGoogleClientScript() {
      if (googleClientScriptLoaded || (window.google && google.accounts && google.accounts.id)) {
        googleClientScriptLoaded = true;
        startGoogleSignInWhenReady();
        return;
      }
      if (googleClientScriptLoading) {
        return;
      }

      googleClientScriptLoading = true;
      const script = document.createElement("script");
      script.src = "https://accounts.google.com/gsi/client";
      script.async = true;
      script.defer = true;
      script.onload = () => {
        googleClientScriptLoaded = true;
        startGoogleSignInWhenReady();
      };
      script.onerror = () => {
        googleSignInHint.textContent = "Google account sign-in could not be loaded. Please use email and password.";
        googleFallbackButton.disabled = true;
      };
      document.head.appendChild(script);
    }

    loadGoogleClientScript();
  </script>

<?php servitech_render_guest_history_guard(); ?>
  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>

</body>
</html>
