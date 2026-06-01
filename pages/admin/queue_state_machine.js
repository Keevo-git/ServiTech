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

  function ensureDialog() {
    if (document.getElementById("queueCancellationOverlay")) return;

    const overlay = document.createElement("div");
    overlay.id = "queueCancellationOverlay";
    overlay.hidden = true;
    overlay.innerHTML = `
      <div class="queue-cancellation-dialog" role="dialog" aria-modal="true" aria-labelledby="queueCancellationTitle">
        <h3 id="queueCancellationTitle">Cancellation Reason</h3>
        <p>Enter the reason that will be sent to the customer.</p>
        <textarea id="queueCancellationReason" rows="5" maxlength="1000" placeholder="Reason for cancellation" required></textarea>
        <p class="queue-cancellation-error" id="queueCancellationError" role="alert"></p>
        <div class="queue-cancellation-actions">
          <button type="button" data-cancellation-close>Back</button>
          <button type="button" class="queue-cancellation-submit" data-cancellation-submit>Cancel Order</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);

    overlay.querySelector("[data-cancellation-close]")?.addEventListener("click", () => closeDialog(null));
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
    if (!window.confirm("This order is going to be cancelled. Do you want to continue?")) {
      return Promise.resolve(null);
    }

    ensureDialog();
    const overlay = document.getElementById("queueCancellationOverlay");
    const textarea = document.getElementById("queueCancellationReason");
    const error = document.getElementById("queueCancellationError");
    if (textarea) textarea.value = "";
    if (error) error.textContent = "";
    if (overlay) overlay.hidden = false;
    window.setTimeout(() => textarea?.focus(), 0);

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
