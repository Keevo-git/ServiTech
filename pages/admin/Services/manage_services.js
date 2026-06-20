(function(){
  const qs = (s, el=document)=>el.querySelector(s);
  const qsa = (s, el=document)=>Array.from(el.querySelectorAll(s));
  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");
  const msApiUrl = window.MS_API_URL || "/pages/admin/Services/services_api.php";

  const overlay = qs("#msOverlay");
  const modalTitle = qs("#msModalTitle");
  const errBox = qs("#msErr");

  const fId = qs("#ms_id");
  const fCat = qs("#ms_category");
  const fName = qs("#ms_name");
  const fDesc = qs("#ms_description");
  const fPrice = qs("#ms_price");
  const fPriceRange = qs("#ms_price_range");
  const fActive = qs("#ms_active");
  const fSort = qs("#ms_sort");
  const fPriceModeField = qs("#ms_priceModeField");
  const fPriceField = qs("#ms_priceField");
  const fPriceLabel = qs("#ms_priceLabel");
  const fPriceHint = qs("#ms_price_hint");
  const fDescriptionHint = qs("#ms_description_hint");
  const catalogEditor = qs("#ms_catalogEditor");
  let currentCatalog = null;
  let originalCatalogSnapshot = "";

  function showErr(msg){
    errBox.textContent = msg;
    errBox.style.display="block";
    window.servitechAdminToast?.error(msg);
  }
  function hideErr(){ errBox.textContent=""; errBox.style.display="none"; }

  function displayServiceName(name) {
    const value = String(name || "").trim();
    return value.toLowerCase() === "xerox" ? "Photocopy" : value;
  }

  function getFPriceMode() {
    return qs("#ms_priceMode");
  }

  function extractOptionPrice(description, option) {
    if (!description) return null;
    const match = description.match(new RegExp(`${option}\\s*(?:/\\s*B&W)?\\s*[-\\u2013\\u2014]?\\s*\\u20B1?\\s*([0-9]+(?:\\.[0-9]+)?)`, "i"));
    return match ? match[1] : null;
  }

  function replaceOptionPrice(description, option, newPrice) {
    const normalizedDescription = description || "";
    const regex = new RegExp(`(${option}\\s*[-\\u2013\\u2014]?\\s*)\\u20B1?\\s*[0-9]+(?:\\.[0-9]+)?`, "i");
    if (regex.test(normalizedDescription)) {
      return normalizedDescription.replace(regex, `$1${newPrice}`);
    }

    if (!newPrice) return normalizedDescription;
    const line = `${option} - ${newPrice}`;
    if (normalizedDescription.trim() === "") return line;
    return normalizedDescription.trimEnd() + "\n" + line;
  }

  function setPriceForMode(mode, description, defaultPrice) {
    if (!fPrice) return;
    const normalizedMode = (mode || "default").toLowerCase();
    if (normalizedMode === "full") {
      fPrice.value = extractOptionPrice(description, "Full") || defaultPrice || "";
    } else if (normalizedMode === "half") {
      fPrice.value = extractOptionPrice(description, "Half") || defaultPrice || "";
    } else {
      fPrice.value = defaultPrice || "";
    }
  }

  function syncPriceMode(description, defaultPrice) {
    const priceMode = getFPriceMode();
    if (!priceMode) return;
    if (fPriceModeField) fPriceModeField.style.display = "";
    if (!["full", "half", "default"].includes(priceMode.value)) {
      priceMode.value = "default";
    }
    setPriceForMode(priceMode.value, description, defaultPrice ?? "");
  }

  function catalogKind(category, name) {
    const cat = String(category || "").toLowerCase();
    const label = String(name || "").toLowerCase();
    if (cat === "printing" && label.includes("document")) return "document_matrix";
    if (cat === "printing" && (label.includes("photocopy") || label.includes("xerox"))) return "photocopy_matrix";
    if (cat === "printing" && label.includes("rush") && label.includes("id")) return "package_list";
    if (cat === "printing" && label.includes("laminat")) return "lamination_list";
    if (cat === "repair") return "repair_matrix";
    if (cat === "installation") return "installation_list";
    return "";
  }

  function moneyOrBlank(value) {
    const number = Number(value);
    return Number.isFinite(number) ? String(number) : "";
  }

  function slug(value) {
    return String(value || "").trim().toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "") || "option";
  }

  function emptyCatalog(kind) {
    if (kind === "document_matrix") {
      return {
        groups: [
          { group_key: "paper_size", name: "Paper Size", active: 1, sort_order: 0, values: [
          ] },
          { group_key: "color_option", name: "Color Option", active: 1, sort_order: 1, values: [
          ] },
        ],
        rules: [],
      };
    }
    if (kind === "photocopy_matrix") {
      return {
        groups: [
          { group_key: "paper_size", name: "Paper Size", active: 1, sort_order: 0, values: [
          ] },
          { group_key: "color_option", name: "Color Option", active: 1, sort_order: 1, values: [
          ] },
        ],
        rules: [],
      };
    }
    if (kind === "package_list") {
      return { groups: [{ group_key: "package", name: "Package", active: 1, sort_order: 0, values: [] }], rules: [] };
    }
    if (kind === "lamination_list") {
      return { groups: [{ group_key: "lamination_type", name: "Lamination Type", active: 1, sort_order: 0, values: [] }], rules: [] };
    }
    if (kind === "repair_matrix") {
      return {
        groups: [
          { group_key: "device_type", name: "Device Type", active: 1, sort_order: 0, values: [
          ] },
          { group_key: "repair_type", name: "Repair Type", active: 1, sort_order: 1, values: [] },
        ],
        rules: [],
      };
    }
    if (kind === "installation_list") {
      return { groups: [{ group_key: "installation_type", name: "Installation Type", active: 1, sort_order: 0, values: [] }], rules: [] };
    }
    return { groups: [], rules: [] };
  }

  function normalizeCatalog(catalog, kind) {
    const source = catalog && catalog.groups ? catalog : emptyCatalog(kind);
    const normalized = {
      groups: JSON.parse(JSON.stringify(source.groups || [])),
      rules: JSON.parse(JSON.stringify(source.rules || [])),
    };
    if (!normalized.groups.length) return emptyCatalog(kind);
    normalized.groups.forEach((group, groupIndex) => {
      group.group_key = group.group_key || slug(group.name || `group_${groupIndex}`);
      group.name = group.name || group.group_key;
      group.active = Number(group.active ?? 1);
      group.sort_order = Number(group.sort_order ?? groupIndex);
      group.values = Array.isArray(group.values) ? group.values : [];
      group.values.forEach((value, valueIndex) => {
        value.value_key = value.value_key || slug(value.label || `option_${valueIndex}`);
        value.label = value.label || value.value_key;
        value.description = value.description || "";
        value.active = Number(value.active ?? 1);
        value.sort_order = Number(value.sort_order ?? valueIndex);
      });
    });
    normalized.rules.forEach((rule, ruleIndex) => {
      rule.option_value_keys = rule.option_value_keys || {};
      rule.rule_key = rule.rule_key || slug(Object.values(rule.option_value_keys).join("_"));
      rule.label = rule.label || "";
      rule.description = rule.description || "";
      rule.price_type = rule.price_type === "assessment" ? "assessment" : "fixed";
      rule.active = Number(rule.active ?? 1);
      rule.sort_order = Number(rule.sort_order ?? ruleIndex);
    });
    return normalized;
  }

  function groupByKey(key) {
    return (currentCatalog?.groups || []).find((group) => group.group_key === key) || { values: [] };
  }

  function findRuleFor(keys) {
    return (currentCatalog?.rules || []).find((rule) => {
      return Object.keys(keys).every((groupKey) => String(rule.option_value_keys?.[groupKey] || "") === String(keys[groupKey]));
    }) || null;
  }

  function ensureRule(keys, fallbackLabel, sortOrder) {
    let rule = findRuleFor(keys);
    if (rule) return rule;
    rule = {
      rule_key: slug(Object.values(keys).join("_")),
      option_value_keys: { ...keys },
      label: fallbackLabel,
      description: "",
      price: "",
      price_type: "fixed",
      active: 1,
      sort_order: sortOrder,
    };
    currentCatalog.rules.push(rule);
    return rule;
  }

  function renderGroupEditor(group) {
    return `
      <div class="ms-catalog-group" data-group-key="${group.group_key}">
        <div class="ms-catalog-group__head">
          <input data-group-name value="${escapeHtml(group.name)}" aria-label="Option group name">
          <label><input type="checkbox" data-group-active ${Number(group.active ?? 1) ? "checked" : ""}> Active</label>
          <button type="button" data-catalog-add-value="${group.group_key}">+ Add</button>
        </div>
        <div class="ms-catalog-values">
          ${(group.values || []).map((value, index) => `
            <div class="ms-catalog-value" data-value-index="${index}">
              <input data-value-label value="${escapeHtml(value.label)}" aria-label="${group.name} label">
              <label><input type="checkbox" data-value-active ${Number(value.active) ? "checked" : ""}> Active</label>
              <input type="number" data-value-sort value="${Number(value.sort_order ?? index)}" aria-label="${group.name} sort order">
            </div>
          `).join("")}
        </div>
      </div>
    `;
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    }[char]));
  }

  function renderMatrixEditor(kind) {
    const paper = groupByKey("paper_size");
    const color = groupByKey("color_option");
    const rows = paper.values || [];
    const cols = color.values || [];
    let order = 0;
    const table = `
      <table class="ms-catalog-matrix">
        <thead>
          <tr><th>Paper Size</th>${cols.map((col) => `<th>${escapeHtml(col.label)}</th>`).join("")}</tr>
        </thead>
        <tbody>
          ${rows.map((row) => `
            <tr>
              <th>${escapeHtml(row.label)}</th>
              ${cols.map((col) => {
                const rule = ensureRule({ paper_size: row.value_key, color_option: col.value_key }, `${row.label} / ${col.label}`, order++);
                return `
                  <td data-rule-key="${escapeHtml(rule.rule_key)}">
                    <input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(moneyOrBlank(rule.price))}" placeholder="Price">
                    <select data-rule-price-type>
                      <option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed</option>
                      <option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For assessment</option>
                    </select>
                    <label><input type="checkbox" data-rule-active ${Number(rule.active) ? "checked" : ""}> Active</label>
                  </td>
                `;
              }).join("")}
            </tr>
          `).join("")}
        </tbody>
      </table>
    `;
    return `
      <div class="ms-catalog-block" data-catalog-kind="${kind}">
        <h4>${kind === "document_matrix" ? "Document Printing Price Matrix" : "Photocopy Price Matrix"}</h4>
        <p class="ms-catalog-hint">Rows and columns are editable option groups. Each cell saves its own price, price type, active state, and sort order.</p>
        ${renderGroupEditor(paper)}
        ${renderGroupEditor(color)}
        ${table}
      </div>
    `;
  }

  function renderRuleList(kind, groupKey, title) {
    const group = groupByKey(groupKey);
    const values = group.values || [];
    values.forEach((value, index) => ensureRule({ [groupKey]: value.value_key }, value.label, index));
    return `
      <div class="ms-catalog-block" data-catalog-kind="${kind}">
        <h4>${title}</h4>
        ${renderGroupEditor(group)}
        <div class="ms-catalog-rule-list">
          ${values.map((value, index) => {
            const rule = ensureRule({ [groupKey]: value.value_key }, value.label, index);
            return `
              <div class="ms-catalog-rule" data-rule-key="${escapeHtml(rule.rule_key)}">
                <strong>${escapeHtml(value.label)}</strong>
                <input data-rule-description value="${escapeHtml(rule.description || value.description || "")}" placeholder="Details / description">
                <input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(moneyOrBlank(rule.price))}" placeholder="Price">
                <select data-rule-price-type>
                  <option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed</option>
                  <option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For assessment</option>
                </select>
                <label><input type="checkbox" data-rule-active ${Number(rule.active) ? "checked" : ""}> Active</label>
                <input data-rule-sort type="number" value="${Number(rule.sort_order ?? index)}" aria-label="Sort order">
              </div>
            `;
          }).join("")}
        </div>
      </div>
    `;
  }

  function renderRepairEditor() {
    const device = groupByKey("device_type");
    const repair = groupByKey("repair_type");
    return `
      <div class="ms-catalog-block" data-catalog-kind="repair_matrix">
        <h4>Repair Device + Type Pricing</h4>
        <p class="ms-catalog-hint">Each rule connects one device type with one repair type. Use For assessment for diagnosis-based repairs.</p>
        ${renderGroupEditor(device)}
        ${renderGroupEditor(repair)}
        <button type="button" data-catalog-add-rule="repair">+ Add Device/Repair Price Rule</button>
        <div class="ms-catalog-rule-list">
          ${(currentCatalog.rules || []).map((rule, index) => `
            <div class="ms-catalog-rule" data-rule-key="${escapeHtml(rule.rule_key)}">
              <select data-rule-group="device_type">
                ${(device.values || []).map((value) => `<option value="${escapeHtml(value.value_key)}" ${rule.option_value_keys?.device_type === value.value_key ? "selected" : ""}>${escapeHtml(value.label)}</option>`).join("")}
              </select>
              <select data-rule-group="repair_type">
                ${(repair.values || []).map((value) => `<option value="${escapeHtml(value.value_key)}" ${rule.option_value_keys?.repair_type === value.value_key ? "selected" : ""}>${escapeHtml(value.label)}</option>`).join("")}
              </select>
              <input data-rule-price type="number" min="0" step="0.01" value="${escapeHtml(moneyOrBlank(rule.price))}" placeholder="Price">
              <select data-rule-price-type>
                <option value="fixed" ${rule.price_type !== "assessment" ? "selected" : ""}>Fixed</option>
                <option value="assessment" ${rule.price_type === "assessment" ? "selected" : ""}>For assessment</option>
              </select>
              <label><input type="checkbox" data-rule-active ${Number(rule.active) ? "checked" : ""}> Active</label>
              <input data-rule-sort type="number" value="${Number(rule.sort_order ?? index)}" aria-label="Sort order">
            </div>
          `).join("")}
        </div>
      </div>
    `;
  }

  function renderCatalogEditor(kind, catalog) {
    if (!catalogEditor) return;
    if (!kind) {
      catalogEditor.innerHTML = "";
      currentCatalog = null;
      originalCatalogSnapshot = "";
      return;
    }
    currentCatalog = normalizeCatalog(catalog, kind);
    originalCatalogSnapshot = JSON.stringify(currentCatalog);
    if (kind === "document_matrix" || kind === "photocopy_matrix") {
      catalogEditor.innerHTML = renderMatrixEditor(kind);
    } else if (kind === "package_list") {
      catalogEditor.innerHTML = renderRuleList(kind, "package", "Rush ID Packages");
    } else if (kind === "lamination_list") {
      catalogEditor.innerHTML = renderRuleList(kind, "lamination_type", "Lamination Types");
    } else if (kind === "repair_matrix") {
      catalogEditor.innerHTML = renderRepairEditor();
    } else if (kind === "installation_list") {
      catalogEditor.innerHTML = renderRuleList(kind, "installation_type", "Installation Types");
    } else {
      catalogEditor.innerHTML = "";
    }
  }

  async function fetchCatalog(serviceId) {
    if (!serviceId) return null;
    try {
      const url = `${msApiUrl}?action=catalog&id=${encodeURIComponent(serviceId)}`;
      const res = await fetch(url, { credentials: "same-origin", cache: "no-store" });
      const out = await res.json();
      return out.ok ? out.catalog : null;
    } catch (err) {
      return null;
    }
  }

  function syncCatalogFromDom() {
    if (!currentCatalog || !catalogEditor) return null;

    catalogEditor.querySelectorAll(".ms-catalog-group").forEach((groupEl) => {
      const groupKey = groupEl.dataset.groupKey;
      const group = groupByKey(groupKey);
      group.name = groupEl.querySelector("[data-group-name]")?.value.trim() || group.name;
      group.active = groupEl.querySelector("[data-group-active]")?.checked ? 1 : 0;
      groupEl.querySelectorAll(".ms-catalog-value").forEach((valueEl, index) => {
        const value = group.values[index];
        if (!value) return;
        const label = valueEl.querySelector("[data-value-label]")?.value.trim() || value.label;
        value.label = label;
        value.value_key = value.value_key || slug(label);
        value.active = valueEl.querySelector("[data-value-active]")?.checked ? 1 : 0;
        value.sort_order = Number(valueEl.querySelector("[data-value-sort]")?.value || index);
      });
    });

    catalogEditor.querySelectorAll("[data-rule-key]").forEach((ruleEl, index) => {
      const ruleKey = ruleEl.dataset.ruleKey;
      const rule = (currentCatalog.rules || []).find((item) => item.rule_key === ruleKey);
      if (!rule) return;
      rule.price = ruleEl.querySelector("[data-rule-price]")?.value.trim() || "";
      rule.price_type = ruleEl.querySelector("[data-rule-price-type]")?.value || "fixed";
      rule.active = ruleEl.querySelector("[data-rule-active]")?.checked ? 1 : 0;
      rule.sort_order = Number(ruleEl.querySelector("[data-rule-sort]")?.value || rule.sort_order || index);
      rule.description = ruleEl.querySelector("[data-rule-description]")?.value.trim() || rule.description || "";
      ruleEl.querySelectorAll("[data-rule-group]").forEach((select) => {
        rule.option_value_keys = rule.option_value_keys || {};
        rule.option_value_keys[select.dataset.ruleGroup] = select.value;
      });
      const labels = Object.keys(rule.option_value_keys || {}).map((groupKey) => {
        const group = groupByKey(groupKey);
        const value = (group.values || []).find((item) => item.value_key === rule.option_value_keys[groupKey]);
        return value?.label || "";
      }).filter(Boolean);
      rule.label = labels.join(" / ") || rule.label;
      rule.rule_key = slug(Object.values(rule.option_value_keys || {}).join("_"));
    });

    return currentCatalog;
  }

  function setCatalogManagedUi(kind) {
    const managed = Boolean(kind);
    if (fPriceModeField) fPriceModeField.style.display = managed ? "none" : "";
    if (fPriceField) fPriceField.style.display = managed ? "none" : "";
    if (fPriceHint) {
      fPriceHint.textContent = managed
        ? "Prices, options, packages, and combinations are managed in the catalog editor below."
        : "Choose Full or Half when editing print price lines inside the description.";
    }
    if (fDescriptionHint) {
      fDescriptionHint.textContent = managed
        ? "Use the description for customer-facing notes only. Official selectable options and prices come from the catalog editor."
        : "Use newline-separated entries for simple service notes.";
    }
  }

  function catalogHasPricingChanges(catalog) {
    if (!originalCatalogSnapshot || !catalog) return false;
    let original;
    try {
      original = JSON.parse(originalCatalogSnapshot);
    } catch (err) {
      return false;
    }
    const keyFor = (rule) => JSON.stringify(rule.option_value_keys || rule.rule_key || "");
    const originalRules = new Map((original.rules || []).map((rule) => [keyFor(rule), rule]));
    return (catalog.rules || []).some((rule) => {
      const before = originalRules.get(keyFor(rule));
      if (!before) return true;
      return String(before.price ?? "") !== String(rule.price ?? "")
        || String(before.price_type ?? "") !== String(rule.price_type ?? "")
        || Number(before.active ?? 1) !== Number(rule.active ?? 1);
    });
  }

  catalogEditor?.addEventListener("click", (event) => {
    const addValueBtn = event.target.closest("[data-catalog-add-value]");
    if (addValueBtn && currentCatalog) {
      syncCatalogFromDom();
      const group = groupByKey(addValueBtn.dataset.catalogAddValue);
      const label = prompt(`New ${group.name}`);
      if (!label || !label.trim()) return;
      group.values.push({
        value_key: slug(label),
        label: label.trim(),
        description: "",
        active: 1,
        sort_order: group.values.length,
      });
      renderCatalogEditor(catalogKind(fCat.value, fName.value), currentCatalog);
      return;
    }

    const addRuleBtn = event.target.closest("[data-catalog-add-rule]");
    if (addRuleBtn && currentCatalog) {
      syncCatalogFromDom();
      const device = groupByKey("device_type").values?.[0];
      const repair = groupByKey("repair_type").values?.[0];
      if (!device || !repair) {
        showErr("Add at least one device type and repair type first.");
        return;
      }
      currentCatalog.rules.push({
        rule_key: slug(`${device.value_key}_${repair.value_key}_${Date.now()}`),
        option_value_keys: { device_type: device.value_key, repair_type: repair.value_key },
        label: `${device.label} / ${repair.label}`,
        price: "",
        price_type: "assessment",
        active: 1,
        sort_order: currentCatalog.rules.length,
      });
      renderCatalogEditor("repair_matrix", currentCatalog);
    }
  });

  async function openModal(mode, data){
    hideErr();
    overlay.style.display="flex";
    modalTitle.textContent = (mode === "edit") ? "Edit Service" : "Add Service";

    fId.value = data?.id || "";
    fCat.value = data?.category || window.MS_ACTIVE_TAB || "printing";
    fName.value = displayServiceName(data?.name || "");
    fDesc.value = data?.description || "";
    if (fPriceRange) fPriceRange.value = data?.price_range || "";
    fActive.value = (data?.active ?? 1) ? "1" : "0";
    fSort.value = data?.sort_order ?? 0;

    const priceValue = (data?.price ?? "") === null ? "" : (data?.price ?? "");
    fPrice.value = priceValue;
    const kind = catalogKind(fCat.value, fName.value);
    setCatalogManagedUi(kind);
    if (!kind) syncPriceMode(fDesc.value, priceValue);
    const catalog = data?.catalog || await fetchCatalog(data?.id || 0);
    renderCatalogEditor(kind, catalog);
  }

  function closeModal(){ overlay.style.display="none"; hideErr(); }

  qs("#msX")?.addEventListener("click", closeModal);
  qs("#msCancel")?.addEventListener("click", closeModal);
  overlay?.addEventListener("click", (e)=>{ if(e.target === overlay) closeModal(); });
  document.addEventListener("keydown", (e)=>{ if(e.key === "Escape") closeModal(); });

  qs("#msAdd")?.addEventListener("click", ()=>openModal("add", null));

  qsa("[data-ms-edit]").forEach(btn=>{
    btn.addEventListener("click", ()=>{
      const raw = btn.getAttribute("data-ms-edit");
      const data = raw ? JSON.parse(raw) : {};
      openModal("edit", data);
    });
  });

  qsa("[data-ms-del]").forEach(btn=>{
    btn.addEventListener("click", async ()=>{
      const id = btn.getAttribute("data-ms-del");
      if(!id) return;
      if(!confirm("Archive this service? Existing queue and order records will keep their saved service snapshot, but customers will no longer see this option.")) return;

      const fd = new FormData();
      fd.append("action","delete");
      fd.append("id", id);

      let txt;
      try {
        const res = await fetch(msApiUrl, {
          method:"POST",
          body:fd,
          credentials:"same-origin",
          headers: {"X-CSRF-Token": csrf()}
        });
        txt = await res.text();
      } catch (e) {
        window.servitechAdminToast?.error("Unable to archive the service.");
        return;
      }
      let out; try{ out = JSON.parse(txt); }catch(e){ window.servitechAdminToast?.error("Server returned an invalid response."); return; }
      if(!out.ok){ window.servitechAdminToast?.error(out.error || "Archive failed"); return; }
      window.servitechAdminToast?.persist("Service archived successfully.");
      location.reload();
    });
  });

  document.addEventListener("change", (event) => {
    if (event.target && event.target.id === "ms_priceMode") {
      if (catalogKind(fCat.value, fName.value)) return;
      syncPriceMode(fDesc.value, fPrice.value);
    }

    if (event.target && (event.target.id === "ms_category" || event.target.id === "ms_name")) {
      const kind = catalogKind(fCat.value, fName.value);
      setCatalogManagedUi(kind);
      renderCatalogEditor(kind, currentCatalog);
    }
  });

  qs("#msSave")?.addEventListener("click", async ()=>{
    hideErr();

    const priceMode = getFPriceMode();
    let descriptionValue = fDesc.value.trim();
    const kind = catalogKind(fCat.value, fName.value);
    let catalogPayload = null;

    if (kind) {
      catalogPayload = syncCatalogFromDom();
      const invalidFixedRule = (catalogPayload?.rules || []).some((rule) => {
        return Number(rule.active ?? 1) && rule.price_type === "fixed" && (rule.price === "" || !Number.isFinite(Number(rule.price)));
      });
      if (invalidFixedRule) {
        showErr("Active fixed-price catalog rules must have a valid price, or set them to For assessment.");
        return;
      }
      if (catalogHasPricingChanges(catalogPayload) && !confirm("Save catalog pricing changes? New customer submissions will use the updated prices, while old queue/order records keep their saved snapshots.")) {
        return;
      }
      if (fId.value && fActive.value === "0" && !confirm("Deactivate this service? Customers will no longer see it, but old queue/order records will remain readable.")) {
        return;
      }
    } else if (priceMode?.value === "full") {
      descriptionValue = replaceOptionPrice(descriptionValue, "Full", fPrice.value.trim());
    } else if (priceMode?.value === "half") {
      descriptionValue = replaceOptionPrice(descriptionValue, "Half", fPrice.value.trim());
    }

    const fd = new FormData();
    fd.append("action","save");
    if(fId.value) fd.append("id", fId.value);
    fd.append("category", fCat.value);
    fd.append("name", fName.value.trim());
    fd.append("description", descriptionValue);
    fd.append("price", fPrice.value.trim());
    fd.append("price_range", fPriceRange ? fPriceRange.value.trim() : "");
    if (catalogPayload) {
      fd.append("catalog_json", JSON.stringify(catalogPayload));
    }
    fd.append("active", fActive.value);
    fd.append("sort_order", fSort.value);

    let txt;
    try {
      const res = await fetch(msApiUrl, {
        method:"POST",
        body:fd,
        credentials:"same-origin",
        headers: {"X-CSRF-Token": csrf()}
      });
      txt = await res.text();
    } catch (e) {
      showErr("Unable to save the service.");
      return;
    }
    let out; try{ out = JSON.parse(txt); }catch(e){ showErr("Server returned non-JSON: "+txt); return; }
    if(!out.ok){ showErr(out.error || "Save failed"); return; }
    window.servitechAdminToast?.persist(fId.value ? "Service updated successfully." : "Service added successfully.");
    location.reload();
  });
})();
