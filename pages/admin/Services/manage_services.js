(function () {
  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");
  const apiUrl = window.MS_API_URL || "/pages/admin/Services/services_api.php";

  const overlay = qs("#msOverlay");
  const editor = qs("#ms_catalogEditor");
  const errorBox = qs("#msErr");
  const fields = {
    id: qs("#ms_id"),
    category: qs("#ms_category"),
    name: qs("#ms_name"),
    description: qs("#ms_description"),
    price: qs("#ms_price"),
    priceRange: qs("#ms_price_range"),
    active: qs("#ms_active"),
    sort: qs("#ms_sort"),
  };

  let catalog = null;
  let originalSnapshot = "";
  let currentKind = "";

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
      title: "Edit Lamination Types",
      help: "Manage available lamination types and their official prices.",
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
        item = { group_key: key, name, active: 1, sort_order: index, values: [] };
        normalized.groups.push(item);
      }
      item.name = name;
      item.active = 1;
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
          <button class="ms-text-action danger" type="button" data-archive-value="${groupKey}" data-value-key="${escapeHtml(value.value_key)}">Archive</button>
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
        <div class="ms-rule-table__head"><span>${escapeHtml(options.nameLabel || "Name")}</span>${options.description ? "<span>Details</span>" : ""}<span>Price</span><span>Price Type</span><span>Status</span><span>Actions</span></div>
        ${values.map((value, index) => {
          const rule = findRule({ [groupKey]: value.value_key });
          return `<div class="ms-rule-row ${Number(value.active) ? "" : "is-inactive"}" data-rule-key="${escapeHtml(rule.rule_key)}" data-group-key="${groupKey}" data-value-key="${escapeHtml(value.value_key)}">
            <input data-value-label value="${escapeHtml(value.label)}" aria-label="${escapeHtml(options.nameLabel || "Name")}">
            ${options.description ? `<input data-rule-description value="${escapeHtml(rule.description || value.description || "")}" placeholder="Details shown to customers">` : ""}
            <div class="ms-price-input"><span>PHP</span><input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(rule.price ?? "")}" placeholder="0.00"></div>
            ${options.fixedOnly ? '<input type="hidden" data-rule-price-type value="fixed"><span class="ms-fixed-label">Fixed Price</span>' : `<select data-rule-price-type><option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option><option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For Assessment</option></select>`}
            ${toggleControl(Number(rule.active) && Number(value.active), Number(rule.active) && Number(value.active) ? "Active" : "Inactive")}
            <div class="ms-row-actions">${movementButtons(groupKey, value.value_key, index, values.length)}<button class="ms-text-action danger" type="button" data-archive-value="${groupKey}" data-value-key="${escapeHtml(value.value_key)}">Archive</button></div>
          </div>`;
        }).join("")}
      </div>
      <div class="ms-inline-add"><input data-new-value="${groupKey}" placeholder="${escapeHtml(addLabel)}"><button type="button" data-add-value="${groupKey}">Add</button></div>
    </section>`;
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
              ${rules.map((rule) => {
                const value = groupValue("repair_type", rule.option_value_keys?.repair_type);
                if (!value) return "";
                return `<div class="ms-repair-row" data-rule-key="${escapeHtml(rule.rule_key)}">
                  <input data-value-label data-group-key="repair_type" data-value-key="${escapeHtml(value.value_key)}" value="${escapeHtml(value.label)}">
                  <div class="ms-price-input"><span>PHP</span><input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(rule.price ?? "")}" placeholder="0.00"></div>
                  <select data-rule-price-type><option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed Price</option><option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For Assessment</option></select>
                  ${toggleControl(Number(rule.active), Number(rule.active) ? "Active" : "Inactive")}
                  <button class="ms-text-action danger" type="button" data-archive-rule="${escapeHtml(rule.rule_key)}">Archive</button>
                </div>`;
              }).join("")}
              <div class="ms-inline-add"><input data-new-repair="${escapeHtml(device.value_key)}" placeholder="New repair service"><button type="button" data-add-repair="${escapeHtml(device.value_key)}">Add Repair Type</button></div>
            </div>
          </details>`;
        }).join("")}</div>
      </section>`;
  }

  function render() {
    if (!catalog || !currentKind) return;
    if (currentKind === "document_printing" || currentKind === "photocopy") editor.innerHTML = matrixEditor();
    else if (currentKind === "rush_id") editor.innerHTML = rushEditor();
    else if (currentKind === "laminating") editor.innerHTML = simpleRuleRows("lamination_type", "Lamination Types", "New lamination type", { nameLabel: "Type" });
    else if (currentKind === "repair") editor.innerHTML = repairEditor();
    else if (currentKind === "installation") editor.innerHTML = simpleRuleRows("installation_type", "Installation Types", "New installation type", { nameLabel: "Installation Type" });
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

  function archiveValue(groupKey, valueKey) {
    const value = groupValue(groupKey, valueKey);
    if (!value) return;
    value.active = 0;
    (catalog.rules || []).forEach((rule) => {
      if (rule.option_value_keys?.[groupKey] === valueKey) rule.active = 0;
    });
  }

  editor.addEventListener("click", (event) => {
    const addButton = event.target.closest("[data-add-value]");
    if (addButton) {
      syncFromDom();
      const groupKey = addButton.dataset.addValue;
      const input = qs(`[data-new-value="${groupKey}"]`, editor);
      const label = input?.value.trim() || "";
      if (!label) return showError("Enter a name before adding the option.");
      addValue(groupKey, label);
      render();
      window.servitechAdminToast?.success?.(`${contracts[currentKind].groups[groupKey]} option added.`);
      return;
    }

    const repairButton = event.target.closest("[data-add-repair]");
    if (repairButton) {
      syncFromDom();
      const deviceKey = repairButton.dataset.addRepair;
      const input = qs(`[data-new-repair="${deviceKey}"]`, editor);
      const label = input?.value.trim() || "";
      if (!label) return showError("Enter a repair service name first.");
      const value = addValue("repair_type", label);
      ensureRule({ device_type: deviceKey, repair_type: value.value_key }, `${groupValue("device_type", deviceKey)?.label || "Device"} / ${value.label}`, catalog.rules.length, "assessment");
      render();
      window.servitechAdminToast?.success?.("Repair type added.");
      return;
    }

    const archiveButton = event.target.closest("[data-archive-value]");
    if (archiveButton) {
      syncFromDom();
      if (!window.confirm("Archive this option? It will disappear from customer pages, but old queue and order snapshots will remain readable.")) return;
      const archivedGroup = contracts[currentKind].groups[archiveButton.dataset.archiveValue] || "Option";
      archiveValue(archiveButton.dataset.archiveValue, archiveButton.dataset.valueKey);
      render();
      window.servitechAdminToast?.success?.(`${archivedGroup} archived.`);
      return;
    }

    const archiveRuleButton = event.target.closest("[data-archive-rule]");
    if (archiveRuleButton) {
      syncFromDom();
      if (!window.confirm("Archive this service option? Customers will no longer be able to select it.")) return;
      const rule = catalog.rules.find((item) => item.rule_key === archiveRuleButton.dataset.archiveRule);
      if (rule) rule.active = 0;
      render();
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

  editor.addEventListener("change", (event) => {
    if ((event.target.matches("[data-rule-active]") || event.target.matches("[data-value-active]"))
      && !event.target.checked
      && !window.confirm("Deactivate this option? It will no longer appear on the landing page or customer queue forms.")) {
      event.target.checked = true;
    }
  });

  async function fetchCatalog(id) {
    const response = await fetch(`${apiUrl}?action=catalog&id=${encodeURIComponent(id)}`, { credentials: "same-origin", cache: "no-store" });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || "Unable to load service options.");
    return data.catalog;
  }

  async function openEditor(data) {
    hideError();
    fields.id.value = data.id;
    fields.category.value = data.category;
    fields.name.value = data.name;
    fields.description.value = data.description || "";
    fields.price.value = data.price ?? "";
    fields.priceRange.value = data.price_range || "";
    fields.sort.value = data.sort_order || 0;
    fields.active.checked = Number(data.active) === 1;
    currentKind = serviceKind(data.category, data.name);
    const contract = contracts[currentKind];
    qs("#msModalTitle").textContent = contract?.title || `Edit ${data.name}`;
    qs("#msModalHelp").textContent = contract?.help || "";
    overlay.style.display = "flex";
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
    overlay.style.display = "none";
    catalog = null;
    hideError();
  }

  qsa("[data-ms-edit]").forEach((button) => button.addEventListener("click", () => {
    const data = JSON.parse(button.getAttribute("data-ms-edit") || "{}");
    openEditor(data);
  }));
  qs("#msX")?.addEventListener("click", closeEditor);
  qs("#msCancel")?.addEventListener("click", closeEditor);
  overlay?.addEventListener("click", (event) => { if (event.target === overlay) closeEditor(); });
  document.addEventListener("keydown", (event) => { if (event.key === "Escape" && overlay?.style.display === "flex") closeEditor(); });

  qs("#msSave")?.addEventListener("click", async () => {
    hideError();
    const payload = syncFromDom();
    const invalidRule = (payload.rules || []).find((rule) =>
      Number(rule.active) && rule.price_type === "fixed" && (rule.price === "" || !Number.isFinite(Number(rule.price)))
    );
    if (invalidRule) return showError(`Enter a valid price for ${invalidRule.label || "the active option"}, or mark it For Assessment.`);

    const changed = JSON.stringify(payload) !== originalSnapshot;
    if (changed && !window.confirm("Save these option and price changes? New customer submissions will use the updated catalog. Old records keep their saved snapshots.")) return;
    if (!fields.active.checked && !window.confirm("Deactivate this entire service? It will be hidden from the landing page and queue forms.")) return;

    const form = new FormData();
    form.append("action", "save");
    form.append("id", fields.id.value);
    form.append("category", fields.category.value);
    form.append("name", fields.name.value);
    form.append("description", fields.description.value.trim());
    form.append("price", "");
    form.append("price_range", "");
    form.append("pricing_json", "");
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
