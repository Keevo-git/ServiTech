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
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h9" />
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h17" />
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

    <form id="editProfileForm" class="profile-form" action="/api/profile_update.php" method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

      <label class="field">
        <span>Full Name</span>
        <div class="input-with-icon">
          <span class="icon" aria-hidden="true">&#128100;</span>
          <input id="fullname" name="fullname" type="text" value="<?php echo htmlspecialchars($u['fullname']); ?>" required autocomplete="name" />
        </div>
      </label>

      <label class="field">
        <span>Email</span>
        <div class="input-with-icon">
          <span class="icon" aria-hidden="true">&#128231;</span>
          <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($u['email']); ?>" required autocomplete="email" />
        </div>
      </label>

      <label class="field">
        <span>Contact Number</span>
        <div class="input-with-icon">
          <span class="icon" aria-hidden="true">&#128222;</span>
          <input id="contacts" name="contacts" type="tel" value="<?php echo htmlspecialchars($u['contacts']); ?>" autocomplete="tel" />
        </div>
      </label>

      <div class="profile-divider thick"></div>

      <label class="field">
        <span>Current Password</span>
        <div class="input-with-icon">
          <span class="icon" aria-hidden="true">&#128274;</span>
          <input id="currentPassword" name="current_password" type="password" placeholder="Enter current password" autocomplete="current-password" />
        </div>
        <small class="hint">Required only if you want to change your password</small>
      </label>

      <label class="field">
        <span>New Password</span>
        <div class="input-with-icon">
          <span class="icon" aria-hidden="true">&#128274;</span>
          <input id="newPassword" name="new_password" type="password" placeholder="Enter new password (optional)" autocomplete="new-password" />
        </div>
      </label>

      <label class="field">
        <span>Confirm New Password</span>
        <div class="input-with-icon">
          <span class="icon" aria-hidden="true">&#128274;</span>
          <input id="confirmPassword" name="confirm_password" type="password" placeholder="Confirm new password" autocomplete="new-password" />
        </div>
      </label>

      <p id="profileFeedback" class="form-feedback" role="alert" aria-live="polite"></p>

      <div class="profile-actions">
        <button id="saveProfileBtn" type="submit" class="btn-save">Save Changes</button>
        <a href="/pages/customer/customer_dash.php" class="btn-cancel">Cancel</a>
      </div>
    </form>
  </section>
</main>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
(function(){
  const form = document.getElementById("editProfileForm");
  const btn = document.getElementById("saveProfileBtn");
  if (!form || !btn) return;

  const fullname = document.getElementById("fullname");
  const email = document.getElementById("email");
  const currentPassword = document.getElementById("currentPassword");
  const newPassword = document.getElementById("newPassword");
  const confirmPassword = document.getElementById("confirmPassword");
  const feedback = document.getElementById("profileFeedback");

  function setInvalid(field, invalid) {
    if (!field) return;
    field.classList.toggle("is-invalid", !!invalid);
  }

  function setFeedback(message, tone) {
    if (!feedback) {
      if (message) alert(message);
      return;
    }
    feedback.textContent = message || "";
    feedback.classList.remove("error", "success");
    if (message) feedback.classList.add(tone === "success" ? "success" : "error");
  }

  form.addEventListener("submit", function(e){
    const np = (newPassword?.value || "").trim();
    const cp = (confirmPassword?.value || "").trim();
    const cur = (currentPassword?.value || "").trim();

    setFeedback("", "error");
    [fullname, email, currentPassword, newPassword, confirmPassword].forEach(f => setInvalid(f, false));

    const errors = [];

    if (!(fullname?.value || "").trim()) {
      errors.push("Full name is required.");
      setInvalid(fullname, true);
    }

    if (!(email?.value || "").trim()) {
      errors.push("Email is required.");
      setInvalid(email, true);
    }

    if (np || cp) {
      if (!cur) {
        errors.push("Current password is required to change your password.");
        setInvalid(currentPassword, true);
      }
      if (np.length < 6) {
        errors.push("New password must be at least 6 characters.");
        setInvalid(newPassword, true);
      }
      if (np !== cp) {
        errors.push("New password and confirm password do not match.");
        setInvalid(confirmPassword, true);
      }
    }

    if (errors.length) {
      e.preventDefault();
      setFeedback(errors.join(" "), "error");
      return;
    }

    btn.disabled = true;
    btn.textContent = "Saving...";
    btn.setAttribute("aria-busy", "true");
    setFeedback("Saving your updates...", "success");
  });
})();
</script>

</body>
</html>

