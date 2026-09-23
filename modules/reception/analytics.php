<?php
require '../../config/database.php';
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Reception' && $_SESSION['role'] !== 'Admin') { die("غير مصرح"); }

// --- DATA FETCHING ---

// 1. Total Patients Today (Since 5 AM)
$stmtToday = $pdo->prepare("SELECT COUNT(*) FROM Patients WHERE registration_date >= ?");
$stmtToday->execute([$medical_day_start]);
$totalToday = $stmtToday->fetchColumn();

// 2. Patients per Doctor (Today)
$stmtDoctors = $pdo->prepare("
    SELECT d.name as doctor_name, COUNT(v.visit_id) as count 
    FROM Doctors d 
    LEFT JOIN Visits v ON d.doctor_id = v.doctor_id AND v.visit_date >= ?
    GROUP BY d.doctor_id 
    ORDER BY count DESC
");
$stmtDoctors->execute([$medical_day_start]);
$doctorStats = $stmtDoctors->fetchAll();

// 3. Gender Distribution (Today)
$stmtGender = $pdo->prepare("SELECT gender, COUNT(*) as count FROM Patients WHERE registration_date >= ? GROUP BY gender");
$stmtGender->execute([$medical_day_start]);
$genderStats = $stmtGender->fetchAll();

// 4. Hourly Trends (Today)
$stmtHourly = $pdo->prepare("
    SELECT HOUR(registration_date) as hour, COUNT(*) as count 
    FROM Patients 
    WHERE registration_date >= ? 
    GROUP BY HOUR(registration_date) 
    ORDER BY hour ASC
");
$stmtHourly->execute([$medical_day_start]);
$hourlyStats = $stmtHourly->fetchAll();

?>

            <li><a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">الاستقبال</a></li>
            <li><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>">إحصائيات القسم</a></li>
            <li><a href="change_password.php">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
    </div>

    <div class="content">
        <div class="analytics-header" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%); padding: 30px; border-radius: 15px; margin-bottom: 30px; color: white; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 30px rgba(255, 140, 0, 0.2);">
            <div>
                <h1 style="margin: 0; font-size: 1.8rem; font-weight: 800; color: white;">إحصائيات قسم الاستقبال</h1>
                <p style="margin: 10px 0 0; opacity: 0.9; font-size: 0.95rem;">مستشفى البدر الدولي - تقرير الأداء اليومي والنشاط التشغيلي</p>
            </div>
            <div style="background: rgba(255, 255, 255, 0.2); padding: 10px 20px; border-radius: 50px; font-weight: bold; font-size: 0.9rem; backdrop-filter: blur(5px);">
                <?php echo date('Y-m-d'); ?>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; margin-bottom: 40px;">
            <div class="premium-card" style="border-right: 5px solid var(--primary);">
                <div class="card-icon" style="background: var(--primary-light); color: var(--primary);">👥</div>
                <div class="card-val-container">
                    <span class="card-label">إجمالي المسجلين اليوم</span>
                    <span class="card-value"><?php echo $totalToday; ?></span>
                    <span class="card-sub">منذ 5:00 صباحاً</span>
                </div>
            </div>
            
            <?php 
            $topDoc = !empty($doctorStats) ? $doctorStats[0] : ['doctor_name' => '--', 'count' => 0];
            ?>
            <div class="premium-card" style="border-right: 5px solid #2ecc71;">
                <div class="card-icon" style="background: #eafaf1; color: #2ecc71;">👨‍⚕️</div>
                <div class="card-val-container">
                    <span class="card-label">الطبيب الأكثر طلباً</span>
                    <span class="card-value" style="font-size: 1.5rem; color: #2ecc71;"><?php echo htmlspecialchars($topDoc['doctor_name']); ?></span>
                    <span class="card-sub"><?php echo $topDoc['count']; ?> حالة مسجلة</span>
                </div>
            </div>

            <div class="premium-card" style="border-right: 5px solid #3498db;">
                <div class="card-icon" style="background: #ebf5fb; color: #3498db;">⚡</div>
                <div class="card-val-container">
                    <span class="card-label">مستوى نشاط القسم</span>
                    <span class="card-value" style="font-size: 1.7rem; color: #3498db;">
                        <?php 
                            if($totalToday > 50) echo "ذروة العمل";
                            elseif($totalToday > 20) echo "نشاط عالي";
                            else echo "مستقر";
                        ?>
                    </span>
                    <span class="card-sub">تقييم تلقائي للأداء</span>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 30px;">
            <div class="card" style="border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.05);">
                <h3 style="display: flex; align-items: center; gap: 10px; font-weight: 800; padding: 5px 0 15px; color: var(--secondary);">
                    <span style="width: 8px; height: 25px; background: var(--primary); border-radius: 4px; display: inline-block;"></span>
                    توزيع الحالات على الأطباء (اليوم)
                </h3>
                <div style="height: 300px;"><canvas id="doctorChart"></canvas></div>
            </div>

            <div class="card" style="border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.05); text-align: center;">
                <h3 style="display: flex; align-items: center; justify-content: center; gap: 10px; font-weight: 800; padding: 5px 0 15px; color: var(--secondary);">نظرة عامة على المرضى</h3>
                <div style="height: 250px; position: relative;">
                    <canvas id="genderChart"></canvas>
                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); pointer-events: none; z-index: 0;">
                        <div style="font-size: 2rem; font-weight: 900; color: #f1f2f6; opacity: 0.5;">HMS</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top: 30px; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.05);">
            <h3 style="display: flex; align-items: center; gap: 10px; font-weight: 800; padding: 5px 0 15px; color: var(--secondary);">
                <span style="width: 8px; height: 25px; background: #3498db; border-radius: 4px; display: inline-block;"></span>
                معدل تدفق المرضى خلال ساعات العمل
            </h3>
            <div style="height: 250px;"><canvas id="hourlyChart"></canvas></div>
        </div>
    </div>

    <style>
        .premium-card { background: white; padding: 25px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 20px; transition: transform 0.3s; }
        .premium-card:hover { transform: translateY(-5px); }
        .card-icon { width: 60px; height: 60px; border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; flex-shrink: 0; }
        .card-val-container { display: flex; flex-direction: column; }
        .card-label { font-size: 0.85rem; color: #888; font-weight: 700; margin-bottom: 5px; }
        .card-value { font-size: 2rem; font-weight: 900; color: #2d3436; line-height: 1.1; }
        .card-sub { font-size: 0.75rem; color: #bbb; margin-top: 5px; }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        Chart.defaults.font.family = "'Tajawal', sans-serif";
        Chart.defaults.color = "#636e72";

        // Doctor Chart
        const docCtx = document.getElementById('doctorChart').getContext('2d');
        const docGradient = docCtx.createLinearGradient(0, 0, 0, 400);
        docGradient.addColorStop(0, 'rgba(255, 140, 0, 0.9)');
        docGradient.addColorStop(1, 'rgba(255, 140, 0, 0.4)');

        new Chart(docCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($doctorStats, 'doctor_name')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($doctorStats, 'count')); ?>,
                    backgroundColor: docGradient,
                    borderRadius: 8,
                    barThickness: 30
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { 
                    x: { grid: { display: false }, border: { display: false } },
                    y: { beginAtZero: true, grid: { borderDash: [5, 5], color: '#f1f2f6' }, border: { display: false } }
                }
            }
        });

        // Gender Chart
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($genderStats, 'count')); ?>,
                    backgroundColor: ['#3498db', '#e74c3c'],
                    hoverOffset: 15,
                    borderWidth: 0,
                    cutout: '75%'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 25 } }
                }
            }
        });

        // Hourly Trend Chart
        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        const blueGradient = hourlyCtx.createLinearGradient(0, 0, 0, 300);
        blueGradient.addColorStop(0, 'rgba(52, 152, 219, 0.3)');
        blueGradient.addColorStop(1, 'rgba(52, 152, 219, 0)');

        new Chart(hourlyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($hours); ?>,
                datasets: [{
                    label: 'عدد التسجيلات',
                    data: <?php echo json_encode(array_column($hourlyStats, 'count')); ?>,
                    borderColor: '#3498db',
                    backgroundColor: blueGradient,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#3498db',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { 
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: '#f1f2f6' } }
                }
            }
        });
    </script>
</body>
</html>

</body>
</html>
