<?php
require '../../config/database.php';
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Doctor' && $_SESSION['role'] !== 'Admin') { die("غير مصرح"); }

// Get Doctor ID
$stmt = $pdo->prepare("SELECT doctor_id, name FROM Doctors WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$doctor = $stmt->fetch();
$doctor_id = $doctor ? $doctor['doctor_id'] : 0;

// 1. Fetch Doctor's Unique Patients
$patients_stmt = $pdo->prepare("
    SELECT DISTINCT p.patient_id, p.name 
    FROM Patients p 
    JOIN Visits v ON p.patient_id = v.patient_id 
    WHERE v.doctor_id = ?
");
$patients_stmt->execute([$doctor_id]);
$patients = $patients_stmt->fetchAll();

// 2. Aggregate Stats for this Doctor
$total_visited = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM Visits WHERE doctor_id = ?");
$total_visited->execute([$doctor_id]);
$total_visited = $total_visited->fetchColumn();

$top_diagnosis = $pdo->prepare("
    SELECT diagnosis_details, COUNT(*) as count 
    FROM Diagnoses 
    WHERE doctor_id = ? 
    GROUP BY diagnosis_details 
    ORDER BY count DESC LIMIT 5
");
$top_diagnosis->execute([$doctor_id]);
$top_diagnosis_data = $top_diagnosis->fetchAll();

// 3. Visit History Trend (Last 30 Days)
$visit_trend_stmt = $pdo->prepare("
    SELECT DATE(visit_date) as d, COUNT(*) as count 
    FROM Visits 
    WHERE doctor_id = ? AND visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(visit_date)
    ORDER BY d ASC
");
$visit_trend_stmt->execute([$doctor_id]);
$visit_trend = $visit_trend_stmt->fetchAll();

// 4. Handle Specific Patient Analytics
$selected_patient_id = $_GET['patient_id'] ?? null;
$patient_trend_data = [];
$patient_name = "";

if ($selected_patient_id) {
    // Fetch patient name
    $pn_stmt = $pdo->prepare("SELECT name FROM Patients WHERE patient_id = ?");
    $pn_stmt->execute([$selected_patient_id]);
    $patient_name = $pn_stmt->fetchColumn();

    // Fetch Lab Results Trend
    $lab_trend_stmt = $pdo->prepare("
        SELECT t.result, t.result_date, s.service_name 
        FROM Medical_Tests t 
        JOIN Lab_Services s ON t.service_id = s.service_id 
        WHERE t.patient_id = ? AND t.status = 'Completed' 
        ORDER BY t.result_date ASC
    ");
    $lab_trend_stmt->execute([$selected_patient_id]);
    $raw_lab = $lab_trend_stmt->fetchAll();
    
    // Group by service for charting
    foreach($raw_lab as $r) {
        $patient_trend_data[$r['service_name']][] = [
            'val' => floatval($r['result']), 
            'date' => date('d/m', strtotime($r['result_date']))
        ];
    }
}
?>
            <li><a href="index.php">العيادة</a></li>
            <li><a href="analytics.php" class="active">التحليلات الطبية</a></li>
            <li><a href="change_password.php">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
    </div>

    <div class="content clinical-dashboard" style="background: #fdfdfe; overflow-y: auto; padding: 25px;">
        
        <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px;">
            <div>
                <h1 style="margin: 0; color: #2D3436; font-size: 1.8rem;">لوحة التحليلات السريرية</h1>
                <p style="color: #636e72; margin: 5px 0 0;">مرحباً د. <?php echo htmlspecialchars($doctor['name'] ?? 'المشرف'); ?> | رؤى طبية دقيقة لتعزيز التشخيص</p>
            </div>
            <div class="medical-badge">نظام دعم القرار الطبي</div>
        </div>

        <!-- Global Doctor Stats -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px;">
            <div class="clinical-stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-info">
                    <span class="label">إجمالي المرضى</span>
                    <span class="value"><?php echo $total_visited; ?></span>
                </div>
            </div>
            <div class="clinical-stat-card">
                <div class="stat-icon">📋</div>
                <div class="stat-info">
                    <span class="label">التشخيصات المسجلة</span>
                    <span class="value">
                        <?php 
                        $diag_count = $pdo->prepare("SELECT COUNT(*) FROM Diagnoses WHERE doctor_id = ?");
                        $diag_count->execute([$doctor_id]);
                        echo $diag_count->fetchColumn();
                        ?>
                    </span>
                </div>
            </div>
            <div class="clinical-stat-card">
                <div class="stat-icon">🔬</div>
                <div class="stat-info">
                    <span class="label">طلبات المختبر</span>
                    <span class="value">
                        <?php 
                        $lab_count = $pdo->prepare("SELECT COUNT(*) FROM Medical_Tests WHERE doctor_id = ?");
                        $lab_count->execute([$doctor_id]);
                        echo $lab_count->fetchColumn();
                        ?>
                    </span>
                </div>
            </div>
            <div class="clinical-stat-card" style="background: linear-gradient(135deg, #FF8C00 0%, #E67E22 100%); color: white;">
                <div class="stat-icon" style="background: rgba(255,255,255,0.1);">🩺</div>
                <div class="stat-info">
                    <span class="label" style="color: rgba(255,255,255,0.8);">الحالة التشغيلية</span>
                    <span class="value" style="font-size: 1rem; color: white;">العيادة نشطة الآن</span>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 25px;">
            
            <!-- Left Column: Patient Selection & Top Diagnoses -->
            <div style="display: flex; flex-direction: column; gap: 25px;">
                
                <!-- Patient Selector -->
                <div class="card clinical-card">
                    <h3>تحليل حالة مريض معين</h3>
                    <form method="GET" style="margin-top: 15px;">
                        <select name="patient_id" onchange="this.form.submit()" style="width: 100%; padding: 12px; border-radius: 8px; border: 2px solid #edf2f7; outline: none; transition: border-color 0.3s;">
                            <option value="">اختر المريض للمتابعة...</option>
                            <?php foreach($patients as $p): ?>
                                <option value="<?php echo $p['patient_id']; ?>" <?php echo ($selected_patient_id == $p['patient_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php if($selected_patient_id): ?>
                        <div style="margin-top: 15px; padding: 12px; background: #FFF5E6; border-radius: 8px; border-right: 4px solid #FF8C00;">
                            <small style="color: #E67E22; font-weight: bold;">يتم الآن عرض البيانات التاريخية لـ:</small><br>
                            <strong style="color: #2D3436;"><?php echo htmlspecialchars($patient_name); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Top Diagnosis Chart -->
                <div class="card clinical-card">
                    <h3>التشخيصات الأكثر شيوعاً</h3>
                    <canvas id="diagPieChart" height="250"></canvas>
                </div>

                <!-- Visit Trend -->
                <div class="card clinical-card">
                    <h3>تطور كثافة المراجعات (30 يوم)</h3>
                    <canvas id="visitTrendChart" height="200"></canvas>
                </div>
            </div>

            <!-- Right Column: Specific Patient Clinical Trends -->
            <div class="card clinical-card" style="flex: 1;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 20px;">
                    <h3 style="margin: 0;">منحنيات التطور السريري</h3>
                    <div style="font-size: 0.8rem; color: #636e72;">* تعتمد الرسوم على الفحوصات المكتملة فقط</div>
                </div>

                <?php if($selected_patient_id && !empty($patient_trend_data)): ?>
                    <div style="display: grid; grid-template-columns: 1fr; gap: 30px;">
                        <?php foreach($patient_trend_data as $service => $data): ?>
                            <div class="clinical-chart-container">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <h4 style="margin:0; color: #2D3436;"><?php echo htmlspecialchars($service); ?></h4>
                                    <span style="font-size: 0.75rem; background: #fdfdfe; border: 1px solid #edf2f7; color: #636e72; padding: 2px 8px; border-radius: 10px;">تحليل تسلسلي</span>
                                </div>
                                <canvas id="chart_<?php echo md5($service); ?>" height="120"></canvas>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif($selected_patient_id): ?>
                    <div style="text-align: center; padding: 100px 0; color: #b2bec3;">
                        <div style="font-size: 3rem; margin-bottom: 20px;">📉</div>
                        <p>لا توجد نتائج فحوصات كافية لهذا المريض لتمثيل المنحنيات البيانية.</p>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 150px 0; color: #b2bec3;">
                        <div style="font-size: 4rem; margin-bottom: 20px;">🧬</div>
                        <h2 style="border:none; color: #dfe6e9;">بانتظار اختيار مريض</h2>
                        <p>قم باختيار مريض من القائمة اليمنى لعرض مساره العلاجي وتطور نتائجه المخبرية.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <style>
            .dashboard-header .medical-badge {
                background: #FFF5E6;
                color: #FF8C00;
                padding: 8px 15px;
                border-radius: 30px;
                font-size: 0.85rem;
                font-weight: 800;
                border: 2px solid #FFAD60;
            }

            .clinical-stat-card {
                background: white;
                padding: 20px;
                border-radius: 15px;
                display: flex;
                align-items: center;
                gap: 15px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.05);
                transition: transform 0.3s ease;
                border: 1px solid #f1f2f6;
            }
            .clinical-stat-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(255,140,0,0.1); }

            .stat-icon {
                width: 50px;
                height: 50px;
                background: #fdfdfe;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.5rem;
                border: 1px solid #edf2f7;
            }

            .stat-info .label { display: block; font-size: 0.75rem; color: #636e72; font-weight: bold; }
            .stat-info .value { font-size: 1.5rem; font-weight: 800; color: #2D3436; }

            .clinical-card {
                margin: 0;
                padding: 25px;
                border-radius: 20px;
                border: 1px solid #edf2f7;
                background: white;
                box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            }
            .clinical-card h3 {
                margin: 0 0 15px;
                font-size: 1.1rem;
                color: #2D3436;
                font-weight: 800;
                border-bottom: none;
            }

            .clinical-chart-container {
                padding: 15px;
                background: #fdfdfe;
                border-radius: 16px;
                border: 1px solid #edf2f7;
            }
        </style>

        <!-- Chart.js -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            const orangePrimary = '#FF8C00';
            const orangeDeep = '#E67E22';
            const orangeLight = '#FFAD60';
            const textColor = '#2D3436';
            const subTextColor = '#636e72';

            Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
            Chart.defaults.color = subTextColor;

            // 1. Visit Trend Chart (Line)
            new Chart(document.getElementById('visitTrendChart'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($visit_trend, 'd')); ?>,
                    datasets: [{
                        label: 'عدد الزيارات',
                        data: <?php echo json_encode(array_column($visit_trend, 'count')); ?>,
                        borderColor: orangePrimary,
                        backgroundColor: 'rgba(255, 140, 0, 0.08)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: orangePrimary,
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: '#f1f2f6', borderDash: [5, 5] } }
                    }
                }
            });

            // 2. Diagnosis Distribution (Pie)
            new Chart(document.getElementById('diagPieChart'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column($top_diagnosis_data, 'diagnosis_details')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($top_diagnosis_data, 'count')); ?>,
                        backgroundColor: [orangePrimary, orangeDeep, orangeLight, '#FFD39B', '#FFE5B4'],
                        borderWidth: 5,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, font: { weight: 'bold' } } } },
                    cutout: '75%'
                }
            });

            // 3. Patient Lab Trends
            <?php if($selected_patient_id && !empty($patient_trend_data)): ?>
                <?php foreach($patient_trend_data as $service => $data): ?>
                    new Chart(document.getElementById('chart_<?php echo md5($service); ?>'), {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode(array_column($data, 'date')); ?>,
                            datasets: [{
                                label: 'القيمة المقاسة',
                                data: <?php echo json_encode(array_column($data, 'val')); ?>,
                                borderColor: orangeDeep,
                                backgroundColor: 'rgba(230, 126, 34, 0.05)',
                                fill: true,
                                tension: 0.3,
                                pointRadius: 5,
                                pointHoverRadius: 8,
                                pointBackgroundColor: '#fff',
                                pointBorderColor: orangeDeep,
                                pointBorderWidth: 3
                            }]
                        },
                        options: {
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { grid: { display: false } },
                                y: { grid: { color: '#f8f9fb' } }
                            }
                        }
                    });
                <?php endforeach; ?>
            <?php endif; ?>
        </script>
    </div>
</body>
</html>
