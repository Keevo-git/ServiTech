<div class="queue-details-overlay" id="queueDetailsOverlay"></div>

<div
  class="queue-details-modal"
  id="queueDetailsModal"
  role="dialog"
  aria-modal="true"
  aria-labelledby="queueDetailsTitle"
  aria-hidden="true"
  data-action-update-url="<?= htmlspecialchars(admin_url_raw('/pages/admin/queue_update_status.php'), ENT_QUOTES, 'UTF-8') ?>"
  data-send-back-url="<?= htmlspecialchars(admin_url_raw('/pages/admin/queue_send_back.php'), ENT_QUOTES, 'UTF-8') ?>"
>
  <div class="queue-details-head">
    <div class="queue-details-title-wrap">
      <p>Queue Details</p>
      <h3 id="queueDetailsTitle">Queue Details</h3>
    </div>
    <button class="queue-details-close" type="button" id="queueDetailsClose" aria-label="Close queue details">&times;</button>
  </div>

  <div class="queue-details-body">
    <div class="queue-details-columns">
      <div class="queue-details-info">
        <div class="queue-details-summary" id="queueDetailsSummary"></div>
        <div class="queue-details-list" id="queueDetailsList"></div>
      </div>

      <div class="queue-details-admin-panel">
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

        <div class="queue-details-action-stack">
          <div class="queue-details-actions">
            <button class="queue-details-action queue-details-action--light" type="button" id="queueDetailsCancel">Cancel</button>
          </div>

          <button class="queue-details-action queue-details-action--primary queue-details-update" type="button" id="queueDetailsUpdate" disabled>Update</button>
          <p class="queue-details-update-feedback" id="queueDetailsUpdateFeedback" role="status" aria-live="polite" hidden></p>

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
    </div>
  </div>
</div>

<div class="queue-sendback-overlay" id="queueSendBackOverlay" hidden></div>
<div class="queue-sendback-modal" id="queueSendBackModal" role="dialog" aria-modal="true" aria-labelledby="queueSendBackTitle" aria-hidden="true" hidden>
  <div class="queue-sendback-head">
    <div>
      <p>Customer Revision</p>
      <h3 id="queueSendBackTitle">Send Back to Customer</h3>
    </div>
    <button class="queue-sendback-close" type="button" id="queueSendBackClose" aria-label="Close send back modal">&times;</button>
  </div>
  <div class="queue-sendback-body">
    <p class="queue-sendback-copy">Add a message or comment to tell the customer what needs to be edited.</p>
    <label class="queue-sendback-field" for="queueSendBackMessage">
      <span>Message / Comments</span>
      <textarea id="queueSendBackMessage" rows="5" maxlength="1000"></textarea>
    </label>
    <p class="queue-sendback-error" id="queueSendBackError" role="alert"></p>
    <div class="queue-sendback-actions">
      <button class="queue-details-action queue-details-action--light" type="button" id="queueSendBackCancel">Cancel</button>
      <button class="queue-details-action queue-details-action--primary" type="button" id="queueSendBackSubmit">Send</button>
    </div>
  </div>
</div>
