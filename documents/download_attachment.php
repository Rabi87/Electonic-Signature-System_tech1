<?php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

if (!isset($_GET['id'])) {
    die("معرف المرفق غير محدد");
}

$attachment_id = intval($_GET['id']);

try {
    $pdo = getDb();
    
    $stmt = $pdo->prepare("
        SELECT a.*, d.id as doc_id 
        FROM document_attachments a
        JOIN documents d ON a.document_id = d.id
        WHERE a.id = ?
    ");
    $stmt->execute([$attachment_id]);
    $attachment = $stmt->fetch();
    
    if (!$attachment) {
        die("المرفق غير موجود");
    }
    
    // التحقق من صلاحيات الوصول
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'] ?? 'employee';
    
    $stmt = $pdo->prepare("SELECT created_by FROM documents WHERE id = ?");
    $stmt->execute([$attachment['doc_id']]);
    $document = $stmt->fetch();
    
    $can_access = false;
    
    if ($user_role === 'admin' || $user_role === 'ceo' || 
        $user_role === 'department_manager' || $document['created_by'] == $user_id) {
        $can_access = true;
    }
    
    if (!$can_access) {
        die("ليس لديك صلاحية للوصول إلى هذا المرفق");
    }
    
    // إرسال الملف للتحميل
    if (file_exists($attachment['file_path'])) {
        header('Content-Type: ' . $attachment['file_type']);
        header('Content-Disposition: attachment; filename="' . $attachment['file_name'] . '"');
        header('Content-Length: ' . filesize($attachment['file_path']));
        readfile($attachment['file_path']);
        exit();
    } else {
        die("الملف غير موجود على الخادم");
    }
    
} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}