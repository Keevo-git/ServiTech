const assert = require("assert");
const fs = require("fs");
const path = require("path");
const vm = require("vm");

const source = fs.readFileSync(
  path.join(__dirname, "..", "assets", "js", "cookie_consent.js"),
  "utf8"
);

function createClassList() {
  const values = new Set();
  return {
    add(value) {
      values.add(value);
    },
    remove(value) {
      values.delete(value);
    },
    contains(value) {
      return values.has(value);
    }
  };
}

function createElement() {
  return {
    hidden: true,
    dataset: {},
    classList: createClassList(),
    focus() {},
    querySelector() {
      return null;
    },
    querySelectorAll() {
      return [];
    }
  };
}

function createBrowserState(sharedState) {
  const listeners = {};
  const root = createElement();
  const banner = createElement();
  const modal = createElement();
  const dialog = createElement();

  root.dataset.storagePath = "/ServiTech/";
  root.querySelector = (selector) => ({
    "[data-privacy-notice]": banner,
    "[data-privacy-modal]": modal,
    ".site-privacy-controls__dialog": dialog
  }[selector] || null);

  const document = {
    activeElement: null,
    body: { classList: createClassList() },
    documentElement: { classList: createClassList() },
    readyState: "loading",
    addEventListener(type, listener) {
      listeners[type] = listeners[type] || [];
      listeners[type].push(listener);
    },
    contains() {
      return true;
    },
    querySelector(selector) {
      return selector === "[data-site-privacy-root]" ? root : null;
    }
  };

  Object.defineProperty(document, "cookie", {
    get() {
      return Object.entries(sharedState.cookies)
        .map(([name, value]) => `${name}=${value}`)
        .join("; ");
    },
    set(value) {
      const firstPart = value.split(";")[0];
      const separator = firstPart.indexOf("=");
      const name = firstPart.slice(0, separator);
      const cookieValue = firstPart.slice(separator + 1);
      if (/Max-Age=0/i.test(value)) {
        delete sharedState.cookies[name];
      } else {
        sharedState.cookies[name] = cookieValue;
      }
    }
  });

  const localStorage = {
    getItem(key) {
      return Object.prototype.hasOwnProperty.call(sharedState.local, key)
        ? sharedState.local[key]
        : null;
    },
    setItem(key, value) {
      sharedState.local[key] = String(value);
    }
  };

  const window = {
    addEventListener() {},
    history: {
      replaceState() {}
    },
    localStorage,
    location: {
      hash: "",
      hostname: "localhost",
      pathname: "/index.php",
      protocol: "http:"
      ,
      search: ""
    },
    setTimeout(callback) {
      callback();
    }
  };

  const context = {
    console,
    Date,
    document,
    HTMLElement: function HTMLElement() {},
    JSON,
    Number,
    RegExp,
    window
  };
  window.window = window;
  window.document = document;

  vm.runInNewContext(source, context, { filename: "cookie_consent.js" });

  return {
    api: window.servitechPrivacyControls,
    banner,
    modal,
    root,
    clickPreferencesLink() {
      const trigger = {
        closest() {
          return trigger;
        },
        getAttribute() {
          return null;
        },
        hasAttribute(name) {
          return name === "data-privacy-settings-open";
        }
      };
      const event = {
        target: trigger,
        preventDefault() {}
      };
      (listeners.click || []).forEach((listener) => listener(event));
    },
    clickAction(action) {
      const trigger = {
        closest() {
          return trigger;
        },
        getAttribute(name) {
          return name === "data-privacy-action" ? action : null;
        },
        hasAttribute() {
          return false;
        }
      };
      const event = {
        target: trigger,
        preventDefault() {}
      };
      (listeners.click || []).forEach((listener) => listener(event));
    }
  };
}

function newSharedState() {
  return { cookies: {}, local: {} };
}

{
  const shared = newSharedState();
  const firstVisit = createBrowserState(shared);
  assert.strictEqual(firstVisit.api.hasChoice(), false);
  assert.strictEqual(firstVisit.banner.hidden, false, "new visitor should see banner");
  firstVisit.clickPreferencesLink();
  assert.strictEqual(firstVisit.modal.hidden, false, "footer preferences link should open the modal");
  firstVisit.clickAction("close");
  assert.strictEqual(firstVisit.api.hasChoice(), true);
  assert.strictEqual(firstVisit.banner.hidden, true, "closing should continue with required settings");

  const returningVisit = createBrowserState(shared);
  assert.strictEqual(returningVisit.banner.hidden, true, "saved acknowledgement should suppress banner");
  returningVisit.clickPreferencesLink();
  assert.strictEqual(returningVisit.modal.hidden, false, "saved visitors can reopen preferences");
}

{
  const shared = {
    cookies: {
      SERVITECH_COOKIE_CONSENT: encodeURIComponent("{}")
    },
    local: {
      "servitech.cookieConsent": ""
    }
  };
  const invalidVisit = createBrowserState(shared);
  assert.strictEqual(invalidVisit.api.hasChoice(), false, "invalid storage must not count as a choice");
  assert.strictEqual(invalidVisit.banner.hidden, false, "invalid storage should show the first-visit banner");
}

{
  const shared = newSharedState();
  const firstVisit = createBrowserState(shared);
  firstVisit.clickAction("continue-required");
  assert.strictEqual(firstVisit.api.hasChoice(), true, "required-only choice should be saved");
  assert.strictEqual(firstVisit.api.allows("necessary"), true);
  assert.strictEqual(firstVisit.api.allows("functional"), false);

  const returningVisit = createBrowserState(shared);
  assert.strictEqual(returningVisit.banner.hidden, true, "saved choice should suppress banner");
}

{
  const shared = newSharedState();
  shared.local["servitech.cookieConsent"] = JSON.stringify({
    version: "2026-06-13",
    necessary: true,
    functional: true,
    updatedAt: "2026-06-13T00:00:00.000Z"
  });

  const migratedVisit = createBrowserState(shared);
  assert.strictEqual(migratedVisit.banner.hidden, true, "valid legacy choice should remain valid");
  assert.strictEqual(migratedVisit.api.allows("functional"), false, "unused legacy category must stay disabled");
  assert.ok(shared.cookies.SERVITECH_COOKIE_CONSENT, "missing cookie should be repaired");
}

console.log("Cookie consent scenarios passed.");
