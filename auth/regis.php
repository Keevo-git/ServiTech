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
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=" . AUTH_UI_VERSION) ?>">
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

          <div class="form-grid form-grid--single">
            <div class="form-field">
              <label for="fullname">Full Name</label>
              <input id="fullname" name="fullname" type="text" placeholder="Enter your full name" autocomplete="name" required>
              <p class="field-error" id="fullnameError" aria-live="polite"></p>
            </div>

            <div class="form-field">
              <label for="contact">Contact Number</label>
              <input id="contact" name="contact" type="tel" inputmode="tel" placeholder="09XXXXXXXXX" autocomplete="tel" maxlength="13" pattern="(?:09[0-9]{9}|\+639[0-9]{9})" title="Enter a Philippine mobile number, such as 09XXXXXXXXX or +639XXXXXXXXX." required>
              <p class="field-error" id="contactError" aria-live="polite"></p>
            </div>
          </div>

          <div class="form-field">
            <label for="email">Email Address</label>
            <input id="email" name="email" type="email" placeholder="Enter your email address" autocomplete="email" required>
            <p class="field-error" id="emailError" aria-live="polite"></p>
          </div>
        </section>

        <section class="form-section" aria-labelledby="account-info-title">
          <div class="form-section__header">
            <h2 id="account-info-title">Account Info</h2>
            <p>Create a secure password for your account.</p>
          </div>

          <div class="form-grid form-grid--single">
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

        <section class="form-section form-section--compact">
          <div class="consent-card">
            <label class="consent-check" for="privacyConsent">
              <input id="privacyConsent" name="privacy_consent" type="checkbox" value="1" required>
              <span>I agree to the <button type="button" class="text-link" data-doc-trigger="privacy">Data Privacy Policy</button> and <button type="button" class="text-link" data-doc-trigger="terms">Terms &amp; Conditions</button>.</span>
            </label>
            <p class="field-error" id="privacyConsentError" aria-live="polite"></p>
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
    let activePolicyTrigger = null;

    const fields = {
      fullname: {
        input: document.getElementById("fullname"),
        error: document.getElementById("fullnameError"),
        validate: (value) => value.trim() ? "" : "Full name is required."
      },
      contact: {
        input: document.getElementById("contact"),
        error: document.getElementById("contactError"),
        validate: (value) => {
          const trimmedValue = value.trim();
          if (!trimmedValue) {
            return "Contact number is required.";
          }

          const philippineMobilePattern = /^(?:09\d{9}|\+639\d{9})$/;
          return philippineMobilePattern.test(trimmedValue)
            ? ""
            : "Enter a valid Philippine mobile number, such as 09XXXXXXXXX.";
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
            <p>ServiTech respects and values the privacy of all users accessing the platform. This Privacy Policy explains how user information is collected, used, stored, and protected within the ServiTech system.</p>
          </section>
          <section class="policy-section">
            <h3>Information Collection</h3>
            <p>ServiTech may collect personal information such as full name, email address, contact number, account credentials, uploaded files, queue transaction details, and service request information.</p>
          </section>
          <section class="policy-section">
            <h3>Purpose of Data Usage</h3>
            <p>Collected data is used solely for account registration, authentication, queue management, printing, repair, installation, laminating, rush ID services, customer communication, academic evaluation, and system improvement.</p>
          </section>
          <section class="policy-section">
            <h3>Data Protection</h3>
            <p>Uploaded files and documents are processed only for the requested transaction and are not intentionally shared with unauthorized individuals or third parties. ServiTech implements reasonable security measures to protect user information from unauthorized access, misuse, disclosure, or alteration.</p>
          </section>
          <section class="policy-section">
            <h3>Academic Purpose</h3>
            <p>ServiTech is a student academic capstone project developed for educational, demonstration, and evaluation purposes. The platform is intended to simulate queueing and service management operations for learning and academic presentation.</p>
          </section>
          <section class="policy-section">
            <h3>User Responsibility</h3>
            <p>Users agree to provide accurate information and ensure that uploaded content complies with applicable laws, school regulations, and ethical standards.</p>
          </section>
          <section class="policy-section">
            <h3>Policy Updates</h3>
            <p>ServiTech reserves the right to update or modify this Privacy Policy whenever necessary to improve security, functionality, academic evaluation, and operational performance.</p>
          </section>
          <section class="policy-section">
            <h3>Contact Information</h3>
            <p>For concerns or inquiries regarding privacy and data usage, users may contact:<br><?= auth_contact_link_html() ?></p>
          </section>
        `
      },
      terms: {
        title: "ServiTech Terms & Conditions",
        body: `
          <section class="policy-section">
            <h3>Overview</h3>
            <p>By creating an account and using the ServiTech platform, users agree to comply with these Terms and Conditions.</p>
          </section>
          <section class="policy-section">
            <h3>Academic Purpose</h3>
            <p>ServiTech is a student academic capstone project developed for educational, demonstration, and evaluation purposes. The platform is intended to simulate queueing and service management operations for learning and academic presentation.</p>
          </section>
          <section class="policy-section">
            <h3>Account Responsibility</h3>
            <p>Users are responsible for maintaining the confidentiality of their account credentials and for all activity made through their account.</p>
          </section>
          <section class="policy-section">
            <h3>Accurate Information</h3>
            <p>Users must provide complete, accurate, and updated information during registration and service transactions.</p>
          </section>
          <section class="policy-section">
            <h3>Proper Use of the Platform</h3>
            <p>ServiTech may only be used for lawful and appropriate service requests related to printing, repair, installation, laminating, rush ID processing, and other related services.</p>
          </section>
          <section class="policy-section">
            <h3>Uploaded Files and Content</h3>
            <p>Users are solely responsible for all uploaded files, documents, and submitted content. Uploading illegal, harmful, offensive, or unauthorized materials is strictly prohibited.</p>
          </section>
          <section class="policy-section">
            <h3>Queue and Service Processing</h3>
            <p>Queue estimates, turnaround times, and service availability may vary depending on operational workload, testing conditions, and staff availability.</p>
          </section>
          <section class="policy-section">
            <h3>System Limitations</h3>
            <p>As an academic project, ServiTech may experience temporary errors, maintenance periods, incomplete features, or technical limitations. Developers shall not be held liable for interruptions caused by technical issues beyond reasonable control.</p>
          </section>
          <section class="policy-section">
            <h3>User Conduct</h3>
            <p>Users must not misuse the platform, attempt unauthorized access, interfere with system operations, or compromise platform security and functionality.</p>
          </section>
          <section class="policy-section">
            <h3>Modification of Services</h3>
            <p>ServiTech reserves the right to modify, improve, suspend, or update system features, workflows, and services whenever necessary for academic evaluation, operational improvement, and security purposes.</p>
          </section>
          <section class="policy-section">
            <h3>Agreement and Acceptance</h3>
            <p>By continuing to use the platform, users acknowledge that they have read, understood, and agreed to the ServiTech Data Privacy Policy and Terms & Conditions.</p>
          </section>
        `
      }
    };

    function setFieldState(fieldConfig, message) {
      const isCheckbox = fieldConfig.input.type === "checkbox";
      const target = isCheckbox ? fieldConfig.input.closest(".consent-card") : fieldConfig.input;

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
        invalid_contact: "Please enter a valid Philippine mobile number, such as 09XXXXXXXXX.",
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
      const compactValue = value.replace(/\s+/g, "");
      const hasAllowedPlusPrefix = compactValue.startsWith("+");
      const digits = compactValue.replace(/\D/g, "");
      return hasAllowedPlusPrefix ? `+${digits.slice(0, 12)}` : digits.slice(0, 11);
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
          field.input.value = sanitizePhilippineMobileInput(field.input.value);
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
    validateForm(false);
    window.addEventListener("pageshow", updateRegistrationPasswordToggleVisibility);
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
