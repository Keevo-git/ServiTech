<?php
$conn = new mysqli("localhost", "root", "", "servitech_db");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullname = $_POST["fullname"];
    $contact  = $_POST["contact"];
    $email    = $_POST["email"];
    $password = password_hash($_POST["password"], PASSWORD_DEFAULT);

    // OPTIONAL (recommended): prevent duplicate emails
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $checkResult = $check->get_result();

    if ($checkResult->num_rows > 0) {
        echo "<script>
            alert('Email already exists. Try logging in.');
            window.location.href='log_in.html';
        </script>";
        exit();
    }
    $check->close();

    $sql = "INSERT INTO users (fullname, contact, email, password) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $fullname, $contact, $email, $password);

    if ($stmt->execute()) {
        echo "<script>
            alert('Registration successful! Please log in.');
            window.location.href='log_in.html';
        </script>";
        exit();
    } else {
        echo "<script>
            alert('Registration failed: " . addslashes($stmt->error) . "');
            window.location.href='regis.html';
        </script>";
        exit();
    }

    $stmt->close();
}
$conn->close();
?>
