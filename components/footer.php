<?php
require_once __DIR__ . "/../config/contact.php";
$footerEmail = servitech_contact_email();
?>
<footer class="footer">
  <div class="footer-container">
    <div class="footer-left">
      <h3>Contact Us:</h3>

      <div class="contact-item">
        <img src="/assets/images/FOOTER_FB.png" alt="Facebook">
        <a href="<?= htmlspecialchars(servitech_contact_facebook_url(), ENT_QUOTES, "UTF-8") ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(servitech_contact_facebook_label(), ENT_QUOTES, "UTF-8") ?></a>
      </div>

      <div class="contact-item">
        <img src="/assets/images/FOOTER_EMAIL.png" alt="Email">
        <?php if ($footerEmail !== ""): ?>
          <a href="mailto:<?= htmlspecialchars($footerEmail, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($footerEmail, ENT_QUOTES, "UTF-8") ?></a>
        <?php else: ?>
          <span>Contact email unavailable</span>
        <?php endif; ?>
      </div>

      <div class="contact-item">
        <img src="/assets/images/FOOTER_PHONE.png" alt="Phone">
        <span><?= htmlspecialchars(servitech_contact_phone(), ENT_QUOTES, "UTF-8") ?></span>
      </div>
    </div>

    <div class="footer-right">
      <a href="/index.php" class="footer-logo-link">
        <img src="/assets/images/LOGO_SERVITECH.png" alt="ServiTech Logo" class="footer-servitech-logo">
        <h1>ServiTech: JC Store</h1>
      </a>
    </div>
  </div>

  <div class="footer-legal-links" aria-label="Footer legal links">
    <a href="/privacy-policy.php">Privacy Policy</a>
    <span aria-hidden="true">|</span>
    <a href="/terms-of-service.php">Terms of Service</a>
    <span aria-hidden="true">|</span>
    <a href="<?= htmlspecialchars(servitech_url('/privacy-policy.php#privacy-settings'), ENT_QUOTES, 'UTF-8') ?>" class="footer-privacy-settings-link" data-privacy-settings-open>Cookie Preferences</a>
  </div>

  <p class="footer-bottom">&copy; 2026 ServiTech: JC Store</p>
</footer>
<?php require_once __DIR__ . "/cookie_consent.php"; ?>
<link rel="stylesheet" href="/assets/css/customer-toast.css?v=20260621-modal-stack-toast">
<script src="/assets/js/customer_toast.js?v=20260602-global-toast"></script>
