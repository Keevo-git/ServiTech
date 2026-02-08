\
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);

$stmt = $pdo->prepare("SELECT fullname, email, contacts FROM users WHERE id = :id LIMIT 1");
$stmt->execute([":id" => $user_id]);
$u = $stmt->fetch() ?: ["fullname"=>"", "email"=>"", "contacts"=>""];

$err = $_GET["err"] ?? "";
$ok  = $_GET["ok"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ServiTech: Edit Profile</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>

<?php include "includes/header.php"; ?>

<main class="profile-page">
  <section class="profile-card">

    <div class="profile-header">
      <a href="customer_dash.php" class="back-arrow">←</a>
      <h2>Edit Profile</h2>
    </div>

    <?php if ($err): ?>
      <p style="background:#ffe1e1;border:1px solid #ffb6b6;padding:10px;border-radius:8px;color:#8b0000;">
        <?php echo htmlspecialchars($err); ?>
      </p>
    <?php endif; ?>

    <?php if ($ok): ?>
      <p style="background:#e9ffe6;border:1px solid #b8f0b0;padding:10px;border-radius:8px;color:#195b13;">
        <?php echo htmlspecialchars($ok); ?>
      </p>
    <?php endif; ?>

    <form id="editProfileForm" action="profile_update.php" method="POST">
      <label class="field">
        <span>Full Name</span>
        <div class="input-with-icon">
          <span class="icon">👤</span>
          <input id="fullname" name="fullname" type="text" value="<?php echo htmlspecialchars($u["fullname"]); ?>" required />
        </div>
      </label>

      <label class="field">
        <span>Email</span>
        <div class="input-with-icon">
          <span class="icon">📧</span>
          <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($u["email"]); ?>" required />
        </div>
      </label>

      <label class="field">
        <span>Contact Number</span>
        <div class="input-with-icon">
          <span class="icon">📞</span>
          <input id="contacts" name="contacts" type="tel" value="<?php echo htmlspecialchars($u["contacts"]); ?>" />
        </div>
      </label>

      <div class="profile-divider thick"></div>

      <label class="field">
        <span>Current Password</span>
        <div class="input-with-icon">
          <span class="icon">🔒</span>
          <input id="currentPassword" name="current_password" type="password" placeholder="Enter current password" />
        </div>
        <small class="hint">Required only if you want to change your password</small>
      </label>

      <label class="field">
        <span>New Password</span>
        <div class="input-with-icon">
          <span class="icon">🔒</span>
          <input id="newPassword" name="new_password" type="password" placeholder="Enter new password (optional)" />
        </div>
      </label>

      <label class="field">
        <span>Confirm New Password</span>
        <div class="input-with-icon">
          <span class="icon">🔒</span>
          <input id="confirmPassword" name="confirm_password" type="password" placeholder="Confirm new password" />
        </div>
      </label>

      <div class="profile-actions">
        <button type="submit" class="btn-save">Save Changes</button>
        <a href="customer_dash.php" class="btn-cancel">Cancel</a>
      </div>
    </form>

  </section>
</main>

<?php include "includes/footer.php"; ?>

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
