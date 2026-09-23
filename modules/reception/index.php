<?php
require '../../config/database.php';
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Reception' && $_SESSION['role'] !== 'Admin') { die("غير مصرح"); }

$message = "";
$print_now = false;
$last_p = null;

// Handle Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_patient'])) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO Patients (name, age, phone, gender, created_by_user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['age'], $_POST['phone'], $_POST['gender'], $_SESSION['user_id']]);
        $new_pid = $pdo->lastInsertId();
        
        if (!empty($_POST['doctor_id'])) {
            $pdo->prepare("INSERT INTO Visits (patient_id, doctor_id, receptionist_user_id) VALUES (?, ?, ?)")
                ->execute([$new_pid, $_POST['doctor_id'], $_SESSION['user_id']]);
            $pdo->commit();
            
            $last_p = [
                'id' => $new_pid, 
                'name' => $_POST['name'], 
                'age' => $_POST['age'], 
                'phone' => $_POST['phone'], 
                'gender' => $_POST['gender']
            ];
            $print_now = true;
            $message = "تم تسجيل المريض بنجاح.";
        } else {
            throw new Exception("يجب اختيار الطبيب.");
        }
    } catch(Exception $e) { 
        if($pdo->inTransaction()) $pdo->rollBack();
        $message = "خطأ: " . $e->getMessage(); 
    }
}

// Handle Add Visit for Existing Patient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_visit'])) {
    $pid = $_POST['patient_id'];
    $did = $_POST['doctor_id'];
    if($pid && $did) {
        // Check if patient already has a pending visit
        $check = $pdo->prepare("SELECT v.*, d.name as doctor_name FROM Visits v JOIN Doctors d ON v.doctor_id = d.doctor_id WHERE v.patient_id = ? AND v.status = 'Pending'");
        $check->execute([$pid]);
        $existing_visit = $check->fetch();

        if ($existing_visit) {
            $message = "خطأ: المريض مسجل حالياً في زيارة قيد التنفيذ مع الدكتور (" . $existing_visit['doctor_name'] . "). لا يمكن إضافة زيارة أخرى لهذا المريض حتى يتم إنهاء زيارته الحالية.";
        } else {
            $pdo->prepare("INSERT INTO Visits (patient_id, doctor_id, receptionist_user_id) VALUES (?, ?, ?)")
                ->execute([$pid, $did, $_SESSION['user_id']]);
            
            // Fetch patient for printing
            $stmt = $pdo->prepare("SELECT * FROM Patients WHERE patient_id = ?");
            $stmt->execute([$pid]);
            $last_p = $stmt->fetch();
            
            $print_now = true;
            $message = "تم تسجيل زيارة جديدة للمريض بنجاح.";
        }
    } else {
        $message = "خطأ: بيانات الزيارة غير مكتملة.";
    }
}

// Handle Delete Patient (Within 10 mins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_patient'])) {
    $pid = $_POST['patient_id'];
    $stmt = $pdo->prepare("SELECT registration_date FROM Patients WHERE patient_id = ?");
    $stmt->execute([$pid]);
    $reg_date = $stmt->fetchColumn();
    
    if ($reg_date && (time() - strtotime($reg_date)) <= 600) { // 10 minutes
        $pdo->prepare("DELETE FROM Patients WHERE patient_id = ?")->execute([$pid]);
        $message = "تم حذف بيانات المريض والزيارة بنجاح.";
    } else {
        $message = "خطأ: انتهت الفترة المسموح بها للحذف (10 دقائق).";
    }
}

// Handle Edit Patient (Within 10 mins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_patient'])) {
    $pid = $_POST['patient_id'];
    $stmt = $pdo->prepare("SELECT registration_date FROM Patients WHERE patient_id = ?");
    $stmt->execute([$pid]);
    $reg_date = $stmt->fetchColumn();
    
    if ($reg_date && (time() - strtotime($reg_date)) <= 600) {
        $stmt = $pdo->prepare("UPDATE Patients SET name = ?, age = ?, phone = ?, gender = ? WHERE patient_id = ?");
        $stmt->execute([$_POST['name'], $_POST['age'], $_POST['phone'], $_POST['gender'], $pid]);
        $message = "تم تحديث بيانات المريض بنجاح.";
    } else {
        $message = "خطأ: انتهت الفترة المسموح بها للتعديل (10 دقائق).";
    }
}

$search_results = [];
if (isset($_GET['search_query'])) {
    $q = $_GET['search_query'];
    $sql = is_numeric($q) ? "SELECT * FROM Patients WHERE patient_id = ?" : "SELECT * FROM Patients WHERE name LIKE ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(is_numeric($q) ? [$q] : ["%$q%"]);
    $search_results = $stmt->fetchAll();
}

// Fetch Recent Registrations for this user (Since 5 AM today)
$recent_stmt = $pdo->prepare("SELECT * FROM Patients WHERE created_by_user_id = ? AND registration_date >= ? ORDER BY registration_date DESC LIMIT 15");
$recent_stmt->execute([$_SESSION['user_id'], $medical_day_start]);
$recent_patients = $recent_stmt->fetchAll();

$doctors = $pdo->query("SELECT * FROM Doctors")->fetchAll();
?>
            <li><a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">الاستقبال</a></li>
            <li><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>">إحصائيات القسم</a></li>
            <li><a href="change_password.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'change_password.php' ? 'active' : ''; ?>">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
    </div>

    <div class="content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1 class="page-title" style="margin: 0; border:none;">قسم الاستقبال</h1>
            <?php if($message): ?><div id="reception-alert" class="alert <?php echo strpos($message, 'خطأ') !== false ? 'alert-danger' : 'alert-success'; ?>" style="margin: 0; padding: 10px 20px;"><?php echo $message; ?></div><?php endif; ?>
        </div>

        <div style="display: grid; grid-template-columns: 1.1fr 0.9fr 1fr; gap: 20px; flex: 1; margin-bottom: 20px;">
            
            <!-- COLUMN 1: REGISTRATION FORM -->
            <div class="card" style="margin: 0; border-top: 5px solid var(--primary); padding: 20px; overflow-y: auto;">
                <h2 style="margin-top:0; color: var(--primary); font-size: 1.3rem;">تسجيل مريض جديد</h2>
                <form method="POST">
                    <input type="hidden" name="register_patient" value="1">
                    
                    <div style="margin-bottom: 12px;">
                        <label>الاسم الرباعي الكامل:</label>
                        <input type="text" name="name" required style="padding: 10px; font-size: 1rem;">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
                        <div>
                            <label>العمر:</label>
                            <input type="number" name="age" required style="padding: 10px;">
                        </div>
                        <div>
                            <label>الجنس:</label>
                            <select name="gender" required style="padding: 10px;">
                                <option value="Male">ذكر</option>
                                <option value="Female">أنثى</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom: 12px;">
                        <label>رقم الهاتف:</label>
                        <input type="text" name="phone" style="padding: 10px;">
                    </div>

                    <div style="margin-bottom: 20px; background: #fffcf5; padding: 15px; border: 1px solid #ffe8cc; border-radius: 8px;">
                        <label style="font-weight: bold; font-size: 0.85rem; color: #E67E22;">تصفية حسب التخصص:</label>
                        <select id="reg_specialty_filter" onchange="filterDoctors('reg_specialty_filter', 'reg_doctor_id')" style="width: 100%; margin-bottom: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="">-- كل التخصصات --</option>
                            <option value="عام">عام</option>
                            <option value="باطنية">باطنية</option>
                            <option value="قلب">قلب</option>
                            <option value="أطفال">أطفال</option>
                            <option value="نساء وولادة">نساء وولادة</option>
                            <option value="عيون">عيون</option>
                            <option value="عظام">عظام</option>
                            <option value="أسنان">أسنان</option>
                            <option value="أنف وأذن وحنجرة">أنف وأذن وحنجرة</option>
                            <option value="جلدية">جلدية</option>
                            <option value="كلى">كلى</option>
                            <option value="مخ وأعصاب">مخ وأعصاب</option>
                        </select>

                        <label style="font-weight: bold; font-size: 0.85rem;">اختر الطبيب:</label>
                        <select name="doctor_id" id="reg_doctor_id" required style="border: 2px solid var(--primary); padding: 10px; font-size: 1rem; width: 100%;">
                            <option value="">-- اختر الطبيب --</option>
                            <?php foreach($doctors as $d): ?>
                                <option value="<?php echo $d['doctor_id']; ?>" data-specialty="<?php echo $d['specialty']; ?>">
                                    <?php echo $d['name']; ?> (<?php echo $d['specialty']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 1.1rem; font-weight: bold; cursor: pointer;">حفظ وتسجيل الزيارة</button>
                </form>
            </div>

            <!-- COLUMN 2: RECENT REGISTRATIONS -->
            <div class="card" style="margin: 0; padding: 15px; display: flex; flex-direction: column; overflow: hidden; border-top: 5px solid var(--primary);">
                <h3 style="margin-top:0; font-size: 1.1rem; color: var(--primary);">المسجلون حديثاً</h3>
                <div style="flex: 1; overflow-y: auto;">
                    <?php if(!empty($recent_patients)): ?>
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                            <tbody>
                                <?php foreach($recent_patients as $rp): 
                                    $diff = time() - strtotime($rp['registration_date']);
                                    $can_modify = ($diff <= 600);
                                ?>
                                <tr style="border-bottom: 1px solid var(--primary-light);">
                                    <td style="padding: 8px 0;">
                                        <strong><?php echo htmlspecialchars($rp['name']); ?></strong>
                                        <div style="font-size: 0.75rem; color: #777;">ID: <?php echo $rp['patient_id']; ?> | <?php echo date('H:i', strtotime($rp['registration_date'])); ?></div>
                                    </td>
                                    <td style="padding: 8px 0; text-align: left; display: flex; gap: 4px; justify-content: flex-end;">
                                        <?php if($can_modify): ?>
                                            <button class="btn btn-info" onclick='openEditModal(<?php echo json_encode($rp); ?>)' style="padding: 4px 8px; font-size: 0.75rem; background: #3498db;">تعديل</button>
                                            <button class="btn btn-danger" onclick="deletePatient(<?php echo $rp['patient_id']; ?>)" style="padding: 4px 8px; font-size: 0.75rem;">حذف</button>
                                        <?php endif; ?>
                                        <button class="btn btn-primary" onclick="openVisitModal(<?php echo $rp['patient_id']; ?>, '<?php echo htmlspecialchars($rp['name']); ?>')" style="padding: 4px 8px; font-size: 0.75rem; background: var(--primary);">زيارة جديدة</button>
                                        <button class="btn btn-success" onclick="printExisting(<?php echo htmlspecialchars(json_encode($rp)); ?>)" style="padding: 4px 8px; font-size: 0.75rem;">طباعة</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: #999; margin-top: 30px; font-size: 0.8rem;">لا يوجد مسجلون مؤخراً</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- COLUMN 3: SEARCH -->
            <div class="card" style="margin: 0; padding: 15px; display: flex; flex-direction: column; overflow: hidden; border-top: 5px solid var(--primary);">
                <h3 style="margin-top:0; font-size: 1.1rem; color: var(--primary);">بحث عن مريض</h3>
                <form method="GET" style="display: flex; gap: 5px; margin-bottom: 12px;">
                    <input type="text" name="search_query" placeholder="الاسم أو الرقم..." required style="margin:0; padding: 8px; font-size: 0.9rem;">
                    <button type="submit" class="btn btn-info" style="padding: 8px 12px; font-size: 0.9rem;">بحث</button>
                </form>
                
                <div style="flex: 1; overflow-y: auto;">
                    <?php if(!empty($search_results)): ?>
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                            <tbody>
                                <?php foreach($search_results as $res): 
                                    $diff = time() - strtotime($res['registration_date']);
                                    $can_modify = ($diff <= 600);
                                ?>
                                <tr style="border-bottom: 1px solid var(--primary-light);">
                                    <td style="padding: 8px 0;">
                                        <?php echo htmlspecialchars($res['name']); ?>
                                        <div style="font-size: 0.75rem; color: #999;">ID: <?php echo $res['patient_id']; ?></div>
                                    </td>
                                    <td style="padding: 8px 0; text-align: left; display: flex; gap: 4px; justify-content: flex-end;">
                                        <?php if($can_modify): ?>
                                            <button class="btn btn-info" onclick='openEditModal(<?php echo json_encode($res); ?>)' style="padding: 4px 8px; font-size: 0.7rem; background: #3498db;">تعديل</button>
                                            <button class="btn btn-danger" onclick="deletePatient(<?php echo $res['patient_id']; ?>)" style="padding: 4px 8px; font-size: 0.7rem;">حذف</button>
                                        <?php endif; ?>
                                        <button class="btn btn-primary" onclick="openVisitModal(<?php echo $res['patient_id']; ?>, '<?php echo htmlspecialchars($res['name']); ?>')" style="padding: 4px 8px; font-size: 0.7rem; background: var(--primary);">زيارة جديدة</button>
                                        <button class="btn btn-success" onclick="printExisting(<?php echo htmlspecialchars(json_encode($res)); ?>)" style="padding: 4px 8px; font-size: 0.7rem;">طباعة</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: #bbb; margin-top: 30px; font-size: 0.8rem;">نتائج البحث...</p>
                    <?php endif; ?>
                </div>
            </div>

    </div>

    <!-- QRCode Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <!-- EDIT PATIENT MODAL -->
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(5px);">
        <div style="background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); max-width: 500px; width: 95%;">
            <h3 style="margin-top: 0; color: var(--primary); text-align: center;">تعديل بيانات المريض</h3>
            
            <form method="POST" id="editForm">
                <input type="hidden" name="edit_patient" value="1">
                <input type="hidden" name="patient_id" id="edit_patient_id">
                
                <div style="margin-bottom: 15px;">
                    <label>الاسم الرباعي:</label>
                    <input type="text" name="name" id="edit_name" required style="padding: 10px; font-size: 1rem;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label>العمر:</label>
                        <input type="number" name="age" id="edit_age" required style="padding: 10px;">
                    </div>
                    <div>
                        <label>الجنس:</label>
                        <select name="gender" id="edit_gender" required style="padding: 10px;">
                            <option value="Male">ذكر</option>
                            <option value="Female">أنثى</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label>رقم الهاتف:</label>
                    <input type="text" name="phone" id="edit_phone" style="padding: 10px;">
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-success" style="flex: 2; padding: 12px;">✔ حفظ التعديلات</button>
                    <button type="button" onclick="closeEditModal()" class="btn btn-secondary" style="flex: 1; background: #ddd; color: #333; padding: 12px; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

    <!-- NEW VISIT MODAL -->
    <div id="visitModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(5px);">
        <div style="background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); max-width: 450px; width: 95%;">
            <h3 style="margin-top: 0; color: var(--primary); text-align: center;">تسجيل زيارة جديدة للمريض</h3>
            <p id="visit_p_name" style="text-align: center; font-weight: bold; margin-bottom: 20px; color: #555;"></p>
            
            <form method="POST">
                <input type="hidden" name="add_visit" value="1">
                <input type="hidden" name="patient_id" id="v_patient_id">
                
                <div style="margin-bottom: 25px;">
                    <label style="font-weight: bold; font-size: 0.85rem; color: #E67E22;">تصفية حسب التخصص:</label>
                    <select id="visit_specialty_filter" onchange="filterDoctors('visit_specialty_filter', 'visit_doctor_id')" style="width: 100%; margin-bottom: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">-- كل التخصصات --</option>
                        <option value="عام">عام</option>
                        <option value="باطنية">باطنية</option>
                        <option value="قلب">قلب</option>
                        <option value="أطفال">أطفال</option>
                        <option value="نساء وولادة">نساء وولادة</option>
                        <option value="عيون">عيون</option>
                        <option value="عظام">عظام</option>
                        <option value="أسنان">أسنان</option>
                        <option value="أنف وأذن وحنجرة">أنف وأذن وحنجرة</option>
                        <option value="جلدية">جلدية</option>
                        <option value="كلى">كلى</option>
                        <option value="مخ وأعصاب">مخ وأعصاب</option>
                    </select>

                    <label style="font-weight: bold; font-size: 0.85rem;">تحويل لعيادة الدكتور:</label>
                    <select name="doctor_id" id="visit_doctor_id" required style="padding: 12px; font-size: 1rem; border: 2px solid var(--primary); border-radius: 8px; width: 100%;">
                        <option value="">-- اختر الطبيب --</option>
                        <?php foreach($doctors as $d): ?>
                            <option value="<?php echo $d['doctor_id']; ?>" data-specialty="<?php echo $d['specialty']; ?>">
                                <?php echo $d['name']; ?> (<?php echo $d['specialty']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary" style="flex: 2; padding: 15px; font-weight: bold;">✔ تأكيد الزيارة</button>
                    <button type="button" onclick="closeVisitModal()" class="btn btn-secondary" style="flex: 1; background: #ddd; color: #333; padding: 12px; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

    <!-- PRINT PREVIEW MODAL -->
    <div id="printModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(5px);">
        <div style="background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); text-align: center; max-width: 450px; width: 95%;">
            <h3 style="margin-top: 0; color: var(--primary);">معاينة بطاقة المريض</h3>
            
            <!-- REDESIGNED CARD WITH BARCODE -->
            <div id="printArea" style="width: 400px; height: 260px; padding: 0; border: 2px solid #E67E22; border-radius: 12px; text-align: center; font-family: 'Segoe UI', Arial, sans-serif; direction: rtl; background: #fff; margin: 20px auto; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.1); position: relative; display: flex; flex-direction: column;">
                <!-- Header -->
                <div style="background: #E67E22; color: white; padding: 10px; display: flex; align-items: center; gap: 10px;">
                    <img src="../../assets/img/logo.png" style="width: 40px; height: 40px; background: white; border-radius: 5px; padding: 2px;">
                    <div style="text-align: right; flex: 1;">
                        <div style="font-weight: bold; font-size: 0.9rem;">مستشفى البدر الدولي</div>
                        <div style="font-size: 0.6rem; opacity: 0.9;">Al-Badr International Hospital</div>
                    </div>
                </div>
                
                <!-- Body -->
                <div style="padding: 15px; text-align: right; flex: 1; display: flex; justify-content: space-between;">
                    <div style="flex: 1;">
                        <div style="font-size: 0.7rem; color: #888; margin-bottom: 2px;">اسم الـمريض:</div>
                        <div id="printName" style="font-size: 1.1rem; font-weight: bold; color: #222; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; border-bottom: 1px dashed #eee; padding-bottom: 5px; margin-bottom: 10px;"></div>
                        
                        <div style="font-size: 0.7rem; color: #888;">رقم الملف الخاص بالمريض:</div>
                        <div id="printID" style="font-size: 1.8rem; font-weight: 900; color: #E67E22; letter-spacing: 2px;"></div>
                    </div>
                    
                    <div style="width: 120px; display: flex; flex-direction: column; align-items: center; justify-content: center; border-right: 1px solid #f0f0f0; padding-right: 10px;">
                        <div id="qrcode" style="padding: 5px; background: white;"></div>
                    </div>
                </div>
                
                <div style="background: #f9f9f9; font-size: 0.6rem; color: #aaa; padding: 5px 0; border-top: 1px solid #eee;">
                    يُرجى إبراز هذه البطاقة عند كل زيارة للمستشفى
                </div>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button onclick="executePrint()" class="btn btn-primary" style="flex: 2; padding: 12px;">✅ تأكيد وطباعة</button>
                <button onclick="closePrintModal()" class="btn btn-secondary" style="flex: 1; background: #ddd; color: #333; padding: 12px; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">إلغاء</button>
            </div>
        </div>
    </div>

    <script>
    // Filter Doctors Function
    function filterDoctors(filterId, selectId) {
        const specialty = document.getElementById(filterId).value;
        const select = document.getElementById(selectId);
        const options = select.getElementsByTagName('option');
        
        for (let i = 0; i < options.length; i++) {
            const opt = options[i];
            // Skip the "Choose Doctor" placeholder
            if (opt.value === "") continue;
            
            const docSpecialty = opt.getAttribute('data-specialty');
            if (specialty === "" || docSpecialty === specialty) {
                opt.style.display = 'block';
            } else {
                opt.style.display = 'none';
            }
        }
        // Reset selection
        select.value = "";
    }
    // Delete Patient Function
    function deletePatient(pid) {
        if(confirm('هل أنت متأكد من حذف بيانات هذا المريض؟ لن تتمكن من التراجع عن هذا الإجراء.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="delete_patient" value="1"><input type="hidden" name="patient_id" value="' + pid + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Open Edit Modal
    function openEditModal(patient) {
        document.getElementById('edit_patient_id').value = patient.patient_id || patient.id;
        document.getElementById('edit_name').value = patient.name;
        document.getElementById('edit_age').value = patient.age;
        document.getElementById('edit_phone').value = patient.phone || '';
        document.getElementById('edit_gender').value = (patient.gender === 'ذكر' ? 'Male' : (patient.gender === 'أنثى' ? 'Female' : patient.gender));
        document.getElementById('editModal').style.display = 'flex';
    }

    // Close Edit Modal
    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    // Open Visit Modal
    function openVisitModal(pid, name) {
        document.getElementById('v_patient_id').value = pid;
        document.getElementById('visit_p_name').innerText = 'المريض: ' + name;
        document.getElementById('visitModal').style.display = 'flex';
    }

    function closeVisitModal() {
        document.getElementById('visitModal').style.display = 'none';
    }

    // Print Modal Functions
    function openPrintModal(p) {
        const patientId = p.patient_id || p.id;
        document.getElementById('printName').innerText = p.name;
        document.getElementById('printID').innerText = patientId;
        
        // Prepare data for QR (Without Arabic name to avoid encoding issues)
        const qrData = `ID: ${patientId}\nAge: ${p.age}\nPhone: ${p.phone}\nGender: ${p.gender}`;
        
        // Clear previous QR
        const qrContainer = document.getElementById('qrcode');
        qrContainer.innerHTML = '';
        
        // Generate QR Code
        new QRCode(qrContainer, {
            text: qrData,
            width: 100,
            height: 100,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });

        document.getElementById('printModal').style.display = 'flex';
    }

    function closePrintModal() {
        document.getElementById('printModal').style.display = 'none';
    }

    function executePrint() {
        // Check if QR code is ready (it can be an img or canvas)
        const qrImg = document.querySelector('#qrcode img');
        const qrCanvas = document.querySelector('#qrcode canvas');
        
        if (!qrImg && !qrCanvas) {
            alert("يرجى الانتظار لحين توليد الرمز، أو المحاولة مرة أخرى.");
            return;
        }

        const content = document.getElementById('printArea').outerHTML;
        const win = window.open('', '', 'height=600,width=800');
        
        if (!win) {
            alert("حدث خطأ: حظر المتصفح نافذة الطباعة المنبثقة. يرجى السماح بالمنبثقات (Popups) لهذا الموقع وحاول مرة أخرى.");
            return;
        }

        win.document.write('<html><head><title>طباعة بطاقة مريض</title>');
        win.document.write('<style>');
        win.document.write('body { display:flex; justify-content:center; align-items:center; height:90vh; margin:0; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; direction: rtl; }');
        win.document.write('@media print { body { padding: 0; margin: 0; } }');
        // Ensure the QR stays visible
        win.document.write('#qrcode canvas { display: none !important; }'); 
        win.document.write('#qrcode img { display: block !important; margin: 0 auto; }');
        win.document.write('</style>');
        win.document.write('</head><body style="margin: 0;">');
        win.document.write(content);
        win.document.write('</body></html>');
        win.document.close();
        
        // Wait for resources to load in the new window
        setTimeout(function() { 
            try {
                win.print(); 
                win.close(); 
                closePrintModal();
            } catch (e) {
                console.error("Print failed:", e);
                alert("تعذر فتح ملف الطباعة، يرجى المحاولة يدوياً.");
            }
        }, 1000);
    }

    function printExisting(p) {
        openPrintModal(p);
    }

    <?php if($print_now && $last_p): ?>
    setTimeout(() => {
        openPrintModal(<?php echo json_encode($last_p); ?>);
    }, 300);
    <?php endif; ?>

    
    // Auto-hide alert after 4 seconds
    setTimeout(() => {
        const alertBox = document.getElementById('reception-alert');
        if(alertBox) {
            alertBox.style.transition = 'opacity 0.5s';
            alertBox.style.opacity = '0';
            setTimeout(() => alertBox.style.display = 'none', 500);
        }
    }, 4000);

    // Auto-Refresh every 60 seconds (Only if no modals are open)
    setInterval(() => {
        const vModal = document.getElementById('visitModal');
        const pModal = document.getElementById('printModal');
        const eModal = document.getElementById('editModal');
        
        const anyModalOpen = 
            (vModal && vModal.style.display === 'flex') || 
            (pModal && pModal.style.display === 'flex') || 
            (eModal && eModal.style.display === 'flex');

        if (!anyModalOpen) {
            location.reload();
        }
    }, 60000);
    </script>
</body>
</html>
