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

  function showErr(msg){
    errBox.textContent = msg;
    errBox.style.display="block";
    window.servitechAdminToast?.error(msg);
  }
  function hideErr(){ errBox.textContent=""; errBox.style.display="none"; }

  function isDocumentPrintingService(category, name) {
    const normalizedCategory = String(category || "").trim().toLowerCase();
    const normalizedName = String(name || "").trim().toLowerCase();
    return normalizedCategory === "printing" && normalizedName.includes("document") && normalizedName.includes("printing");
  }

  function displayServiceName(name) {
    const value = String(name || "").trim();
    return value.toLowerCase() === "xerox" ? "Photocopy" : value;
  }

  function isXeroxService(category, name) {
    const normalizedCategory = String(category || "").trim().toLowerCase();
    const normalizedName = String(name || "").trim().toLowerCase();
    return normalizedCategory === "printing" && (normalizedName.includes("xerox") || normalizedName.includes("photocopy"));
  }

  function isRushIdService(category, name) {
    const normalizedCategory = String(category || "").trim().toLowerCase();
    const normalizedName = String(name || "").trim().toLowerCase();
    return normalizedCategory === "printing" && normalizedName.includes("rush") && normalizedName.includes("id");
  }

  function isLaminatingService(category, name) {
    const normalizedCategory = String(category || "").trim().toLowerCase();
    const normalizedName = String(name || "").trim().toLowerCase();
    return normalizedCategory === "printing" && normalizedName.includes("laminat");
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
        <label>Letter Full Colored Price</label>
        <input id="ms_letter_full_price" type="text" value="10.00">
      </div>
      <div class="ms-field">
        <label>Letter Half Colored Price</label>
        <input id="ms_letter_half_price" type="text" value="5.00">
      </div>
      <div class="ms-field">
        <label>Letter Black and White Price</label>
        <input id="ms_letter_bw_price" type="text" value="5.00">
      </div>
      <div class="ms-field">
        <label>8.5x13 Full Colored Price</label>
        <input id="ms_long_full_price" type="text" value="10.00">
      </div>
      <div class="ms-field">
        <label>8.5x13 Half Colored Price</label>
        <input id="ms_long_half_price" type="text" value="5.00">
      </div>
      <div class="ms-field">
        <label>8.5x13 Black and White Price</label>
        <input id="ms_long_bw_price" type="text" value="5.00">
      </div>
      <div class="ms-field">
        <label>A4 Full Colored Price</label>
        <input id="ms_a4_full_price" type="text" value="10.00">
      </div>
      <div class="ms-field">
        <label>A4 Half Colored Price</label>
        <input id="ms_a4_half_price" type="text" value="5.00">
      </div>
      <div class="ms-field">
        <label>A4 Black and White Price</label>
        <input id="ms_a4_bw_price" type="text" value="5.00">
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

    const stored = storedPrices || {};
    return {
      letterFull: stored.letterFull ?? stored.shortFull ?? "10.00",
      letterHalf: stored.letterHalf ?? stored.shortHalf ?? "5.00",
      letterBw: stored.letterBw ?? stored.shortHalf ?? "5.00",
      longFull: stored.longFull ?? "10.00",
      longHalf: stored.longHalf ?? "5.00",
      longBw: stored.longBw ?? stored.longHalf ?? "5.00",
      a4Full: stored.a4Full ?? "10.00",
      a4Half: stored.a4Half ?? "5.00",
      a4Bw: stored.a4Bw ?? stored.a4Half ?? "5.00",
    };
  }

  function getDocumentPriceValues() {
    return {
      letterFull: getDocumentPriceInput("#ms_letter_full_price")?.value.trim() || "",
      letterHalf: getDocumentPriceInput("#ms_letter_half_price")?.value.trim() || "",
      letterBw: getDocumentPriceInput("#ms_letter_bw_price")?.value.trim() || "",
      longFull: getDocumentPriceInput("#ms_long_full_price")?.value.trim() || "",
      longHalf: getDocumentPriceInput("#ms_long_half_price")?.value.trim() || "",
      longBw: getDocumentPriceInput("#ms_long_bw_price")?.value.trim() || "",
      a4Full: getDocumentPriceInput("#ms_a4_full_price")?.value.trim() || "",
      a4Half: getDocumentPriceInput("#ms_a4_half_price")?.value.trim() || "",
      a4Bw: getDocumentPriceInput("#ms_a4_bw_price")?.value.trim() || "",
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
        ? "Document Print uses fixed color prices for Long Bond, Short Bond, and A4."
        : "Choose Full or Half when editing print price lines inside the description.";
    }
    if (fDescriptionHint) {
      fDescriptionHint.textContent = enabled
        ? "The customer page keeps the paper/color groups fixed and uses these price fields."
        : "Use newline-separated entries. For Document Print, paper and color groups are fixed on the customer page.";
    }

    if (!enabled) return;

    const prices = getDocumentPrices(data || {});
    const inputMap = {
      "#ms_long_full_price": prices.longFull,
      "#ms_long_half_price": prices.longHalf,
      "#ms_long_bw_price": prices.longBw,
      "#ms_letter_full_price": prices.letterFull,
      "#ms_letter_half_price": prices.letterHalf,
      "#ms_letter_bw_price": prices.letterBw,
      "#ms_a4_full_price": prices.a4Full,
      "#ms_a4_half_price": prices.a4Half,
      "#ms_a4_bw_price": prices.a4Bw,
    };
    Object.keys(inputMap).forEach((selector) => {
      const input = getDocumentPriceInput(selector);
      if (input) input.value = inputMap[selector];
    });
    if (fPrice) fPrice.value = prices.letterBw;
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
        <label>Letter Colored Price</label>
        <input id="ms_xerox_letter_colored_price" type="text" placeholder="e.g., 3.00">
      </div>
      <div class="ms-field">
        <label>Letter Black and White Price</label>
        <input id="ms_xerox_letter_bw_price" type="text" placeholder="e.g., 3.00">
      </div>
      <div class="ms-field">
        <label>8.5x13 Colored Price</label>
        <input id="ms_xerox_long_colored_price" type="text" placeholder="e.g., 5.00">
      </div>
      <div class="ms-field">
        <label>8.5x13 Black and White Price</label>
        <input id="ms_xerox_long_bw_price" type="text" placeholder="e.g., 5.00">
      </div>
      <div class="ms-field">
        <label>A4 Colored Price</label>
        <input id="ms_xerox_a4_colored_price" type="text" placeholder="e.g., 3.00">
      </div>
      <div class="ms-field">
        <label>A4 Black and White Price</label>
        <input id="ms_xerox_a4_bw_price" type="text" placeholder="e.g., 3.00">
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
      letterColored: storedPrices?.letterColored ?? storedPrices?.short ?? linePrice("Short Bond Paper") ?? low,
      letterBw: storedPrices?.letterBw ?? storedPrices?.short ?? linePrice("Short Bond Paper") ?? low,
      longColored: storedPrices?.longColored ?? storedPrices?.long ?? linePrice("Long Bond Paper") ?? high,
      longBw: storedPrices?.longBw ?? storedPrices?.long ?? linePrice("Long Bond Paper") ?? high,
      a4Colored: storedPrices?.a4Colored ?? storedPrices?.a4 ?? linePrice("A4") ?? low,
      a4Bw: storedPrices?.a4Bw ?? storedPrices?.a4 ?? linePrice("A4") ?? low,
    };
  }

  function getXeroxPriceValues() {
    return {
      letterColored: getXeroxPriceInput("#ms_xerox_letter_colored_price")?.value.trim() || "",
      letterBw: getXeroxPriceInput("#ms_xerox_letter_bw_price")?.value.trim() || "",
      longColored: getXeroxPriceInput("#ms_xerox_long_colored_price")?.value.trim() || "",
      longBw: getXeroxPriceInput("#ms_xerox_long_bw_price")?.value.trim() || "",
      a4Colored: getXeroxPriceInput("#ms_xerox_a4_colored_price")?.value.trim() || "",
      a4Bw: getXeroxPriceInput("#ms_xerox_a4_bw_price")?.value.trim() || "",
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
    if (fPriceHint) fPriceHint.textContent = "Photocopy uses one full price per paper size.";
    if (fDescriptionHint) fDescriptionHint.textContent = "Description is optional. Paper prices are saved from the fields below.";

    const prices = getXeroxPrices(data || {});
    const inputMap = {
      "#ms_xerox_letter_colored_price": prices.letterColored,
      "#ms_xerox_letter_bw_price": prices.letterBw,
      "#ms_xerox_long_colored_price": prices.longColored,
      "#ms_xerox_long_bw_price": prices.longBw,
      "#ms_xerox_a4_colored_price": prices.a4Colored,
      "#ms_xerox_a4_bw_price": prices.a4Bw,
    };
    Object.keys(inputMap).forEach((selector) => {
      const input = getXeroxPriceInput(selector);
      if (input) input.value = inputMap[selector];
    });
    if (fPrice) fPrice.value = prices.letterBw;
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

  function ensureLaminatingPriceGrid() {
    let grid = qs("#ms_laminatingPriceGrid");
    if (grid) return grid;
    if (!fPriceField || !fPriceField.parentNode || !fPriceField.parentNode.parentNode) return null;

    grid = document.createElement("div");
    grid.className = "ms-row2";
    grid.id = "ms_laminatingPriceGrid";
    grid.innerHTML = `
      <div class="ms-field">
        <label>Thin Price</label>
        <input id="ms_laminating_thin_price" type="text" placeholder="e.g., 20.00">
      </div>
      <div class="ms-field">
        <label>Thick Price</label>
        <input id="ms_laminating_thick_price" type="text" placeholder="e.g., 30.00">
      </div>
    `;
    fPriceField.parentNode.parentNode.insertBefore(grid, fPriceField.parentNode);
    return grid;
  }

  function getLaminatingPriceInput(selector) {
    ensureLaminatingPriceGrid();
    return qs(selector);
  }

  function getLaminatingPrices(data) {
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
    const linePrice = (label) => {
      const match = description.match(new RegExp(`${label}[^0-9]*([0-9]+(?:\\.[0-9]+)?)`, "i"));
      return match ? match[1] : null;
    };

    return {
      thin: storedPrices?.thin ?? linePrice("Thin") ?? linePrice("Manipis") ?? (rangeValues[0] !== undefined ? formatPlainPrice(rangeValues[0]) : (data?.price || fPrice?.value || "20")),
      thick: storedPrices?.thick ?? linePrice("Thick") ?? linePrice("Makapal") ?? (rangeValues[rangeValues.length - 1] !== undefined ? formatPlainPrice(rangeValues[rangeValues.length - 1]) : "30"),
    };
  }

  function getLaminatingPriceValues() {
    return {
      thin: getLaminatingPriceInput("#ms_laminating_thin_price")?.value.trim() || "",
      thick: getLaminatingPriceInput("#ms_laminating_thick_price")?.value.trim() || "",
    };
  }

  function syncLaminatingPriceRange() {
    if (!fPriceRange) return;
    const values = Object.values(getLaminatingPriceValues()).map(Number).filter((value) => Number.isFinite(value));
    if (!values.length) return;
    fPriceRange.value = formatPesoRange(Math.min(...values), Math.max(...values));
  }

  function setLaminatingUi(enabled, data) {
    const grid = ensureLaminatingPriceGrid();

    if (grid) grid.style.display = enabled ? "" : "none";
    if (!enabled) return;

    if (fPriceModeField) fPriceModeField.style.display = "none";
    if (fPriceField) fPriceField.style.display = "none";
    if (fPriceHint) fPriceHint.textContent = "Laminating uses one price for Thin and one price for Thick.";
    if (fDescriptionHint) fDescriptionHint.textContent = "Description is optional. Thin/Thick prices are saved from the fields below.";

    const prices = getLaminatingPrices(data || {});
    const thinInput = getLaminatingPriceInput("#ms_laminating_thin_price");
    const thickInput = getLaminatingPriceInput("#ms_laminating_thick_price");
    if (thinInput) thinInput.value = prices.thin;
    if (thickInput) thickInput.value = prices.thick;
    if (fPrice) fPrice.value = prices.thin;
    syncLaminatingPriceRange();
  }

  function catalogKind(category, name) {
    const cat = String(category || "").toLowerCase();
    const label = String(name || "").toLowerCase();
    if (cat === "printing" && label.includes("document")) return "document_matrix";
    if (cat === "printing" && (label.includes("photocopy") || label.includes("xerox"))) return "photocopy_matrix";
    if (cat === "printing" && label.includes("rush") && label.includes("id")) return "package_list";
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
            { value_key: "letter", label: "Letter", active: 1, sort_order: 0 },
            { value_key: "8_5x13", label: "8.5x13", active: 1, sort_order: 1 },
            { value_key: "a4", label: "A4", active: 1, sort_order: 2 },
          ] },
          { group_key: "color_option", name: "Color Option", active: 1, sort_order: 1, values: [
            { value_key: "half_colored", label: "Half Colored", active: 1, sort_order: 0 },
            { value_key: "full_colored", label: "Full Colored", active: 1, sort_order: 1 },
            { value_key: "black_and_white", label: "Black and White", active: 1, sort_order: 2 },
          ] },
        ],
        rules: [],
      };
    }
    if (kind === "photocopy_matrix") {
      return {
        groups: [
          { group_key: "paper_size", name: "Paper Size", active: 1, sort_order: 0, values: [
            { value_key: "letter", label: "Letter", active: 1, sort_order: 0 },
            { value_key: "8_5x13", label: "8.5x13", active: 1, sort_order: 1 },
            { value_key: "a4", label: "A4", active: 1, sort_order: 2 },
          ] },
          { group_key: "color_option", name: "Color Option", active: 1, sort_order: 1, values: [
            { value_key: "colored", label: "Colored", active: 1, sort_order: 0 },
            { value_key: "black_and_white", label: "Black and White", active: 1, sort_order: 1 },
          ] },
        ],
        rules: [],
      };
    }
    if (kind === "package_list") {
      return { groups: [{ group_key: "package", name: "Package", active: 1, sort_order: 0, values: [] }], rules: [] };
    }
    if (kind === "repair_matrix") {
      return {
        groups: [
          { group_key: "device_type", name: "Device Type", active: 1, sort_order: 0, values: [
            { value_key: "phone", label: "Phone", active: 1, sort_order: 0 },
            { value_key: "laptop", label: "Laptop", active: 1, sort_order: 1 },
            { value_key: "desktop", label: "Desktop", active: 1, sort_order: 2 },
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
          <strong>${group.name}</strong>
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
      return;
    }
    currentCatalog = normalizeCatalog(catalog, kind);
    if (kind === "document_matrix" || kind === "photocopy_matrix") {
      catalogEditor.innerHTML = renderMatrixEditor(kind);
    } else if (kind === "package_list") {
      catalogEditor.innerHTML = renderRuleList(kind, "package", "Rush ID Packages");
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
    const isDocumentPrinting = isDocumentPrintingService(fCat.value, fName.value);
    const isXerox = isXeroxService(fCat.value, fName.value);
    const isRushId = isRushIdService(fCat.value, fName.value);
    const isLaminating = isLaminatingService(fCat.value, fName.value);
    setDocumentPrintingUi(isDocumentPrinting, data);
    setXeroxUi(isXerox, data);
    setRushIdUi(isRushId, data);
    setLaminatingUi(isLaminating, data);
    if (!isDocumentPrinting && !isXerox && !isRushId && !isLaminating) syncPriceMode(fDesc.value, priceValue);

    const kind = catalogKind(fCat.value, fName.value);
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
      if (isDocumentPrintingService(fCat.value, fName.value)) return;
      syncPriceMode(fDesc.value, fPrice.value);
    }

    if (event.target && (event.target.id === "ms_category" || event.target.id === "ms_name")) {
      const isDocumentPrinting = isDocumentPrintingService(fCat.value, fName.value);
      const isXerox = isXeroxService(fCat.value, fName.value);
      const isRushId = isRushIdService(fCat.value, fName.value);
      const isLaminating = isLaminatingService(fCat.value, fName.value);
      setDocumentPrintingUi(isDocumentPrinting);
      setXeroxUi(isXerox);
      setRushIdUi(isRushId);
      setLaminatingUi(isLaminating);
      renderCatalogEditor(catalogKind(fCat.value, fName.value), currentCatalog);
    }
  });

  document.addEventListener("input", (event) => {
    const isDocumentPrinting = isDocumentPrintingService(fCat.value, fName.value);
    const isXerox = isXeroxService(fCat.value, fName.value);
    const isRushId = isRushIdService(fCat.value, fName.value);
    const isLaminating = isLaminatingService(fCat.value, fName.value);
    if (!event.target) return;

    if (isDocumentPrinting && [
      "ms_letter_full_price",
      "ms_letter_half_price",
      "ms_letter_bw_price",
      "ms_long_full_price",
      "ms_long_half_price",
      "ms_long_bw_price",
      "ms_a4_full_price",
      "ms_a4_half_price",
      "ms_a4_bw_price",
    ].includes(event.target.id)) {
      syncDocumentPriceRange();
      return;
    }

    if (isXerox && [
      "ms_xerox_letter_colored_price",
      "ms_xerox_letter_bw_price",
      "ms_xerox_long_colored_price",
      "ms_xerox_long_bw_price",
      "ms_xerox_a4_colored_price",
      "ms_xerox_a4_bw_price",
    ].includes(event.target.id)) {
      syncXeroxPriceRange();
      return;
    }

    if (isRushId && /^ms_rush_package_[1-6]$/.test(event.target.id)) {
      syncRushPackageRange();
      return;
    }

    if (isLaminating && [
      "ms_laminating_thin_price",
      "ms_laminating_thick_price",
    ].includes(event.target.id)) {
      syncLaminatingPriceRange();
    }
  });

  qs("#msSave")?.addEventListener("click", async ()=>{
    hideErr();

    const priceMode = getFPriceMode();
    let descriptionValue = fDesc.value.trim();
    const isDocumentPrinting = isDocumentPrintingService(fCat.value, fName.value);
    const isXerox = isXeroxService(fCat.value, fName.value);
    const isRushId = isRushIdService(fCat.value, fName.value);
    const isLaminating = isLaminatingService(fCat.value, fName.value);
    if (isDocumentPrinting) {
      const prices = getDocumentPriceValues();
      const invalid = Object.values(prices).some((value) => value === "" || !Number.isFinite(Number(value)));
      if (invalid) {
        showErr("Enter valid Letter, 8.5x13, and A4 prices.");
        return;
      }

      if (
        Number(prices.letterFull) < Number(prices.letterHalf) ||
        Number(prices.longFull) < Number(prices.longHalf) ||
        Number(prices.a4Full) < Number(prices.a4Half)
      ) {
        showErr("Full Colored prices should be greater than or equal to Half Colored prices.");
        return;
      }

      syncDocumentPriceRange();
      fPrice.value = prices.letterBw;
    } else if (isXerox) {
      const prices = getXeroxPriceValues();
      const invalid = Object.values(prices).some((value) => value === "" || !Number.isFinite(Number(value)));
      if (invalid) {
        showErr("Enter valid photocopy prices for Letter, 8.5x13, and A4.");
        return;
      }

      syncXeroxPriceRange();
      fPrice.value = prices.letterBw;
    } else if (isRushId) {
      const prices = getRushPackageValues();
      const invalid = Object.values(prices).some((value) => value === "" || !Number.isFinite(Number(value)));
      if (invalid) {
        showErr("Enter valid prices for Rush ID packages 1-6.");
        return;
      }

      syncRushPackageRange();
      fPrice.value = prices.package2;
    } else if (isLaminating) {
      const prices = getLaminatingPriceValues();
      const invalid = Object.values(prices).some((value) => value === "" || !Number.isFinite(Number(value)));
      if (invalid) {
        showErr("Enter valid Thin and Thick laminating prices.");
        return;
      }

      syncLaminatingPriceRange();
      fPrice.value = prices.thin;
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
    } else if (isLaminating) {
      fd.append("pricing_json", JSON.stringify(getLaminatingPriceValues()));
    }
    const catalogPayload = syncCatalogFromDom();
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
