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
        <label>Short Bond / A4 Full Price</label>
        <input id="ms_short_full_price" type="text" placeholder="e.g., 10.00">
      </div>
      <div class="ms-field">
        <label>Short Bond / A4 Half / B&W Price</label>
        <input id="ms_short_half_price" type="text" placeholder="e.g., 5.00">
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
    const rangeValues = parseMoneyValues(data?.price_range || fPriceRange?.value || "");
    const description = String(data?.description || fDesc?.value || "");
    const low = rangeValues[0] !== undefined ? formatPlainPrice(rangeValues[0]) : (data?.price || fPrice?.value || "5");
    const high = rangeValues[rangeValues.length - 1] !== undefined ? formatPlainPrice(rangeValues[rangeValues.length - 1]) : "10";

    return {
      longFull: extractBlockPrice(description, "Long Bond", "Full") || high,
      longHalf: extractBlockPrice(description, "Long Bond", "Half") || low,
      shortFull: extractBlockPrice(description, "Short Bond", "Full") || high,
      shortHalf: extractBlockPrice(description, "Short Bond", "Half") || low,
    };
  }

  function getDocumentPriceValues() {
    return {
      longFull: getDocumentPriceInput("#ms_long_full_price")?.value.trim() || "",
      longHalf: getDocumentPriceInput("#ms_long_half_price")?.value.trim() || "",
      shortFull: getDocumentPriceInput("#ms_short_full_price")?.value.trim() || "",
      shortHalf: getDocumentPriceInput("#ms_short_half_price")?.value.trim() || "",
    };
  }

  function buildDocumentDescription(prices) {
    return [
      "Long Bond Paper",
      `Full - ${prices.longFull}`,
      `Half / B&W - ${prices.longHalf}`,
      "",
      "Short Bond Paper / A4",
      `Full - ${prices.shortFull}`,
      `Half / B&W - ${prices.shortHalf}`,
    ].join("\n");
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
        ? "Long Bond and Short Bond prices can differ. A4 follows the Short Bond / A4 prices."
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
    };
    Object.keys(inputMap).forEach((selector) => {
      const input = getDocumentPriceInput(selector);
      if (input) input.value = inputMap[selector];
    });
    if (fPrice) fPrice.value = prices.shortHalf;
    syncDocumentPriceRange();
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
    setDocumentPrintingUi(isDocumentPrinting, data);
    if (!isDocumentPrinting) syncPriceMode(fDesc.value, priceValue);
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
      setDocumentPrintingUi(isDocumentPrintingService(fCat.value, fName.value));
    }
  });

  document.addEventListener("input", (event) => {
    if (!isDocumentPrintingService(fCat.value, fName.value)) return;
    if (!event.target || ![
      "ms_long_full_price",
      "ms_long_half_price",
      "ms_short_full_price",
      "ms_short_half_price",
    ].includes(event.target.id)) return;

    syncDocumentPriceRange();
  });

  qs("#msSave")?.addEventListener("click", async ()=>{
    hideErr();

    const priceMode = getFPriceMode();
    let descriptionValue = fDesc.value.trim();
    if (isDocumentPrintingService(fCat.value, fName.value)) {
      const prices = getDocumentPriceValues();
      const invalid = Object.values(prices).some((value) => value === "" || !Number.isFinite(Number(value)));
      if (invalid) {
        showErr("Enter valid Long Bond and Short Bond prices.");
        return;
      }

      if (Number(prices.longFull) < Number(prices.longHalf) || Number(prices.shortFull) < Number(prices.shortHalf)) {
        showErr("Full prices should be greater than or equal to Half / B&W prices.");
        return;
      }

      descriptionValue = buildDocumentDescription(prices);
      syncDocumentPriceRange();
      fPrice.value = prices.shortHalf;
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
