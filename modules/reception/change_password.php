<?php
require '../../config/database.php';
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Reception' && $_SESSION['role'] !== 'Admin') { die("غير مصرح"); }

$message = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $error = "كلمتا السر الجديدتان غير متطابقتين.";
    } elseif (strlen($new_password) < 6) {
        $error = "يجب أن تكون كلمة السر الجديدة 6 أحرف على الأقل.";
    } else {
        $stmt = $pdo->prepare("SELECT password FROM Users WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user && password_verify($current_password, $user['password'])) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE Users SET password = ? WHERE user_id = ?");
            $update->execute([$hashed_password, $_SESSION['user_id']]);
            $message = "تم تغيير كلمة السر بنجاح.";
        } else {
            $error = "كلمة السر الحالية غير صحيحة.";
        }
    }
}
?>
            <li><a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">الاستقبال</a></li>
            <li><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>">إحصائيات القسم</a></li>
            <li><a href="change_password.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'change_password.php' ? 'active' : ''; ?>">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
    </div>

    <div class="content" style="background: #f8f9fa; display: flex; align-items: center; justify-content: center; height: 100vh; overflow: hidden;">
        <div class="premium-password-container">
            <div class="password-card-header">
                <div class="lock-icon-wrapper">
                    <svg viewBox="0 0 24 24" class="lock-icon"><path d="M12 17a2 2 0 0 0 2-2 2 2 0 0 0-2-2 2 2 0 0 0-2 2 2 2 0 0 0 2 2m6-9h-1V6a5 5 0 0 0-5-5 5 5 0 0 0-5 5v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2m-6-5a3 3 0 0 1 3 3v2H9V6a3 3 0 0 1 3-3z"/></svg>
                </div>
                <h2>تحديث بيانات الأمان</h2>
                <p>قم بتغيير كلمة المرور الخاصة بحسابك لضمان حماية بيانات النظام</p>
            </div>

            <div class="password-card-body">
                <?php if($message): ?>
                    <div class="custom-alert alert-success-premium">
                        <span class="alert-icon">✓</span>
                        <div class="alert-content"><?php echo $message; ?></div>
                    </div>
                <?php endif; ?>

                <?php if($error): ?>
                    <div class="custom-alert alert-danger-premium">
                        <span class="alert-icon">!</span>
                        <div class="alert-content"><?php echo $error; ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="change_password" value="1">
                    
                    <div class="floating-group">
                        <label>كلمة السر الحالية</label>
                        <div class="input-wrapper">
                            <input type="password" name="current_password" required placeholder="••••••••">
                        </div>
                    </div>

                    <div class="floating-group">
                        <label>كلمة السر الجديدة</label>
                        <div class="input-wrapper">
                            <input type="password" name="new_password" required placeholder="••••••••">
                        </div>
                        <small class="hint-text">يجب ألا تقل عن 6 أحرف</small>
                    </div>

                    <div class="floating-group" style="margin-bottom: 30px;">
                        <label>تأكيد كلمة السر الجديدة</label>
                        <div class="input-wrapper">
                            <input type="password" name="confirm_password" required placeholder="••••••••">
                        </div>
                    </div>

                    <button type="submit" class="btn-premium-action">
                        حفظ التغييرات الجديدة
                        <span class="btn-glow"></span>
                    </button>
                </form>
            </div>
            <div class="password-card-footer">
                <a href="index.php">العودة للرئيسية</a>
            </div>
        </div>

        <style>
            .premium-password-container { width: 100%; max-width: 450px; background: white; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.1); overflow: hidden; animation: slideUp 0.6s cubic-bezier(0.165, 0.84, 0.44, 1); }
            @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
            .password-card-header { padding: 40px 30px 20px; text-align: center; background: linear-gradient(135deg, #fff 0%, #fffbf2 100%); }
            .lock-icon-wrapper { width: 70px; height: 70px; background: #FFF5E6; border: 2px solid #FF8C00; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; box-shadow: 0 10px 20px rgba(255, 140, 0, 0.15); }
            .lock-icon { width: 35px; height: 35px; fill: #FF8C00; }
            .password-card-header h2 { margin: 0; color: #2D3436; font-size: 1.5rem; font-weight: 800; }
            .password-card-header p { margin: 10px 0 0; color: #636e72; font-size: 0.85rem; line-height: 1.5; }
            .password-card-body { padding: 0 40px 30px; }
            .floating-group { margin-bottom: 20px; }
            .floating-group label { display: block; margin-bottom: 8px; font-size: 0.85rem; font-weight: 700; color: #2d3436; padding-right: 5px; }
            .input-wrapper { position: relative; }
            .input-wrapper input { width: 100% !important; padding: 12px 15px !important; border: 2px solid #edf2f7 !important; border-radius: 12px !important; font-size: 1rem !important; transition: all 0.3s !important; background: #fdfdfe !important; margin: 0 !important; }
            .input-wrapper input:focus { border-color: #FF8C00 !important; background: white !important; box-shadow: 0 0 0 5px rgba(255, 140, 0, 0.1) !important; }
            .hint-text { display: block; margin-top: 5px; font-size: 0.75rem; color: #b2bec3; }
            .btn-premium-action { width: 100%; padding: 15px; border: none; border-radius: 12px; background: linear-gradient(135deg, #FF8C00 0%, #E67E22 100%); color: white; font-size: 1.1rem; font-weight: 800; cursor: pointer; position: relative; overflow: hidden; transition: all 0.3s; box-shadow: 0 10px 20px rgba(230, 126, 34, 0.2); }
            .btn-premium-action:hover { transform: translateY(-2px); box-shadow: 0 15px 25px rgba(230, 126, 34, 0.3); }
            .btn-glow { position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent); transition: 0.5s; }
            .btn-premium-action:hover .btn-glow { left: 100%; }
            .password-card-footer { padding: 20px; text-align: center; border-top: 1px solid #f1f2f6; background: #fdfdfe; }
            .password-card-footer a { color: #FF8C00; text-decoration: none; font-weight: 700; font-size: 0.9rem; }
            .password-card-footer a:hover { text-decoration: underline; }
            .custom-alert { display: flex; align-items: center; padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; animation: fadeIn 0.4s ease; }
            @keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
            .alert-success-premium { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .alert-danger-premium { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
            .alert-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-left: 15px; font-weight: bold; flex-shrink: 0; }
            .alert-success-premium .alert-icon { background: #28a745; color: white; }
            .alert-danger-premium .alert-icon { background: #dc3545; color: white; }
        </style>
    </div>
</body>
</html>
