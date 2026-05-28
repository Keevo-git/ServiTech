<?php
require_once __DIR__ . "/../config/app.php";

const AUTH_UI_VERSION = "20260528auth5";

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

if (!function_exists("render_auth_header")) {
    function render_auth_header(string $menuId, string $secondaryPath, string $secondaryLabel): void
    {
        ?>
  <header class="navbar has-nav-menu">
    <a href="<?= auth_url("/index.php") ?>" class="logo">
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
          <a href="https://www.facebook.com/" target="_blank" rel="noopener noreferrer">
            JC Store
          </a>
        </div>

        <div class="contact-item">
          <img src="<?= auth_url("/assets/images/FOOTER_EMAIL.png") ?>" alt="Email">
          <a href="mailto:theservitech.store@gmail.com">
            theservitech.store@gmail.com
          </a>
        </div>

        <div class="contact-item">
          <img src="<?= auth_url("/assets/images/FOOTER_PHONE.png") ?>" alt="Phone">
          <span>+63 912 393 4321</span>
        </div>
      </div>

      <div class="footer-right">
        <a href="<?= auth_url("/index.php") ?>" class="footer-logo-link">
          <img src="<?= auth_url("/assets/images/LOGO_SERVITECH.png") ?>" alt="ServiTech Logo" class="footer-servitech-logo">
          <h1>ServiTech: JC Store</h1>
        </a>
      </div>
    </div>

    <p class="footer-bottom">&copy; 2026 ServiTech: JC Store</p>
  </footer>
<?php
    }
}
