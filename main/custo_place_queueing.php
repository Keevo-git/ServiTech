<?php
// TEMP SESSION TEST HEADER
session_name("SERVITECHSESSID");
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/ServiTech/main/",
    "domain"   => "",
    "secure"   => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// DEBUG – log session
error_log(
    basename(__FILE__) .
    " SID=" . session_id() .
    " user_id=" . ($_SESSION["user_id"] ?? "NONE")
);

// AUTH CHECK
if (!isset($_SESSION["user_id"])) {
    header("Location: /ServiTech/main/log_in.html");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ServiTech: Place Queueing Customer</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include "includes/header.php"; ?>

<!-- CHOOSE SERVICE -->
<section class="choose-service">
  <h2>CHOOSE A SERVICE</h2>

  <div class="choose-grid">

    <a href="custo1_printing_option.php" class="choose-card">
      <img src="./IMAGES/CARD_PRINTING.png" alt="Printing">
      <span>PRINTING</span>
    </a>

    <a href="custo1_repair_option.php" class="choose-card">
      <img src="./IMAGES/CARD_REPAIR.png" alt="Repair">
      <span>REPAIR</span>
    </a>

    <a href="custo1_installation_option.php" class="choose-card">
      <img src="./IMAGES/CARD_INSTALLATION.png" alt="Installation">
      <span>INSTALLATION</span>
    </a>

  </div>
</section>

<?php include "includes/footer.php"; ?>

</body>
</html>
