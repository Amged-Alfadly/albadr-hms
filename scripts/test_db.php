<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SHOW COLUMNS FROM Prescriptions");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
