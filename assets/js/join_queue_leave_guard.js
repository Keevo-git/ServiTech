(function () {
  "use strict";

  function ready(callback) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback, { once: true });
      return;
    }
    callback();
  }

  ready(function () {
    var modal = document.getElementById("joinQueueLeaveModal");
    var formPage = document.querySelector(".form-page");
    if (!modal || !formPage || !document.body.classList.contains("customer-layout")) {
      return;
    }

    var dialog = modal.querySelector(".join-queue-leave-modal");
    var stayButton = modal.querySelector("[data-leave-stay]");
    var confirmButton = modal.querySelector("[data-leave-confirm]");
    var backUrl = modal.getAttribute("data-back-url") || "";
    var controls = Array.prototype.slice.call(
      formPage.querySelectorAll("input, select, textarea")
    ).filter(function (control) {
      return control.type !== "button" && control.type !== "submit" && control.type !== "hidden";
    });
    var backLinks = Array.prototype.slice.call(
      formPage.querySelectorAll(".btn-back[href]")
    ).filter(function (link) {
      return link.getAttribute("href") === backUrl;
    });

    var active = true;
    var baseline = "";
    var pendingNavigation = null;
    var lastFocusedElement = null;
    var previousOverflow = "";
    var guardStateKey = "servitechJoinQueueGuard";
    var pageKey = window.location.pathname + window.location.search;

    function controlValue(control) {
      if (control.type === "checkbox" || control.type === "radio") {
        return control.checked ? "1" : "0";
      }

      if (control.type === "file") {
        return Array.prototype.map.call(control.files || [], function (file) {
          return [file.name, file.size, file.lastModified].join(":");
        }).join("|");
      }

      return control.value;
    }

    function snapshot() {
      return JSON.stringify(controls.map(function (control, index) {
        return [
          control.id || control.name || String(index),
          controlValue(control)
        ];
      }));
    }

    function isDirty() {
      return active && snapshot() !== baseline;
    }

    function pushHistoryCheckpoint() {
      var state = history.state || {};
      if (state[guardStateKey] === pageKey) {
        return;
      }

      var checkpoint = Object.assign({}, state);
      checkpoint[guardStateKey] = pageKey;
      history.pushState(checkpoint, "", window.location.href);
    }

    function setModalOpen(open) {
      modal.hidden = !open;
      if (open) {
        lastFocusedElement = document.activeElement;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        window.setTimeout(function () {
          if (stayButton) stayButton.focus();
        }, 0);
        return;
      }

      document.body.style.overflow = previousOverflow;
      if (lastFocusedElement && typeof lastFocusedElement.focus === "function") {
        lastFocusedElement.focus();
      }
    }

    function openWarning(navigation) {
      pendingNavigation = navigation;
      if (modal.hidden) {
        setModalOpen(true);
      }
    }

    function stayOnForm() {
      var wasHistoryBack = pendingNavigation === "history";
      pendingNavigation = null;
      setModalOpen(false);
      if (wasHistoryBack) {
        pushHistoryCheckpoint();
      }
    }

    function urlsMatchReferrer() {
      if (!document.referrer || !backUrl) return false;

      try {
        var referrer = new URL(document.referrer, window.location.href);
        var target = new URL(backUrl, window.location.href);
        return referrer.origin === target.origin
          && referrer.pathname === target.pathname
          && referrer.search === target.search;
      } catch (error) {
        return false;
      }
    }

    function disarm() {
      active = false;
      pendingNavigation = null;
      if (!modal.hidden) {
        setModalOpen(false);
      }
    }

    function confirmLeave() {
      var navigation = pendingNavigation;
      disarm();

      if (navigation === "history") {
        history.go(-2);
        return;
      }

      if (navigation === "link" && urlsMatchReferrer()) {
        history.go(-2);
        return;
      }

      window.location.href = backUrl;
    }

    backLinks.forEach(function (link) {
      link.addEventListener("click", function (event) {
        if (!isDirty()) {
          disarm();
          return;
        }

        event.preventDefault();
        openWarning("link");
      });
    });

    window.addEventListener("popstate", function () {
      if (!active) return;

      if (!isDirty()) {
        disarm();
        history.back();
        return;
      }

      pushHistoryCheckpoint();
      openWarning("history");
    });

    if (stayButton) {
      stayButton.addEventListener("click", stayOnForm);
    }

    if (confirmButton) {
      confirmButton.addEventListener("click", confirmLeave);
    }

    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        stayOnForm();
      }
    });

    dialog.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        event.preventDefault();
        stayOnForm();
        return;
      }

      if (event.key !== "Tab") return;

      var focusable = Array.prototype.slice.call(
        dialog.querySelectorAll("button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex='-1'])")
      );
      if (!focusable.length) return;

      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    window.servitechJoinQueueLeaveGuard = {
      disarm: disarm,
      isDirty: isDirty
    };

    window.setTimeout(function () {
      baseline = snapshot();
      pushHistoryCheckpoint();
    }, 0);
  });
})();
