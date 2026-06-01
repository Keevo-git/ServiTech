<?php
if (!function_exists("admin_url")) {
    require_once __DIR__ . "/url.php";
}
require_once __DIR__ . "/../../../config/mail.php";
$adminFooterEmail = servitech_smtp_public_from_email();
?>
<style>
  .admin-shared-footer {
    background: linear-gradient(120deg, #112b4f, #1a3f73 52%, #265792) !important;
    color: #ffffff !important;
    box-shadow: 0 -10px 24px rgba(17, 43, 79, 0.16);
  }

  .admin-shared-footer .footer-logo-link,
  .admin-shared-footer .footer-logo-link h1,
  .admin-shared-footer .contact-item a,
  .admin-shared-footer .contact-item span,
  .admin-shared-footer .footer-bottom,
  .admin-shared-footer .footer-left h3 {
    color: #ffffff !important;
  }

  .admin-shared-footer .footer-bottom {
    border-top: 1px solid rgba(255, 255, 255, 0.18) !important;
  }
</style>
<footer class="footer admin-shared-footer">
  <div class="footer-container">
    <div class="footer-left">
      <h3>Contact Us:</h3>

      <div class="contact-item">
        <img src="<?= admin_url('/assets/images/FOOTER_FB.png') ?>" alt="Facebook">
        <a href="https://www.facebook.com/JCstorebagbaguin" target="_blank" rel="noopener noreferrer">JC Store</a>
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
        <span>+63 912 393 4321</span>
      </div>
    </div>

    <div class="footer-right">
      <a href="<?= admin_url('/index.php') ?>" class="footer-logo-link">
        <img src="<?= admin_url('/assets/images/LOGO_SERVITECH.png') ?>" alt="ServiTech Logo" class="footer-servitech-logo">
        <h1>ServiTech: JC Store</h1>
      </a>
    </div>
  </div>

  <p class="footer-bottom">&copy; 2026 ServiTech: JC Store</p>
</footer>
<style>
  #queueCancellationOverlay {
    align-items: center;
    background: rgba(0, 0, 0, 0.52);
    display: flex;
    inset: 0;
    justify-content: center;
    padding: 18px;
    position: fixed;
    z-index: 10000;
  }
  #queueCancellationOverlay[hidden] { display: none; }
  .queue-cancellation-dialog {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 22px 70px rgba(0, 0, 0, 0.28);
    max-width: 520px;
    padding: 20px;
    width: 100%;
  }
  .queue-cancellation-dialog h3 { margin-top: 0; }
  .queue-cancellation-dialog textarea {
    box-sizing: border-box;
    min-height: 120px;
    padding: 10px;
    resize: vertical;
    width: 100%;
  }
  .queue-cancellation-error { color: #991b1b; min-height: 1.2em; }
  .queue-cancellation-actions { display: flex; gap: 10px; justify-content: flex-end; }
  .queue-cancellation-actions button { cursor: pointer; padding: 9px 14px; }
  .queue-cancellation-submit { background: #991b1b; border: 1px solid #991b1b; color: #fff; }
</style>
<script src="<?= admin_url('/pages/admin/queue_state_machine.js?v=20260601-status-machine-v2') ?>"></script>
