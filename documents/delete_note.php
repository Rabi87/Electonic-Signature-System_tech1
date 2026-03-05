<?php
require_once '../includes/session.php'; // استخدام session.php بدلاً من بدء الجلسة يدوياً
checkLogin(); // التأكد من تسجيل الدخول
require_once '../includes/config.php';
require_once '../includes/database.php';

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'message' => ''];

try {
    // التحقق من وجود معرف المستخدم في الجلسة
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('غير مصرح');
    }

    $user_id = (int)$_SESSION['user_id'];
    $user_role = $_SESSION['role_name'] ?? 'employee';

    // التحقق من CSRF
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        throw new Exception('طلب غير صالح (CSRF)');
    }

    $field_id = isset($_POST['field_id']) ? (int)$_POST['field_id'] : 0;
    $document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;

    if (!$field_id || !$document_id) {
        throw new Exception('معرف الحقل أو المستند غير صحيح');
    }

    $pdo = getDB();

    // جلب بيانات الحقل والمستند معاً
    $stmt = $pdo->prepare("
        SELECT f.*, d.created_by, d.current_holder_id, d.current_status
        FROM document_fields f
        INNER JOIN documents d ON f.document_id = d.id
        WHERE f.id = ? AND f.document_id = ?
    ");
    $stmt->execute([$field_id, $document_id]);
    $field = $stmt->fetch();

    if (!$field) {
        throw new Exception('الحقل غير موجود');
    }

    // التحقق من أن الحقل هو بالفعل ملاحظة
    if ($field['field_type'] !== 'note') {
        throw new Exception('هذا الحقل ليس ملاحظة');
    }

    // التحقق من الصلاحية: الشخص المعين (assigned_to) أو منشئ المستند أو المسؤول
    $can_delete = false;
    if ($field['assigned_to'] == $user_id) {
        $can_delete = true; // المستلم المعين
    } elseif ($field['created_by'] == $user_id) {
        $can_delete = true; // منشئ المستند
    } elseif (in_array($user_role, ['admin', 'ceo', 'board', 'department_manager', 'section_manager'], true)) {
        $can_delete = true; // المديرين
    }

    if (!$can_delete) {
        throw new Exception('ليس لديك صلاحية لحذف هذه الملاحظة');
    }

    // بدء معاملة لحذف البيانات بشكل آمن
    $pdo->beginTransaction();

    // حذف القيم المرتبطة أولاً (إذا وجدت)
    $stmt = $pdo->prepare("DELETE FROM field_values WHERE field_id = ?");
    $stmt->execute([$field_id]);

    // حذف الحقل نفسه
    $stmt = $pdo->prepare("DELETE FROM document_fields WHERE id = ?");
    $stmt->execute([$field_id]);

    $pdo->commit();

    $response['success'] = true;
    $response['message'] = 'تم حذف الملاحظة بنجاح';

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('delete_note error: ' . $e->getMessage());
    $response['message'] = $e->getMessage(); // يمكن إرسال رسالة الخطأ الفعلية للتطوير، ولكن يُفضل رسالة عامة في الإنتاج
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit();