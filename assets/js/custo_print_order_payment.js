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

    function openCompletionModal(queueCode) {
      if (!queueCode || !queueModal || !modalQueueNo) return;

      if (typeof window.openQueueSuccessModal === "function") {
        window.openQueueSuccessModal(queueCode, {
          service: "Document Print",
          note: "Your payment details were submitted. You can check your queue status while the shop reviews your order."
        });
      } else {
        modalQueueNo.textContent = queueCode;
        queueModal.style.display = "flex";
      }
    }

    if (confirmedQueue) {
      if (window.servitechJoinQueuePostSuccess) {
        window.servitechJoinQueuePostSuccess.markComplete(confirmedQueue);
      }
      openCompletionModal(confirmedQueue);
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

    if (referenceInput) {
      referenceInput.addEventListener("input", function () {
        var cleaned = referenceInput.value.replace(/\D+/g, "").slice(0, 13);
        if (referenceInput.value !== cleaned) referenceInput.value = cleaned;
        setFieldInvalid(referenceInput, false);
      });
      referenceInput.addEventListener("paste", function () {
        window.setTimeout(function () {
          referenceInput.value = referenceInput.value.replace(/\D+/g, "").slice(0, 13);
        }, 0);
      });
    }

    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      if (submitBtn.disabled) {
        return;
      }

      setFeedback("", "error");
      setFieldInvalid(referenceInput, false);

      if (paymentMethod === "gcash") {
        var referenceNumber = referenceInput ? referenceInput.value.trim() : "";
        if (referenceInput) {
          referenceNumber = referenceNumber.replace(/\D+/g, "").slice(0, 13);
          referenceInput.value = referenceNumber;
        }
        if (referenceNumber === "") {
          setFieldInvalid(referenceInput, true);
          setFeedback("Reference number is required for GCash payments.", "error");
          return;
        }
        if (!/^\d{13}$/.test(referenceNumber)) {
          setFieldInvalid(referenceInput, true);
          setFeedback("Please enter a valid 13-digit GCash reference number.", "error");
          return;
        }
      }

      submitBtn.disabled = true;
      submitBtn.setAttribute("aria-busy", "true");
      var submittingToastId = null;
      if (typeof window.servitechToast === "function") {
        submittingToastId = window.servitechToast("Submitting your payment details...", { tone: "info" });
      }

      try {
        var response = await fetch(form.action, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest"
          },
          body: new FormData(form)
        });
        var raw = await response.text();
        var data;
        try {
          data = JSON.parse(raw);
        } catch (error) {
          data = { ok: false, error: "Server returned an invalid response." };
        }

        if (!response.ok || !data.ok) {
          throw new Error(data.error || "Unable to submit your payment details.");
        }

        if (submittingToastId && typeof window.servitechDismissToast === "function") {
          window.servitechDismissToast(submittingToastId);
          submittingToastId = null;
        }
        if (window.servitechJoinQueuePostSuccess) {
          window.servitechJoinQueuePostSuccess.markComplete(data.queue_code);
        }
        openCompletionModal(data.queue_code);
      } catch (error) {
        if (submittingToastId && typeof window.servitechDismissToast === "function") {
          window.servitechDismissToast(submittingToastId);
        }
        submitBtn.disabled = false;
        submitBtn.removeAttribute("aria-busy");
        setFeedback(error.message || "Unable to submit your payment details.", "error");
      }
    });
  });
})();
