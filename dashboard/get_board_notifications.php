<?php
/**
 * جلب إشعارات الديوان
 */

session_start();
require_once '../includes/database.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'board') {
    header('HTTP/1.1 403 Forbidden');
    exit('غير مصرح');
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// جلب الإشعارات
$notifications_query = "
    SELECT n.*, u.full_name as sender_name
    FROM notifications n
    LEFT JOIN users u ON n.link LIKE CONCAT('%id=', u.id) OR n.message LIKE CONCAT('%', u.full_name, '%')
    WHERE n.user_id = :user_id 
    ORDER BY n.created_at DESC
    LIMIT 15
";

$notifications_stmt = $db->prepare($notifications_query);
$notifications_stmt->execute([':user_id' => $user_id]);
$notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب عدد الإشعارات غير المقروءة
$unread_count_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = 0";
$unread_count_stmt = $db->prepare($unread_count_query);
$unread_count_stmt->execute([':user_id' => $user_id]);
$unread_count = $unread_count_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// إرجاع البيانات كـ JSON
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread_count' => $unread_count
]);
?>