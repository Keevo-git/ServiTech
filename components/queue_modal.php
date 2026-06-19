<link rel="stylesheet" href="/assets/css/queue-modal.css?v=20260602-stable-overlay">
<div id="queueModal" class="modal-overlay queue-modal-overlay" style="display:none;" aria-hidden="true">
  <div class="modal queue-success-modal" role="dialog" aria-modal="true" aria-labelledby="queueModalTitle" aria-describedby="queueModalMessage queueModalNote" tabindex="-1">
    <button id="queueModalCloseBtn" type="button" class="queue-success-modal__close" aria-label="Close joined queue confirmation">&times;</button>

    <div class="queue-success-modal__icon">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M20 6L9 17l-5-5" stroke="#27ae60" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>

    <h3 id="queueModalTitle" class="queue-success-modal__title">Queue Joined Successfully</h3>
    <p id="queueModalMessage" class="queue-success-modal__message">Your service request has been added to the queue.</p>

    <div class="queue-success-modal__reference">
      <span class="queue-success-modal__label">Your queue number is</span>
      <strong id="modalQueueNo" class="queue-success-modal__code">P-001</strong>
    </div>

    <p id="queueModalService" class="queue-success-modal__service" hidden></p>
    <p id="queueModalNote" class="queue-success-modal__note">You can check your queue status while you wait for the next update.</p>

    <div class="modal-actions queue-success-modal__actions">
      <button id="viewQueueBtn" type="button" class="btn-primary">View Queue Status</button>
      <button id="goHomeBtn" type="button" class="btn-secondary">Go Home</button>
    </div>
  </div>
</div>
<?php
if (!isset($servitechJoinQueueNewRequestStarted) && function_exists("servitech_consume_new_join_queue_started")) {
  $servitechJoinQueueNewRequestStarted = servitech_consume_new_join_queue_started();
}
?>
<?php if (!empty($servitechJoinQueueNewRequestStarted)): ?>
<script>window.SERVITECH_JOIN_QUEUE_NEW_REQUEST = true;</script>
<?php endif; ?>
<script src="/assets/js/queue_modal.js?v=20260611-post-success"></script>
<script src="/assets/js/join_queue_post_success.js?v=20260619-new-request"></script>
