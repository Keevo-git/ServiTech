(function () {
  "use strict";

  let activeResolver = null;

  function closeDialog(value = null) {
    const overlay = document.getElementById("queueCancellationOverlay");
    if (overlay) overlay.hidden = true;
    if (activeResolver) {
      const resolve = activeResolver;
      activeResolver = null;
      resolve(value);
    }
  }

  function showReasonStep() {
    const warning = document.getElementById("queueCancellationWarning");
    const reason = document.getElementById("queueCancellationReasonStep");
    const textarea = document.getElementById("queueCancellationReason");
    const error = document.getElementById("queueCancellationError");
    if (warning) warning.hidden = true;
    if (reason) reason.hidden = false;
    if (textarea) textarea.value = "";
    if (error) error.textContent = "";
    window.setTimeout(() => textarea?.focus(), 0);
  }

  function showWarningStep() {
    const warning = document.getElementById("queueCancellationWarning");
    const reason = document.getElementById("queueCancellationReasonStep");
    if (warning) warning.hidden = false;
    if (reason) reason.hidden = true;
    window.setTimeout(() => document.querySelector("[data-cancellation-no]")?.focus(), 0);
  }

  function ensureDialog() {
    if (document.getElementById("queueCancellationOverlay")) return;

    const overlay = document.createElement("div");
    overlay.id = "queueCancellationOverlay";
    overlay.hidden = true;
    overlay.innerHTML = `
      <div class="queue-cancellation-dialog" role="dialog" aria-modal="true" aria-labelledby="queueCancellationTitle">
        <section id="queueCancellationWarning">
          <h3 id="queueCancellationTitle">Cancel Order?</h3>
          <p>This order is going to be cancelled. Do you want to continue?</p>
          <div class="queue-cancellation-actions">
            <button type="button" data-cancellation-no>No</button>
            <button type="button" class="queue-cancellation-submit" data-cancellation-yes>Yes</button>
          </div>
        </section>
        <section id="queueCancellationReasonStep" hidden>
          <h3>Cancellation Reason</h3>
          <p>Enter the reason that will be sent to the customer.</p>
          <textarea id="queueCancellationReason" rows="5" maxlength="1000" placeholder="Reason for cancellation" required></textarea>
          <p class="queue-cancellation-error" id="queueCancellationError" role="alert"></p>
          <div class="queue-cancellation-actions">
            <button type="button" data-cancellation-back>Back</button>
            <button type="button" class="queue-cancellation-submit" data-cancellation-submit>Cancel Order</button>
          </div>
        </section>
      </div>
    `;
    document.body.appendChild(overlay);

    overlay.querySelector("[data-cancellation-no]")?.addEventListener("click", () => closeDialog(null));
    overlay.querySelector("[data-cancellation-yes]")?.addEventListener("click", showReasonStep);
    overlay.querySelector("[data-cancellation-back]")?.addEventListener("click", () => closeDialog(null));
    overlay.addEventListener("click", (event) => {
      if (event.target === overlay) closeDialog(null);
    });
    overlay.querySelector("[data-cancellation-submit]")?.addEventListener("click", () => {
      const reason = String(document.getElementById("queueCancellationReason")?.value || "").trim();
      const error = document.getElementById("queueCancellationError");
      if (!reason) {
        if (error) error.textContent = "Cancellation reason is required.";
        return;
      }
      closeDialog(reason);
    });
  }

  window.servitechRequestCancellationReason = function () {
    ensureDialog();
    showWarningStep();
    const overlay = document.getElementById("queueCancellationOverlay");
    if (overlay) overlay.hidden = false;

    return new Promise((resolve) => {
      activeResolver = resolve;
    });
  };

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !document.getElementById("queueCancellationOverlay")?.hidden) {
      closeDialog(null);
    }
  });
})();
