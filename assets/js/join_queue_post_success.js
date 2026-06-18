(function () {
  "use strict";

  var storageKey = "servitechJoinQueueCompleted";
  var safePath = "/pages/customer/customer_dash.php";

  function basePath() {
    if (typeof window.SERVITECH_BASE_PATH === "string" && window.SERVITECH_BASE_PATH.trim() !== "") {
      return window.SERVITECH_BASE_PATH.replace(/\/+$/, "");
    }
    return window.location.pathname.indexOf("/ServiTech/") === 0 ? "/ServiTech" : "";
  }

  function safeUrl() {
    return basePath() + safePath;
  }

  function readCompletion() {
    try {
      var value = window.sessionStorage.getItem(storageKey);
      return value ? JSON.parse(value) : null;
    } catch (error) {
      return null;
    }
  }

  function hasCompletion() {
    var completion = readCompletion();
    if (!completion || !completion.completedAt) return false;

    if (Number(completion.completedAt) < Date.now() - (2 * 60 * 60 * 1000)) {
      clearCompletion();
      return false;
    }
    return true;
  }

  function historyEntryIsComplete() {
    return !!(history.state && history.state.servitechJoinQueueCompleted);
  }

  function clearCompletion() {
    try {
      window.sessionStorage.removeItem(storageKey);
    } catch (error) {
      // The server-side completion marker still protects stale form revisits.
    }
  }

  function goToChooseService() {
    window.location.replace(safeUrl());
  }

  function clearSubmittedControls() {
    document.querySelectorAll(".form-page form").forEach(function (form) {
      form.reset();
    });

    document.querySelectorAll(".form-page input, .form-page select, .form-page textarea").forEach(function (control) {
      if (control.type === "hidden" || control.type === "button" || control.type === "submit") {
        return;
      }

      if (control.type === "checkbox" || control.type === "radio") {
        control.checked = control.defaultChecked;
      } else if (control.type === "file") {
        control.value = "";
      } else if (control.tagName === "SELECT") {
        var defaultIndex = Array.prototype.findIndex.call(control.options, function (option) {
          return option.defaultSelected;
        });
        control.selectedIndex = defaultIndex >= 0 ? defaultIndex : 0;
      } else {
        control.value = control.defaultValue;
      }

      control.classList.remove("is-invalid");
      control.removeAttribute("aria-invalid");
    });

    document.dispatchEvent(new CustomEvent("servitech:join-queue-completed"));
  }

  function markComplete(queueCode) {
    clearSubmittedControls();

    try {
      window.sessionStorage.setItem(storageKey, JSON.stringify({
        queueCode: String(queueCode || ""),
        completedAt: Date.now()
      }));
    } catch (error) {
      // History interception below still handles the current successful page.
    }

    var state = Object.assign({}, history.state || {});
    state.servitechJoinQueueCompleted = true;
    history.replaceState(state, "", window.location.href);
  }

  window.servitechJoinQueuePostSuccess = {
    clear: clearCompletion,
    goToChooseService: goToChooseService,
    isComplete: hasCompletion,
    isHistoryEntryComplete: historyEntryIsComplete,
    markComplete: markComplete,
    safeUrl: safeUrl
  };

  if (hasCompletion() || historyEntryIsComplete()) {
    goToChooseService();
    return;
  }

  window.addEventListener("pageshow", function (event) {
    if (event.persisted && (hasCompletion() || historyEntryIsComplete())) {
      goToChooseService();
    }
  });

  window.addEventListener("popstate", function () {
    if (hasCompletion() || historyEntryIsComplete()) {
      goToChooseService();
    }
  });

  document.addEventListener("click", function (event) {
    if (!hasCompletion()) return;

    var link = event.target.closest(".btn-back[href]");
    if (!link) return;

    event.preventDefault();
    goToChooseService();
  }, true);
})();
