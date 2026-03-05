<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

session_start();

if (!isset($_SESSION['user_id']) || !isset($_POST['notification_id'])) {
    ob_end_clean();
    echo json_encode(['success' => false]);
    exit;
}

$user_id = $_SESSION['user_id'];
$notification_id = (int)$_POST['notification_id'];

// المسار الصحيح
$database_path = __DIR__ . '/../includes/database.php';

if (!file_exists($database_path)) {
    ob_end_clean();
    echo json_encode(['success' => false]);
    exit;
}

require_once $database_path;

try {
    $db = getDB();
    
    // تحديث الإشعار
    $update = "UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id";
    $stmt = $db->prepare($update);
    $stmt->execute([':id' => $notification_id, ':user_id' => $user_id]);
    
    // جلب العدد الجديد
    $count = "SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = :user_id AND is_read = 0";
    $stmt = $db->prepare($count);
    $stmt->execute([':user_id' => $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'unread_count' => (int)($result['unread_count'] ?? 0)
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'unread_count' => 0]);
}