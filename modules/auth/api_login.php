<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Disable error reporting display to prevent HTML/Text injection into JSON
error_reporting(0);
ini_set('display_errors', 0);

require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'البيانات غير مكتملة']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM Users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            echo json_encode([
                'status' => 'success',
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'role' => $user['role']
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'خطأ في السيرفر: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method']);
}
?>
