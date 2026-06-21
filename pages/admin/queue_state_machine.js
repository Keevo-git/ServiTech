(function () {
  "use strict";

  let activeResolver = null;

  function stack() {
    return window.servitechAdminModalStack;
  }

  function closeOverlay(id) {
    const overlay = document.getElementById(id);
    if (!overlay) return;
    stack()?.close(overlay);
    overlay.hidden = true;
  }

  function resolveDialog(value = null) {
    closeOverlay("queueCancellationReasonOverlay");
    closeOverlay("queueCancellationOverlay");
    if (activeResolver) {
      const resolve = activeResolver;
      activeResolver = null;
      resolve(value);
    }
  }

  function showReasonStep() {
    const overlay = document.getElementById("queueCancellationReasonOverlay");
    const dialog = overlay?.querySelector(".queue-cancellation-dialog");
    const textarea = document.getElementById("queueCancellationReason");
    const error = document.getElementById("queueCancellationError");
    if (!overlay || !dialog) return;
    if (textarea) textarea.value = "";
    if (error) error.textContent = "";
    overlay.hidden = false;
    stack()?.open({
      overlay,
      dialog,
      focus: textarea,
      onEscape: () => resolveDialog(null),
    });
  }

  function showWarningStep() {
    const overlay = document.getElementById("queueCancellationOverlay");
    const dialog = overlay?.querySelector(".queue-cancellation-dialog");
    const noButton = overlay?.querySelector("[data-cancellation-no]");
    if (!overlay || !dialog) return;
    overlay.hidden = false;
    stack()?.open({
      overlay,
      dialog,
      focus: noButton,
      onEscape: () => resolveDialog(null),
    });
  }

  function ensureDialogs() {
    if (document.getElementById("queueCancellationOverlay")) return;

    const warningOverlay = document.createElement("div");
    warningOverlay.className = "queue-cancellation-overlay";
    warningOverlay.id = "queueCancellationOverlay";
    warningOverlay.hidden = true;
    warningOverlay.innerHTML = `
      <div class="queue-cancellation-dialog" role="dialog" aria-modal="true" aria-labelledby="queueCancellationTitle">
        <div class="queue-cancellation-head">
          <div>
            <p>Order Status</p>
            <h3 id="queueCancellationTitle">Cancel Order?</h3>
          </div>
          <button type="button" class="queue-cancellation-close" data-cancellation-no aria-label="Close">&times;</button>
        </div>
        <div class="queue-cancellation-body">
          <p>This order is going to be cancelled. Do you want to continue?</p>
          <div class="queue-cancellation-actions">
            <button type="button" class="queue-cancellation-btn queue-cancellation-btn--secondary" data-cancellation-no>No</button>
            <button type="button" class="queue-cancellation-btn queue-cancellation-btn--primary" data-cancellation-yes>Yes</button>
          </div>
        </div>
      </div>
    `;

    const reasonOverlay = document.createElement("div");
    reasonOverlay.className = "queue-cancellation-overlay";
    reasonOverlay.id = "queueCancellationReasonOverlay";
    reasonOverlay.hidden = true;
    reasonOverlay.innerHTML = `
      <div class="queue-cancellation-dialog" role="dialog" aria-modal="true" aria-labelledby="queueCancellationReasonTitle">
        <div class="queue-cancellation-head">
          <div>
            <p>Order Status</p>
            <h3 id="queueCancellationReasonTitle">Cancellation Reason</h3>
          </div>
          <button type="button" class="queue-cancellation-close" data-cancellation-back aria-label="Close">&times;</button>
        </div>
        <div class="queue-cancellation-body">
          <p>Enter the reason that will be sent to the customer.</p>
          <textarea id="queueCancellationReason" rows="5" maxlength="1000" placeholder="Reason for cancellation" required></textarea>
          <p class="queue-cancellation-error" id="queueCancellationError" role="alert"></p>
          <div class="queue-cancellation-actions">
            <button type="button" class="queue-cancellation-btn queue-cancellation-btn--secondary" data-cancellation-back>Back</button>
            <button type="button" class="queue-cancellation-btn queue-cancellation-btn--primary" data-cancellation-submit>Cancel Order</button>
          </div>
        </div>
      </div>
    `;

    document.body.append(warningOverlay, reasonOverlay);

    warningOverlay.querySelectorAll("[data-cancellation-no]").forEach((button) => {
      button.addEventListener("click", () => resolveDialog(null));
    });
    warningOverlay.querySelector("[data-cancellation-yes]")?.addEventListener("click", showReasonStep);
    warningOverlay.addEventListener("click", (event) => {
      if (event.target === warningOverlay) resolveDialog(null);
    });

    reasonOverlay.querySelectorAll("[data-cancellation-back]").forEach((button) => {
      button.addEventListener("click", () => resolveDialog(null));
    });
    reasonOverlay.addEventListener("click", (event) => {
      if (event.target === reasonOverlay) resolveDialog(null);
    });
    reasonOverlay.querySelector("[data-cancellation-submit]")?.addEventListener("click", () => {
      const reason = String(document.getElementById("queueCancellationReason")?.value || "").trim();
      const error = document.getElementById("queueCancellationError");
      if (!reason) {
        if (error) error.textContent = "Cancellation reason is required.";
        window.servitechAdminToast?.warning("Cancellation reason is required.");
        return;
      }
      resolveDialog(reason);
    });
  }

  window.servitechRequestCancellationReason = function () {
    ensureDialogs();
    showWarningStep();

    return new Promise((resolve) => {
      activeResolver = resolve;
    });
  };
})();

(function () {
  "use strict";

  window.servitechRequestApprovalConfirmation = function (queueCode, paymentLabel) {
    let overlay = document.getElementById("queueApprovalOverlay");
    const label = String(paymentLabel || "GCash").trim() || "GCash";
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.className = "queue-cancellation-overlay";
      overlay.id = "queueApprovalOverlay";
      overlay.hidden = true;
      overlay.innerHTML = `
        <div class="queue-cancellation-dialog" role="dialog" aria-modal="true" aria-labelledby="queueApprovalTitle">
          <div class="queue-cancellation-head">
            <div><p>Payment Review</p><h3 id="queueApprovalTitle">Approve payment?</h3></div>
            <button type="button" class="queue-cancellation-close" data-approval-no aria-label="Close">&times;</button>
          </div>
          <div class="queue-cancellation-body">
            <p data-approval-message></p>
            <div class="queue-cancellation-actions">
              <button type="button" class="queue-cancellation-btn queue-cancellation-btn--secondary" data-approval-no>Go back</button>
              <button type="button" class="queue-cancellation-btn queue-cancellation-btn--primary" data-approval-yes>Approve payment</button>
            </div>
          </div>
        </div>`;
      document.body.appendChild(overlay);
    }

    overlay.querySelectorAll("[data-approval-no], [data-approval-yes]").forEach((button) => {
      button.replaceWith(button.cloneNode(true));
    });
    const dialog = overlay.querySelector(".queue-cancellation-dialog");
    const title = overlay.querySelector("#queueApprovalTitle");
    const message = overlay.querySelector("[data-approval-message]");
    if (title) title.textContent = `Approve this ${label} payment?`;
    if (message) message.textContent = `Approve this ${label} payment? This will mark the payment as approved and allow the queue to continue processing.`;
    overlay.hidden = false;
    window.servitechAdminModalStack?.open({ overlay, dialog, focus: overlay.querySelector("[data-approval-no]") });

    return new Promise((resolve) => {
      const finish = (value) => {
        window.servitechAdminModalStack?.close(overlay);
        overlay.hidden = true;
        resolve(value);
      };
      overlay.querySelectorAll("[data-approval-no]").forEach((button) => button.addEventListener("click", () => finish(false), { once: true }));
      overlay.querySelector("[data-approval-yes]")?.addEventListener("click", () => finish(true), { once: true });
    });
  };
})();
