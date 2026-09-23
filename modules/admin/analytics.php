<?php
require '../../config/database.php';
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Admin') { die("غير مصرح"); }

// 1. Medical Day Stats (Since 5 AM)
$stmt_p = $pdo->prepare("SELECT COUNT(*) FROM Patients WHERE registration_date >= ?");
$stmt_p->execute([$medical_day_start]);
$total_patients = $stmt_p->fetchColumn();

$stmt_v = $pdo->prepare("SELECT COUNT(*) FROM Visits WHERE visit_date >= ?");
$stmt_v->execute([$medical_day_start]);
$total_visits = $stmt_v->fetchColumn();

$total_admissions = $pdo->query("SELECT COUNT(*) FROM Admissions WHERE status = 'Active'")->fetchColumn();

// 2. Congestion Analysis (Pending vs In Progress)
$congestion_stmt = $pdo->query("SELECT status, COUNT(*) as count FROM Visits GROUP BY status");
$congestion_labels = []; $congestion_counts = [];
while($row = $congestion_stmt->fetch()) {
    $congestion_labels[] = $row['status'];
    $congestion_counts[] = $row['count'];
}

// 3. Peak Hours Analysis (Last 30 Days)
$peak_stmt = $pdo->query("
    SELECT HOUR(visit_date) as hr, COUNT(*) as count 
    FROM Visits 
    WHERE visit_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY HOUR(visit_date)
    ORDER BY hr ASC
");
$peak_hours = array_fill(0, 24, 0);
while($row = $peak_stmt->fetch()) { $peak_hours[$row['hr']] = $row['count']; }

// 4. Registration Trends (Last 7 Days)
$trends_stmt = $pdo->query("
    SELECT DATE(registration_date) as reg_date, COUNT(*) as count 
    FROM Patients 
    WHERE registration_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(registration_date)
    ORDER BY reg_date ASC
");
$dates = []; $reg_counts = [];
while ($row = $trends_stmt->fetch()) {
    $dates[] = $row['reg_date'];
    $reg_counts[] = $row['count'];
}

// 5. Visits by Specialty
$specialty_data = $pdo->query("
    SELECT d.specialty, COUNT(v.visit_id) as count 
    FROM Doctors d 
    JOIN Visits v ON d.doctor_id = v.doctor_id 
    GROUP BY d.specialty
")->fetchAll();
$specialties = []; $spec_counts = [];
foreach ($specialty_data as $row) {
    $specialties[] = $row['specialty'];
    $spec_counts[] = $row['count'];
}

// 6. Room Occupancy Rate (Dummy Calculation for demo if rooms are limited)
$total_rooms = 50; // Manual constant for demo
$occupancy_rate = round(($total_admissions / $total_rooms) * 100, 1);

?>
            <li><a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">الرئيسـية</a></li>
            <li><a href="patients_report.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'patients_report.php' ? 'active' : ''; ?>">تقرير المرضى الشامل</a></li>
            <li><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>">التحليلات والإحصائيات</a></li>
            <li><a href="tracking.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'tracking.php' ? 'active' : ''; ?>">تتبع المرضى </a></li>
            <li><a href="change_password.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'change_password.php' ? 'active' : ''; ?>">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
    </div>

    <div class="content" style="overflow-y: auto; padding: 15px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h1 class="page-title" style="margin: 0; border:none; font-size: 1.5rem;">التحليلات المتقدمة</h1>
            <div style="color: var(--primary-dark); font-weight: bold; font-size: 0.9rem;">لوحة ذكاء الأعمال</div>
        </div>

        <!-- Summary Cards (Compact) -->
        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 20px;">
            <div class="card" style="margin: 0; padding: 12px; text-align: center; border-bottom: 4px solid var(--primary); border-right: none;">
                <small style="font-size: 0.75rem; color: #888;">المرضى</small>
                <h4 style="margin: 0; color: var(--primary); font-size: 1.5rem;"><?php echo $total_patients; ?></h4>
            </div>
            <div class="card" style="margin: 0; padding: 12px; text-align: center; border-bottom: 4px solid #F39C12; border-right: none;">
                <small style="font-size: 0.75rem; color: #888;">الزيارات</small>
                <h4 style="margin: 0; color: #F39C12; font-size: 1.5rem;"><?php echo $total_visits; ?></h4>
            </div>
            <div class="card" style="margin: 0; padding: 12px; text-align: center; border-bottom: 4px solid var(--primary); border-right: none;">
                <small style="font-size: 0.75rem; color: #888;">الرقود</small>
                <h4 style="margin: 0; color: var(--primary); font-size: 1.5rem;"><?php echo $total_admissions; ?></h4>
            </div>
            <div class="card" style="margin: 0; padding: 12px; text-align: center; border-bottom: 4px solid var(--secondary); border-right: none;">
                <small style="font-size: 0.75rem; color: #888;">نسبة الإشغال</small>
                <h4 style="margin: 0; color: var(--secondary); font-size: 1.5rem;"><?php echo $occupancy_rate; ?>%</h4>
            </div>
            <div class="card" style="margin: 0; padding: 12px; text-align: center; border-bottom: 4px solid #E67E22; border-right: none;">
                <small style="font-size: 0.75rem; color: #888;">أفضل عيادة</small>
                <h4 style="margin: 0; color: #E67E22; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <?php 
                    $max_v = 0; $top_s = "---";
                    foreach($specialties as $i => $s) { if($spec_counts[$i] > $max_v) { $max_v = $spec_counts[$i]; $top_s = $s; } }
                    echo $top_s;
                    ?>
                </h4>
            </div>
        </div>

        <!-- Main Analytics Grid -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 20px;">
            <div class="card" style="margin:0; padding: 15px;">
                <h3 style="font-size: 1rem; margin-bottom: 10px;">تحليل ساعات الذروة (التكدس الزمني)</h3>
                <canvas id="peakChart" height="100"></canvas>
            </div>
            <div class="card" style="margin:0; padding: 15px;">
                <h3 style="font-size: 1rem; margin-bottom: 10px;">تحليل التكدس (حالة الزيارات)</h3>
                <canvas id="congestionChart" height="150"></canvas>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
             <div class="card" style="margin:0; padding: 15px;">
                <h3 style="font-size: 0.95rem; margin-bottom: 10px;">توجهات التسجيل</h3>
                <canvas id="trendChart" height="180"></canvas>
            </div>
            <div class="card" style="margin:0; padding: 15px;">
                <h3 style="font-size: 0.95rem; margin-bottom: 10px;">توزيع العيادات</h3>
                <canvas id="specChart" height="180"></canvas>
            </div>
            <div class="card" style="margin:0; padding: 15px; background: var(--primary-light); border: 1px solid #ffe8cc; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;">
                 <h2 style="color: var(--secondary); border: none; font-size: 1.2rem; margin-bottom: 5px;">توصية ذكية 💡</h2>
                 <p style="font-size: 0.85rem; color: #555;">
                    <?php
                    $peak_hr = array_search(max($peak_hours), $peak_hours);
                    echo "يُلاحظ وجود ضغط في تمام الساعة <strong>" . ($peak_hr > 12 ? ($peak_hr-12)."م" : $peak_hr."ص") . "</strong>. يُنصح بتوفير طاقم إضافي في هذا التوقيت.";
                    ?>
                 </p>
                 <div style="margin-top: 10px; font-weight: bold; color: var(--primary); font-size: 0.9rem;">
                    حالة النظام: 
                    <?php 
                    $pending_p = 0;
                    foreach($congestion_labels as $i => $l) if($l == 'Pending') $pending_p = $congestion_counts[$i];
                    echo $pending_p > 5 ? '🔴 ازدحام' : '🟢 مستقر';
                    ?>
                 </div>
            </div>
        </div>
    </div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const orange = '#FF8C00'; const deep = '#E67E22'; const navy = '#2c3e50'; const gold = '#F39C12';
        Chart.defaults.font.size = 11;

        // 1. Peak Hours (Bar)
        new Chart(document.getElementById('peakChart'), {
            type: 'bar',
            data: {
                labels: Array.from({length: 24}, (_, i) => i + ':00'),
                datasets: [{
                    label: 'عدد الزيارات',
                    data: <?php echo json_encode(array_values($peak_hours)); ?>,
                    backgroundColor: orange,
                    borderRadius: 3
                }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });

        // 2. Congestion (Pie/Polar)
        new Chart(document.getElementById('congestionChart'), {
            type: 'polarArea',
            data: {
                labels: <?php echo json_encode($congestion_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($congestion_counts); ?>,
                    backgroundColor: [orange, gold, navy, '#EEE']
                }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });

        // 3. Trends (Line)
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: [{
                    data: <?php echo json_encode($reg_counts); ?>,
                    borderColor: orange,
                    tension: 0.4,
                    fill: true,
                    backgroundColor: 'rgba(255, 140, 0, 0.05)'
                }]
            },
            options: { plugins: { legend: { display: false } } }
        });

        // 4. Speciality (Doughnut)
        new Chart(document.getElementById('specChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($specialties); ?>,
                datasets: [{
                    data: <?php echo json_encode($spec_counts); ?>,
                    backgroundColor: [orange, deep, gold, navy]
                }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });
    </script>
</body>
</html>
