<div
  class="order-confirm-overlay"
  id="orderRecycleConfirm"
  data-order-recycle-endpoint="<?= htmlspecialchars(admin_url_raw('/pages/super_admin/super_admin_order_recycle_action.php'), ENT_QUOTES, 'UTF-8') ?>"
  aria-hidden="true"
>
  <div class="order-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="orderRecycleConfirmTitle">
    <div class="order-confirm-head">
      <h3 id="orderRecycleConfirmTitle" data-order-confirm-title>Move Order to Bin?</h3>
      <button class="order-confirm-close" type="button" data-order-confirm-close aria-label="Close confirmation">&times;</button>
    </div>
    <p data-order-confirm-message>This order will be removed from Order Management but can still be restored from the Recycle Bin within 30 days.</p>
    <div class="order-confirm-actions">
      <button class="order-confirm-cancel" type="button" data-order-confirm-cancel>Cancel</button>
      <button class="order-confirm-submit" type="button" data-order-confirm-submit>Move to Bin</button>
    </div>
  </div>
</div>
