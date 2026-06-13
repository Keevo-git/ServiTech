<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/contact.php";

const AUTH_UI_VERSION = "20260613-footer-legal-links";

if (!function_exists("auth_url_raw")) {
    function auth_url_raw(string $path = "/"): string
    {
        return servitech_url($path);
    }
}

if (!function_exists("auth_url")) {
    function auth_url(string $path = "/"): string
    {
        return htmlspecialchars(auth_url_raw($path), ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists("auth_json_url")) {
    function auth_json_url(string $path = "/"): string
    {
        return json_encode(auth_url_raw($path), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}

if (!function_exists("auth_contact_email")) {
    function auth_contact_email(): string
    {
        return servitech_contact_email();
    }
}

if (!function_exists("auth_contact_email_html")) {
    function auth_contact_email_html(): string
    {
        return htmlspecialchars(auth_contact_email(), ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists("auth_contact_link_html")) {
    function auth_contact_link_html(): string
    {
        $email = auth_contact_email();
        if ($email === "") {
            return "Contact email unavailable";
        }

        $safeEmail = htmlspecialchars($email, ENT_QUOTES, "UTF-8");
        return '<a href="mailto:' . $safeEmail . '">' . $safeEmail . '</a>';
    }
}

if (!function_exists("render_auth_header")) {
    function render_auth_header(string $menuId, string $secondaryPath, string $secondaryLabel): void
    {
        ?>
  <header class="navbar has-nav-menu site-header auth-header">
    <a href="<?= htmlspecialchars(servitech_brand_home_url(), ENT_QUOTES, "UTF-8") ?>" class="logo">
      <img src="<?= auth_url("/assets/images/LOGO_SERVITECH.png") ?>" alt="ServiTech Logo" class="servitech-logo">
      <h1>ServiTech</h1>
    </a>
    <button
      class="nav-toggle"
      type="button"
      aria-label="Toggle navigation menu"
      aria-expanded="false"
      aria-controls="<?= htmlspecialchars($menuId, ENT_QUOTES, "UTF-8") ?>"
    >
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
    </button>
    <nav id="<?= htmlspecialchars($menuId, ENT_QUOTES, "UTF-8") ?>" data-collapsible-menu>
      <a href="<?= auth_url("/index.php") ?>">Services Home</a>
      <a href="<?= auth_url($secondaryPath) ?>"><?= htmlspecialchars($secondaryLabel, ENT_QUOTES, "UTF-8") ?></a>
    </nav>
  </header>
<?php
    }
}

if (!function_exists("render_auth_footer")) {
    function render_auth_footer(): void
    {
        ?>
  <footer class="footer">
    <div class="footer-container">
      <div class="footer-left">
        <h3>Contact Us:</h3>

        <div class="contact-item">
          <img src="<?= auth_url("/assets/images/FOOTER_FB.png") ?>" alt="Facebook">
          <a href="<?= htmlspecialchars(servitech_contact_facebook_url(), ENT_QUOTES, "UTF-8") ?>" target="_blank" rel="noopener noreferrer">
            <?= htmlspecialchars(servitech_contact_facebook_label(), ENT_QUOTES, "UTF-8") ?>
          </a>
        </div>

      <div class="contact-item">
        <img src="<?= auth_url("/assets/images/FOOTER_EMAIL.png") ?>" alt="Email">
          <?= auth_contact_link_html() ?>
      </div>

        <div class="contact-item">
          <img src="<?= auth_url("/assets/images/FOOTER_PHONE.png") ?>" alt="Phone">
          <span><?= htmlspecialchars(servitech_contact_phone(), ENT_QUOTES, "UTF-8") ?></span>
        </div>
      </div>

      <div class="footer-right">
        <a href="<?= auth_url("/index.php") ?>" class="footer-logo-link">
          <img src="<?= auth_url("/assets/images/LOGO_SERVITECH.png") ?>" alt="ServiTech Logo" class="footer-servitech-logo">
          <h1>ServiTech: JC Store</h1>
        </a>
      </div>
    </div>

    <div class="footer-legal-links" aria-label="Footer legal links">
      <a href="<?= auth_url("/privacy-policy.php") ?>">Privacy Policy</a>
      <span aria-hidden="true">|</span>
      <a href="<?= auth_url("/terms-of-service.php") ?>">Terms of Service</a>
      <span aria-hidden="true">|</span>
      <a href="<?= auth_url("/privacy-policy.php#cookie-preferences") ?>" class="cookie-preferences-link" data-cookie-preferences-open>Cookie Preferences</a>
    </div>

    <p class="footer-bottom">&copy; 2026 ServiTech: JC Store</p>
  </footer>
<?php require_once __DIR__ . "/../components/cookie_consent.php"; ?>
<?php
    }
}
