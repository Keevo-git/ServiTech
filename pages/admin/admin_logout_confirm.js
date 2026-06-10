(function () {
  if (window.ServiTechAdminLogoutConfirmInitialized) return;
  window.ServiTechAdminLogoutConfirmInitialized = true;

  if (window.ServiTechLogoutConfirm && typeof window.ServiTechLogoutConfirm.init === "function") {
    window.ServiTechLogoutConfirm.init();
  }
})();
