<?php
require_once __DIR__ . "/../admin/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/../admin/_includes/admin_db.php";
require_once __DIR__ . "/../admin/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/operational_controls.php";
require_once __DIR__ . "/../../config/activity_log.php";
require_once __DIR__ . "/../../config/input_limits.php";

function op_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function op_textarea_value(string $key): string
{
    return trim((string)($_POST[$key] ?? ""));
}

function op_format_timestamp($value): string
{
    $value = trim((string)$value);
    if ($value === "") {
        return "Not updated yet";
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone("Asia/Manila"))->format("M j, Y g:i A");
    } catch (Throwable $exception) {
        return $value;
    }
}

function op_failure_message(Throwable $exception): string
{
    $message = trim($exception instanceof RuntimeException || $exception instanceof DomainException ? $exception->getMessage() : "");
    return $message !== "" ? $message : "Unable to update operational controls. Please try again.";
}

function op_log_update(PDO $pdo, string $targetId, array $oldValue, array $newValue, string $description): void
{
    servitech_activity_log($pdo, [
        "action_type" => "operational_controls_update",
        "module" => "system_settings",
        "target_record_id" => $targetId,
        "old_value" => $oldValue,
        "new_value" => $newValue,
        "description" => $description,
    ]);
}

function op_payment_short_label(string $paymentKey): string
{
    return strtolower(trim($paymentKey)) === "gcash" ? "GCash" : "Cash";
}

$notice = "";
$error = "";
$toastType = "";
$toastMessage = "";

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);
    $action = trim((string)($_POST["action"] ?? ""));
    $actorId = (int)($_SESSION["user_id"] ?? 0) ?: null;

    try {
        if (!servitech_operational_schema_ready($pdo)) {
            throw new RuntimeException("Operational controls tables are not available. Apply database/migrations/20260627_add_operational_controls.sql first.");
        }

        if ($action === "save_overall") {
            $closed = isset($_POST["all_services_closed"]);
            $reason = op_textarea_value("all_services_closure_reason");
            servitech_assert_max_length($reason, "Reason for closure", 500);
            $old = servitech_operational_fetch_overall($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO operational_control_settings
                  (id, all_services_closed, all_services_closure_reason, updated_by, updated_at)
                VALUES (1, :closed, :reason, :updated_by, NOW())
                ON CONFLICT (id) DO UPDATE SET
                  all_services_closed = EXCLUDED.all_services_closed,
                  all_services_closure_reason = EXCLUDED.all_services_closure_reason,
                  updated_by = EXCLUDED.updated_by,
                  updated_at = NOW()
            ");
            $stmt->execute([
                ":closed" => $closed ? "true" : "false",
                ":reason" => $reason,
                ":updated_by" => $actorId,
            ]);

            $new = servitech_operational_fetch_overall($pdo);
            $description = $closed
                ? "Super Admin closed all services manually."
                : "Super Admin reopened all services manually.";
            op_log_update($pdo, "all_services", $old, $new, $description);
            $notice = $closed ? "All services have been temporarily closed." : "All services have been reopened.";
        } elseif ($action === "save_service") {
            $serviceId = (int)($_POST["service_id"] ?? 0);
            $manualStatus = strtolower(trim((string)($_POST["manual_status"] ?? "open")));
            $reason = op_textarea_value("closure_reason");
            if ($serviceId <= 0) {
                throw new RuntimeException("Choose a valid service.");
            }
            if (!in_array($manualStatus, ["open", "closed"], true)) {
                throw new RuntimeException("Choose Open or Closed.");
            }
            servitech_assert_max_length($reason, "Closure reason", 500);

            $service = servitech_catalog_fetch_service($pdo, $serviceId, false);
            if (!is_array($service)) {
                throw new RuntimeException("Service not found.");
            }
            $old = servitech_operational_service_closed($pdo, $serviceId) ?? [
                "service_id" => $serviceId,
                "manual_status" => "open",
                "closure_reason" => "",
            ];

            $stmt = $pdo->prepare("
                INSERT INTO operational_service_settings
                  (service_id, manual_status, closure_reason, updated_by, updated_at)
                VALUES (:service_id, :manual_status, :closure_reason, :updated_by, NOW())
                ON CONFLICT (service_id) DO UPDATE SET
                  manual_status = EXCLUDED.manual_status,
                  closure_reason = EXCLUDED.closure_reason,
                  updated_by = EXCLUDED.updated_by,
                  updated_at = NOW()
            ");
            $stmt->execute([
                ":service_id" => $serviceId,
                ":manual_status" => $manualStatus,
                ":closure_reason" => $manualStatus === "closed" ? $reason : "",
                ":updated_by" => $actorId,
            ]);

            $serviceName = trim((string)($service["name"] ?? "Service"));
            $new = [
                "service_id" => $serviceId,
                "manual_status" => $manualStatus,
                "closure_reason" => $manualStatus === "closed" ? $reason : "",
            ];
            $description = $manualStatus === "closed"
                ? "Super Admin closed {$serviceName} service manually."
                : "Super Admin reopened {$serviceName} service.";
            op_log_update($pdo, "service:{$serviceId}", $old, $new, $description);
            $notice = $manualStatus === "closed"
                ? "{$serviceName} has been temporarily closed."
                : "{$serviceName} has been reopened.";
        } elseif ($action === "save_payment") {
            $paymentKey = strtolower(trim((string)($_POST["payment_method_key"] ?? "")));
            $enabled = isset($_POST["is_enabled"]);
            $reason = op_textarea_value("disabled_reason");
            if (!in_array($paymentKey, ["cash", "gcash"], true)) {
                throw new RuntimeException("Choose a valid payment method.");
            }
            servitech_assert_max_length($reason, "Disabled reason", 500);

            $oldMethods = servitech_operational_fetch_payment_methods($pdo);
            $old = $oldMethods[$paymentKey] ?? [];
            $paymentName = servitech_operational_payment_method_label($paymentKey);
            $proposedMethods = $oldMethods;
            $proposedMethods[$paymentKey] = array_merge($proposedMethods[$paymentKey] ?? [], [
                "is_enabled" => $enabled,
            ]);
            servitech_operational_assert_payment_methods_safe($proposedMethods);
            $stmt = $pdo->prepare("
                INSERT INTO operational_payment_method_settings
                  (payment_method_key, payment_method_name, is_enabled, disabled_reason, updated_by, updated_at)
                VALUES (:payment_method_key, :payment_method_name, :is_enabled, :disabled_reason, :updated_by, NOW())
                ON CONFLICT (payment_method_key) DO UPDATE SET
                  payment_method_name = EXCLUDED.payment_method_name,
                  is_enabled = EXCLUDED.is_enabled,
                  disabled_reason = EXCLUDED.disabled_reason,
                  updated_by = EXCLUDED.updated_by,
                  updated_at = NOW()
            ");
            $stmt->execute([
                ":payment_method_key" => $paymentKey,
                ":payment_method_name" => $paymentName,
                ":is_enabled" => $enabled ? "true" : "false",
                ":disabled_reason" => $enabled ? "" : $reason,
                ":updated_by" => $actorId,
            ]);

            $new = servitech_operational_fetch_payment_methods($pdo)[$paymentKey] ?? [];
            $paymentShortName = op_payment_short_label($paymentKey);
            $description = $enabled
                ? "Super Admin enabled {$paymentShortName} payment method."
                : "Super Admin disabled {$paymentShortName} payment method.";
            op_log_update($pdo, "payment:{$paymentKey}", $old, $new, $description);
            $notice = $enabled
                ? "{$paymentShortName} payment has been enabled."
                : "{$paymentShortName} payment has been disabled.";
        }

        if ($notice !== "") {
            $toastType = "success";
            $toastMessage = $notice;
        }
    } catch (Throwable $exception) {
        $error = op_failure_message($exception);
        $toastType = "error";
        $toastMessage = $error;
    }
}

$schemaReady = servitech_operational_schema_ready($pdo);
$currentStoreAvailability = servitech_store_current_availability($pdo);
$overall = servitech_operational_fetch_overall($pdo);
$services = servitech_operational_fetch_services($pdo);
$paymentMethods = servitech_operational_fetch_payment_methods($pdo);
$csrfToken = servitech_csrf_token();
$adminHeaderVariant = "special";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Operational Controls | ServiTech Admin</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260626-roles') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260627-operational-controls') ?>">
</head>
<body class="admin-operational-controls-page">
<?php require __DIR__ . "/../admin/_includes/admin_header.php"; ?>

<main class="admin-owner-shell operational-controls-shell">
  <section class="admin-owner-hero">
    <span class="admin-owner-kicker">Super Admin</span>
    <h1>Operational Controls</h1>
    <p>Manual overrides for new customer service requests and payment-method availability.</p>
  </section>

  <?php if (!$schemaReady): ?>
    <div class="admin-owner-alert admin-owner-alert--error">Operational controls tables are not available. Apply database/migrations/20260627_add_operational_controls.sql first.</div>
  <?php endif; ?>
  <?php if ($notice !== ""): ?><div class="admin-owner-alert"><?= op_h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ""): ?><div class="admin-owner-alert admin-owner-alert--error"><?= op_h($error) ?></div><?php endif; ?>

  <section class="admin-owner-panel operational-controls-info">
    <h2>Manual Override Rules</h2>
    <p>Store Hours controls the normal open/closed schedule. Operational Controls manually close services or disable payment methods until a Super Admin changes them again. Existing queue and order records remain available for staff processing.</p>
  </section>

  <section class="admin-owner-panel operational-controls-overall" aria-labelledby="overallAvailabilityTitle">
    <div class="operational-section-head">
      <div>
        <h2 id="overallAvailabilityTitle">Overall Availability</h2>
        <p>Close or reopen all services for new customer requests.</p>
      </div>
      <span class="operational-status operational-status--<?= !empty($overall["all_services_closed"]) ? "closed" : "open" ?>">
        <?= !empty($overall["all_services_closed"]) ? "Closed" : "Open" ?>
      </span>
    </div>
    <form method="post" class="admin-owner-form" data-operational-confirm="<?= !empty($overall["all_services_closed"]) ? "reopen-all" : "close-all" ?>">
      <input type="hidden" name="csrf_token" value="<?= op_h($csrfToken) ?>">
      <input type="hidden" name="action" value="save_overall">
      <label class="operational-toggle">
        <input type="checkbox" name="all_services_closed" value="1" <?= !empty($overall["all_services_closed"]) ? "checked" : "" ?>>
        <span>Close All Services</span>
      </label>
      <label class="admin-owner-field">
        <span>Reason for closure</span>
        <textarea name="all_services_closure_reason" maxlength="500" placeholder="Optional note for internal tracking"><?= op_h($overall["all_services_closure_reason"] ?? "") ?></textarea>
      </label>
      <div class="admin-owner-actions">
        <button class="admin-owner-button" type="submit">Save Overall Availability</button>
        <a class="admin-owner-button-secondary" href="<?= admin_url('/pages/super_admin/super_admin_system_settings.php') ?>">Back to System Settings</a>
      </div>
    </form>
  </section>

  <section class="admin-owner-panel" aria-labelledby="serviceControlsTitle">
    <div class="operational-section-head">
      <div>
        <h2 id="serviceControlsTitle">Service Controls</h2>
        <p>Manual service closure blocks new customer requests only.</p>
      </div>
    </div>
    <div class="operational-control-list">
      <?php if (!$services): ?>
        <div class="admin-owner-empty-state">No configured service controls were found.</div>
      <?php endif; ?>
      <?php foreach ($services as $service):
        $serviceId = (int)($service["id"] ?? 0);
        $manualStatus = strtolower(trim((string)($service["manual_status"] ?? "open"))) === "closed" ? "closed" : "open";
        $serviceName = trim((string)($service["name"] ?? "Service"));
        $serviceKind = servitech_catalog_service_kind($service);
        $effectiveStatus = $manualStatus;
        $effectiveNote = "";
        if (
            $manualStatus === "open"
            && $serviceKind === "document_printing"
            && servitech_operational_document_printing_requires_enabled_gcash($pdo, $currentStoreAvailability)
        ) {
            $effectiveStatus = "unavailable";
            $effectiveNote = servitech_operational_document_printing_unavailable_message();
        }
      ?>
        <article class="operational-control-row">
          <div class="operational-control-row__main">
            <span class="operational-kicker"><?= op_h(ucfirst((string)($service["category"] ?? "service"))) ?></span>
            <h3><?= op_h($serviceName) ?></h3>
            <small>Last updated: <?= op_h(op_format_timestamp($service["updated_at"] ?? null)) ?></small>
            <?php if ($effectiveNote !== ""): ?><small><?= op_h($effectiveNote) ?></small><?php endif; ?>
          </div>
          <span class="operational-status operational-status--<?= op_h($effectiveStatus) ?>">
            <?= $effectiveStatus === "unavailable" ? "Unavailable" : ($manualStatus === "closed" ? "Closed" : "Open") ?>
          </span>
          <form method="post" class="operational-row-form" data-operational-confirm="<?= $manualStatus === "closed" ? "open-service" : "close-service" ?>">
            <input type="hidden" name="csrf_token" value="<?= op_h($csrfToken) ?>">
            <input type="hidden" name="action" value="save_service">
            <input type="hidden" name="service_id" value="<?= $serviceId ?>">
            <input type="hidden" name="manual_status" value="<?= $manualStatus === "closed" ? "open" : "closed" ?>">
            <label class="admin-owner-field">
              <span>Closure reason</span>
              <textarea name="closure_reason" maxlength="500" placeholder="Optional reason" <?= $manualStatus === "closed" ? "" : "aria-label=\"Reason if closing " . op_h($serviceName) . "\"" ?>><?= op_h($service["closure_reason"] ?? "") ?></textarea>
            </label>
            <button class="<?= $manualStatus === "closed" ? "admin-owner-button-secondary" : "admin-owner-button-danger" ?>" type="submit">
              <?= $manualStatus === "closed" ? "Open" : "Close" ?>
            </button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="admin-owner-panel" aria-labelledby="paymentControlsTitle">
    <div class="operational-section-head">
      <div>
        <h2 id="paymentControlsTitle">Payment Method Controls</h2>
        <p>Disabled payment methods are hidden from new customer forms and rejected by backend validation.</p>
        <p>Cash and GCash cannot both be disabled at the same time.</p>
      </div>
    </div>
    <div class="operational-control-list">
      <?php foreach ($paymentMethods as $method):
        $key = (string)($method["payment_method_key"] ?? "");
        $enabled = !empty($method["is_enabled"]);
        $name = (string)($method["payment_method_name"] ?? servitech_operational_payment_method_label($key));
      ?>
        <article class="operational-control-row">
          <div class="operational-control-row__main">
            <span class="operational-kicker">Payment Method</span>
            <h3><?= op_h($name) ?></h3>
            <small>Last updated: <?= op_h(op_format_timestamp($method["updated_at"] ?? null)) ?></small>
          </div>
          <span class="operational-status operational-status--<?= $enabled ? "enabled" : "disabled" ?>">
            <?= $enabled ? "Enabled" : "Disabled" ?>
          </span>
          <form
            method="post"
            class="operational-row-form"
            data-operational-confirm="<?= $enabled ? "disable-payment" : "enable-payment" ?>"
            data-payment-control="true"
            data-payment-key="<?= op_h($key) ?>"
            data-payment-enabled="<?= $enabled ? "true" : "false" ?>">
            <input type="hidden" name="csrf_token" value="<?= op_h($csrfToken) ?>">
            <input type="hidden" name="action" value="save_payment">
            <input type="hidden" name="payment_method_key" value="<?= op_h($key) ?>">
            <?php if (!$enabled): ?><input type="hidden" name="is_enabled" value="1"><?php endif; ?>
            <label class="admin-owner-field">
              <span>Disabled reason</span>
              <textarea name="disabled_reason" maxlength="500" placeholder="Optional reason"><?= op_h($method["disabled_reason"] ?? "") ?></textarea>
            </label>
            <button class="<?= $enabled ? "admin-owner-button-danger" : "admin-owner-button-secondary" ?>" type="submit">
              <?= $enabled ? "Disable" : "Enable" ?>
            </button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
</main>

<div class="admin-owner-modal-overlay" id="operationalConfirmOverlay" hidden aria-hidden="true">
  <section class="admin-owner-modal operational-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="operationalConfirmTitle" aria-describedby="operationalConfirmMessage" tabindex="-1">
    <button class="admin-owner-modal__close" type="button" data-operational-confirm-cancel aria-label="Close confirmation">&times;</button>
    <div class="admin-owner-modal__header">
      <div>
        <span class="operational-kicker">Confirm action</span>
        <h2 id="operationalConfirmTitle">Confirm Change</h2>
        <p id="operationalConfirmMessage">Save this operational control change?</p>
      </div>
    </div>
    <div class="admin-owner-modal__actions">
      <button class="admin-owner-button-secondary" type="button" data-operational-confirm-cancel>Cancel</button>
      <button class="admin-owner-button-danger" type="button" data-operational-confirm-submit>Confirm</button>
    </div>
  </section>
</div>

<script>
const operationalToast = {
  type: <?= json_encode($toastType) ?>,
  message: <?= json_encode($toastMessage) ?>
};
if (operationalToast.message && window.servitechAdminToast) {
  window.servitechAdminToast.show(operationalToast.message, operationalToast.type || "info");
}

(() => {
  const overlay = document.getElementById("operationalConfirmOverlay");
  const dialog = overlay?.querySelector(".operational-confirm-modal");
  const message = document.getElementById("operationalConfirmMessage");
  const submit = overlay?.querySelector("[data-operational-confirm-submit]");
  let pendingForm = null;
  let confirmedForm = null;
  const paymentSafetyMessage = "At least one payment method must remain available.";
  const messages = {
    "close-all": "Close all services for new customer requests until a Super Admin reopens them?",
    "reopen-all": "Reopen all services for new customer requests?",
    "close-service": "Close this service for new customer requests?",
    "open-service": "Reopen this service for new customer requests?",
    "disable-payment": "Disable this payment method for new customer requests?",
    "enable-payment": "Enable this payment method for new customer requests?"
  };

  function closeConfirm() {
    pendingForm = null;
    overlay.hidden = true;
    overlay.setAttribute("aria-hidden", "true");
    document.documentElement.classList.remove("admin-owner-modal-open");
    document.body.classList.remove("admin-owner-modal-open");
  }

  function openConfirm(form) {
    if (form.dataset.paymentControl === "true" && form.dataset.paymentEnabled === "true") {
      const key = form.dataset.paymentKey || "";
      const otherKey = key === "cash" ? "gcash" : "cash";
      const otherForm = document.querySelector(`form[data-payment-control="true"][data-payment-key="${otherKey}"]`);
      if (otherForm && otherForm.dataset.paymentEnabled === "false") {
        if (window.servitechAdminToast) {
          window.servitechAdminToast.show(paymentSafetyMessage, "error");
        }
        return;
      }
    }
    pendingForm = form;
    message.textContent = messages[form.dataset.operationalConfirm] || "Save this operational control change?";
    overlay.hidden = false;
    overlay.setAttribute("aria-hidden", "false");
    document.documentElement.classList.add("admin-owner-modal-open");
    document.body.classList.add("admin-owner-modal-open");
    setTimeout(() => dialog?.focus(), 0);
  }

  document.querySelectorAll("form[data-operational-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      if (confirmedForm === form) {
        confirmedForm = null;
        return;
      }
      event.preventDefault();
      openConfirm(form);
    });
  });

  submit?.addEventListener("click", () => {
    if (!pendingForm) return;
    confirmedForm = pendingForm;
    const form = pendingForm;
    closeConfirm();
    form.requestSubmit();
  });

  overlay?.querySelectorAll("[data-operational-confirm-cancel]").forEach((button) => {
    button.addEventListener("click", closeConfirm);
  });
  overlay?.addEventListener("click", (event) => {
    if (event.target === overlay) closeConfirm();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && overlay && !overlay.hidden) closeConfirm();
  });
})();
</script>
</body>
</html>
