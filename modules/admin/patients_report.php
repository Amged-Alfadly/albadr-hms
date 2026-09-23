<?php
require '../../config/database.php';
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Admin') { die("غير مصرح"); }

// Fetch all patients with their associated data
$search = isset($_GET['search']) ? $_GET['search'] : '';
$doctor_filter = isset($_GET['doctor_id']) ? $_GET['doctor_id'] : '';

$sql = "SELECT p.*, 
        GROUP_CONCAT(DISTINCT d.name SEPARATOR ', ') as doctors,
        COUNT(DISTINCT v.visit_id) as visit_count
        FROM Patients p
        LEFT JOIN Visits v ON p.patient_id = v.patient_id
        LEFT JOIN Doctors d ON v.doctor_id = d.doctor_id
        WHERE 1=1";

if ($search) {
    $sql .= " AND (p.name LIKE :search OR p.patient_id = :search_id)";
}
if ($doctor_filter) {
    $sql .= " AND v.doctor_id = :doctor_id";
}

$sql .= " GROUP BY p.patient_id ORDER BY p.registration_date DESC";

$stmt = $pdo->prepare($sql);
if ($search) {
    $stmt->bindValue(':search', "%$search%");
    $stmt->bindValue(':search_id', $search);
}
if ($doctor_filter) {
    $stmt->bindValue(':doctor_id', $doctor_filter);
}
$stmt->execute();
$patients = $stmt->fetchAll();

// Fetch all doctors for filter
$doctors = $pdo->query("SELECT * FROM Doctors")->fetchAll();
?>
            <li><a href="index.php">الرئيسـية</a></li>
            <li><a href="patients_report.php" class="active">تقرير المرضى الشامل</a></li>
            <li><a href="analytics.php">التحليلات والإحصائيات</a></li>
            <li><a href="tracking.php">تتبع المرضى </a></li>
            <li><a href="change_password.php">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">تسجيل الخروج</a>
    </div>

    <div class="content">
        <h1 class="page-title">تقرير المرضى الشامل</h1>

        <!-- Search and Filter -->
        <div class="card" style="margin-bottom: 20px;">
            <form method="GET" style="display: flex; gap: 10px; align-items: flex-end;">
                <div style="flex: 2;">
                    <label>بحث بالاسم أو الرقم:</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="اسم المريض أو الرقم..." style="margin: 0;">
                </div>
                <div style="flex: 1;">
                    <label>فلترة حسب الطبيب:</label>
                    <select name="doctor_id" style="margin: 0;">
                        <option value="">الكل</option>
                        <?php foreach($doctors as $d): ?>
                            <option value="<?php echo $d['doctor_id']; ?>" <?php echo $doctor_filter == $d['doctor_id'] ? 'selected' : ''; ?>>
                                <?php echo $d['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="padding: 10px 25px; font-weight: bold;">بحث في السجلات</button>
                <?php if($search || $doctor_filter): ?>
                    <a href="patients_report.php" class="btn btn-secondary" style="padding: 10px 20px; background: #95a5a6; display: flex; align-items: center; justify-content: center;">إعادة تعيين</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Patients Table -->
        <div class="card">
            <h3 style="margin-top: 0;">قائمة المرضى (<?php echo count($patients); ?>)</h3>
            
            <?php if(count($patients) > 0): ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--secondary); color: white;">
                                <th style="padding: 12px; text-align: center;">الرقم</th>
                                <th style="padding: 12px;">الاسم</th>
                                <th style="padding: 12px; text-align: center;">العمر</th>
                                <th style="padding: 12px; text-align: center;">الجنس</th>
                                <th style="padding: 12px;">الهاتف</th>
                                <th style="padding: 12px;">الأطباء</th>
                                <th style="padding: 12px; text-align: center;">الزيارات</th>
                                <th style="padding: 12px; text-align: center;">تاريخ التسجيل</th>
                                <th style="padding: 12px; text-align: center;">التفاصيل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($patients as $p): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 10px; text-align: center; font-weight: bold; color: var(--primary);"><?php echo $p['patient_id']; ?></td>
                                    <td style="padding: 10px;"><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                                    <td style="padding: 10px; text-align: center;"><?php echo $p['age']; ?></td>
                                    <td style="padding: 10px; text-align: center;"><?php echo ($p['gender'] == 'Male' ? 'ذكر' : ($p['gender'] == 'Female' ? 'أنثى' : $p['gender'])); ?></td>
                                    <td style="padding: 10px;"><?php echo $p['phone'] ?: '-'; ?></td>
                                    <td style="padding: 10px; font-size: 0.85rem; color: #555;"><?php echo $p['doctors'] ?: 'لا يوجد'; ?></td>
                                    <td style="padding: 10px; text-align: center;">
                                        <span style="background: var(--primary); color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">
                                            <?php echo $p['visit_count']; ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px; text-align: center; font-size: 0.85rem; color: #777;">
                                        <?php echo date('Y-m-d H:i', strtotime($p['registration_date'])); ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center;">
                                        <button onclick="showDetails(<?php echo $p['patient_id']; ?>)" class="btn btn-primary" style="padding: 5px 15px; font-size: 0.8rem; font-weight: bold;">
                                            عرض التفاصيل
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: #999; padding: 40px;">لا توجد نتائج</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(5px); overflow-y: auto;">
        <div style="background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); max-width: 900px; width: 95%; max-height: 90vh; overflow-y: auto; margin: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid var(--primary); padding-bottom: 10px;">
                <h3 style="margin: 0; color: var(--primary);">تفاصيل المريض</h3>
                <button onclick="closeDetails()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #999;">&times;</button>
            </div>
            
            <div id="detailsContent" style="min-height: 200px;">
                <div style="text-align: center; padding: 40px; color: #999;">جاري التحميل...</div>
            </div>
        </div>
    </div>

    <script>
    function showDetails(patientId) {
        document.getElementById('detailsModal').style.display = 'flex';
        document.getElementById('detailsContent').innerHTML = '<div style="text-align: center; padding: 40px; color: #999;">جاري التحميل...</div>';
        
        // Fetch patient details via AJAX
        fetch('get_patient_details.php?patient_id=' + patientId)
            .then(response => response.json())
            .then(data => {
                let html = `
                    <div style="background: var(--primary-light); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 10px 0; color: var(--primary);">المعلومات الأساسية</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.9rem;">
                            <div><strong>الرقم:</strong> ${data.patient.patient_id}</div>
                            <div><strong>الاسم:</strong> ${data.patient.name}</div>
                            <div><strong>العمر:</strong> ${data.patient.age}</div>
                            <div><strong>الجنس:</strong> ${data.patient.gender === 'Male' ? 'ذكر' : (data.patient.gender === 'Female' ? 'أنثى' : data.patient.gender)}</div>
                            <div><strong>الهاتف:</strong> ${data.patient.phone || '-'}</div>
                            <div><strong>تاريخ التسجيل:</strong> ${data.patient.registration_date}</div>
                        </div>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <h4 style="color: var(--primary); border-bottom: 1px solid #eee; padding-bottom: 5px;">الزيارات والتشخيصات</h4>
                        ${data.visits.length > 0 ? data.visits.map(v => `
                            <div style="background: #f9f9f9; padding: 12px; margin-bottom: 10px; border-radius: 5px; border-right: 3px solid var(--primary);">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                    <strong>الطبيب: ${v.doctor_name}</strong>
                                    <span style="font-size: 0.85rem; color: #777;">${v.visit_date}</span>
                                </div>
                                ${v.diagnosis ? `<div style="background: white; padding: 8px; border-radius: 4px; font-size: 0.9rem;"><strong>التشخيص:</strong> ${v.diagnosis}</div>` : '<div style="color: #999; font-size: 0.85rem;">لا يوجد تشخيص</div>'}
                            </div>
                        `).join('') : '<p style="color: #999; text-align: center;">لا توجد زيارات</p>'}
                    </div>

                    <div>
                        <h4 style="color: var(--primary); border-bottom: 1px solid #eee; padding-bottom: 5px;">نتائج الفحوصات المخبرية</h4>
                        ${data.lab_results.length > 0 ? `
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px;">
                                ${data.lab_results.map(lr => `
                                    <div style="background: ${lr.status === 'Completed' ? '#e8f5e9' : '#fff8f0'}; padding: 10px; border-radius: 5px; border: 1px solid ${lr.status === 'Completed' ? '#c3e6cb' : '#ffe8cc'};">
                                        <div style="font-weight: bold; font-size: 0.85rem; margin-bottom: 3px; color: ${lr.status === 'Completed' ? '#27ae60' : '#FF8C00'};">${lr.service_name}</div>
                                        <div style="font-size: 0.9rem; color: #333;">${lr.result || 'قيد الانتظار...'}</div>
                                        <div style="font-size: 0.75rem; color: #777; margin-top: 3px;">${lr.request_date}</div>
                                    </div>
                                `).join('')}
                            </div>
                        ` : '<p style="color: #999; text-align: center;">لا توجد فحوصات</p>'}
                    </div>
                `;
                document.getElementById('detailsContent').innerHTML = html;
            })
            .catch(error => {
                document.getElementById('detailsContent').innerHTML = '<div style="text-align: center; padding: 40px; color: #e74c3c;">حدث خطأ في تحميل البيانات</div>';
                console.error('Error:', error);
            });
    }

    function closeDetails() {
        document.getElementById('detailsModal').style.display = 'none';
    }
    </script>
</body>
</html>
