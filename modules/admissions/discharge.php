<?php
require '../../config/database.php';
session_start();

if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Admissions' && $_SESSION['role'] !== 'Reception') { 
    die("Unauthorized"); 
}

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE Admissions SET status = 'Discharged', discharge_date = NOW() WHERE admission_id = ?");
        $stmt->execute([$id]);
        
        // Update associated room status if you had a Rooms table (Optional enhancement for later)
        
    } catch (PDOException $e) {
        die("Error discharging patient: " . $e->getMessage());
    }
}

header("Location: index.php");
exit;
?>
