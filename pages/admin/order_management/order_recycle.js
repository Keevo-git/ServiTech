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
    bulk_soft_delete: {
      title: "Move Selected Orders to Bin?",
      message: "The checked orders will be removed from Order Management but can still be restored from the Recycle Bin within 30 days.",
      submit: "Move Selected to Bin",
    },
    restore: {
      title: "Restore order?",
      message: "This order will return to its proper Order Management page.",
      submit: "Restore Order",
    },
    permanent_delete: {
      title: "Permanently delete order?",
      message: "This order will be permanently removed from the system view, but the database record will remain stored.",
      submit: "Delete Permanently",
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

  function openModal(action, id, code, source, items = []) {
    const config = configurations[action];
    if (!config || (!id && items.length === 0)) return;
    pending = { action, id, code, items };
    trigger = source;
    title.textContent = config.title;
    message.textContent = action === "bulk_soft_delete"
      ? `${items.length} ${items.length === 1 ? "order" : "orders"} will be moved to the Recycle Bin.`
      : config.message;
    submitButton.textContent = config.submit;
    submitButton.classList.toggle("order-confirm-submit--restore", action === "restore");
    overlay.classList.add("is-open");
    overlay.setAttribute("aria-hidden", "false");
    document.documentElement.classList.add("order-confirm-open");
    document.body.classList.add("order-confirm-open");
    cancelButton?.focus();
  }

  async function postRecycleAction(id, action) {
    const data = new FormData();
    data.append("id", id);
    data.append("action", action);

    const response = await fetch(endpoint, {
      method: "POST",
      body: data,
      credentials: "same-origin",
      headers: { "X-CSRF-Token": csrf() },
    });
    return response.json();
  }

  async function submitAction() {
    if (!pending || submitButton.disabled) return;
    submitButton.disabled = true;
    const originalSubmitText = submitButton.textContent;

    let result = { ok: false, error: "Unable to update this order." };

    try {
      if (pending.action === "bulk_soft_delete") {
        const items = Array.isArray(pending.items) ? pending.items : [];
        let movedCount = 0;
        let firstError = "";

        for (const item of items) {
          submitButton.textContent = `Moving ${movedCount + 1} of ${items.length}...`;
          const itemResult = await postRecycleAction(item.id, "soft_delete");
          if (!itemResult.ok) {
            firstError = itemResult.error || "Unable to move one of the selected orders.";
            break;
          }
          movedCount += 1;
        }

        result = movedCount === items.length
          ? { ok: true, message: `${movedCount} ${movedCount === 1 ? "order" : "orders"} moved to the Recycle Bin.` }
          : { ok: false, error: firstError || "Unable to move all selected orders." };
      } else {
        result = await postRecycleAction(pending.id, pending.action);
      }
    } catch (error) {
      result = { ok: false, error: "Unable to update this order." };
    }

    submitButton.disabled = false;
    submitButton.textContent = originalSubmitText;
    if (!result.ok) {
      window.servitechAdminToast?.error(result.error || "Unable to update this order.");
      return;
    }

    window.servitechAdminToast?.persist(result.message || "Order updated.");
    closeModal();
    window.location.reload();
  }

  function initBulkToolbar(toolbar) {
    const table = document.getElementById(toolbar.dataset.tableId || "");
    if (!table) return;

    const selectAll = table.querySelector("[data-order-select-all]");
    const checkboxes = Array.from(table.querySelectorAll("[data-order-select]"));
    const bulkButton = toolbar.querySelector("[data-order-bulk-delete]");
    const countEl = toolbar.querySelector("[data-order-bulk-count]");
    if (!selectAll || !checkboxes.length || !bulkButton || !countEl) return;

    function selectedCheckboxes() {
      return checkboxes.filter((checkbox) => checkbox.checked);
    }

    function visibleCheckboxes() {
      return checkboxes.filter((checkbox) => !checkbox.closest("tr")?.hidden);
    }

    function updateBulkState() {
      const selected = selectedCheckboxes();
      const visible = visibleCheckboxes();
      const selectedVisibleCount = visible.filter((checkbox) => checkbox.checked).length;
      countEl.textContent = selected.length
        ? `${selected.length} ${selected.length === 1 ? "order" : "orders"} selected`
        : "No orders selected";
      bulkButton.disabled = selected.length === 0;
      selectAll.checked = visible.length > 0 && selectedVisibleCount === visible.length;
      selectAll.indeterminate = selectedVisibleCount > 0 && selectedVisibleCount < visible.length;
    }

    selectAll.addEventListener("change", () => {
      visibleCheckboxes().forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
      updateBulkState();
    });

    checkboxes.forEach((checkbox) => checkbox.addEventListener("change", updateBulkState));

    bulkButton.addEventListener("click", (event) => {
      event.preventDefault();
      const items = selectedCheckboxes()
        .map((checkbox) => ({
          id: checkbox.dataset.id,
          code: checkbox.dataset.code || "",
        }))
        .filter((item) => item.id);
      openModal("bulk_soft_delete", "", "", bulkButton, items);
    });

    const observer = new MutationObserver(updateBulkState);
    table.querySelectorAll(".order-data-row").forEach((row) => {
      observer.observe(row, { attributes: true, attributeFilter: ["hidden"] });
    });

    updateBulkState();
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
  document.querySelectorAll("[data-order-bulk-toolbar]").forEach(initBulkToolbar);
  overlay.addEventListener("click", (event) => {
    if (event.target === overlay) closeModal();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && overlay.classList.contains("is-open")) closeModal();
  });
  dialog?.addEventListener("click", (event) => event.stopPropagation());
})();
