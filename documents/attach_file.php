<?php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

header('Content-Type: application/json');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح بالوصول']);
    exit();
}

// التحقق من الطريقة
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة غير صالحة']);
    exit();
}

// التحقق من وجود الملف ومعرف المستند
if (!isset($_FILES['attachment']) || !isset($_POST['document_id'])) {
    echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
    exit();
}

$document_id = intval($_POST['document_id']);
$attachment_type = $_POST['attachment_type'] ?? 'separate';
$merge_position = $_POST['merge_position'] ?? 'end';
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_name'] ?? 'employee';

try {
    $pdo = getDb();

    // جلب بيانات المستند
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();

    if (!$document) {
        echo json_encode(['success' => false, 'message' => 'المستند غير موجود']);
        exit();
    }

    // التحقق من صلاحيات المستخدم
    $can_attach = false;
    
    // السماح للإداريين ومالك المستند بإضافة مرفقات
    if ($user_role === 'admin' || $user_role === 'ceo' || 
        $user_role === 'board' || $user_role === 'department_manager' || $user_role === 'employee' ||
        $user_role === 'section_manager' || $document['created_by'] == $user_id) {
        $can_attach = true;
    }

    if (!$can_attach) {
        echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لإضافة مرفقات']);
        exit();
    }

    // معالجة الملف المرفوع
    $attachment = $_FILES['attachment'];
    
    // التحقق من وجود أخطاء
    if ($attachment['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'حدث خطأ في رفع الملف']);
        exit();
    }

    // التحقق من حجم الملف (10MB كحد أقصى)
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($attachment['size'] > $maxSize) {
        echo json_encode(['success' => false, 'message' => 'حجم الملف كبير جداً (الحد الأقصى 10MB)']);
        exit();
    }

    // التحقق من نوع الملف
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg', 
                     'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                     'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    $fileType = mime_content_type($attachment['tmp_name']);
    
    if (!in_array($fileType, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'نوع الملف غير مسموح به (PDF, صور, Word, Excel فقط)']);
        exit();
    }

    // إنشاء مجلد المرفقات إذا لم يكن موجوداً
    $attachDir = '../uploads/attachments/';
    if (!file_exists($attachDir)) {
        mkdir($attachDir, 0777, true);
    }

    // إنشاء اسم فريد للملف
    $extension = pathinfo($attachment['name'], PATHINFO_EXTENSION);
    $newFilename = 'attach_' . time() . '_' . uniqid() . '.' . $extension;
    $destination = $attachDir . $newFilename;

    // نقل الملف إلى المجلد
    if (!move_uploaded_file($attachment['tmp_name'], $destination)) {
        echo json_encode(['success' => false, 'message' => 'فشل في حفظ الملف']);
        exit();
    }

    // إدخال بيانات المرفق في قاعدة البيانات
    $stmt = $pdo->prepare("
        INSERT INTO document_attachments 
        (document_id, file_path, file_name, file_size, file_type, uploaded_by, uploaded_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $document_id,
        $destination,
        $attachment['name'],
        $attachment['size'],
        $fileType,
        $user_id
    ]);

    $attachment_id = $pdo->lastInsertId();

    // إنشاء إشعار للمستخدمين المعنيين
   // $stmt = $pdo->prepare("
     //   INSERT INTO notifications (user_id, title, message, link, created_at)
    //    VALUES (?, ?, ?, ?, NOW())
   // ");
    
  //  $stmt->execute([
     //   $document['created_by'],
    //    'مرفق جديد',
      //  'تم إضافة مرفق جديد إلى المستند: ' . $document['title'],
   //     'view_document.php?id=' . $document_id
   // ]);

    $merged = false;
    
    // إذا كان الملف PDF وتم اختيار الدمج
    if ($fileType === 'application/pdf' && $attachment_type === 'merge' && file_exists($document['file_path'])) {
        
        // محاولة دمج PDF باستخدام المكتبات الموجودة
        try {
            // استخدام FPDF و FPDI من المجلدات الموجودة
            require_once '../fpdf/fpdf.php';
            
            // استخدام FPDI من fpdi/src/Fpdi.php
            if (file_exists('../fpdi/src/Fpdi.php')) {
                require_once '../fpdi/src/Fpdi.php';
                $pdf = new \setasign\Fpdi\Fpdi();
                
                // إنشاء نسخة احتياطية
                $backupPath = '../uploads/backups/doc_' . $document_id . '_' . time() . '.pdf';
                if (!file_exists(dirname($backupPath))) {
                    mkdir(dirname($backupPath), 0777, true);
                }
                copy($document['file_path'], $backupPath);
                
                if ($merge_position === 'beginning') {
                    // إضافة المرفق أولاً
                    $pageCount = $pdf->setSourceFile($destination);
                    for ($i = 1; $i <= $pageCount; $i++) {
                        $templateId = $pdf->importPage($i);
                        $size = $pdf->getTemplateSize($templateId);
                        
                        // إضافة صفحة بالاتجاه المناسب
                        $orientation = ($size['w'] > $size['h']) ? 'L' : 'P';
                        $pdf->AddPage($orientation, [$size['w'], $size['h']]);
                        $pdf->useTemplate($templateId);
                    }
                    
                    // إضافة المستند الأصلي
                    $pageCount = $pdf->setSourceFile($document['file_path']);
                    for ($i = 1; $i <= $pageCount; $i++) {
                        $templateId = $pdf->importPage($i);
                        $size = $pdf->getTemplateSize($templateId);
                        
                        $orientation = ($size['w'] > $size['h']) ? 'L' : 'P';
                        $pdf->AddPage($orientation, [$size['w'], $size['h']]);
                        $pdf->useTemplate($templateId);
                    }
                } else {
                    // إضافة المستند الأصلي أولاً
                    $pageCount = $pdf->setSourceFile($document['file_path']);
                    for ($i = 1; $i <= $pageCount; $i++) {
                        $templateId = $pdf->importPage($i);
                        $size = $pdf->getTemplateSize($templateId);
                        
                        $orientation = ($size['w'] > $size['h']) ? 'L' : 'P';
                        $pdf->AddPage($orientation, [$size['w'], $size['h']]);
                        $pdf->useTemplate($templateId);
                    }
                    
                    // إضافة المرفق
                    $pageCount = $pdf->setSourceFile($destination);
                    for ($i = 1; $i <= $pageCount; $i++) {
                        $templateId = $pdf->importPage($i);
                        $size = $pdf->getTemplateSize($templateId);
                        
                        $orientation = ($size['w'] > $size['h']) ? 'L' : 'P';
                        $pdf->AddPage($orientation, [$size['w'], $size['h']]);
                        $pdf->useTemplate($templateId);
                    }
                }
                
                // حفظ الملف المدمج
                $mergedPath = '../uploads/documents/doc_' . $document_id . '_merged_' . time() . '.pdf';
                $pdf->Output($mergedPath, 'F');
                
                // تحديث مسار المستند إلى الملف المدمج
                $stmt = $pdo->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
                $stmt->execute([$mergedPath, $document_id]);
                
                // تسجيل الإصدار الجديد
                $stmt = $pdo->prepare("
                    INSERT INTO document_versions 
                    (document_id, version_number, file_path, created_by, change_description) 
                    VALUES (?, 
                           (SELECT COALESCE(MAX(version_number), 0) + 1 FROM document_versions WHERE document_id = ?),
                           ?, ?, ?)
                ");
                
                $stmt->execute([
                    $document_id,
                    $document_id,
                    $mergedPath,
                    $user_id,
                    'تم دمج مرفق: ' . $attachment['name']
                ]);
                
                $merged = true;
                
                // تحديث الإشعار
               // $stmt = $pdo->prepare("
                 //   UPDATE notifications 
                 //   SET message = ? 
                 //   WHERE user_id = ? AND link = ? 
                 //   ORDER BY created_at DESC LIMIT 1
              //  ");
                
                $stmt->execute([
                    'تم دمج مرفق مع المستند: ' . $document['title'],
                    $document['created_by'],
                    'view_document.php?id=' . $document_id
                ]);
                
            } else {
                throw new Exception('لم يتم العثور على مكتبة FPDI في المسار المتوقع');
            }
            
        } catch (Exception $e) {
            error_log("خطأ في دمج PDF: " . $e->getMessage());
            $merged = false;
            
            // تحديث وصف المرفق للإشارة إلى أن الدمج لم يتم
            $stmt = $pdo->prepare("
                UPDATE document_attachments 
                SET file_name = CONCAT(file_name, ' (لم يتم الدمج - خطأ في المكتبة)')
                WHERE id = ?
            ");
            $stmt->execute([$attachment_id]);
        }
    }

    // إرجاع النتيجة مع معلومات عن حالة الدمج
    $response = [
        'success' => true, 
        'message' => 'تم رفع المرفق بنجاح',
        'file_name' => $attachment['name'],
        'attachment_type' => $attachment_type,
        'merged' => $merged
    ];

    // إذا لم تكن مكتبة الدمج متوفرة، أضف ملاحظة
    if ($fileType === 'application/pdf' && $attachment_type === 'merge' && !$merged) {
        $response['merge_note'] = 'ملاحظة: ميزة دمج PDF غير متاحة حاليًا (تحقق من تثبيت مكتبة FPDI)';
    }

    echo json_encode($response);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()]);
}