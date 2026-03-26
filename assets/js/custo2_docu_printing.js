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
    var t = null;
    return function () {
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () {
        fn.apply(null, args);
      }, wait);
    };
  }

  document.addEventListener("DOMContentLoaded", function () {
    var body = document.body;
    if (!body || body.dataset.service !== "printing") return;

    var fileUpload = document.getElementById("fileUpload");
    var qtyInput = document.getElementById("qtyInput");
    var paperSizeSelect = document.getElementById("paperSizeSelect");
    var summaryPaperSize = document.getElementById("summaryPaperSize");
    var summaryQty = document.getElementById("summaryQty");
    var summaryTotalPages = document.getElementById("summaryTotalPages");
    var summaryPricePerPage = document.getElementById("summaryPricePerPage");
    var summaryTotal = document.getElementById("summaryTotal");
    var fileAnalysisList = document.getElementById("fileAnalysisList");
    var fileAnalysisMeta = document.getElementById("fileAnalysisMeta");
    var feedbackEl = document.getElementById("formFeedback");

    if (!fileUpload || !qtyInput || !paperSizeSelect || !fileAnalysisList || !fileAnalysisMeta) return;

    var state = {
      files: [],
      total_files: 0,
      total_images: 0,
      total_pages: 0,
      price_per_page: 0,
      estimated_total: 0,
      error: "",
      has_analysis: false,
    };

    window.servitechPrintingState = state;

    function getSelectedColor() {
      var checked = document.querySelector('input[name="color"]:checked');
      return checked ? checked.value : "";
    }

    function getQuantity() {
      var qty = parseInt(qtyInput.value, 10);
      if (!Number.isFinite(qty) || qty < 1) return 1;
      return qty;
    }

    function setFeedback(message, tone) {
      if (!feedbackEl) return;
      feedbackEl.textContent = message || "";
      feedbackEl.classList.remove("error", "success");
      if (message) {
        feedbackEl.classList.add(tone === "success" ? "success" : "error");
      }
    }

    function renderSummary() {
      var size = paperSizeSelect.value;
      if (summaryPaperSize) {
        summaryPaperSize.textContent = size && size !== "Select paper size" ? size : "Not Selected";
      }
      if (summaryQty) summaryQty.textContent = String(getQuantity());
      if (summaryTotalPages) summaryTotalPages.textContent = String(state.total_pages || 0);
      if (summaryPricePerPage) summaryPricePerPage.textContent = toPeso(state.price_per_page || 0);
      if (summaryTotal) summaryTotal.textContent = toPeso(state.estimated_total || 0);
    }

    function renderFileList() {
      fileAnalysisList.innerHTML = "";

      if (!state.files.length) {
        fileAnalysisMeta.textContent = "No files uploaded yet.";
        return;
      }

      state.files.forEach(function (f) {
        var li = document.createElement("li");
        var type = (f.file_type || "").toUpperCase();
        if (typeof f.slide_count !== "undefined") {
          li.textContent = f.file_name + " (" + type + "): " + f.slide_count + " slide(s)";
        } else {
          li.textContent = f.file_name + " (" + type + "): " + (f.page_count || 0) + " page(s)";
        }
        fileAnalysisList.appendChild(li);
      });

      fileAnalysisMeta.textContent =
        "Files: " + state.total_files +
        " | Images: " + state.total_images +
        " | Total Pages: " + state.total_pages;
    }

    async function callAnalyzer(formData) {
      var csrf = (window.servitechCsrfToken && window.servitechCsrfToken()) || "";
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
      } catch (err) {
        data = { ok: false, error: "Server returned invalid response." };
      }
      return data;
    }

    function resetState(msg) {
      state.files = [];
      state.total_files = 0;
      state.total_images = 0;
      state.total_pages = 0;
      state.price_per_page = 0;
      state.estimated_total = 0;
      state.error = msg || "";
      state.has_analysis = false;
      renderFileList();
      renderSummary();
    }

    function applySuccess(data, keepFiles) {
      if (!keepFiles) {
        state.files = Array.isArray(data.files) ? data.files : [];
        state.total_files = Number(data.total_files) || 0;
        state.total_images = Number(data.total_images) || 0;
        state.total_pages = Number(data.total_pages) || 0;
      }

      state.price_per_page = Number(data.price_per_page) || 0;
      state.estimated_total = Number(data.estimated_total) || 0;
      state.error = "";
      state.has_analysis = true;
      renderFileList();
      renderSummary();
      setFeedback("", "error");
    }

    function applyError(data) {
      var message = (data && data.error) ? data.error : "Unable to analyze files.";
      if (data && Array.isArray(data.unsupported_files) && data.unsupported_files.length) {
        message += " Unsupported: " + data.unsupported_files.join(", ") + ".";
      }

      if (data && Array.isArray(data.files)) {
        state.files = data.files;
      }
      if (data && Number.isFinite(Number(data.total_files))) {
        state.total_files = Number(data.total_files);
      }
      if (data && Number.isFinite(Number(data.total_images))) {
        state.total_images = Number(data.total_images);
      }
      if (data && Number.isFinite(Number(data.total_pages))) {
        state.total_pages = Number(data.total_pages);
        state.has_analysis = state.total_pages > 0;
      }

      state.error = message;
      state.price_per_page = 0;
      state.estimated_total = 0;
      renderFileList();
      renderSummary();
      setFeedback(message, "error");
    }

    function buildCommonData(fd) {
      fd.append("paper_size", paperSizeSelect.value || "");
      fd.append("color_option", getSelectedColor());
      fd.append("quantity", String(getQuantity()));
    }

    async function analyzeUploadedFiles() {
      var files = Array.from(fileUpload.files || []);
      if (!files.length) {
        resetState("No files uploaded.");
        setFeedback("Upload at least one supported file.", "error");
        return;
      }

      fileAnalysisMeta.textContent = "Analyzing files...";

      var formData = new FormData();
      buildCommonData(formData);
      files.forEach(function (file) {
        formData.append("files[]", file, file.name);
      });

      try {
        var data = await callAnalyzer(formData);
        if (!data || !data.ok) {
          applyError(data || {});
          return;
        }

        applySuccess(data, false);
      } catch (err) {
        applyError({ error: "Network/server error while analyzing files." });
      }
    }

    async function refreshPricingOnly() {
      renderSummary();

      if (!fileUpload.files || !fileUpload.files.length) return;
      if (!state.has_analysis || state.total_pages < 1) return;

      var formData = new FormData();
      buildCommonData(formData);
      formData.append("total_pages", String(state.total_pages));
      formData.append("total_files", String(state.total_files));
      formData.append("total_images", String(state.total_images));

      try {
        var data = await callAnalyzer(formData);
        if (!data || !data.ok) {
          applyError(data || {});
          return;
        }
        applySuccess(data, true);
      } catch (err) {
        applyError({ error: "Network/server error while updating pricing." });
      }
    }

    var debouncedRefresh = debounce(refreshPricingOnly, 220);

    fileUpload.addEventListener("change", analyzeUploadedFiles);
    qtyInput.addEventListener("input", debouncedRefresh);
    paperSizeSelect.addEventListener("change", debouncedRefresh);

    document.querySelectorAll('input[name="color"]').forEach(function (radio) {
      radio.addEventListener("change", debouncedRefresh);
    });

    renderFileList();
    renderSummary();
  });
})();

