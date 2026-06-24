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
  const dashboardRefreshStorageKey = "servitech:admin-dashboard-refresh";
  let pending = null;
  let trigger = null;

  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");

  function notifyDashboardRefresh() {
    try {
      window.localStorage.setItem(dashboardRefreshStorageKey, String(Date.now()));
    } catch (error) {
      // The dashboard's periodic polling remains the fallback when storage is unavailable.
    }
    window.dispatchEvent(new CustomEvent("servitech:admin-dashboard-refresh"));
  }

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
    bulk_restore: {
      title: "Restore Selected Orders?",
      message: "The checked orders will return to their proper Order Management page.",
      submit: "Restore Selected",
    },
    bulk_permanent_delete: {
      title: "Delete Selected Orders?",
      message: "The checked orders will be removed from the system view, but the database records will remain stored.",
      submit: "Delete Selected",
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
    message.textContent = action.startsWith("bulk_")
      ? bulkConfirmMessage(action, items.length)
      : config.message;
    submitButton.textContent = config.submit;
    submitButton.classList.toggle("order-confirm-submit--restore", action === "restore" || action === "bulk_restore");
    overlay.classList.add("is-open");
    overlay.setAttribute("aria-hidden", "false");
    document.documentElement.classList.add("order-confirm-open");
    document.body.classList.add("order-confirm-open");
    cancelButton?.focus();
  }

  function bulkConfirmMessage(action, count) {
    const label = `${count} ${count === 1 ? "order" : "orders"}`;
    if (action === "bulk_restore") return `${label} will be restored to Order Management.`;
    if (action === "bulk_permanent_delete") return `${label} will be deleted from the Recycle Bin view.`;
    return `${label} will be moved to the Recycle Bin.`;
  }

  function singleActionForBulk(action) {
    if (action === "bulk_restore") return "restore";
    if (action === "bulk_permanent_delete") return "permanent_delete";
    if (action === "bulk_soft_delete") return "soft_delete";
    return action;
  }

  function bulkSuccessMessage(action, count) {
    const label = `${count} ${count === 1 ? "order" : "orders"}`;
    if (action === "bulk_restore") return `${label} restored successfully.`;
    if (action === "bulk_permanent_delete") return `${label} deleted from the Recycle Bin view.`;
    return `${label} moved to the Recycle Bin.`;
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
      if (pending.action.startsWith("bulk_")) {
        const items = Array.isArray(pending.items) ? pending.items : [];
        let movedCount = 0;
        let firstError = "";
        const singleAction = singleActionForBulk(pending.action);

        for (const item of items) {
          submitButton.textContent = `Processing ${movedCount + 1} of ${items.length}...`;
          const itemResult = await postRecycleAction(item.id, singleAction);
          if (!itemResult.ok) {
            firstError = itemResult.error || "Unable to update one of the selected orders.";
            break;
          }
          movedCount += 1;
        }

        result = movedCount === items.length
          ? { ok: true, message: bulkSuccessMessage(pending.action, movedCount) }
          : { ok: false, error: firstError || "Unable to update all selected orders." };
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
    notifyDashboardRefresh();
    closeModal();
    window.location.reload();
  }

  function initBulkToolbar(toolbar) {
    const table = document.getElementById(toolbar.dataset.tableId || "");
    if (!table) return;

    const selectAll = table.querySelector("[data-order-select-all]");
    const checkboxes = Array.from(table.querySelectorAll("[data-order-select]"));
    const bulkButtons = Array.from(toolbar.querySelectorAll("[data-order-bulk-delete], [data-order-bulk-action]"));
    const countEl = toolbar.querySelector("[data-order-bulk-count]");
    if (!selectAll || !checkboxes.length || !bulkButtons.length || !countEl) return;

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
      bulkButtons.forEach((button) => {
        button.disabled = selected.length === 0;
      });
      selectAll.checked = visible.length > 0 && selectedVisibleCount === visible.length;
      selectAll.indeterminate = selectedVisibleCount > 0 && selectedVisibleCount < visible.length;
    }

    selectAll.addEventListener("click", (event) => event.stopPropagation());
    selectAll.addEventListener("change", () => {
      visibleCheckboxes().forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
      updateBulkState();
    });

    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener("click", (event) => event.stopPropagation());
      checkbox.addEventListener("change", updateBulkState);
    });

    bulkButtons.forEach((button) => {
      button.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        const items = selectedCheckboxes()
          .map((checkbox) => ({
            id: checkbox.dataset.id,
            code: checkbox.dataset.code || "",
          }))
          .filter((item) => item.id);
        const action = button.dataset.orderBulkAction || "bulk_soft_delete";
        openModal(action, "", "", button, items);
      });
    });

    const observer = new MutationObserver(updateBulkState);
    table.querySelectorAll(".order-data-row").forEach((row) => {
      observer.observe(row, { attributes: true, attributeFilter: ["hidden"] });
    });

    updateBulkState();
  }

  document.querySelectorAll("[data-order-delete], [data-recycle-action]").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();

      const action = button.hasAttribute("data-order-delete")
        ? "soft_delete"
        : button.dataset.recycleAction;
      openModal(action, button.dataset.id, button.dataset.code, button);
    });
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
