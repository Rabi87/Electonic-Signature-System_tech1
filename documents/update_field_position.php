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
$x_percent = $_POST['x_percent'] ?? null;
$y_percent = $_POST['y_percent'] ?? null;
$x_position = $_POST['x_position'] ?? null;
$y_position = $_POST['y_position'] ?? null;
$document_id = $_POST['document_id'] ?? null;

// التحقق من البيانات
if (!$field_id || $x_percent === null || $y_percent === null) {
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
        SET x_percent = ?, 
            y_percent = ?, 
            x_position = ?, 
            y_position = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $result = $stmt->execute([
        $x_percent, 
        $y_percent, 
        $x_position, 
        $y_position, 
        $field_id
    ]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'تم تحديث الموقع بنجاح'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'فشل في التحديث'
        ]);
    }
    
} catch (PDOException $e) {
    // في حالة حدوث خطأ في قاعدة البيانات
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // في حالة حدوث أي خطأ آخر
    echo json_encode([
        'success' => false,
        'message' => 'خطأ: ' . $e->getMessage()
    ]);
}

exit;
?>