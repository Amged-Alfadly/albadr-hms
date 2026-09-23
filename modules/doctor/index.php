<?php
require '../../config/database.php';
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Doctor' && $_SESSION['role'] !== 'Admin') { die("غير مصرح"); }

// Get Doctor ID and Name
$stmt = $pdo->prepare("SELECT doctor_id, name FROM Doctors WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$doctor = $stmt->fetch() ?: ['doctor_id' => 0, 'name' => 'الطبيب المشرف'];
$doctor_id = $doctor['doctor_id'];
$message = "";

// Handle POST actions (Diagnosis / Lab Order)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($doctor_id <= 0) {
        $message = "خطأ: لا يمكنك تنفيذ هذا الإجراء لأن حسابك ليس مسجلاً كطبيب في النظام. يرجى التواصل مع المسؤول لإضافتك كطبيب.";
    } else {
        $pid = $_POST['patient_id'];
        $vid = $_POST['visit_id'] ?? 0;
        
        try {
            if (isset($_POST['add_diagnosis'])) {
                // Security Check: Verify patient is assigned to this doctor and visit is pending
                $check_assignment = $pdo->prepare("SELECT COUNT(*) FROM Visits WHERE patient_id = ? AND doctor_id = ? AND status = 'Pending'");
                $check_assignment->execute([$pid, $doctor_id]);
                if ($check_assignment->fetchColumn() == 0) {
                    die("خطأ أمني: هذا المريض غير محال إليك أو الزيارة مكتملة.");
                }

                $pdo->prepare("INSERT INTO Diagnoses (patient_id, doctor_id, diagnosis_details, visit_id) VALUES (?, ?, ?, ?)")
                    ->execute([$pid, $doctor_id, $_POST['details'], $vid ?: NULL]);
                if($vid) {
                    $pdo->prepare("UPDATE Visits SET status = 'Completed' WHERE visit_id = ?")->execute([$vid]);
                }
                $message = "تم حفظ التشخيص وإنهاء الزيارة بنجاح.";
            } 
            elseif (isset($_POST['add_prescription'])) {
                $meds = $_POST['medications'];
                $notes = $_POST['notes'] ?? '';
                $pdo->prepare("INSERT INTO Prescriptions (patient_id, doctor_id, visit_id, medications, notes) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$pid, $doctor_id, $vid ?: NULL, $meds, $notes]);
                $message = "تم حفظ الوصفة الطبية بنجاح.";
            }
            elseif (isset($_POST['order_tests'])) {
                // Security Check: Verify patient assignment
                $check_assignment = $pdo->prepare("SELECT COUNT(*) FROM Visits WHERE patient_id = ? AND doctor_id = ? AND status = 'Pending'");
                $check_assignment->execute([$pid, $doctor_id]);
                if ($check_assignment->fetchColumn() == 0) {
                    die("خطأ أمني: هذا المريض غير محال إليك.");
                }

                // Check if already has pending tests
                $check = $pdo->prepare("SELECT COUNT(*) FROM Medical_Tests WHERE patient_id = ? AND status = 'Requested'");
                $check->execute([$pid]);
                if($check->fetchColumn() > 0) {
                    $message = "خطأ: يوجد فحوصات قيد التنفيذ لهذا المريض حالياً. لا يمكن إرسال طلبات جديدة حتى تكتمل النتائج السابقة.";
                } elseif(isset($_POST['services']) && is_array($_POST['services'])) {
                    $sql = "INSERT INTO Medical_Tests (patient_id, doctor_id, service_id, status, visit_id) VALUES (?, ?, ?, 'Requested', ?)";
                    $stmt = $pdo->prepare($sql);
                    foreach($_POST['services'] as $svc_id) {
                        $stmt->execute([$pid, $doctor_id, $svc_id, $vid ?: NULL]);
                    }
                    $message = "تم طلب الفحوصات وإرسالها للمختبر.";
                }
            }
        } catch (PDOException $e) {
            $message = "خطأ في قاعدة البيانات: " . $e->getMessage();
        }
    }
}

// 1. Fetch Patients in Queue
$patients = $pdo->query("SELECT v.visit_id as distinct_visit_id, p.*, v.visit_date FROM Visits v JOIN Patients p ON v.patient_id = p.patient_id WHERE v.doctor_id = $doctor_id AND v.status = 'Pending'")->fetchAll();

// 2. Fetch ALL Completed Lab Results for these patients (Efficient batching)
$patient_ids = array_column($patients, 'patient_id');
$results_by_patient = [];
if(!empty($patient_ids)){
    $placeholders = str_repeat('?,', count($patient_ids) - 1) . '?';
    $sql_results = "SELECT t.*, s.service_name FROM Medical_Tests t JOIN Lab_Services s ON t.service_id = s.service_id WHERE t.patient_id IN ($placeholders) AND t.status = 'Completed' ORDER BY t.result_date DESC";
    $stmt_res = $pdo->prepare($sql_results);
    $stmt_res->execute($patient_ids);
    $all_results = $stmt_res->fetchAll();
    
    foreach($all_results as $res) {
        $results_by_patient[$res['patient_id']][] = [
            'service' => $res['service_name'],
            'value' => $res['result'],
            'date' => date('d/m H:i', strtotime($res['result_date']))
        ];
    }
}

$services = $pdo->query("SELECT * FROM Lab_Services")->fetchAll();

// Search logic - RESTRICTED TO ASSIGNED PATIENTS
$search_res = null;
if (isset($_GET['search_id'])) {
    $q = $_GET['search_id'];
    // Search must join with Visits to ensure it belongs to this doctor and is Pending
    $sql_search = "SELECT p.* FROM Patients p 
                   JOIN Visits v ON p.patient_id = v.patient_id 
                   WHERE (p.patient_id = ? OR p.name LIKE ?) 
                   AND v.doctor_id = ? AND v.status = 'Pending' LIMIT 1";
    $stmt_search = $pdo->prepare($sql_search);
    $stmt_search->execute([intval($q), "%$q%", $doctor_id]);
    $search_res = $stmt_search->fetch();

    // Also fetch results for searched patient if found
    if($search_res) {
        $searched_results = $pdo->query("SELECT t.*, s.service_name FROM Medical_Tests t JOIN Lab_Services s ON t.service_id = s.service_id WHERE t.patient_id = " . $search_res['patient_id'] . " AND t.status = 'Completed'")->fetchAll();
        $results_by_patient[$search_res['patient_id']] = array_map(function($r){
            return ['service' => $r['service_name'], 'value' => $r['result'], 'date' => date('d/m H:i', strtotime($r['result_date']))];
        }, $searched_results);
    }
}

// 3. Fetch Pending Test Status for each patient in queue
$pending_tests = [];
$pending_res = $pdo->query("SELECT DISTINCT patient_id FROM Medical_Tests WHERE status = 'Requested'")->fetchAll(PDO::FETCH_COLUMN);
foreach($pending_res as $pid_p) { $pending_tests[$pid_p] = true; }
?>
            <li><a href="index.php" class="active">العيادة</a></li>
            <li><a href="analytics.php">التحليلات الطبية</a></li>
            <li><a href="change_password.php">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
    </div>

    <!-- Main Content: GRID LAYOUT (No Scroll) -->
    <div class="content" style="height: 100vh; overflow: hidden; padding: 20px; display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h1 class="page-title" style="margin: 0;">عيادة الطبيب</h1>
            <?php if($message): ?>
                <div id="main-alert" class="alert <?php echo strpos($message, 'خطأ') !== false ? 'alert-danger' : 'alert-success'; ?>" style="margin: 0; padding: 10px 20px;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
        </div>

        <div style="display: grid; grid-template-columns: 280px 1fr 300px; grid-template-rows: 1fr; gap: 15px; flex: 1; overflow: hidden; margin-top: 15px;">
            
            <!-- Column 1: Queue -->
            <div style="display: flex; flex-direction: column; gap: 15px; overflow: hidden;">
                <div class="card" style="padding: 10px; margin: 0;">
                    <form method="GET">
                        <input type="number" name="search_id" placeholder="بحث مريض ID..." required style="margin: 0; padding: 8px; width: 68%;">
                        <button class="btn btn-primary" style="width: 25%; padding: 8px;">بحث</button>
                    </form>
                    <?php if($search_res): ?>
                        <div class="patient-item" onclick="openAction(<?php echo $search_res['patient_id']; ?>, 0, '<?php echo $search_res['name']; ?>')" style="background: #fdf2e9; padding: 8px; margin-top: 10px; border-radius: 5px; border: 1px solid var(--primary);">
                            <strong><?php echo $search_res['name']; ?></strong> (ID: <?php echo $search_res['patient_id']; ?>)
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card" style="flex: 1; margin: 0; padding: 10px; overflow-y: auto;">
                    <h4 style="margin: 0 0 10px 0; border-bottom: 2px solid var(--primary); padding-bottom: 5px;">قائمة الانتظار</h4>
                    <?php if(count($patients)): ?>
                        <?php foreach($patients as $p): 
                            $has_results = isset($results_by_patient[$p['patient_id']]);
                        ?>
                        <div class="patient-item" onclick="openAction(<?php echo $p['patient_id']; ?>, <?php echo $p['distinct_visit_id']; ?>, '<?php echo $p['name']; ?>')" style="padding: 12px; border-bottom: 1px solid #eee; cursor: pointer; position: relative;">
                            <strong><?php echo $p['name']; ?></strong>
                            <div style="font-size: 0.8rem; color: #777;">وقت الوصول: <?php echo date('H:i', strtotime($p['visit_date'])); ?></div>
                            <?php if($has_results): ?>
                                <span title="نتائج مختبر جاهزة" style="position: absolute; left: 10px; top: 15px; color: var(--success); font-size: 0.75rem; font-weight: bold; border: 1px solid var(--success); padding: 2px 4px; border-radius: 3px;">نتائج</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 30px; color: #ccc;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 10px; opacity: 0.5;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            <p style="font-size: 0.9rem;">لا يوجد مرضى في الانتظار</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Column 2: Results & Diagnosis (Center) -->
            <div style="display: flex; flex-direction: column; gap: 15px; overflow: hidden;">
                
                <!-- LAB RESULTS VIEWER -->
                <div id="results-card" class="card" style="margin: 0; padding: 15px; flex: 1; min-height: 0; display: flex; flex-direction: column; border-top: 4px solid var(--primary); display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h3 style="margin: 0; color: var(--primary); font-size: 1rem;">نتائج المختبر الأخيرة</h3>
                        <div style="display: flex; gap: 8px;">
                            <a id="patient-analytics-btn-lab" href="analytics.php" class="btn" style="padding: 5px 15px; font-size: 0.8rem; background: #e67e22; color: white; display: flex; align-items: center; gap: 5px; text-decoration: none;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                                <span>التحليلات للمريض</span>
                            </a>
                            <button onclick="previewMedicalReport()" class="btn btn-primary" style="padding: 5px 15px; font-size: 0.8rem; background: var(--primary); display: flex; align-items: center; gap: 5px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                <span>معاينة التقرير</span>
                            </button>
                        </div>
                    </div>
                    <div id="results-list" style="flex: 1; overflow-y: auto; background: var(--primary-light); padding: 10px; border-radius: 5px;" class="custom-scroll">
                        <!-- JS Injected Results -->
                    </div>
                </div>

                <div id="diagnosis-card" class="card" style="margin: 0; flex: 1.5; min-height: 0; display: flex; flex-direction: column; border-top: 4px solid var(--success); display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 id="diag-title" style="margin: 0; color: #444; font-size: 1.1rem;">اختر مريضاً للبدء</h3>
                        <div style="display: flex; gap: 8px;">
                            <button onclick="openPrescriptionModal()" class="btn" style="padding: 6px 15px; font-size: 0.85rem; background: var(--primary); color: white; border: none; border-radius: 5px; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: 0.3s;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                <span>إضافة وصفة طبية</span>
                            </button>
                            <a id="patient-analytics-btn-diag" href="analytics.php" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: #FFF5E6; color: #E67E22; border: 1px solid #FFE5B4; text-decoration: none; display: none; border-radius: 5px;">
                                🔬 التحليلات السريرية
                            </a>
                        </div>
                    </div>
                    
                    <div id="medical-history" style="display:none; margin-bottom: 20px; padding: 15px; background: #fdfdfe; border: 1px solid #edf2f7; border-radius: 10px; max-height: 200px; overflow-y: auto;">
                        <h4 style="margin: 0 0 10px 0; font-size: 0.9rem; color: #E67E22; border-bottom: 1px solid #FFE5B4; padding-bottom: 5px; display: flex; align-items: center; gap: 8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                            <span>التاريخ الطبي (التشخيصات السابقة)</span>
                        </h4>
                        <div id="history-list" style="font-size: 0.85rem; color: #555;">
                            <!-- JS Injected History -->
                        </div>
                    </div>

                    <form method="POST" id="diag-form" style="display:none; flex: 1; flex-direction: column;">
                        <input type="hidden" name="add_diagnosis" value="1">
                        <input type="hidden" name="patient_id" id="d_pid">
                        <input type="hidden" name="visit_id" id="d_vid">
                        
                        <label>التشخيص الطبي الحالي:</label>
                        <textarea name="details" required style="flex: 1; resize: none; border: 1px solid var(--primary); padding: 15px; font-size: 1.1rem; margin-bottom: 15px; background: #fffdf9;" placeholder="اكتب التشخيص هنا..."></textarea>
                        
                        <?php if ($doctor_id > 0): ?>
                        <button class="btn btn-success" style="padding: 12px; font-size: 1.1rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">حفظ واعتماد التشخيص</button>
                        <?php else: ?>
                        <div class="alert alert-danger" style="font-size: 0.9rem;">عذراً، يجب تسجيل حسابك كطبيب لتتمكن من إضافة تشخيص.</div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Column 3: Order Lab (Always Visible for full height) -->
            <div id="lab-card" class="card" style="margin: 0; border-top: 4px solid var(--primary); display: flex; flex-direction: column; height: 100%; overflow: hidden; padding: 10px 10px 60px 10px;">
                <h3 style="margin: 0 0 10px 0; font-size: 1rem;">طلب فحص جديد</h3>
                <form method="POST" id="lab-form" style="display:none; flex: 1; flex-direction: column; overflow: hidden;">
                    <input type="hidden" name="order_tests" value="1">
                    <input type="hidden" name="patient_id" id="l_pid">
                    <input type="hidden" name="visit_id" id="l_vid">
                    
                    <div id="lab-form-inner" style="flex: 1; display: flex; flex-direction: column; overflow: hidden;">
                        <div style="flex: 1; overflow-y: auto; padding-right: 5px; min-height: 0;" class="custom-scroll">
                            <?php foreach($services as $s): ?>
                            <label style="display: flex; align-items: center; padding: 8px 10px; border-bottom: 1px solid #f0f0f0; cursor: pointer; border-radius: 4px; transition: 0.1s; margin-bottom: 2px;" class="svc-label">
                                <input type="checkbox" name="services[]" value="<?php echo $s['service_id']; ?>" style="width: auto; margin-left: 10px;">
                                <span style="font-size: 0.95rem;"><?php echo $s['service_name']; ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($doctor_id > 0): ?>
                        <div style="padding-top: 10px; border-top: 1px solid #eee; margin-bottom: 30px;">
                            <button class="btn btn-success" style="width: 100%; padding: 15px; font-weight: bold; font-size: 1.1rem; box-shadow: 0 4px 10px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: center; gap: 10px;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                                <span>إرسال الطلبات للمختبر</span>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div id="pending-warning" style="display: none; flex: 1; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 20px; color: #E67E22;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 15px; opacity: 0.6;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <h4 style="margin: 0; color: #D35400;">فحوصات قيد التنفيذ</h4>
                        <p style="font-size: 0.85rem; color: #888;">يوجد طلبات مسبقة في المختبر لم تكتمل بعد. لا يمكنك إرسال طلبات جديدة حالياً.</p>
                    </div>
                </form>
                <div id="lab-placeholder" style="flex: 1; display: flex; align-items: center; justify-content: center; color: #bbb;">يرجى فتح ملف مريض</div>
            </div>

        </div>

        <!-- PRESCRIPTION MODAL (Premium Design) -->
        <div id="prescModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10001; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
            <div style="background: white; width: 500px; border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px rgba(0,0,0,0.2); animation: modalIn 0.3s ease-out;">
                <!-- Header -->
                <div style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%); padding: 25px; color: white; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h3 style="margin: 0; font-size: 1.3rem; font-weight: 800;">إضافة وصفة طبية جديدة</h3>
                        <p id="presc-patient-name" style="margin: 5px 0 0; opacity: 0.9; font-size: 0.85rem;">للمريض: جاري التحميل...</p>
                    </div>
                    <button onclick="closePrescriptionModal()" style="background: rgba(255,255,255,0.2); border: none; width: 35px; height: 35px; border-radius: 50%; color: white; cursor: pointer; font-size: 1.2rem; display: flex; align-items: center; justify-content: center;">&times;</button>
                </div>

                <!-- Body -->
                <form method="POST" style="padding: 25px;">
                    <input type="hidden" name="add_prescription" value="1">
                    <input type="hidden" name="patient_id" id="modal_p_pid">
                    <input type="hidden" name="visit_id" id="modal_p_vid">
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 700; color: #2d3436; font-size: 0.9rem;">قائمة الأدوية والجرعات <span style="color: var(--primary);">*</span></label>
                        <textarea name="medications" required 
                            style="width: 100%; height: 150px; border: 2px solid #eee; border-radius: 12px; padding: 15px; font-size: 1rem; resize: none; transition: 0.3s; background: #fafafa;" 
                            placeholder="مثال: Panadol 500mg - حبة مرتين يومياً"></textarea>
                    </div>

                    <div style="margin-bottom: 25px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 700; color: #2d3436; font-size: 0.9rem;">توصيات إضافية</label>
                        <input type="text" name="notes" 
                            style="width: 100%; border: 2px solid #eee; border-radius: 12px; padding: 12px 15px; font-size: 0.95rem; background: #fafafa; transition: 0.3s;" 
                            placeholder="مثال: الراحة التامة، شرب السوائل...">
                    </div>

                    <button class="btn btn-success" style="width: 100%; padding: 15px; border-radius: 12px; font-size: 1.1rem; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 10px 20px rgba(46, 204, 113, 0.2); border: none; cursor: pointer;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span>حفظ وإرسال الوصفة</span>
                    </button>
                </form>
            </div>
        </div>

        <style>
            @keyframes modalIn { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
            textarea:focus, input:focus { border-color: var(--primary) !important; background: white !important; outline: none; }
        </style>

        <!-- MEDICAL REPORT PREVIEW MODAL -->
        <div id="reportModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(5px);">
            <div style="background: #f4f4f4; width: 900px; max-height: 90vh; border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
                <!-- Modal Header -->
                <div style="padding: 15px 25px; background: white; border-bottom: 2px solid #E67E22; display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="margin: 0; color: #333; font-size: 1.2rem;">معاينة التقرير الطبي</h2>
                    <button onclick="closeReportModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #999;">&times;</button>
                </div>

                <!-- Modal Body (The Report Page) -->
                <div id="modalPrintArea" style="flex: 1; overflow-y: auto; padding: 20px; display: flex; justify-content: center; background: #525659;">
                    <div id="medicalReportTemplate" style="width: 210mm; min-height: 297mm; padding: 15mm; background: white; font-family: 'Segoe UI', Arial, sans-serif; direction: rtl; box-sizing: border-box; box-shadow: 0 0 15px rgba(0,0,0,0.5); position: relative;">
                        
                        <!-- Header -->
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 15px; position: relative; z-index: 1;">
                            <div style="text-align: right;">
                                <h1 style="margin: 0; color: #E67E22; font-size: 26px; font-weight: 900;">مستشفى البدر التخصصي</h1>
                                <p style="margin: 2px 0; color: #555; font-size: 14px; font-weight: bold;">AL-BADR SPECIALIZED HOSPITAL</p>
                                <p style="margin: 0; color: #777; font-size: 12px;">قسم المختبرات الطبية الحديثة</p>
                            </div>
                            <div style="text-align: center;">
                                <div style="width: 70px; height: 70px; background: #E67E22; border-radius: 15px; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 45px; box-shadow: 0 4px 10px rgba(230, 126, 34, 0.3);">B</div>
                            </div>
                            <div style="text-align: left; font-size: 11px; color: #333;">
                                <p style="margin: 2px 0;">تاريخ التقرير: <span id="reportPrintDate" style="font-weight: bold;"></span></p>
                                <p style="margin: 2px 0;">رقم التقرير: <span id="reportPrintID" style="font-weight: bold;"></span></p>
                                <div style="margin-top: 5px; padding: 5px; border: 1px dashed #ccc; border-radius: 4px; background: #f9f9f9; text-align: center;">
                                    <span style="font-size: 10px; color: #888;">Diagnostic Report</span>
                                </div>
                            </div>
                        </div>

                        <!-- Patient Info Section -->
                        <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 15px; margin-bottom: 20px; font-size: 13px; position: relative; z-index: 1;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #f8f9fa; padding: 12px; border-radius: 6px; border: 1px solid #eee;">
                                <div><span style="color: #888;">المريض:</span> <span id="reportPatientName" style="font-weight: bold; color: #2C3E50;"></span></div>
                                <div><span style="color: #888;">رقم الملف:</span> <span id="reportPatientID" style="font-weight: bold;"></span></div>
                                <div><span style="color: #888;">العمر/الجنس:</span> <span id="reportPatientMeta"></span></div>
                                <div><span style="color: #888;">التاريخ:</span> <span><?php echo date('Y/m/d'); ?></span></div>
                            </div>
                            <div style="background: #fffcf5; padding: 12px; border-radius: 6px; border: 1px solid #fbeee0; display: flex; flex-direction: column; justify-content: center;">
                                <div><span style="color: #888;">الطبيب المحول:</span> <span id="reportDoctorName" style="font-weight: bold;"></span></div>
                            </div>
                        </div>

                        <!-- Categorized Results Table -->
                        <div id="reportCategorizedBody" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; position: relative; z-index: 1;">
                            <!-- Columns injected by JS -->
                        </div>

                        <!-- Footer -->
                        <div style="margin-top: 30px; border-top: 2px solid #333; padding-top: 15px; display: flex; justify-content: space-between; align-items: flex-end; position: relative; z-index: 1;">
                            <div style="font-size: 11px; color: #555; line-height: 1.6;">
                                <p style="margin: 0; font-weight: bold; color: #E67E22;">مستشفى البدر التخصصي - أب</p>
                                <p style="margin: 0;">شارع الستين الشمالي - جولة الجمنة</p>
                                <p style="margin: 0;">تلفون: 01-331144 | موبايل: 777111000</p>
                            </div>
                            <div style="text-align: center; width: 200px;">
                                <div style="height: 60px; border-bottom: 1px solid #ccc; width: 150px; margin: 0 auto 5px auto;"></div>
                                <p style="margin: 0; font-size: 12px; font-weight: bold;">توقيع واعتماد المختبر</p>
                                <p style="margin: 0; font-size: 10px; color: #888;">Lab Supervisor Signature</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div style="padding: 15px 25px; background: white; border-top: 1px solid #eee; display: flex; gap: 10px; justify-content: flex-end;">
                    <button onclick="executePrintReport()" class="btn btn-success" style="padding: 10px 30px; font-weight: bold;">🖨️ تأكيد وطباعة التقرير</button>
                    <button onclick="closeReportModal()" class="btn btn-secondary" style="padding: 10px 20px; background: #ddd; color: #333; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">إلغاء</button>
                </div>
            </div>
        </div>

        <script>
            const labResults = <?php echo json_encode($results_by_patient); ?>;
            const pendingStatus = <?php echo json_encode($pending_tests); ?>;
            const allServices = <?php echo json_encode($services); ?>;
            let currentPatientData = null;

            function openAction(pid, vid, name){
                // Hide any persistent alerts
                const alertBox = document.getElementById('main-alert');
                if(alertBox) alertBox.style.display = 'none';

                currentPatientData = { id: pid, name: name };
                document.getElementById('diag-title').innerText = 'المريض: ' + name;
                
                // Update Analytics Buttons
                const analyticsUrl = 'analytics.php?patient_id=' + pid;
                document.getElementById('patient-analytics-btn-lab').href = analyticsUrl;
                const diagBtn = document.getElementById('patient-analytics-btn-diag');
                diagBtn.href = analyticsUrl;
                diagBtn.style.display = 'inline-block';
                
                // Show/Hide Sections
                document.getElementById('diagnosis-card').style.display = 'flex';
                document.getElementById('lab-form').style.display = 'flex';
                document.getElementById('lab-placeholder').style.display = 'none';

                // Check for pending tests UI
                if(pendingStatus[pid]) {
                    document.getElementById('lab-form-inner').style.display = 'none';
                    document.getElementById('pending-warning').style.display = 'flex';
                } else {
                    document.getElementById('lab-form-inner').style.display = 'flex';
                    document.getElementById('pending-warning').style.display = 'none';
                    document.getElementById('lab-form').reset(); // Clear checkboxes if clean state
                }
                
                // Show/Hide Inner Forms & Reset Data
                document.getElementById('diag-form').style.display = 'flex';
                document.getElementById('diag-form').querySelector('textarea').value = '';
                document.getElementById('lab-form').style.display = 'flex';
                document.getElementById('lab-form').reset(); // Clear checkboxes
                
                // Set IDs
                document.getElementById('d_pid').value = pid;
                document.getElementById('d_vid').value = vid;
                document.getElementById('modal_p_pid').value = pid;
                document.getElementById('modal_p_vid').value = vid;
                document.getElementById('l_pid').value = pid;
                document.getElementById('l_vid').value = vid;

                document.getElementById('presc-patient-name').innerText = 'المريض: ' + name;

                // Load Results
                const resultsCard = document.getElementById('results-card');
                const resultsList = document.getElementById('results-list');
                
                if(labResults[pid] && labResults[pid].length > 0) {
                    resultsCard.style.display = 'flex';
                    resultsList.innerHTML = labResults[pid].map(r => `
                        <div style="background: white; padding: 8px; margin-bottom: 8px; border-radius: 4px; border-right: 3px solid var(--success); box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #888;">
                                <span>${r.service}</span>
                                <span>${r.date}</span>
                            </div>
                            <div style="font-weight: bold; color: var(--primary-dark); font-size: 1rem; margin-top: 3px;">${r.value}</div>
                        </div>
                    `).join('');
                } else {
                    resultsCard.style.display = 'none';
                    resultsList.innerHTML = '';
                }
            }

            function openPrescriptionModal() {
                if(!currentPatientData) return;
                document.getElementById('prescModal').style.display = 'flex';
            }

            function closePrescriptionModal() {
                document.getElementById('prescModal').style.display = 'none';
            }

            // Define Categories for the report
            const categories = [
                { name: 'Hematology (أمراض الدم)', tests: ['CBC', 'ESR', 'Blood Group', 'Ferritin', 'Serum Iron', 'TIBC'] },
                { name: 'Biochemistry (الكيمياء)', tests: ['FBS', 'RBS', 'HbA1c', 'Urea', 'Creatinine', 'Uric Acid'] },
                { name: 'Liver Functions (وظائف الكبد)', tests: ['Total Bilirubin', 'Direct Bilirubin', 'SGOT (AST)', 'SGPT (ALT)', 'ALP', 'Total Protein', 'Albumin', 'Globulin'] },
                { name: 'Lipid Profile (الدهون)', tests: ['Total Cholesterol', 'Triglycerides', 'HDL', 'LDL'] },
                { name: 'Electrolytes (الأملاح)', tests: ['Sodium (Na+)', 'Potassium (K+)', 'Chloride (Cl-)', 'Calcium', 'Magnesium', 'Phosphorus'] },
                { name: 'Endocrinology (الهرمونات)', tests: ['TSH', 'Free T4', 'Free T3', 'Vitamin D', 'Vitamin B12'] },
                { name: 'Serology (المناعة)', tests: ['CRP', 'RF', 'ASOT', 'Widal Test', 'H. Pylori Ag', 'HBsAg', 'HCV Ab', 'HIV 1/2 Ab'] },
                { name: 'General (فحوصات عامة)', tests: ['Urine Routine', 'Stool Routine', 'hCG', 'PSA', 'Troponin I', 'CK-MB', 'PT', 'PTT', 'INR'] }
            ];

            function previewMedicalReport() {
                if(!currentPatientData) return;

                const pid = currentPatientData.id;
                const results = labResults[pid] || [];
                
                // Maps for easy lookup
                const resultsMap = {};
                results.forEach(r => { resultsMap[r.service] = r.value; });

                // Populate Header info
                document.getElementById('reportPrintDate').innerText = new Date().toLocaleDateString('ar-YE');
                document.getElementById('reportPrintID').innerText = 'LAB-' + (Math.floor(Math.random() * 90000) + 10000);
                document.getElementById('reportPatientName').innerText = currentPatientData.name;
                document.getElementById('reportPatientID').innerText = pid;
                document.getElementById('reportDoctorName').innerText = '<?php echo addslashes($doctor['name'] ?? "الطبيب المشرف"); ?>';
                document.getElementById('reportPatientMeta').innerText = '-(غير محدد)-';

                // Categorized Rendering
                const container = document.getElementById('reportCategorizedBody');
                container.innerHTML = '';

                // Split categories into two columns for the report
                const col1 = document.createElement('div');
                const col2 = document.createElement('div');
                
                categories.slice(0, 4).forEach(cat => renderCategory(cat, col1, resultsMap));
                categories.slice(4).forEach(cat => renderCategory(cat, col2, resultsMap));

                container.appendChild(col1);
                container.appendChild(col2);

                // Show Modal
                document.getElementById('reportModal').style.display = 'flex';
            }

            function renderCategory(cat, parent, resultsMap) {
                const section = document.createElement('div');
                section.style.marginBottom = '20px';
                
                const title = document.createElement('div');
                title.style.background = '#f0f0f0';
                title.style.padding = '5px 10px';
                title.style.fontWeight = '900';
                title.style.fontSize = '12px';
                title.style.borderRight = '4px solid #E67E22';
                title.style.marginBottom = '5px';
                title.innerText = cat.name;
                section.appendChild(title);

                const table = document.createElement('table');
                table.style.width = '100%';
                table.style.borderCollapse = 'collapse';
                table.style.fontSize = '11px';

                cat.tests.forEach(testName => {
                    const val = resultsMap[testName] || '----';
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid #f0f0f0';
                    tr.innerHTML = `
                        <td style="padding: 4px; text-align: right; width: 60%; color: #555;">${testName}</td>
                        <td style="padding: 4px; text-align: center; width: 40%; font-weight: bold; color: ${val !== '----' ? '#2C3E50' : '#ccc'};">${val}</td>
                    `;
                    table.appendChild(tr);
                });

                section.appendChild(table);
                parent.appendChild(section);
            }

            function closeReportModal() {
                document.getElementById('reportModal').style.display = 'none';
            }

            function executePrintReport() {
                const content = document.getElementById('medicalReportTemplate').outerHTML;
                const win = window.open('', '', 'height=900,width=1000');
                win.document.write('<html><head><title>تقرير فحوصات طبية</title>');
                win.document.write('<style>');
                win.document.write('body { margin: 0; padding: 0; background: #fff; }');
                win.document.write('@media print { body { padding: 0; margin: 0; } #medicalReportTemplate { box-shadow: none !important; border: none !important; margin: 0 !important; width: 100% !important; } .watermark { opacity: 0.03 !important; } }');
                win.document.write('</style>');
                win.document.write('</head><body style="margin: 0;">');
                win.document.write(content);
                win.document.write('</body></html>');
                win.document.close();
                
                setTimeout(() => {
                    win.print();
                    win.close();
                    closeReportModal();
                }, 800);
            }

            // Auto-hide alert after 4 seconds
            setTimeout(() => {
                const alertBox = document.getElementById('main-alert');
                if(alertBox) {
                    alertBox.style.transition = 'opacity 0.5s';
                    alertBox.style.opacity = '0';
                    setTimeout(() => alertBox.style.display = 'none', 500);
                }
            }, 4000);

            // Auto-Refresh every 60 seconds
            setInterval(() => {
                // Currently Doctor index doesn't have modals, but we can check for any 'display: flex' on potential future modals
                const anyModalOpen = Array.from(document.querySelectorAll('div[id*="Modal"]')).some(m => m.style.display === 'flex');
                if (!anyModalOpen) {
                    location.reload();
                }
            }, 60000);
        </script>
        
        <style>
            .patient-item { transition: all 0.2s ease; cursor: pointer; }
            .patient-item:hover { 
                background: #fff8f0; 
                border-right: 4px solid var(--primary); 
                transform: translateX(-5px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            }
            .svc-label { transition: all 0.1s ease; }
            .svc-label:hover { background: #fef5e7; transform: scale(1.01); }
            
            /* Custom Scrollbar for a premium feel */
            .custom-scroll {
                scrollbar-width: thin;
                scrollbar-color: #FF8C00 #fefefe;
            }
            .custom-scroll::-webkit-scrollbar { width: 10px; }
            .custom-scroll::-webkit-scrollbar-track { background: #fefefe; border-radius: 10px; }
            .custom-scroll::-webkit-scrollbar-thumb { 
                background: linear-gradient(to bottom, #FF8C00, #E67E22); 
                border-radius: 10px; 
                border: 2px solid #fefefe;
            }
            .custom-scroll::-webkit-scrollbar-thumb:hover { background: #d35400; }
        </style>
    </div>
</body>
</html>
