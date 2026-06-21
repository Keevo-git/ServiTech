(function () {
  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");
  const apiUrl = window.MS_API_URL || "/pages/admin/Services/services_api.php";

  const overlay = qs("#msOverlay");
  const editor = qs("#ms_catalogEditor");
  const errorBox = qs("#msErr");
  const confirmOverlay = qs("#msConfirmOverlay");
  const confirmDialog = qs(".ms-confirm-dialog", confirmOverlay);
  const confirmTitle = qs("#msConfirmTitle");
  const confirmMessage = qs("#msConfirmMessage");
  const confirmCancel = qs("#msConfirmCancel");
  const confirmAccept = qs("#msConfirmAccept");
  const fields = {
    id: qs("#ms_id"),
    category: qs("#ms_category"),
    name: qs("#ms_name"),
    description: qs("#ms_description"),
    active: qs("#ms_active"),
    activeLabel: qs("#ms_active_label"),
    sort: qs("#ms_sort"),
  };

  let catalog = null;
  let originalSnapshot = "";
  let originalServiceSnapshot = null;
  let currentKind = "";
  let confirmResolver = null;
  let confirmReturnFocus = null;
  let editorReturnFocus = null;

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
      groups: { installation_type: "Installation Type" },
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

  function syncServiceStatusLabel() {
    if (!fields.activeLabel || !fields.active) return;
    fields.activeLabel.textContent = fields.active.checked ? "Active" : "Inactive";
  }

  function group(key) {
    return (catalog?.groups || []).find((item) => item.group_key === key);
  }

  function groupValue(groupKey, valueKey) {
    return (group(groupKey)?.values || []).find((item) => item.value_key === valueKey);
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

  function toggleControl(checked, label = "Active") {
    return `<label class="ms-switch ms-switch--compact">
      <input data-rule-active type="checkbox" ${checked ? "checked" : ""}>
      <span aria-hidden="true"></span><em>${label}</em>
    </label>`;
  }

  function movementButtons(groupKey, valueKey, index, total) {
    return `<div class="ms-order-actions" aria-label="Display order">
      <button type="button" data-move-value="${groupKey}" data-value-key="${valueKey}" data-direction="-1" ${index === 0 ? "disabled" : ""} title="Move up">&uarr;</button>
      <button type="button" data-move-value="${groupKey}" data-value-key="${valueKey}" data-direction="1" ${index === total - 1 ? "disabled" : ""} title="Move down">&darr;</button>
    </div>`;
  }

  function valueManager(groupKey, title, addLabel) {
    const values = group(groupKey)?.values || [];
    return `<section class="ms-option-section">
      <div class="ms-section-head">
        <div><h4>${escapeHtml(title)}</h4><p>Edit names, availability, and display order.</p></div>
      </div>
      <div class="ms-value-list">
        ${values.map((value, index) => `<div class="ms-value-row ${Number(value.active) ? "" : "is-inactive"}"
          data-group-key="${groupKey}" data-value-key="${escapeHtml(value.value_key)}">
          <input data-value-label value="${escapeHtml(value.label)}" aria-label="${escapeHtml(title)} name">
          <label class="ms-switch ms-switch--compact">
            <input data-value-active type="checkbox" ${Number(value.active) ? "checked" : ""}>
            <span aria-hidden="true"></span><em>${Number(value.active) ? "Active" : "Inactive"}</em>
          </label>
          ${movementButtons(groupKey, value.value_key, index, values.length)}
        </div>`).join("")}
      </div>
      <div class="ms-inline-add">
        <input data-new-value="${groupKey}" placeholder="${escapeHtml(addLabel)}">
        <button type="button" data-add-value="${groupKey}">Add</button>
      </div>
    </section>`;
  }

  function priceCell(rule) {
    return `<div class="ms-price-cell" data-rule-key="${escapeHtml(rule.rule_key)}">
      <div class="ms-price-input"><span>PHP</span><input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(rule.price ?? "")}" placeholder="0.00"></div>
      <select data-rule-price-type aria-label="Price type">
        <option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option>
        <option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For Assessment</option>
      </select>
      ${toggleControl(Number(rule.active), Number(rule.active) ? "Active" : "Inactive")}
    </div>`;
  }

  function matrixEditor() {
    const papers = group("paper_size")?.values || [];
    const colors = group("color_option")?.values || [];
    let order = 0;
    const activePapers = papers.filter((item) => Number(item.active));
    const activeColors = colors.filter((item) => Number(item.active));
    return `${valueManager("paper_size", "Paper Sizes", "New paper size")}
      ${valueManager("color_option", "Color Options", "New color option")}
      <section class="ms-pricing-section">
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
    return `<section class="ms-pricing-section">
      <div class="ms-section-head"><div><h4>${escapeHtml(title)}</h4><p>${escapeHtml(options.help || "Edit names, prices, and availability.")}</p></div></div>
      <div class="ms-rule-table">
        <div class="ms-rule-table__head"><span>${escapeHtml(options.nameLabel || "Name")}</span>${options.description ? "<span>Details</span>" : ""}<span>Price</span><span>Price Type</span><span>Status</span><span>Order</span></div>
        ${values.map((value, index) => {
          const rule = findRule({ [groupKey]: value.value_key });
          return `<div class="ms-rule-row ${Number(value.active) ? "" : "is-inactive"}" data-rule-key="${escapeHtml(rule.rule_key)}" data-group-key="${groupKey}" data-value-key="${escapeHtml(value.value_key)}">
            <input data-value-label value="${escapeHtml(value.label)}" aria-label="${escapeHtml(options.nameLabel || "Name")}">
            ${options.description ? `<input data-rule-description value="${escapeHtml(rule.description || value.description || "")}" placeholder="Details shown to customers">` : ""}
            <div class="ms-price-input"><span>PHP</span><input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(rule.price ?? "")}" placeholder="0.00"></div>
            ${options.fixedOnly ? '<input type="hidden" data-rule-price-type value="fixed"><span class="ms-fixed-label">Fixed Price</span>' : `<select data-rule-price-type><option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option><option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For Assessment</option></select>`}
            ${toggleControl(Number(rule.active) && Number(value.active), Number(rule.active) && Number(value.active) ? "Active" : "Inactive")}
            <div class="ms-row-actions">${movementButtons(groupKey, value.value_key, index, values.length)}</div>
          </div>`;
        }).join("")}
      </div>
      <div class="ms-inline-add ms-inline-add--rule">
        <input data-new-value="${groupKey}" placeholder="${escapeHtml(addLabel)}">
        ${options.description ? `<input data-new-description="${groupKey}" placeholder="Inclusions or details">` : ""}
        <div class="ms-price-input"><span>PHP</span><input data-new-price="${groupKey}" type="number" min="0" step="0.01" placeholder="Price"></div>
        ${options.fixedOnly
          ? `<input type="hidden" data-new-price-type="${groupKey}" value="fixed"><span class="ms-fixed-label">Fixed Price</span>`
          : `<select data-new-price-type="${groupKey}"><option value="fixed">Fixed Price</option><option value="assessment">For Assessment</option></select>`}
        <label class="ms-switch ms-switch--compact"><input data-new-active="${groupKey}" type="checkbox" checked><span aria-hidden="true"></span><em>Active</em></label>
        <button type="button" data-add-value="${groupKey}">Add</button>
      </div>
    </section>`;
  }

  function finishConfirmation(accepted) {
    if (!confirmResolver) return;
    const resolve = confirmResolver;
    const returnFocus = confirmReturnFocus;
    confirmResolver = null;
    confirmReturnFocus = null;
    confirmOverlay.hidden = true;
    confirmOverlay.setAttribute("aria-hidden", "true");
    confirmOverlay.dataset.tone = "";
    overlay?.removeAttribute("inert");
    if (overlay?.style.display === "flex") overlay.setAttribute("aria-hidden", "false");
    resolve(accepted);
    returnFocus?.focus?.();
  }

  function confirmAction({ title, message, confirmLabel = "Confirm", tone = "primary" }) {
    if (!confirmOverlay || !confirmDialog) return Promise.resolve(false);
    if (confirmResolver) finishConfirmation(false);
    confirmReturnFocus = document.activeElement;
    confirmTitle.textContent = title;
    confirmMessage.textContent = message;
    confirmAccept.textContent = confirmLabel;
    confirmOverlay.dataset.tone = tone;
    confirmOverlay.hidden = false;
    confirmOverlay.setAttribute("aria-hidden", "false");
    overlay?.setAttribute("aria-hidden", "true");
    overlay?.setAttribute("inert", "");
    window.requestAnimationFrame(() => confirmDialog.focus());
    return new Promise((resolve) => { confirmResolver = resolve; });
  }

  confirmCancel?.addEventListener("click", () => finishConfirmation(false));
  confirmAccept?.addEventListener("click", () => finishConfirmation(true));
  confirmOverlay?.addEventListener("click", (event) => {
    if (event.target === confirmOverlay) finishConfirmation(false);
  });
  confirmOverlay?.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      finishConfirmation(false);
      return;
    }
    if (event.key !== "Tab") return;
    const focusable = [confirmCancel, confirmAccept].filter(Boolean);
    if (!focusable.length) return;
    const index = focusable.indexOf(document.activeElement);
    const next = event.shiftKey
      ? (index <= 0 ? focusable.length - 1 : index - 1)
      : (index >= focusable.length - 1 ? 0 : index + 1);
    event.preventDefault();
    focusable[next].focus();
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

  function rushEditor() {
    return `${simpleRuleRows("package", "Packages", "New package name", {
      nameLabel: "Package Name", description: true, help: "Base price for each Rush ID package.",
    })}
    ${simpleRuleRows("addon", "Optional Add-Ons", "New add-on name", {
      nameLabel: "Add-On Name", description: true, fixedOnly: true,
      help: "Additional price added to the selected package. Activate only after setting a price.",
    })}`;
  }

  function repairEditor() {
    const devices = group("device_type")?.values || [];
    return `${valueManager("device_type", "Devices", "New device")}
      <section class="ms-pricing-section">
        <div class="ms-section-head"><div><h4>Repair Services by Device</h4><p>Each device has its own available service types and prices.</p></div></div>
        <div class="ms-device-stack">${devices.filter((device) => Number(device.active)).map((device) => {
          const rules = (catalog.rules || []).filter((rule) => rule.option_value_keys?.device_type === device.value_key)
            .sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
          return `<details class="ms-device-card" open>
            <summary><strong>${escapeHtml(device.label)}</strong><span>${rules.filter((rule) => Number(rule.active)).length} active services</span></summary>
            <div class="ms-device-card__body">
              ${rules.length ? "" : '<p class="ms-device-guidance">Add repair services available for this device.</p>'}
              ${rules.map((rule) => {
                const value = groupValue("repair_type", rule.option_value_keys?.repair_type);
                if (!value) return "";
                return `<div class="ms-repair-row" data-rule-key="${escapeHtml(rule.rule_key)}">
                  <input data-value-label data-group-key="repair_type" data-value-key="${escapeHtml(value.value_key)}" value="${escapeHtml(value.label)}">
                  <div class="ms-price-input"><span>PHP</span><input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(rule.price ?? "")}" placeholder="0.00"></div>
                  <select data-rule-price-type><option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option><option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For Assessment</option></select>
                  ${toggleControl(Number(rule.active), Number(rule.active) ? "Active" : "Inactive")}
                </div>`;
              }).join("")}
              <div class="ms-inline-add ms-inline-add--rule"><input data-new-repair="${escapeHtml(device.value_key)}" placeholder="Service name"><div class="ms-price-input"><span>PHP</span><input data-new-repair-price="${escapeHtml(device.value_key)}" type="number" min="0" step="0.01" placeholder="Price"></div><select data-new-repair-price-type="${escapeHtml(device.value_key)}"><option value="fixed">Fixed Price</option><option value="assessment">For Assessment</option></select><label class="ms-switch ms-switch--compact"><input data-new-repair-active="${escapeHtml(device.value_key)}" type="checkbox" checked><span aria-hidden="true"></span><em>Active</em></label><button type="button" data-add-repair="${escapeHtml(device.value_key)}">Add Service</button></div>
            </div>
          </details>`;
        }).join("")}</div>
      </section>`;
  }

  function installationDeviceEditor() {
    const devices = group("device_type")?.values || [];
    return `${valueManager("device_type", "Devices", "New device")}
      <section class="ms-pricing-section">
        <div class="ms-section-head"><div><h4>Installation Services by Device</h4><p>Add installation services available for each device.</p></div></div>
        <div class="ms-device-stack">${devices.filter((device) => Number(device.active)).map((device) => {
          const rules = (catalog.rules || []).filter((rule) => rule.option_value_keys?.device_type === device.value_key)
            .sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
          return `<details class="ms-device-card" open>
            <summary><strong>${escapeHtml(device.label)}</strong><span>${rules.filter((rule) => Number(rule.active)).length} active services</span></summary>
            <div class="ms-device-card__body">
              ${rules.length ? "" : '<p class="ms-device-guidance">Add installation services available for this device.</p>'}
              ${rules.map((rule) => {
                const value = groupValue("installation_type", rule.option_value_keys?.installation_type);
                if (!value) return "";
                return `<div class="ms-repair-row" data-rule-key="${escapeHtml(rule.rule_key)}">
                  <input data-value-label data-group-key="installation_type" data-value-key="${escapeHtml(value.value_key)}" value="${escapeHtml(value.label)}">
                  <div class="ms-price-input"><span>PHP</span><input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(rule.price ?? "")}" placeholder="0.00"></div>
                  <select data-rule-price-type><option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option><option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For Assessment</option></select>
                  ${toggleControl(Number(rule.active), Number(rule.active) ? "Active" : "Inactive")}
                </div>`;
              }).join("")}
              <div class="ms-inline-add ms-inline-add--rule"><input data-new-installation="${escapeHtml(device.value_key)}" placeholder="Service name"><div class="ms-price-input"><span>PHP</span><input data-new-installation-price="${escapeHtml(device.value_key)}" type="number" min="0" step="0.01" placeholder="Price"></div><select data-new-installation-price-type="${escapeHtml(device.value_key)}"><option value="fixed">Fixed Price</option><option value="assessment">For Assessment</option></select><label class="ms-switch ms-switch--compact"><input data-new-installation-active="${escapeHtml(device.value_key)}" type="checkbox" checked><span aria-hidden="true"></span><em>Active</em></label><button type="button" data-add-installation="${escapeHtml(device.value_key)}">Add Service</button></div>
            </div>
          </details>`;
        }).join("")}</div>
      </section>`;
  }

  function installationEditor() {
    const deviceMode = Number(group("device_type")?.active) === 1;
    return `<section class="ms-option-section ms-mode-section"><div><h4>Pricing Setup</h4><p>Use a simple service list, or enable device-specific installation pricing.</p></div><label class="ms-switch"><input data-installation-device-mode type="checkbox" ${deviceMode ? "checked" : ""}><span aria-hidden="true"></span><em>Use Device Category</em></label></section>
      ${deviceMode
        ? installationDeviceEditor()
        : simpleRuleRows("installation_type", "Installation Types", "New installation service", { nameLabel: "Installation Type" })}`;
  }

  function render() {
    if (!catalog || !currentKind) return;
    if (currentKind === "document_printing" || currentKind === "photocopy") editor.innerHTML = matrixEditor();
    else if (currentKind === "rush_id") editor.innerHTML = rushEditor();
    else if (currentKind === "laminating") editor.innerHTML = simpleRuleRows("lamination_type", "Laminating Options", "New laminating type", { nameLabel: "Type" });
    else if (currentKind === "scanning") editor.innerHTML = simpleRuleRows("paper_size", "Scanning Paper Sizes", "New paper size", { nameLabel: "Paper Size" });
    else if (currentKind === "repair") editor.innerHTML = repairEditor();
    else if (currentKind === "installation") editor.innerHTML = installationEditor();
  }

  function syncFromDom() {
    qsa("[data-group-key][data-value-key]", editor).forEach((row) => {
      const value = groupValue(row.dataset.groupKey, row.dataset.valueKey);
      if (!value) return;
      const labelInput = qs("[data-value-label]", row);
      const activeInput = qs("[data-value-active]", row);
      if (labelInput) value.label = labelInput.value.trim() || value.label;
      if (activeInput) value.active = activeInput.checked ? 1 : 0;
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
      if (active) rule.active = active.checked ? 1 : 0;
      if (active && row.dataset.groupKey && row.dataset.valueKey) {
        const value = groupValue(row.dataset.groupKey, row.dataset.valueKey);
        if (value) value.active = active.checked ? 1 : 0;
      }
      if (description) rule.description = description.value.trim();
      const labels = Object.entries(rule.option_value_keys || {}).map(([key, valueKey]) => groupValue(key, valueKey)?.label || "").filter(Boolean);
      rule.label = labels.join(" / ");
    });
    (catalog.groups || []).forEach((item) => {
      (item.values || []).forEach((value, index) => { value.sort_order = index; });
    });
    return catalog;
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

  editor.addEventListener("click", async (event) => {
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
      if (priceInput && active && priceType === "fixed" && (price === "" || !Number.isFinite(Number(price)))) {
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
      if (active && priceType === "fixed" && (price === "" || !Number.isFinite(Number(price)))) return showError("Enter a valid price, or choose For Assessment.");
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
      if (active && priceType === "fixed" && (price === "" || !Number.isFinite(Number(price)))) return showError("Enter a valid price, or choose For Assessment.");
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

    const moveButton = event.target.closest("[data-move-value]");
    if (moveButton) {
      syncFromDom();
      const values = group(moveButton.dataset.moveValue)?.values || [];
      const index = values.findIndex((value) => value.value_key === moveButton.dataset.valueKey);
      const target = index + Number(moveButton.dataset.direction);
      if (index >= 0 && target >= 0 && target < values.length) {
        [values[index], values[target]] = [values[target], values[index]];
        render();
      }
    }
  });

  editor.addEventListener("change", async (event) => {
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
    if (event.target.matches("[data-rule-active]") || event.target.matches("[data-value-active]")) {
      const activated = event.target.checked;
      if (!await confirmAction({
        title: activated ? "Activate Option" : "Deactivate Option",
        message: activated
          ? "Activate this option? Customers will be able to see and select it after you save."
          : "Deactivate this option? Customers will no longer be able to select it, but old submitted records will remain unchanged.",
        confirmLabel: activated ? "Activate" : "Deactivate",
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
    editorReturnFocus = document.activeElement;
    hideError();
    fields.id.value = data.id;
    fields.category.value = data.category;
    fields.name.value = data.name;
    fields.description.value = data.description || "";
    fields.sort.value = data.sort_order || 0;
    fields.active.checked = Number(data.active) === 1;
    syncServiceStatusLabel();
    originalServiceSnapshot = {
      name: data.name || "",
      description: data.description || "",
      active: Number(data.active) === 1,
    };
    currentKind = serviceKind(data.category, data.name);
    const serviceNameField = qs("#msServiceNameField");
    if (serviceNameField) serviceNameField.hidden = !["laminating", "scanning"].includes(currentKind);
    const contract = contracts[currentKind];
    qs("#msModalTitle").textContent = contract?.title || `Edit ${data.name}`;
    qs("#msModalHelp").textContent = contract?.help || "";
    overlay.style.display = "flex";
    overlay.setAttribute("aria-hidden", "false");
    document.body.classList.add("ms-modal-open");
    window.requestAnimationFrame(() => qs(".ms-modal", overlay)?.focus());
    editor.innerHTML = '<div class="ms-loading">Loading current options...</div>';
    try {
      catalog = normalizeCatalog(await fetchCatalog(data.id));
      originalSnapshot = JSON.stringify(catalog);
      render();
    } catch (error) {
      showError(error.message || "Unable to load service options.");
    }
  }

  function closeEditor() {
    if (confirmResolver) finishConfirmation(false);
    overlay.style.display = "none";
    overlay.setAttribute("aria-hidden", "true");
    document.body.classList.remove("ms-modal-open");
    catalog = null;
    originalServiceSnapshot = null;
    hideError();
    editorReturnFocus?.focus?.();
    editorReturnFocus = null;
  }

  qsa("[data-ms-edit]").forEach((button) => button.addEventListener("click", () => {
    const data = JSON.parse(button.getAttribute("data-ms-edit") || "{}");
    openEditor(data);
  }));
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
    window.servitechAdminToast?.success?.(activated
      ? "Service activated in this draft. Save changes to publish it."
      : "Service deactivated in this draft. Save changes to publish it.");
  });
  qs("#msX")?.addEventListener("click", closeEditor);
  qs("#msCancel")?.addEventListener("click", closeEditor);
  overlay?.addEventListener("click", (event) => { if (event.target === overlay) closeEditor(); });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && overlay?.style.display === "flex" && confirmOverlay?.hidden !== false) closeEditor();
  });

  qs("#msSave")?.addEventListener("click", async () => {
    hideError();
    const payload = syncFromDom();
    const invalidRule = (payload.rules || []).find((rule) =>
      Number(rule.active) && rule.price_type === "fixed" && (rule.price === "" || !Number.isFinite(Number(rule.price)))
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
      || fields.active.checked !== originalServiceSnapshot.active;
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
      window.servitechAdminToast?.persist(data.message || `${fields.name.value} updated successfully.`);
      location.reload();
    } catch (error) {
      showError(error.message || "Failed to save changes. Please try again.");
    } finally {
      qs("#msSave").disabled = false;
    }
  });
})();
