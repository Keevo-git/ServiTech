(function () {
  document.addEventListener("DOMContentLoaded", function () {
    var body = document.body;
    var confirmedQueue = ((body && body.dataset.confirmedQueue) || "").trim();
    var queueHomeUrl = (body && body.dataset.queueHomeUrl) || "/pages/customer/customer_dash.php";
    var queueStatusUrl = (body && body.dataset.queueStatusUrl) || "/pages/customer/custo_service_status.php";
    var queueModal = document.getElementById("queueModal");
    var modalQueueNo = document.getElementById("modalQueueNo");
    var goHomeBtn = document.getElementById("goHomeBtn");
    var viewQueueBtn = document.getElementById("viewQueueBtn");

    if (confirmedQueue && queueModal && modalQueueNo) {
      if (typeof window.openQueueSuccessModal === "function") {
        window.openQueueSuccessModal(confirmedQueue, {
          service: "Online Document Printing",
          note: "Your payment details were submitted. You can check your queue status while the shop reviews your order."
        });
      } else {
        modalQueueNo.textContent = confirmedQueue;
        queueModal.style.display = "flex";
        document.body.classList.add("modal-open");
      }

      if (goHomeBtn) {
        goHomeBtn.addEventListener("click", function () {
          window.location.href = queueHomeUrl;
        });
      }

      if (viewQueueBtn) {
        viewQueueBtn.addEventListener("click", function () {
          window.location.href = queueStatusUrl;
        });
      }
    }

    var form = document.getElementById("printOrderPaymentForm");
    var submitBtn = document.getElementById("placePrintOrderBtn");
    var referenceInput = document.getElementById("referenceNumberInput");
    var initialFeedback = ((body && body.dataset.flashError) || "").trim();
    var paymentMethod = ((body && body.dataset.paymentMethod) || "").toLowerCase();

    if (!form || !submitBtn) {
      return;
    }

    function setFeedback(message, tone) {
      if (!message) return;

      if (typeof window.servitechToast === "function") {
        window.servitechToast(message, { tone: tone || "info" });
        return;
      }
      console.warn(message);
    }

    function setFieldInvalid(el, invalid) {
      if (!el) return;
      el.classList.toggle("is-invalid", !!invalid);
    }

    if (initialFeedback) {
      setFeedback(initialFeedback, "error");
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
        if (!/^\d{13}$/.test(referenceNumber)) {
          event.preventDefault();
          setFieldInvalid(referenceInput, true);
          setFeedback("GCash reference number must be exactly 13 digits.", "error");
          return;
        }
      }

      submitBtn.disabled = true;
      submitBtn.setAttribute("aria-busy", "true");
      if (typeof window.servitechToastForNavigation === "function") {
        window.servitechToastForNavigation("Submitting your payment details...", { tone: "info" });
      } else {
        setFeedback("Submitting your payment details...", "info");
      }
    });
  });
})();
