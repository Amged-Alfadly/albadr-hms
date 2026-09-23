<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    // Define base path adjustment if needed, but for now assuming we are in modules/x/
    header("Location: ../../index.php"); 
    exit;
}

// Deterime relative path to assets based on current directory depth
// Most modules are in modules/role/index.php (depth 2 from root)
$assets_path = "../../assets"; 

// 5 AM Reset Logic (Medical Day Boundary)
// If current time is before 5 AM, the day started at 5 AM yesterday.
// If current time is after 5 AM, the day started at 5 AM today.
$current_hour = (int)date('H');
if ($current_hour < 5) {
    $medical_day_start = date('Y-m-d 05:00:00', strtotime('-1 day'));
} else {
    $medical_day_start = date('Y-m-d 05:00:00');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مستشفى البدر الدولي - النظام الداخلي</title>
    <link rel="stylesheet" href="<?php echo $assets_path; ?>/css/style.css">
</head>
<body class="admin-body">
    <div class="sidebar">
        <div class="brand">
            <img src="<?php echo $assets_path; ?>/img/logo.png" alt="شعار المستشفى">
            <h2> مستشفى البدر الدولي</h2>
            <div class="brand-slogan">كونوا بخير</div>
        </div>
        
        <div class="user-profile">
            <strong><?php echo $_SESSION['username']; ?></strong>
            <div style="font-size: 0.85rem; opacity: 0.7;"><?php echo $_SESSION['role']; ?></div>
        </div>
        
        <ul class="menu">
            <!-- Items will be injected by the dashboard page or we can add universal items here -->
            <!-- But usually dashboards have different menus. We will leave the UL open to be filled by the specific page content or keep navigation logic here if unified. 
                 For this phase, I'll keep it simple: pages include their specific links. -->
