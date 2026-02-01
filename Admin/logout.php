<?php
session_start();
session_destroy();

header("Location: ../main/main.php");
exit();
?>
