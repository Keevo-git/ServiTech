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
<style data-site-privacy-controls-styles>
<?= $consentStyles ?>
</style>

<div
  class="site-privacy-controls"
  id="servitechPrivacyControls"
  data-site-privacy-root
  data-storage-path="<?= htmlspecialchars($cookiePath, ENT_QUOTES, 'UTF-8') ?>"
  data-server-has-choice="<?= $serverHasChoice ? "true" : "false" ?>"
>
  <section
    class="site-privacy-controls__banner"
    data-privacy-notice
    role="region"
    aria-labelledby="privacyControlsTitle"
    <?= $serverHasChoice ? "hidden" : "" ?>
  >
    <div class="site-privacy-controls__copy">
      <p class="site-privacy-controls__eyebrow">Privacy controls</p>
      <h2 id="privacyControlsTitle">ServiTech uses necessary cookies and browser storage.</h2>
      <p>
        Necessary cookies and authentication storage keep login, Google account access, security checks, forms, uploads, notifications, and service pages working.
        Optional functional enhancements only run when you allow them.
      </p>
    </div>
    <div class="site-privacy-controls__actions" aria-label="Cookie consent actions">
      <button type="button" class="site-privacy-controls__btn site-privacy-controls__btn--primary" data-privacy-action="accept-all">Accept All</button>
      <button type="button" class="site-privacy-controls__btn" data-privacy-action="reject">Reject Non-Essential</button>
      <button type="button" class="site-privacy-controls__btn site-privacy-controls__btn--ghost" data-privacy-action="manage">Manage Preferences</button>
    </div>
  </section>

  <div class="site-privacy-controls__modal" id="privacy-settings" data-privacy-modal hidden>
    <div class="site-privacy-controls__backdrop" data-privacy-action="close" aria-hidden="true"></div>
    <section
      class="site-privacy-controls__dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="privacySettingsTitle"
      aria-describedby="privacySettingsIntro"
      tabindex="-1"
    >
      <a href="#privacy-settings-closed" class="site-privacy-controls__close" data-privacy-action="close" aria-label="Close cookie preferences">
        <span aria-hidden="true">&times;</span>
      </a>
      <div class="site-privacy-controls__dialog-head">
        <p class="site-privacy-controls__eyebrow">Cookie Preferences</p>
        <h2 id="privacySettingsTitle">Choose what ServiTech may use</h2>
        <p id="privacySettingsIntro">
          You can change these settings later from the Cookie Preferences link in the footer.
        </p>
      </div>

      <div class="site-privacy-controls__category">
        <div>
          <h3>Strictly Necessary</h3>
          <p>Required for login sessions, Google authentication when selected, CSRF protection, security checks, upload continuity, forms, notifications, and short-lived workflow messages.</p>
        </div>
        <span class="site-privacy-controls__status" aria-label="Strictly necessary cookies are always active">Always active</span>
      </div>

      <label class="site-privacy-controls__category site-privacy-controls__category--toggle">
        <span>
          <strong>Functional Enhancements</strong>
          <span>Allows optional realtime notification enhancement. Core notifications continue through regular polling when this is off.</span>
        </span>
        <input type="checkbox" data-privacy-functional-toggle>
      </label>

      <p class="site-privacy-controls__note">
        ServiTech does not currently use analytics, advertising, or marketing tracking cookies. Those categories are not shown because no active scripts were found for them.
      </p>

      <div class="site-privacy-controls__dialog-actions">
        <button type="button" class="site-privacy-controls__btn site-privacy-controls__btn--primary" data-privacy-action="save">Save Preferences</button>
        <button type="button" class="site-privacy-controls__btn" data-privacy-action="reject">Reject Non-Essential</button>
        <button type="button" class="site-privacy-controls__btn site-privacy-controls__btn--ghost" data-privacy-action="accept-all">Accept All</button>
      </div>
    </section>
  </div>
</div>

<script data-site-privacy-controls-script>
<?= $consentScript ?>
</script>
<?php
    }
}

servitech_render_cookie_consent();
