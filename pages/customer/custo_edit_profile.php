<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);

try {
  $stmt = $pdo->prepare("SELECT fullname, email, contact AS contacts FROM users WHERE id = :id LIMIT 1");
  $stmt->execute([":id" => $user_id]);
  $u = $stmt->fetch();
} catch (PDOException $e) {
  $stmt = $pdo->prepare("SELECT fullname, email, contacts FROM users WHERE id = :id LIMIT 1");
  $stmt->execute([":id" => $user_id]);
  $u = $stmt->fetch();
}

$u = $u ?: ["fullname" => "", "email" => "", "contacts" => ""];

$err = $_GET["err"] ?? "";
$ok  = $_GET["ok"] ?? "";
$csrfToken = servitech_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ServiTech: Edit Profile</title>
  <link rel="stylesheet" href="/assets/css/style.css" />
  <link rel="stylesheet" href="/assets/css/customer-responsive.css" />
</head>
<body class="customer-layout customer-page--profile">

<?php include __DIR__ . "/../../components/header.php"; ?>

<main class="profile-page">
  <section class="profile-card">
    <div class="profile-header">
      <a href="/pages/customer/customer_dash.php" class="back-arrow" aria-label="Back to dashboard">&larr;</a>
      <h2>Edit Profile</h2>
    </div>

    <?php if ($err): ?>
      <p class="profile-alert error"><?php echo htmlspecialchars($err); ?></p>
    <?php endif; ?>

    <?php if ($ok): ?>
      <p class="profile-alert success"><?php echo htmlspecialchars($ok); ?></p>
    <?php endif; ?>

    <form id="editProfileForm" class="profile-form" action="/api/profile_update.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

      <label class="field">
        <span>Full Name</span>
        <div class="input-with-icon">
          <span class="icon">&#128100;</span>
          <input id="fullname" name="fullname" type="text" value="<?php echo htmlspecialchars($u['fullname']); ?>" required />
        </div>
      </label>

      <label class="field">
        <span>Email</span>
        <div class="input-with-icon">
          <span class="icon">&#128231;</span>
          <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($u['email']); ?>" required />
        </div>
      </label>

      <label class="field">
        <span>Contact Number</span>
        <div class="input-with-icon">
          <span class="icon">&#128222;</span>
          <input id="contacts" name="contacts" type="tel" value="<?php echo htmlspecialchars($u['contacts']); ?>" />
        </div>
      </label>

      <div class="profile-divider thick"></div>

      <label class="field">
        <span>Current Password</span>
        <div class="input-with-icon">
          <span class="icon">&#128274;</span>
          <input id="currentPassword" name="current_password" type="password" placeholder="Enter current password" />
        </div>
        <small class="hint">Required only if you want to change your password</small>
      </label>

      <label class="field">
        <span>New Password</span>
        <div class="input-with-icon">
          <span class="icon">&#128274;</span>
          <input id="newPassword" name="new_password" type="password" placeholder="Enter new password (optional)" />
        </div>
      </label>

      <label class="field">
        <span>Confirm New Password</span>
        <div class="input-with-icon">
          <span class="icon">&#128274;</span>
          <input id="confirmPassword" name="confirm_password" type="password" placeholder="Confirm new password" />
        </div>
      </label>

      <div class="profile-actions">
        <button type="submit" class="btn-save">Save Changes</button>
        <a href="/pages/customer/customer_dash.php" class="btn-cancel">Cancel</a>
      </div>
    </form>
  </section>
</main>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
document.getElementById("editProfileForm")?.addEventListener("submit", function(e){
  const np = document.getElementById("newPassword")?.value || "";
  const cp = document.getElementById("confirmPassword")?.value || "";
  if (np || cp) {
    if (np.length < 6) {
      e.preventDefault();
      alert("New password must be at least 6 characters.");
      return;
    }
    if (np !== cp) {
      e.preventDefault();
      alert("New password and confirm password do not match.");
      return;
    }
  }
});
</script>

</body>
</html>
