<?php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

// التحقق من CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'طلب غير صالح (CSRF)']);
    exit();
}

if (!isset($_POST['attachment_id']) || !isset($_POST['document_id'])) {
    echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
    exit();
}

$attachment_id = (int)$_POST['attachment_id'];
$document_id = (int)$_POST['document_id'];
$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role_name'] ?? 'employee';

try {
    $pdo = getDB();
    
    // جلب بيانات المرفق والمستند
    $stmt = $pdo->prepare("
        SELECT a.*, d.created_by 
        FROM document_attachments a
        JOIN documents d ON a.document_id = d.id
        WHERE a.id = ? AND a.document_id = ?
    ");
    $stmt->execute([$attachment_id, $document_id]);
    $data = $stmt->fetch();
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'المرفق غير موجود']);
        exit();
    }
    
    // التحقق من الصلاحية: فقط الشخص الذي رفع المرفق يمكنه حذفه
    $can_delete = false;
    if ((int)$data['uploaded_by'] === $user_id) {
        $can_delete = true;
    }
    // (اختياري) يمكن إضافة صلاحية المسؤول إذا رغبت
    // elseif (in_array($user_role, ['admin'], true)) {
    //     $can_delete = true;
    // }
    
    if (!$can_delete) {
        echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لحذف هذا المرفق']);
        exit();
    }
    
    // حذف الملف من الخادم
    if (!empty($data['file_path'])) {
        // بناء المسار الكامل بناءً على جذر المستند
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/electronic-signature-system/' . ltrim($data['file_path'], '/');
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }
    
    // حذف السجل من قاعدة البيانات
    $stmt = $pdo->prepare("DELETE FROM document_attachments WHERE id = ?");
    $stmt->execute([$attachment_id]);
    
    echo json_encode(['success' => true, 'message' => 'تم حذف المرفق بنجاح'], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('delete_attachment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء حذف المرفق، الرجاء المحاولة لاحقاً'], JSON_UNESCAPED_UNICODE);
}