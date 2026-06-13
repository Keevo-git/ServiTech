<?php
require_once __DIR__ . "/../config/app.php";

if (!function_exists("servitech_cookie_consent_has_valid_cookie_choice")) {
    function servitech_cookie_consent_has_valid_cookie_choice(): bool
    {
        $raw = trim((string)($_COOKIE["SERVITECH_COOKIE_CONSENT"] ?? ""));
        if ($raw === "") {
            return false;
        }

        $preference = json_decode($raw, true);
        return is_array($preference)
            && ($preference["necessary"] ?? null) === true
            && is_bool($preference["functional"] ?? null);
    }
}

if (!function_exists("servitech_render_cookie_consent")) {
    function servitech_render_cookie_consent(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $cookiePath = servitech_cookie_path();
        $stylePath = __DIR__ . "/../assets/css/cookie-consent.css";
        $scriptPath = __DIR__ . "/../assets/js/cookie_consent.js";
        $consentStyles = is_file($stylePath) ? (string)file_get_contents($stylePath) : "";
        $consentScript = is_file($scriptPath) ? (string)file_get_contents($scriptPath) : "";
        $consentScript = str_ireplace("</script", "<\/script", $consentScript);
        $serverHasChoice = servitech_cookie_consent_has_valid_cookie_choice();
        ?>
<style data-cookie-consent-styles>
<?= $consentStyles ?>
</style>

<div
  class="cookie-consent"
  id="servitechCookieConsent"
  data-cookie-consent-root
  data-cookie-path="<?= htmlspecialchars($cookiePath, ENT_QUOTES, 'UTF-8') ?>"
  data-server-has-choice="<?= $serverHasChoice ? "true" : "false" ?>"
  <?= $serverHasChoice ? "hidden" : "" ?>
>
  <section class="cookie-consent__banner" data-cookie-banner role="region" aria-labelledby="cookieConsentTitle">
    <div class="cookie-consent__copy">
      <p class="cookie-consent__eyebrow">Privacy controls</p>
      <h2 id="cookieConsentTitle">ServiTech uses necessary cookies and browser storage.</h2>
      <p>
        Necessary cookies and authentication storage keep login, Google account access, security checks, forms, uploads, notifications, and service pages working.
        Optional functional enhancements only run when you allow them.
      </p>
    </div>
    <div class="cookie-consent__actions" aria-label="Cookie consent actions">
      <button type="button" class="cookie-consent__btn cookie-consent__btn--primary" data-cookie-action="accept-all">Accept All</button>
      <button type="button" class="cookie-consent__btn" data-cookie-action="reject">Reject Non-Essential</button>
      <button type="button" class="cookie-consent__btn cookie-consent__btn--ghost" data-cookie-action="manage">Manage Preferences</button>
    </div>
  </section>

  <div class="cookie-consent__modal" data-cookie-modal hidden>
    <div class="cookie-consent__backdrop" data-cookie-action="close" aria-hidden="true"></div>
    <section
      class="cookie-consent__dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="cookiePreferencesTitle"
      aria-describedby="cookiePreferencesIntro"
      tabindex="-1"
    >
      <button type="button" class="cookie-consent__close" data-cookie-action="close" aria-label="Close cookie preferences">
        <span aria-hidden="true">&times;</span>
      </button>
      <div class="cookie-consent__dialog-head">
        <p class="cookie-consent__eyebrow">Cookie Preferences</p>
        <h2 id="cookiePreferencesTitle">Choose what ServiTech may use</h2>
        <p id="cookiePreferencesIntro">
          You can change these settings later from the Cookie Preferences link in the footer.
        </p>
      </div>

      <div class="cookie-consent__category">
        <div>
          <h3>Strictly Necessary</h3>
          <p>Required for login sessions, Google authentication when selected, CSRF protection, security checks, upload continuity, forms, notifications, and short-lived workflow messages.</p>
        </div>
        <span class="cookie-consent__status" aria-label="Strictly necessary cookies are always active">Always active</span>
      </div>

      <label class="cookie-consent__category cookie-consent__category--toggle">
        <span>
          <strong>Functional Enhancements</strong>
          <span>Allows optional realtime notification enhancement. Core notifications continue through regular polling when this is off.</span>
        </span>
        <input type="checkbox" data-cookie-functional-toggle>
      </label>

      <p class="cookie-consent__note">
        ServiTech does not currently use analytics, advertising, or marketing tracking cookies. Those categories are not shown because no active scripts were found for them.
      </p>

      <div class="cookie-consent__dialog-actions">
        <button type="button" class="cookie-consent__btn cookie-consent__btn--primary" data-cookie-action="save">Save Preferences</button>
        <button type="button" class="cookie-consent__btn" data-cookie-action="reject">Reject Non-Essential</button>
        <button type="button" class="cookie-consent__btn cookie-consent__btn--ghost" data-cookie-action="accept-all">Accept All</button>
      </div>
    </section>
  </div>
</div>

<script data-cookie-consent-script>
<?= $consentScript ?>
</script>
<?php
    }
}

servitech_render_cookie_consent();
