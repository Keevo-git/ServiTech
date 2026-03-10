<div id="queueModal" class="modal-overlay" style="display:none; position:fixed; inset:0; align-items:center; justify-content:center; background:rgba(0,0,0,0.35); z-index:9999;">
  <div class="modal" role="dialog" aria-modal="true" style="background:#fff;border-radius:12px;padding:28px;max-width:420px;width:90%;box-shadow:0 12px 30px rgba(0,0,0,0.2);text-align:center;">

    <div style="width:72px;height:72px;border-radius:50%;background:#e8fbef;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M20 6L9 17l-5-5" stroke="#27ae60" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>

    <h3 style="margin:6px 0 4px;font-size:20px;color:#222;">You're now in Line!</h3>
    <p style="margin:0 0 18px;color:#666;">Your queue number is</p>

    <p id="modalQueueNo" style="font-weight:700;font-size:28px;color:#f28c2b;margin:0 0 18px;">P-001</p>

    <div style="display:flex;gap:12px;justify-content:center">
      <button id="viewQueueBtn" type="button" style="background:#24120f;color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer">View Queue Status</button>
      <button id="goHomeBtn" type="button" style="background:#fff;color:#111;border:1px solid #ddd;padding:10px 18px;border-radius:8px;cursor:pointer">Go Home</button>
    </div>

  </div>
</div>
