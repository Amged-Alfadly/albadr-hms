<?php
session_start();
session_unset();
session_destroy();
header("Location: login.php"); // Redirects to login.php in the same folder (modules/auth/)
exit;
?>
