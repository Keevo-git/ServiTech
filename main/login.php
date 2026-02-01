<?php
session_start();

$conn = new mysqli("localhost", "root", "", "servitech_db");
if ($conn->connect_error) {
    die("Database connection failed");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: log_in.html");
    exit();
}

$email = $_POST["email"] ?? "";
$password = $_POST["password"] ?? "";

// ✅ change this to your real table if needed
$sql = "SELECT id, password FROM users WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows === 1) {
    $user = $result->fetch_assoc();

    if (password_verify($password, $user["password"])) {
        $_SESSION["user_id"] = (int)$user["id"];

        // ✅ SUCCESS: GO TO DASHBOARD (with marker to confirm new file is running)
        header("Location: customer_dash.php?from=login_v2");
        exit();
    }
}

// ❌ FAIL
header("Location: log_in.html?login=fail");
exit();
?>
