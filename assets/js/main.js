/* ==============================
   SERVITECH MAIN.JS (DB VERSION)
   - Join Queue -> POST to PHP -> MySQL
   ============================== */

/* ==============================
   Modal utilities
   ============================== */
function anyModalVisible() {
  return Array.from(document.querySelectorAll(".modal-overlay"))
    .some((m) => getComputedStyle(m).display !== "none");
}

function syncBodyScrollLock() {
  if (anyModalVisible()) document.body.classList.add("modal-open");
  else document.body.classList.remove("modal-open");
}

function scrollToSection(id) {
  const section = document.getElementById(id);
  if (section) section.scrollIntoView({ behavior: "smooth" });
}

function servitechBasePath() {
  if (typeof window.SERVITECH_BASE_PATH === "string" && window.SERVITECH_BASE_PATH.trim() !== "") {
    return window.SERVITECH_BASE_PATH.replace(/\/+$/, "");
  }
  const pathname = window.location.pathname || "";
  if (pathname === "/ServiTech" || pathname.startsWith("/ServiTech/")) {
    return "/ServiTech";
  }
  return "";
}

function servitechUrl(path) {
  const cleanPath = path.startsWith("/") ? path : `/${path}`;
  return `${servitechBasePath()}${cleanPath}`;
}

function openModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  m.style.display = "flex";
  syncBodyScrollLock();
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  m.style.display = "none";
  syncBodyScrollLock();
}

/* ==============================
   Service list modal
   ============================== */
function openServiceModal(sectionId) {
  const section = document.getElementById(sectionId);
  if (!section) return;

  const overlay = document.getElementById("service-modal");
  const titleEl = document.getElementById("service-modal-title");
  const bodyEl = document.getElementById("service-modal-body");
  if (!overlay || !titleEl || !bodyEl) return;

  const title = section.querySelector("h3")?.textContent || "Service";
  titleEl.textContent = title;

  const grid = section.querySelector(".service-grid");
  bodyEl.innerHTML = grid ? grid.outerHTML : section.innerHTML;

  overlay.style.display = "flex";
  syncBodyScrollLock();
  document.addEventListener("keydown", escCloseServiceModal);
}

function closeServiceModal() {
  const overlay = document.getElementById("service-modal");
  const bodyEl = document.getElementById("service-modal-body");

  if (overlay) overlay.style.display = "none";
  if (bodyEl) bodyEl.innerHTML = "";

  document.removeEventListener("keydown", escCloseServiceModal);
  syncBodyScrollLock();
}

function escCloseServiceModal(e) {
  if (e.key === "Escape") closeServiceModal();
}

/* ==============================
   Generic modal close (outside click)
   ============================== */
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".modal-overlay").forEach((modal) => {
    modal.addEventListener("click", (e) => {
      if (e.target !== modal) return;

      if (modal.id === "service-modal") {
        closeServiceModal();
        return;
      }

      modal.style.display = "none";
      syncBodyScrollLock();
    });
  });
});

/* ==============================
   Summary updates
   ============================== */
document.addEventListener("DOMContentLoaded", () => {
  const qtyInput = document.getElementById("qtyInput");
  if (!qtyInput) return;

  const paperSizeSelect = document.getElementById("paperSizeSelect");
  const lamTypeSelect = document.getElementById("lamTypeSelect");
  const packageSelect = document.getElementById("packageSelect");
  const colorRadios = document.querySelectorAll('input[name="color"]');

  const summaryPaperSize = document.getElementById("summaryPaperSize");
  const summaryPackage = document.getElementById("summaryPackage");
  const summaryQty = document.getElementById("summaryQty");
  const summaryTotal = document.getElementById("summaryTotal");

  const defaultPrice = 5;
  const svc = document.body?.dataset?.service || "";
  const isXerox = svc === "xerox";

  const xeroxPriceMap = {
    "Long Bond (8.5 x 13)": 5,
    "Short Bond (8.5 x 11)": 3,
    A4: 3,
    A3: 5,
  };

  function updateSummary() {
    const qty = parseInt(qtyInput.value, 10) || 1;
    if (summaryQty) summaryQty.textContent = qty;

    if (paperSizeSelect && summaryPaperSize) {
      const size = paperSizeSelect.value;
      summaryPaperSize.textContent = size && size !== "Select paper size" ? size : "Not Selected";
    }

    if (packageSelect && summaryPackage) {
      const opt = packageSelect.options[packageSelect.selectedIndex];
      const label = opt?.textContent || "";
      summaryPackage.textContent = label && label !== "Select a Package" ? label : "Not Selected";
    }

    let pricePerItem = defaultPrice;

    if (lamTypeSelect) {
      const opt = lamTypeSelect.options[lamTypeSelect.selectedIndex];
      const p = opt?.dataset?.price ? parseFloat(opt.dataset.price) : null;
      pricePerItem = p !== null ? p : defaultPrice;
    } else if (packageSelect) {
      const opt = packageSelect.options[packageSelect.selectedIndex];
      const p = opt?.dataset?.price ? parseFloat(opt.dataset.price) : 0;
      pricePerItem = p;
    } else if (isXerox && paperSizeSelect) {
      const size = paperSizeSelect.value;
      pricePerItem = xeroxPriceMap[size] ?? 0;
    }

    if (summaryTotal) {
      summaryTotal.textContent = `\u20B1${(qty * pricePerItem).toFixed(2)}`;
    }
  }

  if (paperSizeSelect) paperSizeSelect.addEventListener("change", updateSummary);
  if (lamTypeSelect) lamTypeSelect.addEventListener("change", updateSummary);
  if (packageSelect) packageSelect.addEventListener("change", updateSummary);
  qtyInput.addEventListener("input", updateSummary);
  colorRadios.forEach((r) => r.addEventListener("change", updateSummary));

  updateSummary();
});

/* ==============================
   Join queue flow with validation + submit lock
   ============================== */
document.addEventListener("DOMContentLoaded", () => {
  const joinBtn = document.getElementById("joinQueueBtn");
  const queueModal = document.getElementById("queueModal");
  const modalQueueNo = document.getElementById("modalQueueNo");
  const goHomeBtn = document.getElementById("goHomeBtn");
  const viewQueueBtn = document.getElementById("viewQueueBtn");
  const feedbackEl = document.getElementById("formFeedback");

  if (!joinBtn || !queueModal || !modalQueueNo) return;

  const refs = {
    paperSizeSelect: document.getElementById("paperSizeSelect"),
    qtyInput: document.getElementById("qtyInput"),
    notesEl:
      document.getElementById("notes") ||
      document.getElementById("repairNotes") ||
      document.getElementById("installationNotes") ||
      null,
    packageSelect: document.getElementById("packageSelect"),
    lamTypeSelect: document.getElementById("lamTypeSelect"),
    repairServiceSelect: document.getElementById("repairServiceSelect"),
    deviceTypeSelect: document.getElementById("deviceTypeSelect"),
    installationTypeSelect: document.getElementById("installationTypeSelect"),
    fileUpload: document.getElementById("fileUpload"),
  };

  function setFieldInvalid(el, invalid) {
    if (!el) return;
    el.classList.toggle("is-invalid", !!invalid);
  }

  function setRadioInvalid(name, invalid) {
    const first = document.querySelector(`input[name="${name}"]`);
    const group = first ? first.closest(".radio-group") : null;
    if (group) group.classList.toggle("is-invalid", !!invalid);
  }

  function setFeedback(message, tone) {
    if (!feedbackEl) {
      if (message) alert(message);
      return;
    }
    feedbackEl.textContent = message || "";
    feedbackEl.classList.remove("error", "success");
    if (message) feedbackEl.classList.add(tone === "success" ? "success" : "error");
  }

  function clearValidationState() {
    [
      refs.paperSizeSelect,
      refs.qtyInput,
      refs.packageSelect,
      refs.lamTypeSelect,
      refs.repairServiceSelect,
      refs.deviceTypeSelect,
      refs.installationTypeSelect,
      refs.fileUpload,
    ].forEach((el) => setFieldInvalid(el, false));
    setRadioInvalid("color", false);
    setFeedback("", "error");
  }

  function getSelectedColor() {
    const radios = document.querySelectorAll('input[name="color"]');
    let val = "";
    radios.forEach((r) => {
      if (r.checked) val = r.value;
    });
    return val;
  }

  function buildServiceLabel() {
    let serviceLabel = "Service";
    const title = (document.title || "").toLowerCase();

    if (title.includes("document printing")) serviceLabel = "Document Printing";
    if (title.includes("xerox")) serviceLabel = "Xerox";
    if (title.includes("laminating")) serviceLabel = "Laminating";
    if (title.includes("rush id")) serviceLabel = "Rush ID";

    if (refs.repairServiceSelect) {
      const opt = refs.repairServiceSelect.options[refs.repairServiceSelect.selectedIndex];
      serviceLabel = opt ? opt.textContent : "Repair Service";
    }

    if (refs.installationTypeSelect) {
      const opt = refs.installationTypeSelect.options[refs.installationTypeSelect.selectedIndex];
      serviceLabel = opt ? opt.textContent : "Installation Service";
    }

    return serviceLabel;
  }

  function collectPayload() {
    const fileList = refs.fileUpload && refs.fileUpload.files
      ? Array.from(refs.fileUpload.files)
      : [];
    const fileName = fileList.length ? fileList[0].name : null;
    const fileNames = fileList.map((f) => f.name);
    const printState = window.servitechPrintingState || null;

    return {
      category: (document.body?.dataset?.service || "general").toLowerCase(),
      service_label: buildServiceLabel(),
      paper_size: refs.paperSizeSelect ? refs.paperSizeSelect.value : null,
      quantity: refs.qtyInput ? parseInt(refs.qtyInput.value, 10) || 1 : 1,
      color_option: getSelectedColor(),
      package_label: refs.packageSelect
        ? (refs.packageSelect.options[refs.packageSelect.selectedIndex]?.textContent || null)
        : null,
      lamination_type: refs.lamTypeSelect ? refs.lamTypeSelect.value : null,
      device_type: refs.deviceTypeSelect ? refs.deviceTypeSelect.value : null,
      notes: refs.notesEl ? refs.notesEl.value : null,
      file_name: fileName,
      file_names: fileNames.length ? fileNames : null,
      total_files: printState && Number.isFinite(printState.total_files)
        ? Number(printState.total_files)
        : null,
      total_images: printState && Number.isFinite(printState.total_images)
        ? Number(printState.total_images)
        : null,
      total_pages: printState && Number.isFinite(printState.total_pages)
        ? Number(printState.total_pages)
        : null,
      price_per_page: printState && Number.isFinite(printState.price_per_page)
        ? Number(printState.price_per_page)
        : null,
      estimated_total: printState && Number.isFinite(printState.estimated_total)
        ? Number(printState.estimated_total)
        : null,
      file_analysis: printState && Array.isArray(printState.files)
        ? printState.files
        : null,
    };
  }

  function validatePayload(payload) {
    const errors = [];

    if (!payload.service_label || payload.service_label === "Service") {
      errors.push("Please complete the service selection first.");
    }

    if (refs.paperSizeSelect && !payload.paper_size) {
      errors.push("Select paper size.");
      setFieldInvalid(refs.paperSizeSelect, true);
    }

    if (refs.qtyInput && (!Number.isFinite(payload.quantity) || payload.quantity < 1)) {
      errors.push("Quantity must be at least 1.");
      setFieldInvalid(refs.qtyInput, true);
    }

    if (refs.packageSelect && !refs.packageSelect.value) {
      errors.push("Select a package.");
      setFieldInvalid(refs.packageSelect, true);
    }

    if (refs.lamTypeSelect && !refs.lamTypeSelect.value) {
      errors.push("Select lamination type.");
      setFieldInvalid(refs.lamTypeSelect, true);
    }

    if (refs.repairServiceSelect && !refs.repairServiceSelect.value) {
      errors.push("Select repair service.");
      setFieldInvalid(refs.repairServiceSelect, true);
    }

    if (refs.deviceTypeSelect && !refs.deviceTypeSelect.value) {
      errors.push("Select device type.");
      setFieldInvalid(refs.deviceTypeSelect, true);
    }

    if (refs.installationTypeSelect && !refs.installationTypeSelect.value) {
      errors.push("Select installation type.");
      setFieldInvalid(refs.installationTypeSelect, true);
    }

    const hasColorOptions = document.querySelectorAll('input[name="color"]').length > 0;
    if (hasColorOptions && !payload.color_option) {
      errors.push("Select a color option.");
      setRadioInvalid("color", true);
    }


    const isPrinting = payload.category === "printing";
    if (isPrinting && refs.fileUpload) {
      const hasFiles = !!(refs.fileUpload.files && refs.fileUpload.files.length);
      const printState = window.servitechPrintingState || null;

      if (!hasFiles) {
        errors.push("Upload at least one file.");
        setFieldInvalid(refs.fileUpload, true);
      }

      if (payload.paper_size === "A3") {
        errors.push("Not Available: A3 printing is not available.");
        setFieldInvalid(refs.paperSizeSelect, true);
      }

      if (printState && printState.error) {
        errors.push(printState.error);
      }

      if (hasFiles && (!printState || !Number.isFinite(printState.total_pages) || printState.total_pages < 1)) {
        errors.push("Unable to compute total pages. Re-upload files and try again.");
        setFieldInvalid(refs.fileUpload, true);
      }
    }

    return errors;
  }

  async function createQueue(payload) {
    const csrf = (window.servitechCsrfToken && window.servitechCsrfToken()) || "";
    const res = await fetch(servitechUrl("/api/queue_create.php"), {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-Token": csrf,
      },
      body: JSON.stringify(payload),
    });

    const raw = await res.text();
    try {
      return JSON.parse(raw);
    } catch (e) {
      return {
        ok: false,
        error:
          "Server returned non-JSON. It may have redirected to login (session issue) or PHP error.",
      };
    }
  }

  joinBtn.addEventListener("click", async (e) => {
    e.preventDefault();
    if (joinBtn.disabled) return;

    clearValidationState();
    const payload = collectPayload();
    const errors = validatePayload(payload);

    if (errors.length) {
      setFeedback(errors.join(" "), "error");
      return;
    }

    const originalLabel = joinBtn.textContent;
    joinBtn.disabled = true;
    joinBtn.textContent = "Joining Queue...";
    joinBtn.setAttribute("aria-busy", "true");
    setFeedback("Submitting your queue request...", "success");

    try {
      const result = await createQueue(payload);
      if (!result.ok) {
        setFeedback("Queue not saved: " + (result.error || "Unknown error"), "error");
        return;
      }

      modalQueueNo.textContent = result.queue_code;
      queueModal.style.display = "flex";
      syncBodyScrollLock();
      setFeedback("", "error");
    } catch (err) {
      console.error(err);
      setFeedback("Network/server error. Please try again.", "error");
    } finally {
      joinBtn.disabled = false;
      joinBtn.textContent = originalLabel;
      joinBtn.removeAttribute("aria-busy");
    }
  });

  if (goHomeBtn) {
    goHomeBtn.addEventListener("click", () => {
      window.location.href = servitechUrl("/pages/customer/customer_dash.php");
    });
  }

  if (viewQueueBtn) {
    viewQueueBtn.addEventListener("click", () => {
      window.location.href = servitechUrl("/pages/customer/custo_service_status.php");
    });
  }
});
