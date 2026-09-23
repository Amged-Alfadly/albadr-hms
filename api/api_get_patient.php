<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Use existing database configuration
require_once __DIR__ . '/../config/database.php';

if (isset($_GET['id'])) {
    $patient_id = $_GET['id'];
    
    try {
        // 1. Get Patient Profile
        $stmt = $pdo->prepare("SELECT * FROM Patients WHERE patient_id = ?");
        $stmt->execute([$patient_id]);
        $patient = $stmt->fetch();
        
        if ($patient) {
            // 2. Get Recent Visits
            $stmtVisits = $pdo->prepare("SELECT v.*, d.name as doctor_name FROM Visits v LEFT JOIN Doctors d ON v.doctor_id = d.doctor_id WHERE v.patient_id = ? ORDER BY v.visit_date DESC LIMIT 10");
            $stmtVisits->execute([$patient_id]);
            $visits = $stmtVisits->fetchAll();
            
            // 3. Get Recent Lab Tests
            $stmtTests = $pdo->prepare("SELECT mt.*, ls.service_name FROM Medical_Tests mt JOIN Lab_Services ls ON mt.service_id = ls.service_id WHERE mt.patient_id = ? ORDER BY mt.request_date DESC LIMIT 10");
            $stmtTests->execute([$patient_id]);
            $tests = $stmtTests->fetchAll();
            
            // 4. Get Prescriptions
            $stmtPresc = $pdo->prepare("SELECT * FROM Prescriptions WHERE patient_id = ? ORDER BY created_at DESC");
            $stmtPresc->execute([$patient_id]);
            $prescriptions = $stmtPresc->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "patient" => $patient,
                "visits" => $visits,
                "tests" => $tests,
                "prescriptions" => $prescriptions
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Patient not found"]);
        }
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "ID missing"]);
}
?>
