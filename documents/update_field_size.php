<?php
// ابدأ بإغلاق أي إخراج مسبق
if (ob_get_level()) ob_end_clean();

// إرسال الـ header أولاً
header('Content-Type: application/json; charset=utf-8');

// تجنب أي إخراج غير مقصود
error_reporting(0);
ini_set('display_errors', 0);

// المسارات الصحيحة
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/session.php';

// بدء الجلسة
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// التحقق من أن الطلب هو POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'الطلب يجب أن يكون POST'
    ]);
    exit;
}

// جلب البيانات
$field_id = $_POST['field_id'] ?? null;
$width_percent = $_POST['width_percent'] ?? null;
$height_percent = $_POST['height_percent'] ?? null;
$width = $_POST['width'] ?? null;
$height = $_POST['height'] ?? null;
$document_id = $_POST['document_id'] ?? null;

// التحقق من البيانات
if (!$field_id || $width_percent === null || $height_percent === null) {
    echo json_encode([
        'success' => false,
        'message' => 'بيانات غير كاملة'
    ]);
    exit;
}

try {
    // الاتصال بقاعدة البيانات
    $pdo = getDB();
    
    // التحقق من وجود الحقل
    $check_stmt = $pdo->prepare("SELECT id FROM document_fields WHERE id = ?");
    $check_stmt->execute([$field_id]);
    if (!$check_stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'الحقل غير موجود'
        ]);
        exit;
    }
    
    // تحديث البيانات
    $stmt = $pdo->prepare("
        UPDATE document_fields 
        SET width_percent = ?, 
            height_percent = ?, 
            width = ?, 
            height = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $result = $stmt->execute([
        $width_percent, 
        $height_percent, 
        $width, 
        $height, 
        $field_id
    ]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'تم تحديث الحجم بنجاح'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'فشل في التحديث'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'خطأ: ' . $e->getMessage()
    ]);
}

exit;
?>