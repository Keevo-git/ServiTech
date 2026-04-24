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
  const fPriceMode = qs("#ms_priceMode");
  const fActive = qs("#ms_active");
  const fSort = qs("#ms_sort");

  const fPriceModeField = fPriceMode?.closest(".ms-field");

  function showErr(msg){ errBox.textContent = msg; errBox.style.display="block"; }

  function extractOptionPrice(description, option) {
    if (!description) return null;
    const match = description.match(new RegExp(`${option}\\s*[-–—]?\\s*₱?\\s*([0-9]+(?:\\.[0-9]+)?)`, "i"));
    return match ? match[1] : null;
  }

  function showPriceModeField(show) {
    if (!fPriceModeField) return;
    fPriceModeField.style.display = show ? "" : "none";
  }

  function syncPriceMode(description, defaultPrice) {
    if (!fPriceMode) return;
    const hasFull = extractOptionPrice(description, "Full");
    const hasHalf = extractOptionPrice(description, "Half");
    const usePrice = defaultPrice ?? "";

    if (hasFull || hasHalf) {
      showPriceModeField(true);
      if (!["full", "half"].includes(fPriceMode.value)) {
        fPriceMode.value = hasFull ? "full" : "half";
      }
      setPriceForMode(fPriceMode.value, description, usePrice);
    } else {
      showPriceModeField(false);
      fPriceMode.value = "default";
      setPriceForMode("default", description, usePrice);
    }
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
    if (!description || !newPrice) return description;
    const regex = new RegExp(`(${option}\\s*[-–—]?\\s*)₱?\\s*[0-9]+(?:\\.[0-9]+)?`, "i");
    if (regex.test(description)) {
      return description.replace(regex, `$1${newPrice}`);
    }
    return description;
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
    fActive.value = (data?.active ?? 1) ? "1" : "0";
    fSort.value = data?.sort_order ?? 0;

    const priceValue = (data?.price ?? "") === null ? "" : (data?.price ?? "");
    fPrice.value = priceValue;
    syncPriceMode(fDesc.value, priceValue);
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

  fPriceMode?.addEventListener("change", ()=>{
    syncPriceMode(fDesc.value, fPrice.value);
  });

  qs("#msSave")?.addEventListener("click", async ()=>{
    hideErr();

    let descriptionValue = fDesc.value.trim();
    if (fPriceMode?.value === "full") {
      descriptionValue = replaceOptionPrice(descriptionValue, "Full", fPrice.value.trim());
    } else if (fPriceMode?.value === "half") {
      descriptionValue = replaceOptionPrice(descriptionValue, "Half", fPrice.value.trim());
    }

    const fd = new FormData();
    fd.append("action","save");
    if(fId.value) fd.append("id", fId.value);
    fd.append("category", fCat.value);
    fd.append("name", fName.value.trim());
    fd.append("description", descriptionValue);
    fd.append("price", fPrice.value.trim());
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
