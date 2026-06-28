(function () {
  "use strict";

  if (window.ServiTechCharacterLimitUiInitialized) {
    return;
  }
  window.ServiTechCharacterLimitUiInitialized = true;

  var STYLE_ID = "servitech-character-limit-ui-style";
  var HINT_CLASS = "character-limit-hint";
  var ROW_CLASS = "field-feedback-row";
  var FIELD_SELECTOR = "textarea[maxlength], input[maxlength]";
  var ERROR_SELECTORS = [".field-error", ".invalid-feedback", ".error-message", ".form-error", "[data-field-error]"];
  var ERROR_SELECTOR = ERROR_SELECTORS.join(", ");
  var FIELD_CONTAINER_SELECTOR = ".form-field, .field, .admin-owner-field, .employee-setup-field, .service-field, .ms-field";
  var INPUT_TYPES = new Set(["", "text", "email", "search", "url"]);
  var CONTEXT_SUPPRESS_TOKENS = ["filter", "filters", "search", "refine"];

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) {
      return;
    }

    var style = document.createElement("style");
    style.id = STYLE_ID;
    style.textContent = [
      "." + ROW_CLASS + "{",
      "  display:flex;",
      "  align-items:flex-start;",
      "  justify-content:space-between;",
      "  gap:6px 12px;",
      "  margin-top:4px;",
      "  min-height:18px;",
      "  width:100%;",
      "}",
      ERROR_SELECTORS.map(function (selector) { return "." + ROW_CLASS + ">" + selector; }).join(",") + "{",
      "  flex:1 1 auto;",
      "  min-width:0;",
      "}",
      ERROR_SELECTORS.map(function (selector) { return "." + ROW_CLASS + ">" + selector + ":empty"; }).join(",") + "{min-height:0;}",
      "." + HINT_CLASS + "{",
      "  display:flex;",
      "  flex:0 0 auto;",
      "  justify-content:flex-end;",
      "  margin-top:4px;",
      "  color:#64748b;",
      "  font-size:12px;",
      "  font-weight:600;",
      "  line-height:1.35;",
      "  white-space:nowrap;",
      "}",
      "." + ROW_CLASS + ">." + HINT_CLASS + "{",
      "  margin-top:0;",
      "  margin-left:auto;",
      "}",
      "." + HINT_CLASS + ".is-at-limit{color:#9a3412;}",
      "." + HINT_CLASS + ".is-over-limit{color:#991b1b;}",
      "@media (max-width:520px){",
      "  ." + ROW_CLASS + "{flex-wrap:wrap;}",
      "  " + ERROR_SELECTORS.map(function (selector) { return "." + ROW_CLASS + ">" + selector; }).join(",") + "{flex-basis:100%;}",
      "  ." + HINT_CLASS + "{font-size:11px;}",
      "}"
    ].join("\n");
    document.head.appendChild(style);
  }

  function fieldType(field) {
    return String(field.getAttribute("type") || "").toLowerCase();
  }

  function labelSelectorFor(id) {
    var escapedId = window.CSS && typeof window.CSS.escape === "function"
      ? window.CSS.escape(id)
      : String(id).replace(/\\/g, "\\\\").replace(/"/g, '\\"');
    return 'label[for="' + escapedId + '"]';
  }

  function textForLabels(field) {
    var labels = [];
    if (field.labels && field.labels.length) {
      labels = Array.from(field.labels);
    } else if (field.id) {
      labels = Array.from(document.querySelectorAll(labelSelectorFor(field.id)));
    }

    return labels.map(function (label) {
      return label.textContent || "";
    }).join(" ");
  }

  function tokenizedContextText(element) {
    var parts = [];
    ["id", "class", "role", "aria-label"].forEach(function (attribute) {
      parts.push(element.getAttribute(attribute) || "");
    });

    Array.from(element.attributes || []).forEach(function (attribute) {
      if (attribute.name.indexOf("data-") === 0) {
        parts.push(attribute.name, attribute.value || "");
      }
    });

    return parts.join(" ").toLowerCase();
  }

  function hasSuppressedContextToken(text) {
    return CONTEXT_SUPPRESS_TOKENS.some(function (token) {
      return text.indexOf(token) !== -1;
    });
  }

  function isSearchOrFilterContext(field) {
    if (fieldType(field) === "search") {
      return true;
    }

    var fieldText = [
      field.id || "",
      field.name || "",
      field.className || "",
      field.getAttribute("placeholder") || "",
      field.getAttribute("aria-label") || "",
      textForLabels(field)
    ].join(" ").toLowerCase();

    if (hasSuppressedContextToken(fieldText)) {
      return true;
    }

    var current = field.parentElement;
    while (current && current !== document.body && current !== document.documentElement) {
      if (hasSuppressedContextToken(tokenizedContextText(current))) {
        return true;
      }
      current = current.parentElement;
    }

    return false;
  }

  function shouldSuppressHint(field) {
    if (field.closest("#loginForm, [data-limit-ui-context='login']")) {
      return true;
    }

    return isSearchOrFilterContext(field);
  }

  function shouldEnhance(field) {
    if (!field || field.dataset.limitUiInitialized === "true") {
      return false;
    }

    if (field.dataset.limitUi === "off" || field.hasAttribute("data-character-count")) {
      return false;
    }

    if (shouldSuppressHint(field)) {
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

  function describedElements(field) {
    var describedBy = String(field.getAttribute("aria-describedby") || "").trim();
    if (!describedBy) {
      return [];
    }

    return describedBy.split(/\s+/).map(function (id) {
      return document.getElementById(id);
    }).filter(Boolean);
  }

  function isErrorElement(element) {
    return element && element.matches && element.matches(ERROR_SELECTOR);
  }

  function closestFieldContainer(field) {
    return field.closest(FIELD_CONTAINER_SELECTOR);
  }

  function findRelatedError(field) {
    var describedError = describedElements(field).find(isErrorElement);
    if (describedError) {
      return describedError;
    }

    var container = closestFieldContainer(field);
    if (!container) {
      return null;
    }

    var fieldId = field.id ? field.id.toLowerCase() : "";
    var errors = Array.from(container.querySelectorAll(ERROR_SELECTOR));
    if (fieldId) {
      var namedError = errors.find(function (error) {
        var errorId = String(error.id || "").toLowerCase();
        return errorId === fieldId + "error" || errorId.indexOf(fieldId) === 0;
      });
      if (namedError) {
        return namedError;
      }
    }

    return errors[0] || null;
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
    hint.textContent = length + " / " + maxLength;
    hint.classList.toggle("is-at-limit", length === maxLength);
    hint.classList.toggle("is-over-limit", length > maxLength);
  }

  function placeHint(field, hint) {
    var error = findRelatedError(field);
    if (!error) {
      field.insertAdjacentElement("afterend", hint);
      return;
    }

    var row = error.closest("." + ROW_CLASS);
    if (!row) {
      row = document.createElement("div");
      row.className = ROW_CLASS;
      error.insertAdjacentElement("beforebegin", row);
      row.appendChild(error);
    }

    row.appendChild(hint);
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

    placeHint(field, hint);
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
        if (
          mutation.type === "attributes" &&
          mutation.target &&
          mutation.target.nodeType === 1 &&
          mutation.target.matches &&
          mutation.target.matches(FIELD_SELECTOR)
        ) {
          enhance(mutation.target);
          return;
        }

        mutation.addedNodes.forEach(function (node) {
          if (node.nodeType === 1) {
            enhanceWithin(node);
          }
        });
      });
    });
    observer.observe(document.documentElement, {
      attributeFilter: ["disabled", "readonly", "maxlength", "data-limit-ui"],
      attributes: true,
      childList: true,
      subtree: true
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
