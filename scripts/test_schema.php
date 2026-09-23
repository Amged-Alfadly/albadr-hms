<?php
require_once __DIR__ . '/../config/database.php';
$tables = ['Diagnoses', 'Prescriptions', 'Medical_Tests'];
$output = [];
foreach($tables as $t) {
    $stmt = $pdo->query("SHOW COLUMNS FROM $t");
    $output[$t] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($output);
?>
