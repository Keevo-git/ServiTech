<?php
if (!function_exists("admin_url")) {
    require_once __DIR__ . "/url.php";
}
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
        <a href="mailto:theservitech.store@gmail.com">theservitech.store@gmail.com</a>
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
