(function () {
  function servitechBasePath() {
    if (typeof window.SERVITECH_BASE_PATH === "string" && window.SERVITECH_BASE_PATH.trim() !== "") {
      return window.SERVITECH_BASE_PATH.replace(/\/+$/, "");
    }
    var pathname = window.location.pathname || "";
    if (pathname === "/ServiTech" || pathname.indexOf("/ServiTech/") === 0) {
      return "/ServiTech";
    }
    return "";
  }

  function servitechUrl(path) {
    var cleanPath = path.charAt(0) === "/" ? path : "/" + path;
    return servitechBasePath() + cleanPath;
  }

  function toPeso(value) {
    var n = Number(value);
    if (!Number.isFinite(n)) n = 0;
    return "\u20B1" + n.toFixed(2);
  }

  function debounce(fn, wait) {
    var timer = null;
    return function () {
      clearTimeout(timer);
      timer = setTimeout(fn, wait);
    };
  }

  document.addEventListener("DOMContentLoaded", function () {
    var body = document.body;
    if (!body || body.dataset.service !== "printing") return;

    var fileUpload = document.getElementById("fileUpload");
    var fileListEl = document.getElementById("fileAnalysisList");
    var fileMetaEl = document.getElementById("fileAnalysisMeta");
    var feedbackEl = document.getElementById("formFeedback");
    var qtyInput = document.getElementById("qtyInput");
    var paperSizeSelect = document.getElementById("paperSizeSelect");
    var orderTypeSelect = document.getElementById("orderTypeSelect");
    var paymentSection = document.getElementById("paymentSection");
    var paymentMethodSelect = document.getElementById("paymentMethodSelect");
    var cashPaymentNote = document.getElementById("cashPaymentNote");
    var joinQueueBtn = document.getElementById("joinQueueBtn");
    var summaryPaperSize = document.getElementById("summaryPaperSize");
    var summaryQty = document.getElementById("summaryQty");
    var summaryTotalPages = document.getElementById("summaryTotalPages");
    var summaryPricePerPage = document.getElementById("summaryPricePerPage");
    var summaryTotal = document.getElementById("summaryTotal");
    var queueModal = document.getElementById("queueModal");
    var modalQueueNo = document.getElementById("modalQueueNo");

    if (!fileUpload || !fileListEl || !fileMetaEl || !qtyInput || !paperSizeSelect || !orderTypeSelect || !paymentMethodSelect || !joinQueueBtn) {
      return;
    }

    var ALLOWED_EXT = {
      pdf: true,
      doc: true,
      docx: true,
      ppt: true,
      pptx: true,
      jpg: true,
      jpeg: true,
      png: true,
    };
    var MAX_FILE_SIZE = 20 * 1024 * 1024;

    var selectedFiles = [];
    var uploadedSignature = "";
    var isSubmitting = false;

    var state = {
      files: [],
      uploaded_files: [],
      total_files: 0,
      total_images: 0,
      total_pages: 0,
      price_per_page: 0,
      estimated_total: 0,
      error: "",
    };

    window.servitechPrintingState = state;

    function getExt(filename) {
      var dot = filename.lastIndexOf(".");
      if (dot < 0) return "";
      return filename.slice(dot + 1).toLowerCase();
    }

    function getSelectedColor() {
      var checked = document.querySelector('input[name="color"]:checked');
      return checked ? checked.value : "";
    }

    function getQuantity() {
      var qty = parseInt(qtyInput.value, 10);
      if (!Number.isFinite(qty) || qty < 1) return 1;
      return qty;
    }

    function getOrderType() {
      return (orderTypeSelect.value || "").trim().toLowerCase();
    }

    function getPaymentMethod() {
      return getOrderType() === "online"
        ? (paymentMethodSelect.value || "").trim().toLowerCase()
        : "";
    }

    function fileKey(file) {
      return [
        (file.name || "").toLowerCase(),
        String(file.size || 0),
        String(file.lastModified || 0),
      ].join("|");
    }

    function currentSignature() {
      return selectedFiles.map(fileKey).sort().join("::");
    }

    function setFeedback(message, tone) {
      if (!feedbackEl) return;
      feedbackEl.textContent = message || "";
      feedbackEl.classList.remove("error", "success");
      if (message) {
        feedbackEl.classList.add(tone === "success" ? "success" : "error");
      }
    }

    function setFieldInvalid(el, invalid) {
      if (!el) return;
      el.classList.toggle("is-invalid", !!invalid);
    }

    function setRadioInvalid(name, invalid) {
      var first = document.querySelector('input[name="' + name + '"]');
      var group = first ? first.closest(".radio-group") : null;
      if (group) group.classList.toggle("is-invalid", !!invalid);
    }

    function clearValidationState() {
      setFieldInvalid(orderTypeSelect, false);
      setFieldInvalid(paymentMethodSelect, false);
      setFieldInvalid(paperSizeSelect, false);
      setFieldInvalid(qtyInput, false);
      setFieldInvalid(fileUpload, false);
      setRadioInvalid("color", false);
      setFeedback("", "error");
    }

    function setProcessingState(processing, label) {
      isSubmitting = processing;
      joinQueueBtn.disabled = !!processing;
      if (processing) {
        joinQueueBtn.dataset.originalLabel = joinQueueBtn.dataset.originalLabel || joinQueueBtn.textContent;
        joinQueueBtn.textContent = label || "Processing...";
        joinQueueBtn.setAttribute("aria-busy", "true");
        return;
      }

      joinQueueBtn.textContent = joinQueueBtn.dataset.originalLabel || "Join Queue";
      joinQueueBtn.removeAttribute("aria-busy");
    }

    function syncFileInput() {
      var dt = new DataTransfer();
      selectedFiles.forEach(function (f) {
        dt.items.add(f);
      });
      fileUpload.files = dt.files;
    }

    function renderSummary() {
      var size = paperSizeSelect.value || "";
      if (summaryPaperSize) {
        summaryPaperSize.textContent = size ? size : "Not Selected";
      }
      if (summaryQty) summaryQty.textContent = String(getQuantity());
      if (summaryTotalPages) summaryTotalPages.textContent = String(state.total_pages || 0);
      if (summaryPricePerPage) summaryPricePerPage.textContent = toPeso(state.price_per_page || 0);
      if (summaryTotal) summaryTotal.textContent = toPeso(state.estimated_total || 0);
    }

    function renderList() {
      fileListEl.innerHTML = "";

      if (!selectedFiles.length) {
        fileMetaEl.textContent = "No files uploaded yet.";
        return;
      }

      state.files.forEach(function (fileInfo, index) {
        var sourceFile = selectedFiles[index];
        var li = document.createElement("li");
        var ext = (fileInfo.file_type || (sourceFile ? getExt(sourceFile.name) : "")).toUpperCase() || "FILE";
        var label = fileInfo.file_name || (sourceFile ? sourceFile.name : "File");
        var countLabel = "";

        if (typeof fileInfo.slide_count !== "undefined") {
          countLabel = " - " + fileInfo.slide_count + " slide(s)";
        } else if (typeof fileInfo.page_count !== "undefined") {
          countLabel = " - " + fileInfo.page_count + " page(s)";
        }

        var info = document.createElement("span");
        info.textContent = label + " (" + ext + ")" + countLabel + " ";

        var removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.textContent = "Remove";
        removeBtn.setAttribute("aria-label", "Remove " + label);
        removeBtn.dataset.fileKey = sourceFile ? fileKey(sourceFile) : label + "|" + index;

        li.appendChild(info);
        li.appendChild(removeBtn);
        fileListEl.appendChild(li);
      });

      fileMetaEl.textContent =
        selectedFiles.length + (selectedFiles.length === 1 ? " file selected" : " files selected") +
        " | Total Pages: " + state.total_pages;
    }

    function resetAnalysis(keepFeedback) {
      state.files = selectedFiles.map(function (file) {
        return {
          file_name: file.name,
          file_type: getExt(file.name),
        };
      });
      state.total_files = selectedFiles.length;
      state.total_images = 0;
      state.total_pages = 0;
      state.price_per_page = 0;
      state.estimated_total = 0;
      if (!keepFeedback) {
        state.error = "";
        setFeedback("", "error");
      }
      if (!selectedFiles.length) {
        state.uploaded_files = [];
      }
      renderList();
      renderSummary();
    }

    async function analyzeSelectedFiles() {
      resetAnalysis(true);

      if (!selectedFiles.length) {
        state.error = "";
        setFeedback("", "error");
        return;
      }

      var formData = new FormData();
      formData.append("paper_size", paperSizeSelect.value || "");
      formData.append("color_option", getSelectedColor());
      formData.append("quantity", String(getQuantity()));
      selectedFiles.forEach(function (file) {
        formData.append("files[]", file, file.name);
      });

      var csrf = (window.servitechCsrfToken && window.servitechCsrfToken()) || "";

      try {
        var res = await fetch(servitechUrl("/api/printing_analyze.php"), {
          method: "POST",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-Token": csrf,
          },
          body: formData,
        });

        var raw = await res.text();
        var data = null;
        try {
          data = JSON.parse(raw);
        } catch (parseErr) {
          data = { ok: false, error: "Server returned invalid response." };
        }

        if (!data.ok) {
          state.error = data.error || "Unable to analyze files.";
          state.files = Array.isArray(data.files) && data.files.length ? data.files : state.files;
          state.total_files = Number.isFinite(Number(data.total_files)) ? Number(data.total_files) : selectedFiles.length;
          state.total_images = Number.isFinite(Number(data.total_images)) ? Number(data.total_images) : 0;
          state.total_pages = Number.isFinite(Number(data.total_pages)) ? Number(data.total_pages) : 0;
          state.price_per_page = 0;
          state.estimated_total = 0;
          renderList();
          renderSummary();
          setFeedback(state.error, "error");
          return;
        }

        state.error = "";
        state.files = Array.isArray(data.files) ? data.files : state.files;
        state.total_files = Number(data.total_files) || selectedFiles.length;
        state.total_images = Number(data.total_images) || 0;
        state.total_pages = Number(data.total_pages) || 0;
        state.price_per_page = Number(data.price_per_page) || 0;
        state.estimated_total = Number(data.estimated_total) || 0;
        renderList();
        renderSummary();
        setFeedback("", "error");
      } catch (err) {
        state.error = "Network/server error while analyzing files.";
        state.price_per_page = 0;
        state.estimated_total = 0;
        renderSummary();
        setFeedback(state.error, "error");
      }
    }

    function addFiles(incoming) {
      var errors = [];
      var existing = {};
      selectedFiles.forEach(function (f) {
        existing[fileKey(f)] = true;
      });

      Array.from(incoming || []).forEach(function (file) {
        var ext = getExt(file.name);
        if (!ALLOWED_EXT[ext]) {
          errors.push(file.name + " has unsupported file type.");
          return;
        }

        if ((file.size || 0) > MAX_FILE_SIZE) {
          errors.push(file.name + " exceeds 20MB limit.");
          return;
        }

        var key = fileKey(file);
        if (existing[key]) {
          errors.push(file.name + " is already selected.");
          return;
        }

        existing[key] = true;
        selectedFiles.push(file);
      });

      uploadedSignature = "";
      state.uploaded_files = [];
      syncFileInput();

      if (errors.length) {
        state.error = errors.join(" ");
        setFeedback(state.error, "error");
      } else {
        state.error = "";
        setFeedback("", "error");
      }

      resetAnalysis(true);
      analyzeSelectedFiles();
    }

    function removeSelectedByKey(key) {
      selectedFiles = selectedFiles.filter(function (f) {
        return fileKey(f) !== key;
      });

      uploadedSignature = "";
      state.uploaded_files = [];
      state.error = "";
      setFeedback("", "error");
      syncFileInput();
      resetAnalysis(true);
      analyzeSelectedFiles();
    }

    async function uploadSelectedFiles() {
      if (!selectedFiles.length) {
        state.error = "Upload at least one file.";
        setFeedback(state.error, "error");
        return { ok: false, error: state.error };
      }

      if (state.error) {
        setFeedback(state.error, "error");
        return { ok: false, error: state.error };
      }

      var sig = currentSignature();
      if (sig !== "" && sig === uploadedSignature && state.uploaded_files.length) {
        return {
          ok: true,
          payload: {
            uploaded_files: state.uploaded_files,
            file_name: selectedFiles[0] ? selectedFiles[0].name : null,
            file_names: selectedFiles.map(function (f) { return f.name; }),
            total_files: state.total_files,
            total_images: state.total_images,
            total_pages: state.total_pages,
            price_per_page: state.price_per_page,
            estimated_total: state.estimated_total,
            file_analysis: state.files,
          },
        };
      }

      var fd = new FormData();
      selectedFiles.forEach(function (file) {
        fd.append("files[]", file, file.name);
      });

      var csrf = (window.servitechCsrfToken && window.servitechCsrfToken()) || "";
      var res = await fetch(servitechUrl("/api/upload_handler.php"), {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-Token": csrf,
        },
        body: fd,
      });

      var raw = await res.text();
      var data = null;
      try {
        data = JSON.parse(raw);
      } catch (err) {
        data = { success: false, message: "Server returned invalid response." };
      }

      if (!data.success) {
        var errMsg = data.message || "File upload failed.";
        if (Array.isArray(data.errors) && data.errors.length) {
          errMsg += " " + data.errors.join(" ");
        }
        state.error = errMsg;
        setFeedback(errMsg, "error");
        return { ok: false, error: errMsg };
      }

      state.error = "";
      state.uploaded_files = Array.isArray(data.uploaded_files) ? data.uploaded_files : [];
      uploadedSignature = sig;

      return {
        ok: true,
        payload: {
          uploaded_files: state.uploaded_files,
          file_name: selectedFiles[0] ? selectedFiles[0].name : null,
          file_names: selectedFiles.map(function (f) { return f.name; }),
          total_files: state.total_files,
          total_images: state.total_images,
          total_pages: state.total_pages,
          price_per_page: state.price_per_page,
          estimated_total: state.estimated_total,
          file_analysis: state.files,
        },
      };
    }

    async function cleanupUploadedFiles(uploadedFiles) {
      if (!Array.isArray(uploadedFiles) || uploadedFiles.length === 0) return;

      var csrf = (window.servitechCsrfToken && window.servitechCsrfToken()) || "";

      try {
        await fetch(servitechUrl("/api/upload_cleanup.php"), {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-Token": csrf,
          },
          body: JSON.stringify({ uploaded_files: uploadedFiles }),
        });
      } catch (err) {
        console.error("upload cleanup failed", err);
      } finally {
        uploadedSignature = "";
        state.uploaded_files = [];
      }
    }

    function updateOrderTypeUi() {
      var online = getOrderType() === "online";
      paymentSection.hidden = !online;
      if (!online) {
        paymentMethodSelect.value = "";
      }
      cashPaymentNote.hidden = !(online && getPaymentMethod() === "cash");
      setFieldInvalid(orderTypeSelect, false);
      setFieldInvalid(paymentMethodSelect, false);
    }

    function buildPayload() {
      return {
        category: "printing",
        service_label: "Document Printing",
        order_type: getOrderType(),
        paper_size: paperSizeSelect.value || null,
        quantity: getQuantity(),
        color_option: getSelectedColor(),
        payment_method: getPaymentMethod() || null,
        notes: document.getElementById("notes") ? document.getElementById("notes").value.trim() : null,
        file_name: selectedFiles[0] ? selectedFiles[0].name : null,
        file_names: selectedFiles.length ? selectedFiles.map(function (file) { return file.name; }) : null,
        total_files: Number(state.total_files) || 0,
        total_images: Number(state.total_images) || 0,
        total_pages: Number(state.total_pages) || 0,
        price_per_page: Number(state.price_per_page) || 0,
        estimated_total: Number(state.estimated_total) || 0,
        file_analysis: Array.isArray(state.files) ? state.files : [],
        uploaded_files: Array.isArray(state.uploaded_files) ? state.uploaded_files : [],
      };
    }

    function validatePayload(payload) {
      var errors = [];

      if (!payload.order_type) {
        errors.push("Select an order type.");
        setFieldInvalid(orderTypeSelect, true);
      }

      if (!payload.paper_size) {
        errors.push("Select paper size.");
        setFieldInvalid(paperSizeSelect, true);
      }

      if (!Number.isFinite(payload.quantity) || payload.quantity < 1) {
        errors.push("Quantity must be at least 1.");
        setFieldInvalid(qtyInput, true);
      }

      if (!payload.color_option) {
        errors.push("Select a color option.");
        setRadioInvalid("color", true);
      }

      if (!selectedFiles.length) {
        errors.push("Upload at least one file.");
        setFieldInvalid(fileUpload, true);
      }

      if (payload.paper_size === "A3") {
        errors.push("Not Available: A3 printing is not available.");
        setFieldInvalid(paperSizeSelect, true);
      }

      if (payload.order_type === "online" && !payload.payment_method) {
        errors.push("Select a payment method for online orders.");
        setFieldInvalid(paymentMethodSelect, true);
      }

      if (state.error) {
        errors.push(state.error);
      }

      return errors;
    }

    async function createQueue(payload) {
      var csrf = (window.servitechCsrfToken && window.servitechCsrfToken()) || "";
      var res = await fetch(servitechUrl("/api/queue_create.php"), {
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

      var raw = await res.text();
      try {
        return JSON.parse(raw);
      } catch (err) {
        return {
          ok: false,
          error: "Server returned invalid response.",
        };
      }
    }

    async function saveOnlineDraft(payload) {
      var csrf = (window.servitechCsrfToken && window.servitechCsrfToken()) || "";
      var res = await fetch(servitechUrl("/api/print_order_draft.php"), {
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

      var raw = await res.text();
      try {
        return JSON.parse(raw);
      } catch (err) {
        return {
          ok: false,
          error: "Server returned invalid response.",
        };
      }
    }

    function openSuccessModal(queueCode) {
      if (!queueModal || !modalQueueNo) return;
      modalQueueNo.textContent = queueCode;
      queueModal.style.display = "flex";
      document.body.classList.add("modal-open");
    }

    async function handleJoinQueue(event) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (isSubmitting) {
        return;
      }

      clearValidationState();
      updateOrderTypeUi();

      var payload = buildPayload();
      var errors = validatePayload(payload);
      if (errors.length) {
        setFeedback(errors.join(" "), "error");
        return;
      }

      setProcessingState(true, payload.order_type === "online" ? "Preparing Order..." : "Joining Queue...");
      setFeedback(payload.order_type === "online" ? "Saving your print order draft..." : "Submitting your queue request...", "success");

      try {
        var uploadResult = await uploadSelectedFiles();
        if (!uploadResult || uploadResult.ok === false) {
          setFeedback(uploadResult && uploadResult.error ? uploadResult.error : "File upload failed.", "error");
          return;
        }

        if (uploadResult.payload && typeof uploadResult.payload === "object") {
          for (var key in uploadResult.payload) {
            payload[key] = uploadResult.payload[key];
          }
        }

        if (payload.order_type === "online") {
          var draftResult = await saveOnlineDraft(payload);
          if (!draftResult.ok) {
            await cleanupUploadedFiles(payload.uploaded_files);
            setFeedback(draftResult.error || "Unable to continue to payment.", "error");
            return;
          }

          window.location.href = servitechUrl("/pages/customer/custo_print_order_payment.php");
          return;
        }

        var result = await createQueue(payload);
        if (!result.ok) {
          await cleanupUploadedFiles(payload.uploaded_files);
          setFeedback("Queue not saved: " + (result.error || "Unknown error"), "error");
          return;
        }

        setFeedback("", "error");
        openSuccessModal(result.queue_code);
      } catch (err) {
        console.error(err);
        await cleanupUploadedFiles(payload.uploaded_files);
        setFeedback("Network/server error. Please try again.", "error");
      } finally {
        setProcessingState(false);
      }
    }

    var debouncedAnalyze = debounce(analyzeSelectedFiles, 220);

    fileUpload.addEventListener("change", function (e) {
      addFiles(e.target.files);
      fileUpload.value = "";
    });

    fileListEl.addEventListener("click", function (e) {
      var target = e.target;
      if (!target || target.tagName !== "BUTTON") return;
      var key = target.dataset.fileKey || "";
      if (!key) return;
      removeSelectedByKey(key);
    });

    qtyInput.addEventListener("input", debouncedAnalyze);
    paperSizeSelect.addEventListener("change", debouncedAnalyze);
    orderTypeSelect.addEventListener("change", updateOrderTypeUi);
    paymentMethodSelect.addEventListener("change", updateOrderTypeUi);
    document.querySelectorAll('input[name="color"]').forEach(function (radio) {
      radio.addEventListener("change", debouncedAnalyze);
    });

    joinQueueBtn.addEventListener("click", handleJoinQueue, true);

    resetAnalysis(true);
    renderSummary();
    updateOrderTypeUi();
  });
})();
