<div class="qmsg-overlay" id="queueMessageModal" aria-hidden="true">
  <div class="qmsg-modal" role="dialog" aria-modal="true" aria-labelledby="queueMessageTitle">
    <div class="qmsg-head">
      <div>
        <p>Queue Message</p>
        <h3 id="queueMessageTitle">Send Update</h3>
      </div>
      <button class="qmsg-close" type="button" id="queueMessageClose" aria-label="Close">&times;</button>
    </div>

    <div class="qmsg-info">
      <div>
        <span>Queue Number</span>
        <strong id="queueMessageCode">-</strong>
      </div>
      <div>
        <span>Customer</span>
        <strong id="queueMessageCustomer">-</strong>
      </div>
      <div>
        <span>Service</span>
        <strong id="queueMessageService">-</strong>
      </div>
    </div>

    <div class="qmsg-templates">
      <button type="button" data-qmsg-template="pickup">Ready for Pick-Up</button>
      <button type="button" data-qmsg-template="cancel">Cancellation Confirmation</button>
      <button type="button" data-qmsg-template="part">No Available Repair Part</button>
      <button type="button" data-qmsg-template="repairman">No Available Repairman</button>
    </div>

    <label class="qmsg-field" for="queueMessageText">
      <span>Message</span>
      <textarea id="queueMessageText" rows="6" placeholder="Type your queue update here..."></textarea>
    </label>

    <p class="qmsg-status" id="queueMessageStatus" aria-live="polite"></p>

    <div class="qmsg-actions">
      <button class="qmsg-btn qmsg-btn--light" type="button" id="queueMessageCancel">Cancel</button>
      <button class="qmsg-btn qmsg-btn--primary" type="button" id="queueMessageSend">Send Email</button>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById("queueMessageModal");
  const closeBtn = document.getElementById("queueMessageClose");
  const cancelBtn = document.getElementById("queueMessageCancel");
  const sendBtn = document.getElementById("queueMessageSend");
  const messageEl = document.getElementById("queueMessageText");
  const statusEl = document.getElementById("queueMessageStatus");
  const codeEl = document.getElementById("queueMessageCode");
  const customerEl = document.getElementById("queueMessageCustomer");
  const serviceEl = document.getElementById("queueMessageService");
  const endpoint = <?= json_encode(admin_url_raw("/pages/admin/queue_list/send_queue_message.php")) ?>;
  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");

  const templates = {
    pickup: "Good day, our dear customer, mabuhay! This is ServiTech. We are pleased to inform you that your queue {queue} is now ready for pickup. You may claim your item at our JC Store at your most convenient time. Thank you for trusting our service!",
    cancel: "Good day, our dear customer, mabuhay! This is ServiTech. We would like to confirm the cancellation for queue {queue}. Please reply YES to finalize the cancellation. Thank you, and we apologize for any inconvenience this may have caused.",
    part: "Good day, our dear customer, mabuhay! This is ServiTech. We would like to inform you that the required part for queue {queue} is currently unavailable. We apologize for the inconvenience. Kindly advise if you prefer to wait or proceed with cancellation. Thank you!",
    repairman: "Good day, our dear customer, mabuhay! This is ServiTech. We would like to notify you that there is currently no available repairman to process queue {queue}. We sincerely apologize for the inconvenience. We will update you as soon as a technician becomes available. Thank you for your patience."
  };

  function setStatus(message, type = "") {
    if (!statusEl) return;
    statusEl.textContent = message;
    statusEl.className = "qmsg-status" + (type ? " is-" + type : "");
  }

  function openModal(button) {
    if (!modal) return;
    modal.dataset.queueId = button.dataset.id || "";
    modal.dataset.queueCode = button.dataset.queueCode || "";
    codeEl.textContent = button.dataset.queueCode || "-";
    customerEl.textContent = button.dataset.customer || "-";
    serviceEl.textContent = button.dataset.service || "-";
    messageEl.value = "";
    setStatus("");
    modal.style.display = "flex";
    modal.setAttribute("aria-hidden", "false");
    messageEl.focus();
  }

  function closeModal() {
    if (!modal) return;
    modal.style.display = "none";
    modal.setAttribute("aria-hidden", "true");
  }

  document.querySelectorAll(".btn-message").forEach((button) => {
    button.addEventListener("click", () => openModal(button));
  });

  document.querySelectorAll("[data-qmsg-template]").forEach((button) => {
    button.addEventListener("click", () => {
      const key = button.dataset.qmsgTemplate || "";
      const code = modal.dataset.queueCode || "";
      messageEl.value = (templates[key] || "").replaceAll("{queue}", code) + "\n\n";
      messageEl.focus();
      messageEl.selectionStart = messageEl.selectionEnd = messageEl.value.length;
    });
  });

  sendBtn?.addEventListener("click", async () => {
    const queueId = modal?.dataset.queueId || "";
    const message = messageEl?.value.trim() || "";

    if (!queueId) {
      setStatus("Queue entry is missing.", "error");
      return;
    }
    if (!message) {
      setStatus("Please enter a message before sending.", "error");
      return;
    }

    const originalText = sendBtn.textContent;
    sendBtn.disabled = true;
    sendBtn.textContent = "Sending...";
    setStatus("Sending queue message...", "pending");

    const formData = new FormData();
    formData.append("csrf_token", csrf());
    formData.append("queue_id", queueId);
    formData.append("subject", "ServiTech Queue Update");
    formData.append("message", message);

    try {
      const response = await fetch(endpoint, {
        method: "POST",
        body: formData,
        credentials: "same-origin"
      });
      const data = await response.json();

      if (!response.ok || !data.ok) {
        throw new Error(data.error || "Queue message failed.");
      }

      setStatus(data.warning || data.message || "Queue message sent.", data.warning ? "error" : "success");
    } catch (error) {
      setStatus(error.message || "Queue message failed.", "error");
    } finally {
      sendBtn.disabled = false;
      sendBtn.textContent = originalText;
    }
  });

  closeBtn?.addEventListener("click", closeModal);
  cancelBtn?.addEventListener("click", closeModal);
  modal?.addEventListener("click", (event) => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal?.style.display === "flex") closeModal();
  });
})();
</script>
