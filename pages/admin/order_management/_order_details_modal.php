<div class="order-modal-overlay" id="orderModalOverlay"></div>

<div class="order-modal" id="orderModal" role="dialog" aria-modal="true" aria-labelledby="orderModalTitle" aria-hidden="true">
  <div class="modal-header">
    <div>
      <p class="modal-eyebrow" id="orderModalService">Order Details</p>
      <h3 id="orderModalTitle">Order Details</h3>
    </div>
    <button class="close-modal" type="button" id="orderModalClose" aria-label="Close order details">&times;</button>
  </div>

  <div class="modal-body">
    <div class="order-modal-summary" id="orderModalSummary"></div>
    <div class="order-modal-details" id="orderModalDetails"></div>

    <label class="order-modal-field" for="omComments">
      <span>Additional Comments</span>
      <textarea id="omComments" rows="4" readonly></textarea>
    </label>

    <section class="order-status-section" aria-labelledby="omStatusSectionTitle">
      <div class="order-current-status">
        <span id="omStatusSectionTitle">Current Status</span>
        <strong class="status-badge status-pending" id="omCurrentStatus">Pending</strong>
      </div>

      <div class="order-status-divider" aria-hidden="true"></div>

      <label class="order-modal-field" for="omStatus">
        <span>Update Status</span>
        <select class="om-select" id="omStatus">
          <option value="">Loading allowed statuses...</option>
        </select>
      </label>

      <p class="order-status-help" id="omStatusHelp"></p>
    </section>

    <div class="om-error" id="omError" role="alert"></div>

    <div class="order-modal-actions">
      <button class="om-btn om-btn--light" type="button" id="omCancel">Cancel</button>
      <button class="om-btn om-btn--primary" type="button" id="omSave">Update</button>
    </div>

    <button
      class="btn-message order-modal-message"
      type="button"
      id="orderModalMessage"
      data-id=""
      data-queue-code=""
      data-customer=""
      data-service=""
      hidden
    >Message Customer</button>
  </div>
</div>
