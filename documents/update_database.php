<?php
require_once '../includes/database.php';

try {
    $pdo = getDb();
    
    // إضافة أعمدة النسب المئوية إذا لم تكن موجودة
    $columns = ['x_percent', 'y_percent', 'width_percent', 'height_percent'];
    
    foreach ($columns as $column) {
        $stmt = $pdo->query("SHOW COLUMNS FROM document_fields LIKE '$column'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE document_fields ADD COLUMN $column DECIMAL(5,2) DEFAULT NULL");
            echo "تم إضافة العمود: $column<br>";
        }
    }
    
    // حساب النسب المئوية للسجلات القديمة
    $stmt = $pdo->query("SELECT * FROM document_fields WHERE x_percent IS NULL");
    $fields = $stmt->fetchAll();
    
    foreach ($fields as $field) {
        // افتراض حجم صفحة A4 قياسي
        $pageWidth = 595;
        $pageHeight = 842;
        
        $x_percent = ($field['x_position'] / $pageWidth) * 100;
        $y_percent = ($field['y_position'] / $pageHeight) * 100;
        $width_percent = ($field['width'] / $pageWidth) * 100;
        $height_percent = ($field['height'] / $pageHeight) * 100;
        
        $updateStmt = $pdo->prepare("
            UPDATE document_fields 
            SET x_percent = ?, y_percent = ?, width_percent = ?, height_percent = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$x_percent, $y_percent, $width_percent, $height_percent, $field['id']]);
    }
    
    echo "تم تحديث قاعدة البيانات بنجاح!";
    
} catch (PDOException $e) {
    die("خطأ في تحديث قاعدة البيانات: " . $e->getMessage());
}