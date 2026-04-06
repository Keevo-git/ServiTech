(function () {
  document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("printOrderPaymentForm");
    var submitBtn = document.getElementById("placePrintOrderBtn");
    var referenceInput = document.getElementById("referenceNumberInput");
    var feedbackEl = document.getElementById("printPaymentFeedback");
    var paymentMethod = ((document.body && document.body.dataset.paymentMethod) || "").toLowerCase();

    if (!form || !submitBtn) {
      return;
    }

    function setFeedback(message, tone) {
      if (!feedbackEl) return;
      feedbackEl.textContent = message || "";
      feedbackEl.classList.remove("error", "success");
      if (message) {
        feedbackEl.classList.add(tone === "success" ? "success" : "error");
      }
    }

    function setFieldInvalid(el, invalid) {
      if (!el) return;
      el.classList.toggle("is-invalid", !!invalid);
    }

    form.addEventListener("submit", function (event) {
      if (submitBtn.disabled) {
        event.preventDefault();
        return;
      }

      setFeedback("", "error");
      setFieldInvalid(referenceInput, false);

      if (paymentMethod === "gcash") {
        var referenceNumber = referenceInput ? referenceInput.value.trim() : "";
        if (referenceNumber === "") {
          event.preventDefault();
          setFieldInvalid(referenceInput, true);
          setFeedback("Reference number is required for GCash payments.", "error");
          return;
        }
      }

      submitBtn.disabled = true;
      submitBtn.dataset.originalLabel = submitBtn.dataset.originalLabel || submitBtn.textContent;
      submitBtn.textContent = "Placing Print Order...";
      submitBtn.setAttribute("aria-busy", "true");
    });
  });
})();
