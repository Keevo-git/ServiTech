<div id="queueModal" class="modal-overlay queue-modal-overlay" style="display:none;">
  <div class="modal queue-success-modal" role="dialog" aria-modal="true">
    <div class="queue-success-modal__icon">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M20 6L9 17l-5-5" stroke="#27ae60" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>

    <h3 class="queue-success-modal__title">You're now in Line!</h3>
    <p class="queue-success-modal__label">Your queue number is</p>

    <p id="modalQueueNo" class="queue-success-modal__code">P-001</p>

    <div class="modal-actions queue-success-modal__actions">
      <button id="viewQueueBtn" type="button" class="btn-primary">View Queue Status</button>
      <button id="goHomeBtn" type="button" class="btn-secondary">Go Home</button>
    </div>
  </div>
</div>
