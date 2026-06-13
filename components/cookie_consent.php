<?php
require_once __DIR__ . "/../config/app.php";

if (!function_exists("servitech_render_cookie_consent")) {
    function servitech_render_cookie_consent(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $assetVersion = "20260613-cookie-consent";
        $cookiePath = servitech_cookie_path();
        ?>
<link rel="stylesheet" href="<?= htmlspecialchars(servitech_url('/assets/css/cookie-consent.css?v=' . $assetVersion), ENT_QUOTES, 'UTF-8') ?>">

<div
  class="cookie-consent"
  id="servitechCookieConsent"
  data-cookie-consent-root
  data-cookie-path="<?= htmlspecialchars($cookiePath, ENT_QUOTES, 'UTF-8') ?>"
  hidden
>
  <section class="cookie-consent__banner" data-cookie-banner role="region" aria-labelledby="cookieConsentTitle">
    <div class="cookie-consent__copy">
      <p class="cookie-consent__eyebrow">Privacy controls</p>
      <h2 id="cookieConsentTitle">ServiTech uses necessary cookies and browser storage.</h2>
      <p>
        Necessary cookies keep login, security checks, forms, uploads, notifications, and service pages working.
        Optional functional services, such as Google Sign-In and realtime notification enhancement, only run when you allow them.
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
          <p>Required for login sessions, CSRF protection, security checks, upload continuity, forms, notifications, and short-lived workflow messages.</p>
        </div>
        <span class="cookie-consent__status" aria-label="Strictly necessary cookies are always active">Always active</span>
      </div>

      <label class="cookie-consent__category cookie-consent__category--toggle">
        <span>
          <strong>Functional Services</strong>
          <span>Allows optional Google Sign-In and realtime notification enhancement. Core email/password login and notification polling still work when this is off.</span>
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

<script src="<?= htmlspecialchars(servitech_url('/assets/js/cookie_consent.js?v=' . $assetVersion), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php
    }
}

servitech_render_cookie_consent();
