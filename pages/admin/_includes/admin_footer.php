<?php
if (!function_exists("admin_url")) {
    require_once __DIR__ . "/url.php";
}
require_once __DIR__ . "/../../../config/contact.php";
$adminFooterEmail = servitech_contact_email();
?>
<style>
  .admin-shared-footer {
    background: linear-gradient(120deg, #112b4f, #1a3f73 52%, #265792) !important;
    color: #ffffff !important;
    padding: 30px clamp(20px, 4vw, 40px) 20px !important;
    overflow: hidden;
    box-shadow: 0 -10px 24px rgba(17, 43, 79, 0.16);
  }

  .admin-shared-footer .footer-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 28px 40px;
    width: min(100%, 1100px);
    margin: 0 auto;
  }

  .admin-shared-footer .footer-left,
  .admin-shared-footer .footer-right {
    min-width: 0;
  }

  .admin-shared-footer .footer-right {
    display: flex;
    align-items: center;
    justify-content: flex-end;
  }

  .admin-shared-footer .footer-logo-link {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    max-width: 100%;
    text-decoration: none !important;
  }

  .admin-shared-footer .contact-item {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
  }

  .admin-shared-footer .contact-item a,
  .admin-shared-footer .contact-item span {
    overflow-wrap: anywhere;
  }

  .admin-shared-footer .footer-logo-link,
  .admin-shared-footer .footer-logo-link h1,
  .admin-shared-footer .contact-item a,
  .admin-shared-footer .contact-item span,
  .admin-shared-footer .footer-legal-links a,
  .admin-shared-footer .footer-legal-links span,
  .admin-shared-footer .footer-legal-links button,
  .admin-shared-footer .footer-bottom,
  .admin-shared-footer .footer-left h3 {
    color: #ffffff !important;
  }

  .admin-shared-footer a,
  .admin-shared-footer button {
    text-decoration: none !important;
    transition:
      color .2s ease,
      opacity .2s ease,
      text-shadow .2s ease,
      background-color .2s ease,
      transform .2s ease;
  }

  .admin-shared-footer .footer-legal-links {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    width: min(100%, 1100px);
    margin: 24px auto 0;
    padding-top: 16px;
    border-top: 1px solid rgba(255, 255, 255, 0.18);
    gap: 0;
    font-size: 13px;
    line-height: 1.4;
    text-align: center;
  }

  .admin-shared-footer .footer-legal-links a,
  .admin-shared-footer .footer-legal-links button {
    border-radius: 8px;
    padding: 6px 10px;
    text-decoration: none !important;
  }

  .admin-shared-footer .footer-logo-link:hover,
  .admin-shared-footer .contact-item a:hover,
  .admin-shared-footer .footer-legal-links a:hover,
  .admin-shared-footer .footer-legal-links button:hover {
    color: #ffffff !important;
    opacity: .9;
    text-decoration: none !important;
    text-shadow: 0 0 8px rgba(255, 255, 255, .25);
  }

  .admin-shared-footer .footer-legal-links a:hover,
  .admin-shared-footer .footer-legal-links button:hover {
    background: rgba(255, 255, 255, 0.08);
  }

  .admin-shared-footer .footer-logo-link:focus-visible,
  .admin-shared-footer .contact-item a:focus-visible,
  .admin-shared-footer .footer-legal-links a:focus-visible,
  .admin-shared-footer .footer-legal-links button:focus-visible {
    color: #ffffff !important;
    text-decoration: none !important;
    outline: 2px solid rgba(255, 255, 255, .82);
    outline-offset: 3px;
  }

  .admin-shared-footer .footer-bottom {
    margin: 10px 0 0 !important;
    padding-top: 0 !important;
    border-top: 0 !important;
    text-align: center;
  }

  @media (max-width: 760px) {
    .admin-shared-footer .footer-container {
      flex-direction: column !important;
      align-items: center !important;
      text-align: center;
      gap: 26px;
    }

    .admin-shared-footer .footer-left,
    .admin-shared-footer .footer-right {
      width: 100%;
    }

    .admin-shared-footer .footer-right,
    .admin-shared-footer .footer-logo-link,
    .admin-shared-footer .contact-item {
      justify-content: center !important;
    }

    .admin-shared-footer .footer-legal-links {
      margin-top: 22px;
      padding-top: 14px;
    }
  }

  @media (max-width: 420px) {
    .admin-shared-footer .footer-logo-link {
      flex-wrap: wrap;
    }

    .admin-shared-footer .footer-logo-link h1 {
      font-size: 18px;
    }

    .admin-shared-footer .footer-legal-links {
      flex-direction: column;
      gap: 4px;
    }

    .admin-shared-footer .footer-legal-links a,
    .admin-shared-footer .footer-legal-links button {
      width: 100%;
    }

    .admin-shared-footer .footer-legal-links span {
      display: none;
    }
  }
</style>
<footer class="footer admin-shared-footer">
  <div class="footer-container">
    <div class="footer-left">
      <h3>Contact Us:</h3>

      <div class="contact-item">
        <img src="<?= admin_url('/assets/images/FOOTER_FB.png') ?>" alt="Facebook">
        <a href="<?= htmlspecialchars(servitech_contact_facebook_url(), ENT_QUOTES, "UTF-8") ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(servitech_contact_facebook_label(), ENT_QUOTES, "UTF-8") ?></a>
      </div>

      <div class="contact-item">
        <img src="<?= admin_url('/assets/images/FOOTER_EMAIL.png') ?>" alt="Email">
        <?php if ($adminFooterEmail !== ""): ?>
          <a href="mailto:<?= htmlspecialchars($adminFooterEmail, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($adminFooterEmail, ENT_QUOTES, "UTF-8") ?></a>
        <?php else: ?>
          <span>Contact email unavailable</span>
        <?php endif; ?>
      </div>

      <div class="contact-item">
        <img src="<?= admin_url('/assets/images/FOOTER_PHONE.png') ?>" alt="Phone">
        <span><?= htmlspecialchars(servitech_contact_phone(), ENT_QUOTES, "UTF-8") ?></span>
      </div>
    </div>

    <div class="footer-right">
      <a href="<?= admin_url('/index.php') ?>" class="footer-logo-link">
        <img src="<?= admin_url('/assets/images/LOGO_SERVITECH.png') ?>" alt="ServiTech Logo" class="footer-servitech-logo">
        <h1>ServiTech: JC Store</h1>
      </a>
    </div>
  </div>

  <div class="footer-legal-links" aria-label="Footer legal links">
    <a href="<?= admin_url('/privacy-policy.php') ?>">Privacy Policy</a>
    <span aria-hidden="true">|</span>
    <a href="<?= admin_url('/terms-of-service.php') ?>">Terms of Service</a>
    <span aria-hidden="true">|</span>
    <a href="<?= admin_url('/privacy-policy.php#privacy-settings') ?>" class="footer-privacy-settings-link" data-privacy-settings-open>Cookie Preferences</a>
  </div>

  <p class="footer-bottom">&copy; 2026 ServiTech: JC Store</p>
</footer>
<?php require_once __DIR__ . "/../../../components/cookie_consent.php"; ?>
<style>
  .queue-cancellation-overlay {
    align-items: center;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    inset: 0;
    justify-content: center;
    padding: 20px;
    position: fixed;
    z-index: 12000;
  }
  .queue-cancellation-overlay[hidden] { display: none; }
  .queue-cancellation-dialog {
    background: #fff;
    border: 1px solid #e1e7f0;
    border-radius: 12px;
    box-shadow: 0 26px 70px rgba(10, 24, 44, 0.28);
    max-height: calc(100vh - 40px);
    overflow: hidden;
    position: relative;
    width: 100%;
  }
  #queueCancellationOverlay .queue-cancellation-dialog { max-width: 460px; }
  #queueCancellationReasonOverlay .queue-cancellation-dialog { max-width: 560px; }
  .queue-cancellation-head {
    align-items: flex-start;
    border-bottom: 1px solid #eef2f7;
    display: flex;
    gap: 20px;
    justify-content: space-between;
    padding: 22px 24px 16px;
  }
  .queue-cancellation-head p {
    color: #7c1d1d;
    font-size: 14px;
    font-style: italic;
    font-weight: 700;
    margin: 0;
  }
  .queue-cancellation-head h3 {
    color: #101f33;
    font-size: 22px;
    font-weight: 600;
    line-height: 1.3;
    margin: 4px 0 0;
  }
  .queue-cancellation-close {
    background: transparent;
    border: 0;
    border-radius: 6px;
    color: #5d0f12;
    cursor: pointer;
    font-size: 28px;
    line-height: 1;
    padding: 0 3px 3px;
    transition: background 0.2s ease, color 0.2s ease;
  }
  .queue-cancellation-close:hover,
  .queue-cancellation-close:focus-visible {
    background: #fee2e2;
    color: #7c1d1d;
  }
  .queue-cancellation-body {
    max-height: calc(100vh - 118px);
    overflow-y: auto;
    padding: 20px 24px 24px;
  }
  .queue-cancellation-body > p {
    color: #475569;
    font-size: 15px;
    line-height: 1.6;
    margin: 0 0 16px;
  }
  .queue-cancellation-dialog textarea {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    box-sizing: border-box;
    color: #1f2d3d;
    font: inherit;
    font-size: 15px;
    line-height: 1.5;
    min-height: 120px;
    padding: 12px 14px;
    resize: vertical;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    width: 100%;
  }
  .queue-cancellation-dialog textarea:focus {
    border-color: #8fb0da;
    box-shadow: 0 0 0 3px rgba(33, 79, 145, 0.12);
    outline: none;
  }
  .queue-cancellation-dialog textarea::placeholder { color: #94a3b8; }
  .queue-cancellation-error {
    background: #fee2e2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    color: #991b1b;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.45;
    margin: 14px 0 0;
    padding: 10px 12px;
  }
  .queue-cancellation-error:empty { display: none; }
  .queue-cancellation-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 20px;
  }
  .queue-cancellation-btn {
    border: 1px solid transparent;
    border-radius: 10px;
    cursor: pointer;
    font: inherit;
    font-size: 15px;
    font-weight: 800;
    min-width: 112px;
    padding: 11px 18px;
    transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
  }
  .queue-cancellation-btn:hover,
  .queue-cancellation-btn:focus-visible {
    transform: translateY(-1px);
  }
  .queue-cancellation-btn--secondary {
    background: #e8eef7;
    border-color: #e8eef7;
    color: #243b59;
  }
  .queue-cancellation-btn--secondary:hover,
  .queue-cancellation-btn--secondary:focus-visible {
    background: #dce6f2;
    border-color: #cddaea;
  }
  .queue-cancellation-btn--primary {
    background: #f5a623;
    border-color: #f5a623;
    color: #111827;
  }
  .queue-cancellation-btn--primary:hover,
  .queue-cancellation-btn--primary:focus-visible {
    background: #e49718;
    border-color: #d48a11;
    box-shadow: 0 5px 12px rgba(245, 166, 35, 0.2);
  }
  @media (max-width: 520px) {
    .queue-cancellation-overlay { padding: 16px; }
    .queue-cancellation-head { padding: 18px 18px 12px; }
    .queue-cancellation-head h3 { font-size: 18px; }
    .queue-cancellation-head p { font-size: 13px; }
    .queue-cancellation-body {
      max-height: calc(100vh - 98px);
      padding: 16px 18px 18px;
    }
    .queue-cancellation-body > p,
    .queue-cancellation-dialog textarea {
      font-size: 14px;
    }
    .queue-cancellation-actions {
      display: grid;
      grid-template-columns: 1fr;
      gap: 10px;
    }
    .queue-cancellation-btn {
      min-width: 0;
      width: 100%;
    }
  }
</style>
<script src="<?= admin_url('/pages/admin/modal_stack.js?v=20260601-modal-stack') ?>"></script>
<script src="<?= admin_url('/pages/admin/queue_state_machine.js?v=20260601-modal-stack') ?>"></script>
