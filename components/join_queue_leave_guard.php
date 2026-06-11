<?php
$joinQueueBackUrl = isset($joinQueueBackUrl) && is_string($joinQueueBackUrl)
    ? $joinQueueBackUrl
    : "/pages/customer/custo_place_queueing.php";
?>
<link rel="stylesheet" href="/assets/css/join-queue-leave-guard.css?v=20260611a">
<div
  id="joinQueueLeaveModal"
  class="join-queue-leave-overlay"
  data-back-url="<?= htmlspecialchars($joinQueueBackUrl, ENT_QUOTES, "UTF-8") ?>"
  hidden
>
  <div
    class="join-queue-leave-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="joinQueueLeaveTitle"
    aria-describedby="joinQueueLeaveMessage"
    tabindex="-1"
  >
    <div class="join-queue-leave-modal__icon" aria-hidden="true">!</div>
    <h2 id="joinQueueLeaveTitle" class="join-queue-leave-modal__title">Leave this form?</h2>
    <p id="joinQueueLeaveMessage" class="join-queue-leave-modal__message">
      Your current answers will be lost if you go back to choosing a service.
    </p>
    <div class="join-queue-leave-modal__actions">
      <button type="button" class="join-queue-leave-modal__stay" data-leave-stay>Stay</button>
      <button type="button" class="join-queue-leave-modal__back" data-leave-confirm>Go Back</button>
    </div>
  </div>
</div>
<script src="/assets/js/join_queue_leave_guard.js?v=20260611a" defer></script>
