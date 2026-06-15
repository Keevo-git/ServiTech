<div
  class="order-confirm-overlay"
  id="orderRecycleConfirm"
  data-order-recycle-endpoint="<?= htmlspecialchars(admin_url_raw('/pages/admin/order_management/order_recycle_action.php'), ENT_QUOTES, 'UTF-8') ?>"
  aria-hidden="true"
>
  <div class="order-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="orderRecycleConfirmTitle">
    <h3 id="orderRecycleConfirmTitle" data-order-confirm-title>Move order to Recycle Bin?</h3>
    <p data-order-confirm-message>This order will be hidden from Order Management but can still be restored from the Recycle Bin.</p>
    <div class="order-confirm-actions">
      <button class="order-confirm-cancel" type="button" data-order-confirm-cancel>Cancel</button>
      <button class="order-confirm-submit" type="button" data-order-confirm-submit>Move to Recycle Bin</button>
    </div>
  </div>
</div>
