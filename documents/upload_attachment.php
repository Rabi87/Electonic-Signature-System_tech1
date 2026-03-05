<?php
// upload_attachment.php - رفع مرفقات جديدة
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من أن المستخدم مسجل دخول وله صلاحية board
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'board') {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

$user_id = $_SESSION['user_id'];
$db = getDB();

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('طريقة الطلب غير صحيحة');
    }

    if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('خطأ في تحميل الملف');
    }

    $document_id = intval($_POST['document_id'] ?? 0);
    if ($document_id <= 0) {
        throw new Exception('معرف المستند غير صالح');
    }

    // رفع الملف
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/electronic-signature-system/uploads/attachments/';

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file = $_FILES['attachment'];
    $original_name = basename($file['name']);
    $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    // التحقق من نوع الملف
    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    if (!in_array($file_extension, $allowed_extensions)) {
        throw new Exception('نوع الملف غير مسموح به');
    }

    // التحقق من حجم الملف (10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('حجم الملف كبير جداً (الحد الأقصى 10MB)');
    }

    $file_name = 'attach_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_extension;
    $file_path = $upload_dir . $file_name;

    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('فشل في تحميل الملف');
    }

    $relative_file_path = 'uploads/attachments/' . $file_name;
    $file_size = filesize($file_path);

    // تحديد نوع الملف
    $file_type = mime_content_type($file_path);

    // حفظ في قاعدة البيانات
    $sql = "
        INSERT INTO document_attachments 
        (document_id, file_name, file_path, file_type, file_size, uploaded_by, uploaded_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $document_id,
        $original_name,
        $relative_file_path,
        $file_type,
        $file_size,
        $user_id
    ]);

    $response['success'] = true;
    $response['message'] = 'تم رفع المرفق بنجاح';

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>