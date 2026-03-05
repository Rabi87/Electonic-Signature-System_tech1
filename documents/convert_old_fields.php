<?php
require_once '../includes/session.php';
require_once '../includes/config.php';
require_once '../includes/database.php';

checkLogin();



$data = json_decode(file_get_contents('php://input'), true);
$documentId = $data['document_id'] ?? 0;

try {
    $pdo = getDb();
    
    // جلب الحقول القديمة التي لا تحتوي على metadata أو metadata فارغة
    $stmt = $pdo->prepare("
        SELECT * FROM document_fields 
        WHERE document_id = ? 
        AND (metadata IS NULL OR metadata = '' OR metadata = '{}')
    ");
    $stmt->execute([$documentId]);
    $fields = $stmt->fetchAll();
    
    $converted = 0;
    
    foreach ($fields as $field) {
        // أبعاد الصفحة الأصلية (افتراض A4)
        $originalPageWidth = 595;
        $originalPageHeight = 842;
        
        // حساب النسب المئوية
        $xPercent = ($field['x_position'] / $originalPageWidth) * 100;
        $yPercent = ($field['y_position'] / $originalPageHeight) * 100;
        $widthPercent = ($field['width'] / $originalPageWidth) * 100;
        $heightPercent = ($field['height'] / $originalPageHeight) * 100;
        
        $metadata = [
            'x_percent' => round($xPercent, 2),
            'y_percent' => round($yPercent, 2),
            'width_percent' => round($widthPercent, 2),
            'height_percent' => round($heightPercent, 2),
            'original_page_width' => $originalPageWidth,
            'original_page_height' => $originalPageHeight,
            'coordinate_type' => 'converted',
            'converted_at' => date('Y-m-d H:i:s')
        ];
        
        // تحديث الحقل
        $updateStmt = $pdo->prepare("
            UPDATE document_fields 
            SET metadata = ? 
            WHERE id = ?
        ");
        
        $updateStmt->execute([json_encode($metadata, JSON_UNESCAPED_UNICODE), $field['id']]);
        $converted++;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "تم تحويل $converted حقلاً إلى نظام النسب المئوية",
        'converted_count' => $converted
    ]);
    
} catch (Exception $e) {
    error_log("خطأ في تحويل الحقول: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ: ' . $e->getMessage()
    ]);
}
?>