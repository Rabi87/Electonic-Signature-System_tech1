<?php
/**
 * استعادة مستند من الأرشيف (حذف من user_archives)
 * إذا كان آخر مستخدم، يتم حذف الملف المؤرشف أيضاً
 */

require_once '../includes/session.php';
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once 'archive_functions.php';

checkLogin();

$allowed_roles = ['board', 'sub_board', 'private_board'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], $allowed_roles)) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['success' => false, 'message' => 'طريقة غير مسموحة']);
    exit();
}

$document_id = $_POST['document_id'] ?? null;

if (!$document_id || !is_numeric($document_id)) {
    echo json_encode(['success' => false, 'message' => 'معرف المستند مطلوب']);
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// التحقق من وجود السجل في أرشيف المستخدم مع جلب مسار الملف المؤرشف
$checkStmt = $db->prepare("SELECT archived_file_path FROM user_archives WHERE user_id = ? AND document_id = ?");
$checkStmt->execute([$user_id, $document_id]);
$archiveRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

if (!$archiveRecord) {
    echo json_encode(['success' => false, 'message' => 'المستند غير موجود في أرشيفك']);
    exit();
}

$db->beginTransaction();

try {
    // حذف السجل من user_archives
    $deleteStmt = $db->prepare("DELETE FROM user_archives WHERE user_id = ? AND document_id = ?");
    $deleteStmt->execute([$user_id, $document_id]);

    // التحقق من وجود مستخدمين آخرين أرشفوا نفس المستند
    $checkOthers = $db->prepare("SELECT COUNT(*) as count FROM user_archives WHERE document_id = ?");
    $checkOthers->execute([$document_id]);
    $othersCount = $checkOthers->fetch(PDO::FETCH_ASSOC)['count'];

    $fileDeleted = false;
    if ($othersCount == 0 && !empty($archiveRecord['archived_file_path'])) {
        // لا يوجد مستخدم آخر، يمكن حذف الملف المؤرشف
        $fileDeleted = deleteArchiveFile($archiveRecord['archived_file_path']);
    }

    // تسجيل العملية في archive_logs
    $logStmt = $db->prepare("
        INSERT INTO archive_logs (document_id, user_id, action, priority, archived_at)
        VALUES (?, ?, 'restore', NULL, NOW())
    ");
    $logStmt->execute([$document_id, $user_id]);

    $db->commit();

    $message = 'تمت إزالة المستند من أرشيفك';
    if ($fileDeleted) {
        $message .= ' وتم حذف الملف المؤرشف نهائياً';
    }

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'فشل في الاستعادة: ' . $e->getMessage()
    ]);
}
?>