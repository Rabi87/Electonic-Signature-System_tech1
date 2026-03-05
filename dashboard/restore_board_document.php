<?php
/**
 * استعادة مستند من الأرشيف للديوان
 */

require_once '../includes/session.php';
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once 'archive_functions.php'; // الدوال المساعدة

checkLogin();

if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'board') {
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

// التحقق من أن المستند موجود ومؤرشف
$stmt = $db->prepare("
    SELECT d.*, u.full_name as creator_name 
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    WHERE d.id = ? AND d.archived = 1
");
$stmt->execute([$document_id]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    echo json_encode(['success' => false, 'message' => 'المستند غير موجود أو لم يتم أرشفته']);
    exit();
}

// بدء المعاملة
$db->beginTransaction();

try {
    $oldFilePath = $document['file_path'] ?? null;
    $newFilePath = null;
    
    // إذا كان هناك ملف مرفق
    if (!empty($document['file_path'])) {
        $currentPath = '../' . $document['file_path'];
        
        // إذا كان الملف في الأرشيف
        if (isFileInArchive($document['file_path']) && file_exists($currentPath)) {
            // نقل الملف من الأرشيف إلى المجلد الأصلي
            $moveResult = moveFileFromArchive($currentPath);
            
            if (!$moveResult['success']) {
                throw new Exception($moveResult['message']);
            }
            
            // تحديث مسار الملف
            $newFilePath = $moveResult['path'];
            $updateFileStmt = $db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
            $updateFileStmt->execute([$newFilePath, $document_id]);
        }
        // إذا كان الملف ليس في الأرشيف (حالة غريبة)
        else {
            // نترك الملف في مكانه الحالي
            $newFilePath = $document['file_path'];
        }
    }
    
    // تحديث حالة الأرشيف
    $updateStmt = $db->prepare("UPDATE documents SET archived = 0, archived_at = NULL WHERE id = ?");
    $updateStmt->execute([$document_id]);
    
    // تسجيل العملية
    $logStmt = $db->prepare("
        INSERT INTO archive_logs (document_id, user_id, action, old_path, new_path, priority, archived_at)
        VALUES (?, ?, 'restore', ?, ?, ?, NOW())
    ");
    $logStmt->execute([
        $document_id,
        $user_id,
        $oldFilePath ?? null,
        $newFilePath ?? null,
        $document['priority'] ?? 'normal'
    ]);
    
    // تأكيد المعاملة
    $db->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'تمت استعادة المستند بنجاح',
        'document' => [
            'id' => $document['id'],
            'title' => $document['title'],
            'file_path' => $newFilePath ?? $oldFilePath
        ]
    ]);
    
} catch (Exception $e) {
    // إرجاع المعاملة في حالة خطأ
    $db->rollBack();
    
    echo json_encode([
        'success' => false, 
        'message' => 'فشل في استعادة المستند: ' . $e->getMessage()
    ]);
}
?>