<?php
require_once __DIR__ . '/../config/database.php';
$tests = [
    ['CBC', 'Complete Blood Count'], ['FBS', 'Fasting Blood Sugar'], ['RBS', 'Random Blood Sugar'], ['HbA1c', 'Glycated Hemoglobin'],
    ['Urea', 'Blood Urea'], ['Creatinine', 'Serum Creatinine'], ['Uric Acid', 'Serum Uric Acid'],
    ['Total Bilirubin', 'Total Bilirubin'], ['Direct Bilirubin', 'Direct Bilirubin'],
    ['SGOT (AST)', 'Aspartate Aminotransferase'], ['SGPT (ALT)', 'Alanine Aminotransferase'],
    ['ALP', 'Alkaline Phosphatase'], ['Total Protein', 'Total Protein'], ['Albumin', 'Serum Albumin'],
    ['Globulin', 'Serum Globulin'], ['Total Cholesterol', 'Lipid Profile - Cholesterol'],
    ['Triglycerides', 'Lipid Profile - Triglycerides'], ['HDL', 'High-Density Lipoprotein'],
    ['LDL', 'Low-Density Lipoprotein'], ['Sodium (Na+)', 'Electrolytes - Sodium'],
    ['Potassium (K+)', 'Electrolytes - Potassium'], ['Chloride (Cl-)', 'Electrolytes - Chloride'],
    ['Calcium', 'Serum Calcium'], ['Magnesium', 'Serum Magnesium'], ['Phosphorus', 'Serum Phosphorus'],
    ['TSH', 'Thyroid Stimulating Hormone'], ['Free T4', 'Free Thyroxine'], ['Free T3', 'Free Triiodothyronine'],
    ['Vitamin D', '25-Hydroxy Vitamin D'], ['Vitamin B12', 'Serum Vitamin B12'], ['Ferritin', 'Serum Ferritin'],
    ['Serum Iron', 'Serum Iron'], ['TIBC', 'Total Iron Binding Capacity'], ['ESR', 'Erythrocyte Sedimentation Rate'],
    ['CRP', 'C-Reactive Protein'], ['RF', 'Rheumatoid Factor'], ['ASOT', 'Anti-Streptolysin O Titre'],
    ['Urine Routine', 'Urine Analysis'], ['Stool Routine', 'Stool Analysis'],
    ['H. Pylori Ag', 'Helicobacter Pylori Antigen'], ['HBsAg', 'Hepatitis B Surface Antigen'],
    ['HCV Ab', 'Hepatitis C Virus Antibody'], ['HIV 1/2 Ab', 'Human Immunodeficiency Virus'],
    ['hCG', 'Pregnancy Test'], ['PSA', 'Prostatic Specific Antigen'], ['Troponin I', 'Troponin I (Cardiac)'],
    ['CK-MB', 'Creatine Kinase-MB'], ['PT', 'Prothrombin Time'], ['PTT', 'Partial Thromboplastin Time'],
    ['INR', 'International Normalized Ratio'], ['Blood Group', 'Blood Group & Rh Typing'], ['Widal Test', 'Typhoid Test']
];
$stmt = $pdo->prepare('INSERT IGNORE INTO Lab_Services (service_name, description) VALUES (?, ?)');
foreach ($tests as $t) { $stmt->execute($t); }
echo 'Seeded ' . count($tests) . ' tests.';
unlink(__FILE__);
?>
