<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/database.php';

header('Content-Type: application/json');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مسجل دخول']);
    exit();
}

$user_id = $_SESSION['user_id'];
$db = getDB();

// جلب الإشعارات
$stmt = $db->prepare("
    SELECT 
        n.*,
        u.full_name as sender_name,
        d.id as document_id,
        d.title as document_title
    FROM notifications n
    LEFT JOIN users u ON n.sender_id = u.id
    LEFT JOIN documents d ON n.document_id = d.id
    WHERE n.user_id = :user_id
    ORDER BY n.is_read ASC, n.created_at DESC
    LIMIT 10
");

$stmt->execute([':user_id' => $user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'notifications' => $notifications
]);
?>