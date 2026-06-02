<div class="queue-details-overlay" id="queueDetailsOverlay"></div>

<div class="queue-details-modal" id="queueDetailsModal" role="dialog" aria-modal="true" aria-labelledby="queueDetailsTitle" aria-hidden="true">
  <div class="queue-details-head">
    <div>
      <p>Queue Details</p>
      <h3 id="queueDetailsTitle">Queue Details</h3>
    </div>
    <button class="queue-details-close" type="button" id="queueDetailsClose" aria-label="Close queue details">&times;</button>
  </div>

  <div class="queue-details-body">
    <div class="queue-details-summary" id="queueDetailsSummary"></div>
    <div class="queue-details-list" id="queueDetailsList"></div>

    <section
      class="queue-payment-section"
      aria-labelledby="queueDetailsPaymentTitle"
      data-payment-update-url="<?= htmlspecialchars(admin_url_raw('/pages/admin/payment_update.php'), ENT_QUOTES, 'UTF-8') ?>"
    >
      <h4 id="queueDetailsPaymentTitle">Payment</h4>
      <div class="queue-payment-grid">
        <label class="queue-details-field" for="queueDetailsPrice">
          <span>Price</span>
          <input class="queue-details-input" id="queueDetailsPrice" type="number" min="0" step="0.01" inputmode="decimal">
        </label>
        <label class="queue-details-field" for="queueDetailsPaidAmount">
          <span>Paid Amount</span>
          <input class="queue-details-input" id="queueDetailsPaidAmount" type="number" min="0" step="0.01" inputmode="decimal">
        </label>
      </div>
      <div class="queue-payment-pending">
        <span>Paid Pending</span>
        <strong id="queueDetailsPaidPending">PHP 0.00</strong>
      </div>
      <p class="queue-payment-help" id="queueDetailsPaymentHelp"></p>
      <p class="queue-payment-error" id="queueDetailsPaymentError" role="alert"></p>
      <button class="queue-details-action queue-details-action--primary queue-payment-update" type="button" id="queueDetailsPaymentUpdate">Update Payment</button>
    </section>

    <section class="queue-status-section" aria-labelledby="queueDetailsStatusTitle">
      <div class="queue-current-status">
        <span id="queueDetailsStatusTitle">Current Status</span>
        <strong class="status-badge status-pending" id="queueDetailsCurrentStatus">Pending</strong>
      </div>

      <div class="queue-status-divider" aria-hidden="true"></div>

      <label class="queue-details-field" for="queueDetailsStatus">
        <span>Update Status</span>
        <select class="queue-details-select" id="queueDetailsStatus">
          <option value="">Loading allowed statuses...</option>
        </select>
      </label>

      <p class="queue-status-help" id="queueDetailsStatusHelp"></p>
    </section>

    <div class="queue-details-actions">
      <button class="queue-details-action queue-details-action--light" type="button" id="queueDetailsCancel">Cancel</button>
      <button class="queue-details-action queue-details-action--primary" type="button" id="queueDetailsUpdate" disabled>Update</button>
    </div>

    <button
      class="btn-message queue-details-message"
      type="button"
      id="queueDetailsMessage"
      data-id=""
      data-queue-code=""
      data-customer=""
      data-service=""
      hidden
    >Message Customer</button>
  </div>
</div>
