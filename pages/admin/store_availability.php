<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/store_availability.php";

function store_admin_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function store_admin_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
    return $date !== false && $date->format("Y-m-d") === $value;
}

$dayNames = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
$notice = "";
$error = "";

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);
    $action = trim((string)($_POST["action"] ?? ""));

    try {
        if ($action === "save_settings") {
            $status = strtolower(trim((string)($_POST["store_status"] ?? "")));
            $cutoff = servitech_store_normalize_time($_POST["queue_cutoff_time"] ?? null);
            if (!in_array($status, ["open", "closed", "paused", "fully_booked"], true)) {
                throw new RuntimeException("Choose a valid store status.");
            }
            if ($cutoff === null) {
                throw new RuntimeException("Enter a valid queue cutoff time.");
            }

            $validatedHours = [];
            for ($day = 0; $day <= 6; $day++) {
                $isOpen = isset($_POST["hours"][$day]["is_open"]);
                $opensAt = servitech_store_normalize_time($_POST["hours"][$day]["opens_at"] ?? null);
                $closesAt = servitech_store_normalize_time($_POST["hours"][$day]["closes_at"] ?? null);
                if ($isOpen && ($opensAt === null || $closesAt === null || $closesAt <= $opensAt)) {
                    throw new RuntimeException($dayNames[$day] . " must have a closing time later than its opening time.");
                }
                $validatedHours[$day] = [
                    "is_open" => $isOpen,
                    "opens_at" => $isOpen ? $opensAt : null,
                    "closes_at" => $isOpen ? $closesAt : null,
                ];
            }

            $pdo->beginTransaction();
            $settingsStmt = $pdo->prepare("
                INSERT INTO store_availability_settings
                  (id, store_status, queue_cutoff_time, updated_by, updated_at)
                VALUES (1, :store_status, :queue_cutoff_time, :updated_by, NOW())
                ON CONFLICT (id) DO UPDATE SET
                  store_status = EXCLUDED.store_status,
                  queue_cutoff_time = EXCLUDED.queue_cutoff_time,
                  updated_by = EXCLUDED.updated_by,
                  updated_at = NOW()
            ");
            $settingsStmt->execute([
                ":store_status" => $status,
                ":queue_cutoff_time" => $cutoff,
                ":updated_by" => (int)($_SESSION["user_id"] ?? 0) ?: null,
            ]);

            $hoursStmt = $pdo->prepare("
                INSERT INTO store_hours (day_of_week, is_open, opens_at, closes_at, updated_at)
                VALUES (:day_of_week, :is_open, :opens_at, :closes_at, NOW())
                ON CONFLICT (day_of_week) DO UPDATE SET
                  is_open = EXCLUDED.is_open,
                  opens_at = EXCLUDED.opens_at,
                  closes_at = EXCLUDED.closes_at,
                  updated_at = NOW()
            ");
            foreach ($validatedHours as $day => $hours) {
                $hoursStmt->execute([
                    ":day_of_week" => $day,
                    ":is_open" => $hours["is_open"] ? "true" : "false",
                    ":opens_at" => $hours["opens_at"],
                    ":closes_at" => $hours["closes_at"],
                ]);
            }
            $pdo->commit();
            $notice = "Store availability settings saved.";
        } elseif ($action === "save_holiday") {
            $holidayId = (int)($_POST["holiday_id"] ?? 0);
            $holidayDate = trim((string)($_POST["holiday_date"] ?? ""));
            $title = trim((string)($_POST["title"] ?? ""));
            $note = trim((string)($_POST["note"] ?? ""));

            if (!store_admin_valid_date($holidayDate)) {
                throw new RuntimeException("Enter a valid closed date.");
            }
            if ($title === "") {
                throw new RuntimeException("Enter a title or reason for the closed date.");
            }
            if (strlen($title) > 120 || strlen($note) > 500) {
                throw new RuntimeException("The holiday title or note is too long.");
            }

            $duplicateStmt = $pdo->prepare("
                SELECT id FROM store_holidays
                WHERE holiday_date = :holiday_date AND id <> :holiday_id
                LIMIT 1
            ");
            $duplicateStmt->execute([
                ":holiday_date" => $holidayDate,
                ":holiday_id" => $holidayId,
            ]);
            if ($duplicateStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException("A holiday or closed date already exists for that date.");
            }

            if ($holidayId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE store_holidays
                    SET holiday_date = :holiday_date, title = :title, note = :note, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ":holiday_date" => $holidayDate,
                    ":title" => $title,
                    ":note" => $note,
                    ":id" => $holidayId,
                ]);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException("Closed date not found.");
                }
                $notice = "Closed date updated.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO store_holidays (holiday_date, title, note, created_by)
                    VALUES (:holiday_date, :title, :note, :created_by)
                ");
                $stmt->execute([
                    ":holiday_date" => $holidayDate,
                    ":title" => $title,
                    ":note" => $note,
                    ":created_by" => (int)($_SESSION["user_id"] ?? 0) ?: null,
                ]);
                $notice = "Closed date added.";
            }
        } elseif ($action === "delete_holiday") {
            $holidayId = (int)($_POST["holiday_id"] ?? 0);
            if ($holidayId <= 0) {
                throw new RuntimeException("Invalid closed date.");
            }
            $stmt = $pdo->prepare("DELETE FROM store_holidays WHERE id = :id");
            $stmt->execute([":id" => $holidayId]);
            $notice = "Closed date deleted.";
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$snapshot = servitech_store_fetch_snapshot($pdo, 50);
$availability = servitech_store_evaluate($snapshot);
$holidays = [];
if ($snapshot["settings_available"]) {
    try {
        $holidays = $pdo->query("
            SELECT id, holiday_date::text AS holiday_date, title, note
            FROM store_holidays
            ORDER BY holiday_date DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        $error = $error ?: "Store availability tables are not available. Apply the database migration first.";
    }
} else {
    $error = $error ?: "Store availability tables are not available. Apply database/migrations/20260615_add_store_availability.sql first.";
}
$csrfToken = servitech_csrf_token();
$adminHeaderVariant = "special";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Store Availability</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260612header-global-type') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/store_availability.css?v=20260615') ?>">
</head>
<body class="store-settings-page">
<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<main class="store-settings-shell">
  <header class="store-settings-hero">
    <div>
      <span>Admin Quick Access</span>
      <h1>Store Availability</h1>
      <p>Manage shop hours, cutoffs, holidays, and service status.</p>
    </div>
    <div class="store-settings-preview store-settings-preview--<?= store_admin_h($availability["effective_status"]) ?>">
      <small>Customer view now</small>
      <strong><?= store_admin_h($availability["status_label"]) ?></strong>
      <span><?= store_admin_h($availability["message"]) ?></span>
    </div>
  </header>

  <?php if ($notice !== ""): ?><div class="store-alert store-alert--success"><?= store_admin_h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ""): ?><div class="store-alert store-alert--error"><?= store_admin_h($error) ?></div><?php endif; ?>

  <form method="post" class="store-settings-form">
    <input type="hidden" name="csrf_token" value="<?= store_admin_h($csrfToken) ?>">
    <input type="hidden" name="action" value="save_settings">

    <section class="settings-panel settings-panel--status">
      <div class="settings-panel__heading">
        <div><span>1</span><h2>Store Status</h2></div>
        <p>This status overrides the regular schedule. Online Document Printing remains available in every state.</p>
      </div>
      <div class="status-options">
        <?php
        $statuses = [
            "open" => ["Open", "Customers can place regular queue requests during available hours."],
            "closed" => ["Closed", "Regular queue requests are unavailable."],
            "paused" => ["Paused", "Temporarily stop regular queue requests."],
            "fully_booked" => ["Full Service / Fully Booked", "No more regular queue requests can be accepted."],
        ];
        ?>
        <?php foreach ($statuses as $value => [$label, $description]): ?>
          <label class="status-option">
            <input type="radio" name="store_status" value="<?= store_admin_h($value) ?>" <?= $snapshot["store_status"] === $value ? "checked" : "" ?>>
            <span><strong><?= store_admin_h($label) ?></strong><small><?= store_admin_h($description) ?></small></span>
          </label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="settings-panel">
      <div class="settings-panel__heading">
        <div><span>2</span><h2>Shop Hours</h2></div>
        <p>Set the opening and closing time for each day. Closed days ignore their time fields.</p>
      </div>
      <div class="hours-list">
        <?php foreach ($dayNames as $day => $dayName): $hours = $snapshot["hours"][$day]; ?>
          <div class="hours-row">
            <strong><?= store_admin_h($dayName) ?></strong>
            <label class="open-switch">
              <input type="checkbox" name="hours[<?= $day ?>][is_open]" value="1" <?= !empty($hours["is_open"]) ? "checked" : "" ?> data-day-toggle>
              <span>Open</span>
            </label>
            <label>Opens
              <input type="time" name="hours[<?= $day ?>][opens_at]" value="<?= store_admin_h($hours["opens_at"] ?? "08:00") ?>" data-day-time>
            </label>
            <label>Closes
              <input type="time" name="hours[<?= $day ?>][closes_at]" value="<?= store_admin_h($hours["closes_at"] ?? "17:00") ?>" data-day-time>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="settings-panel">
      <div class="settings-panel__heading">
        <div><span>3</span><h2>Queue Cutoff</h2></div>
        <p>After this time, regular walk-in and service queue requests stop for the day. Online Document Printing stays available.</p>
      </div>
      <label class="cutoff-field">Stop accepting regular queue requests after
        <input type="time" name="queue_cutoff_time" value="<?= store_admin_h($snapshot["queue_cutoff_time"]) ?>" required>
      </label>
    </section>

    <button class="store-primary-btn" type="submit" <?= !$snapshot["settings_available"] ? "disabled" : "" ?>>Save Availability Settings</button>
  </form>

  <section class="settings-panel holiday-panel">
    <div class="settings-panel__heading">
      <div><span>4</span><h2>Holidays &amp; Special Closed Dates</h2></div>
      <p>Add one-time dates when regular queue requests should be unavailable.</p>
    </div>

    <form method="post" class="holiday-form" id="holidayForm">
      <input type="hidden" name="csrf_token" value="<?= store_admin_h($csrfToken) ?>">
      <input type="hidden" name="action" value="save_holiday">
      <input type="hidden" name="holiday_id" id="holidayId" value="0">
      <label>Date<input type="date" name="holiday_date" id="holidayDate" required></label>
      <label>Title / Reason<input type="text" name="title" id="holidayTitle" maxlength="120" required></label>
      <label class="holiday-note-field">Optional note<textarea name="note" id="holidayNote" maxlength="500" rows="2"></textarea></label>
      <div class="holiday-form__actions">
        <button class="store-primary-btn" type="submit" <?= !$snapshot["settings_available"] ? "disabled" : "" ?>>Save Closed Date</button>
        <button class="store-secondary-btn" type="button" id="cancelHolidayEdit" hidden>Cancel Edit</button>
      </div>
    </form>

    <div class="holiday-list">
      <?php if (!$holidays): ?>
        <p class="empty-holidays">No holidays or special closed dates have been added.</p>
      <?php else: ?>
        <?php foreach ($holidays as $holiday): ?>
          <article class="holiday-item">
            <time datetime="<?= store_admin_h($holiday["holiday_date"]) ?>"><?= store_admin_h(date("F j, Y", strtotime($holiday["holiday_date"]))) ?></time>
            <div><h3><?= store_admin_h($holiday["title"]) ?></h3><p><?= store_admin_h($holiday["note"] ?: "No additional note.") ?></p></div>
            <div class="holiday-item__actions">
              <button type="button" class="store-secondary-btn" data-edit-holiday
                data-id="<?= (int)$holiday["id"] ?>"
                data-date="<?= store_admin_h($holiday["holiday_date"]) ?>"
                data-title="<?= store_admin_h($holiday["title"]) ?>"
                data-note="<?= store_admin_h($holiday["note"]) ?>">Edit</button>
              <form method="post" onsubmit="return confirm('Delete this closed date?');">
                <input type="hidden" name="csrf_token" value="<?= store_admin_h($csrfToken) ?>">
                <input type="hidden" name="action" value="delete_holiday">
                <input type="hidden" name="holiday_id" value="<?= (int)$holiday["id"] ?>">
                <button class="store-danger-btn" type="submit">Delete</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php require __DIR__ . "/_includes/admin_footer.php"; ?>
<script>
document.querySelectorAll("[data-day-toggle]").forEach((toggle) => {
  const row = toggle.closest(".hours-row");
  const sync = () => row.querySelectorAll("[data-day-time]").forEach((input) => input.disabled = !toggle.checked);
  toggle.addEventListener("change", sync);
  sync();
});

const holidayForm = document.getElementById("holidayForm");
const holidayId = document.getElementById("holidayId");
const holidayDate = document.getElementById("holidayDate");
const holidayTitle = document.getElementById("holidayTitle");
const holidayNote = document.getElementById("holidayNote");
const cancelHolidayEdit = document.getElementById("cancelHolidayEdit");

function resetHolidayForm() {
  holidayForm.reset();
  holidayId.value = "0";
  cancelHolidayEdit.hidden = true;
}

document.querySelectorAll("[data-edit-holiday]").forEach((button) => {
  button.addEventListener("click", () => {
    holidayId.value = button.dataset.id || "0";
    holidayDate.value = button.dataset.date || "";
    holidayTitle.value = button.dataset.title || "";
    holidayNote.value = button.dataset.note || "";
    cancelHolidayEdit.hidden = false;
    holidayForm.scrollIntoView({ behavior: "smooth", block: "center" });
    holidayDate.focus();
  });
});
cancelHolidayEdit.addEventListener("click", resetHolidayForm);
</script>
</body>
</html>
