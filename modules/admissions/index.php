<?php
require '../../config/database.php';
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Admissions' && $_SESSION['role'] !== 'Reception') { die("غير مصرح"); }

$message = "";

// 1. Handle Admission Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admit_patient'])) {
    $patient_id = $_POST['patient_id'];
    $doctor_id = $_POST['doctor_id'];
    $room_number = $_POST['room_number'];

    if (empty($patient_id) || empty($doctor_id) || empty($room_number)) {
        $message = "الرجاء ملء جميع الحقول.";
    } else {
        try {
            // Validate Patient Existence
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM Patients WHERE patient_id = ?");
            $stmt_check->execute([$patient_id]);
            if ($stmt_check->fetchColumn() == 0) {
                $message = "خطأ: رقم ملف المريض غير موجود في النظام.";
            } else {
                // Check if already admitted
                $check = $pdo->prepare("SELECT COUNT(*) FROM Admissions WHERE patient_id = ? AND status = 'Active'");
                $check->execute([$patient_id]);
                if ($check->fetchColumn() > 0) {
                    $message = "المريض مسجل في الرقود حالياً!";
                } else {
                    $insert = $pdo->prepare("INSERT INTO Admissions (patient_id, doctor_id, room_number) VALUES (?, ?, ?)");
                    $insert->execute([$patient_id, $doctor_id, $room_number]);
                    $message = "تم تسجيل دخول المريض للرقود بنجاح.";
                }
            }
        } catch (PDOException $e) { $message = "خطأ في القاعدة: " . $e->getMessage(); }
    }
}

// 2. Fetch Active Admissions
$stmt = $pdo->query("
    SELECT a.*, p.name as patient_name, d.name as doctor_name 
    FROM Admissions a 
    JOIN Patients p ON a.patient_id = p.patient_id 
    LEFT JOIN Doctors d ON a.doctor_id = d.doctor_id 
    WHERE a.status = 'Active' 
    ORDER BY a.admission_date DESC
");
$admissions = $stmt->fetchAll();

// 3. Search Logic
$search_results = [];
if (isset($_GET['search_query'])) {
    $q = $_GET['search_query'];
    $sql = is_numeric($q) ? "SELECT * FROM Patients WHERE patient_id = ?" : "SELECT * FROM Patients WHERE name LIKE ?";
    $stmt_search = $pdo->prepare($sql);
    $stmt_search->execute(is_numeric($q) ? [$q] : ["%$q%"]);
    $search_results = $stmt_search->fetchAll();
}

$doctors = $pdo->query("SELECT * FROM Doctors")->fetchAll();
$total_rooms = 50;
$occupied_rooms = count($admissions);
?>
            <li><a href="index.php" class="active">لوحة الرقود</a></li>
            <li><a href="change_password.php">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
    </div>

    <div class="content" style="padding: 15px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h1 class="page-title" style="margin: 0; border:none; font-size: 1.5rem;">إدارة الرقود (Inpatient)</h1>
            <?php if($message): ?>
                <?php $alert_class = (strpos($message, 'خطأ') !== false) ? 'alert-danger' : 'alert-success'; ?>
                <div class="alert <?php echo $alert_class; ?>" style="margin: 0; padding: 5px 15px; font-size: 0.9rem;"><?php echo $message; ?></div>
            <?php endif; ?>
        </div>

        <div style="display: grid; grid-template-columns: 320px 1fr 300px; gap: 15px; flex: 1; overflow: hidden;">
            
            <!-- COLUMN 1: ADMISSION FORM -->
            <div class="card" style="margin: 0; border-top: 5px solid var(--primary); padding: 15px; overflow-y: auto;">
                <h3 style="margin-top:0; font-size: 1rem; color: var(--primary);">أمر رقود جديد</h3>
                <form method="POST">
                    <input type="hidden" name="admit_patient" value="1">
                    <div style="margin-bottom: 12px;">
                        <label>رقم ملف المريض:</label>
                        <input type="number" name="patient_id" id="patient_id_field" required style="padding: 8px;">
                        <small style="color: #888;">استخدم البحث لتجد الرقم</small>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label>الطبيب المشرف:</label>
                        <select name="doctor_id" required style="padding: 8px;">
                            <option value="">-- اختر الطبيب --</option>
                            <?php foreach($doctors as $d): ?><option value="<?php echo $d['doctor_id']; ?>"><?php echo $d['name']; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label>رقم الغرفة:</label>
                        <input type="text" name="room_number" required style="padding: 8px;">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; font-weight: bold;">تأكيد الرقود</button>
                </form>

                <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
                
                <h3 style="font-size: 1rem; color: var(--primary);">حالة الأجنحة</h3>
                <div style="background: var(--primary-light); padding: 15px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.8rem; color: #666;">نسبة الإشغال</div>
                    <div style="font-size: 1.5rem; font-weight: 900; color: var(--primary-dark);"><?php echo round(($occupied_rooms/$total_rooms)*100, 1); ?>%</div>
                    <div style="font-size: 0.75rem; color: #888;">(<?php echo $occupied_rooms; ?> من <?php echo $total_rooms; ?> غرفة)</div>
                </div>
            </div>

            <!-- COLUMN 2: ACTIVE LIST -->
            <div class="card" style="margin:0; padding: 0; display: flex; flex-direction: column; overflow: hidden; border-top: 5px solid var(--primary-dark);">
                <div style="padding: 12px; border-bottom: 1px solid #eee; background: #fffcf5;">
                    <h3 style="margin: 0; font-size: 1rem; color: var(--primary-dark);">المرضى المرقدين حالياً</h3>
                </div>
                <div style="flex: 1; overflow-y: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                        <thead style="position: sticky; top: 0; background: #f9f9f9; z-index: 1;">
                            <tr>
                                <th style="padding: 10px;">المريض</th>
                                <th style="padding: 10px;">الطبيب</th>
                                <th style="padding: 10px;">الغرفة</th>
                                <th style="padding: 10px;">التاريخ</th>
                                <th style="padding: 10px;">إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($admissions as $adm): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 8px;"><strong><?php echo htmlspecialchars($adm['patient_name']); ?></strong></td>
                                    <td style="padding: 8px;"><?php echo htmlspecialchars($adm['doctor_name']); ?></td>
                                    <td style="padding: 8px;"><span style="background: var(--primary-light); color: var(--primary-dark); padding: 2px 6px; border-radius: 4px; font-weight: bold;"><?php echo $adm['room_number']; ?></span></td>
                                    <td style="padding: 8px; color: #888; font-size: 0.75rem;"><?php echo date('m/d H:i', strtotime($adm['admission_date'])); ?></td>
                                    <td style="padding: 8px; text-align: center;">
                                        <a href="discharge.php?id=<?php echo $adm['admission_id']; ?>" class="btn btn-danger" style="padding: 3px 8px; font-size: 0.7rem;" onclick="return confirm('تأكيد خروج المريض؟')">خروج</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- COLUMN 3: SEARCH -->
            <div class="card" style="margin: 0; padding: 15px; display: flex; flex-direction: column; overflow: hidden; border-top: 5px solid lightgrey;">
                <h3 style="margin-top:0; font-size: 1rem;">بحث عن مريض</h3>
                <form method="GET" style="display: flex; gap: 5px; margin-bottom: 12px;">
                    <input type="text" name="search_query" placeholder="الاسم أو الرقم..." required style="margin:0; padding: 6px; font-size: 0.85rem;">
                    <button type="submit" class="btn btn-info" style="padding: 6px 10px; font-size: 0.85rem;">بحث</button>
                </form>
                
                <div style="flex: 1; overflow-y: auto;">
                    <?php if(!empty($search_results)): ?>
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
                            <tbody>
                                <?php foreach($search_results as $res): ?>
                                <tr style="border-bottom: 1px solid #eee; cursor: pointer; transition: 0.2s;" onclick="selectPatient(<?php echo $res['patient_id']; ?>, '<?php echo addslashes($res['name']); ?>')">
                                    <td style="padding: 8px;">
                                        <strong><?php echo htmlspecialchars($res['name']); ?></strong>
                                        <div style="font-size: 0.7rem; color: #999;">رقم الملف: <?php echo $res['patient_id']; ?></div>
                                    </td>
                                    <td style="text-align: left; vertical-align: middle;">
                                        <span style="color: var(--primary); font-size: 1.2rem;">←</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: #bbb; margin-top: 30px; font-size: 0.8rem;">ابحث لاختيار مريض للرقود</p>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <script>
    function selectPatient(id, name) {
        document.getElementById('patient_id_field').value = id;
        // Optionally show name in UI or highlight
    }
    </script>
</body>
</html>
