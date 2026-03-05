<?php
// delete_field_value.php - حذف قيمة الحقل والصورة المرتبطة به بأمان
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'message' => ''];

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('غير مصرح');
    }

    // التحقق من CSRF
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        throw new Exception('طلب غير صالح (CSRF)');
    }

    $field_id = isset($_POST['field_id']) ? (int)$_POST['field_id'] : 0;
    if (!$field_id) {
        throw new Exception('معرّف الحقل غير صالح');
    }

    $db = getDB();

    // حذف قيمة الحقل
    $stmt = $db->prepare("DELETE FROM field_values WHERE field_id = ?");
    $stmt->execute([$field_id]);

    // حذف صورة الحقل إذا كانت موجودة
    $stmt = $db->prepare("SELECT image_path FROM field_images WHERE field_id = ?");
    $stmt->execute([$field_id]);
    $image = $stmt->fetch();

    if ($image && !empty($image['image_path'])) {
        // حذف الملف من الخادم
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/electronic-signature-system/' . ltrim($image['image_path'], '/');
        if (file_exists($file_path)) {
            @unlink($file_path);
        }

        // حذف السجل من قاعدة البيانات
        $stmt = $db->prepare("DELETE FROM field_images WHERE field_id = ?");
        $stmt->execute([$field_id]);
    }

    $response['success'] = true;
    $response['message'] = 'تم حذف قيمة الحقل بنجاح';
} catch (Exception $e) {
    error_log('delete_field_value error: ' . $e->getMessage());
    $response['message'] = 'حدث خطأ أثناء حذف قيمة الحقل، الرجاء المحاولة لاحقاً';
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>