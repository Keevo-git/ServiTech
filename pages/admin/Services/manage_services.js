(function () {
  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");
  const apiUrl = window.MS_API_URL || "/pages/super_admin/super_admin_services_api.php";

  const overlay = qs("#msOverlay");
  const editorDialog = overlay ? qs(".ms-modal", overlay) : null;
  const editor = qs("#ms_catalogEditor");
  const errorBox = qs("#msErr");
  const pageError = qs("#msPageError");
  const confirmOverlay = qs("#msConfirmOverlay");
  const confirmDialog = confirmOverlay ? qs(".ms-confirm-dialog", confirmOverlay) : null;
  const confirmTitle = qs("#msConfirmTitle");
  const confirmMessage = qs("#msConfirmMessage");
  const confirmCancel = qs("#msConfirmCancel");
  const confirmAccept = qs("#msConfirmAccept");
  const confirmClose = qs("#msConfirmX");
  const fields = {
    id: qs("#ms_id"),
    category: qs("#ms_category"),
    name: qs("#ms_name"),
    description: qs("#ms_description"),
    active: qs("#ms_active"),
    activeLabel: qs("#ms_active_label"),
    sort: qs("#ms_sort"),
  };
  const optionLabelMaxLength = 120;
  const optionDescriptionMaxLength = 255;

  let catalog = null;
  let originalSnapshot = "";
  let originalServiceSnapshot = null;
  let currentKind = "";
  let confirmResolver = null;
  let confirmReturnFocus = null;
  let editorLoadToken = 0;
  let selectedPaperSizeKey = null;
  let selectedColorKey = null;
  let selectedPackageKey = null;
  let selectedAddonKey = null;
  let selectedLaminationTypeKey = null;
  let selectedRepairDeviceKey = null;
  let selectedInstallationDeviceKey = null;
  let selectedSimpleInstallationTypeKey = null;
  const serviceModalStack = [];
  const focusableSelector = 'button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const modalBaseZIndex = 2147483100;
  const modalLayerStep = 200;

  function isConfirmOpen() {
    return !!confirmOverlay && !confirmOverlay.hidden && confirmOverlay.classList.contains("is-open");
  }

  function isEditorOpen() {
    return !!overlay && !overlay.hidden && overlay.classList.contains("is-open");
  }

  function focusableElements(dialog) {
    if (!dialog) return [];
    return qsa(focusableSelector, dialog).filter((element) => (
      !element.hidden && (element.offsetParent !== null || element === document.activeElement)
    ));
  }

  function focusLater(element) {
    window.requestAnimationFrame(() => {
      if (!element || !document.contains(element) || typeof element.focus !== "function") return;
      try {
        element.focus({ preventScroll: true });
      } catch (_) {
        element.focus();
      }
    });
  }

  function topModal() {
    return serviceModalStack[serviceModalStack.length - 1] || null;
  }

  function isTopModal(layer) {
    return topModal()?.layer === layer;
  }

  function captureScrollPositions(dialog) {
    if (!dialog) return [];
    return qsa(".ms-body, .ms-matrix-wrap", dialog).map((element) => ({
      element,
      top: element.scrollTop,
      left: element.scrollLeft,
    }));
  }

  function restoreScrollPositions(entry) {
    (entry?.scrollPositions || []).forEach(({ element, top, left }) => {
      if (!document.contains(element)) return;
      element.scrollTop = top;
      element.scrollLeft = left;
    });
  }

  function syncModalLayers() {
    serviceModalStack.forEach((entry, index) => {
      const covered = index < serviceModalStack.length - 1;
      entry.layer.style.zIndex = String(modalBaseZIndex + (index * modalLayerStep));
      entry.layer.classList.toggle("is-covered", covered);
      entry.dialog.setAttribute("aria-modal", covered ? "false" : "true");
      entry.layer.dataset.modalDepth = String(index);
    });
    const hasOpenModal = serviceModalStack.length > 0;
    document.documentElement.classList.toggle("ms-modal-open", hasOpenModal);
    document.body.classList.toggle("ms-modal-open", hasOpenModal);
  }

  function openModalLayer(layer, dialog, focus, onEscape) {
    if (!layer || !dialog) return false;
    if (serviceModalStack.some((entry) => entry.layer === layer)) return false;
    const previous = topModal();
    if (previous) previous.scrollPositions = captureScrollPositions(previous.dialog);
    layer.hidden = false;
    layer.classList.add("is-open");
    layer.classList.remove("is-covered");
    layer.setAttribute("aria-hidden", "false");
    serviceModalStack.push({
      layer,
      dialog,
      focus,
      onEscape,
      previousFocus: document.activeElement,
      scrollPositions: captureScrollPositions(dialog),
    });
    syncModalLayers();
    if (previous) {
      restoreScrollPositions(previous);
      window.requestAnimationFrame(() => restoreScrollPositions(previous));
    }
    focusLater(focus || focusableElements(dialog)[0] || dialog);
    return true;
  }

  document.addEventListener("keydown", (event) => {
    const entry = topModal();
    if (!entry) return;
    if (event.key === "Escape" && typeof entry.onEscape === "function") {
      event.preventDefault();
      event.stopImmediatePropagation();
      entry.onEscape();
      return;
    }
    if (event.key !== "Tab") return;
    const focusable = focusableElements(entry.dialog);
    if (!focusable.length) {
      event.preventDefault();
      entry.dialog.focus();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }, true);

  document.addEventListener("focusin", (event) => {
    const entry = topModal();
    if (!entry || entry.dialog.contains(event.target)) return;
    focusLater(entry.focus || focusableElements(entry.dialog)[0] || entry.dialog);
  }, true);

  function closeModalLayer(layer, dialog) {
    if (!layer || !dialog || !isTopModal(layer)) return false;
    const entry = serviceModalStack.pop();
    layer.classList.remove("is-open");
    layer.classList.remove("is-covered");
    layer.hidden = true;
    layer.setAttribute("aria-hidden", "true");
    dialog.setAttribute("aria-modal", "false");
    layer.style.zIndex = "";
    delete layer.dataset.modalDepth;
    syncModalLayers();
    const next = topModal();
    if (next) window.requestAnimationFrame(() => restoreScrollPositions(next));
    const returnFocus = entry.previousFocus && document.contains(entry.previousFocus)
      ? entry.previousFocus
      : (next?.focus || focusableElements(next?.dialog)[0] || next?.dialog);
    focusLater(returnFocus);
    return true;
  }

  function closeTopModal(expectedLayer = null) {
    const entry = topModal();
    if (!entry || (expectedLayer && entry.layer !== expectedLayer)) return false;
    return closeModalLayer(entry.layer, entry.dialog);
  }

  const contracts = {
    document_printing: {
      groups: { paper_size: "Paper Size", color_option: "Color Option" },
      title: "Edit Document Printing Prices",
      help: "Set prices for each paper size and color option. These prices appear on the landing page and customer queue form.",
    },
    photocopy: {
      groups: { paper_size: "Paper Size", color_option: "Color Option" },
      title: "Edit Photocopy Prices",
      help: "Manage paper sizes, color options, and the official price for every valid combination.",
    },
    rush_id: {
      groups: { package: "Package", addon: "Add-Ons" },
      title: "Edit Rush ID Packages and Add-Ons",
      help: "Set package base prices and optional add-on prices. Customer totals are calculated from these active records.",
    },
    laminating: {
      groups: { lamination_type: "Type" },
      title: "Edit Laminating Prices and Options",
      help: "Manage available laminating types and their official prices.",
    },
    scanning: {
      groups: { paper_size: "Paper Size" },
      title: "Edit Scanning Prices and Options",
      help: "Set the official scanning price for each paper size. These prices appear on the landing page and customer queue form.",
    },
    repair: {
      groups: { device_type: "Devices", repair_type: "Service Type" },
      title: "Edit Repair Services",
      help: "Choose a device section, then manage its repair services, prices, and availability.",
    },
    installation: {
      groups: { installation_type: "Installation Type", device_type: "Devices" },
      title: "Edit Installation Services",
      help: "Manage installation types and mark each price as fixed or for assessment.",
    },
  };

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    }[char]));
  }

  function slug(value) {
    return String(value || "").trim().toLowerCase()
      .replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "") || "option";
  }

  function serviceKind(category, name) {
    const cat = String(category || "").toLowerCase();
    const label = String(name || "").toLowerCase();
    if (cat === "printing" && label.includes("document")) return "document_printing";
    if (cat === "printing" && (label.includes("photocopy") || label.includes("xerox"))) return "photocopy";
    if (cat === "printing" && label.includes("rush") && label.includes("id")) return "rush_id";
    if (cat === "printing" && label.includes("laminat")) return "laminating";
    if (cat === "printing" && label.includes("scan")) return "scanning";
    if (cat === "repair") return "repair";
    if (cat === "installation") return "installation";
    return "";
  }

  function showError(message) {
    errorBox.textContent = message;
    errorBox.style.display = "block";
    window.servitechAdminToast?.error(message);
  }

  function hideError() {
    errorBox.textContent = "";
    errorBox.style.display = "none";
  }

  function clearPageError() {
    if (!pageError) return;
    pageError.textContent = "";
    pageError.hidden = true;
  }

  function reportEditorOpenError(error) {
    const message = "Unable to open the service editor. Please refresh and try again.";
    console.error("[Manage Services] Unable to open service editor:", error);
    if (pageError) {
      pageError.textContent = message;
      pageError.hidden = false;
    }
    window.servitechAdminToast?.error?.(message);
  }

  function syncServiceStatusLabel() {
    if (!fields.activeLabel || !fields.active) return;
    fields.activeLabel.textContent = fields.active.checked ? "Active" : "Inactive";
  }

  function updateServiceCard(service) {
    if (!service) return;
    const editButton = qsa("[data-ms-edit]").find((button) => {
      try {
        return Number(JSON.parse(button.getAttribute("data-ms-edit") || "{}").id) === Number(service.id);
      } catch (_) {
        return false;
      }
    });
    if (!editButton) return;

    const buttonData = {
      id: Number(service.id),
      category: String(service.category || fields.category.value),
      name: String(service.name || fields.name.value),
      description: String(service.description || ""),
      active: Number(service.active) === 1 ? 1 : 0,
      sort_order: Number(service.sort_order || 0),
    };
    editButton.setAttribute("data-ms-edit", JSON.stringify(buttonData));
    editButton.dataset.serviceId = String(buttonData.id);
    editButton.dataset.serviceCategory = buttonData.category;
    editButton.dataset.serviceName = buttonData.name;

    const card = editButton.closest(".ms-service-card");
    if (!card) return;
    const heading = qs("h2", card);
    const description = qs(".ms-service-card__head + p", card);
    const status = qs(".ms-pill", card);
    const price = qs(".ms-service-card__foot > span", card);
    if (heading) heading.textContent = buttonData.name.toLowerCase() === "xerox" ? "Photocopy" : buttonData.name;
    if (description) description.textContent = buttonData.description;
    if (status) {
      status.classList.toggle("on", buttonData.active === 1);
      status.classList.toggle("off", buttonData.active !== 1);
      status.textContent = buttonData.active === 1 ? "Active" : "Inactive";
    }
    if (price) {
      const label = document.createElement("strong");
      label.textContent = "Customer price:";
      price.replaceChildren(label, document.createTextNode(` ${service.catalog_price_range || "Catalog unavailable"}`));
    }

    const grid = card?.parentElement;
    if (grid) {
      qsa(".ms-service-card", grid)
        .sort((left, right) => {
          const leftButton = qs("[data-ms-edit]", left);
          const rightButton = qs("[data-ms-edit]", right);
          const read = (button) => {
            try { return JSON.parse(button?.getAttribute("data-ms-edit") || "{}"); }
            catch (_) { return {}; }
          };
          const leftData = read(leftButton);
          const rightData = read(rightButton);
          return Number(leftData.sort_order || 0) - Number(rightData.sort_order || 0)
            || Number(leftData.id || 0) - Number(rightData.id || 0);
        })
        .forEach((item) => grid.appendChild(item));
    }
  }

  function group(key) {
    return (catalog?.groups || []).find((item) => item.group_key === key);
  }

  function groupValue(groupKey, valueKey) {
    return (group(groupKey)?.values || []).find((item) => item.value_key === valueKey);
  }

  function optionValueIsActive(groupKey, valueKey) {
    const optionGroup = group(groupKey);
    const value = groupValue(groupKey, valueKey);
    return Number(optionGroup?.active ?? 1) === 1
      && Number(value?.active ?? 0) === 1
      && String(value?.label || "").trim() !== "";
  }

  function ruleHasValidPrice(rule) {
    if (rule?.price_type === "assessment") return true;
    const price = Number(rule?.price);
    return rule?.price !== "" && rule?.price !== null && Number.isFinite(price) && price > 0;
  }

  function ruleIsCustomerSelectable(rule) {
    return Number(rule?.active) === 1
      && ruleHasValidPrice(rule)
      && Object.entries(rule.option_value_keys || {}).every(([groupKey, valueKey]) => optionValueIsActive(groupKey, valueKey));
  }

  function customerPrimarySelectableRules() {
    const rules = (catalog?.rules || []).filter(ruleIsCustomerSelectable);
    if (currentKind === "rush_id") {
      return rules.filter((rule) => Object.prototype.hasOwnProperty.call(rule.option_value_keys || {}, "package"));
    }
    if (currentKind === "installation") {
      const deviceMode = Number(group("device_type")?.active) === 1;
      return rules.filter((rule) => {
        const hasDevice = Object.prototype.hasOwnProperty.call(rule.option_value_keys || {}, "device_type");
        return deviceMode ? hasDevice : !hasDevice;
      });
    }
    return rules;
  }

  function serviceAvailabilityWarning() {
    if (!fields.active?.checked) return "";
    return customerPrimarySelectableRules().length
      ? ""
      : "This service is Active, but it will not appear to customers until at least one active option has a complete price setup.";
  }

  function optionVisibilityWarning(groupKey, value) {
    if (!Number(value?.active)) return "";
    if (!fields.active?.checked) {
      return "This option is active, but customers cannot see it while the service is inactive.";
    }
    const optionGroup = group(groupKey);
    if (!Number(optionGroup?.active ?? 1)) {
      return "This option is active, but customers cannot see it while this option group is inactive.";
    }
    const hasSelectableRule = (catalog?.rules || []).some((rule) => (
      String(rule.option_value_keys?.[groupKey] ?? "") === String(value.value_key)
      && ruleIsCustomerSelectable(rule)
    ));
    return hasSelectableRule
      ? ""
      : "This option is active, but it will not appear to customers until at least one active price combination is configured.";
  }

  function rulesForGroup(groupKey) {
    return (catalog?.rules || [])
      .filter((rule) => Object.prototype.hasOwnProperty.call(rule.option_value_keys || {}, groupKey))
      .sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
  }

  function findRule(keys) {
    return (catalog?.rules || []).find((rule) => {
      const ruleKeys = rule.option_value_keys || {};
      return Object.keys(keys).length === Object.keys(ruleKeys).length
        && Object.entries(keys).every(([key, value]) => String(ruleKeys[key]) === String(value));
    }) || null;
  }

  function ensureRule(keys, label, order, priceType = "fixed") {
    let rule = findRule(keys);
    if (rule) return rule;
    rule = {
      rule_key: slug(Object.entries(keys).map(([key, value]) => `${key}_${value}`).join("_")),
      option_value_keys: { ...keys },
      label,
      description: "",
      price: "",
      price_type: priceType,
      active: 1,
      sort_order: order,
    };
    catalog.rules.push(rule);
    return rule;
  }

  function normalizeCatalog(source) {
    const contract = contracts[currentKind];
    const normalized = {
      groups: JSON.parse(JSON.stringify(source?.groups || [])),
      rules: JSON.parse(JSON.stringify(source?.rules || [])),
    };
    Object.entries(contract.groups).forEach(([key, name], index) => {
      let item = normalized.groups.find((candidate) => candidate.group_key === key);
      if (!item) {
        item = {
          group_key: key,
          name,
          active: currentKind === "installation" && key === "device_type" ? 0 : 1,
          sort_order: index,
          values: [],
        };
        normalized.groups.push(item);
      }
      item.name = name;
      item.active = currentKind === "installation" && key === "device_type"
        ? Number(item.active ?? 0)
        : 1;
      item.sort_order = index;
      item.values = Array.isArray(item.values) ? item.values : [];
      item.values.forEach((value, valueIndex) => {
        value.value_key = value.value_key || slug(value.label);
        value.description = value.description || "";
        value.active = Number(value.active ?? 1);
        value.sort_order = Number(value.sort_order ?? valueIndex);
      });
    });
    normalized.groups = normalized.groups.filter((item) => contract.groups[item.group_key]);
    normalized.rules.forEach((rule, index) => {
      rule.option_value_keys = rule.option_value_keys || {};
      rule.rule_key = rule.rule_key || slug(Object.values(rule.option_value_keys).join("_"));
      rule.description = rule.description || "";
      rule.price_type = rule.price_type === "assessment" ? "assessment" : "fixed";
      rule.active = Number(rule.active ?? 1);
      rule.sort_order = Number(rule.sort_order ?? index);
    });
    return normalized;
  }

  function toggleControl(checked, label = "Active", ariaLabel = "Toggle active status") {
    return `<label class="ms-switch ms-switch--compact">
      <input data-rule-active data-action="toggle-active" type="checkbox" aria-label="${escapeHtml(ariaLabel)}" ${checked ? "checked" : ""}>
      <span aria-hidden="true"></span><em>${label}</em>
    </label>`;
  }

  function characterHelp(maxLength, value = "") {
    const length = String(value ?? "").length;
    return `${length}/${maxLength} characters`;
  }

  function serviceField({ label, control, help = "", className = "", full = false }) {
    return `<label class="service-field ${full ? "service-field--full" : ""} ${className}">
      <span class="service-field-label">${escapeHtml(label)}</span>
      ${control}
      ${help ? `<small class="service-field-help" data-character-help>${escapeHtml(help)}</small>` : ""}
    </label>`;
  }

  function textInputField({ label, value = "", maxLength = optionLabelMaxLength, inputAttrs = "", placeholder = "", className = "", full = false }) {
    return serviceField({
      label,
      className,
      full,
      help: characterHelp(maxLength, value),
      control: `<input ${inputAttrs} value="${escapeHtml(value)}" maxlength="${maxLength}" placeholder="${escapeHtml(placeholder)}" data-character-count>`,
    });
  }

  function newTextInputField({ label, dataAttr, maxLength = optionLabelMaxLength, placeholder = "", className = "", full = false }) {
    return serviceField({
      label,
      className,
      full,
      help: characterHelp(maxLength),
      control: `<input ${dataAttr} maxlength="${maxLength}" placeholder="${escapeHtml(placeholder)}" data-character-count>`,
    });
  }

  function priceField(inputHtml, label = "Price", className = "") {
    return serviceField({
      label,
      className: `service-field--price ${className}`,
      control: `<div class="ms-price-input price-input-group"><span>PHP</span>${inputHtml}</div>`,
    });
  }

  function selectField({ label, control, help = "", className = "" }) {
    return `<div class="service-field ${className}">
      <span class="service-field-label">${escapeHtml(label)}</span>
      ${control}
      ${help ? `<small class="service-field-help">${escapeHtml(help)}</small>` : ""}
    </div>`;
  }

  function statusField(control, label = "Active") {
    return `<div class="service-status-field">
      <span class="service-field-label">${escapeHtml(label)}</span>
      ${control}
    </div>`;
  }

  function syncCharacterCounters(root = editor) {
    qsa("[data-character-count]", root).forEach((input) => {
      const maxLength = Number(input.getAttribute("maxlength") || 0);
      const help = input.closest(".service-field, .ms-field")?.querySelector("[data-character-help]");
      if (!maxLength || !help) return;
      help.textContent = characterHelp(maxLength, input.value);
    });
  }

  function movementButtons(groupKey, valueKey, valueLabel, index, total) {
    return `<div class="ms-arrange-cell">
      <span class="ms-control-label">Arrange</span>
      <div class="ms-order-actions" role="group" aria-label="Arrange ${escapeHtml(valueLabel)}">
        <button type="button" data-action="move-up" data-move-value="${groupKey}" data-value-key="${valueKey}" data-direction="-1" ${index === 0 ? "disabled" : ""} title="Move up" aria-label="Move ${escapeHtml(valueLabel)} up">&uarr;</button>
        <button type="button" data-action="move-down" data-move-value="${groupKey}" data-value-key="${valueKey}" data-direction="1" ${index === total - 1 ? "disabled" : ""} title="Move down" aria-label="Move ${escapeHtml(valueLabel)} down">&darr;</button>
      </div>
    </div>`;
  }

  function ruleMovementButtons(ruleKey, ruleLabel, index, total) {
    return `<div class="ms-arrange-cell">
      <span class="ms-control-label">Arrange</span>
      <div class="ms-order-actions" role="group" aria-label="Arrange ${escapeHtml(ruleLabel)}">
        <button type="button" data-action="move-rule-up" data-rule-key="${escapeHtml(ruleKey)}" data-direction="-1" ${index === 0 ? "disabled" : ""} title="Move up" aria-label="Move ${escapeHtml(ruleLabel)} up">&uarr;</button>
        <button type="button" data-action="move-rule-down" data-rule-key="${escapeHtml(ruleKey)}" data-direction="1" ${index === total - 1 ? "disabled" : ""} title="Move down" aria-label="Move ${escapeHtml(ruleLabel)} down">&darr;</button>
      </div>
    </div>`;
  }

  function valueManager(groupKey, title, addLabel) {
    const values = group(groupKey)?.values || [];
    return `<section class="ms-option-section service-edit-section">
      <div class="ms-section-head">
        <div><h4>${escapeHtml(title)}</h4><p>Edit names, availability, and display order.</p></div>
      </div>
      <div class="ms-value-list">
        <div class="ms-value-list__head" aria-hidden="true"><span>Name</span><span>Status</span><span>Arrange</span></div>
        ${values.map((value, index) => `<div class="ms-value-row service-form-grid ${Number(value.active) ? "" : "is-inactive"}"
          data-group-key="${groupKey}" data-value-key="${escapeHtml(value.value_key)}">
          ${textInputField({
            label: `${title} name`,
            value: value.label,
            inputAttrs: `data-value-label aria-label="${escapeHtml(title)} name"`,
            full: true,
          })}
          <div class="ms-status-cell">
            <span class="ms-control-label">Status</span>
            <label class="ms-switch ms-switch--compact">
              <input data-value-active data-action="toggle-active" type="checkbox" aria-label="Toggle ${escapeHtml(value.label)} active status" ${Number(value.active) ? "checked" : ""}>
              <span aria-hidden="true"></span><em>${Number(value.active) ? "Active" : "Inactive"}</em>
            </label>
          </div>
          ${movementButtons(groupKey, value.value_key, value.label, index, values.length)}
          ${optionVisibilityWarning(groupKey, value) ? `<p class="ms-option-warning" role="status">${escapeHtml(optionVisibilityWarning(groupKey, value))}</p>` : ""}
        </div>`).join("")}
      </div>
      <div class="ms-inline-add service-form-grid">
        ${newTextInputField({
          label: addLabel,
          dataAttr: `data-new-value="${groupKey}"`,
          placeholder: addLabel,
          full: true,
        })}
        <div class="service-action-row">
          <button type="button" data-add-value="${groupKey}">Add</button>
        </div>
      </div>
    </section>`;
  }

  function priceCell(rule) {
    return `<div class="ms-price-cell" data-rule-key="${escapeHtml(rule.rule_key)}">
      ${priceField(`<input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(rule.price ?? "")}" placeholder="0.00">`)}
      ${selectField({ label: "Price type", control: `<select data-rule-price-type aria-label="Price type">
        <option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option>
        <option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For Assessment</option>
      </select>` })}
      ${statusField(toggleControl(Number(rule.active), Number(rule.active) ? "Active" : "Inactive", `Toggle ${rule.label || "price combination"} active status`))}
    </div>`;
  }

  function matrixEditor() {
    const papers = group("paper_size")?.values || [];
    const colors = group("color_option")?.values || [];
    let order = 0;
    const activePapers = papers.filter((item) => Number(item.active));
    const activeColors = colors.filter((item) => Number(item.active));
    const selectedSize = papers.find((p) => p.value_key === selectedPaperSizeKey);
    const selectedColor = colors.find((c) => c.value_key === selectedColorKey);

    return `<section class="ms-option-section service-edit-section">
      <div class="ms-section-head">
        <div><h4>Paper Sizes</h4><p>Select an existing paper size to edit, or add a new paper size.</p></div>
      </div>
      <div class="ms-value-list">
        <div class="ms-dropdown-wrapper">
          <label for="ms-paper-size-select">Select Paper Size</label>
          <select id="ms-paper-size-select" data-select-paper-size aria-label="Select paper size to edit">
            <option value="">-- Select a paper size --</option>
            ${papers.map((paper) => `<option value="${escapeHtml(paper.value_key)}" ${selectedPaperSizeKey === paper.value_key ? "selected" : ""}>${escapeHtml(paper.label)}</option>`).join("")}
          </select>
        </div>
        ${selectedSize ? `<div class="ms-value-row service-form-grid ${Number(selectedSize.active) ? "" : "is-inactive"}" data-group-key="paper_size" data-value-key="${escapeHtml(selectedSize.value_key)}">
          <div class="ms-size-edit-header"><strong>${escapeHtml(selectedSize.label)}</strong></div>
          ${textInputField({
            label: "Paper size name",
            value: selectedSize.label,
            inputAttrs: `data-value-label aria-label="Paper size name" class="ms-size-input"`,
            full: true,
          })}
          <div class="ms-status-cell service-action-row">
            ${statusField(`<label class="ms-switch ms-switch--compact">
              <input data-value-active data-action="toggle-active" type="checkbox" aria-label="Toggle ${escapeHtml(selectedSize.label)} active status" ${Number(selectedSize.active) ? "checked" : ""}>
              <span aria-hidden="true"></span><em>${Number(selectedSize.active) ? "Active" : "Inactive"}</em>
            </label>`)}
          </div>
          ${optionVisibilityWarning("paper_size", selectedSize) ? `<p class="ms-option-warning" role="status">${escapeHtml(optionVisibilityWarning("paper_size", selectedSize))}</p>` : ""}
        </div>` : ""}
      </div>
      <div class="ms-inline-add service-form-grid">
        ${newTextInputField({ label: "Paper Size", dataAttr: 'data-new-value="paper_size"', placeholder: "New paper size", full: true })}
        <div class="service-action-row"><button type="button" data-add-value="paper_size">Add</button></div>
      </div>
    </section>
    <section class="ms-option-section service-edit-section">
      <div class="ms-section-head">
        <div><h4>Color Options</h4><p>Select an existing color option to edit, or add a new color option.</p></div>
      </div>
      <div class="ms-value-list">
        <div class="ms-dropdown-wrapper">
          <label for="ms-color-select">Select Color Option</label>
          <select id="ms-color-select" data-select-color aria-label="Select color option to edit">
            <option value="">-- Select a color option --</option>
            ${colors.map((color) => `<option value="${escapeHtml(color.value_key)}" ${selectedColorKey === color.value_key ? "selected" : ""}>${escapeHtml(color.label)}</option>`).join("")}
          </select>
        </div>
        ${selectedColor ? `<div class="ms-value-row service-form-grid ${Number(selectedColor.active) ? "" : "is-inactive"}" data-group-key="color_option" data-value-key="${escapeHtml(selectedColor.value_key)}">
          <div class="ms-size-edit-header"><strong>${escapeHtml(selectedColor.label)}</strong></div>
          ${textInputField({
            label: "Color option name",
            value: selectedColor.label,
            inputAttrs: `data-value-label aria-label="Color option name" class="ms-size-input"`,
            full: true,
          })}
          <div class="ms-status-cell service-action-row">
            ${statusField(`<label class="ms-switch ms-switch--compact">
              <input data-value-active data-action="toggle-active" type="checkbox" aria-label="Toggle ${escapeHtml(selectedColor.label)} active status" ${Number(selectedColor.active) ? "checked" : ""}>
              <span aria-hidden="true"></span><em>${Number(selectedColor.active) ? "Active" : "Inactive"}</em>
            </label>`)}
          </div>
          ${optionVisibilityWarning("color_option", selectedColor) ? `<p class="ms-option-warning" role="status">${escapeHtml(optionVisibilityWarning("color_option", selectedColor))}</p>` : ""}
        </div>` : ""}
      </div>
      <div class="ms-inline-add service-form-grid">
        ${newTextInputField({ label: "Color Option", dataAttr: 'data-new-value="color_option"', placeholder: "New color option", full: true })}
        <div class="service-action-row"><button type="button" data-add-value="color_option">Add</button></div>
      </div>
    </section>
    <section class="ms-pricing-section service-edit-section">
      <div class="ms-section-head"><div><h4>Price Matrix</h4><p>Each cell controls one paper size and color combination.</p></div></div>
      ${activePapers.length && activeColors.length ? `<div class="ms-matrix-wrap"><table class="ms-catalog-matrix">
        <thead><tr><th>Paper Size</th>${activeColors.map((color) => `<th>${escapeHtml(color.label)}</th>`).join("")}</tr></thead>
        <tbody>${activePapers.map((paper) => `<tr><th>${escapeHtml(paper.label)}</th>${activeColors.map((color) => {
          const rule = ensureRule(
            { paper_size: paper.value_key, color_option: color.value_key },
            `${paper.label} / ${color.label}`,
            order++
          );
          return `<td>${priceCell(rule)}</td>`;
        }).join("")}</tr>`).join("")}</tbody>
      </table></div>` : `<p class="ms-empty-inline">Activate at least one paper size and color option to edit prices.</p>`}
    </section>`;
  }

  function simpleRuleRows(groupKey, title, addLabel, options = {}) {
    const values = group(groupKey)?.values || [];
    values.forEach((value, index) => ensureRule({ [groupKey]: value.value_key }, value.label, index, options.defaultType || "fixed"));
    return `<section class="ms-pricing-section service-edit-section">
      <div class="ms-section-head"><div><h4>${escapeHtml(title)}</h4><p>${escapeHtml(options.help || "Edit names, prices, and availability.")}</p></div></div>
      <div class="ms-rule-table">
        <div class="ms-rule-table__head"><span>${escapeHtml(options.nameLabel || "Name")}</span>${options.description ? "<span>Details</span>" : ""}<span>Price</span><span>Price Type</span><span>Status</span><span>Order</span></div>
        ${values.map((value, index) => {
          const rule = findRule({ [groupKey]: value.value_key });
          return `<div class="ms-rule-row service-form-grid ${Number(value.active) ? "" : "is-inactive"}" data-rule-key="${escapeHtml(rule.rule_key)}" data-group-key="${groupKey}" data-value-key="${escapeHtml(value.value_key)}">
            ${textInputField({
              label: options.nameLabel || "Name",
              value: value.label,
              inputAttrs: `data-value-label aria-label="${escapeHtml(options.nameLabel || "Name")}"`,
              full: !options.description,
            })}
            ${options.description ? textInputField({
              label: "Inclusions or Details",
              value: rule.description || value.description || "",
              maxLength: optionDescriptionMaxLength,
              inputAttrs: "data-rule-description",
              placeholder: "Details shown to customers",
              full: true,
            }) : ""}
            <div class="service-action-row">
              ${priceField(`<input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(rule.price ?? "")}" placeholder="0.00">`)}
              ${options.fixedOnly ? '<input type="hidden" data-rule-price-type value="fixed"><span class="ms-fixed-label">Fixed Price</span>' : selectField({ label: "Price Type", control: `<select data-rule-price-type><option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option><option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For Assessment</option></select>` })}
              ${statusField(toggleControl(Number(rule.active) && Number(value.active), Number(rule.active) && Number(value.active) ? "Active" : "Inactive", `Toggle ${value.label} active status`))}
            </div>
            <div class="ms-row-actions">${movementButtons(groupKey, value.value_key, value.label, index, values.length)}</div>
          </div>`;
        }).join("")}
      </div>
      <div class="ms-inline-add ms-inline-add--rule service-form-grid">
        ${newTextInputField({ label: addLabel, dataAttr: `data-new-value="${groupKey}"`, placeholder: addLabel })}
        ${options.description ? newTextInputField({
          label: "Inclusions or Details",
          dataAttr: `data-new-description="${groupKey}"`,
          maxLength: optionDescriptionMaxLength,
          placeholder: "Inclusions or details",
          full: true,
        }) : ""}
        <div class="service-action-row">
          ${priceField(`<input data-new-price="${groupKey}" type="number" min="0" step="0.01" placeholder="0.00">`)}
          ${options.fixedOnly
            ? `<input type="hidden" data-new-price-type="${groupKey}" value="fixed"><span class="ms-fixed-label">Fixed Price</span>`
            : selectField({ label: "Price Type", control: `<select data-new-price-type="${groupKey}"><option value="fixed">Fixed Price</option><option value="assessment">For Assessment</option></select>` })}
          ${statusField(`<label class="ms-switch ms-switch--compact"><input data-new-active="${groupKey}" type="checkbox" checked><span aria-hidden="true"></span><em>Active</em></label>`)}
          <button type="button" data-add-value="${groupKey}">Add</button>
        </div>
      </div>
    </section>`;
  }

  function finishConfirmation(accepted) {
    if (!confirmResolver || !isTopModal(confirmOverlay)) return;
    const resolve = confirmResolver;
    const returnFocus = confirmReturnFocus;
    if (!closeTopModal(confirmOverlay)) return;
    confirmResolver = null;
    confirmReturnFocus = null;
    confirmOverlay.dataset.tone = "";
    resolve(accepted);
    focusLater(returnFocus);
  }

  function confirmAction({ title, message, confirmLabel = "Confirm", tone = "primary" }) {
    if (!confirmOverlay || !confirmDialog) return Promise.resolve(false);
    if (confirmResolver) finishConfirmation(false);
    confirmReturnFocus = document.activeElement;
    confirmTitle.textContent = title;
    confirmMessage.textContent = message;
    confirmAccept.textContent = confirmLabel;
    confirmOverlay.dataset.tone = tone;
    if (!openModalLayer(confirmOverlay, confirmDialog, confirmAccept, () => finishConfirmation(false))) {
      return Promise.resolve(false);
    }
    return new Promise((resolve) => { confirmResolver = resolve; });
  }

  confirmCancel?.addEventListener("click", () => finishConfirmation(false));
  confirmClose?.addEventListener("click", () => finishConfirmation(false));
  confirmAccept?.addEventListener("click", () => finishConfirmation(true));
  confirmOverlay?.addEventListener("click", (event) => {
    if (event.target === confirmOverlay && isTopModal(confirmOverlay)) finishConfirmation(false);
  });

  function optionGroupName(groupKey) {
    return contracts[currentKind]?.groups?.[groupKey] || "Option";
  }

  function addConfirmationMessage(groupKey, active) {
    if (currentKind === "rush_id" && groupKey === "addon") {
      return "Save this add-on? Its price will be added to the selected RUSH ID package when customers choose it.";
    }
    return `Add this new ${optionGroupName(groupKey).toLowerCase()}? It will be available in Manage Services${active ? " and may appear to customers after you save" : " but will remain inactive"}.`;
  }

  function toggleConfirmation(target, activated) {
    const valueRow = target.closest("[data-group-key][data-value-key]");
    const groupKey = valueRow?.dataset?.groupKey || "";
    const label = groupKey ? optionGroupName(groupKey).toLowerCase() : "option";
    if (groupKey === "device_type") {
      return {
        title: activated ? "Activate Device" : "Deactivate Device",
        message: activated
          ? "Activate this device? Customers will be able to see it if it has active services and complete pricing."
          : "Deactivate this device? Customers will no longer see this device or the services under it. Existing submitted records will remain unchanged.",
        confirmLabel: activated ? "Activate Device" : "Deactivate Device",
      };
    }
    if (target.matches("[data-value-active]")) {
      return {
        title: activated ? `Activate ${optionGroupName(groupKey)}` : `Deactivate ${optionGroupName(groupKey)}`,
        message: activated
          ? `Activate this ${label}? Customers will be able to select related active prices after you save.`
          : `Deactivate this ${label}? Related prices will be hidden from customers, but existing submitted records will remain unchanged.`,
        confirmLabel: activated ? "Activate" : "Deactivate",
      };
    }
    return {
      title: activated ? "Activate Price Option" : "Deactivate Price Option",
      message: activated
        ? "Activate this price option? Customers will be able to select it after you save if its parent options are active."
        : "Deactivate this price option? Customers will no longer be able to select it, but old submitted records will remain unchanged.",
      confirmLabel: activated ? "Activate" : "Deactivate",
    };
  }

  function rushEditor() {
    const packages = group("package")?.values || [];
    const addons = group("addon")?.values || [];
    const selectedPackage = packages.find((p) => p.value_key === selectedPackageKey);
    const selectedPackageRule = selectedPackage ? findRule({ package: selectedPackage.value_key }) : null;
    const selectedAddon = addons.find((a) => a.value_key === selectedAddonKey);
    const selectedAddonRule = selectedAddon ? findRule({ addon: selectedAddon.value_key }) : null;

    return `<section class="ms-option-section service-edit-section">
      <div class="ms-section-head">
        <div><h4>Packages</h4><p>Select an existing package to edit, or add a new package.</p></div>
      </div>
      <div class="ms-value-list">
        <div class="ms-dropdown-wrapper">
          <label for="ms-package-select">Select Package</label>
          <select id="ms-package-select" data-select-package aria-label="Select package to edit">
            <option value="">-- Select a package --</option>
            ${packages.map((pkg) => `<option value="${escapeHtml(pkg.value_key)}" ${selectedPackageKey === pkg.value_key ? "selected" : ""}>${escapeHtml(pkg.label)}</option>`).join("")}
          </select>
        </div>
        ${selectedPackage ? `<div class="ms-value-row service-form-grid ${Number(selectedPackage.active) ? "" : "is-inactive"}" data-rule-key="${escapeHtml(selectedPackageRule?.rule_key || "")}" data-group-key="package" data-value-key="${escapeHtml(selectedPackage.value_key)}">
          <div class="ms-size-edit-header"><strong>${escapeHtml(selectedPackage.label)}</strong></div>
          ${textInputField({
            label: "Package Name",
            value: selectedPackage.label,
            inputAttrs: `data-value-label aria-label="Package name" class="ms-size-input"`,
          })}
          ${textInputField({
            label: "Inclusions or Details",
            value: selectedPackageRule?.description || selectedPackage.description || "",
            maxLength: optionDescriptionMaxLength,
            inputAttrs: `data-rule-description class="ms-size-input"`,
            placeholder: "Inclusions or details",
            full: true,
          })}
          <div class="ms-status-cell service-action-row">
            ${priceField(`<input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(selectedPackageRule?.price ?? "")}" placeholder="0.00">`)}
            ${statusField(`<label class="ms-switch ms-switch--compact">
              <input data-value-active data-action="toggle-active" type="checkbox" aria-label="Toggle ${escapeHtml(selectedPackage.label)} active status" ${Number(selectedPackage.active) ? "checked" : ""}>
              <span aria-hidden="true"></span><em>${Number(selectedPackage.active) ? "Active" : "Inactive"}</em>
            </label>`)}
          </div>
        </div>` : ""}
      </div>
      <div class="ms-inline-add service-form-grid">
        ${newTextInputField({ label: "Package Name", dataAttr: 'data-new-value="package"', placeholder: "New package name" })}
        ${newTextInputField({ label: "Inclusions or Details", dataAttr: 'data-new-description="package"', maxLength: optionDescriptionMaxLength, placeholder: "Inclusions or details", full: true })}
        <div class="service-action-row">
          ${priceField('<input data-new-price="package" type="number" min="0" step="0.01" placeholder="0.00">')}
          ${statusField('<label class="ms-switch ms-switch--compact"><input data-new-active="package" type="checkbox" checked><span aria-hidden="true"></span><em>Active</em></label>')}
          <button type="button" data-add-value="package">Add</button>
        </div>
      </div>
    </section>
    <section class="ms-option-section service-edit-section">
      <div class="ms-section-head">
        <div><h4>Optional Add-Ons</h4><p>Select an existing add-on to edit, or add a new add-on for the selected package.</p></div>
      </div>
      <div class="ms-value-list">
        <div class="ms-dropdown-wrapper">
          <label for="ms-addon-select">Select Add-On</label>
          <select id="ms-addon-select" data-select-addon aria-label="Select add-on to edit">
            <option value="">-- Select an add-on --</option>
            ${addons.map((addon) => `<option value="${escapeHtml(addon.value_key)}" ${selectedAddonKey === addon.value_key ? "selected" : ""}>${escapeHtml(addon.label)}</option>`).join("")}
          </select>
        </div>
        ${selectedAddon ? `<div class="ms-value-row service-form-grid ${Number(selectedAddon.active) ? "" : "is-inactive"}" data-rule-key="${escapeHtml(selectedAddonRule?.rule_key || "")}" data-group-key="addon" data-value-key="${escapeHtml(selectedAddon.value_key)}">
          <div class="ms-size-edit-header"><strong>${escapeHtml(selectedAddon.label)}</strong></div>
          ${textInputField({
            label: "Add-On Name",
            value: selectedAddon.label,
            inputAttrs: `data-value-label aria-label="Add-on name" class="ms-size-input"`,
          })}
          ${textInputField({
            label: "Inclusions or Details",
            value: selectedAddonRule?.description || selectedAddon.description || "",
            maxLength: optionDescriptionMaxLength,
            inputAttrs: `data-rule-description class="ms-size-input"`,
            placeholder: "Inclusions or details",
            full: true,
          })}
          <div class="ms-status-cell service-action-row">
            ${priceField(`<input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(selectedAddonRule?.price ?? "")}" placeholder="0.00">`)}
            ${statusField(`<label class="ms-switch ms-switch--compact">
              <input data-value-active data-action="toggle-active" type="checkbox" aria-label="Toggle ${escapeHtml(selectedAddon.label)} active status" ${Number(selectedAddon.active) ? "checked" : ""}>
              <span aria-hidden="true"></span><em>${Number(selectedAddon.active) ? "Active" : "Inactive"}</em>
            </label>`)}
          </div>
        </div>` : ""}
      </div>
      <div class="ms-inline-add service-form-grid">
        ${newTextInputField({ label: "Add-On Name", dataAttr: 'data-new-value="addon"', placeholder: "New add-on name" })}
        ${newTextInputField({ label: "Inclusions or Details", dataAttr: 'data-new-description="addon"', maxLength: optionDescriptionMaxLength, placeholder: "Inclusions or details", full: true })}
        <div class="service-action-row">
          ${priceField('<input data-new-price="addon" type="number" min="0" step="0.01" placeholder="0.00">')}
          ${statusField('<label class="ms-switch ms-switch--compact"><input data-new-active="addon" type="checkbox" checked><span aria-hidden="true"></span><em>Active</em></label>')}
          <button type="button" data-add-value="addon">Add</button>
        </div>
      </div>
    </section>`;
  }

  function repairEditor() {
    const devices = group("device_type")?.values || [];
    const selectedDevice = devices.find((d) => d.value_key === selectedRepairDeviceKey);
    const rules = selectedDevice
      ? (catalog.rules || []).filter((rule) => rule.option_value_keys?.device_type === selectedDevice.value_key)
          .sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0))
      : [];

    return `${valueManager("device_type", "Devices", "New device")}
      <section class="ms-pricing-section service-edit-section">
        <div class="ms-section-head"><div><h4>Repair Services by Device</h4><p>Select a device from the dropdown to manage its services.</p></div></div>
        <div class="ms-dropdown-wrapper">
          <label for="ms-repair-device-select">Select Device</label>
          <select id="ms-repair-device-select" data-select-repair-device aria-label="Select device to edit">
            <option value="">-- Select a device --</option>
            ${devices.filter((device) => Number(device.active)).map((device) => `<option value="${escapeHtml(device.value_key)}" ${selectedRepairDeviceKey === device.value_key ? "selected" : ""}>${escapeHtml(device.label)}</option>`).join("")}
          </select>
        </div>
        ${selectedDevice ? `<div class="ms-device-card-wrapper">
          <div class="ms-device-card__body">
            ${rules.length ? "" : '<p class="ms-device-guidance">Add repair services available for this device.</p>'}
            ${rules.map((rule, ruleIndex) => {
              const value = groupValue("repair_type", rule.option_value_keys?.repair_type);
              if (!value) return "";
              return `<div class="ms-repair-row service-form-grid" data-rule-key="${escapeHtml(rule.rule_key)}">
                ${textInputField({
                  label: "Service Name",
                  value: value.label,
                  inputAttrs: `data-value-label data-group-key="repair_type" data-value-key="${escapeHtml(value.value_key)}"`,
                })}
                <div class="service-action-row">
                  ${priceField(`<input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(rule.price ?? "")}" placeholder="0.00">`)}
                  ${selectField({ label: "Price Type", control: `<select data-rule-price-type><option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option><option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For Assessment</option></select>` })}
                  ${statusField(toggleControl(Number(rule.active), Number(rule.active) ? "Active" : "Inactive", `Toggle ${value.label} active status`))}
                </div>
                ${ruleMovementButtons(rule.rule_key, value.label, ruleIndex, rules.length)}
              </div>`;
            }).join("")}
            <div class="ms-inline-add ms-inline-add--rule service-form-grid">
              ${newTextInputField({ label: "Service Name", dataAttr: `data-new-repair="${escapeHtml(selectedDevice.value_key)}"`, placeholder: "Service name" })}
              <div class="service-action-row">
                ${priceField(`<input data-new-repair-price="${escapeHtml(selectedDevice.value_key)}" type="number" min="0" step="0.01" placeholder="0.00">`)}
                ${selectField({ label: "Price Type", control: `<select data-new-repair-price-type="${escapeHtml(selectedDevice.value_key)}"><option value="fixed">Fixed Price</option><option value="assessment">For Assessment</option></select>` })}
                ${statusField(`<label class="ms-switch ms-switch--compact"><input data-new-repair-active="${escapeHtml(selectedDevice.value_key)}" type="checkbox" checked><span aria-hidden="true"></span><em>Active</em></label>`)}
                <button type="button" data-add-repair="${escapeHtml(selectedDevice.value_key)}">Add Service</button>
              </div>
            </div>
          </div>
        </div>` : ""}
      </section>`;
  }

  function laminatingEditor() {
    const types = group("lamination_type")?.values || [];
    const selectedType = types.find((t) => t.value_key === selectedLaminationTypeKey);
    const selectedRule = selectedType ? findRule({ lamination_type: selectedType.value_key }) : null;

    return `<section class="ms-option-section service-edit-section">
      <div class="ms-section-head">
        <div><h4>Laminating Types</h4><p>Select an existing laminating type to edit, or add a new type.</p></div>
      </div>
      <div class="ms-value-list">
        <div class="ms-dropdown-wrapper">
          <label for="ms-laminating-type-select">Select Type</label>
          <select id="ms-laminating-type-select" data-select-laminating-type aria-label="Select laminating type to edit">
            <option value="">-- Select a type --</option>
            ${types.map((type) => `<option value="${escapeHtml(type.value_key)}" ${selectedLaminationTypeKey === type.value_key ? "selected" : ""}>${escapeHtml(type.label)}</option>`).join("")}
          </select>
        </div>
        ${selectedType ? `<div class="ms-value-row service-form-grid ${Number(selectedType.active) ? "" : "is-inactive"}" data-rule-key="${escapeHtml(selectedRule?.rule_key || "")}" data-group-key="lamination_type" data-value-key="${escapeHtml(selectedType.value_key)}">
          <div class="ms-size-edit-header"><strong>${escapeHtml(selectedType.label)}</strong></div>
          ${textInputField({
            label: "Laminating Type Name",
            value: selectedType.label,
            inputAttrs: `data-value-label aria-label="Laminating type name" class="ms-size-input"`,
          })}
          <div class="ms-status-cell service-action-row">
            ${priceField(`<input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(selectedRule?.price ?? "")}" placeholder="0.00">`)}
            ${statusField(`<label class="ms-switch ms-switch--compact">
              <input data-value-active data-action="toggle-active" type="checkbox" aria-label="Toggle ${escapeHtml(selectedType.label)} active status" ${Number(selectedType.active) ? "checked" : ""}>
              <span aria-hidden="true"></span><em>${Number(selectedType.active) ? "Active" : "Inactive"}</em>
            </label>`)}
          </div>
        </div>` : ""}
      </div>
      <div class="ms-inline-add service-form-grid">
        ${newTextInputField({ label: "Laminating Type Name", dataAttr: 'data-new-value="lamination_type"', placeholder: "New laminating type" })}
        <div class="service-action-row">
          ${priceField('<input data-new-price="lamination_type" type="number" min="0" step="0.01" placeholder="0.00">')}
          ${statusField('<label class="ms-switch ms-switch--compact"><input data-new-active="lamination_type" type="checkbox" checked><span aria-hidden="true"></span><em>Active</em></label>')}
          <button type="button" data-add-value="lamination_type">Add</button>
        </div>
      </div>
    </section>`;
  }

  function simpleInstallationEditor() {
    const types = group("installation_type")?.values || [];
    const selectedType = types.find((t) => t.value_key === selectedSimpleInstallationTypeKey);
    const selectedRule = selectedType ? findRule({ installation_type: selectedType.value_key }) : null;

    return `<section class="ms-option-section service-edit-section">
      <div class="ms-section-head">
        <div><h4>Installation Types</h4><p>Select an existing installation type to edit, or add a new type.</p></div>
      </div>
      <div class="ms-value-list">
        <div class="ms-dropdown-wrapper">
          <label for="ms-simple-installation-select">Select Installation Type</label>
          <select id="ms-simple-installation-select" data-select-simple-installation-type aria-label="Select installation type to edit">
            <option value="">-- Select a type --</option>
            ${types.map((type) => `<option value="${escapeHtml(type.value_key)}" ${selectedSimpleInstallationTypeKey === type.value_key ? "selected" : ""}>${escapeHtml(type.label)}</option>`).join("")}
          </select>
        </div>
        ${selectedType ? `<div class="ms-value-row service-form-grid ${Number(selectedType.active) ? "" : "is-inactive"}" data-rule-key="${escapeHtml(selectedRule?.rule_key || "")}" data-group-key="installation_type" data-value-key="${escapeHtml(selectedType.value_key)}">
          <div class="ms-size-edit-header"><strong>${escapeHtml(selectedType.label)}</strong></div>
          ${textInputField({
            label: "Installation Type Name",
            value: selectedType.label,
            inputAttrs: `data-value-label aria-label="Installation type name" class="ms-size-input"`,
          })}
          <div class="ms-status-cell service-action-row">
            ${priceField(`<input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(selectedRule?.price ?? "")}" placeholder="0.00">`)}
            ${selectField({ label: "Price Type", control: `<select data-rule-price-type aria-label="Price type"><option value="fixed" ${selectedRule?.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option><option value="assessment" ${selectedRule?.price_type === "assessment" ? "selected" : ""}>For Assessment</option></select>` })}
            ${statusField(`<label class="ms-switch ms-switch--compact">
              <input data-value-active data-action="toggle-active" type="checkbox" aria-label="Toggle ${escapeHtml(selectedType.label)} active status" ${Number(selectedType.active) ? "checked" : ""}>
              <span aria-hidden="true"></span><em>${Number(selectedType.active) ? "Active" : "Inactive"}</em>
            </label>`)}
          </div>
        </div>` : ""}
      </div>
      <div class="ms-inline-add service-form-grid">
        ${newTextInputField({ label: "Installation Type Name", dataAttr: 'data-new-value="installation_type"', placeholder: "New installation type" })}
        <div class="service-action-row">
          ${statusField('<label class="ms-switch ms-switch--compact"><input data-new-active="installation_type" type="checkbox" checked><span aria-hidden="true"></span><em>Active</em></label>')}
          <button type="button" data-add-value="installation_type">Add</button>
        </div>
      </div>
    </section>`;
  }

  function installationDeviceEditor() {
    const devices = group("device_type")?.values || [];
    const selectedDevice = devices.find((d) => d.value_key === selectedInstallationDeviceKey);
    const rules = selectedDevice
      ? (catalog.rules || []).filter((rule) => rule.option_value_keys?.device_type === selectedDevice.value_key)
          .sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0))
      : [];

    return `${valueManager("device_type", "Devices", "New device")}
      <section class="ms-pricing-section service-edit-section">
        <div class="ms-section-head"><div><h4>Installation Services by Device</h4><p>Select a device from the dropdown to manage its services.</p></div></div>
        <div class="ms-dropdown-wrapper">
          <label for="ms-installation-device-select">Select Device</label>
          <select id="ms-installation-device-select" data-select-installation-device aria-label="Select device to edit">
            <option value="">-- Select a device --</option>
            ${devices.filter((device) => Number(device.active)).map((device) => `<option value="${escapeHtml(device.value_key)}" ${selectedInstallationDeviceKey === device.value_key ? "selected" : ""}>${escapeHtml(device.label)}</option>`).join("")}
          </select>
        </div>
        ${selectedDevice ? `<div class="ms-device-card-wrapper">
          <div class="ms-device-card__body">
            ${rules.length ? "" : '<p class="ms-device-guidance">Add installation services available for this device.</p>'}
            ${rules.map((rule, ruleIndex) => {
              const value = groupValue("installation_type", rule.option_value_keys?.installation_type);
              if (!value) return "";
              return `<div class="ms-repair-row service-form-grid" data-rule-key="${escapeHtml(rule.rule_key)}">
                ${textInputField({
                  label: "Service Name",
                  value: value.label,
                  inputAttrs: `data-value-label data-group-key="installation_type" data-value-key="${escapeHtml(value.value_key)}"`,
                })}
                <div class="service-action-row">
                  ${priceField(`<input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(rule.price ?? "")}" placeholder="0.00">`)}
                  ${selectField({ label: "Price Type", control: `<select data-rule-price-type><option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option><option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For Assessment</option></select>` })}
                  ${statusField(toggleControl(Number(rule.active), Number(rule.active) ? "Active" : "Inactive", `Toggle ${value.label} active status`))}
                </div>
                ${ruleMovementButtons(rule.rule_key, value.label, ruleIndex, rules.length)}
              </div>`;
            }).join("")}
            <div class="ms-inline-add ms-inline-add--rule service-form-grid">
              ${newTextInputField({ label: "Service Name", dataAttr: `data-new-installation="${escapeHtml(selectedDevice.value_key)}"`, placeholder: "Service name" })}
              <div class="service-action-row">
                ${priceField(`<input data-new-installation-price="${escapeHtml(selectedDevice.value_key)}" type="number" min="0" step="0.01" placeholder="0.00">`)}
                ${selectField({ label: "Price Type", control: `<select data-new-installation-price-type="${escapeHtml(selectedDevice.value_key)}"><option value="fixed">Fixed Price</option><option value="assessment">For Assessment</option></select>` })}
                ${statusField(`<label class="ms-switch ms-switch--compact"><input data-new-installation-active="${escapeHtml(selectedDevice.value_key)}" type="checkbox" checked><span aria-hidden="true"></span><em>Active</em></label>`)}
                <button type="button" data-add-installation="${escapeHtml(selectedDevice.value_key)}">Add Service</button>
              </div>
            </div>
          </div>
        </div>` : ""}
      </section>`;
  }

  function installationEditor() {
    const deviceMode = Number(group("device_type")?.active) === 1;
    return `<section class="ms-option-section service-edit-section ms-mode-section"><div><h4>Pricing Setup</h4><p>Use a simple service list, or enable device-specific installation pricing.</p></div><label class="ms-switch"><input data-installation-device-mode type="checkbox" ${deviceMode ? "checked" : ""}><span aria-hidden="true"></span><em>Use Device Category</em></label></section>
      ${deviceMode
        ? installationDeviceEditor()
        : simpleInstallationEditor()}`;
  }

  function render() {
    if (!catalog || !currentKind) return;
    let content = "";
    if (currentKind === "document_printing" || currentKind === "photocopy") content = matrixEditor();
    else if (currentKind === "rush_id") content = rushEditor();
    else if (currentKind === "laminating") content = laminatingEditor();
    else if (currentKind === "scanning") content = simpleRuleRows("paper_size", "Scanning Paper Sizes", "New paper size", { nameLabel: "Paper Size" });
    else if (currentKind === "repair") content = repairEditor();
    else if (currentKind === "installation") content = installationEditor();
    const warning = serviceAvailabilityWarning();
    editor.innerHTML = `${warning ? `<div class="ms-option-warning ms-service-warning" role="status">${escapeHtml(warning)}</div>` : ""}${content}`;
    syncCharacterCounters(editor);
  }

  function syncFromDom({ includeStatus = true } = {}) {
    qsa("[data-group-key][data-value-key]", editor).forEach((row) => {
      const value = groupValue(row.dataset.groupKey, row.dataset.valueKey);
      if (!value) return;
      const labelInput = qs("[data-value-label]", row);
      const activeInput = qs("[data-value-active]", row);
      if (labelInput) value.label = labelInput.value.trim() || value.label;
      if (activeInput && includeStatus) value.active = activeInput.checked ? 1 : 0;
    });
    qsa("[data-value-label][data-group-key][data-value-key]", editor).forEach((input) => {
      const value = groupValue(input.dataset.groupKey, input.dataset.valueKey);
      if (value) value.label = input.value.trim() || value.label;
    });
    qsa("[data-rule-key]", editor).forEach((row) => {
      const rule = (catalog.rules || []).find((item) => item.rule_key === row.dataset.ruleKey);
      if (!rule) return;
      const price = qs("[data-rule-price]", row);
      const priceType = qs("[data-rule-price-type]", row);
      const active = qs("[data-rule-active]", row);
      const description = qs("[data-rule-description]", row);
      if (price) rule.price = price.value.trim();
      if (priceType) rule.price_type = priceType.value;
      if (active && includeStatus) rule.active = active.checked ? 1 : 0;
      if (active && includeStatus && row.dataset.groupKey && row.dataset.valueKey) {
        const value = groupValue(row.dataset.groupKey, row.dataset.valueKey);
        if (value) value.active = active.checked ? 1 : 0;
      }
      if (description) rule.description = description.value.trim();
      const labels = Object.entries(rule.option_value_keys || {}).map(([key, valueKey]) => groupValue(key, valueKey)?.label || "").filter(Boolean);
      rule.label = labels.join(" / ");
    });
    resequenceCatalog();
    return catalog;
  }

  function resequenceCatalog() {
    const indexFor = (groupKey, valueKey) => {
      const values = group(groupKey)?.values || [];
      const index = values.findIndex((value) => String(value.value_key) === String(valueKey));
      return index < 0 ? 9999 : index;
    };
    (catalog?.groups || []).forEach((item) => {
      (item.values || []).forEach((value, index) => { value.sort_order = index; });
    });
    const deviceRuleIndexes = new Map();
    if (["repair", "installation"].includes(currentKind)) {
      (group("device_type")?.values || []).forEach((device) => {
        (catalog?.rules || [])
          .filter((rule) => rule.option_value_keys?.device_type === device.value_key)
          .sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0))
          .forEach((rule, index) => deviceRuleIndexes.set(rule.rule_key, index));
      });
    }
    (catalog?.rules || []).forEach((rule, currentIndex) => {
      const keys = rule.option_value_keys || {};
      if (["document_printing", "photocopy"].includes(currentKind)) {
        rule.sort_order = (indexFor("paper_size", keys.paper_size) * 1000) + indexFor("color_option", keys.color_option);
      } else if (currentKind === "rush_id") {
        rule.sort_order = keys.package !== undefined
          ? indexFor("package", keys.package)
          : 10000 + indexFor("addon", keys.addon);
      } else if (currentKind === "repair") {
        rule.sort_order = (indexFor("device_type", keys.device_type) * 1000) + (deviceRuleIndexes.get(rule.rule_key) ?? currentIndex);
      } else if (currentKind === "installation" && keys.device_type !== undefined) {
        rule.sort_order = (indexFor("device_type", keys.device_type) * 1000) + (deviceRuleIndexes.get(rule.rule_key) ?? currentIndex);
      } else {
        const groupKey = Object.keys(keys)[0];
        rule.sort_order = groupKey ? indexFor(groupKey, keys[groupKey]) : currentIndex;
      }
    });
    catalog?.rules?.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
  }

  function addValue(groupKey, label) {
    const item = group(groupKey);
    const valueKey = slug(label);
    const existing = (item.values || []).find((value) => value.value_key === valueKey);
    if (existing) {
      existing.active = 1;
      existing.label = label;
      return existing;
    }
    const value = { value_key: valueKey, label, description: "", active: 1, sort_order: item.values.length };
    item.values.push(value);
    return value;
  }

  editor?.addEventListener("click", async (event) => {
    const toggleTarget = event.target.closest('input[data-action="toggle-active"]');
    if (toggleTarget) {
      event.stopPropagation();
      return;
    }

    const moveButton = event.target.closest('button[data-action="move-up"], button[data-action="move-down"]');
    if (moveButton) {
      event.preventDefault();
      event.stopPropagation();
      syncFromDom({ includeStatus: false });
      const values = group(moveButton.dataset.moveValue)?.values || [];
      const index = values.findIndex((value) => value.value_key === moveButton.dataset.valueKey);
      const targetIndex = index + Number(moveButton.dataset.direction);
      if (index >= 0 && targetIndex >= 0 && targetIndex < values.length) {
        [values[index], values[targetIndex]] = [values[targetIndex], values[index]];
        resequenceCatalog();
        render();
      }
      return;
    }

    const moveRuleButton = event.target.closest('button[data-action="move-rule-up"], button[data-action="move-rule-down"]');
    if (moveRuleButton) {
      event.preventDefault();
      event.stopPropagation();
      syncFromDom({ includeStatus: false });
      const rule = (catalog.rules || []).find((item) => item.rule_key === moveRuleButton.dataset.ruleKey);
      const deviceKey = rule?.option_value_keys?.device_type;
      const rules = (catalog.rules || [])
        .filter((item) => item.option_value_keys?.device_type === deviceKey)
        .sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
      const index = rules.findIndex((item) => item.rule_key === moveRuleButton.dataset.ruleKey);
      const targetIndex = index + Number(moveRuleButton.dataset.direction);
      if (index >= 0 && targetIndex >= 0 && targetIndex < rules.length) {
        const currentOrder = Number(rules[index].sort_order || index);
        rules[index].sort_order = Number(rules[targetIndex].sort_order || targetIndex);
        rules[targetIndex].sort_order = currentOrder;
        catalog.rules.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
        render();
      }
      return;
    }

    const addButton = event.target.closest("[data-add-value]");
    if (addButton) {
      syncFromDom();
      const groupKey = addButton.dataset.addValue;
      const input = qs(`[data-new-value="${groupKey}"]`, editor);
      const label = input?.value.trim() || "";
      if (!label) return showError("Enter a name before adding the option.");
      const priceInput = qs(`[data-new-price="${groupKey}"]`, editor);
      const priceTypeInput = qs(`[data-new-price-type="${groupKey}"]`, editor);
      const activeInput = qs(`[data-new-active="${groupKey}"]`, editor);
      const descriptionInput = qs(`[data-new-description="${groupKey}"]`, editor);
      const priceType = priceTypeInput?.value === "assessment" ? "assessment" : "fixed";
      const active = activeInput ? activeInput.checked : true;
      const price = priceInput?.value.trim() || "";
      if (priceInput && active && priceType === "fixed" && (price === "" || !Number.isFinite(Number(price)) || Number(price) <= 0)) {
        return showError("Enter a valid price, or choose For Assessment before adding this option.");
      }
      if (!await confirmAction({
        title: `Add ${optionGroupName(groupKey)}`,
        message: addConfirmationMessage(groupKey, active),
        confirmLabel: "Add Option",
      })) return;
      const value = addValue(groupKey, label);
      value.active = active ? 1 : 0;
      value.description = descriptionInput?.value.trim() || value.description || "";
      if (priceInput) {
        const rule = ensureRule({ [groupKey]: value.value_key }, value.label, catalog.rules.length, priceType);
        rule.description = value.description;
        rule.price = priceType === "fixed" ? price : "";
        rule.price_type = priceType;
        rule.active = active ? 1 : 0;
      }
      render();
      if (groupKey === "device_type") {
        window.servitechAdminToast?.success?.("Device added to this draft. Add services available for this device.");
      } else {
        window.servitechAdminToast?.success?.(`${optionGroupName(groupKey)} added to this draft. Save changes to publish it.`);
      }
      return;
    }

    const repairButton = event.target.closest("[data-add-repair]");
    if (repairButton) {
      syncFromDom();
      const deviceKey = repairButton.dataset.addRepair;
      const input = qs(`[data-new-repair="${deviceKey}"]`, editor);
      const label = input?.value.trim() || "";
      if (!label) return showError("Enter a repair service name first.");
      const price = qs(`[data-new-repair-price="${deviceKey}"]`, editor)?.value.trim() || "";
      const priceType = qs(`[data-new-repair-price-type="${deviceKey}"]`, editor)?.value === "assessment" ? "assessment" : "fixed";
      const active = qs(`[data-new-repair-active="${deviceKey}"]`, editor)?.checked !== false;
      if (active && priceType === "fixed" && (price === "" || !Number.isFinite(Number(price)) || Number(price) <= 0)) return showError("Enter a price greater than zero, or choose For Assessment.");
      if (!await confirmAction({
        title: "Add Repair Service",
        message: "Add this repair service? It will appear under the selected device and may become available to customers after you save.",
        confirmLabel: "Add Service",
      })) return;
      const value = addValue("repair_type", label);
      const rule = ensureRule({ device_type: deviceKey, repair_type: value.value_key }, `${groupValue("device_type", deviceKey)?.label || "Device"} / ${value.label}`, catalog.rules.length, priceType);
      rule.price = priceType === "fixed" ? price : "";
      rule.price_type = priceType;
      rule.active = active ? 1 : 0;
      render();
      window.servitechAdminToast?.success?.("Repair service added to this draft. Save changes to publish it.");
      return;
    }

    const installationButton = event.target.closest("[data-add-installation]");
    if (installationButton) {
      syncFromDom();
      const deviceKey = installationButton.dataset.addInstallation;
      const input = qs(`[data-new-installation="${deviceKey}"]`, editor);
      const label = input?.value.trim() || "";
      if (!label) return showError("Enter an installation service name first.");
      const price = qs(`[data-new-installation-price="${deviceKey}"]`, editor)?.value.trim() || "";
      const priceType = qs(`[data-new-installation-price-type="${deviceKey}"]`, editor)?.value === "assessment" ? "assessment" : "fixed";
      const active = qs(`[data-new-installation-active="${deviceKey}"]`, editor)?.checked !== false;
      if (active && priceType === "fixed" && (price === "" || !Number.isFinite(Number(price)) || Number(price) <= 0)) return showError("Enter a price greater than zero, or choose For Assessment.");
      if (!await confirmAction({
        title: "Add Installation Service",
        message: "Add this installation service? It will appear under the selected device and may become available to customers after you save.",
        confirmLabel: "Add Service",
      })) return;
      const value = addValue("installation_type", label);
      const rule = ensureRule({ device_type: deviceKey, installation_type: value.value_key }, `${groupValue("device_type", deviceKey)?.label || "Device"} / ${value.label}`, catalog.rules.length, priceType);
      rule.price = priceType === "fixed" ? price : "";
      rule.price_type = priceType;
      rule.active = active ? 1 : 0;
      render();
      window.servitechAdminToast?.success?.("Installation service added to this draft. Save changes to publish it.");
      return;
    }

  });

  editor?.addEventListener("input", (event) => {
    if (event.target.matches("[data-character-count]")) {
      syncCharacterCounters(editor);
    }
  });

  editor?.addEventListener("change", async (event) => {
    if (event.target.matches("[data-select-paper-size]")) {
      event.stopPropagation();
      selectedPaperSizeKey = event.target.value || null;
      render();
      return;
    }

    if (event.target.matches("[data-select-color]")) {
      event.stopPropagation();
      selectedColorKey = event.target.value || null;
      render();
      return;
    }

    if (event.target.matches("[data-select-package]")) {
      event.stopPropagation();
      selectedPackageKey = event.target.value || null;
      render();
      return;
    }

    if (event.target.matches("[data-select-addon]")) {
      event.stopPropagation();
      selectedAddonKey = event.target.value || null;
      render();
      return;
    }

    if (event.target.matches("[data-select-laminating-type]")) {
      event.stopPropagation();
      selectedLaminationTypeKey = event.target.value || null;
      render();
      return;
    }

    if (event.target.matches("[data-select-repair-device]")) {
      event.stopPropagation();
      selectedRepairDeviceKey = event.target.value || null;
      render();
      return;
    }

    if (event.target.matches("[data-select-installation-device]")) {
      event.stopPropagation();
      selectedInstallationDeviceKey = event.target.value || null;
      render();
      return;
    }

    if (event.target.matches("[data-select-simple-installation-type]")) {
      event.stopPropagation();
      selectedSimpleInstallationTypeKey = event.target.value || null;
      render();
      return;
    }

    if (event.target.matches("[data-installation-device-mode]")) {
      const enabled = event.target.checked;
      if (!await confirmAction({
        title: enabled ? "Enable Device-Based Installation" : "Use Simple Installation Setup",
        message: enabled
          ? "Enable device-based installation setup? Installation services will be grouped by device, similar to Repair Services."
          : "Disable device-based installation setup? Customers will use the simple installation service list after you save.",
        confirmLabel: enabled ? "Enable Device Setup" : "Use Simple Setup",
      })) {
        event.target.checked = !enabled;
        return;
      }
      syncFromDom();
      const deviceGroup = group("device_type");
      if (deviceGroup) deviceGroup.active = enabled ? 1 : 0;
      render();
      window.servitechAdminToast?.success?.(enabled
        ? "Device pricing enabled in this draft. Add a device, then add its installation services."
        : "Simple installation pricing enabled in this draft.");
      return;
    }
    if (event.target.matches('input[data-action="toggle-active"][data-rule-active], input[data-action="toggle-active"][data-value-active]')) {
      const activated = event.target.checked;
      const copy = toggleConfirmation(event.target, activated);
      if (!await confirmAction({
        title: copy.title,
        message: copy.message,
        confirmLabel: copy.confirmLabel,
        tone: activated ? "primary" : "warning",
      })) {
        event.target.checked = !activated;
        return;
      }
      syncFromDom();
      render();
      window.servitechAdminToast?.success?.(activated
        ? "Option activated in this draft. Save changes to publish it."
        : "Option deactivated in this draft. Save changes to publish it.");
    }
  });

  async function fetchCatalog(id) {
    const response = await fetch(`${apiUrl}?action=catalog&id=${encodeURIComponent(id)}`, { credentials: "same-origin", cache: "no-store" });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || "Unable to load service options.");
    return data.catalog;
  }

  async function openEditor(data) {
    if (!overlay || !editorDialog || !editor || !errorBox || !confirmOverlay || !confirmDialog) {
      throw new Error("Required editor modal elements are missing from the page.");
    }
    const id = Number(data?.id);
    const category = String(data?.category || "").trim().toLowerCase();
    const name = String(data?.name || "").trim();
    const kind = serviceKind(category, name);
    if (!Number.isInteger(id) || id <= 0 || !["printing", "repair", "installation"].includes(category) || !kind || !contracts[kind]) {
      throw new Error(`Invalid or unsupported service payload: ${JSON.stringify(data || {})}`);
    }

    const loadToken = ++editorLoadToken;
    clearPageError();
    hideError();
    fields.id.value = id;
    fields.category.value = category;
    fields.name.value = name;
    fields.description.value = data.description || "";
    fields.sort.value = data.sort_order || 0;
    fields.active.checked = Number(data.active) === 1;
    syncServiceStatusLabel();
    syncCharacterCounters(document);
    originalServiceSnapshot = {
      name: data.name || "",
      description: data.description || "",
      active: Number(data.active) === 1,
      sort_order: Number(data.sort_order || 0),
    };
    currentKind = kind;
    const serviceNameField = qs("#msServiceNameField");
    if (serviceNameField) serviceNameField.hidden = !["laminating", "scanning"].includes(currentKind);
    const contract = contracts[currentKind];
    qs("#msModalTitle").textContent = contract?.title || `Edit ${name}`;
    qs("#msModalHelp").textContent = contract?.help || "";
    if (!openModalLayer(overlay, editorDialog, editorDialog, closeEditor)) {
      throw new Error("The editor modal layer could not be opened.");
    }
    selectedPaperSizeKey = null;
    selectedColorKey = null;
    selectedPackageKey = null;
    selectedAddonKey = null;
    selectedLaminationTypeKey = null;
    selectedRepairDeviceKey = null;
    selectedInstallationDeviceKey = null;
    selectedSimpleInstallationTypeKey = null;
    editor.innerHTML = '<div class="ms-loading">Loading current options...</div>';
    try {
      const fetchedCatalog = await fetchCatalog(data.id);
      if (loadToken !== editorLoadToken || !isEditorOpen()) return;
      catalog = normalizeCatalog(fetchedCatalog);
      originalSnapshot = JSON.stringify(catalog);
      render();
    } catch (error) {
      if (loadToken !== editorLoadToken || !isEditorOpen()) return;
      console.error(`[Manage Services] Catalog load failed for service ${id}:`, error);
      showError(error.message || "Unable to load service options.");
    }
  }

  function closeEditor() {
    if (!closeModalLayer(overlay, editorDialog)) return;
    editorLoadToken += 1;
    catalog = null;
    originalSnapshot = "";
    originalServiceSnapshot = null;
    selectedPaperSizeKey = null;
    selectedColorKey = null;
    selectedPackageKey = null;
    selectedAddonKey = null;
    selectedLaminationTypeKey = null;
    selectedRepairDeviceKey = null;
    selectedInstallationDeviceKey = null;
    selectedSimpleInstallationTypeKey = null;
    hideError();
  }

  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-ms-edit]");
    if (!button) return;
    event.preventDefault();
    if (button.disabled || button.getAttribute("aria-busy") === "true") return;
    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    try {
      const data = JSON.parse(button.getAttribute("data-ms-edit") || "{}");
      data.id = data.id || button.dataset.serviceId;
      data.category = data.category || button.dataset.serviceCategory;
      data.name = data.name || button.dataset.serviceName;
      await openEditor(data);
    } catch (error) {
      reportEditorOpenError(error);
    } finally {
      button.disabled = false;
      button.removeAttribute("aria-busy");
    }
  });
  fields.active?.addEventListener("change", async () => {
    const activated = fields.active.checked;
    if (!await confirmAction({
      title: activated ? "Activate Service" : "Deactivate Service",
      message: activated
        ? "Activate this service? Customers will be able to see and select its active options after you save."
        : "Deactivate this service? Customers will no longer be able to select it, but old submitted records will remain unchanged.",
      confirmLabel: activated ? "Activate Service" : "Deactivate Service",
      tone: activated ? "primary" : "warning",
    })) {
      fields.active.checked = !activated;
      syncServiceStatusLabel();
      return;
    }
    syncServiceStatusLabel();
    render();
    window.servitechAdminToast?.success?.(activated
      ? "Service activated in this draft. Save changes to publish it."
      : "Service deactivated in this draft. Save changes to publish it.");
  });
  fields.name?.addEventListener("input", () => syncCharacterCounters(document));
  fields.description?.addEventListener("input", () => syncCharacterCounters(document));
  qs("#msX")?.addEventListener("click", closeEditor);
  qs("#msCancel")?.addEventListener("click", closeEditor);
  overlay?.addEventListener("click", (event) => {
    if (event.target === overlay && isTopModal(overlay)) closeEditor();
  });

  qs("#msSave")?.addEventListener("click", async () => {
    hideError();
    const payload = syncFromDom();
    const invalidRule = (payload.rules || []).find((rule) =>
      Number(rule.active) && rule.price_type === "fixed" && (rule.price === "" || !Number.isFinite(Number(rule.price)) || Number(rule.price) <= 0)
    );
    if (invalidRule) return showError(`Enter a valid price for ${invalidRule.label || "the active option"}, or mark it For Assessment.`);
    if (currentKind === "installation" && Number(group("device_type")?.active) === 1) {
      const hasDeviceService = (payload.rules || []).some((rule) => Number(rule.active)
        && rule.option_value_keys?.device_type && rule.option_value_keys?.installation_type);
      if (!hasDeviceService) return showError("Add at least one active installation service under a device before enabling Device Category.");
    }

    const catalogChanged = JSON.stringify(payload) !== originalSnapshot;
    const serviceChanged = !originalServiceSnapshot
      || fields.name.value.trim() !== originalServiceSnapshot.name
      || fields.description.value.trim() !== originalServiceSnapshot.description
      || fields.active.checked !== originalServiceSnapshot.active
      || Number(fields.sort.value || 0) !== originalServiceSnapshot.sort_order;
    if (!catalogChanged && !serviceChanged) {
      window.servitechAdminToast?.show?.("No service changes to save.", "info");
      return;
    }
    const saveMessage = currentKind === "rush_id"
      ? "Save these RUSH ID package, add-on, and price changes? Add-on prices will be included in future customer totals. Existing submitted orders will keep their original saved details and price."
      : "Save these option and price changes? Updated names and prices will appear on the landing page and customer queue forms. Existing submitted orders will keep their original saved details and price.";
    if (!await confirmAction({
      title: "Save Service Changes",
      message: saveMessage,
      confirmLabel: "Save Changes",
    })) return;

    const form = new FormData();
    form.append("action", "save");
    form.append("id", fields.id.value);
    form.append("category", fields.category.value);
    form.append("name", fields.name.value);
    form.append("description", fields.description.value.trim());
    form.append("catalog_json", JSON.stringify(payload));
    form.append("active", fields.active.checked ? "1" : "0");
    form.append("sort_order", fields.sort.value || "0");

    qs("#msSave").disabled = true;
    try {
      const response = await fetch(apiUrl, {
        method: "POST", body: form, credentials: "same-origin",
        headers: { "X-CSRF-Token": csrf() },
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || "Failed to save changes. Please try again.");
      catalog = normalizeCatalog(data.catalog || payload);
      originalSnapshot = JSON.stringify(catalog);
      if (data.service) {
        fields.name.value = data.service.name || fields.name.value;
        fields.description.value = data.service.description || "";
        fields.active.checked = Number(data.service.active) === 1;
        fields.sort.value = data.service.sort_order || fields.sort.value;
      }
      originalServiceSnapshot = {
        name: fields.name.value.trim(),
        description: fields.description.value.trim(),
        active: fields.active.checked,
        sort_order: Number(fields.sort.value || 0),
      };
      syncServiceStatusLabel();
      updateServiceCard(data.service);
      render();
      window.servitechAdminToast?.success?.(data.message || `${fields.name.value} updated successfully.`);
    } catch (error) {
      showError(error.message || "Failed to save changes. Please try again.");
    } finally {
      qs("#msSave").disabled = false;
    }
  });
})();
