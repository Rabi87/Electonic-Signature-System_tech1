<?php
// upload_field_image.php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'message' => 'غير مصرح']));
}

$response = ['success' => false, 'message' => '', 'image_path' => ''];

try {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('خطأ في تحميل الصورة');
    }
    
    $field_id = intval($_POST['field_id']);
    $document_id = intval($_POST['document_id']);
    $user_id = intval($_POST['user_id']);
    
    $file = $_FILES['image'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('نوع الصورة غير مدعوم');
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('حجم الصورة كبير جداً (الحد الأقصى 5MB)');
    }
    
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/electronic-signature-system/uploads/field_images/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_name = 'field_' . $field_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_extension;
    $file_path = $upload_dir . $file_name;
    
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        $relative_path = 'uploads/field_images/' . $file_name;
        $response['success'] = true;
        $response['image_path'] = $relative_path;
        $response['message'] = 'تم رفع الصورة بنجاح';
    } else {
        throw new Exception('فشل في حفظ الصورة');
    }
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>