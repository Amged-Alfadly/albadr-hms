<?php
require_once __DIR__ . '/../config/database.php';

echo "<h1>Last 10 Diagnoses</h1>";
$d = $pdo->query("SELECT * FROM Diagnoses ORDER BY diagnosis_id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($d);
echo "</pre>";

echo "<h1>Last 10 Prescriptions</h1>";
$p = $pdo->query("SELECT * FROM Prescriptions ORDER BY prescription_id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($p);
echo "</pre>";

echo "<h1>Last 10 Medical Tests</h1>";
$t = $pdo->query("SELECT * FROM Medical_Tests ORDER BY test_id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($t);
echo "</pre>";
?>
