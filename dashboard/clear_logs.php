<?php
// clear_logs.php
require_once 'includes/session.php';
checkLogin();
require_once 'includes/config.php';
require_once 'includes/database.php';

header('Content-Type: application/json');

// التحقق من الصلاحيات
if ($_SESSION['role_name'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'صلاحية غير كافية']);
    exit;
}

$db = getDB();

try {
    // حذف السجلات الأقدم من 30 يوم
    $stmt = $db->prepare("DELETE FROM user_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    
    $deleted = $stmt->rowCount();
    
    // تسجيل عملية الحذف
    //logAction($db, $_SESSION['user_id'], 'clear_old_logs', null, json_encode(['deleted_count' => $deleted]));
    
    echo json_encode(['success' => true, 'deleted' => $deleted, 'message' => 'تم مسح ' . $deleted . ' سجل بنجاح']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>