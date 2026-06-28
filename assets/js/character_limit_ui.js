(function () {
  "use strict";

  if (window.ServiTechCharacterLimitUiInitialized) {
    return;
  }
  window.ServiTechCharacterLimitUiInitialized = true;

  var STYLE_ID = "servitech-character-limit-ui-style";
  var HINT_CLASS = "character-limit-hint";
  var FIELD_SELECTOR = "textarea[maxlength], input[maxlength]";
  var INPUT_TYPES = new Set(["", "text", "email", "search", "url"]);

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) {
      return;
    }

    var style = document.createElement("style");
    style.id = STYLE_ID;
    style.textContent = [
      "." + HINT_CLASS + "{",
      "  display:flex;",
      "  justify-content:flex-end;",
      "  margin-top:4px;",
      "  color:#64748b;",
      "  font-size:12px;",
      "  font-weight:600;",
      "  line-height:1.35;",
      "}",
      "." + HINT_CLASS + ".is-at-limit{color:#9a3412;}",
      "." + HINT_CLASS + ".is-over-limit{color:#991b1b;}",
      "@media (max-width:520px){." + HINT_CLASS + "{font-size:11px;}}"
    ].join("\n");
    document.head.appendChild(style);
  }

  function fieldType(field) {
    return String(field.getAttribute("type") || "").toLowerCase();
  }

  function shouldEnhance(field) {
    if (!field || field.dataset.limitUiInitialized === "true") {
      return false;
    }

    if (field.dataset.limitUi === "off" || field.hasAttribute("data-character-count")) {
      return false;
    }

    if (field.disabled || field.readOnly || field.getAttribute("type") === "hidden") {
      return false;
    }

    if (field.tagName === "INPUT" && !INPUT_TYPES.has(fieldType(field))) {
      return field.dataset.limitUi === "on";
    }

    if (
      field.tagName === "INPUT" &&
      field.dataset.limitUi !== "on" &&
      String(field.getAttribute("inputmode") || "").toLowerCase() === "numeric" &&
      String(field.getAttribute("pattern") || "").trim() !== ""
    ) {
      return false;
    }

    var maxLength = Number(field.getAttribute("maxlength") || 0);
    return Number.isFinite(maxLength) && maxLength > 0;
  }

  function textLength(value) {
    return Array.from(String(value || "")).length;
  }

  function hintId(field) {
    if (field.id) {
      return field.id + "LimitHint";
    }
    return "limitHint" + Math.random().toString(36).slice(2, 10);
  }

  function addDescribedBy(field, id) {
    var describedBy = String(field.getAttribute("aria-describedby") || "").trim();
    var parts = describedBy ? describedBy.split(/\s+/) : [];
    if (!parts.includes(id)) {
      parts.push(id);
      field.setAttribute("aria-describedby", parts.join(" "));
    }
  }

  function displayMode(field) {
    var configured = String(field.dataset.limitDisplay || "").toLowerCase();
    if (configured === "static" || configured === "counter") {
      return configured;
    }
    return field.tagName === "TEXTAREA" ? "counter" : "static";
  }

  function updateHint(field, hint, maxLength) {
    var length = textLength(field.value);
    hint.textContent = length + "/" + maxLength;
    hint.classList.toggle("is-at-limit", length === maxLength);
    hint.classList.toggle("is-over-limit", length > maxLength);
  }

  function enhance(field) {
    if (!shouldEnhance(field)) {
      return;
    }

    var maxLength = Number(field.getAttribute("maxlength") || 0);
    if (!Number.isFinite(maxLength) || maxLength <= 0) {
      return;
    }

    var hint = document.createElement("span");
    hint.className = HINT_CLASS;
    hint.id = hintId(field);
    hint.setAttribute("aria-live", "polite");

    field.insertAdjacentElement("afterend", hint);
    addDescribedBy(field, hint.id);
    field.dataset.limitUiInitialized = "true";

    var refresh = function () {
      updateHint(field, hint, maxLength);
    };

    field.addEventListener("input", refresh);
    field.addEventListener("focus", refresh);
    field.addEventListener("blur", refresh);
    refresh();
  }

  function enhanceWithin(root) {
    if (!root || !root.querySelectorAll) {
      return;
    }

    if (root.matches && root.matches(FIELD_SELECTOR)) {
      enhance(root);
    }

    root.querySelectorAll(FIELD_SELECTOR).forEach(enhance);
  }

  function init() {
    injectStyles();
    enhanceWithin(document);

    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        mutation.addedNodes.forEach(function (node) {
          if (node.nodeType === 1) {
            enhanceWithin(node);
          }
        });
      });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
