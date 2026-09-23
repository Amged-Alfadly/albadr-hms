<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: modules/auth/login.php");
    exit;
}

// Redirect based on role if logged in
switch ($_SESSION['role']) {
    case 'Admin': header("Location: modules/admin/index.php"); break;
    case 'Doctor': header("Location: modules/doctor/index.php"); break;
    case 'Reception': header("Location: modules/reception/index.php"); break;
    case 'Lab': header("Location: modules/lab/index.php"); break;
    case 'Admissions': header("Location: modules/admissions/index.php"); break;
    default:     header("Location: modules/auth/login.php"); exit;
}
?>
