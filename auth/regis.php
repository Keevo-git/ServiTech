<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/guest_guard.php";
require_once __DIR__ . "/../config/account.php";
require_once __DIR__ . "/../config/input_limits.php";
require_once __DIR__ . "/../components/privacy_policy_content.php";
servitech_require_guest_page();
$csrfToken = servitech_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Register</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=20260624-register-agreement-v2") ?>">
  <style id="register-agreement-critical-styles">
    body.auth-page--register .agreement-row {
      position: relative !important;
      display: grid !important;
      grid-template-columns: 20px minmax(0, 1fr) !important;
      align-items: start !important;
      column-gap: 12px !important;
    }

    body.auth-page--register .agreement-row__native[type="checkbox"] {
      position: absolute !important;
      width: 1px !important;
      height: 1px !important;
      margin: 0 !important;
      opacity: 0 !important;
      clip-path: inset(50%) !important;
    }

    body.auth-page--register .agreement-row__box {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      width: 20px !important;
      height: 20px !important;
      min-width: 20px !important;
      border: 2px solid #7a0808 !important;
      border-radius: 4px !important;
      background: #fff !important;
      box-sizing: border-box !important;
      visibility: visible !important;
      opacity: 1 !important;
      pointer-events: none !important;
    }

    body.auth-page--register .agreement-row__box svg {
      width: 14px !important;
      height: 14px !important;
      opacity: 0 !important;
    }

    body.auth-page--register .agreement-row__box path {
      fill: none !important;
      stroke: #fff !important;
      stroke-linecap: round !important;
      stroke-linejoin: round !important;
      stroke-width: 2.4 !important;
    }

    body.auth-page--register .agreement-row__native[type="checkbox"]:checked + .agreement-row__box {
      background: #7a0808 !important;
    }

    body.auth-page--register .agreement-row__native[type="checkbox"]:checked + .agreement-row__box svg {
      opacity: 1 !important;
    }

    body.auth-page--register .agreement-row__native[type="checkbox"]:focus-visible + .agreement-row__box {
      outline: 3px solid rgba(255, 139, 44, 0.35) !important;
      outline-offset: 2px !important;
    }
  </style>
</head>
<body class="auth-page auth-page--register">

<?php render_auth_header("register-header-menu", "/auth/log_in.php", "Login"); ?>

  <main class="auth-shell">
    <section class="auth-card auth-card--register" aria-labelledby="register-title">
      <div class="auth-card__header">
        <p class="auth-card__eyebrow">Welcome to ServiTech</p>
        <h1 id="register-title">Create Account</h1>
        <p class="auth-card__subtitle">Set up your customer account to access queueing, service status updates, and Document Printing orders.</p>
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
              <input id="fullname" name="fullname" type="text" placeholder="Enter your full name" autocomplete="name" maxlength="<?= SERVITECH_LIMIT_FULLNAME ?>" required>
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
              <input id="email" name="email" type="email" placeholder="Enter your email address" autocomplete="email" maxlength="<?= SERVITECH_LIMIT_EMAIL ?>" required>
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
              <div class="password-input-wrap">
                <input id="confirmPassword" name="confirm_password" type="password" placeholder="Re-enter your password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" required>
                <button type="button" class="password-toggle" id="registrationConfirmPasswordToggle" aria-label="Show confirm password" aria-pressed="false" aria-hidden="true" tabindex="-1"></button>
              </div>
              <p class="field-error" id="confirmPasswordError" aria-live="polite"></p>
            </div>
          </div>
        </section>

        <section class="form-section form-section--compact form-section--consent">
          <div class="consent-card">
            <label class="agreement-row" for="privacyConsent">
              <input id="privacyConsent" class="agreement-row__native" name="privacy_consent" type="checkbox" value="1" aria-describedby="privacyConsentError" required>
              <span class="agreement-row__box" aria-hidden="true">
                <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                  <path d="m3.2 8.2 3 3.1 6.6-7"></path>
                </svg>
              </span>
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
    const registrationConfirmPasswordToggle = document.getElementById("registrationConfirmPasswordToggle");
    const contactInput = document.getElementById("contact");
    const privacyPolicyBody = <?= json_encode(servitech_privacy_policy_html("modal"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let activePolicyTrigger = null;

    const fields = {
      fullname: {
        input: document.getElementById("fullname"),
        error: document.getElementById("fullnameError"),
        validate: (value) => {
          const trimmedValue = value.trim();
          if (!trimmedValue) {
            return "Full name is required.";
          }
          return trimmedValue.length <= <?= SERVITECH_LIMIT_FULLNAME ?> ? "" : "Full name is too long.";
        }
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
          if (trimmedValue.length > <?= SERVITECH_LIMIT_EMAIL ?>) {
            return "Email address must not exceed <?= SERVITECH_LIMIT_EMAIL ?> characters.";
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
        body: privacyPolicyBody
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
        name_length: "Full name is too long.",
        invalid_contact: "Please enter a valid Philippine mobile number after +63, starting with 9.",
        mismatch: "Passwords do not match.",
        password: `Password must be ${passwordMinLength} to ${passwordMaxLength} characters.`,
        privacy: "You must agree to the Data Privacy Policy before creating an account.",
        error: "We could not create your account right now. Please try again.",
        verification_unavailable: "Account activation is temporarily unavailable because email verification is not configured. Please contact support.",
        verification_redirect: "Email verification could not start because the Supabase confirmation redirect is not configured correctly.",
        profile_setup: "Supabase could not create the account profile. Please contact support before trying again.",
        signup_disabled: "New account registration is currently disabled in Supabase. Please contact support."
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

    function updateRegistrationPasswordToggleVisibility(input, toggle, fieldLabel) {
      const hasValue = Boolean(input.value);
      toggle.classList.toggle("has-value", hasValue);
      toggle.tabIndex = hasValue ? 0 : -1;
      toggle.setAttribute("aria-hidden", hasValue ? "false" : "true");

      if (!hasValue) {
        input.type = "password";
        toggle.classList.remove("is-visible");
        toggle.setAttribute("aria-label", `Show ${fieldLabel}`);
        toggle.setAttribute("aria-pressed", "false");
      }
    }

    function updateRegistrationPasswordToggles() {
      updateRegistrationPasswordToggleVisibility(fields.password.input, registrationPasswordToggle, "password");
      updateRegistrationPasswordToggleVisibility(fields.confirmPassword.input, registrationConfirmPasswordToggle, "confirm password");
    }

    function bindPasswordToggle(input, toggle, fieldLabel) {
      toggle.addEventListener("click", () => {
        const showPassword = input.type === "password";
        input.type = showPassword ? "text" : "password";
        toggle.setAttribute("aria-label", `${showPassword ? "Hide" : "Show"} ${fieldLabel}`);
        toggle.setAttribute("aria-pressed", showPassword ? "true" : "false");
        toggle.classList.toggle("is-visible", showPassword);
      });
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

        if (fieldName === "password" || fieldName === "confirmPassword") {
          updateRegistrationPasswordToggles();
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

    bindPasswordToggle(fields.password.input, registrationPasswordToggle, "password");
    bindPasswordToggle(fields.confirmPassword.input, registrationConfirmPasswordToggle, "confirm password");

    document.querySelectorAll("[data-doc-trigger]").forEach((button) => {
      button.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        openPolicyModal(button.dataset.docTrigger);
      });
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
      updateRegistrationPasswordToggles();
      validateForm(false);
    });
    updateRegistrationPasswordToggles();

    let registerGoogleLoadAttempts = 0;
    let registerGoogleLoadTimer = null;
    let registerGoogleClientScriptLoading = false;
    let registerGoogleClientScriptLoaded = Boolean(window.google && google.accounts && google.accounts.id);
    let registerGoogleSignInInitialized = false;

    function startRegisterGoogleSignInWhenReady() {
      if (registerGoogleSignInInitialized) {
        return;
      }
      if (registerGoogleLoadTimer) {
        clearInterval(registerGoogleLoadTimer);
      }

      registerGoogleLoadAttempts = 0;
      registerGoogleLoadTimer = setInterval(() => {
        registerGoogleLoadAttempts += 1;

        if (window.google && google.accounts && google.accounts.id) {
          clearInterval(registerGoogleLoadTimer);
          registerGoogleLoadTimer = null;
          registerGoogleSignInInitialized = true;
          loadRegisterGoogleSignIn();
        } else if (registerGoogleLoadAttempts >= 20) {
          clearInterval(registerGoogleLoadTimer);
          registerGoogleLoadTimer = null;
          const signInHint = document.getElementById("registerGoogleSignInHint");
          if (signInHint) {
            signInHint.textContent = "Google account sign-in could not be loaded. Please refresh the page.";
            signInHint.hidden = false;
          }
        }
      }, 300);
    }

    function loadRegisterGoogleClientScript() {
      if (registerGoogleClientScriptLoaded || (window.google && google.accounts && google.accounts.id)) {
        registerGoogleClientScriptLoaded = true;
        startRegisterGoogleSignInWhenReady();
        return;
      }
      if (registerGoogleClientScriptLoading) {
        return;
      }

      registerGoogleClientScriptLoading = true;
      const script = document.createElement("script");
      script.src = "https://accounts.google.com/gsi/client";
      script.async = true;
      script.defer = true;
      script.onload = () => {
        registerGoogleClientScriptLoaded = true;
        startRegisterGoogleSignInWhenReady();
      };
      script.onerror = () => {
        const signInHint = document.getElementById("registerGoogleSignInHint");
        const fallbackButton = document.getElementById("registerGoogleFallbackButton");
        if (signInHint) {
          signInHint.textContent = "Google account sign-in could not be loaded. Please use the form above.";
          signInHint.hidden = false;
        }
        if (fallbackButton) fallbackButton.disabled = true;
      };
      document.head.appendChild(script);
    }

    loadRegisterGoogleClientScript();
  </script>

<?php servitech_render_guest_history_guard(); ?>
  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>

</body>
</html>
