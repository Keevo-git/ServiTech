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
            && ($preference["necessary"] ?? null) === true;
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
        These required settings keep you signed in, protect your account, support forms and uploads, manage bookings, show important notifications, and remember your cookie choice.
      </p>
    </div>
    <div class="site-privacy-controls__actions" aria-label="Cookie consent actions">
      <button type="button" class="site-privacy-controls__btn site-privacy-controls__btn--primary" data-privacy-action="continue-required">Continue with Required Only</button>
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
      <a href="#privacy-settings-closed" class="site-privacy-controls__close" data-privacy-action="close" aria-label="Close and continue with required settings" title="Close and continue with required settings">
        <span aria-hidden="true">&times;</span>
      </a>
      <div class="site-privacy-controls__dialog-head">
        <p class="site-privacy-controls__eyebrow">Cookie Preferences</p>
        <h2 id="privacySettingsTitle">Required website settings</h2>
        <p id="privacySettingsIntro">
          ServiTech currently uses only the cookies and browser settings required for the website to work.
        </p>
      </div>

      <div class="site-privacy-controls__category">
        <div>
          <h3>Strictly Necessary</h3>
          <p>Required to keep you signed in, protect your account, process forms, continue uploads, manage bookings, show important system notifications, and support Google sign-in when you choose it. These are always active because the website cannot work properly without them.</p>
        </div>
        <span class="site-privacy-controls__status" aria-label="Strictly necessary cookies are always active">Always active</span>
      </div>

      <p class="site-privacy-controls__note">
        ServiTech does not currently use analytics, advertising, or marketing tracking cookies. These options are not shown because they are not being used.
      </p>

      <div class="site-privacy-controls__dialog-actions">
        <button type="button" class="site-privacy-controls__btn site-privacy-controls__btn--primary" data-privacy-action="continue-required">Continue with Required Only</button>
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
