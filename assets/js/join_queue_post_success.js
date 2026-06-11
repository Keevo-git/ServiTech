(function () {
  "use strict";

  var storageKey = "servitechJoinQueueCompleted";
  var safePath = "/pages/customer/custo_place_queueing.php";

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

  function markComplete(queueCode) {
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
    markComplete: markComplete,
    safeUrl: safeUrl
  };

  if (hasCompletion()) {
    goToChooseService();
    return;
  }

  window.addEventListener("pageshow", function (event) {
    if (event.persisted && hasCompletion()) {
      goToChooseService();
    }
  });

  window.addEventListener("popstate", function () {
    if (hasCompletion()) {
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
