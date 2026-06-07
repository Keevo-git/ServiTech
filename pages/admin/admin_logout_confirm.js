(function () {
  if (window.ServiTechAdminLogoutConfirmInitialized) return;
  window.ServiTechAdminLogoutConfirmInitialized = true;

  var activeModal = null;
  var previousFocus = null;
  var scrollLockState = null;

  function isAdminLogoutLink(link) {
    if (!link || !link.href) return false;

    var href = link.getAttribute("href") || "";
    return (
      link.classList.contains("admin-logout-link") ||
      href.indexOf("/pages/admin/logout.php") !== -1
    );
  }

  function ensureStyles() {
    if (document.getElementById("servitech-admin-logout-confirm-styles")) return;

    var style = document.createElement("style");
    style.id = "servitech-admin-logout-confirm-styles";
    style.textContent = [
      "body.admin-logout-confirm-open{overflow:hidden!important;}",
      ".admin-logout-confirm-overlay{position:fixed!important;inset:0!important;z-index:2147483200!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:clamp(16px,4vw,32px)!important;background:rgba(8,21,39,.66)!important;}",
      ".admin-logout-confirm-modal{box-sizing:border-box!important;width:min(100%,420px)!important;max-height:calc(100dvh - 32px)!important;overflow-y:auto!important;padding:clamp(22px,4vw,30px)!important;border:1px solid rgba(26,63,115,.18)!important;border-radius:18px!important;background:#fff!important;color:#112338!important;box-shadow:0 26px 74px rgba(10,27,49,.42)!important;text-align:left!important;font-family:inherit!important;}",
      ".admin-logout-confirm-modal__header{margin-bottom:10px!important;}",
      ".admin-logout-confirm-modal h2{margin:0!important;color:#112b4f!important;font-size:clamp(22px,5vw,26px)!important;line-height:1.2!important;letter-spacing:0!important;font-weight:800!important;}",
      ".admin-logout-confirm-modal p{margin:0!important;color:#5d6f86!important;font-size:15px!important;line-height:1.55!important;}",
      ".admin-logout-confirm-modal__actions{display:flex!important;justify-content:flex-end!important;gap:12px!important;margin-top:24px!important;}",
      ".admin-logout-confirm-modal__button{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:auto!important;min-width:112px!important;min-height:46px!important;margin:0!important;padding:11px 18px!important;border-radius:12px!important;font-size:15px!important;font-weight:800!important;line-height:1.2!important;cursor:pointer!important;text-decoration:none!important;transition:background-color .2s ease,border-color .2s ease,color .2s ease,transform .18s ease,box-shadow .2s ease!important;}",
      ".admin-logout-confirm-modal__button:hover{transform:translateY(-1px)!important;}",
      ".admin-logout-confirm-modal__button:active{transform:translateY(0)!important;}",
      ".admin-logout-confirm-modal__button:focus-visible{outline:2px solid rgba(36,168,188,.9)!important;outline-offset:2px!important;}",
      ".admin-logout-confirm-modal__button--cancel{border:1px solid #cbd8e8!important;background:#f5f9ff!important;color:#17365f!important;box-shadow:none!important;}",
      ".admin-logout-confirm-modal__button--cancel:hover{border-color:#adc4df!important;background:#eaf3ff!important;}",
      ".admin-logout-confirm-modal__button--confirm{border:1px solid rgba(255,190,198,.72)!important;background:#b91c1c!important;color:#fff!important;box-shadow:0 9px 18px rgba(185,28,28,.22)!important;}",
      ".admin-logout-confirm-modal__button--confirm:hover{border-color:rgba(255,210,216,.9)!important;background:#dc2626!important;}",
      "@media (max-width:520px){.admin-logout-confirm-overlay{padding:14px!important;}.admin-logout-confirm-modal{width:100%!important;padding:22px 18px!important;border-radius:16px!important;}.admin-logout-confirm-modal__actions{display:grid!important;grid-template-columns:1fr 1fr!important;gap:10px!important;}.admin-logout-confirm-modal__button{width:100%!important;min-width:0!important;padding-right:12px!important;padding-left:12px!important;}}",
      "@media (max-width:360px){.admin-logout-confirm-modal__actions{grid-template-columns:1fr!important;}}"
    ].join("");

    (document.head || document.documentElement).appendChild(style);
  }

  function closeModal() {
    if (!activeModal) return;

    document.removeEventListener("keydown", handleKeydown);
    document.body.classList.remove("admin-logout-confirm-open");
    restoreScrollLock();
    activeModal.remove();
    activeModal = null;

    if (previousFocus && typeof previousFocus.focus === "function") {
      previousFocus.focus({ preventScroll: true });
    }
    previousFocus = null;
  }

  function applyScrollLock() {
    if (scrollLockState) return;

    var body = document.body;
    var scrollbarWidth = Math.max(0, window.innerWidth - document.documentElement.clientWidth);

    scrollLockState = {
      paddingRight: body.style.paddingRight
    };

    if (scrollbarWidth > 0) {
      var currentPadding = parseFloat(window.getComputedStyle(body).paddingRight) || 0;
      body.style.paddingRight = (currentPadding + scrollbarWidth) + "px";
    }
  }

  function restoreScrollLock() {
    if (!scrollLockState) return;

    document.body.style.paddingRight = scrollLockState.paddingRight;
    scrollLockState = null;
  }

  function handleKeydown(event) {
    if (!activeModal) return;

    if (event.key === "Escape") {
      event.preventDefault();
      closeModal();
      return;
    }

    if (event.key !== "Tab") return;

    var focusable = activeModal.querySelectorAll(
      'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
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
  }

  function openModal(link) {
    closeModal();
    ensureStyles();

    previousFocus = link;

    var logoutUrl = link.href;
    var overlay = document.createElement("div");
    overlay.className = "admin-logout-confirm-overlay";
    overlay.innerHTML =
      '<section class="admin-logout-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="admin-logout-confirm-title" aria-describedby="admin-logout-confirm-message" tabindex="-1">' +
        '<div class="admin-logout-confirm-modal__header">' +
          '<h2 id="admin-logout-confirm-title">Confirm Logout</h2>' +
        "</div>" +
        '<p id="admin-logout-confirm-message">Are you sure you want to log out?</p>' +
        '<div class="admin-logout-confirm-modal__actions">' +
          '<button type="button" class="admin-logout-confirm-modal__button admin-logout-confirm-modal__button--cancel" data-admin-logout-cancel>Cancel</button>' +
          '<button type="button" class="admin-logout-confirm-modal__button admin-logout-confirm-modal__button--confirm" data-admin-logout-confirm>Logout</button>' +
        "</div>" +
      "</section>";

    overlay.addEventListener("click", function (event) {
      if (event.target === overlay) closeModal();
    });

    overlay.querySelector("[data-admin-logout-cancel]").addEventListener("click", closeModal);
    overlay.querySelector("[data-admin-logout-confirm]").addEventListener("click", function () {
      window.location.href = logoutUrl;
    });

    document.body.appendChild(overlay);
    applyScrollLock();
    document.body.classList.add("admin-logout-confirm-open");
    activeModal = overlay;
    document.addEventListener("keydown", handleKeydown);

    overlay.querySelector("[data-admin-logout-cancel]").focus({ preventScroll: true });
  }

  document.addEventListener("click", function (event) {
    var link = event.target.closest ? event.target.closest("a") : null;
    if (!isAdminLogoutLink(link)) return;

    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    openModal(link);
  }, true);
})();
