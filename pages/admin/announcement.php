<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";

function ann_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$notice = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);

    $action = trim((string)($_POST["action"] ?? "save"));

    try {
        if ($action === "clear") {
            $pdo->exec("UPDATE announcements SET active = FALSE, updated_at = NOW()");
            $notice = "Announcement hidden from the landing page.";
        } elseif ($action === "toggle_status") {
            $id = (int)($_POST["announcement_id"] ?? 0);
            $status = trim((string)($_POST["status"] ?? ""));

            if ($id <= 0 || !in_array($status, ["active", "hidden"], true)) {
                throw new RuntimeException("Invalid announcement status.");
            }

            if ($status === "active") {
                $pdo->beginTransaction();
                $pdo->exec("UPDATE announcements SET active = FALSE, updated_at = NOW()");

                $stmt = $pdo->prepare("UPDATE announcements SET active = TRUE, updated_at = NOW() WHERE id = :id");
                $stmt->execute([":id" => $id]);

                if ($stmt->rowCount() < 1) {
                    $pdo->rollBack();
                    throw new RuntimeException("Announcement not found.");
                }

                $pdo->commit();
                $notice = "Announcement published on the landing page.";
            } else {
                $stmt = $pdo->prepare("UPDATE announcements SET active = FALSE, updated_at = NOW() WHERE id = :id");
                $stmt->execute([":id" => $id]);

                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException("Announcement not found.");
                }

                $notice = "Announcement hidden from the landing page.";
            }
        } elseif ($action === "delete") {
            $id = (int)($_POST["id"] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException("Invalid announcement.");
            }

            $stmt = $pdo->prepare("
                UPDATE announcements
                SET active = FALSE, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([":id" => $id]);
            $notice = "Announcement archived.";
        } elseif ($action === "edit_announcement") {
            $id = (int)($_POST["announcement_id"] ?? 0);
            $title = trim((string)($_POST["title"] ?? ""));
            $message = trim((string)($_POST["message"] ?? ""));
            $status = trim((string)($_POST["status"] ?? ""));

            if ($id <= 0) {
                throw new RuntimeException("Invalid announcement.");
            }

            if ($title === "" || $message === "") {
                throw new RuntimeException("Title and message are required.");
            }

            if (!in_array($status, ["active", "hidden"], true)) {
                throw new RuntimeException("Invalid announcement status.");
            }

            $existsStmt = $pdo->prepare("SELECT id FROM announcements WHERE id = :id");
            $existsStmt->execute([":id" => $id]);
            if (!$existsStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException("Announcement not found.");
            }

            $active = $status === "active" ? 1 : 0;

            if ($active) {
                $pdo->beginTransaction();
                $pdo->exec("UPDATE announcements SET active = FALSE, updated_at = NOW()");
            }

            $stmt = $pdo->prepare("
                UPDATE announcements
                SET title = :title, message = :message, active = :active, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ":id" => $id,
                ":title" => $title,
                ":message" => $message,
                ":active" => $active,
            ]);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            $notice = $active
                ? "Announcement updated and published on the landing page."
                : "Announcement updated and saved as hidden.";
        } else {
            $title = trim((string)($_POST["title"] ?? ""));
            $message = trim((string)($_POST["message"] ?? ""));
            $active = isset($_POST["active"]) ? 1 : 0;

            if ($title === "" || $message === "") {
                throw new RuntimeException("Title and message are required.");
            }

            if ($active) {
                $pdo->exec("UPDATE announcements SET active = FALSE, updated_at = NOW()");
            }

            $stmt = $pdo->prepare("
                INSERT INTO announcements (title, message, active, created_at, updated_at)
                VALUES (:title, :message, :active, NOW(), NOW())
            ");
            $stmt->execute([
                ":title" => $title,
                ":message" => $message,
                ":active" => $active,
            ]);

            $notice = $active
                ? "Announcement published on the landing page."
                : "Announcement saved as inactive.";
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception->getMessage();
    }
}

$csrfToken = servitech_csrf_token();

$activeStmt = $pdo->query("
    SELECT id, title, message, active, created_at, updated_at
    FROM announcements
    WHERE active = TRUE
    ORDER BY updated_at DESC, id DESC
    LIMIT 1
");
$activeAnnouncement = $activeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$recentStmt = $pdo->query("
    SELECT id, title, message, active, created_at, updated_at
    FROM announcements
    ORDER BY updated_at DESC, id DESC
    LIMIT 8
");
$recentAnnouncements = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage Announcement</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260619-hero-actions') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/announcement.css?v=20260619-hero-actions') ?>">
  <style>
    .announcement-item .announcement-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
      flex-wrap: wrap;
    }

    .announcement-item .announcement-actions form {
      display: inline-flex;
      flex: 0 0 auto;
      margin: 0;
    }

    .announcement-item .announcement-actions button {
      width: auto;
      margin: 0;
      white-space: nowrap;
    }

    .status-toggle {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 72px;
      padding: 6px 14px;
      border-radius: 20px;
      border: none;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .status-toggle.active {
      background: #d1fae5;
      color: #065f46;
    }

    .status-toggle.hidden {
      background: #e5e7eb;
      color: #374151;
    }

    .edit-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 72px;
      background: #3b82f6;
      color: #fff;
      padding: 8px 14px;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      font-size: 13px;
      line-height: 1;
      transition: all 0.2s ease;
    }

    .delete-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 72px;
      background: #7f1d1d;
      color: #fff;
      padding: 8px 14px;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      font-size: 13px;
      line-height: 1;
      transition: all 0.2s ease;
    }

    .status-toggle:hover,
    .edit-btn:hover,
    .delete-btn:hover {
      opacity: 0.85;
    }

    .announcement-modal {
      position: fixed;
      inset: 0;
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(15, 23, 42, 0.55);
    }

    .announcement-modal.is-open {
      display: flex;
    }

    .announcement-modal__panel {
      width: min(560px, 100%);
      border-radius: 16px;
      background: #fff;
      box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
    }

    .announcement-modal__head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 18px 20px;
      border-bottom: 1px solid #e0e8f2;
    }

    .announcement-modal__head h3 {
      margin: 0;
      color: #112338;
    }

    .announcement-modal__close {
      border: 0;
      border-radius: 999px;
      background: #eef4fb;
      color: #17345a;
      cursor: pointer;
      font-size: 22px;
      height: 34px;
      line-height: 1;
      width: 34px;
      transition: all 0.2s ease;
    }

    .announcement-modal__close:hover {
      opacity: 0.85;
    }

    .announcement-modal .announcement-form {
      padding: 20px;
    }

    .announcement-modal .announcement-actions {
      gap: 10px;
    }

    .announcement-form select {
      width: 100%;
      border: 1px solid #cbd8e8;
      border-radius: 11px;
      padding: 12px 13px;
      color: #112338;
      font: inherit;
      background: #fff;
    }

    @media (max-width: 620px) {
      .announcement-item .announcement-actions {
        justify-content: flex-start;
        flex-wrap: wrap;
      }

      .announcement-item .announcement-actions form,
      .announcement-item .announcement-actions .edit-btn,
      .announcement-item .announcement-actions .delete-btn {
        flex: 0 0 auto;
      }
    }
  </style>
</head>
<body>
  <?php
  $adminHeaderVariant = "special";
  $adminHeaderMenuId = "admin-announcement-menu";
  require __DIR__ . "/_includes/admin_header.php";
  ?>

  <main class="announcement-main">
    <section class="announcement-hero announcement-hero--actions">
      <div class="announcement-hero-text">
        <p>Landing Page Notice</p>
        <h2>Announcement</h2>
        <span>Publish a short message customers will see at the top of the landing page.</span>
      </div>
      <div class="announcement-hero-actions" aria-label="Announcement actions">
        <button type="button" class="hero-btn hero-btn-secondary" onclick="goAdminBack()">Back</button>
        <a class="hero-btn hero-btn-primary" href="<?= admin_url('/pages/admin/store_availability.php') ?>">Store Availability</a>
      </div>
    </section>

    <?php if ($notice !== "" || $error !== ""): ?>
      <script>
        <?php if ($notice !== ""): ?>
          window.servitechAdminToast?.success(<?= json_encode($notice) ?>);
        <?php endif; ?>
        <?php if ($error !== ""): ?>
          window.servitechAdminToast?.error(<?= json_encode($error) ?>);
        <?php endif; ?>
      </script>
    <?php endif; ?>

    <section class="announcement-grid">
      <article class="announcement-panel">
        <div class="announcement-panel__head">
          <h3>Create Announcement</h3>
          <p>Keep it short and direct for the landing page banner.</p>
        </div>

        <form method="post" class="announcement-form">
          <input type="hidden" name="csrf_token" value="<?= ann_h($csrfToken) ?>">
          <input type="hidden" name="action" value="save">

          <label>
            <span>Title</span>
            <input name="title" type="text" maxlength="90" placeholder="e.g., Holiday Schedule" required>
          </label>

          <label>
            <span>Message</span>
            <textarea name="message" maxlength="420" rows="6" placeholder="Type the announcement customers should see..." required></textarea>
          </label>

          <label class="announcement-toggle">
            <input name="active" type="checkbox" checked>
            <span>Publish immediately</span>
          </label>

          <div class="announcement-actions">
            <button class="announcement-btn announcement-btn--primary" type="submit">Publish</button>
          </div>
        </form>
      </article>

      <aside class="announcement-panel announcement-preview">
        <div class="announcement-panel__head">
          <h3>Current Landing Banner</h3>
          <p>Only one announcement is shown at a time.</p>
        </div>

        <?php if (!empty($activeAnnouncement["title"])): ?>
          <div class="landing-announcement-preview" role="status" aria-label="Announcement preview">
            <span class="landing-announcement-preview__label">Announcement</span>
            <span class="landing-announcement-preview__title"><?= ann_h($activeAnnouncement["title"] ?? "") ?></span>
            <span class="landing-announcement-preview__message"><?= ann_h($activeAnnouncement["message"] ?? "") ?></span>
            <?php if (!empty($activeAnnouncement["updated_at"])): ?>
              <time class="landing-announcement-preview__date" datetime="<?= ann_h((string)$activeAnnouncement["updated_at"]) ?>">
                <?= ann_h(date("F j, Y • g:i A", strtotime((string)$activeAnnouncement["updated_at"]))) ?>
              </time>
            <?php endif; ?>
          </div>
          <form method="post" class="announcement-clear">
            <input type="hidden" name="csrf_token" value="<?= ann_h($csrfToken) ?>">
            <input type="hidden" name="action" value="clear">
            <button class="announcement-btn announcement-btn--ghost" type="submit">Hide Announcement</button>
          </form>
        <?php else: ?>
          <div class="announcement-empty">No active announcement right now.</div>
        <?php endif; ?>
      </aside>
    </section>

    <section class="announcement-panel announcement-history">
      <div class="announcement-panel__head">
        <h3>Recent Announcements</h3>
        <p>Newest updates created by admin.</p>
      </div>

      <?php if (!$recentAnnouncements): ?>
        <div class="announcement-empty">No announcements yet.</div>
      <?php else: ?>
        <div class="announcement-list">
          <?php foreach ($recentAnnouncements as $item): ?>
            <div class="announcement-item">
              <div>
                <strong><?= ann_h($item["title"] ?? "") ?></strong>
                <p><?= ann_h($item["message"] ?? "") ?></p>
              </div>
              <div class="announcement-actions">
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= ann_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="toggle_status">
                  <input type="hidden" name="announcement_id" value="<?= (int)($item["id"] ?? 0) ?>">
                  <input type="hidden" name="status" value="<?= !empty($item["active"]) ? "hidden" : "active" ?>">
                  <button
                    class="status-toggle <?= !empty($item["active"]) ? "active" : "hidden" ?>"
                    type="submit"
                    aria-label="<?= !empty($item["active"]) ? "Hide announcement" : "Set announcement active" ?>"
                  >
                    <?= !empty($item["active"]) ? "Active" : "Hidden" ?>
                  </button>
                </form>
                <button
                  class="edit-btn"
                  type="button"
                  data-edit-announcement
                  data-id="<?= (int)($item["id"] ?? 0) ?>"
                  data-title="<?= ann_h($item["title"] ?? "") ?>"
                  data-message="<?= ann_h($item["message"] ?? "") ?>"
                  data-status="<?= !empty($item["active"]) ? "active" : "hidden" ?>"
                >
                  Edit
                </button>
                <form method="post" onsubmit="return confirm('Delete this announcement?');">
                  <input type="hidden" name="csrf_token" value="<?= ann_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)($item["id"] ?? 0) ?>">
                  <button class="delete-btn" type="submit">Delete</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <div class="announcement-modal" id="announcementEditModal" aria-hidden="true">
    <div class="announcement-modal__panel" role="dialog" aria-modal="true" aria-labelledby="announcementEditTitle">
      <div class="announcement-modal__head">
        <h3 id="announcementEditTitle">Edit Announcement</h3>
        <button class="announcement-modal__close" type="button" data-close-edit aria-label="Close edit dialog">&times;</button>
      </div>

      <form method="post" class="announcement-form">
        <input type="hidden" name="csrf_token" value="<?= ann_h($csrfToken) ?>">
        <input type="hidden" name="action" value="edit_announcement">
        <input type="hidden" name="announcement_id" id="editAnnouncementId">

        <label>
          <span>Title</span>
          <input name="title" id="editAnnouncementTitle" type="text" maxlength="90" required>
        </label>

        <label>
          <span>Message</span>
          <textarea name="message" id="editAnnouncementMessage" maxlength="420" rows="6" required></textarea>
        </label>

        <label>
          <span>Status</span>
          <select name="status" id="editAnnouncementStatus">
            <option value="active">Active</option>
            <option value="hidden">Hidden</option>
          </select>
        </label>

        <div class="announcement-actions">
          <button class="announcement-btn announcement-btn--ghost" type="button" data-close-edit>Cancel</button>
          <button class="announcement-btn announcement-btn--primary" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>

  <?php require_once __DIR__ . "/_includes/admin_footer.php"; ?>
  <script>
    const editModal = document.getElementById("announcementEditModal");
    const editId = document.getElementById("editAnnouncementId");
    const editTitle = document.getElementById("editAnnouncementTitle");
    const editMessage = document.getElementById("editAnnouncementMessage");
    const editStatus = document.getElementById("editAnnouncementStatus");

    function closeEditModal() {
      editModal.classList.remove("is-open");
      editModal.setAttribute("aria-hidden", "true");
    }

    document.querySelectorAll("[data-edit-announcement]").forEach((btn) => {
      btn.addEventListener("click", () => {
        editId.value = btn.dataset.id || "";
        editTitle.value = btn.dataset.title || "";
        editMessage.value = btn.dataset.message || "";
        editStatus.value = btn.dataset.status || "hidden";
        editModal.classList.add("is-open");
        editModal.setAttribute("aria-hidden", "false");
        editTitle.focus();
      });
    });

    document.querySelectorAll("[data-close-edit]").forEach((btn) => {
      btn.addEventListener("click", closeEditModal);
    });

    editModal.addEventListener("click", (event) => {
      if (event.target === editModal) {
        closeEditModal();
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && editModal.classList.contains("is-open")) {
        closeEditModal();
      }
    });
  </script>
  <script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
