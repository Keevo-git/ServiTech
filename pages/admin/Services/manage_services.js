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

  function showErr(msg){ errBox.textContent = msg; errBox.style.display="block"; }
  function hideErr(){ errBox.textContent=""; errBox.style.display="none"; }

  function isDocumentPrintingService(category, name) {
    const normalizedCategory = String(category || "").trim().toLowerCase();
    const normalizedName = String(name || "").trim().toLowerCase();
    return normalizedCategory === "printing" && normalizedName.includes("document") && normalizedName.includes("printing");
  }

  function isXeroxService(category, name) {
    const normalizedCategory = String(category || "").trim().toLowerCase();
    const normalizedName = String(name || "").trim().toLowerCase();
    return normalizedCategory === "printing" && normalizedName.includes("xerox");
  }

  function isRushIdService(category, name) {
    const normalizedCategory = String(category || "").trim().toLowerCase();
    const normalizedName = String(name || "").trim().toLowerCase();
    return normalizedCategory === "printing" && normalizedName.includes("rush") && normalizedName.includes("id");
  }

  function parseMoneyValues(value) {
    const matches = String(value || "").match(/[0-9]+(?:\.[0-9]+)?/g);
    if (!matches) return [];
    return matches
      .map((item) => Number(item))
      .filter((item) => Number.isFinite(item) && item >= 0)
      .sort((a, b) => a - b);
  }

  function formatPlainPrice(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return "";
    return number.toFixed(2).replace(/\.00$/, "");
  }

  function formatPesoRange(low, high) {
    return `\u20B1${formatPlainPrice(low)} - \u20B1${formatPlainPrice(high)}`;
  }

  function getFPriceMode() {
    return qs("#ms_priceMode");
  }

  function extractOptionPrice(description, option) {
    if (!description) return null;
    const match = description.match(new RegExp(`${option}\\s*(?:/\\s*B&W)?\\s*[-\\u2013\\u2014]?\\s*\\u20B1?\\s*([0-9]+(?:\\.[0-9]+)?)`, "i"));
    return match ? match[1] : null;
  }

  function extractBlockPrice(description, blockName, option) {
    const blocks = String(description || "").split(/\r?\n\s*\r?\n/);
    const block = blocks.find((item) => item.toLowerCase().includes(blockName.toLowerCase()));
    if (!block) return null;
    return extractOptionPrice(block, option);
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

  function ensureDocumentPriceGrid() {
    let grid = qs("#ms_documentPriceGrid");
    if (grid) return grid;
    if (!fPriceField || !fPriceField.parentNode || !fPriceField.parentNode.parentNode) return null;

    grid = document.createElement("div");
    grid.className = "ms-row2";
    grid.id = "ms_documentPriceGrid";
    grid.innerHTML = `
      <div class="ms-field">
        <label>Long Bond Full Price</label>
        <input id="ms_long_full_price" type="text" placeholder="e.g., 15.00">
      </div>
      <div class="ms-field">
        <label>Long Bond Half / B&W Price</label>
        <input id="ms_long_half_price" type="text" placeholder="e.g., 10.00">
      </div>
      <div class="ms-field">
        <label>Short Bond Full Price</label>
        <input id="ms_short_full_price" type="text" placeholder="e.g., 10.00">
      </div>
      <div class="ms-field">
        <label>Short Bond Half / B&W Price</label>
        <input id="ms_short_half_price" type="text" placeholder="e.g., 5.00">
      </div>
      <div class="ms-field">
        <label>A4 Full Price</label>
        <input id="ms_a4_full_price" type="text" placeholder="e.g., 10.00">
      </div>
      <div class="ms-field">
        <label>A4 Half / B&W Price</label>
        <input id="ms_a4_half_price" type="text" placeholder="e.g., 5.00">
      </div>
      <div class="ms-field">
        <label>A3 Full Price</label>
        <input id="ms_a3_full_price" type="text" placeholder="e.g., 20.00">
      </div>
      <div class="ms-field">
        <label>A3 Half / B&W Price</label>
        <input id="ms_a3_half_price" type="text" placeholder="e.g., 10.00">
      </div>
    `;
    fPriceField.parentNode.parentNode.insertBefore(grid, fPriceField.parentNode);
    return grid;
  }

  function getDocumentPriceInput(selector) {
    ensureDocumentPriceGrid();
    return qs(selector);
  }

  function getDocumentPrices(data) {
    let storedPrices = null;
    if (data?.pricing_json) {
      try {
        storedPrices = typeof data.pricing_json === "string"
          ? JSON.parse(data.pricing_json)
          : data.pricing_json;
      } catch (err) {
        storedPrices = null;
      }
    }

    const rangeValues = parseMoneyValues(data?.price_range || fPriceRange?.value || "");
    const description = String(data?.description || fDesc?.value || "");
    const low = rangeValues[0] !== undefined ? formatPlainPrice(rangeValues[0]) : (data?.price || fPrice?.value || "5");
    const high = rangeValues[rangeValues.length - 1] !== undefined ? formatPlainPrice(rangeValues[rangeValues.length - 1]) : "10";

    return {
      longFull: storedPrices?.longFull ?? extractBlockPrice(description, "Long Bond", "Full") ?? high,
      longHalf: storedPrices?.longHalf ?? extractBlockPrice(description, "Long Bond", "Half") ?? low,
      shortFull: storedPrices?.shortFull ?? extractBlockPrice(description, "Short Bond", "Full") ?? high,
      shortHalf: storedPrices?.shortHalf ?? extractBlockPrice(description, "Short Bond", "Half") ?? low,
      a4Full: storedPrices?.a4Full ?? extractBlockPrice(description, "A4", "Full") ?? high,
      a4Half: storedPrices?.a4Half ?? extractBlockPrice(description, "A4", "Half") ?? low,
      a3Full: storedPrices?.a3Full ?? extractBlockPrice(description, "A3", "Full") ?? high,
      a3Half: storedPrices?.a3Half ?? extractBlockPrice(description, "A3", "Half") ?? low,
    };
  }

  function getDocumentPriceValues() {
    return {
      longFull: getDocumentPriceInput("#ms_long_full_price")?.value.trim() || "",
      longHalf: getDocumentPriceInput("#ms_long_half_price")?.value.trim() || "",
      shortFull: getDocumentPriceInput("#ms_short_full_price")?.value.trim() || "",
      shortHalf: getDocumentPriceInput("#ms_short_half_price")?.value.trim() || "",
      a4Full: getDocumentPriceInput("#ms_a4_full_price")?.value.trim() || "",
      a4Half: getDocumentPriceInput("#ms_a4_half_price")?.value.trim() || "",
      a3Full: getDocumentPriceInput("#ms_a3_full_price")?.value.trim() || "",
      a3Half: getDocumentPriceInput("#ms_a3_half_price")?.value.trim() || "",
    };
  }

  function syncDocumentPriceRange() {
    if (!fPriceRange) return;
    const values = Object.values(getDocumentPriceValues()).map(Number).filter((value) => Number.isFinite(value));
    if (!values.length) return;
    fPriceRange.value = formatPesoRange(Math.min(...values), Math.max(...values));
  }

  function setDocumentPrintingUi(enabled, data) {
    const grid = ensureDocumentPriceGrid();

    if (fPriceModeField) fPriceModeField.style.display = enabled ? "none" : "";
    if (fPriceField) fPriceField.style.display = enabled ? "none" : "";
    if (grid) grid.style.display = enabled ? "" : "none";
    if (fPriceLabel) fPriceLabel.textContent = "Price (optional)";

    if (fPriceHint) {
      fPriceHint.textContent = enabled
        ? "Long Bond, Short Bond, A4, and A3 can each use separate prices."
        : "Choose Full or Half when editing print price lines inside the description.";
    }
    if (fDescriptionHint) {
      fDescriptionHint.textContent = enabled
        ? "The customer page keeps the paper/color groups fixed and uses these price fields."
        : "Use newline-separated entries. For Document Printing, paper and color groups are fixed on the customer page.";
    }

    if (!enabled) return;

    const prices = getDocumentPrices(data || {});
    const inputMap = {
      "#ms_long_full_price": prices.longFull,
      "#ms_long_half_price": prices.longHalf,
      "#ms_short_full_price": prices.shortFull,
      "#ms_short_half_price": prices.shortHalf,
      "#ms_a4_full_price": prices.a4Full,
      "#ms_a4_half_price": prices.a4Half,
      "#ms_a3_full_price": prices.a3Full,
      "#ms_a3_half_price": prices.a3Half,
    };
    Object.keys(inputMap).forEach((selector) => {
      const input = getDocumentPriceInput(selector);
      if (input) input.value = inputMap[selector];
    });
    if (fPrice) fPrice.value = prices.shortHalf;
    syncDocumentPriceRange();
  }

  function ensureXeroxPriceGrid() {
    let grid = qs("#ms_xeroxPriceGrid");
    if (grid) return grid;
    if (!fPriceField || !fPriceField.parentNode || !fPriceField.parentNode.parentNode) return null;

    grid = document.createElement("div");
    grid.className = "ms-row2";
    grid.id = "ms_xeroxPriceGrid";
    grid.innerHTML = `
      <div class="ms-field">
        <label>Long Bond Price</label>
        <input id="ms_xerox_long_price" type="text" placeholder="e.g., 5.00">
      </div>
      <div class="ms-field">
        <label>Short Bond Price</label>
        <input id="ms_xerox_short_price" type="text" placeholder="e.g., 3.00">
      </div>
      <div class="ms-field">
        <label>A4 Price</label>
        <input id="ms_xerox_a4_price" type="text" placeholder="e.g., 3.00">
      </div>
      <div class="ms-field">
        <label>A3 Price</label>
        <input id="ms_xerox_a3_price" type="text" placeholder="e.g., 5.00">
      </div>
    `;
    fPriceField.parentNode.parentNode.insertBefore(grid, fPriceField.parentNode);
    return grid;
  }

  function getXeroxPriceInput(selector) {
    ensureXeroxPriceGrid();
    return qs(selector);
  }

  function getXeroxPrices(data) {
    let storedPrices = null;
    if (data?.pricing_json) {
      try {
        storedPrices = typeof data.pricing_json === "string"
          ? JSON.parse(data.pricing_json)
          : data.pricing_json;
      } catch (err) {
        storedPrices = null;
      }
    }

    const rangeValues = parseMoneyValues(data?.price_range || fPriceRange?.value || "");
    const description = String(data?.description || fDesc?.value || "");
    const low = rangeValues[0] !== undefined ? formatPlainPrice(rangeValues[0]) : (data?.price || fPrice?.value || "3");
    const high = rangeValues[rangeValues.length - 1] !== undefined ? formatPlainPrice(rangeValues[rangeValues.length - 1]) : "5";
    const linePrice = (label) => {
      const escaped = label.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
      const match = description.match(new RegExp(`${escaped}\\s*:?\\s*\\u20B1?\\s*([0-9]+(?:\\.[0-9]+)?)`, "i"));
      return match ? match[1] : null;
    };

    return {
      long: storedPrices?.long ?? linePrice("Long Bond Paper") ?? high,
      short: storedPrices?.short ?? linePrice("Short Bond Paper") ?? low,
      a4: storedPrices?.a4 ?? linePrice("A4") ?? low,
      a3: storedPrices?.a3 ?? linePrice("A3") ?? high,
    };
  }

  function getXeroxPriceValues() {
    return {
      long: getXeroxPriceInput("#ms_xerox_long_price")?.value.trim() || "",
      short: getXeroxPriceInput("#ms_xerox_short_price")?.value.trim() || "",
      a4: getXeroxPriceInput("#ms_xerox_a4_price")?.value.trim() || "",
      a3: getXeroxPriceInput("#ms_xerox_a3_price")?.value.trim() || "",
    };
  }

  function syncXeroxPriceRange() {
    if (!fPriceRange) return;
    const values = Object.values(getXeroxPriceValues()).map(Number).filter((value) => Number.isFinite(value));
    if (!values.length) return;
    fPriceRange.value = formatPesoRange(Math.min(...values), Math.max(...values));
  }

  function setXeroxUi(enabled, data) {
    const grid = ensureXeroxPriceGrid();

    if (grid) grid.style.display = enabled ? "" : "none";
    if (!enabled) return;

    if (fPriceModeField) fPriceModeField.style.display = "none";
    if (fPriceField) fPriceField.style.display = "none";
    if (fPriceHint) fPriceHint.textContent = "Xerox uses one full price per paper size.";
    if (fDescriptionHint) fDescriptionHint.textContent = "Description is optional. Paper prices are saved from the fields below.";

    const prices = getXeroxPrices(data || {});
    const inputMap = {
      "#ms_xerox_long_price": prices.long,
      "#ms_xerox_short_price": prices.short,
      "#ms_xerox_a4_price": prices.a4,
      "#ms_xerox_a3_price": prices.a3,
    };
    Object.keys(inputMap).forEach((selector) => {
      const input = getXeroxPriceInput(selector);
      if (input) input.value = inputMap[selector];
    });
    if (fPrice) fPrice.value = prices.short;
    syncXeroxPriceRange();
  }

  function ensureRushPackageGrid() {
    let grid = qs("#ms_rushPackageGrid");
    if (grid) return grid;
    if (!fPriceField || !fPriceField.parentNode || !fPriceField.parentNode.parentNode) return null;

    grid = document.createElement("div");
    grid.className = "ms-row2";
    grid.id = "ms_rushPackageGrid";
    grid.innerHTML = [1, 2, 3, 4, 5, 6].map((number) => `
      <div class="ms-field">
        <label>Package ${number} Price</label>
        <input id="ms_rush_package_${number}" type="text" placeholder="e.g., ${number === 1 ? "40.00" : "30.00"}">
      </div>
    `).join("");
    fPriceField.parentNode.parentNode.insertBefore(grid, fPriceField.parentNode);
    return grid;
  }

  function getRushPackageInput(number) {
    ensureRushPackageGrid();
    return qs(`#ms_rush_package_${number}`);
  }

  function getRushPackagePrices(data) {
    let storedPrices = null;
    if (data?.pricing_json) {
      try {
        storedPrices = typeof data.pricing_json === "string"
          ? JSON.parse(data.pricing_json)
          : data.pricing_json;
      } catch (err) {
        storedPrices = null;
      }
    }

    const defaults = { package1: 40, package2: 30, package3: 30, package4: 50, package5: 30, package6: 50 };
    const rangeValues = parseMoneyValues(data?.price_range || fPriceRange?.value || "");
    const low = rangeValues[0] !== undefined ? formatPlainPrice(rangeValues[0]) : (data?.price || fPrice?.value || "30");
    const high = rangeValues[rangeValues.length - 1] !== undefined ? formatPlainPrice(rangeValues[rangeValues.length - 1]) : "50";

    return {
      package1: storedPrices?.package1 ?? defaults.package1 ?? high,
      package2: storedPrices?.package2 ?? defaults.package2 ?? low,
      package3: storedPrices?.package3 ?? defaults.package3 ?? low,
      package4: storedPrices?.package4 ?? defaults.package4 ?? high,
      package5: storedPrices?.package5 ?? defaults.package5 ?? low,
      package6: storedPrices?.package6 ?? defaults.package6 ?? high,
    };
  }

  function getRushPackageValues() {
    return {
      package1: getRushPackageInput(1)?.value.trim() || "",
      package2: getRushPackageInput(2)?.value.trim() || "",
      package3: getRushPackageInput(3)?.value.trim() || "",
      package4: getRushPackageInput(4)?.value.trim() || "",
      package5: getRushPackageInput(5)?.value.trim() || "",
      package6: getRushPackageInput(6)?.value.trim() || "",
    };
  }

  function syncRushPackageRange() {
    if (!fPriceRange) return;
    const values = Object.values(getRushPackageValues()).map(Number).filter((value) => Number.isFinite(value));
    if (!values.length) return;
    fPriceRange.value = formatPesoRange(Math.min(...values), Math.max(...values));
  }

  function setRushIdUi(enabled, data) {
    const grid = ensureRushPackageGrid();

    if (grid) grid.style.display = enabled ? "" : "none";
    if (!enabled) return;

    if (fPriceModeField) fPriceModeField.style.display = "none";
    if (fPriceField) fPriceField.style.display = "none";
    if (fPriceHint) fPriceHint.textContent = "Rush ID package names stay fixed. Only package prices change.";
    if (fDescriptionHint) fDescriptionHint.textContent = "Description is optional. Package prices are saved from the fields below.";

    const prices = getRushPackagePrices(data || {});
    [1, 2, 3, 4, 5, 6].forEach((number) => {
      const input = getRushPackageInput(number);
      if (input) input.value = prices[`package${number}`];
    });
    if (fPrice) fPrice.value = prices.package2;
    syncRushPackageRange();
  }

  function openModal(mode, data){
    hideErr();
    overlay.style.display="flex";
    modalTitle.textContent = (mode === "edit") ? "Edit Service" : "Add Service";

    fId.value = data?.id || "";
    fCat.value = data?.category || window.MS_ACTIVE_TAB || "printing";
    fName.value = data?.name || "";
    fDesc.value = data?.description || "";
    if (fPriceRange) fPriceRange.value = data?.price_range || "";
    fActive.value = (data?.active ?? 1) ? "1" : "0";
    fSort.value = data?.sort_order ?? 0;

    const priceValue = (data?.price ?? "") === null ? "" : (data?.price ?? "");
    fPrice.value = priceValue;
    const isDocumentPrinting = isDocumentPrintingService(fCat.value, fName.value);
    const isXerox = isXeroxService(fCat.value, fName.value);
    const isRushId = isRushIdService(fCat.value, fName.value);
    setDocumentPrintingUi(isDocumentPrinting, data);
    setXeroxUi(isXerox, data);
    setRushIdUi(isRushId, data);
    if (!isDocumentPrinting && !isXerox && !isRushId) syncPriceMode(fDesc.value, priceValue);
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
      if(!confirm("Delete this service?")) return;

      const fd = new FormData();
      fd.append("action","delete");
      fd.append("id", id);

      const res = await fetch(msApiUrl, {
        method:"POST",
        body:fd,
        credentials:"same-origin",
        headers: {"X-CSRF-Token": csrf()}
      });
      const txt = await res.text();
      let out; try{ out = JSON.parse(txt); }catch(e){ alert("Non-JSON: "+txt); return; }
      if(!out.ok){ alert(out.error || "Delete failed"); return; }
      location.reload();
    });
  });

  document.addEventListener("change", (event) => {
    if (event.target && event.target.id === "ms_priceMode") {
      if (isDocumentPrintingService(fCat.value, fName.value)) return;
      syncPriceMode(fDesc.value, fPrice.value);
    }

    if (event.target && (event.target.id === "ms_category" || event.target.id === "ms_name")) {
      const isDocumentPrinting = isDocumentPrintingService(fCat.value, fName.value);
      const isXerox = isXeroxService(fCat.value, fName.value);
      const isRushId = isRushIdService(fCat.value, fName.value);
      setDocumentPrintingUi(isDocumentPrinting);
      setXeroxUi(isXerox);
      setRushIdUi(isRushId);
    }
  });

  document.addEventListener("input", (event) => {
    const isDocumentPrinting = isDocumentPrintingService(fCat.value, fName.value);
    const isXerox = isXeroxService(fCat.value, fName.value);
    const isRushId = isRushIdService(fCat.value, fName.value);
    if (!event.target) return;

    if (isDocumentPrinting && [
      "ms_long_full_price",
      "ms_long_half_price",
      "ms_short_full_price",
      "ms_short_half_price",
      "ms_a4_full_price",
      "ms_a4_half_price",
      "ms_a3_full_price",
      "ms_a3_half_price",
    ].includes(event.target.id)) {
      syncDocumentPriceRange();
      return;
    }

    if (isXerox && [
      "ms_xerox_long_price",
      "ms_xerox_short_price",
      "ms_xerox_a4_price",
      "ms_xerox_a3_price",
    ].includes(event.target.id)) {
      syncXeroxPriceRange();
      return;
    }

    if (isRushId && /^ms_rush_package_[1-6]$/.test(event.target.id)) {
      syncRushPackageRange();
    }
  });

  qs("#msSave")?.addEventListener("click", async ()=>{
    hideErr();

    const priceMode = getFPriceMode();
    let descriptionValue = fDesc.value.trim();
    const isDocumentPrinting = isDocumentPrintingService(fCat.value, fName.value);
    const isXerox = isXeroxService(fCat.value, fName.value);
    const isRushId = isRushIdService(fCat.value, fName.value);
    if (isDocumentPrinting) {
      const prices = getDocumentPriceValues();
      const invalid = Object.values(prices).some((value) => value === "" || !Number.isFinite(Number(value)));
      if (invalid) {
        showErr("Enter valid Long Bond, Short Bond, A4, and A3 prices.");
        return;
      }

      if (
        Number(prices.longFull) < Number(prices.longHalf) ||
        Number(prices.shortFull) < Number(prices.shortHalf) ||
        Number(prices.a4Full) < Number(prices.a4Half) ||
        Number(prices.a3Full) < Number(prices.a3Half)
      ) {
        showErr("Full prices should be greater than or equal to Half / B&W prices.");
        return;
      }

      syncDocumentPriceRange();
      fPrice.value = prices.shortHalf;
    } else if (isXerox) {
      const prices = getXeroxPriceValues();
      const invalid = Object.values(prices).some((value) => value === "" || !Number.isFinite(Number(value)));
      if (invalid) {
        showErr("Enter valid Xerox prices for Long Bond, Short Bond, A4, and A3.");
        return;
      }

      syncXeroxPriceRange();
      fPrice.value = prices.short;
    } else if (isRushId) {
      const prices = getRushPackageValues();
      const invalid = Object.values(prices).some((value) => value === "" || !Number.isFinite(Number(value)));
      if (invalid) {
        showErr("Enter valid prices for Rush ID packages 1-6.");
        return;
      }

      syncRushPackageRange();
      fPrice.value = prices.package2;
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
    if (isDocumentPrinting) {
      fd.append("pricing_json", JSON.stringify(getDocumentPriceValues()));
    } else if (isXerox) {
      fd.append("pricing_json", JSON.stringify(getXeroxPriceValues()));
    } else if (isRushId) {
      fd.append("pricing_json", JSON.stringify(getRushPackageValues()));
    }
    fd.append("active", fActive.value);
    fd.append("sort_order", fSort.value);

    const res = await fetch(msApiUrl, {
      method:"POST",
      body:fd,
      credentials:"same-origin",
      headers: {"X-CSRF-Token": csrf()}
    });
    const txt = await res.text();
    let out; try{ out = JSON.parse(txt); }catch(e){ showErr("Server returned non-JSON: "+txt); return; }
    if(!out.ok){ showErr(out.error || "Save failed"); return; }
    location.reload();
  });
})();
