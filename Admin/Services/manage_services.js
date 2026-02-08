(function(){
  const qs = (s, el=document)=>el.querySelector(s);
  const qsa = (s, el=document)=>Array.from(el.querySelectorAll(s));

  const overlay = qs("#msOverlay");
  const modalTitle = qs("#msModalTitle");
  const errBox = qs("#msErr");

  const fId = qs("#ms_id");
  const fCat = qs("#ms_category");
  const fName = qs("#ms_name");
  const fDesc = qs("#ms_description");
  const fPrice = qs("#ms_price");
  const fActive = qs("#ms_active");
  const fSort = qs("#ms_sort");

  function showErr(msg){ errBox.textContent = msg; errBox.style.display="block"; }
  function hideErr(){ errBox.textContent=""; errBox.style.display="none"; }

  function openModal(mode, data){
    hideErr();
    overlay.style.display="flex";
    modalTitle.textContent = (mode === "edit") ? "Edit Service" : "Add Service";

    fId.value = data?.id || "";
    fCat.value = data?.category || window.MS_ACTIVE_TAB || "printing";
    fName.value = data?.name || "";
    fDesc.value = data?.description || "";
    fPrice.value = (data?.price ?? "") === null ? "" : (data?.price ?? "");
    fActive.value = (data?.active ?? 1) ? "1" : "0";
    fSort.value = data?.sort_order ?? 0;
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

      const res = await fetch("services_api.php", { method:"POST", body:fd, credentials:"same-origin" });
      const txt = await res.text();
      let out; try{ out = JSON.parse(txt); }catch(e){ alert("Non-JSON: "+txt); return; }
      if(!out.ok){ alert(out.error || "Delete failed"); return; }
      location.reload();
    });
  });

  qs("#msSave")?.addEventListener("click", async ()=>{
    hideErr();

    const fd = new FormData();
    fd.append("action","save");
    if(fId.value) fd.append("id", fId.value);
    fd.append("category", fCat.value);
    fd.append("name", fName.value.trim());
    fd.append("description", fDesc.value.trim());
    fd.append("price", fPrice.value.trim());
    fd.append("active", fActive.value);
    fd.append("sort_order", fSort.value);

    const res = await fetch("services_api.php", { method:"POST", body:fd, credentials:"same-origin" });
    const txt = await res.text();
    let out; try{ out = JSON.parse(txt); }catch(e){ showErr("Server returned non-JSON: "+txt); return; }
    if(!out.ok){ showErr(out.error || "Save failed"); return; }
    location.reload();
  });
})();
