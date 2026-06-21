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
    var fileUpload = document.getElementById("fileUpload");
    var fileListEl = document.getElementById("fileAnalysisList");
    var fileMetaEl = document.getElementById("fileAnalysisMeta");
    var fileUploadStatus = document.getElementById("fileUploadStatus");
    var qtyInput = document.getElementById("qtyInput");
    var paperSizeSelect = document.getElementById("paperSizeSelect");
    var paymentSection = document.getElementById("paymentSection");
    var paymentMethodSelect = document.getElementById("paymentMethodSelect");
    var cashPaymentNote = document.getElementById("cashPaymentNote");
    var joinQueueBtn = document.getElementById("joinQueueBtn");
    var notesInput = document.getElementById("notes");
    var summaryPaperSize = document.getElementById("summaryPaperSize");
    var summaryColorOption = document.getElementById("summaryColorOption");
    var summaryQty = document.getElementById("summaryQty");
    var summaryTotalPages = document.getElementById("summaryTotalPages");
    var summaryPricePerPage = document.getElementById("summaryPricePerPage");
    var summaryTotal = document.getElementById("summaryTotal");
    var queueModal = document.getElementById("queueModal");
    var modalQueueNo = document.getElementById("modalQueueNo");

    if (!fileUpload || !fileListEl || !fileMetaEl || !qtyInput || !paperSizeSelect || !paymentMethodSelect || !joinQueueBtn) {
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
    var uploadLimits = window.ServitechUpload && window.ServitechUpload.limits
      ? window.ServitechUpload.limits
      : {
          maxFileBytes: 25 * 1024 * 1024,
          maxTotalBytes: 100 * 1024 * 1024,
          maxFiles: 5,
          fileSizeMessage: "Maximum file size is 25 MB per file.",
          totalSizeMessage: "Total upload size must not exceed 100 MB.",
          fileCountMessage: "You can upload up to 5 files only."
        };

    var selectedFiles = [];
    var uploadedSignature = "";
    var analysisRequestSeq = 0;
    var isSubmitting = false;
    var isAnalyzingFiles = false;
    var uploadTasks = {};
    var activeUploadSession = null;
    var fileAnalysisCache = {};

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

    function selectedCatalogOptions() {
      return window.ServitechCatalogClient.selectionMap([
        window.ServitechCatalogClient.fromSelect(paperSizeSelect, "paper_size"),
        window.ServitechCatalogClient.fromChecked("color", "color_option", document)
      ]);
    }

    function findSelectedCatalogRule() {
      var rules = Array.isArray(window.servitechCatalogRules) ? window.servitechCatalogRules : [];
      return window.ServitechCatalogClient.findRule(rules, selectedCatalogOptions());
    }

    function getClientPricePerPage() {
      var rule = findSelectedCatalogRule();
      if (rule) {
        if (rule.price_type === "assessment") return 0;
        var catalogPrice = Number(rule.price);
        return Number.isFinite(catalogPrice) && catalogPrice > 0 ? catalogPrice : 0;
      }
      return 0;
    }

    function syncClientPricing() {
      var pricePerPage = getClientPricePerPage();
      if (pricePerPage <= 0) {
        state.price_per_page = 0;
        state.estimated_total = 0;
        return;
      }

      state.price_per_page = pricePerPage;
    }

    function updateColorPriceLabels() {
      var rules = Array.isArray(window.servitechCatalogRules) ? window.servitechCatalogRules : [];
      var paper = window.ServitechCatalogClient.fromSelect(paperSizeSelect, "paper_size");
      document.querySelectorAll("[data-doc-color-key]").forEach(function (el) {
        var color = {
          group_key: "color_option",
          value_id: Number(el.dataset.docColorId || 0),
          value_key: el.dataset.docColorKey || "",
          label: el.closest("label")?.textContent?.trim() || ""
        };
        var rule = window.ServitechCatalogClient.findRule(
          rules,
          window.ServitechCatalogClient.selectionMap([paper, color])
        );
        var radio = Array.from(document.querySelectorAll('input[name="color"]')).find(function (input) {
          return Number(input.dataset.valueId || 0) === color.value_id
            || String(input.dataset.valueKey || "") === color.value_key;
        });
        if (radio) {
          radio.disabled = !rule;
          radio.closest("label")?.classList.toggle("is-unavailable", !rule);
          if (!rule && radio.checked) radio.checked = false;
        }
        if (!el) return;
        var price = Number(rule && rule.price);
        el.textContent = !rule
          ? "Unavailable"
          : (rule.price_type !== "assessment" && Number.isFinite(price) ? toPeso(price) : "For assessment");
      });
    }

    function setEstimatedTotalDisplay(value, displayState) {
      if (!summaryTotal) return;

      if (displayState !== "final") {
        summaryTotal.textContent = displayState === "computing" ? "Computing..." : "\u2014";
        summaryTotal.classList.add("is-pending-total");
        summaryTotal.classList.toggle("is-computing", displayState === "computing");
        return;
      }

      summaryTotal.textContent = value;
      summaryTotal.classList.remove("is-pending-total");
      summaryTotal.classList.remove("is-computing");
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

    function getPaymentMethod() {
      return (paymentMethodSelect.value || "").trim().toLowerCase();
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

    function analysisKey(files) {
      return Array.from(files || []).map(fileKey).sort().join("::");
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

    function updateOrderSummary() {
      updateColorPriceLabels();
      syncClientPricing();
      var size = paperSizeSelect.value || "";
      var color = getSelectedColor();
      var qty = getEnteredQuantity();
      var selectedPaperSize = size.trim() !== "";
      var selectedColorOption = color.trim() !== "";
      var uploadedFiles = currentFileNames();
      var totalPages = Number(state.total_pages) || 0;
      var pricePerPage = Number(state.price_per_page) || 0;
      var hasValidQty = Number.isFinite(qty) && qty > 0;
      var hasPaymentMethod = getPaymentMethod() !== "";
      var hasMinimumForComputing = selectedPaperSize && selectedColorOption && hasValidQty && uploadedFiles.length > 0;
      var canShowFinalTotal =
        selectedPaperSize &&
        selectedColorOption &&
        hasValidQty &&
        uploadedFiles.length > 0 &&
        !isAnalyzingFiles &&
        totalPages > 0 &&
        pricePerPage > 0 &&
        hasPaymentMethod;

      if (!canShowFinalTotal) {
        state.estimated_total = 0;
      }

      if (summaryPaperSize) {
        summaryPaperSize.textContent = size ? size : "Not Selected";
      }
      if (summaryColorOption) {
        summaryColorOption.textContent = color || "Not Selected";
      }
      if (summaryQty) summaryQty.textContent = hasValidQty ? String(qty) : "0";
      if (summaryTotalPages) summaryTotalPages.textContent = String(state.total_pages || 0);
      if (summaryPricePerPage) summaryPricePerPage.textContent = size && color ? toPeso(state.price_per_page || 0) : "\u2014";

      if (!canShowFinalTotal) {
        setEstimatedTotalDisplay("", isAnalyzingFiles && hasMinimumForComputing ? "computing" : "pending");
        return;
      }

      var finalTotal = qty * totalPages * pricePerPage;
      state.estimated_total = finalTotal;
      setEstimatedTotalDisplay(toPeso(finalTotal), "final");
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

    function setSelectedColor(value, valueId) {
      var normalized = (value || "").trim().toLowerCase();
      document.querySelectorAll('input[name="color"]').forEach(function (radio) {
        radio.checked = Number(valueId || 0) > 0
          ? Number(radio.dataset.valueId || 0) === Number(valueId)
          : radio.value.trim().toLowerCase() === normalized;
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
            index: index,
            key: sourceFile ? fileKey(sourceFile) : ""
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
        var head = document.createElement("div");
        var countLabel = item.count > 0
          ? " - " + item.count + (item.isSlides ? " slide(s)" : " page(s)")
          : "";
        var task = item.key ? uploadTasks[item.key] || null : null;
        var taskIsActive = task && window.ServitechUpload && window.ServitechUpload.isActiveStatus(task.status);
        var taskHasProblem = task && window.ServitechUpload && window.ServitechUpload.isTerminalProblemStatus(task.status);

        var info = document.createElement("span");
        li.className = "servitech-upload-item";
        head.className = "servitech-upload-item__head";
        info.className = "servitech-upload-item__name";
        info.textContent = item.label + " (" + item.type + ")" + countLabel + " ";

        var removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.textContent = taskIsActive
          ? "Cancel"
          : "Remove";
        removeBtn.dataset.uploadAction = removeBtn.textContent.toLowerCase();
        removeBtn.dataset.fileKey = item.key || "";
        removeBtn.setAttribute("aria-label", removeBtn.textContent + " " + item.label);
        removeBtn.dataset.fileIndex = String(item.index);

        head.appendChild(info);
        head.appendChild(removeBtn);
        li.appendChild(head);
        if (taskIsActive) {
          var progress = document.createElement("div");
          var track = document.createElement("div");
          var bar = document.createElement("div");
          var meta = document.createElement("div");
          progress.className = "servitech-upload-progress servitech-upload-progress--" + task.status;
          progress.setAttribute("role", "progressbar");
          progress.setAttribute("aria-label", item.label + " " + (task.message || "file progress"));
          progress.setAttribute("aria-valuemin", "0");
          progress.setAttribute("aria-valuemax", "100");
          if (task.status !== "analyzing" && task.status !== "processing" && task.status !== "checking") {
            progress.setAttribute("aria-valuenow", String(Math.max(0, Math.min(100, task.progress || 0))));
          }
          track.className = "servitech-upload-progress__track";
          bar.className = "servitech-upload-progress__bar";
          bar.style.width = String(Math.max(0, Math.min(100, task.progress || 0))) + "%";
          meta.className = "servitech-upload-progress__meta";
          meta.textContent = task.message || "Uploading...";
          track.appendChild(bar);
          progress.appendChild(track);
          progress.appendChild(meta);
          li.appendChild(progress);
        } else if (taskHasProblem) {
          var result = document.createElement("div");
          result.className = "servitech-upload-result servitech-upload-result--" + task.status;
          result.textContent = task.message || "File processing did not complete.";
          li.appendChild(result);
        }
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
          return fileAnalysisCache[fileKey(file)] || {
            file_name: file.name,
            file_type: getExt(file.name)
          };
        });
        state.file_names = selectedFiles.map(function (file) {
          return file.name;
        });
        state.total_files = selectedFiles.length;
        state.total_images = state.files.reduce(function (total, fileInfo) {
          var ext = String(fileInfo && fileInfo.file_type || "").toLowerCase();
          return total + (/^(jpg|jpeg|png)$/.test(ext) ? 1 : 0);
        }, 0);
        state.total_pages = state.files.reduce(function (total, fileInfo) {
          return total + getPageCountFromInfo(fileInfo);
        }, 0);
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
      updateOrderSummary();
    }

    async function analyzeSelectedFiles() {
      var requestSeq = ++analysisRequestSeq;
      var requestPaperSize = paperSizeSelect.value || "";
      var requestColor = getSelectedColor();
      var requestQuantity = getQuantity();
      var pendingFiles = selectedFiles.filter(function (file) {
        return !fileAnalysisCache[fileKey(file)];
      });
      var requestAnalysisKey = analysisKey(pendingFiles);

      if (!selectedFiles.length && !hasSavedUploads()) {
        state.error = "";
        setFeedback("", "error");
        return;
      }

      if (!pendingFiles.length) {
        isAnalyzingFiles = false;
        resetAnalysis(true);
        return;
      }

      isAnalyzingFiles = true;
      if (!activeUploadSession) {
        pendingFiles.forEach(function (file) {
          uploadTasks[fileKey(file)] = {
            key: fileKey(file),
            file: file,
            status: "analyzing",
            progress: 35,
            message: "Processing file and counting pages...",
            metadata: null
          };
        });
        renderList();
      }
      updateOrderSummary();

      var formData = new FormData();
      formData.append("paper_size", requestPaperSize);
      formData.append("color_option", requestColor);
      formData.append("catalog_pricing_rule_id", String(findSelectedCatalogRule() ? Number(findSelectedCatalogRule().id) || 0 : 0));
      formData.append("quantity", String(requestQuantity));

      pendingFiles.forEach(function (file) {
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

        if (requestSeq !== analysisRequestSeq || requestAnalysisKey !== analysisKey(pendingFiles)) {
          return;
        }

        if (!data.ok) {
          var formValidationOnly = data.error_scope === "form";
          if (formValidationOnly) {
            state.error = "";
            if (Array.isArray(data.files)) {
              pendingFiles.forEach(function (file, index) {
                if (data.files[index]) fileAnalysisCache[fileKey(file)] = data.files[index];
              });
            }
            pendingFiles.forEach(function (file) {
              var readyTask = uploadTasks[fileKey(file)];
              if (!readyTask) return;
              readyTask.status = "success";
              readyTask.progress = 100;
              readyTask.message = "Processing complete. Ready to upload.";
            });
            isAnalyzingFiles = false;
            resetAnalysis(true);
            return;
          }

          state.error = data.error || "Unable to analyze files.";
          pendingFiles.forEach(function (file) {
            var task = uploadTasks[fileKey(file)];
            if (!task) return;
            task.status = "error";
            task.progress = 100;
            task.message = data.error || "File processing failed.";
          });
          isAnalyzingFiles = false;
          resetAnalysis(true);
          setFeedback(state.error, "error");
          return;
        }

        if (requestSeq !== analysisRequestSeq || requestAnalysisKey !== analysisKey(pendingFiles)) {
          return;
        }

        state.error = "";
        if (Array.isArray(data.files)) {
          pendingFiles.forEach(function (file, index) {
            if (data.files[index]) fileAnalysisCache[fileKey(file)] = data.files[index];
          });
        }
        pendingFiles.forEach(function (file) {
          var task = uploadTasks[fileKey(file)];
          if (!task) return;
          task.status = "success";
          task.progress = 100;
          task.message = "Processing complete. Ready to upload.";
        });
        resetAnalysis(true);
        isAnalyzingFiles = false;
        updateOrderSummary();
        setFeedback("", "error");
      } catch (err) {
        if (requestSeq !== analysisRequestSeq || requestAnalysisKey !== analysisKey(pendingFiles)) {
          return;
        }
        state.error = "Network/server error while analyzing files.";
        pendingFiles.forEach(function (file) {
          var task = uploadTasks[fileKey(file)];
          if (!task) return;
          task.status = "error";
          task.progress = 100;
          task.message = "File processing failed. Please try again.";
        });
        resetAnalysis(true);
        isAnalyzingFiles = false;
        updateOrderSummary();
        setFeedback(state.error, "error");
      }
    }

    async function addFiles(incoming) {
      var errors = [];
      var existing = {};
      var acceptedFiles = [];
      var acceptedBytes = selectedFiles.reduce(function (total, file) {
        return total + Math.max(0, Number(file.size) || 0);
      }, 0);

      selectedFiles.forEach(function (f) {
        existing[fileKey(f)] = true;
      });

      var incomingFiles = Array.from(incoming || []);
      if (incomingFiles.length && fileUploadStatus) {
        fileUploadStatus.textContent = "Checking selected files...";
        fileUploadStatus.classList.add("is-processing");
      }

      for (var i = 0; i < incomingFiles.length; i++) {
        var file = incomingFiles[i];
        var ext = getExt(file.name);
        if (!ALLOWED_EXT[ext]) {
          errors.push(file.name + " has unsupported file type.");
          continue;
        }

        if ((file.size || 0) > uploadLimits.maxFileBytes) {
          errors.push(uploadLimits.fileSizeMessage);
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

        if (selectedFiles.length + acceptedFiles.length >= uploadLimits.maxFiles) {
          errors.push(uploadLimits.fileCountMessage);
          continue;
        }
        if (acceptedBytes + (file.size || 0) > uploadLimits.maxTotalBytes) {
          errors.push(uploadLimits.totalSizeMessage);
          continue;
        }

        existing[key] = true;
        acceptedFiles.push(file);
        acceptedBytes += file.size || 0;
      }

      if (acceptedFiles.length && !selectedFiles.length && hasSavedUploads()) {
        fileAnalysisCache = {};
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
        isAnalyzingFiles = true;
      }
      syncFileInput();

      if (errors.length) {
        state.error = "";
        setFeedback(Array.from(new Set(errors)).join(" "), "error");
      } else {
        state.error = "";
        setFeedback("", "error");
      }

      resetAnalysis(true);
      if (fileUploadStatus) fileUploadStatus.classList.remove("is-processing");
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
      updateOrderSummary();
    }

    function removeSelectedByIndex(index) {
      if (index < 0) return;

      if (selectedFiles.length) {
        var removedFile = selectedFiles[index] || null;
        selectedFiles = selectedFiles.filter(function (file, fileIndex) {
          return fileIndex !== index;
        });

        uploadedSignature = "";
        analysisRequestSeq++;
        state.uploaded_files = [];
        if (removedFile) {
          delete fileAnalysisCache[fileKey(removedFile)];
          delete uploadTasks[fileKey(removedFile)];
        }
        state.error = "";
        isAnalyzingFiles = false;
        setFeedback("", "error");
        syncFileInput();
        resetAnalysis(true);
        if (selectedFiles.some(function (file) {
          return !fileAnalysisCache[fileKey(file)];
        })) {
          analyzeSelectedFiles();
        }
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

      if (!window.ServitechUpload) {
        return { ok: false, error: "Upload progress support could not be loaded. Please refresh and try again." };
      }

      uploadTasks = {};
      fileUpload.disabled = true;
      activeUploadSession = window.ServitechUpload.start(selectedFiles, {
        onChange: function (tasks) {
          uploadTasks = {};
          tasks.forEach(function (task) {
            uploadTasks[task.key] = task;
          });
          renderList();
        }
      });
      var result = await activeUploadSession.promise;
      activeUploadSession = null;
      fileUpload.disabled = false;

      if (!result.ok) {
        var cancelledKeys = {};
        result.tasks.forEach(function (task) {
          if (task.status === "cancelled") cancelledKeys[task.key] = true;
        });
        if (Object.keys(cancelledKeys).length) {
          selectedFiles = selectedFiles.filter(function (file) {
            var key = fileKey(file);
            if (cancelledKeys[key]) {
              delete fileAnalysisCache[key];
              return false;
            }
            return true;
          });
          syncFileInput();
          resetAnalysis(true);
        }
        state.uploaded_files = [];
        uploadedSignature = "";
        renderList();
        setFeedback(result.error || "File upload failed.", "error");
        return { ok: false, error: result.error || "File upload failed." };
      }

      state.error = "";
      state.uploaded_files = Array.isArray(result.uploaded_files) ? result.uploaded_files : [];
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

    function updatePaymentUi() {
      paymentSection.hidden = false;
      cashPaymentNote.hidden = getPaymentMethod() !== "cash";
      setFieldInvalid(paymentMethodSelect, false);
      updateOrderSummary();
    }

    function buildPayload() {
      var fileNames = currentFileNames();
      return {
        category: "printing",
        service_label: "Document Print",
        catalog_service_id: Number(document.body && document.body.dataset ? document.body.dataset.catalogServiceId : 0) || null,
        catalog_pricing_rule_id: findSelectedCatalogRule() ? Number(findSelectedCatalogRule().id) || null : null,
        catalog_option_value_ids: window.ServitechCatalogClient.optionIdMap(selectedCatalogOptions()),
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

      if (payload.paper_size && payload.color_option && !findSelectedCatalogRule()) {
        errors.push("The selected option is not available because it has no active price setup. Please choose another option or contact the shop.");
        window.ServitechCatalogClient.debugUnavailable(
          "Document Printing",
          selectedCatalogOptions(),
          Array.isArray(window.servitechCatalogRules) ? window.servitechCatalogRules : []
        );
        setFieldInvalid(paperSizeSelect, true);
        setRadioInvalid("color", true);
      }

      if (!hasAnyFiles()) {
        errors.push("Upload at least one file.");
        setFieldInvalid(fileUpload, true);
      }

      if (!payload.payment_method) {
        errors.push("Select a payment method for Document Print.");
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

    async function savePrintDraft(payload) {
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
          service: "Document Print"
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

      paymentMethodSelect.value = draftState.payment_method || "";
      var restoredPaperSize = draftState.paper_size || "";
      var restoredOptionIds = draftState.catalog_option_value_ids || {};
      var restoredPaperOption = Array.from(paperSizeSelect.options).find(function (option) {
        return Number(restoredOptionIds.paper_size || 0) > 0
          ? Number(option.dataset.valueId || 0) === Number(restoredOptionIds.paper_size)
          : option.value === restoredPaperSize;
      });
      paperSizeSelect.value = restoredPaperOption ? restoredPaperOption.value : "";
      qtyInput.value = String(Math.max(1, parseInt(draftState.quantity, 10) || 1));
      if (notesInput) {
        notesInput.value = draftState.notes || "";
      }
      setSelectedColor(draftState.color_option || "", restoredOptionIds.color_option || 0);

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
      updatePaymentUi();

      var payload = buildPayload();
      var errors = validatePayload(payload);
      if (errors.length) {
        setFeedback(errors.join(" "), "error");
        return;
      }

      var usesGcashPaymentPage = payload.payment_method === "gcash";
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
          var gcashResult = await createQueue(payload);
          if (!gcashResult.ok) {
            if (gcashResult.redirect_url) {
              if (window.servitechJoinQueueLeaveGuard) window.servitechJoinQueueLeaveGuard.disarm();
              window.location.href = gcashResult.redirect_url;
              return;
            }
            if (canCleanupUploads) {
              await cleanupUploadedFiles(payload.uploaded_files);
            }
            setFeedback(gcashResult.error || "Unable to continue to payment.", "error");
            return;
          }

          if (!gcashResult.redirect_url) {
            setFeedback("Your payment draft was saved, but the payment page could not be opened.", "error");
            return;
          }

          if (typeof window.servitechToastForNavigation === "function") {
            window.servitechToastForNavigation("Complete your GCash payment details to submit the print order.", {
              tone: "info"
            });
          }
          if (window.servitechJoinQueueLeaveGuard) {
            window.servitechJoinQueueLeaveGuard.disarm();
          }
          window.location.href = gcashResult.redirect_url;
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

        if (!String(result.queue_code || "").toUpperCase().startsWith("P")) {
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

    var debouncedPricingUpdate = debounce(function () {
      updateOrderSummary();
    }, 80);

    fileUpload.addEventListener("change", function (e) {
      addFiles(e.target.files);
    });

    fileListEl.addEventListener("click", function (e) {
      var target = e.target;
      if (!target || target.tagName !== "BUTTON") return;
      if (target.dataset.uploadAction === "cancel" && activeUploadSession) {
        activeUploadSession.cancel(target.dataset.fileKey || "");
        return;
      }
      var index = parseInt(target.dataset.fileIndex || "-1", 10);
      if (!Number.isInteger(index) || index < 0) return;
      removeSelectedByIndex(index);
    });

    qtyInput.addEventListener("input", debouncedPricingUpdate);
    paperSizeSelect.addEventListener("change", debouncedPricingUpdate);
    paymentMethodSelect.addEventListener("change", updatePaymentUi);
    document.querySelectorAll('input[name="color"]').forEach(function (radio) {
      radio.addEventListener("change", debouncedPricingUpdate);
    });

    joinQueueBtn.addEventListener("click", handleJoinQueue, true);

    document.addEventListener("servitech:join-queue-completed", function () {
      selectedFiles = [];
      uploadedSignature = "";
      analysisRequestSeq++;
      isAnalyzingFiles = false;
      uploadTasks = {};
      activeUploadSession = null;
      fileAnalysisCache = {};
      state.files = [];
      state.file_names = [];
      state.uploaded_files = [];
      state.total_files = 0;
      state.total_images = 0;
      state.total_pages = 0;
      state.price_per_page = 0;
      state.estimated_total = 0;
      state.error = "";
      syncFileInput();
      renderList();
      updateOrderSummary();
    });

    restoreDraft();
    resetAnalysis(true);
    updateOrderSummary();
    updatePaymentUi();
  });
})();


