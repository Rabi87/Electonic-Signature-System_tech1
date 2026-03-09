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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة غير صالحة']);
    exit();
}

if (!isset($_POST['attachment_id']) || !isset($_POST['document_id']) || !isset($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
    exit();
}

if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'رمز CSRF غير صالح']);
    exit();
}

$attachment_id = intval($_POST['attachment_id']);
$document_id = intval($_POST['document_id']);
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_name'] ?? 'employee';

$pdo = getDb();

// جلب بيانات المرفق والمستند
$stmt = $pdo->prepare("
    SELECT a.*, d.title as doc_title, d.created_by as doc_creator
    FROM document_attachments a
    JOIN documents d ON a.document_id = d.id
    WHERE a.id = ?
");
$stmt->execute([$attachment_id]);
$attachment = $stmt->fetch();

if (!$attachment) {
    echo json_encode(['success' => false, 'message' => 'المرفق غير موجود']);
    exit();
}

// التحقق من الصلاحية للحذف
$can_delete = false;
if ($user_role === 'admin' || $user_role === 'board' || $attachment['uploaded_by'] == $user_id || $attachment['doc_creator'] == $user_id) {
    $can_delete = true;
}

if (!$can_delete) {
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لحذف هذا المرفق']);
    exit();
}

try {
    // حذف الملف الفيزيائي
    $file_path = $attachment['file_path'];
    if (file_exists($file_path)) {
        unlink($file_path);
    } else {
        // محاولة مسارات بديلة
        $alt_path = '../' . $file_path;
        if (file_exists($alt_path)) {
            unlink($alt_path);
        }
    }

    // حذف السجل من قاعدة البيانات
    $pdo->prepare("DELETE FROM document_attachments WHERE id = ?")->execute([$attachment_id]);

    // ========== تسجيل النشاط في user_activity_logs ==========
    $stmt = $pdo->prepare("SELECT username, full_name, role_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();

    $action = 'حذف مرفق';
    $description = 'قام بحذف المرفق "' . $attachment['file_name'] . '" من المستند: ' . $attachment['doc_title'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $log_stmt = $pdo->prepare("
        INSERT INTO user_activity_logs 
        (user_id, username, full_name, role_name, action, description, ip_address, user_agent, document_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $log_stmt->execute([
        $user_id,
        $user_data['username'] ?? '',
        $user_data['full_name'] ?? '',
        $user_data['role_name'] ?? '',
        $action,
        $description,
        $ip,
        $ua,
        $document_id
    ]);

    echo json_encode(['success' => true, 'message' => 'تم حذف المرفق بنجاح']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()]);
}