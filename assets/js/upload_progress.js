(function () {
  function basePath() {
    if (typeof window.SERVITECH_BASE_PATH === "string" && window.SERVITECH_BASE_PATH.trim() !== "") {
      return window.SERVITECH_BASE_PATH.replace(/\/+$/, "");
    }
    var pathname = window.location.pathname || "";
    return pathname === "/ServiTech" || pathname.indexOf("/ServiTech/") === 0 ? "/ServiTech" : "";
  }

  function url(path) {
    return basePath() + (path.charAt(0) === "/" ? path : "/" + path);
  }

  function uploadId() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    var bytes = new Uint8Array(24);
    if (window.crypto && typeof window.crypto.getRandomValues === "function") {
      window.crypto.getRandomValues(bytes);
      return Array.from(bytes, function (value) {
        return value.toString(16).padStart(2, "0");
      }).join("");
    }
    return "upload-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2);
  }

  function fileKey(file) {
    return [
      String(file && file.name || "").toLowerCase(),
      String(file && file.size || 0),
      String(file && file.lastModified || 0)
    ].join("|");
  }

  function csrfToken() {
    return (window.servitechCsrfToken && window.servitechCsrfToken()) || "";
  }

  function snapshot(tasks) {
    return tasks.map(function (task) {
      return {
        id: task.id,
        key: task.key,
        file: task.file,
        status: task.status,
        progress: task.progress,
        message: task.message,
        metadata: task.metadata
      };
    });
  }

  function cancelOnServer(task) {
    return fetch(url("/api/upload_cancel.php"), {
      method: "POST",
      keepalive: true,
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-Token": csrfToken()
      },
      body: JSON.stringify({ upload_id: task.id })
    }).catch(function () {
      return null;
    });
  }

  function cleanupUploadedFiles(uploadedFiles) {
    if (!uploadedFiles.length) return Promise.resolve();
    return fetch(url("/api/upload_cleanup.php"), {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-Token": csrfToken()
      },
      body: JSON.stringify({ uploaded_files: uploadedFiles })
    }).catch(function () {
      return null;
    });
  }

  function start(files, options) {
    options = options || {};
    var onChange = typeof options.onChange === "function" ? options.onChange : function () {};
    var tasks = Array.from(files || []).map(function (file) {
      return {
        id: uploadId(),
        key: fileKey(file),
        file: file,
        status: "pending",
        progress: 0,
        message: "Waiting to upload...",
        metadata: null,
        xhr: null,
        cancelRequested: false
      };
    });

    function notify() {
      try {
        onChange(snapshot(tasks));
      } catch (error) {
        console.error("Upload progress UI update failed", error);
      }
    }

    function cancel(task) {
      if (!task || !["pending", "uploading", "processing"].includes(task.status)) {
        return Promise.resolve();
      }
      task.cancelRequested = true;
      task.status = "cancelling";
      task.message = "Cancelling...";
      notify();
      if (task.xhr) {
        task.xhr.abort();
        return Promise.resolve();
      }
      return cancelOnServer(task).then(function () {
        task.status = "cancelled";
        task.progress = 0;
        task.message = "File upload was cancelled.";
        notify();
      });
    }

    function upload(task) {
      return new Promise(function (resolve) {
        var xhr = new XMLHttpRequest();
        var formData = new FormData();
        task.xhr = xhr;
        formData.append("upload_id", task.id);
        if (options.context) formData.append("upload_context", options.context);
        formData.append("files[]", task.file, task.file.name);

        xhr.open("POST", url("/api/upload_handler.php"), true);
        xhr.withCredentials = true;
        xhr.setRequestHeader("Accept", "application/json");
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhr.setRequestHeader("X-CSRF-Token", csrfToken());

        xhr.upload.addEventListener("progress", function (event) {
          if (!event.lengthComputable || task.cancelRequested) return;
          task.status = "uploading";
          task.progress = Math.min(99, Math.round((event.loaded / event.total) * 100));
          task.message = "Uploading... " + task.progress + "%";
          notify();
        });

        xhr.upload.addEventListener("load", function () {
          if (task.cancelRequested) return;
          task.status = "processing";
          task.progress = 100;
          task.message = "Processing file...";
          notify();
        });

        xhr.addEventListener("load", function () {
          if (task.cancelRequested) {
            cancelOnServer(task).then(function () {
              task.status = "cancelled";
              task.progress = 0;
              task.message = "File upload was cancelled.";
              notify();
              resolve(task);
            });
            return;
          }

          var data = {};
          try {
            data = JSON.parse(xhr.responseText || "{}");
          } catch (error) {
            data = {};
          }
          if (xhr.status >= 200 && xhr.status < 300 && data.success && Array.isArray(data.uploaded_files) && data.uploaded_files[0]) {
            task.status = "success";
            task.progress = 100;
            task.message = "Uploaded.";
            task.metadata = data.uploaded_files[0];
          } else {
            var detail = Array.isArray(data.errors) && data.errors.length ? data.errors.join(" ") : "";
            task.status = data.cancelled ? "cancelled" : "error";
            task.message = detail || data.message || "Failed to upload file. Please try again.";
          }
          notify();
          resolve(task);
        });

        xhr.addEventListener("error", function () {
          task.status = "error";
          task.message = "Failed to upload file. Please check your connection and try again.";
          notify();
          resolve(task);
        });

        xhr.addEventListener("abort", function () {
          cancelOnServer(task).then(function () {
            task.status = "cancelled";
            task.progress = 0;
            task.message = "File upload was cancelled.";
            notify();
            resolve(task);
          });
        });

        task.status = "uploading";
        task.message = "Uploading... 0%";
        notify();
        try {
          xhr.send(formData);
        } catch (error) {
          task.status = "error";
          task.message = "Failed to start file upload. Please try again.";
          notify();
          resolve(task);
        }
      });
    }

    function cancelForNavigation() {
      tasks.forEach(function (task) {
        if (["pending", "uploading", "processing"].includes(task.status)) cancel(task);
      });
    }
    window.addEventListener("pagehide", cancelForNavigation, { once: true });

    var promise = Promise.all(tasks.map(upload)).then(function () {
      var failed = tasks.filter(function (task) {
        return task.status !== "success";
      });
      var uploadedFiles = tasks.map(function (task) {
        return task.metadata;
      }).filter(Boolean);

      if (!failed.length) {
        window.removeEventListener("pagehide", cancelForNavigation);
        return { ok: true, uploaded_files: uploadedFiles, tasks: snapshot(tasks) };
      }

      return cleanupUploadedFiles(uploadedFiles).then(function () {
        tasks.forEach(function (task) {
          if (task.status === "success") {
            task.status = "discarded";
            task.metadata = null;
            task.message = "Not saved because another file did not upload.";
          }
        });
        notify();
        window.removeEventListener("pagehide", cancelForNavigation);
        var cancelled = failed.some(function (task) { return task.status === "cancelled"; });
        return {
          ok: false,
          cancelled: cancelled,
          error: cancelled ? "File upload was cancelled." : failed[0].message,
          uploaded_files: [],
          tasks: snapshot(tasks)
        };
      });
    });

    notify();
    return {
      tasks: tasks,
      promise: promise,
      cancel: function (key) {
        var task = tasks.find(function (item) { return item.key === key; });
        return cancel(task);
      },
      cancelAll: function () {
        return Promise.all(tasks.map(cancel));
      }
    };
  }

  window.ServitechUpload = {
    cleanup: cleanupUploadedFiles,
    fileKey: fileKey,
    start: start
  };
})();
