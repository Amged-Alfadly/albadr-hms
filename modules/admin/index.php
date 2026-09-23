<?php
require '../../config/database.php'; 
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Admin') { die("غير مصرح"); }

$message = "";
$error = "";

// --- 1. USER ACTIONS ---

// Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    try {
        $stmt = $pdo->prepare("INSERT INTO Users (username, password, role) VALUES (?, ?, ?)");
        $stmt->execute([$username, $password, $role]);
        $uid = $pdo->lastInsertId();
        if ($role === 'Doctor') {
            $specialty = $_POST['specialty'] ?? 'عام';
            $pdo->prepare("INSERT INTO Doctors (user_id, name, specialty) VALUES (?, ?, ?)")->execute([$uid, "د. $username", $specialty]);
        }
        $message = "تم إضافة المستخدم بنجاح.";
    } catch (PDOException $e) { $error = "خطأ: اسم المستخدم موجود مسبقاً."; }
}

// Update User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $uid = $_POST['user_id'];
    $username = $_POST['username'];
    $role = $_POST['role'];
    try {
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE Users SET username = ?, role = ?, password = ? WHERE user_id = ?");
            $stmt->execute([$username, $role, $password, $uid]);
        } else {
            $stmt = $pdo->prepare("UPDATE Users SET username = ?, role = ? WHERE user_id = ?");
            $stmt->execute([$username, $role, $uid]);
        }
        
        // Handle Specialty Update/Insert for Doctors
        if ($role === 'Doctor') {
            $specialty = $_POST['specialty'] ?? 'عام';
            // Check if record exists in Doctors
            $checkDoc = $pdo->prepare("SELECT COUNT(*) FROM Doctors WHERE user_id = ?");
            $checkDoc->execute([$uid]);
            if ($checkDoc->fetchColumn() > 0) {
                $pdo->prepare("UPDATE Doctors SET specialty = ? WHERE user_id = ?")->execute([$specialty, $uid]);
            } else {
                $pdo->prepare("INSERT INTO Doctors (user_id, name, specialty) VALUES (?, ?, ?)")->execute([$uid, "د. $username", $specialty]);
            }
        }
        
        $message = "تم تحديث بيانات المستخدم بنجاح.";
    } catch (PDOException $e) { $error = "خطأ في التحديث: " . $e->getMessage(); }
}

// Delete User
if (isset($_GET['delete_user'])) {
    $uid = $_GET['delete_user'];
    if ($uid == $_SESSION['user_id']) {
        $error = "لا يمكنك حذف حسابك الحالي!";
    } else {
        try {
            $pdo->prepare("DELETE FROM Users WHERE user_id = ?")->execute([$uid]);
            $message = "تم حذف المستخدم بنجاح.";
        } catch (PDOException $e) { $error = "فشل الحذف المرتبط ببيانات أخرى."; }
    }
}

// --- 2. LAB SERVICE ACTIONS ---

// Add Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    try {
        $pdo->prepare("INSERT INTO Lab_Services (service_name) VALUES (?)")->execute([$_POST['service_name']]);
        $message = "تم إضافة خدمة المختبر.";
    } catch (PDOException $e) { $error = "خطأ في الإضافة."; }
}

// Update Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_service'])) {
    try {
        $pdo->prepare("UPDATE Lab_Services SET service_name = ? WHERE service_id = ?")->execute([$_POST['service_name'], $_POST['service_id']]);
        $message = "تم تحديث الخدمة بنجاح.";
    } catch (PDOException $e) { $error = "خطأ في التحديث."; }
}

// Delete Service
if (isset($_GET['delete_service'])) {
    try {
        $pdo->prepare("DELETE FROM Lab_Services WHERE service_id = ?")->execute([$_GET['delete_service']]);
        $message = "تم حذف الخدمة.";
    } catch (PDOException $e) { $error = "فشل حذف الخدمة."; }
}

// Fetch Data (Medical Day Totals)
$user_count = $pdo->query("SELECT COUNT(*) FROM Users")->fetchColumn();
$patient_total = $pdo->prepare("SELECT COUNT(*) FROM Patients WHERE registration_date >= ?");
$patient_total->execute([$medical_day_start]);
$patient_total = $patient_total->fetchColumn();

$users = $pdo->query("SELECT u.*, d.specialty FROM Users u LEFT JOIN Doctors d ON u.user_id = d.user_id ORDER BY u.user_id DESC")->fetchAll();
$services = $pdo->query("SELECT * FROM Lab_Services ORDER BY service_id DESC")->fetchAll();
?>
            <li><a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">الرئيسـية</a></li>
            <li><a href="patients_report.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'patients_report.php' ? 'active' : ''; ?>">تقرير المرضى الشامل</a></li>
            <li><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>">التحليلات والإحصائيات</a></li>
            <li><a href="tracking.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'tracking.php' ? 'active' : ''; ?>">تتبع المرضى </a></li>
            <li><a href="change_password.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'change_password.php' ? 'active' : ''; ?>">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
    </div>

    <!-- ADMIN GRID -->
    <!-- ADMIN INTERFACE -->
    <div class="content">
        <!-- COMPACT DASHBOARD HEADER -->
        <div class="admin-header" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%); padding: 10px 20px; border-radius: 8px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 15px;">
                <h2 style="margin: 0; font-size: 1.2rem; font-weight: 800; border: none; color: white;">لوحة التحكم الشاملة</h2>
                <div style="width: 1px; height: 20px; background: rgba(255,255,255,0.3);"></div>
                <p style="margin: 0; font-size: 0.9rem; opacity: 0.9;">مستشفى البدر الدولي .. <span style="font-weight: bold; background: rgba(255,255,255,0.2); padding: 2px 10px; border-radius: 20px;">كونوا بخير</span></p>
            </div>
            
            <div id="alert-container">
               <?php if($message): ?><div class="alert alert-success" style="margin: 0; padding: 5px 15px; font-size: 0.85rem; background: rgba(255,255,255,0.9); color: var(--primary); border: none;"><?php echo $message; ?></div><?php endif; ?>
               <?php if($error): ?><div class="alert alert-danger" style="margin: 0; padding: 5px 15px; font-size: 0.85rem; background: white; color: var(--danger); border: none;"><?php echo $error; ?></div><?php endif; ?>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 20px; flex: 1; margin-bottom: 20px;">
            
            <!-- STAFF MANAGEMENT -->
            <div class="card" style="margin: 0; display: flex; flex-direction: column; overflow: hidden; border-top: 4px solid var(--primary);">
                <h3 style="color: var(--secondary); background: #fffcf5; padding: 15px; margin: 0; border-bottom: 1px solid #eee;">إدارة الطاقم (المستخدمين)</h3>
                <form method="POST" id="userForm" style="margin: 20px; background: white; padding: 20px; border-radius: 8px; border: 1px solid #eee; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                    <input type="hidden" name="add_user" id="userAction" value="1">
                    <input type="hidden" name="user_id" id="edit_user_id" value="">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                        <div>
                            <label style="font-size: 0.8rem; color: #888;">اسم المستخدم</label>
                            <input type="text" name="username" id="username_field" placeholder="اسم المستخدم" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>
                        <div>
                            <label style="font-size: 0.8rem; color: #888;">كلمة المرور</label>
                            <input type="password" name="password" id="password_field" placeholder="كلمة المرور" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>
                        <div>
                            <label style="font-size: 0.8rem; color: #888;">الدور</label>
                            <select name="role" id="role_field" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="Admin" selected>مدير</option>
                                <option value="Doctor">طبيب</option>
                                <option value="Reception">استقبال</option>
                                <option value="Lab">مختبر</option>
                                <option value="Admissions">مسؤول رقود</option>
                            </select>
                            </div>
                        <div id="specialty_div" style="display:none;">
                            <label style="font-size: 0.8rem; color: #888;">التخصص (للأطباء فقط)</label>
                            <select name="specialty" id="specialty_field" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
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
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" id="userSubmitBtn" class="btn" style="flex:2; background: var(--primary); font-weight: bold; padding: 12px;">إضافة موظف جديد</button>
                        <button type="button" id="cancelUserEdit" class="btn btn-secondary" style="flex:1; display:none; background: #95a5a6;" onclick="resetUserForm()">إلغاء</button>
                    </div>
                </form>

                <div style="flex: 1; overflow-y: auto; min-height: 0;" class="custom-scroll">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                        <thead style="background: var(--primary); color: white; position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th style="padding: 12px;">ID</th>
                                <th style="padding: 12px;">اسم المستخدم</th>
                                <th style="padding: 12px;">الدور</th>
                                <th style="padding: 12px; text-align: center;">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $u): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 8px;"><?php echo $u['user_id']; ?></td>
                                <td style="padding: 8px;"><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                                <td style="padding: 8px;"><span style="color: var(--primary-dark); font-weight: bold;"><?php echo $u['role']; ?></span></td>
                                <td style="padding: 8px; text-align: center;">
                                    <button class="btn btn-info" style="padding: 4px 10px; font-size: 0.75rem;" onclick="editUser(<?php echo htmlspecialchars(json_encode($u)); ?>)">تعديل</button>
                                    <a href="?delete_user=<?php echo $u['user_id']; ?>" class="btn btn-danger" style="padding: 4px 10px; font-size: 0.75rem;" onclick="return confirm('هل أنت متأكد من حذف هذا المستخدم؟')">حذف</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- LAB SERVICES MANAGEMENT -->
            <div class="card" style="margin: 0; display: flex; flex-direction: column; overflow: hidden; border-top: 4px solid var(--primary);">
                <h3 style="color: var(--secondary); background: #fffcf5; padding: 15px; margin: 0; border-bottom: 1px solid #eee;">إدارة فحص المختبر</h3>
                <form method="POST" id="serviceForm" style="display: flex; gap: 10px; margin: 20px; background: white; padding: 15px; border-radius: 8px; border: 1px solid #eee; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                    <input type="hidden" name="add_service" id="serviceAction" value="1">
                    <input type="hidden" name="service_id" id="edit_service_id" value="">
                    <input type="text" name="service_name" id="service_name_field" placeholder="اسم الفحص..." required style="margin:0; flex:1; padding: 12px; border: 1px solid #ddd; border-radius: 4px;">
                    <button type="submit" id="serviceSubmitBtn" class="btn btn-primary" style="padding: 10px 25px; font-weight: bold;">إضافة</button>
                    <button type="button" id="cancelServiceEdit" class="btn btn-secondary" style="display:none; background: #95a5a6;" onclick="resetServiceForm()">X</button>
                </form>

                <div style="flex: 1; overflow-y: auto; min-height: 0;" class="custom-scroll">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                        <thead style="position: sticky; top: 0; background: var(--primary); color: white; z-index: 1;">
                            <tr>
                                <th style="padding: 12px;">اسم الفحص</th>
                                <th style="padding: 12px; text-align: center;">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($services as $svc): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 8px;"><?php echo htmlspecialchars($svc['service_name']); ?></td>
                                <td style="padding: 8px; text-align: center;">
                                    <button class="btn btn-info" style="padding: 3px 8px; font-size: 0.7rem;" onclick="editService(<?php echo htmlspecialchars(json_encode($svc)); ?>)">تعديل</button>
                                    <a href="?delete_service=<?php echo $svc['service_id']; ?>" class="btn btn-danger" style="padding: 3px 8px; font-size: 0.7rem;" onclick="return confirm('حذف الفحص؟')">حذف</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script>
    document.getElementById('role_field').addEventListener('change', function() {
        const specDiv = document.getElementById('specialty_div');
        if(this.value === 'Doctor') {
            specDiv.style.display = 'block';
        } else {
            specDiv.style.display = 'none';
        }
    });

    function editUser(user) {
        document.getElementById('userAction').name = "update_user";
        document.getElementById('edit_user_id').value = user.user_id;
        document.getElementById('username_field').value = user.username;
        document.getElementById('role_field').value = user.role;
        
        // Set specialty if doctor
        if(user.role === 'Doctor') {
            document.getElementById('specialty_field').value = user.specialty || "عام";
        }
        
        // Trigger change to show/hide specialty
        document.getElementById('role_field').dispatchEvent(new Event('change'));

        document.getElementById('userSubmitBtn').innerText = "تحديث البيانات";
        document.getElementById('userSubmitBtn').classList.replace('btn-primary', 'btn-success');
        document.getElementById('cancelUserEdit').style.display = "inline-block";
        document.getElementById('username_field').focus();
    }

    function resetUserForm() {
        document.getElementById('userAction').name = "add_user";
        document.getElementById('edit_user_id').value = "";
        document.getElementById('userForm').reset();
        
        // Hide specialty div
        document.getElementById('specialty_div').style.display = 'none';
        
        document.getElementById('userSubmitBtn').innerText = "إضافة موظف جديد";
        document.getElementById('userSubmitBtn').classList.replace('btn-success', 'btn-primary');
        document.getElementById('cancelUserEdit').style.display = "none";
    }

    function editService(svc) {
        document.getElementById('serviceAction').name = "update_service";
        document.getElementById('edit_service_id').value = svc.service_id;
        document.getElementById('service_name_field').value = svc.service_name;
        document.getElementById('serviceSubmitBtn').innerText = "تحديث";
        document.getElementById('cancelServiceEdit').style.display = "inline-block";
        document.getElementById('service_name_field').focus();
    }

    function resetServiceForm() {
        document.getElementById('serviceAction').name = "add_service";
        document.getElementById('edit_service_id').value = "";
        document.getElementById('serviceForm').reset();
        document.getElementById('serviceSubmitBtn').innerText = "إضافة";
        document.getElementById('cancelServiceEdit').style.display = "none";
    }
    </script>
    <style>
        /* Custom Scrollbar for a premium feel */
        .custom-scroll {
            scrollbar-width: thin;
            scrollbar-color: #FF8C00 #fefefe;
        }
        .custom-scroll::-webkit-scrollbar { width: 8px; }
        .custom-scroll::-webkit-scrollbar-track { background: #fefefe; border-radius: 10px; }
        .custom-scroll::-webkit-scrollbar-thumb { 
            background: linear-gradient(to bottom, #FF8C00, #E67E22); 
            border-radius: 10px; 
            border: 2px solid #fefefe;
        }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: #d35400; }

        .card table thead th {
            background: #f8f9fa;
            position: sticky;
            top: 0;
            z-index: 1;
            box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1);
        }
    </style>
</body>
</html>
