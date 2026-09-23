<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/database.php';

if (isset($_GET['test_id'])) {
    $test_id = $_GET['test_id'];
    
    try {
        // Get Test Details
        $stmt = $pdo->prepare("
            SELECT mt.*, 
                   ls.service_name, ls.description,
                   p.name as patient_name, p.age, p.gender,
                   d.name as doctor_name
            FROM Medical_Tests mt
            JOIN Lab_Services ls ON mt.service_id = ls.service_id
            JOIN Patients p ON mt.patient_id = p.patient_id
            LEFT JOIN Doctors d ON mt.doctor_id = d.doctor_id
            WHERE mt.test_id = ?
        ");
        $stmt->execute([$test_id]);
        $test = $stmt->fetch();
        
        if ($test) {
            echo json_encode([
                "status" => "success",
                "test" => $test
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Test not found"]);
        }
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Test ID missing"]);
}
?>
