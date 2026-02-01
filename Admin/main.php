<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ServiTech Login</title>
  <link rel="stylesheet" href="../main/style.css">
</head>
<body>

<h1>Admin Login</h1>

<form method="POST" action="/ServiTech/Admin/admin.php">
  <label>Email</label>
  <input type="email" name="email" required>

  <label>Password</label>
  <input type="password" name="password" required>

  <button type="submit">Login</button>
</form>

</body>
</html>
