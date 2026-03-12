<?php
/**
 * أرشفة مستند - نسخة مبسطة للتشخيص
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
$priority = $_POST['priority'] ?? 'normal';

if (!$document_id || !is_numeric($document_id)) {
    echo json_encode(['success' => false, 'message' => 'معرف المستند مطلوب']);
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// التحقق من وجود المستند
$stmt = $db->prepare("SELECT id, title, file_path FROM documents WHERE id = ?");
$stmt->execute([$document_id]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    echo json_encode(['success' => false, 'message' => 'المستند غير موجود']);
    exit();
}

// بدء المعاملة
$db->beginTransaction();

try {
    $archivedFilePath = null;

    // محاولة نسخ الملف إذا كان موجوداً (لكن لا نمنع الإدراج إذا فشل)
    if (!empty($document['file_path'])) {
        $currentPath = '../' . $document['file_path'];
        if (file_exists($currentPath)) {
            $copyResult = copyFileToArchive($currentPath, $priority);
            if ($copyResult['success']) {
                $archivedFilePath = $copyResult['path'];
            } else {
                // سجل الخطأ لكن استمر في الإدراج
                error_log("فشل نسخ الملف للمستند $document_id: " . $copyResult['message']);
            }
        } else {
            error_log("الملف الأصلي غير موجود: $currentPath");
        }
    }

    // إدراج سجل في user_archives (حتى لو كان الملف غير موجود)
    $insertStmt = $db->prepare("
        INSERT INTO user_archives (user_id, document_id, priority, archived_file_path, archived_at)
        VALUES (:user_id, :doc_id, :priority, :archived_path, NOW())
    ");
    $insertResult = $insertStmt->execute([
        ':user_id' => $user_id,
        ':doc_id' => $document_id,
        ':priority' => $priority,
        ':archived_path' => $archivedFilePath
    ]);

    if (!$insertResult) {
        $errorInfo = $insertStmt->errorInfo();
        throw new Exception("فشل الإدراج في user_archives: " . $errorInfo[2]);
    }

    // تسجيل العملية في archive_logs (اختياري)
    $logStmt = $db->prepare("
        INSERT INTO archive_logs (document_id, user_id, action, old_path, new_path, priority, archived_at)
        VALUES (?, ?, 'archive', ?, ?, ?, NOW())
    ");
    $logStmt->execute([
        $document_id,
        $user_id,
        $document['file_path'] ?? null,
        $archivedFilePath,
        $priority
    ]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'تمت إضافة المستند إلى أرشيفك الشخصي',
        'document' => [
            'id' => $document['id'],
            'title' => $document['title']
        ]
    ]);
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'فشل في الأرشفة: ' . $e->getMessage()
    ]);
}
?>