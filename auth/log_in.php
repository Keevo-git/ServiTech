<?php
require_once __DIR__ . "/_shared.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Login</title>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=" . AUTH_UI_VERSION) ?>">
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
            <button type="button" class="password-toggle" id="passwordToggle" aria-label="Show password">Show</button>
          </div>
          <div class="forgot-password-container">
            <a href="<?= auth_url("/auth/forgot_password.php") ?>" class="forgot-link">Forgot Password?</a>
          </div>
          <p class="field-error" id="loginPasswordError" aria-live="polite"></p>
        </div>

        <button type="submit" id="loginSubmit" class="auth-submit">Login</button>
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
    const loginPageUrl = <?= auth_json_url("/auth/log_in.php") ?>;
    const googleLoginUrl = <?= auth_json_url("/auth/google_login.php") ?>;
    const googleConfigUrl = <?= auth_json_url("/auth/google_config.php") ?>;
    const defaultCustomerRedirectUrl = <?= auth_json_url("/pages/customer/customer_dash.php") ?>;

    const loginForm = document.getElementById("loginForm");
    const loginSubmit = document.getElementById("loginSubmit");
    const loginMessage = document.getElementById("loginMessage");
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
            <p>For concerns or inquiries regarding privacy and data usage, users may contact:<br><a href="mailto:theservitech.store@gmail.com">theservitech.store@gmail.com</a></p>
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
      } else if (registeredCode === "exists") {
        setMessage("error", "That email is already registered. Try logging in instead.");
      } else if (logoutCode === "1") {
        setMessage("success", "You have been logged out.");
      } else if (loginCode === "required") {
        setMessage("error", "Enter your email address and password to continue.");
      } else if (loginCode === "google_required") {
        setMessage("error", "This account uses Google account sign-in. Continue with Google Account to access it.");
      } else if (loginCode === "google_unavailable") {
        setMessage("error", "Google account sign-in is currently unavailable. Please use your email and password.");
      } else if (loginCode === "fail") {
        setMessage("error", "Invalid email or password.");
      } else if (params.get("reset") === "success") {
        setMessage("success", "Your password has been updated. You can now log in with your new password.");
      }

      if (loginCode || registeredCode || logoutCode || params.get("reset")) {
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
          body: JSON.stringify({ credential: response.credential })
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
      passwordToggle.textContent = showPassword ? "Hide" : "Show";
      passwordToggle.setAttribute("aria-label", showPassword ? "Hide password" : "Show password");
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

    applyPageMessageFromQuery();

    let googleLoadAttempts = 0;
    const googleLoadTimer = setInterval(() => {
      googleLoadAttempts += 1;

      if (window.google && google.accounts && google.accounts.id) {
        clearInterval(googleLoadTimer);
        loadGoogleSignIn();
      } else if (googleLoadAttempts >= 20) {
        clearInterval(googleLoadTimer);
        googleSignInHint.textContent = "Google account sign-in could not be loaded. Please refresh the page.";
      }
    }, 300);
  </script>

  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>

</body>
</html>
