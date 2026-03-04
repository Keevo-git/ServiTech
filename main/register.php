<?php
$conn = new mysqli("localhost", "root", "", "servitech_db");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullname = $_POST["fullname"];
    $contact  = $_POST["contacts"];
    $email    = $_POST["email"];
    $password = password_hash($_POST["password"], PASSWORD_DEFAULT);

    // OPTIONAL: prevent duplicate email
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $checkResult = $check->get_result();

    if ($checkResult->num_rows > 0) {
        header("Location: log_in.html?registered=exists");
        exit();
    }
    $check->close();

    $sql = "INSERT INTO users (fullname, contacts, email, password) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $fullname, $contact, $email, $password);

    if ($stmt->execute()) {
        header("Location: log_in.html?registered=1");
        exit();
    } else {
        header("Location: regis.html?error=1");
        exit();
    }

    $stmt->close();
}

$conn->close();
?>
