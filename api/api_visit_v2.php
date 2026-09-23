<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/database.php';

if (isset($_GET['visit_id'])) {
    $visit_id = $_GET['visit_id'];
    
    try {
        // Get Visit Details
        $stmt = $pdo->prepare("
            SELECT v.*, 
                   p.name as patient_name, p.age, p.gender, p.phone,
                   d.name as doctor_name, d.specialty
            FROM Visits v
            JOIN Patients p ON v.patient_id = p.patient_id
            JOIN Doctors d ON v.doctor_id = d.doctor_id
            WHERE v.visit_id = ?
        ");
        $stmt->execute([$visit_id]);
        $visit = $stmt->fetch();
        
        if ($visit) {
            // Get Diagnoses for this SPECIFIC visit (or fallback to date match if legacy)
            $stmtDiag = $pdo->prepare("
                SELECT * FROM Diagnoses 
                WHERE (visit_id = ? OR (visit_id IS NULL AND patient_id = ? AND DATE(diagnosis_date) = DATE(?)))
                ORDER BY diagnosis_date DESC
            ");
            $stmtDiag->execute([$visit['visit_id'], $visit['patient_id'], $visit['visit_date']]);
            $diagnoses = $stmtDiag->fetchAll();
            
            // Get Prescriptions for this SPECIFIC visit (or fallback to date match)
            $stmtPresc = $pdo->prepare("
                SELECT * FROM Prescriptions 
                WHERE (visit_id = ? OR (visit_id IS NULL AND patient_id = ? AND DATE(created_at) = DATE(?)))
                ORDER BY created_at DESC
            ");
            $stmtPresc->execute([$visit['visit_id'], $visit['patient_id'], $visit['visit_date']]);
            $prescriptions = $stmtPresc->fetchAll();
            
            // Get Medical Tests for this SPECIFIC visit (or fallback to date match)
            $stmtTests = $pdo->prepare("
                SELECT mt.*, 
                       ls.service_name, 
                       ls.description as service_description
                FROM Medical_Tests mt
                JOIN Lab_Services ls ON mt.service_id = ls.service_id
                WHERE (mt.visit_id = ? OR (mt.visit_id IS NULL AND mt.patient_id = ? AND DATE(mt.request_date) = DATE(?)))
                ORDER BY mt.request_date DESC
            ");
            $stmtTests->execute([$visit['visit_id'], $visit['patient_id'], $visit['visit_date']]);
            $medical_tests = $stmtTests->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "visit" => $visit,
                "diagnoses" => $diagnoses,
                "prescriptions" => $prescriptions,
                "medical_tests" => $medical_tests
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Visit not found"]);
        }
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Visit ID missing"]);
}
?>
