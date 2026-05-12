<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";

function ann_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function ensure_announcements_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id BIGSERIAL PRIMARY KEY,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");
}

ensure_announcements_table($pdo);
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
        } elseif ($action === "delete") {
            $id = (int)($_POST["id"] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException("Invalid announcement.");
            }

            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = :id");
            $stmt->execute([":id" => $id]);
            $notice = "Announcement deleted.";
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
  <link rel="icon" type="images/png" href="/assets/images/favicon.png">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260315h2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/announcement.css?v=20260513a2') ?>">
</head>
<body>
  <header class="navbar has-nav-menu">
    <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>" class="logo">
      <img src="<?= admin_url('/assets/images/LOGO_SERVITECH.png') ?>" alt="ServiTech Logo">
      <h1>ServiTech Admin</h1>
    </a>
    <button
      class="nav-toggle"
      type="button"
      aria-label="Toggle navigation menu"
      aria-expanded="false"
      aria-controls="admin-announcement-menu"
    >
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
    </button>
    <nav id="admin-announcement-menu" data-collapsible-menu>
      <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>">Dashboard</a>
      <a href="<?= admin_url('/index.php') ?>">View Landing</a>
      <a href="<?= admin_url('/pages/admin/logout.php') ?>">Logout</a>
    </nav>
  </header>

  <main class="announcement-main">
    <section class="announcement-hero">
      <p>Landing Page Notice</p>
      <h2>Announcement</h2>
      <span>Publish a short message customers will see at the top of the landing page.</span>
    </section>

    <?php if ($notice !== ""): ?>
      <div class="announcement-alert announcement-alert--success"><?= ann_h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ""): ?>
      <div class="announcement-alert announcement-alert--error"><?= ann_h($error) ?></div>
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
            <div class="landing-announcement-preview__content">
              <span class="landing-announcement-preview__icon" aria-hidden="true">&#x1F4E3;</span>
              <span class="landing-announcement-preview__title"><?= ann_h($activeAnnouncement["title"] ?? "") ?></span>
              <span class="landing-announcement-preview__status"><?= ann_h($activeAnnouncement["message"] ?? "") ?></span>
            </div>
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
              <div class="announcement-item__actions">
                <span class="<?= !empty($item["active"]) ? "is-active" : "" ?>">
                  <?= !empty($item["active"]) ? "Active" : "Hidden" ?>
                </span>
                <form method="post" onsubmit="return confirm('Delete this announcement?');">
                  <input type="hidden" name="csrf_token" value="<?= ann_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)($item["id"] ?? 0) ?>">
                  <button type="submit">Delete</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <?php require_once __DIR__ . "/_includes/admin_footer.php"; ?>
  <script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
