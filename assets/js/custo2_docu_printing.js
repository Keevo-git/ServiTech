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

    var draftState = window.servitechPrintOrderDraft && typeof window.servitechPrintOrderDraft === "object"
      ? window.servitechPrintOrderDraft
      : null;
    var dynamicPricing = window.servitechDocumentPrintPricing && typeof window.servitechDocumentPrintPricing === "object"
      ? window.servitechDocumentPrintPricing
      : {};

    var fileUpload = document.getElementById("fileUpload");
    var fileListEl = document.getElementById("fileAnalysisList");
    var fileMetaEl = document.getElementById("fileAnalysisMeta");
    var fileUploadStatus = document.getElementById("fileUploadStatus");
    var qtyInput = document.getElementById("qtyInput");
    var paperSizeSelect = document.getElementById("paperSizeSelect");
    var orderTypeSelect = document.getElementById("orderTypeSelect");
    var paymentSection = document.getElementById("paymentSection");
    var paymentMethodSelect = document.getElementById("paymentMethodSelect");
    var cashPaymentNote = document.getElementById("cashPaymentNote");
    var joinQueueBtn = document.getElementById("joinQueueBtn");
    var notesInput = document.getElementById("notes");
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
    var analysisRequestSeq = 0;
    var isSubmitting = false;

    var state = {
      files: [],
      file_names: [],
      uploaded_files: [],
      total_files: 0,
      total_images: 0,
      total_pages: 0,
      price_per_page: 0,
      estimated_total: 0,
      error: ""
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

    function getClientPricePerPage() {
      var paperSize = (paperSizeSelect.value || "").trim().toLowerCase();
      var colorOption = getSelectedColor().trim().toLowerCase();

      if (!paperSize) {
        return 0;
      }

      var isLong = paperSize.indexOf("long bond") !== -1 || paperSize.indexOf("8.5 x 13") !== -1;
      var isA4 = paperSize === "a4";
      var isA3 = paperSize === "a3";
      var prefix = isLong ? "long" : (isA4 ? "a4" : (isA3 ? "a3" : "short"));
      var fullPrice = Number(dynamicPricing[prefix + "_full_price"]);
      var halfPrice = Number(dynamicPricing[prefix + "_half_price"]);

      if (!Number.isFinite(halfPrice) || halfPrice <= 0) halfPrice = 5;
      if (!Number.isFinite(fullPrice) || fullPrice <= 0) fullPrice = Math.max(halfPrice, 10);

      if (colorOption === "colored full") return fullPrice;
      if (colorOption === "colored half") return halfPrice;
      return halfPrice;
    }

    function syncClientPricing() {
      var pricePerPage = getClientPricePerPage();
      if (pricePerPage <= 0) {
        state.price_per_page = 0;
        state.estimated_total = 0;
        return;
      }

      state.price_per_page = pricePerPage;
      state.estimated_total = Math.max(1, Number(state.total_pages) || 0) * pricePerPage * getQuantity();
    }

    function getQuantity() {
      var qty = parseInt(qtyInput.value, 10);
      if (!Number.isFinite(qty) || qty < 1) return 1;
      return qty;
    }

    function getEnteredQuantity() {
      var raw = (qtyInput.value || "").trim();
      if (raw === "") return NaN;
      return parseInt(raw, 10);
    }

    function getOrderType() {
      return (orderTypeSelect.value || "").trim().toLowerCase();
    }

    function getPaymentMethod() {
      return getOrderType() === "online"
        ? (paymentMethodSelect.value || "").trim().toLowerCase()
        : "";
    }

    // Keep the client payload aligned with the PHP queue rules:
    // P**** => printing, OP**** => online_printorder.
    function getQueueCategoryFromOrderType(orderType) {
      if (orderType === "online") return "online_printorder";
      if (orderType === "walkin") return "printing";
      return "";
    }

    function getCategoryFromQueueCode(code) {
      var normalized = (code || "").trim().toUpperCase();
      if (normalized.indexOf("OP") === 0) return "online_printorder";
      if (normalized.indexOf("P") === 0) return "printing";
      return "";
    }

    function getServiceLabelFromOrderType(orderType) {
      return orderType === "online" ? "Online Print Order" : "Document Printing";
    }

    function fileKey(file) {
      return [
        (file.name || "").toLowerCase(),
        String(file.size || 0),
        String(file.lastModified || 0),
      ].join("|");
    }

    function arrayBufferToBinaryString(buffer) {
      var bytes = new Uint8Array(buffer);
      var chunks = [];
      var chunkSize = 0x8000;
      for (var i = 0; i < bytes.length; i += chunkSize) {
        chunks.push(String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize)));
      }
      return chunks.join("");
    }

    function hasPdfEncryptionMarker(buffer) {
      return /\/Encrypt\s+(?:\d+\s+\d+\s+R|<<)/i.test(arrayBufferToBinaryString(buffer));
    }

    function hasOfficeEncryptionMarker(buffer) {
      var text = arrayBufferToBinaryString(buffer).replace(/\0/g, "");
      return text.indexOf("EncryptedPackage") !== -1 || text.indexOf("EncryptionInfo") !== -1;
    }

    async function validateUnlockedFile(file, ext) {
      try {
        if (ext === "pdf") {
          var pdfBuffer = await file.arrayBuffer();
          if (hasPdfEncryptionMarker(pdfBuffer)) {
            return file.name + " is password-protected. Please upload an unlocked PDF.";
          }
          return "";
        }

        if (ext === "doc" || ext === "docx" || ext === "ppt" || ext === "pptx") {
          var officeSlice = file.slice(0, Math.min(file.size || 0, 1024 * 1024));
          var officeBuffer = await officeSlice.arrayBuffer();
          if (hasOfficeEncryptionMarker(officeBuffer)) {
            return file.name + " is locked or protected. Please remove file protection and try again.";
          }
          return "";
        }

        var readableSlice = file.slice(0, Math.min(file.size || 0, 4096));
        await readableSlice.arrayBuffer();
        return "";
      } catch (err) {
        return file.name + " is locked or unreadable. Please remove file protection and try again.";
      }
    }

    function currentSignature() {
      return selectedFiles.map(fileKey).sort().join("::");
    }

    function currentAnalysisKey() {
      return [
        currentSignature(),
        paperSizeSelect.value || "",
        getSelectedColor(),
        String(getQuantity())
      ].join("::");
    }

    function setFeedback(message, tone) {
      if (!message) return;

      if (typeof window.servitechToast === "function") {
        window.servitechToast(message, { tone: tone || "info" });
        return;
      }
      console.warn(message);
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

    function setProcessingState(processing) {
      isSubmitting = processing;
      joinQueueBtn.disabled = !!processing;
      if (processing) {
        joinQueueBtn.setAttribute("aria-busy", "true");
        return;
      }

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
      syncClientPricing();
      var size = paperSizeSelect.value || "";
      if (summaryPaperSize) {
        summaryPaperSize.textContent = size ? size : "Not Selected";
      }
      if (summaryQty) summaryQty.textContent = String(getQuantity());
      if (summaryTotalPages) summaryTotalPages.textContent = String(state.total_pages || 0);
      if (summaryPricePerPage) summaryPricePerPage.textContent = toPeso(state.price_per_page || 0);
      if (summaryTotal) summaryTotal.textContent = toPeso(state.estimated_total || 0);
    }

    function restoredFileCount() {
      var analysisCount = Array.isArray(state.files) ? state.files.length : 0;
      var uploadedCount = Array.isArray(state.uploaded_files) ? state.uploaded_files.length : 0;
      var nameCount = currentFileNames().length;
      return Math.max(analysisCount, uploadedCount, nameCount, 0);
    }

    function hasSavedUploads() {
      return Array.isArray(state.uploaded_files) && state.uploaded_files.length > 0;
    }

    function hasAnyFiles() {
      return selectedFiles.length > 0 || restoredFileCount() > 0;
    }

    function getPageCountFromInfo(fileInfo) {
      if (!fileInfo || typeof fileInfo !== "object") return 0;
      if (typeof fileInfo.slide_count !== "undefined") {
        return Math.max(0, parseInt(fileInfo.slide_count, 10) || 0);
      }
      if (typeof fileInfo.page_count !== "undefined") {
        return Math.max(0, parseInt(fileInfo.page_count, 10) || 0);
      }
      return 0;
    }

    function setSelectedColor(value) {
      var normalized = (value || "").trim().toLowerCase();
      document.querySelectorAll('input[name="color"]').forEach(function (radio) {
        radio.checked = radio.value.trim().toLowerCase() === normalized;
      });
    }

    function refreshSavedFileTotals() {
      var analysisFiles = Array.isArray(state.files) ? state.files : [];
      var draftNames = Array.isArray(state.file_names) ? state.file_names : [];
      var uploadedFiles = Array.isArray(state.uploaded_files) ? state.uploaded_files : [];
      var derivedCount = Math.max(analysisFiles.length, draftNames.length, uploadedFiles.length, 0);
      var derivedPages = 0;
      var derivedImages = 0;

      if (analysisFiles.length) {
        analysisFiles.forEach(function (fileInfo) {
          derivedPages += getPageCountFromInfo(fileInfo);
          var ext = String(fileInfo && fileInfo.file_type ? fileInfo.file_type : "").toLowerCase();
          if (ext === "jpg" || ext === "jpeg" || ext === "png") {
            derivedImages += 1;
          }
        });
        state.total_pages = derivedPages;
        state.total_images = derivedImages;
      } else {
        state.total_pages = Math.max(0, parseInt(state.total_pages, 10) || 0);
        state.total_images = Math.max(0, parseInt(state.total_images, 10) || 0);
      }

      state.total_files = derivedCount;
    }

    function currentFileNames() {
      if (selectedFiles.length) {
        return selectedFiles.map(function (file) {
          return file.name;
        });
      }

      if (Array.isArray(state.file_names) && state.file_names.length) {
        return state.file_names.filter(function (name) {
          return typeof name === "string" && name.trim() !== "";
        });
      }

      if (Array.isArray(state.uploaded_files) && state.uploaded_files.length) {
        return state.uploaded_files.map(function (file) {
          return (file && (file.original_name || file.file_name || "")) || "";
        }).filter(function (name) {
          return name !== "";
        });
      }

      return [];
    }

    function renderUploadStatus() {
      if (!fileUploadStatus) return;

      if (selectedFiles.length) {
        fileUploadStatus.textContent = selectedFiles.length + (selectedFiles.length === 1
          ? " new file selected."
          : " new files selected.");
        return;
      }

      if (hasSavedUploads()) {
        fileUploadStatus.textContent = restoredFileCount() + (restoredFileCount() === 1
          ? " saved file restored below. The browser upload box stays empty until you choose a new file."
          : " saved files restored below. The browser upload box stays empty until you choose new files.");
        return;
      }

      if (restoredFileCount() > 0) {
        fileUploadStatus.textContent = restoredFileCount() + (restoredFileCount() === 1
          ? " file is listed below, but it needs to be selected again before continuing."
          : " files are listed below, but they need to be selected again before continuing.");
        return;
      }

      fileUploadStatus.textContent = "No files selected yet.";
    }

    function renderList() {
      fileListEl.innerHTML = "";

      var displayItems = [];

      if (selectedFiles.length) {
        selectedFiles.forEach(function (sourceFile, index) {
          var fileInfo = (Array.isArray(state.files) && state.files[index]) ? state.files[index] : {};
          displayItems.push({
            label: fileInfo.file_name || (sourceFile ? sourceFile.name : "File"),
            type: (fileInfo.file_type || (sourceFile ? getExt(sourceFile.name) : "") || "file").toUpperCase(),
            count: getPageCountFromInfo(fileInfo),
            isSlides: typeof fileInfo.slide_count !== "undefined",
            index: index
          });
        });
      } else {
        var analysisFiles = Array.isArray(state.files) ? state.files : [];
        var draftNames = currentFileNames();
        var uploadedFiles = Array.isArray(state.uploaded_files) ? state.uploaded_files : [];
        var itemCount = Math.max(analysisFiles.length, draftNames.length, uploadedFiles.length, 0);

        for (var i = 0; i < itemCount; i++) {
          var savedInfo = analysisFiles[i] || {};
          var uploadedInfo = uploadedFiles[i] || {};
          displayItems.push({
            label: savedInfo.file_name || draftNames[i] || uploadedInfo.original_name || uploadedInfo.file_name || "File",
            type: (savedInfo.file_type || uploadedInfo.file_type || getExt(draftNames[i] || "") || "file").toUpperCase(),
            count: getPageCountFromInfo(savedInfo),
            isSlides: typeof savedInfo.slide_count !== "undefined",
            index: i
          });
        }
      }

      if (!displayItems.length) {
        fileMetaEl.textContent = "No files uploaded yet.";
        renderUploadStatus();
        return;
      }

      displayItems.forEach(function (item) {
        var li = document.createElement("li");
        var countLabel = item.count > 0
          ? " - " + item.count + (item.isSlides ? " slide(s)" : " page(s)")
          : "";

        var info = document.createElement("span");
        info.textContent = item.label + " (" + item.type + ")" + countLabel + " ";

        var removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.textContent = "Remove";
        removeBtn.setAttribute("aria-label", "Remove " + item.label);
        removeBtn.dataset.fileIndex = String(item.index);

        li.appendChild(info);
        li.appendChild(removeBtn);
        fileListEl.appendChild(li);
      });

      fileMetaEl.textContent =
        displayItems.length + (displayItems.length === 1 ? " file ready" : " files ready") +
        " | Total Pages: " + (state.total_pages || 0);
      renderUploadStatus();
    }

    function resetAnalysis(keepFeedback) {
      if (selectedFiles.length) {
        state.files = selectedFiles.map(function (file) {
          return {
            file_name: file.name,
            file_type: getExt(file.name)
          };
        });
        state.file_names = selectedFiles.map(function (file) {
          return file.name;
        });
        state.total_files = selectedFiles.length;
        state.total_images = 0;
        state.total_pages = 0;
        state.price_per_page = 0;
        state.estimated_total = 0;
      } else if (hasSavedUploads()) {
        refreshSavedFileTotals();
      } else {
        state.files = [];
        state.file_names = [];
        state.uploaded_files = [];
        state.total_files = 0;
        state.total_images = 0;
        state.total_pages = 0;
        state.price_per_page = 0;
        state.estimated_total = 0;
      }

      if (!keepFeedback) {
        state.error = "";
        setFeedback("", "error");
      }

      renderList();
      renderSummary();
    }

    async function analyzeSelectedFiles() {
      var requestSeq = ++analysisRequestSeq;
      var requestPaperSize = paperSizeSelect.value || "";
      var requestColor = getSelectedColor();
      var requestQuantity = getQuantity();
      var requestAnalysisKey = currentAnalysisKey();

      resetAnalysis(true);

      if (!selectedFiles.length && !hasSavedUploads()) {
        state.error = "";
        setFeedback("", "error");
        return;
      }

      var formData = new FormData();
      formData.append("paper_size", requestPaperSize);
      formData.append("color_option", requestColor);
      formData.append("quantity", String(requestQuantity));

      if (selectedFiles.length) {
        selectedFiles.forEach(function (file) {
          formData.append("files[]", file, file.name);
        });
      } else {
        formData.append("total_pages", String(state.total_pages || 0));
        formData.append("total_files", String(state.total_files || 0));
        formData.append("total_images", String(state.total_images || 0));
      }

      var csrf = (window.servitechCsrfToken && window.servitechCsrfToken()) || "";

      try {
        var res = await fetch(servitechUrl("/api/printing_analyze.php"), {
          method: "POST",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-Token": csrf
          },
          body: formData
        });

        var raw = await res.text();
        var data = null;
        try {
          data = JSON.parse(raw);
        } catch (parseErr) {
          data = { ok: false, error: "Server returned invalid response." };
        }

        if (requestSeq !== analysisRequestSeq || requestAnalysisKey !== currentAnalysisKey()) {
          return;
        }

        if (!data.ok) {
          state.error = data.error || "Unable to analyze files.";
          if (selectedFiles.length && Array.isArray(data.files) && data.files.length) {
            state.files = data.files;
          }
          state.total_files = Number.isFinite(Number(data.total_files)) ? Number(data.total_files) : state.total_files;
          state.total_images = Number.isFinite(Number(data.total_images)) ? Number(data.total_images) : state.total_images;
          state.total_pages = Number.isFinite(Number(data.total_pages)) ? Number(data.total_pages) : state.total_pages;
          state.price_per_page = 0;
          state.estimated_total = 0;
          renderList();
          renderSummary();
          setFeedback(state.error, "error");
          return;
        }

        if (requestSeq !== analysisRequestSeq || requestAnalysisKey !== currentAnalysisKey()) {
          return;
        }

        state.error = "";
        if (selectedFiles.length && Array.isArray(data.files)) {
          state.files = data.files;
        }
        state.total_files = Number(data.total_files) || state.total_files;
        state.total_images = Number(data.total_images) || 0;
        state.total_pages = Number(data.total_pages) || 0;
        state.price_per_page = Number(data.price_per_page) || 0;
        state.estimated_total = Number(data.estimated_total) || 0;
        renderList();
        renderSummary();
        setFeedback("", "error");
      } catch (err) {
        if (requestSeq !== analysisRequestSeq || requestAnalysisKey !== currentAnalysisKey()) {
          return;
        }
        state.error = "Network/server error while analyzing files.";
        state.price_per_page = 0;
        state.estimated_total = 0;
        renderSummary();
        setFeedback(state.error, "error");
      }
    }

    async function addFiles(incoming) {
      var errors = [];
      var existing = {};
      var acceptedFiles = [];

      selectedFiles.forEach(function (f) {
        existing[fileKey(f)] = true;
      });

      var incomingFiles = Array.from(incoming || []);
      if (incomingFiles.length && fileUploadStatus) {
        fileUploadStatus.textContent = "Checking selected files...";
      }

      for (var i = 0; i < incomingFiles.length; i++) {
        var file = incomingFiles[i];
        var ext = getExt(file.name);
        if (!ALLOWED_EXT[ext]) {
          errors.push(file.name + " has unsupported file type.");
          continue;
        }

        if ((file.size || 0) > MAX_FILE_SIZE) {
          errors.push(file.name + " exceeds 20MB limit.");
          continue;
        }

        var key = fileKey(file);
        if (existing[key]) {
          errors.push(file.name + " is already selected.");
          continue;
        }

        var lockedError = await validateUnlockedFile(file, ext);
        if (lockedError) {
          errors.push(lockedError);
          continue;
        }

        existing[key] = true;
        acceptedFiles.push(file);
      }

      if (acceptedFiles.length && !selectedFiles.length && hasSavedUploads()) {
        state.files = [];
        state.file_names = [];
        state.uploaded_files = [];
        state.total_files = 0;
        state.total_images = 0;
        state.total_pages = 0;
        state.price_per_page = 0;
        state.estimated_total = 0;
      }

      acceptedFiles.forEach(function (file) {
        selectedFiles.push(file);
      });

      uploadedSignature = "";
      analysisRequestSeq++;
      if (acceptedFiles.length) {
        state.uploaded_files = [];
      }
      syncFileInput();

      if (errors.length) {
        state.error = errors.join(" ");
        setFeedback(state.error, "error");
      } else {
        state.error = "";
        setFeedback("", "error");
      }

      resetAnalysis(true);
      if (acceptedFiles.length) {
        analyzeSelectedFiles();
      }
    }

    function removeSavedFileAt(index) {
      if (index < 0) return;

      if (Array.isArray(state.files) && state.files.length > index) {
        state.files.splice(index, 1);
      }
      if (Array.isArray(state.file_names) && state.file_names.length > index) {
        state.file_names.splice(index, 1);
      }
      if (Array.isArray(state.uploaded_files) && state.uploaded_files.length > index) {
        state.uploaded_files.splice(index, 1);
      }

      uploadedSignature = "";
      analysisRequestSeq++;
      state.error = "";
      setFeedback("", "error");

      if (!hasSavedUploads()) {
        resetAnalysis(true);
        return;
      }

      refreshSavedFileTotals();
      renderList();
      renderSummary();
      analyzeSelectedFiles();
    }

    function removeSelectedByIndex(index) {
      if (index < 0) return;

      if (selectedFiles.length) {
        selectedFiles = selectedFiles.filter(function (file, fileIndex) {
          return fileIndex !== index;
        });

        uploadedSignature = "";
        analysisRequestSeq++;
        state.uploaded_files = [];
        state.error = "";
        setFeedback("", "error");
        syncFileInput();
        resetAnalysis(true);
        analyzeSelectedFiles();
        return;
      }

      removeSavedFileAt(index);
    }

    async function uploadSelectedFiles() {
      if (!selectedFiles.length && hasSavedUploads()) {
        return {
          ok: true,
          reused: true,
          payload: {
            uploaded_files: state.uploaded_files,
            file_name: currentFileNames()[0] || null,
            file_names: currentFileNames(),
            total_files: state.total_files,
            total_images: state.total_images,
            total_pages: state.total_pages,
            price_per_page: state.price_per_page,
            estimated_total: state.estimated_total,
            file_analysis: state.files
          }
        };
      }

      if (!selectedFiles.length && restoredFileCount() > 0) {
        state.error = "Your previous files are listed below, but the upload session expired. Please choose the files again.";
        setFeedback(state.error, "error");
        return { ok: false, error: state.error };
      }

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
          reused: false,
          payload: {
            uploaded_files: state.uploaded_files,
            file_name: selectedFiles[0] ? selectedFiles[0].name : null,
            file_names: selectedFiles.map(function (f) { return f.name; }),
            total_files: state.total_files,
            total_images: state.total_images,
            total_pages: state.total_pages,
            price_per_page: state.price_per_page,
            estimated_total: state.estimated_total,
            file_analysis: state.files
          }
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
          "X-CSRF-Token": csrf
        },
        body: fd
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
        reused: false,
        payload: {
          uploaded_files: state.uploaded_files,
          file_name: selectedFiles[0] ? selectedFiles[0].name : null,
          file_names: selectedFiles.map(function (f) { return f.name; }),
          total_files: state.total_files,
          total_images: state.total_images,
          total_pages: state.total_pages,
          price_per_page: state.price_per_page,
          estimated_total: state.estimated_total,
          file_analysis: state.files
        }
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
      var fileNames = currentFileNames();
      var orderType = getOrderType();
      return {
        category: getQueueCategoryFromOrderType(orderType),
        service_label: getServiceLabelFromOrderType(orderType),
        order_type: orderType,
        paper_size: paperSizeSelect.value || null,
        quantity: getEnteredQuantity(),
        color_option: getSelectedColor(),
        payment_method: getPaymentMethod() || null,
        notes: notesInput ? notesInput.value.trim() : null,
        file_name: fileNames[0] || null,
        file_names: fileNames.length ? fileNames : null,
        total_files: Number(state.total_files) || 0,
        total_images: Number(state.total_images) || 0,
        total_pages: Number(state.total_pages) || 0,
        price_per_page: Number(state.price_per_page) || 0,
        estimated_total: Number(state.estimated_total) || 0,
        file_analysis: Array.isArray(state.files) ? state.files : [],
        uploaded_files: Array.isArray(state.uploaded_files) ? state.uploaded_files : []
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

      if (!hasAnyFiles()) {
        errors.push("Upload at least one file.");
        setFieldInvalid(fileUpload, true);
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
      if (typeof window.openQueueSuccessModal === "function") {
        window.openQueueSuccessModal(queueCode, {
          service: getOrderType() === "online" ? "Online Document Printing" : "Walk-In Document Printing"
        });
        return;
      }
      modalQueueNo.textContent = queueCode;
      queueModal.style.display = "flex";
    }

    function restoreDraft() {
      if (!draftState || typeof draftState !== "object") {
        return;
      }

      var draftOrderType = (draftState.order_type || "").toLowerCase();
      if (draftOrderType !== "online") {
        return;
      }

      orderTypeSelect.value = "online";
      paymentMethodSelect.value = draftState.payment_method || "";
      paperSizeSelect.value = draftState.paper_size || "";
      qtyInput.value = String(Math.max(1, parseInt(draftState.quantity, 10) || 1));
      if (notesInput) {
        notesInput.value = draftState.notes || "";
      }
      setSelectedColor(draftState.color_option || "");

      state.files = Array.isArray(draftState.file_analysis) ? draftState.file_analysis.slice() : [];
      state.file_names = Array.isArray(draftState.file_names) ? draftState.file_names.slice() : [];
      state.uploaded_files = Array.isArray(draftState.uploaded_files) ? draftState.uploaded_files.slice() : [];
      state.total_files = Number(draftState.total_files) || 0;
      state.total_images = Number(draftState.total_images) || 0;
      state.total_pages = Number(draftState.total_pages) || 0;
      state.price_per_page = Number(draftState.price_per_page) || 0;
      state.estimated_total = Number(draftState.estimated_total) || 0;
      state.error = "";

      if ((!state.file_names || !state.file_names.length) && state.uploaded_files.length) {
        state.file_names = state.uploaded_files.map(function (file) {
          return (file && file.original_name) || "";
        }).filter(function (name) {
          return name !== "";
        });
      }
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

      var usesGcashPaymentPage = payload.order_type === "online" && payload.payment_method === "gcash";
      setProcessingState(true);
      setFeedback(usesGcashPaymentPage ? "Preparing payment..." : "Submitting your queue request...", "info");

      try {
        var uploadResult = await uploadSelectedFiles();
        if (!uploadResult || uploadResult.ok === false) {
          setFeedback(uploadResult && uploadResult.error ? uploadResult.error : "File upload failed.", "error");
          return;
        }

        var canCleanupUploads = !uploadResult.reused;

        if (uploadResult.payload && typeof uploadResult.payload === "object") {
          for (var key in uploadResult.payload) {
            payload[key] = uploadResult.payload[key];
          }
        }

        if (usesGcashPaymentPage) {
          var draftResult = await saveOnlineDraft(payload);
          if (!draftResult.ok) {
            if (canCleanupUploads) {
              await cleanupUploadedFiles(payload.uploaded_files);
            }
            setFeedback(draftResult.error || "Unable to continue to payment.", "error");
            return;
          }

          if (typeof window.servitechToastForNavigation === "function") {
            window.servitechToastForNavigation("Print order saved. Complete your GCash payment details.", {
              tone: "success"
            });
          }
          if (window.servitechJoinQueueLeaveGuard) {
            window.servitechJoinQueueLeaveGuard.disarm();
          }
          window.location.href = servitechUrl("/pages/customer/custo_print_order_payment.php");
          return;
        }

        var result = await createQueue(payload);
        if (!result.ok) {
          if (canCleanupUploads) {
            await cleanupUploadedFiles(payload.uploaded_files);
          }
          setFeedback("Queue not saved: " + (result.error || "Unknown error"), "error");
          return;
        }

        if (getCategoryFromQueueCode(result.queue_code) !== payload.category) {
          if (canCleanupUploads) {
            await cleanupUploadedFiles(payload.uploaded_files);
          }
          setFeedback("Queue not saved: queue mapping mismatch.", "error");
          return;
        }

        setFeedback("", "error");
        if (window.servitechJoinQueueLeaveGuard) {
          window.servitechJoinQueueLeaveGuard.disarm();
        }
        if (window.servitechJoinQueuePostSuccess) {
          window.servitechJoinQueuePostSuccess.markComplete(result.queue_code);
        }
        openSuccessModal(result.queue_code);
      } catch (err) {
        console.error(err);
        if (payload && Array.isArray(payload.uploaded_files) && payload.uploaded_files.length && selectedFiles.length) {
          await cleanupUploadedFiles(payload.uploaded_files);
        }
        setFeedback("Network/server error. Please try again.", "error");
      } finally {
        setProcessingState(false);
      }
    }

    var debouncedAnalyze = debounce(analyzeSelectedFiles, 220);

    fileUpload.addEventListener("change", function (e) {
      addFiles(e.target.files);
    });

    fileListEl.addEventListener("click", function (e) {
      var target = e.target;
      if (!target || target.tagName !== "BUTTON") return;
      var index = parseInt(target.dataset.fileIndex || "-1", 10);
      if (!Number.isInteger(index) || index < 0) return;
      removeSelectedByIndex(index);
    });

    qtyInput.addEventListener("input", debouncedAnalyze);
    paperSizeSelect.addEventListener("change", debouncedAnalyze);
    orderTypeSelect.addEventListener("change", updateOrderTypeUi);
    paymentMethodSelect.addEventListener("change", updateOrderTypeUi);
    document.querySelectorAll('input[name="color"]').forEach(function (radio) {
      radio.addEventListener("change", debouncedAnalyze);
    });

    joinQueueBtn.addEventListener("click", handleJoinQueue, true);

    restoreDraft();
    resetAnalysis(true);
    renderSummary();
    updateOrderTypeUi();
  });
})();


