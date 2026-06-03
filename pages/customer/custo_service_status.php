<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Service Status</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260526status-badges">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260526status-badges">
  <style>
    body.customer-layout.customer-page--status {
      background:
        radial-gradient(900px 300px at 50% 8%, rgba(255, 178, 80, 0.14), transparent 70%),
        linear-gradient(180deg, #fffaf3 0%, #fff4e6 52%, #ffe6c2 100%) !important;
      background-color: #fff4e4 !important;
    }

    body.customer-layout.customer-page--status .status-page {
      padding-inline: clamp(16px, 4vw, 32px);
      background: transparent !important;
    }

    body.customer-layout.customer-page--status .status-shell {
      margin-top: 0;
    }

    body.customer-layout.customer-page--status .status-page-header {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      align-items: center;
      gap: clamp(10px, 2vw, 14px);
      padding: clamp(12px, 2.4vw, 16px) clamp(16px, 3vw, 22px);
      min-height: 72px;
    }

    body.customer-layout.customer-page--status .status-page-back {
      width: clamp(42px, 5vw, 46px);
      height: clamp(42px, 5vw, 46px);
      min-height: 44px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      justify-self: start;
      align-self: center;
      padding: 0;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.42);
      background: rgba(255, 255, 255, 0.16);
      text-decoration: none;
      cursor: pointer;
      overflow: hidden;
      box-sizing: border-box;
      flex-shrink: 0;
      transition: background-color 0.2s ease, opacity 0.2s ease, transform 0.2s ease;
    }

    body.customer-layout.customer-page--status .status-page-back:hover {
      background: rgba(255, 255, 255, 0.28);
      opacity: 0.96;
    }

    body.customer-layout.customer-page--status .status-page-back:active {
      background: rgba(255, 255, 255, 0.34);
      transform: scale(0.98);
    }

    body.customer-layout.customer-page--status .status-page-back:focus-visible {
      outline: 2px solid rgba(255, 255, 255, 0.92);
      outline-offset: 2px;
    }

    body.customer-layout.customer-page--status .status-page-back img {
      width: clamp(20px, 3vw, 24px);
      max-width: 100%;
      height: auto;
      display: block;
      object-fit: contain;
      pointer-events: none;
    }

    body.customer-layout.customer-page--status .status-page-header strong {
      min-width: 0;
      align-self: center;
      font-size: clamp(20px, 2.6vw, 24px);
      line-height: 1.18;
      letter-spacing: 0;
    }

    body.customer-layout.customer-page--status .status-panel {
      padding: clamp(18px, 4vw, 30px);
    }

    body.customer-layout.customer-page--status #detailModal {
      z-index: 5000 !important;
      align-items: center !important;
      background: rgba(32, 18, 15, 0.48) !important;
      justify-content: center !important;
      padding: clamp(14px, 3vw, 28px) !important;
      backdrop-filter: blur(4px);
    }

    body.customer-layout.customer-page--status #detailModal.is-open {
      display: flex;
    }

    body.customer-layout.customer-page--status .status-modal {
      background: linear-gradient(180deg, #fffdf9 0%, #fff8ef 100%) !important;
      border: 1px solid rgba(95, 14, 15, 0.14) !important;
      border-radius: 22px !important;
      box-shadow: 0 28px 70px rgba(74, 5, 5, 0.22) !important;
      color: #24120f;
      display: flex;
      flex-direction: column;
      margin: auto !important;
      max-height: 85vh !important;
      max-width: 760px !important;
      overflow: hidden;
      padding: 0 !important;
      position: relative;
      width: min(100%, 760px) !important;
    }

    body.customer-layout.customer-page--status .status-modal__header {
      align-items: start;
      display: grid;
      gap: 0.4rem;
      grid-template-columns: minmax(0, 1fr) auto;
      padding: clamp(18px, 3vw, 26px) clamp(88px, 9vw, 96px) clamp(14px, 2vw, 18px) clamp(18px, 3vw, 26px);
      border-bottom: 1px solid rgba(95, 14, 15, 0.1);
      background: rgba(255, 253, 249, 0.92);
      flex: 0 0 auto;
    }

    body.customer-layout.customer-page--status .status-modal__eyebrow {
      color: #8a5f34;
      font-size: 0.74rem;
      font-weight: 800;
      letter-spacing: 0.09em;
      margin: 0;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--status .status-modal .modal-title {
      color: #4a0505 !important;
      font-size: clamp(1.35rem, 3vw, 1.85rem) !important;
      line-height: 1.15;
      margin: 0 !important;
      overflow-wrap: anywhere;
      padding-right: 0;
    }

    body.customer-layout.customer-page--status .status-modal .modal-close {
      align-items: center !important;
      appearance: none;
      aspect-ratio: 1 / 1;
      background: #fff8f5 !important;
      border: 1px solid #ead2c5 !important;
      border-radius: 50% !important;
      box-shadow: 0 10px 22px rgba(74, 5, 5, 0.08);
      box-sizing: border-box;
      color: #8b1e1e !important;
      cursor: pointer;
      display: flex !important;
      font-family: Arial, Helvetica, sans-serif;
      flex: 0 0 48px !important;
      flex-grow: 0 !important;
      flex-shrink: 0 !important;
      font-size: 0 !important;
      font-weight: 700;
      height: 48px !important;
      justify-content: center !important;
      line-height: 1 !important;
      max-height: 48px !important;
      max-width: 48px !important;
      min-height: 48px !important;
      min-width: 48px !important;
      padding: 0 !important;
      position: absolute !important;
      right: clamp(16px, 3vw, 24px) !important;
      top: clamp(16px, 3vw, 24px) !important;
      text-align: center;
      transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
      width: 48px !important;
    }

    body.customer-layout.customer-page--status .status-modal .modal-close span {
      display: none;
    }

    body.customer-layout.customer-page--status .status-modal .modal-close::before,
    body.customer-layout.customer-page--status .status-modal .modal-close::after {
      background: currentColor;
      border-radius: 999px;
      content: "";
      height: 3px;
      left: 50%;
      pointer-events: none;
      position: absolute;
      top: 50%;
      transform-origin: center;
      width: 17px;
    }

    body.customer-layout.customer-page--status .status-modal .modal-close::before {
      transform: translate(-50%, -50%) rotate(45deg);
    }

    body.customer-layout.customer-page--status .status-modal .modal-close::after {
      transform: translate(-50%, -50%) rotate(-45deg);
    }

    body.customer-layout.customer-page--status .status-modal .modal-close:hover,
    body.customer-layout.customer-page--status .status-modal .modal-close:focus-visible {
      background: #8b1e1e !important;
      border-color: #8b1e1e !important;
      box-shadow: 0 16px 30px rgba(139, 30, 30, 0.22);
      color: #ffffff !important;
      outline: none;
      transform: translateY(-1px) scale(1.03);
    }

    body.customer-layout.customer-page--status .status-modal__grid {
      display: grid;
      gap: 0.85rem;
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
      padding: clamp(16px, 3vw, 24px);
      scrollbar-gutter: stable;
    }

    body.customer-layout.customer-page--status .status-modal__section {
      background: rgba(255, 255, 255, 0.82);
      border: 1px solid rgba(95, 14, 15, 0.11);
      border-radius: 16px;
      display: grid;
      gap: 0.75rem;
      padding: clamp(14px, 2.2vw, 18px);
    }

    body.customer-layout.customer-page--status .status-modal__section[hidden],
    body.customer-layout.customer-page--status .status-detail-row[hidden],
    body.customer-layout.customer-page--status #modalNotesWrap[hidden] {
      display: none !important;
    }

    body.customer-layout.customer-page--status .status-modal__section-title {
      color: #5f0e0f;
      font-size: 0.78rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      margin: 0;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--status #modalPaymentDetails,
    body.customer-layout.customer-page--status #modalExtra {
      display: grid;
      gap: 0.75rem;
      min-width: 0;
    }

    body.customer-layout.customer-page--status .status-detail-row,
    body.customer-layout.customer-page--status #modalExtra .status-detail-row {
      align-items: start;
      display: grid;
      gap: 0.65rem;
      grid-template-columns: minmax(120px, 0.34fr) minmax(0, 1fr);
      margin: 0 !important;
      padding: 0 !important;
    }

    body.customer-layout.customer-page--status .status-detail-label {
      color: #7c625b !important;
      font-size: 0.76rem !important;
      font-weight: 800 !important;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--status .status-detail-value {
      color: #24120f !important;
      font-size: 0.95rem;
      font-weight: 650;
      min-width: 0;
      overflow-wrap: anywhere;
    }

    body.customer-layout.customer-page--status .status-notes {
      display: grid;
      gap: 0.45rem;
      margin: 0 !important;
    }

    body.customer-layout.customer-page--status .status-modal textarea {
      background: #fffaf4 !important;
      border: 1px solid rgba(95, 14, 15, 0.12) !important;
      border-radius: 14px !important;
      color: #24120f !important;
      min-height: 82px !important;
      padding: 0.8rem 0.9rem !important;
      resize: vertical;
      width: 100%;
    }

    body.customer-layout.customer-page--status .file-list {
      display: grid !important;
      gap: 0.55rem !important;
      min-width: 0;
    }

    body.customer-layout.customer-page--status .file-entry {
      align-items: center;
      background: #fff7ed !important;
      border: 1px solid rgba(240, 138, 0, 0.28) !important;
      border-radius: 12px !important;
      color: #5f0e0f !important;
      display: flex !important;
      font-weight: 750;
      justify-content: space-between;
      min-width: 0;
      overflow-wrap: anywhere;
      padding: 0.7rem 0.85rem !important;
      text-decoration: none !important;
      transition: background-color 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
    }

    body.customer-layout.customer-page--status a.file-entry::after {
      content: "Open";
      background: #f08a00;
      border-radius: 999px;
      color: #fff;
      flex: 0 0 auto;
      font-size: 0.72rem;
      margin-left: 0.8rem;
      padding: 0.28rem 0.62rem;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--status a.file-entry:hover {
      background: #fff1dd !important;
      border-color: rgba(240, 138, 0, 0.46) !important;
      transform: translateY(-1px);
    }

    body.customer-layout.customer-page--status .payment-note {
      color: #775d58;
      font-size: 0.88rem;
      margin: 0;
    }

    body.customer-layout.customer-page--status .status-payment-price {
      align-items: center;
      background: linear-gradient(90deg, rgba(95, 14, 15, 0.94) 0%, rgba(139, 30, 30, 0.9) 100%);
      border: 1px solid rgba(95, 14, 15, 0.16);
      border-radius: 14px;
      box-shadow: 0 10px 22px rgba(74, 5, 5, 0.12);
      color: #ffffff;
      display: flex;
      gap: 0.75rem;
      justify-content: space-between;
      min-height: 50px;
      padding: 0.78rem 0.95rem;
    }

    body.customer-layout.customer-page--status .status-payment-price .status-detail-label {
      color: rgba(255, 246, 235, 0.82) !important;
      font-size: 0.72rem !important;
    }

    body.customer-layout.customer-page--status .status-payment-price .status-detail-value {
      color: #ffffff !important;
      font-size: 1.05rem;
      font-weight: 850;
      text-align: right;
    }

    body.customer-layout.customer-page--status .status-payment-qr {
      align-items: center;
      background: #fffaf4;
      border: 1px solid rgba(95, 14, 15, 0.12);
      border-radius: 14px;
      display: none;
      gap: 0.8rem;
      grid-template-columns: minmax(120px, 180px) minmax(0, 1fr);
      padding: 0.8rem;
    }

    body.customer-layout.customer-page--status .status-payment-qr.is-visible {
      display: grid;
    }

    body.customer-layout.customer-page--status .status-payment-qr img {
      display: block;
      height: auto;
      max-width: 180px;
      object-fit: contain;
      width: 100%;
    }

    body.customer-layout.customer-page--status .status-modal__footer {
      display: flex;
      justify-content: center;
      flex: 0 0 auto;
      padding: clamp(14px, 2.4vw, 18px) clamp(18px, 3vw, 26px);
      border-top: 1px solid rgba(95, 14, 15, 0.1);
      background: rgba(255, 253, 249, 0.94);
    }

    body.customer-layout.customer-page--status .status-current-card {
      align-items: center;
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      background: #fffaf4;
      border: 1px solid rgba(95, 14, 15, 0.1);
      border-radius: 14px;
      padding: 0.9rem 1rem;
    }

    body.customer-layout.customer-page--status .status-current-card__label {
      color: #7c625b;
      font-size: 0.78rem;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--status .file-entry--unavailable::after {
      content: none;
    }

    body.customer-layout.customer-page--status .file-entry--unavailable {
      color: #8a5f34 !important;
      justify-content: flex-start;
      opacity: 0.78;
    }

    body.customer-layout.customer-page--status .status-modal .modal-back {
      background: linear-gradient(180deg, #fbbf24 0%, #f08a00 100%) !important;
      border: 0 !important;
      border-radius: 12px !important;
      box-shadow: 0 10px 20px rgba(95, 14, 15, 0.16);
      color: #24120f !important;
      cursor: pointer;
      font-weight: 800;
      min-height: 46px;
      min-width: min(100%, 220px);
      padding: 0.8rem 1.35rem !important;
      transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
    }

    body.customer-layout.customer-page--status .status-modal .modal-back:hover {
      box-shadow: 0 12px 24px rgba(95, 14, 15, 0.2);
      filter: saturate(1.05);
      transform: translateY(-1px);
    }

    body.customer-layout.customer-page--status .status-modal .modal-back:active {
      transform: translateY(0);
    }

    body.customer-layout.customer-page--status .queue-list {
      grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
      gap: clamp(14px, 2vw, 20px);
    }

    body.customer-layout.customer-page--status .queue-card {
      justify-content: center;
      align-items: center;
      gap: 16px;
      min-height: 156px;
      padding: clamp(20px, 3vw, 26px);
      border-radius: 18px;
      border: 1px solid rgba(74, 5, 5, 0.10);
      background: linear-gradient(180deg, #ffffff 0%, #fffaf4 100%);
      box-shadow: 0 14px 28px rgba(74, 5, 5, 0.09);
      cursor: pointer;
      text-align: center;
    }

    body.customer-layout.customer-page--status .queue-card:focus-visible {
      outline: 2px solid #4a0505;
      outline-offset: 3px;
    }

    body.customer-layout.customer-page--status .queue-card__head {
      width: 100%;
      justify-content: center;
      align-items: center;
      gap: 10px 14px;
    }

    body.customer-layout.customer-page--status .queue-card__code {
      font-size: clamp(18px, 2.5vw, 22px);
      line-height: 1.2;
      letter-spacing: 0;
    }

    body.customer-layout.customer-page--status .status-badge,
    body.customer-layout.customer-page--status .queue-card__badge {
      flex: 0 0 auto;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 6px 12px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      line-height: 1.2;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      white-space: nowrap;
      text-align: center;
    }

    body.customer-layout.customer-page--status #modalStatus.status-pending,
    body.customer-layout.customer-page--status .status-pending {
      background: #fef3c7;
      color: #b45309;
    }

    body.customer-layout.customer-page--status #modalStatus.status-ongoing,
    body.customer-layout.customer-page--status .status-ongoing {
      background: #dbeafe;
      color: #1d4ed8;
    }

    body.customer-layout.customer-page--status #modalStatus.status-pickup,
    body.customer-layout.customer-page--status .status-pickup {
      background: #ede9fe;
      color: #7c3aed;
    }

    body.customer-layout.customer-page--status #modalStatus.status-done,
    body.customer-layout.customer-page--status .status-done {
      background: #dcfce7;
      color: #15803d;
    }

    body.customer-layout.customer-page--status #modalStatus.status-cancelled,
    body.customer-layout.customer-page--status .status-cancelled {
      background: #fee2e2;
      color: #b91c1c;
    }

    body.customer-layout.customer-page--status .queue-card__divider {
      width: min(100%, 260px);
      margin: 0;
      opacity: 0.85;
    }

    body.customer-layout.customer-page--status .queue-card__meta {
      display: grid;
      gap: 5px;
      font-size: 14px;
      line-height: 1.45;
      justify-items: center;
      margin: 0;
      max-width: 100%;
    }

    body.customer-layout.customer-page--status .queue-card__meta strong {
      color: #24120f;
      font-size: clamp(16px, 2vw, 18px);
      line-height: 1.25;
      overflow-wrap: anywhere;
    }

    body.customer-layout.customer-page--status .queue-card__meta small {
      margin-top: 0;
      color: #5f6b7a;
      font-size: 13px;
      line-height: 1.35;
      overflow-wrap: anywhere;
    }

    @media (max-width: 640px) {
      body.customer-layout.customer-page--status .status-page {
        padding-inline: 14px;
      }

      body.customer-layout.customer-page--status .status-page-header {
        gap: 12px;
        padding: 14px 16px;
      }

      body.customer-layout.customer-page--status .queue-card {
        border-radius: 16px;
        min-height: 0;
        padding: 18px 16px;
      }

      body.customer-layout.customer-page--status .queue-card__head {
        align-items: center;
        flex-direction: column;
        gap: 8px;
      }

      body.customer-layout.customer-page--status .queue-card__badge {
        min-height: 28px;
        display: inline-flex;
        align-items: center;
      }

      body.customer-layout.customer-page--status .status-modal {
        border-radius: 18px !important;
        max-height: calc(100vh - 28px) !important;
      }

      body.customer-layout.customer-page--status .status-modal__header {
        padding-right: 78px;
      }

      body.customer-layout.customer-page--status .status-detail-row,
      body.customer-layout.customer-page--status #modalExtra .status-detail-row {
        grid-template-columns: 1fr;
        gap: 0.25rem;
      }

      body.customer-layout.customer-page--status .status-payment-qr {
        grid-template-columns: 1fr;
        justify-items: center;
        text-align: center;
      }

      body.customer-layout.customer-page--status .status-payment-price {
        align-items: flex-start;
        flex-direction: column;
        gap: 0.25rem;
      }

      body.customer-layout.customer-page--status .status-payment-price .status-detail-value {
        text-align: left;
      }

      body.customer-layout.customer-page--status .status-modal .modal-back {
        width: 100%;
      }

      body.customer-layout.customer-page--status .status-current-card {
        align-items: flex-start;
        flex-direction: column;
      }
    }

    @media (min-width: 1025px) {
      body.customer-layout.customer-page--status .status-page-back {
        width: 46px;
        height: 46px;
        min-height: 46px;
        box-shadow: 0 7px 14px rgba(0, 0, 0, 0.14);
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--status">

<?php include __DIR__ . "/../../components/header.php"; ?>

<main class="form-page status-page">
  <section class="status-shell">
    <div class="status-page-header">
      <a href="/pages/customer/customer_dash.php" class="status-page-back" aria-label="Back to dashboard">
        <img src="/assets/images/arrow.png" alt="" aria-hidden="true">
      </a>
      <strong>Service Status</strong>
    </div>

    <div class="status-panel">
      <h3 class="status-section-title">YOUR QUEUES</h3>
      <div id="queueList" class="queue-list"></div>
    </div>
  </section>
</main>

<?php include __DIR__ . "/../../components/footer.php"; ?>

    <div id="detailModal" class="modal-overlay" aria-hidden="true">
      <div class="modal status-modal" role="dialog" aria-modal="true" aria-labelledby="detailModalTitle" tabindex="-1">
        <button id="closeDetail" class="modal-close" type="button" aria-label="Close details"><span aria-hidden="true">&times;</span></button>

        <div class="status-modal__header">
          <div>
            <p class="status-modal__eyebrow">Queue Details</p>
            <h3 id="detailModalTitle" class="modal-title">
              <span id="modalQueue"></span>
            </h3>
          </div>
        </div>

        <div class="status-modal__grid modal-body">
          <section class="status-modal__section" aria-labelledby="serviceDetailsTitle">
            <h4 id="serviceDetailsTitle" class="status-modal__section-title">Service Details</h4>
            <div class="status-detail-row">
              <span class="status-detail-label">Category</span>
              <span id="modalType" class="status-detail-value"></span>
            </div>
            <div class="status-detail-row">
              <span class="status-detail-label">Service</span>
              <span id="modalService" class="status-detail-value"></span>
            </div>
            <div id="modalExtra"></div>
            <div class="status-detail-row modal-price" hidden>
              <span class="status-detail-label">Price</span>
              <span id="modalPrice" class="status-detail-value">To be assessed</span>
            </div>
            <div id="modalNotesWrap" class="status-notes">
              <label class="status-detail-label" for="modalNotes">Notes</label>
              <textarea id="modalNotes" readonly></textarea>
            </div>
          </section>

          <section id="attachedFilesSection" class="status-modal__section" aria-labelledby="attachedFilesTitle">
            <h4 id="attachedFilesTitle" class="status-modal__section-title">Attached Files</h4>
            <div class="status-detail-row status-detail-row--files">
              <span id="modalFileLabel" class="status-detail-label">Attached File</span>
              <div id="modalFile" class="status-detail-value file-list"></div>
            </div>
          </section>

          <section class="status-modal__section" aria-labelledby="paymentDetailsTitle">
            <h4 id="paymentDetailsTitle" class="status-modal__section-title">Payment Details</h4>
            <div id="modalPaymentDetails"></div>
            <div id="modalPaymentQr" class="status-payment-qr">
              <img src="/assets/images/gcash-qr.jpg" alt="JC Shop GCash QR code">
              <p class="payment-note">Use this QR for GCash payments, then submit your reference number.</p>
            </div>
          </section>

          <section class="status-modal__section" aria-labelledby="currentStatusTitle">
            <h4 id="currentStatusTitle" class="status-modal__section-title">Current Status</h4>
            <div class="status-current-card modal-status">
              <span class="status-current-card__label">Current Status</span>
              <span id="modalStatus"></span>
            </div>
          </section>
        </div>

        <div class="status-modal__footer">
          <button id="modalCloseBtn" class="modal-back" type="button">Close</button>
        </div>
      </div>
    </div>

<script>
(async function(){
  const listEl = document.getElementById("queueList");
  const detailModal = document.getElementById("detailModal");
  const statusModal = detailModal?.querySelector(".status-modal");
  const closeDetail = document.getElementById("closeDetail");
  const modalCloseBtn = document.getElementById("modalCloseBtn");

  let lastFocused = null;

  function servitechBasePath(){
    const pathname = window.location.pathname || "";
    if (pathname === "/ServiTech" || pathname.startsWith("/ServiTech/")) return "/ServiTech";
    return "";
  }

  function servitechUrl(path){
    const cleanPath = path.startsWith("/") ? path : `/${path}`;
    return `${servitechBasePath()}${cleanPath}`;
  }

  function esc(s){
    return (s ?? "").toString().replace(/[&<>"']/g, c => ({
      "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
    }[c]));
  }

  function toNumber(value){
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  }

  function toPeso(value){
    const n = toNumber(value);
    return `\u20B1${(n ?? 0).toFixed(2)}`;
  }

  function resolveFileHref(path){
    const raw = (path || "").toString().trim();
    if (!raw) return "";
    if (/^https?:\/\//i.test(raw)) return raw;
    if (raw.startsWith("/uploads/printing/")) return servitechUrl(raw);
    if (raw.startsWith("/uploads/print_orders/")) return servitechUrl(raw);
    if (raw.startsWith(servitechBasePath() + "/uploads/printing/")) return raw;
    if (raw.startsWith(servitechBasePath() + "/uploads/print_orders/")) return raw;
    return "";
  }

  function formatKnownLabel(value){
    const raw = (value || "").toString().trim();
    if (!raw) return "";

    return raw
      .replace(/print\s*order/ig, "Print Order")
      .replace(/printorder/ig, "Print Order")
      .replace(/document\s*printing/ig, "Document Printing")
      .replace(/rush\s*id/ig, "Rush ID");
  }

  function formatLabel(value){
    return formatKnownLabel(value)
      .toString()
      .trim()
      .replace(/[_-]+/g, " ")
      .replace(/([a-z])([A-Z])/g, "$1 $2")
      .toLowerCase()
      .replace(/(^|\s)\S/g, (match) => match.toUpperCase())
      .replace(/\bId\b/g, "ID");
  }

  function formatServiceLabel(value){
    const raw = (value || "").toString().trim();
    if (!raw) return "";

    const normalizedRaw = formatKnownLabel(raw);
    const compact = normalizedRaw.replace(/[^a-z0-9]/gi, "").toLowerCase();
    const knownLabels = {
      printorder: "Print Order",
      documentprinting: "Document Printing",
      rushid: "Rush ID",
      openlinesamsungiphone: "Openline Samsung & iPhone",
      bypassgoogleaccount: "Bypass Google Account",
      bypasspassword: "Bypass Password"
    };

    if (knownLabels[compact]) {
      return knownLabels[compact];
    }

    return normalizedRaw
      .replace(/[_-]+/g, " ")
      .replace(/([a-z])([A-Z])/g, "$1 $2")
      .replace(/\s+/g, " ")
      .trim()
      .replace(/(^|\s)\S/g, (match) => match.toUpperCase());
  }

  function badgeTone(status){
    const key = String(status || "PENDING")
      .trim()
      .toLowerCase()
      .replace(/[\s_]+/g, "-");
    if (key === "ongoing") return "ongoing";
    if (key === "for-pick-up" || key === "for-pickup" || key === "ready") return "pickup";
    if (key === "done") return "done";
    if (key === "cancelled" || key === "canceled") return "cancelled";
    return "pending";
  }

  function formatPaymentMethod(value){
    const key = String(value || "").trim().toLowerCase();
    if (key === "gcash") return "GCash";
    if (key === "cash") return "Cash / Pay at Store";
    return "Not specified";
  }

  function queueDetails(queueData){
    return queueData && typeof queueData.details === "object" && queueData.details
      ? queueData.details
      : {};
  }

  function compactKey(value){
    return String(value || "")
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "");
  }

  function categoryKey(queueData){
    return String(queueData?.category || "").trim().toLowerCase();
  }

  function serviceKey(queueData){
    const details = queueDetails(queueData);
    return compactKey(queueData?.service_label || details.service_label || "");
  }

  function orderTypeKey(queueData){
    const details = queueDetails(queueData);
    return String(queueData?.order_type || details.order_type || "").trim().toLowerCase();
  }

  function isDocumentPrinting(queueData){
    const service = serviceKey(queueData);
    return service === "documentprinting" || service === "onlineprintorder" || categoryKey(queueData) === "online_printorder";
  }

  function isOnlineDocumentPrinting(queueData){
    return isDocumentPrinting(queueData) && (categoryKey(queueData) === "online_printorder" || orderTypeKey(queueData) === "online");
  }

  function isWalkInDocumentPrinting(queueData){
    return isDocumentPrinting(queueData) && !isOnlineDocumentPrinting(queueData);
  }

  function supportsFileUpload(queueData){
    const service = serviceKey(queueData);
    return service === "rushid" || isDocumentPrinting(queueData);
  }

  function serviceDetailRows(queueData){
    const details = queueDetails(queueData);
    const service = serviceKey(queueData);
    const category = categoryKey(queueData);
    const rows = [];
    const add = (label, value) => {
      const cleanValue = value === null || value === undefined ? "" : String(value).trim();
      if (cleanValue !== "") rows.push([label, cleanValue]);
    };

    if (isDocumentPrinting(queueData)) {
      add("Order Type", isOnlineDocumentPrinting(queueData) ? "Online" : "Walk-In");
      add("Paper Size", queueData.paper_size ?? details.paper_size);
      add("Quantity/Copies", queueData.quantity ?? details.quantity);
      add("Color Option", queueData.color_option ?? details.color_option);
      add("Total Pages", queueData.total_pages ?? details.total_pages);
      add("Price Per Page", toNumber(queueData.price_per_page ?? details.price_per_page) !== null
        ? toPeso(queueData.price_per_page ?? details.price_per_page)
        : "");
      return rows;
    }

    if (service === "rushid") {
      add("Package", queueData.package_label ?? details.package_label);
      add("Quantity", queueData.quantity ?? details.quantity);
      return rows;
    }

    if (service === "xerox") {
      add("Paper Size", queueData.paper_size ?? details.paper_size);
      add("Quantity", queueData.quantity ?? details.quantity);
      return rows;
    }

    if (service === "laminating") {
      add("Lamination", queueData.lamination_type ?? details.lamination_type);
      add("Quantity", queueData.quantity ?? details.quantity);
      return rows;
    }

    if (category === "repair" || category === "installation") {
      add("Device", queueData.device_type ?? details.device_type);
      return rows;
    }

    add("Paper Size", queueData.paper_size ?? details.paper_size);
    add("Quantity", queueData.quantity ?? details.quantity);
    add("Color", queueData.color_option ?? details.color_option);
    add("Package", queueData.package_label ?? details.package_label);
    add("Lamination", queueData.lamination_type ?? details.lamination_type);
    add("Device", queueData.device_type ?? details.device_type);
    return rows;
  }

  function renderState(message, actionHtml){
    listEl.innerHTML = `
      <div class="status-empty-state">
        <p class="muted">${esc(message)}</p>
        ${actionHtml || ""}
      </div>
    `;
  }

  function buildCard(q){
    const div = document.createElement("div");
    const tone = badgeTone(q.status);
    div.className = "card queue-card";
    div.tabIndex = 0;
    div.setAttribute("role", "button");
    div.setAttribute("aria-label", `Open details for queue ${q.queue_code || ""}`);

    div.dataset.queue = q.queue_code || "";
    div.dataset.queueId = q.id || "";
    div.dataset.type = q.category || "";
    div.dataset.service = formatServiceLabel(q.service_label || "");
    div.dataset.paper = q.paper_size || "";
    div.dataset.qty = q.quantity || "";
    div.dataset.color = q.color_option || "";
    div.dataset.pkg = q.package_label || "";
    div.dataset.lam = q.lamination_type || "";
    div.dataset.device = q.device_type || "";
    div.dataset.notes = q.notes || "";
    div.dataset.file = q.file_name || "";
    div.dataset.status = q.status || "";
    div.dataset.paymentMethod = q.payment_method || q.details?.payment_method || "";
    div.dataset.referenceNumber = q.reference_number || q.details?.reference_number || "";
    div.queueData = q;

    div.innerHTML = `
      <div class="queue-card__head">
        <div class="queue-card__code">${esc(q.queue_code)}</div>
        <div class="status-badge queue-card__badge status-${tone} queue-card__badge--${tone}">${esc(q.status || "PENDING")}</div>
      </div>
      <hr class="queue-card__divider">
      <p class="queue-card__meta">
        <strong>${esc(formatServiceLabel(q.service_label || "Service"))}</strong>
        <small>${esc(formatLabel(q.category || ""))}</small>
      </p>
    `;

    return div;
  }

  function getInstallationPriceLabel(serviceLabel){
    const normalized = (serviceLabel || "").toString().trim().toLowerCase();
    if (!normalized) return "";

    const ranges = [
      ["reprogram service", [1000, 4000]],
      ["hang logo fix service", [1000, 3500]],
      ["boot loop fix service", [1000, 5000]],
      ["openline samsung & iphone", [3500, 6000]],
      ["bypass google account", [500, 2000]],
      ["bypass password", [1000, 3000]],
    ];

    const match = ranges.find(([label]) => normalized.includes(label));
    if (!match) return "";

    return `${toPeso(match[1][0])} - ${toPeso(match[1][1])}`;
  }

  function getQueuePriceLabel(queueData){
    const details = queueData && typeof queueData.details === "object" && queueData.details
      ? queueData.details
      : {};

    const trackedPrice = toNumber(queueData.price);
    if (queueData.price !== null && queueData.price !== undefined && trackedPrice !== null) {
      return toPeso(trackedPrice);
    }

    const directEstimate = toNumber(queueData.estimated_total ?? details.estimated_total);
    if (directEstimate !== null && directEstimate > 0) {
      return toPeso(directEstimate);
    }

    let totalPages = toNumber(queueData.total_pages ?? details.total_pages);
    const pricePerPage = toNumber(queueData.price_per_page ?? details.price_per_page);
    const quantity = Math.max(1, toNumber(queueData.quantity ?? details.quantity) ?? 1);
    const fileAnalysis = Array.isArray(queueData.file_analysis)
      ? queueData.file_analysis
      : (Array.isArray(details.file_analysis) ? details.file_analysis : []);

    if (totalPages === null && fileAnalysis.length) {
      totalPages = fileAnalysis.reduce((sum, file) => {
        const pages = toNumber(file.page_count ?? file.slide_count) ?? 0;
        return sum + pages;
      }, 0);
    }

    if (totalPages !== null && pricePerPage !== null && pricePerPage > 0) {
      return toPeso(totalPages * pricePerPage * quantity);
    }

    const serviceLabel = (queueData.service_label || details.service_label || "").toString();
    const serviceLower = serviceLabel.toLowerCase();
    const packageLabel = (queueData.package_label || details.package_label || "").toString();
    const paperSize = (queueData.paper_size || details.paper_size || "").toString();
    const laminationType = (queueData.lamination_type || details.lamination_type || "").toString().toLowerCase();
    const xeroxPriceMap = {
      "Long Bond (8.5 x 13)": 5,
      "Short Bond (8.5 x 11)": 3,
      "A4": 3,
      "A3": 5,
    };

    if (serviceLower.includes("xerox") && xeroxPriceMap[paperSize]) {
      return toPeso(xeroxPriceMap[paperSize] * quantity);
    }

    if (serviceLower.includes("laminating")) {
      const laminationPrice = laminationType === "thin" ? 20 : laminationType === "thick" ? 30 : null;
      if (laminationPrice !== null) {
        return toPeso(laminationPrice * quantity);
      }
    }

    if (serviceLower.includes("rush id") || packageLabel) {
      const match = packageLabel.match(/(?:\u20B1|PHP\s*)([0-9]+(?:\.[0-9]{1,2})?)/i);
      if (match) {
        return toPeso(Number(match[1]) * quantity);
      }
    }

    const installationRange = getInstallationPriceLabel(serviceLabel);
    if (installationRange) {
      return installationRange;
    }

    return "To be assessed";
  }

  function trapModalFocus(e){
    if (!statusModal || e.key !== "Tab") return;
    const focusables = statusModal.querySelectorAll('button, [href], textarea, input, select, [tabindex]:not([tabindex="-1"])');
    if (!focusables.length) return;

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
      return;
    }

    if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function closeDetailModal(){
    if (!detailModal) return;
    detailModal.classList.remove("is-open");
    detailModal.setAttribute("aria-hidden", "true");
    document.removeEventListener("keydown", onModalKeydown);
    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus({ preventScroll: true });
    }
  }

  function onModalKeydown(e){
    if (e.key === "Escape") {
      e.preventDefault();
      closeDetailModal();
      return;
    }
    trapModalFocus(e);
  }

  function renderAttachedFiles(queueData){
    const fileSection = document.getElementById("attachedFilesSection");
    const fileEl = document.getElementById("modalFile");
    const fileLabelEl = document.getElementById("modalFileLabel");
    if (!fileEl) return;

    if (!supportsFileUpload(queueData)) {
      if (fileSection) fileSection.hidden = true;
      fileEl.innerHTML = "";
      if (fileLabelEl) fileLabelEl.textContent = "Attached File";
      return;
    }

    if (fileSection) fileSection.hidden = false;

    const uploadedFiles = Array.isArray(queueData.uploaded_files) ? queueData.uploaded_files : [];
    const fileNames = Array.isArray(queueData.file_names)
      ? queueData.file_names
      : (Array.isArray(queueData.details?.file_names) ? queueData.details.file_names : []);
    const fileAnalysis = Array.isArray(queueData.file_analysis)
      ? queueData.file_analysis
      : (Array.isArray(queueData.details?.file_analysis) ? queueData.details.file_analysis : []);
    const derivedNames = fileAnalysis
      .map((file) => (file && file.file_name ? String(file.file_name).trim() : ""))
      .filter(Boolean);

    fileEl.innerHTML = "";

    function appendEntry(label, href){
      if (!label) return;

      if (href) {
        const link = document.createElement("a");
        link.href = href;
        link.target = "_blank";
        link.rel = "noopener noreferrer";
        link.textContent = label;
        link.className = "file-entry";
        fileEl.appendChild(link);
        return;
      }

      const textNode = document.createElement("span");
      textNode.textContent = label ? `${label} - File unavailable` : "File unavailable";
      textNode.className = "file-entry file-entry--unavailable";
      fileEl.appendChild(textNode);
    }

    if (uploadedFiles.length) {
      uploadedFiles.forEach((file, index) => {
        const href = file.available === false ? "" : (file.href || file.download_url || resolveFileHref(file.saved_path || file.file_path || ""));
        const label = file.original_name || fileNames[index] || derivedNames[index] || file.saved_path || `File ${index + 1}`;
        appendEntry(label, href);
      });
      if (fileLabelEl) fileLabelEl.textContent = uploadedFiles.length > 1 ? "Attached Files" : "Attached File";
      return;
    }

    if (fileNames.length) {
      fileNames.forEach((name) => appendEntry(name, resolveFileHref(name)));
      if (fileLabelEl) fileLabelEl.textContent = fileNames.length > 1 ? "Attached Files" : "Attached File";
      return;
    }

    if (derivedNames.length) {
      derivedNames.forEach((name) => appendEntry(name, resolveFileHref(name)));
      if (fileLabelEl) fileLabelEl.textContent = derivedNames.length > 1 ? "Attached Files" : "Attached File";
      return;
    }

    const fallbackHref = queueData.file_href || resolveFileHref(queueData.saved_path || queueData.file_path || queueData.file_name || "");
    if (queueData.file_name) {
      appendEntry(queueData.file_name, fallbackHref || "");
      if (fileLabelEl) fileLabelEl.textContent = "Attached File";
      return;
    }

    if (fileLabelEl) fileLabelEl.textContent = "Attached File";
    fileEl.innerHTML = '<span class="file-entry file-entry--unavailable">File unavailable</span>';
  }

  function buildDetailRow(label, value){
    if (value === null || value === undefined || String(value).trim() === "") return "";
    return `
      <div class="status-detail-row">
        <span class="status-detail-label">${esc(label)}</span>
        <span class="status-detail-value">${esc(value)}</span>
      </div>
    `;
  }

  function renderPaymentDetails(queueData){
    const details = queueDetails(queueData);
    const paymentEl = document.getElementById("modalPaymentDetails");
    const paymentQr = document.getElementById("modalPaymentQr");
    const method = String(queueData.payment_method || details.payment_method || "").trim().toLowerCase();
    const reference = String(queueData.reference_number || details.reference_number || "").trim();
    const baseRows = `
      <div class="status-payment-price">
        <span class="status-detail-label">Price</span>
        <span class="status-detail-value">${esc(getQueuePriceLabel(queueData))}</span>
      </div>
      <div class="status-detail-row">
        <span class="status-detail-label">Paid Amount</span>
        <span class="status-detail-value">${esc(toPeso(queueData.paid_amount))}</span>
      </div>
      <div class="status-detail-row">
        <span class="status-detail-label">Paid Pending</span>
        <span class="status-detail-value">${esc(toPeso(queueData.paid_pending))}</span>
      </div>
    `;

    if (!paymentEl) return;

    if (isOnlineDocumentPrinting(queueData)) {
      paymentEl.innerHTML = `
        ${baseRows}
        <div class="status-detail-row">
          <span class="status-detail-label">Payment Method</span>
          <span class="status-detail-value">${esc(formatPaymentMethod(method))}</span>
        </div>
        ${method === "gcash" || reference ? `
          <div class="status-detail-row">
            <span class="status-detail-label">Reference Number</span>
            <span class="status-detail-value">${esc(reference || "-")}</span>
          </div>
        ` : ""}
      `;
      if (paymentQr) paymentQr.classList.toggle("is-visible", method === "gcash");
      return;
    }

    paymentEl.innerHTML = baseRows;

    if (paymentQr) {
      paymentQr.classList.remove("is-visible");
    }
  }

  function openDetail(card){
    const queueData = card.queueData || {};

    document.getElementById("modalQueue").textContent = card.dataset.queue ? `Queue ${card.dataset.queue}` : "Queue Details";
    document.getElementById("modalType").textContent = formatLabel(card.dataset.type || "");
    document.getElementById("modalService").textContent = card.dataset.service || "";
    const notesValue = card.dataset.notes || "";
    const notesWrap = document.getElementById("modalNotesWrap");
    document.getElementById("modalNotes").value = notesValue;
    if (notesWrap) notesWrap.hidden = notesValue.trim() === "";
    document.getElementById("modalPrice").textContent = getQueuePriceLabel(queueData);
    renderAttachedFiles(queueData);
    renderPaymentDetails(queueData);

    const statusEl = document.getElementById("modalStatus");
    const status = (card.dataset.status || "PENDING").toUpperCase();
    const tone = badgeTone(status);
    statusEl.textContent = status;
    statusEl.className = "status-badge modal-status-pill status-" + tone + " modal-status-pill--" + tone;

    const extra = document.getElementById("modalExtra");
    extra.innerHTML = serviceDetailRows(queueData)
      .map(([label, value]) => buildDetailRow(label, value))
      .join("");
    lastFocused = document.activeElement;
    detailModal.classList.add("is-open");
    detailModal.setAttribute("aria-hidden", "false");
    document.addEventListener("keydown", onModalKeydown);
    closeDetail?.focus({ preventScroll: true });
  }

  function getRequestedQueueId() {
    const params = new URLSearchParams(window.location.search);
    const requestedId = Number(params.get("queue_id") || 0);
    return requestedId > 0 ? requestedId : 0;
  }

  function clearRequestedQueueFromUrl() {
    const url = new URL(window.location.href);
    url.searchParams.delete("queue_id");
    url.searchParams.delete("open");
    window.history.replaceState({}, "", url.pathname + url.search + url.hash);
  }

  function maybeOpenRequestedQueue() {
    const requestedId = getRequestedQueueId();
    if (!requestedId) {
      return;
    }

    const targetCard = listEl.querySelector('[data-queue-id="' + String(requestedId) + '"]');
    if (!targetCard) {
      return;
    }

    targetCard.scrollIntoView({
      behavior: "smooth",
      block: "center"
    });

    window.setTimeout(() => {
      openDetail(targetCard);
    }, 240);
    clearRequestedQueueFromUrl();
  }

  async function loadQueues(){
    renderState("Loading queue list...");

    let res;
    try {
      res = await fetch(servitechUrl("/api/queue_list.php"), {
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        }
      });
    } catch (e) {
      renderState("Could not connect to the server.", '<button id="retryQueuesBtn" type="button" class="btn-next">Retry</button>');
      return;
    }

    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      console.error("RAW response:", text);
      renderState("Server returned an invalid response.", '<button id="retryQueuesBtn" type="button" class="btn-next">Retry</button>');
      return;
    }

    listEl.innerHTML = "";

    if (!data.ok) {
      renderState(data.error || "Unable to load your queue list.", '<button id="retryQueuesBtn" type="button" class="btn-next">Retry</button>');
      return;
    }

    if (!data.queues || data.queues.length === 0) {
      renderState("No queues yet.", '<a href="/pages/customer/custo_place_queueing.php" class="btn-next">Join Queue</a>');
      return;
    }

    data.queues.forEach(q => {
      const card = buildCard(q);
      listEl.appendChild(card);

      card.addEventListener("click", () => openDetail(card));
      card.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          openDetail(card);
        }
      });
    });

    maybeOpenRequestedQueue();
  }

  [closeDetail, modalCloseBtn].forEach(btn => {
    if (btn) btn.addEventListener("click", closeDetailModal);
  });

  if (detailModal) {
    detailModal.addEventListener("click", (e) => {
      if (e.target === detailModal) closeDetailModal();
    });
  }

  listEl?.addEventListener("click", (e) => {
    const t = e.target;
    if (t && t.id === "retryQueuesBtn") {
      loadQueues();
    }
  });

  await loadQueues();
})();
</script>

</body>
</html>

