<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

session_start();

$database_path = __DIR__ . '/../includes/database.php';
require_once $database_path;

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'غير مسجل دخول']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $db = getDB();
    
    // جلب الإشعارات
    $query = "SELECT id, title, message, link, is_read, created_at 
              FROM notifications 
              WHERE user_id = :user_id AND is_read = 0
              ORDER BY created_at DESC 
              LIMIT 5";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':user_id' => $user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إصلاح الروابط لتشير إلى المسار الصحيح
    foreach ($notifications as &$notification) {
        if (!empty($notification['link'])) {
            // إذا كان الرابط يبدأ بـ view_document.php فقط
            if (strpos($notification['link'], 'view_document.php') === 0) {
                $notification['link'] = '../documents/' . $notification['link'];
            }
            // إذا كان الرابط يبدأ بـ /
            elseif (strpos($notification['link'], '/view_document.php') === 0) {
                $notification['link'] = '../documents' . $notification['link'];
            }
            // إذا كان الرابط فقط id
            elseif (is_numeric($notification['link'])) {
                $notification['link'] = '../documents/view_document.php?id=' . $notification['link'];
            }
        }
    }
    
    // جلب عدد غير المقروء
    $unread_query = "SELECT COUNT(*) as unread_count 
                     FROM notifications 
                     WHERE user_id = :user_id AND is_read = 0";
    $unread_stmt = $db->prepare($unread_query);
    $unread_stmt->execute([':user_id' => $user_id]);
    $unread_result = $unread_stmt->fetch(PDO::FETCH_ASSOC);
    $unread_count = $unread_result['unread_count'] ?? 0;
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'notifications' => $notifications ?: [],
        'unread_count' => (int)$unread_count
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'خطأ: ' . $e->getMessage(),
        'notifications' => [],
        'unread_count' => 0
    ]);
}