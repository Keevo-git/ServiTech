/* ==============================
   SERVITECH MAIN.JS (DB VERSION)
   - No localStorage queues
   - Join Queue -> POST to PHP -> MySQL
   ============================== */

function scrollToSection(id) {
  const section = document.getElementById(id);
  if (section) section.scrollIntoView({ behavior: "smooth" });
}

/* ==============================
   GENERIC MODAL CLOSE (click outside)
   ============================== */
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".modal-overlay").forEach((modal) => {
    modal.addEventListener("click", (e) => {
      if (e.target === modal) modal.style.display = "none";
    });
  });
});

/* ==============================
   SUMMARY UPDATES
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
    "A4": 3,
    "A3": 5
  };

  function updateSummary() {
    const qty = parseInt(qtyInput.value, 10) || 1;
    if (summaryQty) summaryQty.textContent = qty;

    if (paperSizeSelect && summaryPaperSize) {
      const size = paperSizeSelect.value;
      summaryPaperSize.textContent =
        size && size !== "Select paper size" ? size : "Not Selected";
    }

    if (packageSelect && summaryPackage) {
      const opt = packageSelect.options[packageSelect.selectedIndex];
      const label = opt?.textContent || "";
      summaryPackage.textContent =
        label && label !== "Select a Package" ? label : "Not Selected";
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
      summaryTotal.textContent = `₱${(qty * pricePerItem).toFixed(2)}`;
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
   JOIN QUEUE -> DATABASE (with debugging)
   ============================== */
document.addEventListener("DOMContentLoaded", () => {
  const joinBtn = document.getElementById("joinQueueBtn");
  const queueModal = document.getElementById("queueModal");
  const modalQueueNo = document.getElementById("modalQueueNo");
  const goHomeBtn = document.getElementById("goHomeBtn");
  const viewQueueBtn = document.getElementById("viewQueueBtn");

  console.log("[JoinQueue] loaded:", {
    joinBtn: !!joinBtn,
    queueModal: !!queueModal,
    modalQueueNo: !!modalQueueNo
  });

  if (!joinBtn || !queueModal || !modalQueueNo) return;

  function getSelectedColor() {
    const radios = document.querySelectorAll('input[name="color"]');
    let val = null;
    radios.forEach((r) => { if (r.checked) val = r.value; });
    return val;
  }

  function collectPayload() {
    const category = (document.body?.dataset?.service || "general").toLowerCase();

    const paperSizeSelect = document.getElementById("paperSizeSelect");
    const qtyInput = document.getElementById("qtyInput");

    const notesEl =
      document.getElementById("notes") ||
      document.getElementById("repairNotes") ||
      document.getElementById("installationNotes") ||
      null;

    const packageSelect = document.getElementById("packageSelect");
    const lamTypeSelect = document.getElementById("lamTypeSelect");
    const repairServiceSelect = document.getElementById("repairServiceSelect");
    const deviceTypeSelect = document.getElementById("deviceTypeSelect");
    const installationTypeSelect = document.getElementById("installationTypeSelect");

    const fileUpload = document.getElementById("fileUpload");
    const fileName = (fileUpload && fileUpload.files && fileUpload.files[0])
      ? fileUpload.files[0].name
      : null;

    // Default label
    let service_label = "Service";

    const title = (document.title || "").toLowerCase();

    if (title.includes("document printing")) service_label = "Document Printing";
    if (title.includes("xerox")) service_label = "Xerox";
    if (title.includes("laminating")) service_label = "Laminating";
    if (title.includes("rush id")) service_label = "Rush ID";

    // Repair
    if (repairServiceSelect) {
      const opt = repairServiceSelect.options[repairServiceSelect.selectedIndex];
      service_label = opt ? opt.textContent : "Repair Service";
    }

    // Installation
    if (installationTypeSelect) {
      const opt = installationTypeSelect.options[installationTypeSelect.selectedIndex];
      service_label = opt ? opt.textContent : "Installation Service";
    }

    return {
      category,
      service_label,
      paper_size: paperSizeSelect ? paperSizeSelect.value : null,
      quantity: qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1,
      color_option: getSelectedColor(),
      package_label: packageSelect
        ? (packageSelect.options[packageSelect.selectedIndex]?.textContent || null)
        : null,
      lamination_type: lamTypeSelect ? lamTypeSelect.value : null,
      device_type: deviceTypeSelect ? deviceTypeSelect.value : null,
      notes: notesEl ? notesEl.value : null,
      file_name: fileName
    };
  }

  async function createQueue(payload) {
    const res = await fetch("queue_create.php", {
  method: "POST",
  credentials: "same-origin",
  headers: {
    "Content-Type": "application/json",
    "Accept": "application/json",
    "X-Requested-With": "XMLHttpRequest"
  },
  body: JSON.stringify(payload)
});


    const raw = await res.text();

    console.log("[JoinQueue] HTTP Status:", res.status);
    console.log("[JoinQueue] Raw Response:", raw);

    try {
      return JSON.parse(raw);
    } catch (e) {
      return {
        ok: false,
        error:
          "Server returned non-JSON. It may have redirected to log_in.html (session issue) or PHP error. Check console raw response."
      };
    }
  }

  joinBtn.addEventListener("click", async (e) => {
    e.preventDefault();
    console.log("[JoinQueue] click");

    const payload = collectPayload();
    console.log("[JoinQueue] payload:", payload);

    if (!payload.service_label || payload.service_label === "Service") {
      alert("Please complete the form first.");
      return;
    }

    try {
      const result = await createQueue(payload);
      console.log("[JoinQueue] result:", result);

      if (!result.ok) {
        alert("Queue not saved: " + (result.error || "Unknown error"));
        return;
      }

      modalQueueNo.textContent = result.queue_code;
      queueModal.style.display = "flex";
    } catch (err) {
      console.error(err);
      alert("Network/server error. Check XAMPP Apache + PHP error logs.");
    }
  });

  if (goHomeBtn) {
  goHomeBtn.addEventListener("click", () => {
    window.location.href = "customer_dash.php";
  });
}


  if (viewQueueBtn) {
    viewQueueBtn.addEventListener("click", () => {
      window.location.href = "custo_service_status.php";
    });
  }
});
