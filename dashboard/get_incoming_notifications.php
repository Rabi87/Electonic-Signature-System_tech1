<?php
/**
 * جلب الإشعارات الواردة فقط (من مستخدمين آخرين)
 */

session_start();
require_once '../includes/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مسجل دخول']);
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// جلب الإشعارات الواردة فقط (حيث المرسل ليس المستخدم الحالي)
$query = "SELECT * FROM notifications 
          WHERE user_id = :user_id 
          AND sender_id IS NOT NULL 
          AND sender_id != :user_id 
          ORDER BY created_at DESC 
          LIMIT 50";

$stmt = $db->prepare($query);
$stmt->execute([':user_id' => $user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب عدد الإشعارات غير المقروءة الواردة فقط
$unread_count_query = "SELECT COUNT(*) as count FROM notifications 
                       WHERE user_id = :user_id 
                       AND is_read = 0 
                       AND sender_id IS NOT NULL 
                       AND sender_id != :user_id";

$unread_count_stmt = $db->prepare($unread_count_query);
$unread_count_stmt->execute([':user_id' => $user_id]);
$unread_count = $unread_count_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// تنسيق الإشعارات
$formatted_notifications = [];
foreach ($notifications as $notification) {
    $formatted_notifications[] = [
        'id' => $notification['id'],
        'title' => $notification['title'],
        'message' => $notification['message'],
        'link' => $notification['link'],
        'is_read' => $notification['is_read'],
        'created_at' => $notification['created_at']
    ];
}

echo json_encode([
    'success' => true,
    'notifications' => $formatted_notifications,
    'unread_count' => $unread_count
]);
?>