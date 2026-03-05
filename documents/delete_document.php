<?php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}
$user_id = $_SESSION['user_id'];
$csrf_token = $_POST['csrf_token'] ?? '';

// التحقق من CSRF
if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'طلب غير صالح (CSRF)']);
    exit();
}
$document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;

if (!$document_id) {
    echo json_encode(['success' => false, 'message' => 'معرف المستند غير صالح']);
    exit();
}

$db = getDB();

try {
    // التحقق من أن المستند موجود وأن المستخدم هو منشئه أو لديه صلاحية الحذف
    $stmt = $db->prepare("SELECT created_by, current_status, file_path FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'المستند غير موجود']);
        exit();
    }

    // السماح بالحذف فقط إذا كان المستخدم هو المنشئ والمستند في حالة مسودة (draft) أو لم يُرسل بعد
    // يمكنك تعديل الشروط حسب سياسة النظام
    if ($doc['created_by'] != $user_id) {
        echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية حذف هذا المستند']);
        exit();
    }

    if (($user_id != $_SESSION['user_id']) && !in_array($doc['current_status'], ['draft', 'completed'])) {
        echo json_encode(['success' => false, 'message' => 'لا يمكن حذف مستند في هذه الحالة']);
        exit();
    }

    $db->beginTransaction();

    // حذف الملف الفعلي من الخادم
    $file_path = $_SERVER['DOCUMENT_ROOT'] . '/electronic-signature-system/' . $doc['file_path'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }

    // حذف السجلات المرتبطة (سيتم حذفها تلقائياً بسبب قيود ON DELETE CASCADE في قاعدة البيانات)
    // ولكن يمكن حذف المستند مباشرة
    $stmt = $db->prepare("DELETE FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);

    $db->commit();

    echo json_encode(['success' => true, 'message' => 'تم حذف المستند بنجاح']);

} catch (Exception $e) {
    $db->rollBack();
    // تسجيل الخطأ داخلياً بدون كشف تفاصيل للمستخدم
    error_log('delete_document error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء حذف المستند، الرجاء المحاولة لاحقاً']);
}