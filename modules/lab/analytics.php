<?php
require '../../config/database.php';
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Lab' && $_SESSION['role'] !== 'Admin') {
    die("غير مصرح");
}

// Time variables
$today = date('Y-m-d');
$month_start = date('Y-m-01');

// 1. Medical Day KPI Stats (Since 5 AM)
$stmt_t = $pdo->prepare("SELECT COUNT(*) FROM Medical_Tests WHERE request_date >= ?");
$stmt_t->execute([$medical_day_start]);
$total_today = $stmt_t->fetchColumn();

$stmt_c = $pdo->prepare("SELECT COUNT(*) FROM Medical_Tests WHERE status = 'Completed' AND result_date >= ?");
$stmt_c->execute([$medical_day_start]);
$completed_today = $stmt_c->fetchColumn();
$pending_total = $pdo->query("SELECT COUNT(*) FROM Medical_Tests WHERE status = 'Requested'")->fetchColumn();
$avg_completion_time = "14 دقيقة"; // Placeholder for future logic

// 2. Top Services Today (Since 5 AM)
$stmt_s = $pdo->prepare("SELECT s.service_name, COUNT(t.test_id) as count 
                             FROM Medical_Tests t 
                             JOIN Lab_Services s ON t.service_id = s.service_id 
                             WHERE t.request_date >= ? 
                             GROUP BY t.service_id 
                             ORDER BY count DESC LIMIT 5");
$stmt_s->execute([$medical_day_start]);
$top_services = $stmt_s->fetchAll();

// 3. Recent Activity (Last 10 Completed)
$recent_activity = $pdo->query("SELECT t.*, p.name as p_name, s.service_name 
                                FROM Medical_Tests t 
                                JOIN Patients p ON t.patient_id = p.patient_id 
                                JOIN Lab_Services s ON t.service_id = s.service_id 
                                WHERE t.status = 'Completed' 
                                ORDER BY t.result_date DESC LIMIT 10")->fetchAll();

?>

            <li><a href="index.php">المختبر</a></li>
            <li><a href="analytics.php" class="active">الإحصائيات الجارية</a></li>
            <li><a href="change_password.php">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
    </div>

    <div class="content" style="height: 100vh; overflow: hidden; display: flex; flex-direction: column; padding: 25px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h1 class="page-title" style="margin: 0; border: none; font-size: 1.8rem;">التحليلات الجارية للمختبر</h1>
            <div style="color: #666; font-size: 0.9rem; background: #fff; padding: 8px 15px; border-radius: 50px; border: 1px solid #e3e6f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                تحديث تلقائي كل 5 دقائق
            </div>
        </div>

        <!-- KPI Cards -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px;">
            <div class="card" style="margin: 0; border: none; border-bottom: 4px solid var(--primary); display: flex; align-items: center; padding: 20px;">
                <div style="width: 50px; height: 50px; background: #fff4e6; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-left: 20px;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                </div>
                <div>
                    <div style="color: #888; font-size: 0.85rem; font-weight: bold;">إجمالي طلبات اليوم</div>
                    <div style="font-size: 1.8rem; font-weight: 800; color: #2c3e50;"><?php echo $total_today; ?></div>
                </div>
            </div>

            <div class="card" style="margin: 0; border: none; border-bottom: 4px solid #FF8C00; display: flex; align-items: center; padding: 20px;">
                <div style="width: 50px; height: 50px; background: #fff8f0; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-left: 20px;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#FF8C00" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                </div>
                <div>
                    <div style="color: #888; font-size: 0.85rem; font-weight: bold;">الفحوصات المنجزة</div>
                    <div style="font-size: 1.8rem; font-weight: 800; color: #2c3e50;"><?php echo $completed_today; ?></div>
                </div>
            </div>

            <div class="card" style="margin: 0; border: none; border-bottom: 4px solid var(--danger); display: flex; align-items: center; padding: 20px;">
                <div style="width: 50px; height: 50px; background: #fdf2f2; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-left: 20px;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div>
                    <div style="color: #888; font-size: 0.85rem; font-weight: bold;">المعلقة بالانتظار</div>
                    <div style="font-size: 1.8rem; font-weight: 800; color: #2c3e50;"><?php echo $pending_total; ?></div>
                </div>
            </div>

            <div class="card" style="margin: 0; border: none; border-bottom: 4px solid #2c3e50; display: flex; align-items: center; padding: 20px;">
                <div style="width: 50px; height: 50px; background: #f0f2f5; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-left: 20px;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#2c3e50" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                </div>
                <div>
                    <div style="color: #888; font-size: 0.85rem; font-weight: bold;">سرعة الإنجاز</div>
                    <div style="font-size: 1.4rem; font-weight: 800; color: #2c3e50;"><?php echo $avg_completion_time; ?></div>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 350px; gap: 20px; flex: 1; overflow: hidden;">
            
            <!-- Recent Completed Table -->
            <div class="card" style="margin: 0; padding: 0; display: flex; flex-direction: column; overflow: hidden;">
                <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 1.1rem; color: #444;">سجل النشاط الأخير (الفحوصات المكتملة)</h3>
                </div>
                <div style="flex: 1; overflow-y: auto;" class="custom-scroll">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead style="position: sticky; top: 0; background: #fafafa; z-index: 10;">
                            <tr>
                                <th style="padding: 15px; border-bottom: 2px solid #eee; text-align: right;">المريض</th>
                                <th style="padding: 15px; border-bottom: 2px solid #eee; text-align: right;">نوع الفحص</th>
                                <th style="padding: 15px; border-bottom: 2px solid #eee; text-align: center;">وقت الإنجاز</th>
                                <th style="padding: 15px; border-bottom: 2px solid #eee; text-align: center;">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($recent_activity)): ?>
                                <tr><td colspan="4" style="text-align: center; padding: 50px; color: #999;">لا يوجد نشاط مسجل لليوم بعد.</td></tr>
                            <?php else: ?>
                                <?php foreach($recent_activity as $act): ?>
                                <tr>
                                    <td style="padding: 15px; border-bottom: 1px solid #f8f9fc;">
                                        <div style="font-weight: bold; color: #2c3e50;"><?php echo $act['p_name']; ?></div>
                                        <div style="font-size: 0.75rem; color: #999;">ID: #<?php echo $act['patient_id']; ?></div>
                                    </td>
                                    <td style="padding: 15px; border-bottom: 1px solid #f8f9fc; vertical-align: middle;"><?php echo $act['service_name']; ?></td>
                                    <td style="padding: 15px; border-bottom: 1px solid #f8f9fc; text-align: center; color: #777;">
                                        <?php echo date('H:i', strtotime($act['result_date'])); ?>
                                    </td>
                                    <td style="padding: 15px; border-bottom: 1px solid #f8f9fc; text-align: center;">
                                        <span style="background: #fff8f0; color: #FF8C00; padding: 4px 12px; border-radius: 50px; font-size: 0.8rem; font-weight: bold; border: 1px solid #ffe8cc;">
                                            مكتمل
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Side Cards -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <!-- Top Services Card -->
                <div class="card" style="margin: 0; padding: 20px; flex: 1;">
                    <h3 style="margin: 0 0 20px 0; font-size: 1rem; color: #444; display: flex; align-items: center; gap: 10px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M12 20v-6M6 20V10M18 20V4"></path></svg>
                        الأكثر طلباً اليوم
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <?php if(empty($top_services)): ?>
                            <p style="color: #999; text-align: center; padding: 20px;">لا توجد بيانات كافية</p>
                        <?php else: ?>
                            <?php foreach($top_services as $svc): 
                                $percent = ($total_today > 0) ? round(($svc['count'] / $total_today) * 100) : 0;
                            ?>
                            <div>
                                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 8px;">
                                    <span style="color: #555;"><?php echo $svc['service_name']; ?></span>
                                    <span style="font-weight: bold;"><?php echo $svc['count']; ?></span>
                                </div>
                                <div style="width: 100%; height: 8px; background: #eee; border-radius: 10px; overflow: hidden;">
                                    <div style="width: <?php echo $percent; ?>%; height: 100%; background: #FF8C00; border-radius: 10px;"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action Card -->
                <div class="card" style="margin: 0; padding: 20px; background: var(--primary); color: white; border: none; text-align: center;">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-bottom: 15px; opacity: 0.8;"><path d="M12 1V23M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    <h4 style="margin: 0 0 10px 0;">توليد تقارير الأداء</h4>
                    <p style="font-size: 0.8rem; margin-bottom: 15px; opacity: 0.9;">يمكنك استخراج تقارير مفصلة للإدارة عن نشاط المختبر الشهري.</p>
                    <button onclick="openReportModal()" class="btn" style="background: white; color: var(--primary); border: none; font-weight: bold; width: 100%;">تحميل التقرير (PDF)</button>
                </div>
            </div>

        </div>

        <style>
            .custom-scroll::-webkit-scrollbar { width: 6px; }
            .custom-scroll::-webkit-scrollbar-track { background: #f1f1f1; }
            .custom-scroll::-webkit-scrollbar-thumb { background: #d1d1d1; border-radius: 10px; }
            .custom-scroll::-webkit-scrollbar-thumb:hover { background: var(--primary); }
            
            tr:hover td { background-color: #fcfcfd !important; }

            @keyframes modalPop {
                from { transform: scale(0.9); opacity: 0; }
                to { transform: scale(1); opacity: 1; }
            }
        </style>

        <!-- Report Selection Modal -->
        <div id="reportModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
            <div class="card" style="width: 400px; padding: 30px; border-radius: 20px; animation: modalPop 0.3s ease;">
                <h3 style="margin: 0 0 20px 0; color: var(--primary); text-align: center;">توليد تقرير الأداء</h3>
                <p style="text-align: center; color: #666; margin-bottom: 25px;">يرجى اختيار الفترة الزمنية للتقرير:</p>
                
                <div style="display: grid; gap: 15px; margin-bottom: 25px;">
                    <button onclick="generateReport('today')" class="btn" style="padding: 15px; border: 2px solid #eee; background: white; color: #333; font-weight: bold; display: flex; justify-content: space-between; align-items: center;">
                        <span>تقرير النشاط اليومي</span>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </button>
                    <button onclick="generateReport('month')" class="btn" style="padding: 15px; border: 2px solid #eee; background: white; color: #333; font-weight: bold; display: flex; justify-content: space-between; align-items: center;">
                        <span>تقرير النشاط الشهري</span>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                    </button>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button onclick="closeReportModal()" class="btn" style="flex: 1; background: #eee; color: #666;">إلغاء</button>
                </div>
            </div>
        </div>

        <script>
            function openReportModal() {
                document.getElementById('reportModal').style.display = 'flex';
            }
            function closeReportModal() {
                document.getElementById('reportModal').style.display = 'none';
            }
            function generateReport(period) {
                closeReportModal();
                const win = window.open('reports_print.php?period=' + period, '_blank', 'height=800,width=1000');
            }

        </script>
    </div>
</body>
</html>
