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
