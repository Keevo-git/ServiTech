(function () {
  const overlay = document.getElementById("orderRecycleConfirm");
  if (!overlay) return;

  const dialog = overlay.querySelector(".order-confirm-dialog");
  const title = overlay.querySelector("[data-order-confirm-title]");
  const message = overlay.querySelector("[data-order-confirm-message]");
  const closeButton = overlay.querySelector("[data-order-confirm-close]");
  const cancelButton = overlay.querySelector("[data-order-confirm-cancel]");
  const submitButton = overlay.querySelector("[data-order-confirm-submit]");
  const endpoint = overlay.dataset.orderRecycleEndpoint || "";
  let pending = null;
  let trigger = null;

  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");

  const configurations = {
    soft_delete: {
      title: "Move Order to Bin?",
      message: "This order will be removed from Order Management but can still be restored from the Recycle Bin within 30 days.",
      submit: "Move to Bin",
    },
    restore: {
      title: "Restore order?",
      message: "This order will return to its proper Order Management page.",
      submit: "Restore Order",
    },
    permanent_delete: {
      title: "Remove Permanently?",
      message: "This order will be permanently removed from the system view, but the database record will remain stored.",
      submit: "Remove Permanently",
    },
  };

  function closeModal() {
    overlay.classList.remove("is-open");
    overlay.setAttribute("aria-hidden", "true");
    document.documentElement.classList.remove("order-confirm-open");
    document.body.classList.remove("order-confirm-open");
    trigger?.focus();
    pending = null;
    trigger = null;
  }

  function openModal(action, id, code, source) {
    const config = configurations[action];
    if (!config || !id) return;
    pending = { action, id, code };
    trigger = source;
    title.textContent = config.title;
    message.textContent = config.message;
    submitButton.textContent = config.submit;
    submitButton.classList.toggle("order-confirm-submit--restore", action === "restore");
    overlay.classList.add("is-open");
    overlay.setAttribute("aria-hidden", "false");
    document.documentElement.classList.add("order-confirm-open");
    document.body.classList.add("order-confirm-open");
    cancelButton?.focus();
  }

  async function submitAction() {
    if (!pending || submitButton.disabled) return;
    submitButton.disabled = true;

    const data = new FormData();
    data.append("id", pending.id);
    data.append("action", pending.action);

    let result;
    try {
      const response = await fetch(endpoint, {
        method: "POST",
        body: data,
        credentials: "same-origin",
        headers: { "X-CSRF-Token": csrf() },
      });
      result = await response.json();
    } catch (error) {
      result = { ok: false, error: "Unable to update this order." };
    }

    submitButton.disabled = false;
    if (!result.ok) {
      window.servitechAdminToast?.error(result.error || "Unable to update this order.");
      return;
    }

    window.servitechAdminToast?.persist(result.message || "Order updated.");
    closeModal();
    window.location.reload();
  }

  document.addEventListener("click", (event) => {
    const deleteButton = event.target.closest("[data-order-delete]");
    if (deleteButton) {
      event.preventDefault();
      openModal("soft_delete", deleteButton.dataset.id, deleteButton.dataset.code, deleteButton);
      return;
    }

    const recycleButton = event.target.closest("[data-recycle-action]");
    if (recycleButton) {
      event.preventDefault();
      openModal(recycleButton.dataset.recycleAction, recycleButton.dataset.id, recycleButton.dataset.code, recycleButton);
    }
  });

  cancelButton?.addEventListener("click", closeModal);
  closeButton?.addEventListener("click", closeModal);
  submitButton?.addEventListener("click", submitAction);
  overlay.addEventListener("click", (event) => {
    if (event.target === overlay) closeModal();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && overlay.classList.contains("is-open")) closeModal();
  });
  dialog?.addEventListener("click", (event) => event.stopPropagation());
})();
