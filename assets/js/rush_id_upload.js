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

  document.addEventListener("DOMContentLoaded", function () {
    var body = document.body;
    if (!body || body.dataset.service !== "printing" || !document.getElementById("packageSelect")) return;

    var fileUpload = document.getElementById("fileUpload");
    var fileListEl = document.getElementById("fileAnalysisList");
    var fileMetaEl = document.getElementById("fileAnalysisMeta");
    var fileUploadStatus = document.getElementById("fileUploadStatus");
    var feedbackEl = document.getElementById("formFeedback");

    if (!fileUpload || !fileListEl || !fileMetaEl) return;

    var allowedExt = {
      pdf: true,
      doc: true,
      docx: true,
      ppt: true,
      pptx: true,
      jpg: true,
      jpeg: true,
      png: true
    };
    var maxFileSize = 20 * 1024 * 1024;
    var selectedFiles = [];
    var uploadedSignature = "";
    var uploadedFiles = [];

    function getExt(filename) {
      var dot = filename.lastIndexOf(".");
      return dot >= 0 ? filename.slice(dot + 1).toLowerCase() : "";
    }

    function fileKey(file) {
      return [(file.name || "").toLowerCase(), String(file.size || 0), String(file.lastModified || 0)].join("|");
    }

    function currentSignature() {
      return selectedFiles.map(fileKey).sort().join("::");
    }

    function setFeedback(message, tone) {
      if (feedbackEl) {
        feedbackEl.textContent = "";
        feedbackEl.hidden = true;
        feedbackEl.classList.remove("error", "success");
      }
      if (!message) return;

      if (typeof window.servitechToast === "function") {
        window.servitechToast(message, { tone: tone || "info" });
        return;
      }

      if (!feedbackEl) return;
      feedbackEl.hidden = false;
      feedbackEl.textContent = message;
      feedbackEl.classList.add(tone === "success" ? "success" : "error");
    }

    function syncFileInput() {
      var dt = new DataTransfer();
      selectedFiles.forEach(function (file) {
        dt.items.add(file);
      });
      fileUpload.files = dt.files;
    }

    function renderList() {
      fileListEl.innerHTML = "";

      if (!selectedFiles.length) {
        fileMetaEl.textContent = "No files uploaded yet.";
        if (fileUploadStatus) fileUploadStatus.textContent = "No files selected yet.";
        return;
      }

      selectedFiles.forEach(function (file, index) {
        var li = document.createElement("li");
        var info = document.createElement("span");
        var removeBtn = document.createElement("button");

        info.textContent = file.name + " (" + getExt(file.name).toUpperCase() + ")";
        removeBtn.type = "button";
        removeBtn.textContent = "Remove";
        removeBtn.dataset.fileIndex = String(index);
        removeBtn.setAttribute("aria-label", "Remove " + file.name);

        li.appendChild(info);
        li.appendChild(removeBtn);
        fileListEl.appendChild(li);
      });

      fileMetaEl.textContent = selectedFiles.length + (selectedFiles.length === 1 ? " file ready" : " files ready");
      if (fileUploadStatus) {
        fileUploadStatus.textContent = selectedFiles.length + (selectedFiles.length === 1 ? " file selected." : " files selected.");
      }
    }

    function addFiles(incoming) {
      var errors = [];
      var seen = {};

      selectedFiles.forEach(function (file) {
        seen[fileKey(file)] = true;
      });

      Array.from(incoming || []).forEach(function (file) {
        var ext = getExt(file.name);
        var key = fileKey(file);

        if (!allowedExt[ext]) {
          errors.push(file.name + " has unsupported file type.");
          return;
        }

        if ((file.size || 0) > maxFileSize) {
          errors.push(file.name + " exceeds 20MB limit.");
          return;
        }

        if (seen[key]) {
          errors.push(file.name + " is already selected.");
          return;
        }

        seen[key] = true;
        selectedFiles.push(file);
      });

      uploadedSignature = "";
      uploadedFiles = [];
      syncFileInput();
      renderList();
      setFeedback(errors.join(" "), errors.length ? "error" : "success");
    }

    async function uploadSelectedFiles() {
      if (!selectedFiles.length) {
        return { ok: false, error: "Upload at least one file." };
      }

      var signature = currentSignature();
      if (signature && signature === uploadedSignature && uploadedFiles.length) {
        return buildUploadPayload(true);
      }

      var formData = new FormData();
      selectedFiles.forEach(function (file) {
        formData.append("files[]", file, file.name);
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
        body: formData
      });

      var raw = await res.text();
      var data;
      try {
        data = JSON.parse(raw);
      } catch (err) {
        data = { success: false, message: "Server returned invalid response." };
      }

      if (!data.success) {
        var message = data.message || "File upload failed.";
        if (Array.isArray(data.errors) && data.errors.length) {
          message += " " + data.errors.join(" ");
        }
        return { ok: false, error: message };
      }

      uploadedFiles = Array.isArray(data.uploaded_files) ? data.uploaded_files : [];
      uploadedSignature = signature;
      return buildUploadPayload(false);
    }

    function buildUploadPayload(reused) {
      var fileNames = selectedFiles.map(function (file) {
        return file.name;
      });

      return {
        ok: true,
        reused: !!reused,
        payload: {
          file_name: fileNames[0] || null,
          file_names: fileNames,
          total_files: fileNames.length,
          total_images: selectedFiles.filter(function (file) {
            return /^(jpg|jpeg|png)$/i.test(getExt(file.name));
          }).length,
          file_analysis: selectedFiles.map(function (file) {
            return {
              file_name: file.name,
              file_type: getExt(file.name)
            };
          }),
          uploaded_files: uploadedFiles
        }
      };
    }

    fileUpload.addEventListener("change", function (event) {
      addFiles(event.target.files);
    });

    fileListEl.addEventListener("click", function (event) {
      var target = event.target;
      if (!target || target.tagName !== "BUTTON") return;

      var index = parseInt(target.dataset.fileIndex || "-1", 10);
      if (!Number.isInteger(index) || index < 0) return;

      selectedFiles = selectedFiles.filter(function (file, fileIndex) {
        return fileIndex !== index;
      });
      uploadedSignature = "";
      uploadedFiles = [];
      syncFileInput();
      renderList();
      setFeedback("", "error");
    });

    window.servitechBeforeQueueSubmit = uploadSelectedFiles;
    window.servitechResetUploadedFiles = function () {
      uploadedSignature = "";
      uploadedFiles = [];
    };

    renderList();
  });
})();
