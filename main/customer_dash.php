<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: log_in.html");
    exit();
}

$conn = new mysqli("localhost", "root", "", "servitech_db");
if ($conn->connect_error) {
    die("Database connection failed");
}

$user_id = (int)$_SESSION["user_id"];

$sql = "SELECT fullname FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$fullname = "Customer";
if ($result && $result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $fullname = $row["fullname"] ?? "Customer";
}

$stmt->close();
$conn->close();

function format_fullname($name) {
    $name = trim($name);
    if ($name === "") return "Customer";

    $name = preg_replace('/\s+/', ' ', $name);
    $name = ucwords(strtolower($name));

    // keep initials uppercase: "t." -> "T."
    $name = preg_replace_callback('/\b([A-Za-z])\./', function ($m) {
        return strtoupper($m[1]) . '.';
    }, $name);

    return $name;
}

$display_name = format_fullname($fullname);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Customer Dashboard</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

  <header class="navbar">
    <a href="landing.html" class="logo">
      <img src="./IMAGES/LOGO_SERVITECH.png" alt="ServiTech Logo" class="servitech-logo">
      <h1>ServiTech</h1>
    </a>
    <nav>
      <a href="landing.html">Services Home</a>
      <a href="logout.php">Logout</a>
    </nav>
  </header>

  <section class="customer-hero">
    <h2>Welcome, <span id="customerName"><?php echo htmlspecialchars($display_name); ?></span>!</h2>
    <p>Manage your queue, request status and print orders.</p>
  </section>

  <section class="customer-dashboard">
    <div class="dashboard-card wide">
      <h3>ACTIVE QUEUE</h3>
      <div class="divider"></div>

      <div class="queue-header">
        <span class="queue-number" id="queueNo">#---</span>
        <span class="status pending" id="queueStatus">PENDING</span>
      </div>

      <p id="queueService">Service: ---</p>
      <p id="queueDetails">Details: ---</p>

      <p id="noQueueMsg" style="display:none; color:#888; margin-top:10px;">
        You have no active queue.
      </p>
    </div>

    <div class="dashboard-card">
      <h3>ON-GOING SERVICE(S)</h3>
      <div class="divider"></div>
      <h1 id="ongoingCount">00</h1>
    </div>
  </section>

  <section class="quick-access">
    <h3>Quick Access</h3>
    <div class="divider"></div>

    <div class="quick-grid">
      <a href="custo_place_queueing.html" class="quick-card-link">
        <div class="quick-card">
          <div class="quick-icon-box">
            <img src="./IMAGES/LANDING_QUEUEING.png" alt="Join Queue" class="quick-icon">
          </div>
          <h4>Join Queue</h4>
          <p>Join the line to place your request.</p>
        </div>
      </a>

      <a href="custo_service_status.html" class="quick-card-link">
        <div class="quick-card">
          <div class="quick-icon-box">
            <img src="./IMAGES/LANDING_SERVICE-STAT.png" alt="Service Status" class="quick-icon">
          </div>
          <h4>Service Status</h4>
          <p>Check your requested service status or your queue status.</p>
        </div>
      </a>

      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="./IMAGES/LANDING_PRINT-ORD.png" alt="Print Order" class="quick-icon">
        </div>
        <h4>Print Order</h4>
        <p>Place an order to print your document.</p>
      </div>

      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="./IMAGES/ICON_EDIT_PROF.png" alt="Edit Profile" class="quick-icon">
        </div>
        <h4>Edit Profile</h4>
        <p>Edit your personal information.</p>
      </div>
    </div>
  </section>

  <footer class="footer">
    <p class="footer-bottom">© 2026 ServiTech: JC Store</p>
  </footer>

  <script>
    const params = new URLSearchParams(window.location.search);
    if (params.get("from") === "login_v2") {
      alert("Login successful! (dashboard reached)");
      window.history.replaceState({}, document.title, "customer_dash.php");
    }
  </script>

</body>
</html>
