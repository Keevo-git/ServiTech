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
  const toggle = createElement();

  root.dataset.cookiePath = "/ServiTech/";
  root.querySelector = (selector) => ({
    "[data-cookie-banner]": banner,
    "[data-cookie-modal]": modal,
    ".cookie-consent__dialog": dialog,
    "[data-cookie-functional-toggle]": toggle
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
      return selector === "[data-cookie-consent-root]" ? root : null;
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
    localStorage,
    location: {
      hash: "",
      hostname: "localhost",
      protocol: "http:"
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
    api: window.servitechCookieConsent,
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
          return name === "data-cookie-preferences-open";
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

  firstVisit.api.rejectNonEssential();
  assert.strictEqual(firstVisit.api.hasChoice(), true);
  assert.strictEqual(firstVisit.api.allows("functional"), false);

  const returningRejected = createBrowserState(shared);
  assert.strictEqual(returningRejected.banner.hidden, true, "saved rejection should suppress banner");
  assert.strictEqual(returningRejected.api.allows("functional"), false);
}

{
  const shared = newSharedState();
  const firstVisit = createBrowserState(shared);
  let allowedCount = 0;
  let blockedCount = 0;
  firstVisit.api.whenAllowed(
    "functional",
    () => {
      allowedCount += 1;
    },
    () => {
      blockedCount += 1;
    }
  );

  firstVisit.api.acceptAll();
  assert.strictEqual(allowedCount, 1, "acceptance should enable optional behavior");

  const returningAccepted = createBrowserState(shared);
  assert.strictEqual(returningAccepted.banner.hidden, true, "saved acceptance should suppress banner");
  assert.strictEqual(returningAccepted.api.allows("functional"), true);

  firstVisit.api.savePreferences({ functional: false });
  assert.ok(blockedCount >= 2, "revocation should notify optional behavior to stop");

  const returningChanged = createBrowserState(shared);
  assert.strictEqual(returningChanged.banner.hidden, true, "changed preference should remain saved");
  assert.strictEqual(returningChanged.api.allows("functional"), false);
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
  assert.strictEqual(migratedVisit.api.allows("functional"), true);
  assert.ok(shared.cookies.SERVITECH_COOKIE_CONSENT, "missing cookie should be repaired");
}

console.log("Cookie consent scenarios passed.");
