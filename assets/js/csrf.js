(function () {
  function readCookie(name) {
    var pattern = "(?:^|; )" + name.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, "\\$&") + "=([^;]*)";
    var match = document.cookie.match(new RegExp(pattern));
    return match ? decodeURIComponent(match[1]) : "";
  }

  window.servitechCsrfToken = function () {
    return readCookie("SERVITECH_CSRF");
  };
})();
