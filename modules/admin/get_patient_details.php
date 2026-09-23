<?php
require '../../config/database.php';
header('Content-Type: application/json');

if (!isset($_GET['patient_id'])) {
    echo json_encode(['error' => 'Patient ID required']);
    exit;
}

$pid = $_GET['patient_id'];

// Fetch patient basic info
$patient = $pdo->prepare("SELECT * FROM Patients WHERE patient_id = ?");
$patient->execute([$pid]);
$patient_data = $patient->fetch();

// Fetch visits with diagnoses
$visits_stmt = $pdo->prepare("
    SELECT v.*, d.name as doctor_name, diag.diagnosis_details
    FROM Visits v
    LEFT JOIN Doctors d ON v.doctor_id = d.doctor_id
    LEFT JOIN Diagnoses diag ON diag.patient_id = v.patient_id AND diag.doctor_id = v.doctor_id
    WHERE v.patient_id = ?
    ORDER BY v.visit_date DESC
");
$visits_stmt->execute([$pid]);
$visits = $visits_stmt->fetchAll();

// Fetch lab results
$lab_stmt = $pdo->prepare("
    SELECT mt.*, ls.service_name
    FROM Medical_Tests mt
    JOIN Lab_Services ls ON mt.service_id = ls.service_id
    WHERE mt.patient_id = ?
    ORDER BY mt.request_date DESC
");
$lab_stmt->execute([$pid]);
$lab_results = $lab_stmt->fetchAll();

echo json_encode([
    'patient' => $patient_data,
    'visits' => $visits,
    'lab_results' => $lab_results
]);
?>
