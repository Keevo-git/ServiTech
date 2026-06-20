<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Service Status</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260616-footer-hover">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260526status-badges">
  <link rel="stylesheet" href="/assets/css/customer-toast.css?v=20260607-status-edit-toast">
  <link rel="stylesheet" href="/assets/css/upload-progress.css?v=20260611-per-file-state">
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

    body.customer-layout.customer-page--status .status-tabs {
      align-items: center;
      display: flex;
      gap: 0.75rem;
      margin-bottom: clamp(14px, 2.5vw, 20px);
      min-width: 0;
    }

    body.customer-layout.customer-page--status .status-tab {
      align-items: center;
      appearance: none;
      background: transparent;
      border: 0;
      border-bottom: 2px solid transparent;
      color: #7a3810;
      cursor: pointer;
      display: inline-flex;
      gap: 0.6rem;
      justify-content: center;
      min-height: 42px;
      padding: 0.55rem 0;
      text-transform: uppercase;
      transition: border-color 0.18s ease, color 0.18s ease;
    }

    body.customer-layout.customer-page--status .status-tab:hover,
    body.customer-layout.customer-page--status .status-tab:focus-visible {
      color: #5f0e0f;
      outline: none;
    }

    body.customer-layout.customer-page--status .status-tab.is-active {
      border-bottom-color: #f08a00;
      color: #a33b00;
    }

    body.customer-layout.customer-page--status .status-tab__label {
      font-size: clamp(0.95rem, 2vw, 1.08rem);
      font-weight: 900;
      letter-spacing: 0.02em;
      margin: 0;
    }

    body.customer-layout.customer-page--status .status-count-pill {
      background: #fff7ed;
      border: 1px solid rgba(240, 138, 0, 0.24);
      border-radius: 999px;
      color: #7a3810;
      flex: 0 0 auto;
      font-size: 0.76rem;
      font-weight: 800;
      padding: 0.35rem 0.7rem;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--status .status-tab-panel[hidden] {
      display: none !important;
    }

    body.customer-layout.customer-page--status .status-filter-bar {
      align-items: end;
      background: rgba(255, 255, 255, 0.74);
      border: 1px solid rgba(95, 14, 15, 0.1);
      border-radius: 16px;
      display: grid;
      gap: 0.85rem;
      grid-template-columns: repeat(3, minmax(150px, 1fr)) auto;
      margin-bottom: clamp(16px, 2.5vw, 22px);
      padding: clamp(12px, 2.2vw, 16px);
    }

    body.customer-layout.customer-page--status .status-filter-field {
      display: grid;
      gap: 0.4rem;
      min-width: 0;
    }

    body.customer-layout.customer-page--status .status-filter-label {
      color: #7c625b;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.07em;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--status .status-filter-control {
      appearance: none;
      background: #fffaf4;
      border: 1px solid rgba(95, 14, 15, 0.14);
      border-radius: 12px;
      color: #24120f;
      font: inherit;
      font-size: 0.92rem;
      font-weight: 700;
      min-height: 44px;
      min-width: 0;
      padding: 0.68rem 0.8rem;
      width: 100%;
    }

    body.customer-layout.customer-page--status select.status-filter-control {
      background-image:
        linear-gradient(45deg, transparent 50%, #8b1e1e 50%),
        linear-gradient(135deg, #8b1e1e 50%, transparent 50%);
      background-position:
        calc(100% - 18px) 50%,
        calc(100% - 13px) 50%;
      background-repeat: no-repeat;
      background-size: 5px 5px, 5px 5px;
      padding-right: 2.2rem;
    }

    body.customer-layout.customer-page--status .status-filter-clear {
      background: #fff8f5;
      border: 1px solid #ead2c5;
      border-radius: 12px;
      color: #8b1e1e;
      cursor: pointer;
      font-weight: 800;
      min-height: 44px;
      padding: 0.68rem 0.95rem;
      transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }

    body.customer-layout.customer-page--status .status-filter-clear:hover,
    body.customer-layout.customer-page--status .status-filter-clear:focus-visible {
      background: #8b1e1e;
      border-color: #8b1e1e;
      color: #ffffff;
      outline: none;
    }

    body.customer-layout.customer-page--status #detailModal {
      z-index: var(--site-modal-z, 20000) !important;
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
      max-height: 88vh !important;
      max-width: 1120px !important;
      overflow: hidden;
      padding: 0 !important;
      position: relative;
      width: min(100%, 1120px) !important;
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
      gap: clamp(0.9rem, 2vw, 1.25rem);
      grid-template-columns: minmax(0, 1.08fr) minmax(320px, 0.92fr);
      align-items: start;
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
      padding: clamp(16px, 3vw, 24px);
      scrollbar-gutter: stable;
    }

    body.customer-layout.customer-page--status .status-modal__column {
      display: grid;
      gap: 0.85rem;
      min-width: 0;
    }

    body.customer-layout.customer-page--status .status-modal__column--secondary {
      align-content: start;
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
      align-items: center;
      gap: 0.75rem;
      justify-content: flex-end;
      flex: 0 0 auto;
      padding: clamp(14px, 2.4vw, 18px) clamp(16px, 3vw, 24px);
      border-top: 1px solid rgba(95, 14, 15, 0.1);
      background: rgba(255, 253, 249, 0.92);
    }

    body.customer-layout.customer-page--status .status-cancel-message {
      color: #7c625b;
      flex: 1 1 auto;
      font-size: 0.88rem;
      font-weight: 700;
      min-width: 0;
      overflow-wrap: anywhere;
    }

    body.customer-layout.customer-page--status .status-cancel-message[hidden],
    body.customer-layout.customer-page--status .status-cancel-btn[hidden] {
      display: none !important;
    }

    body.customer-layout.customer-page--status .status-cancel-btn {
      appearance: none;
      background: #fff1f2;
      border: 1px solid #fecdd3;
      border-radius: 12px;
      color: #be123c;
      cursor: pointer;
      flex: 0 0 auto;
      font-weight: 850;
      min-height: 44px;
      padding: 0.72rem 1rem;
      transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease, opacity 0.18s ease;
    }

    body.customer-layout.customer-page--status .status-cancel-btn:hover:not(:disabled),
    body.customer-layout.customer-page--status .status-cancel-btn:focus-visible:not(:disabled) {
      background: #be123c;
      border-color: #be123c;
      color: #ffffff;
      outline: none;
    }

    body.customer-layout.customer-page--status .status-cancel-btn:disabled {
      cursor: wait;
      opacity: 0.68;
    }

    body.customer-layout.customer-page--status .status-admin-message {
      background: #fff7ed;
      border-color: rgba(240, 138, 0, 0.3);
    }

    body.customer-layout.customer-page--status .status-modal.status-modal--editing {
      border-color: rgba(240, 138, 0, 0.38) !important;
      box-shadow: 0 30px 76px rgba(95, 14, 15, 0.28) !important;
    }

    body.customer-layout.customer-page--status .status-modal.status-modal--editing .status-modal__header {
      background: linear-gradient(90deg, rgba(255, 247, 237, 0.98) 0%, rgba(255, 253, 249, 0.94) 100%);
      border-bottom-color: rgba(240, 138, 0, 0.24);
    }

    body.customer-layout.customer-page--status #editRequestModal {
      z-index: var(--site-stacked-modal-z, 20100) !important;
      align-items: center !important;
      background: rgba(32, 18, 15, 0.56) !important;
      justify-content: center !important;
      padding: clamp(14px, 3vw, 28px) !important;
      backdrop-filter: blur(5px);
    }

    body.customer-layout.customer-page--status #editRequestModal.is-open {
      display: flex;
    }

    body.customer-layout.customer-page--status .status-edit-modal {
      background: linear-gradient(180deg, #fffdf9 0%, #fff8ef 100%) !important;
      border: 1px solid rgba(240, 138, 0, 0.3) !important;
      border-radius: 22px !important;
      box-shadow: 0 30px 76px rgba(95, 14, 15, 0.28) !important;
      color: #24120f;
      display: flex;
      flex-direction: column;
      margin: auto !important;
      max-height: 90vh !important;
      max-width: 980px !important;
      overflow: hidden;
      padding: 0 !important;
      position: relative;
      width: min(100%, 980px) !important;
    }

    body.customer-layout.customer-page--status .status-edit-modal__header {
      align-items: start;
      background: linear-gradient(90deg, rgba(255, 247, 237, 0.98) 0%, rgba(255, 253, 249, 0.94) 100%);
      border-bottom: 1px solid rgba(240, 138, 0, 0.24);
      display: grid;
      gap: 0.4rem;
      grid-template-columns: minmax(0, 1fr) auto;
      padding: clamp(18px, 3vw, 26px) clamp(88px, 9vw, 96px) clamp(14px, 2vw, 18px) clamp(18px, 3vw, 26px);
      flex: 0 0 auto;
    }

    body.customer-layout.customer-page--status .status-edit-modal__eyebrow {
      color: #8a5f34;
      font-size: 0.74rem;
      font-weight: 800;
      letter-spacing: 0.09em;
      margin: 0;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--status .status-edit-modal .modal-title {
      color: #4a0505 !important;
      font-size: clamp(1.35rem, 3vw, 1.85rem) !important;
      line-height: 1.15;
      margin: 0 !important;
      overflow-wrap: anywhere;
    }

    body.customer-layout.customer-page--status .status-edit-modal .modal-close {
      align-items: center !important;
      appearance: none;
      aspect-ratio: 1 / 1;
      background: #fff8f5 !important;
      border: 1px solid #ead2c5 !important;
      border-radius: 50% !important;
      box-shadow: 0 10px 22px rgba(74, 5, 5, 0.08);
      color: #8b1e1e !important;
      cursor: pointer;
      display: flex !important;
      font-size: 0 !important;
      height: 48px !important;
      justify-content: center !important;
      min-height: 48px !important;
      min-width: 48px !important;
      padding: 0 !important;
      position: absolute !important;
      right: clamp(16px, 3vw, 24px) !important;
      top: clamp(16px, 3vw, 24px) !important;
      width: 48px !important;
    }

    body.customer-layout.customer-page--status .status-edit-modal .modal-close span {
      display: none;
    }

    body.customer-layout.customer-page--status .status-edit-modal .modal-close::before,
    body.customer-layout.customer-page--status .status-edit-modal .modal-close::after {
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

    body.customer-layout.customer-page--status .status-edit-modal .modal-close::before {
      transform: translate(-50%, -50%) rotate(45deg);
    }

    body.customer-layout.customer-page--status .status-edit-modal .modal-close::after {
      transform: translate(-50%, -50%) rotate(-45deg);
    }

    body.customer-layout.customer-page--status .status-edit-modal__body {
      display: grid;
      gap: 1rem;
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
      padding: clamp(16px, 3vw, 24px);
      scrollbar-gutter: stable;
    }

    body.customer-layout.customer-page--status .status-edit-shell {
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(95, 14, 15, 0.11);
      border-radius: 18px;
      display: grid;
      gap: 1rem;
      padding: clamp(14px, 2.4vw, 20px);
    }

    body.customer-layout.customer-page--status .status-edit-modal__footer {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      justify-content: flex-end;
      flex: 0 0 auto;
      padding: clamp(14px, 2.4vw, 18px) clamp(16px, 3vw, 24px);
      border-top: 1px solid rgba(95, 14, 15, 0.1);
      background: rgba(255, 253, 249, 0.94);
    }

    body.customer-layout.customer-page--status .status-admin-message[hidden],
    body.customer-layout.customer-page--status .status-edit-form[hidden],
    body.customer-layout.customer-page--status .status-edit-btn[hidden],
    body.customer-layout.customer-page--status .status-save-edit-btn[hidden],
    body.customer-layout.customer-page--status .status-edit-cancel-btn[hidden] {
      display: none !important;
    }

    body.customer-layout.customer-page--status .status-admin-message__body {
      color: #5f0e0f;
      font-size: 0.94rem;
      font-weight: 700;
      line-height: 1.5;
      margin: 0;
      overflow-wrap: anywhere;
    }

    body.customer-layout.customer-page--status .status-edit-form {
      display: grid;
      gap: 0.85rem;
    }

    body.customer-layout.customer-page--status .status-edit-grid {
      display: grid;
      gap: 0.75rem;
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    body.customer-layout.customer-page--status .status-edit-field {
      display: grid;
      gap: 0.4rem;
      min-width: 0;
    }

    body.customer-layout.customer-page--status .status-edit-field--full {
      grid-column: 1 / -1;
    }

    body.customer-layout.customer-page--status .status-edit-label {
      color: #7c625b;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--status .status-edit-control {
      appearance: none;
      background: #fffaf4;
      border: 1px solid rgba(95, 14, 15, 0.14);
      border-radius: 12px;
      color: #24120f;
      font: inherit;
      font-size: 0.94rem;
      font-weight: 650;
      min-height: 44px;
      min-width: 0;
      padding: 0.68rem 0.8rem;
      width: 100%;
    }

    body.customer-layout.customer-page--status select.status-edit-control {
      background-color: #fffaf4;
      background-image:
        linear-gradient(45deg, transparent 50%, #8b1e1e 50%),
        linear-gradient(135deg, #8b1e1e 50%, transparent 50%),
        linear-gradient(to right, rgba(240, 138, 0, 0.16), rgba(240, 138, 0, 0.16));
      background-position:
        calc(100% - 20px) 50%,
        calc(100% - 14px) 50%,
        calc(100% - 42px) 50%;
      background-repeat: no-repeat;
      background-size: 7px 7px, 7px 7px, 1px 62%;
      cursor: pointer;
      padding-right: 3rem;
    }

    body.customer-layout.customer-page--status .status-edit-control:focus-visible {
      border-color: rgba(240, 138, 0, 0.82);
      box-shadow: 0 0 0 3px rgba(240, 138, 0, 0.14);
      outline: none;
    }

    body.customer-layout.customer-page--status textarea.status-edit-control {
      min-height: 94px !important;
    }

    body.customer-layout.customer-page--status .status-edit-existing-files {
      background: #fffaf4;
      border: 1px solid rgba(240, 138, 0, 0.24);
      border-radius: 14px;
      display: grid;
      gap: 0.6rem;
      padding: 0.75rem;
    }

    body.customer-layout.customer-page--status .status-edit-existing-files__title {
      color: #5f0e0f;
      font-size: 0.84rem;
      font-weight: 850;
      margin: 0;
    }

    body.customer-layout.customer-page--status .status-edit-existing-file {
      align-items: center;
      background: #ffffff;
      border: 1px solid rgba(95, 14, 15, 0.12);
      border-radius: 12px;
      display: grid;
      gap: 0.65rem;
      grid-template-columns: minmax(0, 1fr) auto;
      min-width: 0;
      padding: 0.65rem 0.75rem;
    }

    body.customer-layout.customer-page--status .status-edit-existing-file__name {
      color: #24120f;
      font-size: 0.9rem;
      font-weight: 800;
      min-width: 0;
      overflow-wrap: anywhere;
    }

    body.customer-layout.customer-page--status .status-edit-existing-file__meta {
      color: #7c625b;
      font-size: 0.78rem;
      font-weight: 700;
      margin-top: 0.15rem;
    }

    body.customer-layout.customer-page--status .status-edit-file-remove {
      appearance: none;
      background: #fff1f2;
      border: 1px solid #fecdd3;
      border-radius: 999px;
      color: #be123c;
      cursor: pointer;
      font-size: 0.78rem;
      font-weight: 850;
      min-height: 36px;
      padding: 0.48rem 0.72rem;
    }

    body.customer-layout.customer-page--status .status-edit-file-remove:hover,
    body.customer-layout.customer-page--status .status-edit-file-remove:focus-visible {
      background: #be123c;
      border-color: #be123c;
      color: #ffffff;
      outline: none;
    }

    body.customer-layout.customer-page--status .status-edit-existing-files__empty {
      color: #7c625b;
      font-size: 0.86rem;
      font-weight: 700;
      margin: 0;
    }

    body.customer-layout.customer-page--status .status-edit-price-card {
      background: linear-gradient(180deg, #fffdf9 0%, #fff4e2 100%);
      border: 1px solid rgba(240, 138, 0, 0.28);
      border-radius: 16px;
      display: grid;
      gap: 0.7rem;
      grid-column: 1 / -1;
      padding: 0.9rem;
    }

    body.customer-layout.customer-page--status .status-edit-price-card__head {
      align-items: center;
      display: flex;
      gap: 0.75rem;
      justify-content: space-between;
    }

    body.customer-layout.customer-page--status .status-edit-price-card__title {
      color: #5f0e0f;
      font-size: 0.84rem;
      font-weight: 900;
      letter-spacing: 0.06em;
      margin: 0;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--status .status-edit-price-card__total {
      color: #ffffff;
      background: #8b1e1e;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 900;
      padding: 0.55rem 0.8rem;
      white-space: nowrap;
    }

    body.customer-layout.customer-page--status .status-edit-price-card__rows {
      display: grid;
      gap: 0.45rem;
    }

    body.customer-layout.customer-page--status .status-edit-price-row {
      align-items: center;
      display: flex;
      gap: 0.75rem;
      justify-content: space-between;
      color: #5f4a43;
      font-size: 0.86rem;
      font-weight: 750;
    }

    body.customer-layout.customer-page--status .status-edit-price-row strong {
      color: #24120f;
      text-align: right;
    }

    body.customer-layout.customer-page--status .status-edit-price-note {
      color: #8a5f34;
      font-size: 0.78rem;
      font-weight: 750;
      line-height: 1.4;
      margin: 0;
    }

    body.customer-layout.customer-page--status .status-edit-help,
    body.customer-layout.customer-page--status .status-edit-error {
      color: #7c625b;
      font-size: 0.86rem;
      font-weight: 700;
      line-height: 1.45;
      margin: 0;
    }

    body.customer-layout.customer-page--status .status-edit-mode-note {
      background: #fff7ed;
      border: 1px solid rgba(240, 138, 0, 0.28);
      border-radius: 12px;
      color: #5f0e0f;
      font-size: 0.92rem;
      font-weight: 750;
      line-height: 1.45;
      margin: 0;
      padding: 0.75rem 0.85rem;
    }

    body.customer-layout.customer-page--status .status-edit-error {
      color: #be123c;
    }

    body.customer-layout.customer-page--status .status-edit-btn,
    body.customer-layout.customer-page--status .status-save-edit-btn,
    body.customer-layout.customer-page--status .status-edit-cancel-btn {
      appearance: none;
      border-radius: 12px;
      cursor: pointer;
      flex: 0 0 auto;
      font-weight: 850;
      min-height: 44px;
      padding: 0.72rem 1rem;
      transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease, opacity 0.18s ease;
    }

    body.customer-layout.customer-page--status .status-edit-btn,
    body.customer-layout.customer-page--status .status-save-edit-btn {
      background: #f08a00;
      border: 1px solid #f08a00;
      color: #ffffff;
    }

    body.customer-layout.customer-page--status .status-edit-cancel-btn {
      background: #fff8f5;
      border: 1px solid #ead2c5;
      color: #8b1e1e;
    }

    body.customer-layout.customer-page--status .status-edit-btn:disabled,
    body.customer-layout.customer-page--status .status-save-edit-btn:disabled,
    body.customer-layout.customer-page--status .status-edit-cancel-btn:disabled {
      cursor: wait;
      opacity: 0.68;
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

    body.customer-layout.customer-page--status #modalStatus.status-approved,
    body.customer-layout.customer-page--status .status-approved {
      background: #e0f2fe;
      color: #0369a1;
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

      body.customer-layout.customer-page--status .status-tabs {
        align-items: flex-start;
        flex-direction: column;
        gap: 0.55rem;
      }

      body.customer-layout.customer-page--status .status-tab {
        justify-content: space-between;
        width: 100%;
      }

      body.customer-layout.customer-page--status .status-filter-bar {
        grid-template-columns: 1fr;
      }

      body.customer-layout.customer-page--status .status-filter-clear {
        width: 100%;
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

      body.customer-layout.customer-page--status .status-edit-modal {
        border-radius: 18px !important;
        max-height: calc(100vh - 28px) !important;
      }

      body.customer-layout.customer-page--status .status-modal__grid {
        grid-template-columns: 1fr;
        gap: 0.85rem;
      }

      body.customer-layout.customer-page--status .status-modal__header {
        padding-right: 78px;
      }

      body.customer-layout.customer-page--status .status-edit-modal__header {
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

      body.customer-layout.customer-page--status .status-modal__footer {
        align-items: stretch;
        flex-direction: column;
        justify-content: stretch;
      }

      body.customer-layout.customer-page--status .status-edit-modal__footer {
        align-items: stretch;
        flex-direction: column-reverse;
        justify-content: stretch;
      }

      body.customer-layout.customer-page--status .status-edit-grid {
        grid-template-columns: 1fr;
      }

      body.customer-layout.customer-page--status .status-edit-existing-file {
        align-items: stretch;
        grid-template-columns: 1fr;
      }

      body.customer-layout.customer-page--status .status-edit-file-remove,
      body.customer-layout.customer-page--status .status-edit-price-card__total {
        width: 100%;
      }

      body.customer-layout.customer-page--status .status-edit-price-card__head,
      body.customer-layout.customer-page--status .status-edit-price-row {
        align-items: stretch;
        flex-direction: column;
        gap: 0.35rem;
      }

      body.customer-layout.customer-page--status .status-edit-price-row strong {
        text-align: left;
      }

      body.customer-layout.customer-page--status .status-cancel-message,
      body.customer-layout.customer-page--status .status-cancel-btn,
      body.customer-layout.customer-page--status .status-edit-btn,
      body.customer-layout.customer-page--status .status-save-edit-btn,
      body.customer-layout.customer-page--status .status-edit-cancel-btn {
        width: 100%;
      }

      body.customer-layout.customer-page--status .status-current-card {
        align-items: flex-start;
        flex-direction: column;
      }
    }

    @media (min-width: 641px) and (max-width: 940px) {
      body.customer-layout.customer-page--status .status-modal {
        max-width: 860px !important;
        width: min(100%, 860px) !important;
      }

      body.customer-layout.customer-page--status .status-edit-modal {
        max-width: 860px !important;
        width: min(100%, 860px) !important;
      }

      body.customer-layout.customer-page--status .status-modal__grid {
        grid-template-columns: 1fr;
      }

      body.customer-layout.customer-page--status .status-filter-bar {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      body.customer-layout.customer-page--status .status-filter-clear {
        grid-column: 1 / -1;
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

    <div id="serviceStatusPanel" class="status-panel">
      <div class="status-tabs" role="tablist" aria-label="Service status sections">
        <button id="activeQueuesTab" class="status-tab is-active" type="button" role="tab" aria-selected="true" aria-controls="activeQueuePanel" data-status-tab="active">
          <span class="status-tab__label">Your Queues</span>
          <span id="activeQueueCount" class="status-count-pill">0 Active</span>
        </button>
        <button id="completedQueuesTab" class="status-tab" type="button" role="tab" aria-selected="false" aria-controls="completedQueuePanel" data-status-tab="completed">
          <span class="status-tab__label">Queues Completed</span>
          <span id="archiveQueueCount" class="status-count-pill">0 Completed</span>
        </button>
      </div>

      <div class="status-filter-bar" aria-label="Filter service status records">
        <label class="status-filter-field" for="categoryFilter">
          <span class="status-filter-label">Category</span>
          <select id="categoryFilter" class="status-filter-control">
            <option value="">All Categories</option>
            <option value="printing">Print</option>
            <option value="repair">Repair</option>
            <option value="installation">Installation</option>
          </select>
        </label>

        <label class="status-filter-field" for="statusFilter">
          <span class="status-filter-label">Status</span>
          <select id="statusFilter" class="status-filter-control">
            <option value="">All Statuses</option>
            <option value="PENDING">Pending</option>
            <option value="APPROVED">Approved</option>
            <option value="ONGOING">Ongoing</option>
            <option value="FOR PICK-UP">For Pick-up</option>
            <option value="DONE">Done</option>
            <option value="CANCELLED">Cancelled</option>
          </select>
        </label>

        <label class="status-filter-field" for="dateFilter">
          <span class="status-filter-label">Submitted Date</span>
          <input id="dateFilter" class="status-filter-control" type="date">
        </label>

        <button id="clearFiltersBtn" class="status-filter-clear" type="button">Clear</button>
      </div>

      <div id="activeQueuePanel" class="status-tab-panel" role="tabpanel" aria-labelledby="activeQueuesTab">
        <div id="queueList" class="queue-list"></div>
      </div>
      <div id="completedQueuePanel" class="status-tab-panel" role="tabpanel" aria-labelledby="completedQueuesTab" hidden>
        <div id="archiveQueueList" class="queue-list"></div>
      </div>
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
          <div class="status-modal__column status-modal__column--primary">
            <section id="adminSendBackSection" class="status-modal__section status-admin-message" aria-labelledby="adminSendBackTitle" hidden>
              <h4 id="adminSendBackTitle" class="status-modal__section-title">Message from Admin</h4>
              <p id="adminSendBackMessage" class="status-admin-message__body"></p>
            </section>

            <section class="status-modal__section" aria-labelledby="serviceDetailsTitle">
              <h4 id="serviceDetailsTitle" class="status-modal__section-title">Service Details</h4>
              <div class="status-detail-row">
                <span class="status-detail-label">Queue Reference</span>
                <span id="modalQueueRef" class="status-detail-value"></span>
              </div>
              <div class="status-detail-row">
                <span class="status-detail-label">Category</span>
                <span id="modalType" class="status-detail-value"></span>
              </div>
              <div class="status-detail-row">
                <span class="status-detail-label">Service</span>
                <span id="modalService" class="status-detail-value"></span>
              </div>
              <div class="status-detail-row">
                <span class="status-detail-label">Submitted</span>
                <span id="modalSubmittedAt" class="status-detail-value"></span>
              </div>
              <div id="modalCompletedAtRow" class="status-detail-row" hidden>
                <span class="status-detail-label">Completed</span>
                <span id="modalCompletedAt" class="status-detail-value"></span>
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

            <section class="status-modal__section" aria-labelledby="currentStatusTitle">
              <h4 id="currentStatusTitle" class="status-modal__section-title">Current Status</h4>
              <div class="status-current-card modal-status">
                <span class="status-current-card__label">Current Status</span>
                <span id="modalStatus"></span>
              </div>
            </section>

          </div>

          <div class="status-modal__column status-modal__column--secondary">
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

          </div>
        </div>
        <div class="status-modal__footer">
          <p id="modalCancelMessage" class="status-cancel-message" role="status" hidden></p>
          <button id="editQueueBtn" class="status-edit-btn" type="button" hidden>Edit</button>
          <button id="cancelPendingQueueBtn" class="status-cancel-btn" type="button" hidden>Cancel Request</button>
        </div>
      </div>
    </div>

    <div id="editRequestModal" class="modal-overlay" aria-hidden="true">
      <div class="modal status-edit-modal" role="dialog" aria-modal="true" aria-labelledby="editRequestTitle" tabindex="-1">
        <button id="closeEditRequest" class="modal-close" type="button" aria-label="Close edit request"><span aria-hidden="true">&times;</span></button>

        <div class="status-edit-modal__header">
          <div>
            <p class="status-edit-modal__eyebrow">Editing Mode</p>
            <h3 id="editRequestTitle" class="modal-title">
              <span id="editRequestQueueTitle">Edit Request</span>
            </h3>
          </div>
        </div>

        <div class="status-edit-modal__body">
          <section class="status-modal__section status-admin-message" aria-labelledby="editAdminMessageTitle">
            <h4 id="editAdminMessageTitle" class="status-modal__section-title">Message from Admin</h4>
            <p id="editAdminMessage" class="status-admin-message__body"></p>
          </section>

          <section id="statusEditSection" class="status-edit-shell" aria-labelledby="statusEditTitle">
            <h4 id="statusEditTitle" class="status-modal__section-title">Edit Request Details</h4>
            <p class="status-edit-mode-note">Update the fields below based on admin feedback. Existing uploaded files stay attached unless you choose replacements.</p>
            <form id="statusEditForm" class="status-edit-form">
              <div id="statusEditFields" class="status-edit-grid"></div>
              <p id="statusEditHelp" class="status-edit-help"></p>
              <p id="statusEditError" class="status-edit-error" role="alert"></p>
            </form>
          </section>
        </div>

        <div class="status-edit-modal__footer">
          <button id="saveEditQueueBtn" class="status-save-edit-btn" type="button">Save Changes</button>
          <button id="cancelEditQueueBtn" class="status-edit-cancel-btn" type="button">Cancel</button>
        </div>
      </div>
    </div>

<script src="/assets/js/csrf.js"></script>
<script src="/assets/js/customer_toast.js?v=20260607-status-edit-toast"></script>
<script src="/assets/js/upload_progress.js?v=20260612-upload-limits"></script>
<script>
(async function(){
  const listEl = document.getElementById("queueList");
  const panelEl = document.getElementById("serviceStatusPanel");
  const archiveListEl = document.getElementById("archiveQueueList");
  const activeQueuePanel = document.getElementById("activeQueuePanel");
  const completedQueuePanel = document.getElementById("completedQueuePanel");
  const activeQueuesTab = document.getElementById("activeQueuesTab");
  const completedQueuesTab = document.getElementById("completedQueuesTab");
  const activeQueueCount = document.getElementById("activeQueueCount");
  const archiveQueueCount = document.getElementById("archiveQueueCount");
  const categoryFilter = document.getElementById("categoryFilter");
  const statusFilter = document.getElementById("statusFilter");
  const dateFilter = document.getElementById("dateFilter");
  const clearFiltersBtn = document.getElementById("clearFiltersBtn");
  const detailModal = document.getElementById("detailModal");
  const statusModal = detailModal?.querySelector(".status-modal");
  const modalEyebrow = statusModal?.querySelector(".status-modal__eyebrow");
  const modalTitleText = document.getElementById("modalQueue");
  const editRequestModal = document.getElementById("editRequestModal");
  const editRequestDialog = editRequestModal?.querySelector(".status-edit-modal");
  const editRequestQueueTitle = document.getElementById("editRequestQueueTitle");
  const closeEditRequest = document.getElementById("closeEditRequest");
  const editAdminMessage = document.getElementById("editAdminMessage");
  const closeDetail = document.getElementById("closeDetail");
  const modalCloseBtn = document.getElementById("modalCloseBtn");
  const cancelPendingQueueBtn = document.getElementById("cancelPendingQueueBtn");
  const modalCancelMessage = document.getElementById("modalCancelMessage");
  const adminSendBackSection = document.getElementById("adminSendBackSection");
  const adminSendBackMessage = document.getElementById("adminSendBackMessage");
  const statusEditSection = document.getElementById("statusEditSection");
  const statusEditFields = document.getElementById("statusEditFields");
  const statusEditHelp = document.getElementById("statusEditHelp");
  const statusEditError = document.getElementById("statusEditError");
  const editQueueBtn = document.getElementById("editQueueBtn");
  const saveEditQueueBtn = document.getElementById("saveEditQueueBtn");
  const cancelEditQueueBtn = document.getElementById("cancelEditQueueBtn");

  let lastFocused = null;
  let allQueues = [];
  let selectedStatusTab = "active";
  let currentDetailQueue = null;
  let cancellationInProgress = false;
  let editInProgress = false;
  let editMode = false;
  let editRemovedFileTokens = new Set();
  let editRemovedFileIndexes = new Set();
  let editUploadTasks = {};
  let activeEditUploadSession = null;
  const serviceCatalogCache = {};

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

  function showCustomerToast(message, tone = "info"){
    const cleanMessage = String(message || "").trim();
    if (!cleanMessage || typeof window.servitechToast !== "function") return;
    window.servitechToast(cleanMessage, { tone });
  }

  function parseDateTime(value){
    const raw = String(value || "").trim();
    if (!raw) return null;

    const normalized = raw
      .replace(" ", "T")
      .replace(/(\.\d{3})\d+/, "$1")
      .replace(/([+-]\d{2})$/, "$1:00");
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function formatDateTime(value){
    const raw = String(value || "").trim();
    if (!raw) return "Not available";

    const date = parseDateTime(raw);
    if (!date) return raw;

    return new Intl.DateTimeFormat("en-PH", {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "numeric",
      minute: "2-digit"
    }).format(date);
  }

  function dateInputValue(value){
    const date = parseDateTime(value);
    if (!date) {
      const raw = String(value || "").trim();
      return /^\d{4}-\d{2}-\d{2}/.test(raw) ? raw.slice(0, 10) : "";
    }

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
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

    if (/^(online|walk-?in)\s+(document\s+)?printing$/i.test(raw)
      || /^online\s+print\s*order$/i.test(raw)
      || /^(online_printorder|printing_online|printing_walkin|walkin)$/i.test(raw)) {
      return /print\s*order|document/i.test(raw) ? "Document Print" : "Print";
    }

    return raw
      .replace(/print\s*order/ig, "Print Order")
      .replace(/printorder/ig, "Print Order")
      .replace(/document\s*printing/ig, "Document Print")
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
      documentprinting: "Document Print",
      onlineprintorder: "Document Print",
      onlineprinting: "Document Print",
      onlinedocumentprinting: "Document Print",
      walkinprinting: "Document Print",
      walkindocumentprinting: "Document Print",
      printwalkin: "Document Print",
      printonline: "Document Print",
      xerox: "Photocopy",
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
    if (key === "approved") return "approved";
    if (key === "for-pick-up" || key === "for-pickup" || key === "ready") return "pickup";
    if (key === "done") return "done";
    if (key === "cancelled" || key === "canceled") return "cancelled";
    return "pending";
  }

  function normalizeStatus(value){
    const status = String(value || "PENDING")
      .trim()
      .toUpperCase()
      .replace(/[\s_]+/g, " ");
    if (status === "" || status === "PENDING PAYMENT") return "PENDING";
    if (status === "FOR PICK UP" || status === "FOR PICKUP") return "FOR PICK-UP";
    if (status === "COMPLETED") return "DONE";
    if (status === "CANCELED" || status === "CANCEL") return "CANCELLED";
    return status;
  }

  function isArchivedStatus(status){
    const normalized = normalizeStatus(status);
    return normalized === "DONE" || normalized === "CANCELLED";
  }

  function canCustomerCancel(status){
    return normalizeStatus(status) === "PENDING";
  }

  function hasActiveSendBack(queueData){
    return Boolean(queueData?.customer_edit_required);
  }

  function canCustomerEdit(queueData){
    const normalized = normalizeStatus(queueData?.status);
    return hasActiveSendBack(queueData) && (normalized === "PENDING" || normalized === "APPROVED");
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

  function filterCategoryKey(queueData){
    const category = categoryKey(queueData);
    if (["printing", "online_printorder", "printing_online", "walkin", "printing_walkin"].includes(category)) return "printing";
    if (category === "repair") return "repair";
    if (category === "installation") return "installation";
    return category;
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
    const legacyServices = [
      "documentprinting",
      "onlineprintorder",
      "onlineprinting",
      "onlinedocumentprinting",
      "walkinprinting",
      "walkindocumentprinting",
      "printwalkin",
      "printonline"
    ];
    const printingCategories = ["printing", "online_printorder", "printing_online", "walkin", "printing_walkin"];
    return legacyServices.includes(service) || printingCategories.includes(categoryKey(queueData));
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

  function serviceCatalogCategory(queueData){
    const category = filterCategoryKey(queueData);
    return ["printing", "repair", "installation"].includes(category) ? category : "";
  }

  function cleanServiceLabel(value){
    return String(value || "")
      .replace(/\s+[-\u2013\u2014]\s+.*$/u, "")
      .trim();
  }

  async function ensureServiceCatalog(queueData){
    const category = serviceCatalogCategory(queueData);
    if (!category || serviceCatalogCache[category]) return serviceCatalogCache[category] || [];

    serviceCatalogCache[category] = [];
    try {
      const response = await fetch(servitechUrl(`/api/services_public.php?action=list&category=${encodeURIComponent(category)}`), {
        method: "GET",
        credentials: "same-origin",
        headers: { "Accept": "application/json" }
      });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.ok && Array.isArray(data.services)) {
        serviceCatalogCache[category] = data.services;
      }
    } catch (_error) {
      serviceCatalogCache[category] = [];
    }

    return serviceCatalogCache[category];
  }

  function findCatalogService(queueData){
    const category = serviceCatalogCategory(queueData);
    const services = serviceCatalogCache[category] || [];
    const service = serviceKey(queueData);
    const label = cleanServiceLabel(queueData?.service_label || queueDetails(queueData).service_label || "").toLowerCase();

    if (service === "documentprinting" || service === "onlineprintorder") {
      return services.find((item) => /document.*printing/i.test(String(item?.name || ""))) || null;
    }
    if (service === "rushid") {
      return services.find((item) => /rush.*id/i.test(String(item?.name || ""))) || null;
    }
    if (service === "xerox") {
      return services.find((item) => /^(xerox|photocopy)$/i.test(String(item?.name || "").trim())) || null;
    }
    if (service === "laminating") {
      return services.find((item) => /laminat/i.test(String(item?.name || ""))) || null;
    }

    return services.find((item) => cleanServiceLabel(item?.name).toLowerCase() === label) || null;
  }

  function pricingMap(queueData, fallback){
    const service = findCatalogService(queueData);
    let stored = {};
    try {
      stored = JSON.parse(String(service?.pricing_json || "{}"));
    } catch (_error) {
      stored = {};
    }
    return Object.fromEntries(Object.entries(fallback).map(([key, value]) => {
      const parsed = toNumber(stored[key]);
      return [key, parsed !== null && parsed >= 0 ? parsed : value];
    }));
  }

  function normalizePaperKey(value){
    const text = String(value || "").trim().toLowerCase();
    if (text.includes("letter") || text.includes("short bond") || text.includes("8.5 x 11")) return "letter";
    if (text.includes("8.5x13") || text.includes("long bond") || text.includes("8.5 x 13")) return "long";
    if (text === "a4") return "a4";
    return "";
  }

  function normalizeColorKey(value){
    const text = String(value || "").trim().toLowerCase();
    if (["black & white", "black and white", "bw"].includes(text)) return "bw";
    if (["full colored", "colored full", "colored - full", "colored (full)"].includes(text)) return "full";
    if (["half colored", "colored half", "colored - half", "colored (half)"].includes(text)) return "half";
    if (["colored", "color"].includes(text)) return "colored";
    return "";
  }

  function inputValue(name){
    return statusEditFields?.querySelector(`[name="${name}"]`)?.value || "";
  }

  function editField(name, label, value = "", type = "text", attrs = ""){
    return `
      <label class="status-edit-field" for="edit_${esc(name)}">
        <span class="status-edit-label">${esc(label)}</span>
        <input id="edit_${esc(name)}" class="status-edit-control" name="${esc(name)}" type="${esc(type)}" value="${esc(value)}" ${attrs}>
      </label>
    `;
  }

  function editSelect(name, label, value, options){
    const selectedValue = String(value || "");
    const opts = options.map((option) => {
      const optionValue = Array.isArray(option) ? option[0] : option;
      const optionLabel = Array.isArray(option) ? option[1] : option;
      return `<option value="${esc(optionValue)}"${String(optionValue) === selectedValue ? " selected" : ""}>${esc(optionLabel)}</option>`;
    }).join("");
    return `
      <label class="status-edit-field" for="edit_${esc(name)}">
        <span class="status-edit-label">${esc(label)}</span>
        <select id="edit_${esc(name)}" class="status-edit-control" name="${esc(name)}">${opts}</select>
      </label>
    `;
  }

  function editTextarea(name, label, value = ""){
    return `
      <label class="status-edit-field status-edit-field--full" for="edit_${esc(name)}">
        <span class="status-edit-label">${esc(label)}</span>
        <textarea id="edit_${esc(name)}" class="status-edit-control" name="${esc(name)}">${esc(value)}</textarea>
      </label>
    `;
  }

  function editFileField(queueData){
    if (!supportsFileUpload(queueData)) return "";
    const rushId = serviceKey(queueData) === "rushid";
    const accept = rushId ? ".jpg,.jpeg,.png" : ".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png";
    return `
      <div class="status-edit-field status-edit-field--full">
        <span class="status-edit-label">Current Attachments</span>
        <div id="statusEditExistingFiles" class="status-edit-existing-files"></div>
      </div>
      <div class="status-edit-field status-edit-field--full">
        <label class="status-edit-label" for="edit_files">${rushId ? "Add New Photos" : "Add New Files"}</label>
        <input id="edit_files" class="status-edit-control" name="files" type="file" multiple accept="${accept}">
        <span class="status-edit-existing-file__meta">Up to 5 files total, 25 MB each, 100 MB combined.</span>
        <div id="statusEditUploadProgress" class="servitech-upload-list" aria-live="polite"></div>
      </div>
    `;
  }

  function validateEditFileSelection(input, queueData){
    if (!input || !supportsFileUpload(queueData)) return [];
    const limits = window.ServitechUpload?.limits || {
      maxFileBytes: 25 * 1024 * 1024,
      maxTotalBytes: 100 * 1024 * 1024,
      maxFiles: 5,
      fileSizeMessage: "Maximum file size is 25 MB per file.",
      totalSizeMessage: "Total upload size must not exceed 100 MB.",
      fileCountMessage: "You can upload up to 5 files only."
    };
    const rushId = serviceKey(queueData) === "rushid";
    const allowed = rushId
      ? new Set(["jpg", "jpeg", "png"])
      : new Set(["pdf", "doc", "docx", "ppt", "pptx", "jpg", "jpeg", "png"]);
    const keptFiles = keptEditFiles(queueData);
    let count = keptFiles.length;
    let bytes = keptFiles.reduce((total, file) => total + Math.max(0, Number(file?.byte_size) || 0), 0);
    const accepted = [];
    const errors = [];

    Array.from(input.files || []).forEach((file) => {
      const extension = String(file.name || "").split(".").pop().toLowerCase();
      if (rushId && extension === "webp") {
        errors.push("Rush ID only accepts JPG, JPEG, and PNG photo files. WEBP files are not allowed.");
        return;
      }
      if (!allowed.has(extension)) {
        errors.push(rushId
          ? "Rush ID only accepts JPG, JPEG, and PNG photo files."
          : `${file.name} has unsupported file type.`);
        return;
      }
      if ((file.size || 0) > limits.maxFileBytes) {
        errors.push(limits.fileSizeMessage);
        return;
      }
      if (count >= limits.maxFiles) {
        errors.push(limits.fileCountMessage);
        return;
      }
      if (bytes + (file.size || 0) > limits.maxTotalBytes) {
        errors.push(limits.totalSizeMessage);
        return;
      }
      accepted.push(file);
      count++;
      bytes += file.size || 0;
    });

    const transfer = new DataTransfer();
    accepted.forEach((file) => transfer.items.add(file));
    input.files = transfer.files;
    return Array.from(new Set(errors));
  }

  function renderEditUploadProgress(){
    const container = document.getElementById("statusEditUploadProgress");
    const input = statusEditFields?.querySelector('[name="files"]');
    if (!container || !input) return;
    container.innerHTML = "";

    Array.from(input.files || []).forEach((file) => {
      const key = window.ServitechUpload?.fileKey(file) || `${file.name}|${file.size}|${file.lastModified}`;
      const task = editUploadTasks[key] || null;
      const taskIsActive = task && window.ServitechUpload?.isActiveStatus(task.status);
      const taskHasProblem = task && window.ServitechUpload?.isTerminalProblemStatus(task.status);
      const row = document.createElement("div");
      const head = document.createElement("div");
      const name = document.createElement("span");
      const action = document.createElement("button");

      row.className = "status-edit-existing-file servitech-upload-item";
      head.className = "servitech-upload-item__head";
      name.className = "status-edit-existing-file__name servitech-upload-item__name";
      name.textContent = file.name;
      action.type = "button";
      action.className = "status-edit-file-remove";
      action.textContent = taskIsActive
        ? "Cancel"
        : "Remove";
      action.dataset.editUploadAction = action.textContent.toLowerCase();
      action.dataset.editUploadKey = key;
      action.setAttribute("aria-label", `${action.textContent} ${file.name}`);

      head.appendChild(name);
      head.appendChild(action);
      row.appendChild(head);

      if (taskIsActive) {
        const progress = document.createElement("div");
        const track = document.createElement("div");
        const bar = document.createElement("div");
        const meta = document.createElement("div");
        progress.className = `servitech-upload-progress servitech-upload-progress--${task.status}`;
        progress.setAttribute("role", "progressbar");
        progress.setAttribute("aria-label", `${file.name} ${task.message || "file progress"}`);
        progress.setAttribute("aria-valuemin", "0");
        progress.setAttribute("aria-valuemax", "100");
        if (!["processing", "checking", "analyzing"].includes(task.status)) {
          progress.setAttribute("aria-valuenow", String(Math.max(0, Math.min(100, task.progress || 0))));
        }
        track.className = "servitech-upload-progress__track";
        bar.className = "servitech-upload-progress__bar";
        bar.style.width = `${Math.max(0, Math.min(100, task.progress || 0))}%`;
        meta.className = "servitech-upload-progress__meta";
        meta.textContent = task.message || "Uploading...";
        track.appendChild(bar);
        progress.appendChild(track);
        progress.appendChild(meta);
        row.appendChild(progress);
      } else if (taskHasProblem) {
        const result = document.createElement("div");
        result.className = `servitech-upload-result servitech-upload-result--${task.status}`;
        result.textContent = task.message || "File upload did not complete.";
        row.appendChild(result);
      }
      container.appendChild(row);
    });
  }

  function fileToken(file){
    return String(file?.upload_token || file?.token || "").trim();
  }

  function fileAnalysisCount(file){
    return toNumber(file?.analysis?.page_count ?? file?.analysis?.slide_count) ?? 0;
  }

  function existingEditFiles(queueData){
    const details = queueDetails(queueData);
    const uploadedFiles = Array.isArray(queueData.uploaded_files)
      ? queueData.uploaded_files
      : (Array.isArray(details.uploaded_files) ? details.uploaded_files : []);
    const fileNames = Array.isArray(queueData.file_names)
      ? queueData.file_names
      : (Array.isArray(details.file_names) ? details.file_names : []);
    const fileAnalysis = Array.isArray(queueData.file_analysis)
      ? queueData.file_analysis
      : (Array.isArray(details.file_analysis) ? details.file_analysis : []);

    if (uploadedFiles.length) {
      return uploadedFiles.map((file, index) => {
        const analysis = fileAnalysis[index] || {};
        const label = file?.original_name || fileNames[index] || analysis.file_name || file?.saved_path || `File ${index + 1}`;
        return {
          index,
          token: fileToken(file),
          label,
          href: file?.available === false ? "" : (file?.href || file?.download_url || resolveFileHref(file?.saved_path || file?.file_path || "")),
          byte_size: Math.max(0, Number(file?.byte_size) || 0),
          analysis
        };
      });
    }

    if (fileNames.length || fileAnalysis.length) {
      const length = Math.max(fileNames.length, fileAnalysis.length);
      return Array.from({ length }, (_item, index) => {
        const analysis = fileAnalysis[index] || {};
        const label = fileNames[index] || analysis.file_name || `File ${index + 1}`;
        return {
          index,
          token: "",
          label,
          href: resolveFileHref(label),
          byte_size: 0,
          analysis
        };
      });
    }

    const fallbackLabel = String(queueData.file_name || details.file_name || "").trim();
    if (!fallbackLabel) return [];
    return [{
      index: 0,
      token: "",
      label: fallbackLabel,
      href: queueData.file_href || resolveFileHref(fallbackLabel),
      byte_size: 0,
      analysis: {}
    }];
  }

  function keptEditFiles(queueData){
    return existingEditFiles(queueData).filter((file) => {
      if (file.token && editRemovedFileTokens.has(file.token)) return false;
      return !editRemovedFileIndexes.has(file.index);
    });
  }

  function renderExistingEditFiles(queueData){
    const container = document.getElementById("statusEditExistingFiles");
    if (!container) return;
    const files = existingEditFiles(queueData);
    const keptFiles = keptEditFiles(queueData);

    if (!files.length) {
      container.innerHTML = '<p class="status-edit-existing-files__empty">No submitted files are attached yet.</p>';
      return;
    }

    container.innerHTML = `
      <p class="status-edit-existing-files__title">${esc(keptFiles.length)} of ${esc(files.length)} file${files.length === 1 ? "" : "s"} will be kept</p>
      ${files.map((file) => {
        const removed = (file.token && editRemovedFileTokens.has(file.token)) || editRemovedFileIndexes.has(file.index);
        const count = fileAnalysisCount(file);
        const meta = removed
          ? "Marked for removal"
          : (count > 0 ? `${count} page${count === 1 ? "" : "s"} counted` : "Kept unless removed");
        return `
          <div class="status-edit-existing-file">
            <div>
              <div class="status-edit-existing-file__name">
                ${file.href && !removed ? `<a href="${esc(file.href)}" target="_blank" rel="noopener noreferrer">${esc(file.label)}</a>` : esc(file.label)}
              </div>
              <div class="status-edit-existing-file__meta">${esc(meta)}</div>
            </div>
            <button class="status-edit-file-remove" type="button" data-edit-remove-file="${esc(file.token || String(file.index))}" data-edit-remove-index="${esc(file.index)}" data-edit-remove-token="${esc(file.token)}">
              ${removed ? "Undo" : "Remove"}
            </button>
          </div>
        `;
      }).join("")}
    `;
  }

  function editPriceEstimate(queueData){
    const details = queueDetails(queueData);
    const service = serviceKey(queueData);
    const quantity = Math.max(1, toNumber(inputValue("quantity") || queueData.quantity || details.quantity) ?? 1);
    const fileInput = statusEditFields?.querySelector('[name="files"]');
    const addedFiles = Array.from(fileInput?.files || []);
    const keptFiles = keptEditFiles(queueData);
    const paidAmount = toNumber(queueData.paid_amount) ?? 0;
    const rows = [];
    const notes = [];
    let total = null;
    let totalLabel = "To be assessed";

    if (isDocumentPrinting(queueData)) {
      const paperKey = normalizePaperKey(inputValue("paper_size") || queueData.paper_size || details.paper_size);
      const colorKey = normalizeColorKey(inputValue("color_option") || queueData.color_option || details.color_option);
      const prices = pricingMap(queueData, {
        letterFull: 10, letterHalf: 5, letterBw: 5,
        longFull: 10, longHalf: 5, longBw: 5,
        a4Full: 10, a4Half: 5, a4Bw: 5,
      });
      const suffix = colorKey === "full" ? "Full" : (colorKey === "half" ? "Half" : "Bw");
      const unitKey = paperKey && colorKey ? `${paperKey}${suffix}` : "";
      const unitPrice = toNumber(prices[unitKey]) ?? (toNumber(queueData.price_per_page ?? details.price_per_page) ?? 0);
      let totalPages = keptFiles.reduce((sum, file) => sum + fileAnalysisCount(file), 0);
      if (!totalPages && keptFiles.length === existingEditFiles(queueData).length && !editRemovedFileTokens.size && !editRemovedFileIndexes.size) {
        totalPages = toNumber(queueData.total_pages ?? details.total_pages) ?? 0;
      }
      if (addedFiles.length) {
        totalPages += addedFiles.length;
        notes.push("New files are estimated as 1 page each here and will be recalculated after saving.");
      }
      total = unitPrice * quantity * Math.max(0, totalPages);
      totalLabel = toPeso(total);
      rows.push(["Price per page", toPeso(unitPrice)]);
      rows.push(["Pages / files", `${totalPages} page${totalPages === 1 ? "" : "s"} from ${keptFiles.length + addedFiles.length} file${keptFiles.length + addedFiles.length === 1 ? "" : "s"}`]);
      rows.push(["Copies", quantity]);
    } else if (service === "xerox") {
      const paperKey = normalizePaperKey(inputValue("paper_size") || queueData.paper_size || details.paper_size);
      const colorKey = normalizeColorKey(inputValue("color_option") || queueData.color_option || details.color_option || "Colored");
      const prices = pricingMap(queueData, {
        letterColored: 3, letterBw: 3,
        longColored: 5, longBw: 5,
        a4Colored: 3, a4Bw: 3,
      });
      const unitPrice = toNumber(prices[`${paperKey}${colorKey === "bw" ? "Bw" : "Colored"}`]) ?? 0;
      total = unitPrice * quantity;
      totalLabel = toPeso(total);
      rows.push(["Price per copy", toPeso(unitPrice)]);
      rows.push(["Color", colorKey === "bw" ? "Black and White" : "Colored"]);
      rows.push(["Copies", quantity]);
    } else if (service === "rushid") {
      const packageLabel = inputValue("package_label") || queueData.package_label || details.package_label || "";
      const match = String(packageLabel).match(/package\s*([1-6])/i);
      const prices = pricingMap(queueData, {
        package1: 40, package2: 30, package3: 30,
        package4: 50, package5: 30, package6: 50,
      });
      const unitPrice = match ? (toNumber(prices[`package${match[1]}`]) ?? 0) : 0;
      total = unitPrice * quantity;
      totalLabel = toPeso(total);
      rows.push(["Package price", toPeso(unitPrice)]);
      rows.push(["Quantity", quantity]);
      rows.push(["Photos attached", `${keptFiles.length + addedFiles.length}`]);
    } else if (service === "laminating") {
      const type = String(inputValue("lamination_type") || queueData.lamination_type || details.lamination_type || "").toLowerCase();
      const prices = pricingMap(queueData, { thin: 20, thick: 30 });
      const unitPrice = toNumber(prices[type]) ?? 0;
      total = unitPrice * quantity;
      totalLabel = toPeso(total);
      rows.push(["Price per item", toPeso(unitPrice)]);
      rows.push(["Quantity", quantity]);
    } else {
      const catalog = findCatalogService(queueData);
      const range = String(catalog?.price_range || details.price_range || "").trim();
      totalLabel = range || getQueuePriceLabel(queueData);
      rows.push(["Estimate", totalLabel]);
    }

    if (total !== null) {
      rows.push(["Paid amount", toPeso(paidAmount)]);
      rows.push(["Pending after edit", toPeso(Math.max(0, total - paidAmount))]);
    }

    return { totalLabel, rows, notes };
  }

  function renderEditPriceCard(queueData){
    const container = document.getElementById("statusEditPriceCard");
    if (!container) return;
    const estimate = editPriceEstimate(queueData);
    container.innerHTML = `
      <div class="status-edit-price-card__head">
        <p class="status-edit-price-card__title">Price Estimate</p>
        <strong class="status-edit-price-card__total">${esc(estimate.totalLabel)}</strong>
      </div>
      <div class="status-edit-price-card__rows">
        ${estimate.rows.map(([label, value]) => `
          <div class="status-edit-price-row">
            <span>${esc(label)}</span>
            <strong>${esc(value)}</strong>
          </div>
        `).join("")}
      </div>
      ${estimate.notes.map((note) => `<p class="status-edit-price-note">${esc(note)}</p>`).join("")}
    `;
  }

  function refreshEditComputedUI(queueData){
    renderExistingEditFiles(queueData);
    renderEditPriceCard(queueData);
  }

  function renderEditForm(queueData){
    if (!statusEditFields) return;
    const details = queueDetails(queueData);
    const service = serviceKey(queueData);
    const category = categoryKey(queueData);
    const rows = [];

    if (isDocumentPrinting(queueData)) {
      rows.push(editSelect("paper_size", "Paper Size", queueData.paper_size ?? details.paper_size, [
        "Letter",
        "8.5x13",
        "A4",
      ]));
      rows.push(editField("quantity", "Quantity / Copies", queueData.quantity ?? details.quantity ?? 1, "number", 'min="1" step="1" inputmode="numeric"'));
      rows.push(editSelect("color_option", "Color Option", queueData.color_option ?? details.color_option, [
        "Black & White",
        "Full Colored",
        "Half Colored",
      ]));
    } else if (service === "rushid") {
      rows.push(editSelect("package_label", "Package", queueData.package_label ?? details.package_label, [
        "Package 1 - PHP 40",
        "Package 2 - PHP 30",
        "Package 3 - PHP 30",
        "Package 4 - PHP 50",
        "Package 5 - PHP 30",
        "Package 6 - PHP 50",
      ]));
      rows.push(editField("quantity", "Quantity", queueData.quantity ?? details.quantity ?? 1, "number", 'min="1" step="1" inputmode="numeric"'));
    } else if (service === "xerox") {
      rows.push(editSelect("paper_size", "Paper Size", queueData.paper_size ?? details.paper_size, [
        "Letter",
        "8.5x13",
        "A4",
      ]));
      rows.push(editSelect("color_option", "Color Option", queueData.color_option ?? details.color_option ?? "Colored", [
        "Colored",
        "Black & White",
      ]));
      rows.push(editField("quantity", "Quantity", queueData.quantity ?? details.quantity ?? 1, "number", 'min="1" step="1" inputmode="numeric"'));
    } else if (service === "laminating") {
      rows.push(editSelect("lamination_type", "Lamination", queueData.lamination_type ?? details.lamination_type, [
        ["thin", "Thin"],
        ["thick", "Thick"],
      ]));
      rows.push(editField("quantity", "Quantity", queueData.quantity ?? details.quantity ?? 1, "number", 'min="1" step="1" inputmode="numeric"'));
    } else if (category === "repair" || category === "installation") {
      rows.push(editField("device_type", "Device", queueData.device_type ?? details.device_type ?? ""));
    }

    if (isOnlineDocumentPrinting(queueData)) {
      rows.push(editSelect("payment_method", "Payment Method", queueData.payment_method || details.payment_method || "cash", [
        ["cash", "Cash / Pay at Store"],
        ["gcash", "GCash"],
      ]));
      rows.push(editField("reference_number", "GCash Reference", queueData.reference_number || details.reference_number || "", "text", 'maxlength="13" inputmode="numeric"'));
    }

    rows.push(editFileField(queueData));
    rows.push(editTextarea("notes", "Notes", queueData.notes ?? details.notes ?? ""));
    rows.push('<section id="statusEditPriceCard" class="status-edit-price-card" aria-live="polite"></section>');
    statusEditFields.innerHTML = rows.join("");
    editUploadTasks = {};
    renderEditUploadProgress();
    refreshEditComputedUI(queueData);
    ensureServiceCatalog(queueData).then(() => {
      if (currentDetailQueue && currentDetailQueue.id === queueData.id) {
        renderEditPriceCard(currentDetailQueue);
      }
    });
    if (statusEditHelp) {
      statusEditHelp.textContent = supportsFileUpload(queueData)
        ? "Existing files stay attached unless you remove them. New uploads are added to the kept files."
        : "";
    }
    if (statusEditError) statusEditError.textContent = "";
  }

  function renderAdminSendBack(queueData){
    const message = String(queueData?.send_back_message || "").trim();
    const visible = message !== "" && hasActiveSendBack(queueData);
    if (adminSendBackSection) adminSendBackSection.hidden = !visible;
    if (adminSendBackMessage) adminSendBackMessage.textContent = message;
    if (editAdminMessage) editAdminMessage.textContent = message || "No admin message was provided.";
  }

  function syncModalModeState(){
    const queueCode = String(currentDetailQueue?.queue_code || "").trim();
    statusModal?.classList.remove("status-modal--editing");
    if (modalEyebrow) {
      modalEyebrow.textContent = "Queue Details";
    }
    if (modalTitleText) {
      modalTitleText.textContent = queueCode ? `Queue ${queueCode}` : "Queue Details";
    }
  }

  function setEditMode(enabled){
    editMode = Boolean(enabled) && canCustomerEdit(currentDetailQueue);
    syncModalModeState();
    if (editQueueBtn) editQueueBtn.hidden = editMode || !canCustomerEdit(currentDetailQueue);
    if (cancelPendingQueueBtn) cancelPendingQueueBtn.hidden = editMode || !canCustomerCancel(currentDetailQueue?.status);
    if (!editMode) {
      closeEditRequestModal();
      renderCancellationAction(currentDetailQueue || {}, currentDetailQueue?.status || "PENDING");
      return;
    }

    if (editRequestQueueTitle) {
      const queueCode = String(currentDetailQueue?.queue_code || "").trim();
      editRequestQueueTitle.textContent = queueCode ? `Edit Request ${queueCode}` : "Edit Request";
    }
    if (editAdminMessage) {
      editAdminMessage.textContent = String(currentDetailQueue?.send_back_message || "").trim() || "No admin message was provided.";
    }
    editRemovedFileTokens = new Set();
    editRemovedFileIndexes = new Set();
    renderEditForm(currentDetailQueue || {});
    openEditRequestModal();
  }

  function openEditRequestModal(){
    if (!editRequestModal) return;
    editRequestModal.classList.add("is-open");
    editRequestModal.setAttribute("aria-hidden", "false");
    document.removeEventListener("keydown", onModalKeydown);
    document.addEventListener("keydown", onEditModalKeydown);
    window.setTimeout(() => {
      const firstField = statusEditFields?.querySelector("input, select, textarea");
      (firstField || saveEditQueueBtn || editRequestDialog)?.focus?.({ preventScroll: true });
    }, 0);
  }

  function closeEditRequestModal(){
    if (!editRequestModal || !editRequestModal.classList.contains("is-open")) return;
    if (activeEditUploadSession) {
      activeEditUploadSession.cancelAll();
      activeEditUploadSession = null;
    }
    editRequestModal.classList.remove("is-open");
    editRequestModal.setAttribute("aria-hidden", "true");
    document.removeEventListener("keydown", onEditModalKeydown);
    if (detailModal?.classList.contains("is-open")) {
      document.addEventListener("keydown", onModalKeydown);
      editQueueBtn?.focus?.({ preventScroll: true });
    }
  }

  async function uploadEditFiles(fileInput, queueData){
    const files = Array.from(fileInput?.files || []);
    if (!files.length) return [];
    if (!window.ServitechUpload) {
      throw new Error("Upload progress support could not be loaded. Please refresh and try again.");
    }

    editUploadTasks = {};
    fileInput.disabled = true;
    activeEditUploadSession = window.ServitechUpload.start(files, {
      context: serviceKey(queueData) === "rushid" ? "rush_id" : "",
      onChange: (tasks) => {
        editUploadTasks = {};
        tasks.forEach((task) => {
          editUploadTasks[task.key] = task;
        });
        renderEditUploadProgress();
      }
    });
    const result = await activeEditUploadSession.promise;
    activeEditUploadSession = null;
    fileInput.disabled = false;

    if (!result.ok) {
      const cancelledKeys = new Set(
        result.tasks.filter((task) => task.status === "cancelled").map((task) => task.key)
      );
      if (cancelledKeys.size) {
        const transfer = new DataTransfer();
        files.forEach((file) => {
          if (!cancelledKeys.has(window.ServitechUpload.fileKey(file))) transfer.items.add(file);
        });
        fileInput.files = transfer.files;
      }
      renderEditUploadProgress();
      throw new Error(result.error || "Unable to upload replacement files.");
    }
    return Array.isArray(result.uploaded_files) ? result.uploaded_files : [];
  }

  async function saveCurrentEdit(){
    if (!currentDetailQueue?.id || editInProgress || !canCustomerEdit(currentDetailQueue)) return;
    editInProgress = true;
    if (saveEditQueueBtn) {
      saveEditQueueBtn.disabled = true;
      saveEditQueueBtn.textContent = "Saving...";
    }
    if (cancelEditQueueBtn) cancelEditQueueBtn.disabled = true;
    if (statusEditError) statusEditError.textContent = "";

    let uploadedFiles = [];
    try {
      uploadedFiles = await uploadEditFiles(statusEditFields?.querySelector('[name="files"]'), currentDetailQueue);
      const payload = {
        queue_id: currentDetailQueue.id,
        paper_size: inputValue("paper_size"),
        quantity: inputValue("quantity"),
        color_option: inputValue("color_option"),
        package_label: inputValue("package_label"),
        lamination_type: inputValue("lamination_type"),
        device_type: inputValue("device_type"),
        notes: inputValue("notes"),
        payment_method: inputValue("payment_method"),
        reference_number: inputValue("reference_number"),
        uploaded_files: uploadedFiles,
        removed_file_tokens: Array.from(editRemovedFileTokens),
        removed_file_indexes: Array.from(editRemovedFileIndexes)
      };

      const response = await fetch(servitechUrl("/api/queue_update_details.php"), {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-Token": window.servitechCsrfToken ? window.servitechCsrfToken() : ""
        },
        body: JSON.stringify(payload)
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) {
        throw new Error(data.error || "Unable to save changes.");
      }

      showCustomerToast(
        data.toast_message || `Queue ${data.queue_code || currentDetailQueue.queue_code || ""} updated successfully.`,
        "success"
      );
      closeDetailModal();
      await loadQueues();
    } catch (error) {
      if (uploadedFiles.length && window.ServitechUpload) {
        await window.ServitechUpload.cleanup(uploadedFiles);
      }
      const message = error.message || "Unable to save changes.";
      if (statusEditError) statusEditError.textContent = message;
      showCustomerToast(message, "error");
    } finally {
      editInProgress = false;
      if (saveEditQueueBtn) {
        saveEditQueueBtn.disabled = false;
        saveEditQueueBtn.textContent = "Save Changes";
      }
      if (cancelEditQueueBtn) cancelEditQueueBtn.disabled = false;
    }
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

  function renderListState(targetEl, message, actionHtml){
    if (!targetEl) return;
    targetEl.innerHTML = `
      <div class="status-empty-state">
        <p class="muted">${esc(message)}</p>
        ${actionHtml || ""}
      </div>
    `;
  }

  function renderState(message, actionHtml){
    renderListState(listEl, message, actionHtml);
  }

  function updateCounts(activeCount, archiveCount){
    if (activeQueueCount) activeQueueCount.textContent = `${activeCount} Active`;
    if (archiveQueueCount) archiveQueueCount.textContent = `${archiveCount} Completed`;
  }

  function switchStatusTab(tab){
    selectedStatusTab = tab === "completed" ? "completed" : "active";
    const completed = selectedStatusTab === "completed";

    if (activeQueuePanel) activeQueuePanel.hidden = completed;
    if (completedQueuePanel) completedQueuePanel.hidden = !completed;
    if (activeQueuesTab) {
      activeQueuesTab.classList.toggle("is-active", !completed);
      activeQueuesTab.setAttribute("aria-selected", completed ? "false" : "true");
    }
    if (completedQueuesTab) {
      completedQueuesTab.classList.toggle("is-active", completed);
      completedQueuesTab.setAttribute("aria-selected", completed ? "true" : "false");
    }
  }

  function queueMatchesFilters(queueData){
    const categoryValue = categoryFilter?.value || "";
    const statusValue = statusFilter?.value || "";
    const dateValue = dateFilter?.value || "";

    if (categoryValue && filterCategoryKey(queueData) !== categoryValue) return false;
    if (statusValue && normalizeStatus(queueData.status) !== statusValue) return false;
    if (dateValue && dateInputValue(queueData.created_at) !== dateValue) return false;

    return true;
  }

  function attachQueueCardEvents(card){
    card.addEventListener("click", () => openDetail(card));
    card.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        openDetail(card);
      }
    });
  }

  function renderQueueList(targetEl, queues, emptyMessage){
    if (!targetEl) return;
    targetEl.innerHTML = "";

    if (!queues.length) {
      renderListState(targetEl, emptyMessage);
      return;
    }

    queues.forEach((q) => {
      const card = buildCard(q);
      targetEl.appendChild(card);
      attachQueueCardEvents(card);
    });
  }

  function renderFilteredQueues(){
    const filteredQueues = allQueues.filter(queueMatchesFilters);
    const activeQueues = filteredQueues.filter((q) => !isArchivedStatus(q.status));
    const archivedQueues = filteredQueues.filter((q) => isArchivedStatus(q.status));
    const hasFilters = !!(categoryFilter?.value || statusFilter?.value || dateFilter?.value);

    updateCounts(activeQueues.length, archivedQueues.length);
    switchStatusTab(selectedStatusTab);
    renderQueueList(
      listEl,
      activeQueues,
      hasFilters ? "No active queues match your filters." : "No active queues right now."
    );
    renderQueueList(
      archiveListEl,
      archivedQueues,
      hasFilters ? "No completed queues match your filters." : "No completed queues yet."
    );
  }

  function buildCard(q){
    const div = document.createElement("div");
    const status = normalizeStatus(q.status);
    const tone = badgeTone(status);
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
    div.dataset.status = status || "";
    div.dataset.paymentMethod = q.payment_method || q.details?.payment_method || "";
    div.dataset.referenceNumber = q.reference_number || q.details?.reference_number || "";
    div.queueData = q;

    div.innerHTML = `
      <div class="queue-card__head">
        <div class="queue-card__code">${esc(q.queue_code)}</div>
        <div class="status-badge queue-card__badge status-${tone} queue-card__badge--${tone}">${esc(status || "PENDING")}</div>
      </div>
      <hr class="queue-card__divider">
      <p class="queue-card__meta">
        <strong>${esc(isDocumentPrinting(q) ? "Document Print" : formatServiceLabel(q.service_label || "Service"))}</strong>
        <small>${esc(isDocumentPrinting(q) ? "Print" : formatLabel(q.category || ""))}</small>
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
    const snapshotPrice = toNumber(details.price_snapshot);
    if ((serviceLower.includes("xerox") || serviceLower.includes("photocopy")) && snapshotPrice !== null) {
      return toPeso(snapshotPrice * quantity);
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

  function trapEditModalFocus(e){
    if (!editRequestDialog || e.key !== "Tab") return;
    const focusables = editRequestDialog.querySelectorAll('button, [href], textarea, input, select, [tabindex]:not([tabindex="-1"])');
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
    editMode = false;
    closeEditRequestModal();
    syncModalModeState();
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

  function onEditModalKeydown(e){
    if (e.key === "Escape") {
      e.preventDefault();
      setEditMode(false);
      return;
    }
    trapEditModalFocus(e);
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

  function renderCancellationAction(queueData, status){
    if (!cancelPendingQueueBtn) return;

    const allowed = queueData && queueData.id && canCustomerCancel(status) && !editMode;
    cancelPendingQueueBtn.hidden = !allowed;
    cancelPendingQueueBtn.disabled = false;
    cancelPendingQueueBtn.textContent = "Cancel Request";

    if (modalCancelMessage) {
      modalCancelMessage.hidden = !allowed;
      modalCancelMessage.textContent = allowed
        ? "You can cancel this request while it is still pending."
        : "";
    }
  }

  function renderEditAction(queueData, status){
    const allowed = queueData && queueData.id && canCustomerEdit(queueData);
    if (editQueueBtn) {
      editQueueBtn.hidden = !allowed;
      editQueueBtn.disabled = false;
    }
  }

  async function cancelCurrentPendingQueue(){
    if (!currentDetailQueue?.id || cancellationInProgress) return;
    if (!canCustomerCancel(currentDetailQueue.status)) {
      if (modalCancelMessage) {
        modalCancelMessage.hidden = false;
        modalCancelMessage.textContent = "Only pending requests can be cancelled.";
      }
      return;
    }

    if (!window.confirm("Cancel this pending request?")) {
      return;
    }

    cancellationInProgress = true;
    if (cancelPendingQueueBtn) {
      cancelPendingQueueBtn.disabled = true;
      cancelPendingQueueBtn.textContent = "Cancelling...";
    }
    if (modalCancelMessage) {
      modalCancelMessage.hidden = false;
      modalCancelMessage.textContent = "Cancelling request...";
    }

    try {
      const res = await fetch(servitechUrl("/api/queue_cancel_request.php"), {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-Token": window.servitechCsrfToken ? window.servitechCsrfToken() : ""
        },
        body: JSON.stringify({ queue_id: currentDetailQueue.id })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.error || "Unable to cancel this request.");
      }

      closeDetailModal();
      switchStatusTab("completed");
      await loadQueues();
    } catch (error) {
      if (modalCancelMessage) {
        modalCancelMessage.hidden = false;
        modalCancelMessage.textContent = error.message || "Unable to cancel this request.";
      }
      if (cancelPendingQueueBtn) {
        cancelPendingQueueBtn.disabled = false;
        cancelPendingQueueBtn.textContent = "Cancel Request";
      }
    } finally {
      cancellationInProgress = false;
    }
  }

  function openDetail(card){
    const queueData = card.queueData || {};
    const status = (card.dataset.status || "PENDING").toUpperCase();
    currentDetailQueue = queueData;
    editMode = false;
    editRemovedFileTokens = new Set();
    editRemovedFileIndexes = new Set();
    syncModalModeState();

    if (modalTitleText) modalTitleText.textContent = card.dataset.queue ? `Queue ${card.dataset.queue}` : "Queue Details";
    document.getElementById("modalQueueRef").textContent = card.dataset.queue || "Not available";
    document.getElementById("modalType").textContent = isDocumentPrinting(queueData)
      ? "Print"
      : formatLabel(card.dataset.type || "");
    document.getElementById("modalService").textContent = isDocumentPrinting(queueData)
      ? "Document Print"
      : (card.dataset.service || "");
    document.getElementById("modalSubmittedAt").textContent = formatDateTime(queueData.created_at);
    const completedRow = document.getElementById("modalCompletedAtRow");
    const completedAt = document.getElementById("modalCompletedAt");
    const showCompletedAt = status === "DONE" && !!queueData.updated_at;
    if (completedAt) completedAt.textContent = showCompletedAt ? formatDateTime(queueData.updated_at) : "";
    if (completedRow) completedRow.hidden = !showCompletedAt;
    const notesValue = card.dataset.notes || "";
    const notesWrap = document.getElementById("modalNotesWrap");
    document.getElementById("modalNotes").value = notesValue;
    if (notesWrap) notesWrap.hidden = notesValue.trim() === "";
    document.getElementById("modalPrice").textContent = getQueuePriceLabel(queueData);
    renderAttachedFiles(queueData);
    renderPaymentDetails(queueData);
    renderCancellationAction(queueData, status);
    renderEditAction(queueData, status);
    renderAdminSendBack(queueData);
    renderEditForm(queueData);

    const statusEl = document.getElementById("modalStatus");
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

    const targetCard = panelEl?.querySelector('[data-queue-id="' + String(requestedId) + '"]');
    if (!targetCard) {
      return;
    }

    if (targetCard.closest("#completedQueuePanel")) {
      switchStatusTab("completed");
    } else {
      switchStatusTab("active");
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
    renderListState(archiveListEl, "Loading completed queues...");
    updateCounts(0, 0);

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
    if (archiveListEl) archiveListEl.innerHTML = "";

    if (!data.ok) {
      renderState(data.error || "Unable to load your queue list.", '<button id="retryQueuesBtn" type="button" class="btn-next">Retry</button>');
      renderListState(archiveListEl, "Completed queues could not be loaded.");
      return;
    }

    if (!data.queues || data.queues.length === 0) {
      allQueues = [];
      renderState("No queues yet.", '<a href="/pages/customer/customer_dash.php" class="btn-next">Join Queue</a>');
      renderListState(archiveListEl, "No completed queues yet.");
      return;
    }

    allQueues = data.queues;
    renderFilteredQueues();
    maybeOpenRequestedQueue();
  }

  [closeDetail, modalCloseBtn].forEach(btn => {
    if (btn) btn.addEventListener("click", closeDetailModal);
  });
  closeEditRequest?.addEventListener("click", () => setEditMode(false));
  cancelPendingQueueBtn?.addEventListener("click", cancelCurrentPendingQueue);
  editQueueBtn?.addEventListener("click", () => {
    setEditMode(true);
  });
  cancelEditQueueBtn?.addEventListener("click", () => {
    setEditMode(false);
    renderCancellationAction(currentDetailQueue || {}, currentDetailQueue?.status || "PENDING");
  });
  saveEditQueueBtn?.addEventListener("click", saveCurrentEdit);

  statusEditFields?.addEventListener("click", (event) => {
    const uploadButton = event.target?.closest?.("[data-edit-upload-action]");
    if (uploadButton) {
      const key = String(uploadButton.dataset.editUploadKey || "");
      if (uploadButton.dataset.editUploadAction === "cancel" && activeEditUploadSession) {
        activeEditUploadSession.cancel(key);
        return;
      }
      const input = statusEditFields?.querySelector('[name="files"]');
      if (input) {
        const transfer = new DataTransfer();
        Array.from(input.files || []).forEach((file) => {
          if ((window.ServitechUpload?.fileKey(file) || "") !== key) transfer.items.add(file);
        });
        input.files = transfer.files;
        delete editUploadTasks[key];
        renderEditUploadProgress();
        if (currentDetailQueue) renderEditPriceCard(currentDetailQueue);
      }
      return;
    }

    const button = event.target?.closest?.("[data-edit-remove-file]");
    if (!button || !currentDetailQueue) return;
    const token = String(button.dataset.editRemoveToken || "").trim();
    const index = Number(button.dataset.editRemoveIndex);
    if (token) {
      if (editRemovedFileTokens.has(token)) {
        editRemovedFileTokens.delete(token);
      } else {
        editRemovedFileTokens.add(token);
      }
    } else if (Number.isInteger(index)) {
      if (editRemovedFileIndexes.has(index)) {
        editRemovedFileIndexes.delete(index);
      } else {
        editRemovedFileIndexes.add(index);
      }
    }
    refreshEditComputedUI(currentDetailQueue);
  });

  statusEditFields?.addEventListener("input", () => {
    if (currentDetailQueue) renderEditPriceCard(currentDetailQueue);
  });

  statusEditFields?.addEventListener("change", (event) => {
    if (event.target?.matches?.('[name="files"]')) {
      const errors = validateEditFileSelection(event.target, currentDetailQueue);
      editUploadTasks = {};
      renderEditUploadProgress();
      if (errors.length) {
        const message = errors.join(" ");
        if (statusEditError) statusEditError.textContent = message;
        showCustomerToast(message, "error");
      } else if (statusEditError) {
        statusEditError.textContent = "";
      }
    }
    if (currentDetailQueue) renderEditPriceCard(currentDetailQueue);
  });

  if (detailModal) {
    detailModal.addEventListener("click", (e) => {
      if (e.target === detailModal) closeDetailModal();
    });
  }

  if (editRequestModal) {
    editRequestModal.addEventListener("click", (e) => {
      if (e.target === editRequestModal) setEditMode(false);
    });
  }

  [categoryFilter, statusFilter, dateFilter].forEach((control) => {
    control?.addEventListener("change", () => {
      if (control === statusFilter && statusFilter?.value) {
        switchStatusTab(isArchivedStatus(statusFilter.value) ? "completed" : "active");
      }
      renderFilteredQueues();
    });
  });

  activeQueuesTab?.addEventListener("click", () => switchStatusTab("active"));
  completedQueuesTab?.addEventListener("click", () => switchStatusTab("completed"));

  clearFiltersBtn?.addEventListener("click", () => {
    if (categoryFilter) categoryFilter.value = "";
    if (statusFilter) statusFilter.value = "";
    if (dateFilter) dateFilter.value = "";
    switchStatusTab("active");
    renderFilteredQueues();
  });

  panelEl?.addEventListener("click", (e) => {
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

