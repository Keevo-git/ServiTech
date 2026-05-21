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
    return `₱${formatPlainPrice(low)} – ₱${formatPlainPrice(high)}`;
  }

  function ensureDocumentFullPriceField() {
    let field = qs("#ms_documentFullPriceField");
    if (field) return field;
    if (!fPriceField || !fPriceField.parentNode) return null;

    field = document.createElement("div");
    field.className = "ms-field";
    field.id = "ms_documentFullPriceField";
    field.innerHTML = `
      <label>Full Price</label>
      <input id="ms_document_full_price" type="text" placeholder="e.g., 10.00">
    `;
    fPriceField.parentNode.insertBefore(field, fPriceField);
    return field;
  }

  function getDocumentFullPriceInput() {
    ensureDocumentFullPriceField();
    return qs("#ms_document_full_price");
  }

  function getDocumentPrices(data) {
    const rangeValues = parseMoneyValues(data?.price_range || fPriceRange?.value || "");
    const description = String(data?.description || fDesc?.value || "");
    const fullFromDescription = extractOptionPrice(description, "Full");
    const halfFromDescription = extractOptionPrice(description, "Half");
    const fallbackPrice = data?.price ?? fPrice?.value ?? "";

    return {
      half: halfFromDescription || (rangeValues[0] !== undefined ? formatPlainPrice(rangeValues[0]) : fallbackPrice || "5"),
      full: fullFromDescription || (rangeValues[rangeValues.length - 1] !== undefined ? formatPlainPrice(rangeValues[rangeValues.length - 1]) : "10"),
    };
  }

  function setDocumentPrintingUi(enabled, data) {
    const fullPriceField = ensureDocumentFullPriceField();
    const fullPriceInput = getDocumentFullPriceInput();

    if (fPriceModeField) fPriceModeField.style.display = enabled ? "none" : "";
    if (fullPriceField) fullPriceField.style.display = enabled ? "" : "none";

    if (fPriceLabel) fPriceLabel.textContent = enabled ? "Half / B&W Price" : "Price (optional)";
    if (fPriceHint) {
      fPriceHint.textContent = enabled
        ? "Document Printing keeps paper/color groups fixed. Only these shared prices change."
        : "Choose Full or Half when editing print price lines inside the description.";
    }
    if (fDescriptionHint) {
      fDescriptionHint.textContent = enabled
        ? "Paper and color groups are fixed on the customer page. Edit the wording only if needed."
        : "Use newline-separated entries. For Document Printing, paper and color groups are fixed on the customer page.";
    }

    if (!enabled) return;

    const prices = getDocumentPrices(data || {});
    if (fullPriceInput) fullPriceInput.value = prices.full;
    if (fPrice) fPrice.value = prices.half;
    if (fPriceRange) fPriceRange.value = formatPesoRange(prices.half, prices.full);
  }

  function getFPriceMode() {
    return qs("#ms_priceMode");
  }

  function getFPriceModeField() {
    const el = getFPriceMode();
    return el ? el.closest(".ms-field") : null;
  }

  function ensurePriceModeField() {
    let priceMode = getFPriceMode();
    if (priceMode) return priceMode;

    const priceField = qs("#ms_price")?.closest(".ms-field");
    if (!priceField || !priceField.parentNode) return null;

    const wrapper = document.createElement("div");
    wrapper.className = "ms-field";
    wrapper.innerHTML = `
      <label>Price Mode</label>
      <select id="ms_priceMode">
        <option value="default">Default price</option>
        <option value="full">Full price</option>
        <option value="half">Half price</option>
      </select>
    `;
    priceField.parentNode.insertBefore(wrapper, priceField);
    return getFPriceMode();
  }

  function showErr(msg){ errBox.textContent = msg; errBox.style.display="block"; }

  function extractOptionPrice(description, option) {
    if (!description) return null;
    const match = description.match(new RegExp(`${option}\\s*[-–—]?\\s*₱?\\s*([0-9]+(?:\\.[0-9]+)?)`, "i"));
    return match ? match[1] : null;
  }

  function showPriceModeField(show) {
    const field = fPriceModeField || getFPriceModeField();
    if (!field) return;
    field.style.display = show ? "" : "none";
  }

  function syncPriceMode(description, defaultPrice) {
    const priceMode = ensurePriceModeField();
    if (!priceMode) return;
    const hasFull = extractOptionPrice(description, "Full");
    const hasHalf = extractOptionPrice(description, "Half");
    const usePrice = defaultPrice ?? "";

    // Show the dropdown always so the user can choose Full/Half explicitly.
    showPriceModeField(true);
    if (!["full", "half", "default"].includes(priceMode.value)) {
      priceMode.value = hasFull ? "full" : hasHalf ? "half" : "default";
    }
    setPriceForMode(priceMode.value, description, usePrice);
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

  function replaceOptionPrice(description, option, newPrice) {
    const normalizedDescription = description || "";
    const regex = new RegExp(`(${option}\s*[-–—]?\s*)₱?\s*[0-9]+(?:\.[0-9]+)?`, "i");
    if (regex.test(normalizedDescription)) {
      return normalizedDescription.replace(regex, `$1${newPrice}`);
    }

    if (!newPrice) return normalizedDescription;
    const line = `${option} - ${newPrice}`;
    if (normalizedDescription.trim() === "") {
      return line;
    }
    return normalizedDescription.trimEnd() + "\n" + line;
  }

  function hideErr(){ errBox.textContent=""; errBox.style.display="none"; }

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
    if (!isDocumentPrinting) {
      syncPriceMode(fDesc.value, priceValue);
    }
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
    if (!event.target || !["ms_price", "ms_document_full_price"].includes(event.target.id)) return;

    const fullPrice = getDocumentFullPriceInput()?.value.trim() || "";
    const halfPrice = fPrice.value.trim();
    if (fPriceRange && halfPrice !== "" && fullPrice !== "") {
      fPriceRange.value = formatPesoRange(halfPrice, fullPrice);
    }
  });

  qs("#msSave")?.addEventListener("click", async ()=>{
    hideErr();

    const priceMode = getFPriceMode();
    let descriptionValue = fDesc.value.trim();
    if (isDocumentPrintingService(fCat.value, fName.value)) {
      const fullPrice = getDocumentFullPriceInput()?.value.trim() || "";
      const halfPrice = fPrice.value.trim();

      if (!halfPrice || !fullPrice || !isFinite(Number(halfPrice)) || !isFinite(Number(fullPrice))) {
        showErr("Enter valid Full Price and Half / B&W Price.");
        return;
      }

      if (Number(fullPrice) < Number(halfPrice)) {
        showErr("Full Price should be greater than or equal to Half / B&W Price.");
        return;
      }

      if (fPriceRange) fPriceRange.value = formatPesoRange(halfPrice, fullPrice);
      fPrice.value = halfPrice;
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
