<?php
require_once __DIR__ . '/../config/database.php';

$username = 'manager';
$password = '123456';
$role = 'Admin';

try {
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO Users (username, password, role) VALUES (?, ?, ?)");
    $stmt->execute([$username, $hashed_password, $role]);
    
    echo "<h1>تم إضافة المدير بنجاح!</h1>";
    echo "<p>اسم المستخدم: <strong>$username</strong></p>";
    echo "<p>كلمة المرور: <strong>$password</strong></p>";
    echo "<br><a href='index.php'>الذهاب لصفحة الدخول</a>";

} catch (PDOException $e) {
    echo "<h1>خطأ</h1>";
    echo "يبدو أن المستخدم موجود مسبقاً أو حدث خطأ.<br>";
    echo $e->getMessage();
}
?>
