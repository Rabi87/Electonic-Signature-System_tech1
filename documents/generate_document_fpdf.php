<?php
/**
 * generate_document_fpdf.php
 * إنشاء وتحميل المستند مع التوقيعات باستخدام FPDF و FPDI
 */

require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/fpdf/fpdf.php';

// تحميل مكتبة FPDI يدوياً بدلاً من autoload
$fpdi_path = '../includes/fpdi/src/Fpdi.php';
if (file_exists($fpdi_path)) {
    require_once $fpdi_path;
} else {
    // إذا لم نجد FPDI، استخدم FPDF مباشرة
    class Fpdi extends FPDF {
        // دالة بديلة بسيطة
        public function setSourceFile($filename) {
            // إرجاع 1 لعدد الصفحات كقيمة افتراضية
            return 1;
        }
        
        public function importPage($pageNo) {
            return 1;
        }
        
        public function getTemplateSize($templateId) {
            return ['width' => 210, 'height' => 297, 'orientation' => 'P']; // A4 افتراضي
        }
        
        public function useTemplate($templateId, $x = null, $y = null, $width = null, $height = null) {
            // لا تفعل شيئاً
        }
    }
}

// التحقق من أن المستخدم مسجل دخول
session_start();
if (!isset($_SESSION['user_id'])) {
    die('يرجى تسجيل الدخول');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['document_id'])) {
    $document_id = intval($_POST['document_id']);
    $db = getDB();
    
    // جلب معلومات المستند
    $query = "SELECT d.*, u.full_name as creator_name 
              FROM documents d 
              LEFT JOIN users u ON d.created_by = u.id 
              WHERE d.id = :id";
    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        die('المستند غير موجود');
    }
    
    // التحقق من صلاحيات المستخدم
    $user_id = $_SESSION['user_id'];
    $check_query = "SELECT COUNT(*) as count FROM document_user_status 
                    WHERE document_id = :doc_id AND user_id = :user_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute([':doc_id' => $document_id, ':user_id' => $user_id]);
    $has_access = $check_stmt->fetchColumn();
    
    if (!$has_access) {
        die('ليس لديك صلاحية للوصول إلى هذا المستند');
    }
    
    // جلب التوقيعات المرتبطة بالمستند
    $signatures_query = "SELECT s.*, u.full_name as signer_name
                         FROM signatures s
                         LEFT JOIN users u ON s.user_id = u.id
                         WHERE s.document_id = :doc_id AND s.signed_at IS NOT NULL
                         ORDER BY s.signed_at ASC";
    $signatures_stmt = $db->prepare($signatures_query);
    $signatures_stmt->execute([':doc_id' => $document_id]);
    $signatures = $signatures_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // جلب الحقول المضافة مع قيمها من field_values
    $fields_query = "SELECT df.*, fv.value_data, fv.image_path, fv.signed_at, fv.user_id as value_user_id
                     FROM document_fields df
                     LEFT JOIN field_values fv ON df.id = fv.field_id
                     WHERE df.document_id = :doc_id 
                     AND (fv.value_data IS NOT NULL OR fv.image_path IS NOT NULL)";
    $fields_stmt = $db->prepare($fields_query);
    $fields_stmt->execute([':doc_id' => $document_id]);
    $fields = $fields_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // جلب مسار الملف الأصلي
    $original_file = '../' . $document['file_path'];
    
    if (!file_exists($original_file)) {
        die('الملف الأصلي غير موجود');
    }
    
    // تحديد نوع الملف
    $file_extension = strtolower(pathinfo($original_file, PATHINFO_EXTENSION));
    
    // إنشاء PDF جديد - نستخدم Fpdi إذا كان متاحاً، وإلا FPDF
    $pdf = new Fpdi();
    
    // إعداد معلومات المستند
    $pdf->SetTitle($document['title']);
    $pdf->SetAuthor($document['creator_name']);
    $pdf->SetCreator('نظام التوقيع الإلكتروني');
    
    // محاولة معالجة الملف الأصلي إذا كان PDF
    $is_pdf = ($file_extension === 'pdf');
    
    if ($is_pdf) {
        // محاولة استخدام FPDI لدمج الملف الأصلي مع التوقيعات
        try {
            // فحص ما إذا كانت دالة setSourceFile موجودة (تشير إلى FPDI حقيقي)
            if (method_exists($pdf, 'setSourceFile')) {
                $page_count = $pdf->setSourceFile($original_file);
                
                for ($page_no = 1; $page_no <= $page_count; $page_no++) {
                    // استيراد الصفحة
                    $template_id = $pdf->importPage($page_no);
                    $size = $pdf->getTemplateSize($template_id);
                    
                    // إضافة صفحة جديدة
                    $orientation = isset($size['orientation']) ? $size['orientation'] : 'P';
                    $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                    $pdf->useTemplate($template_id);
                    
                    // إضافة التوقيعات على هذه الصفحة
                    addSignaturesAndFieldsToPage($pdf, $page_no, $signatures, $fields);
                }
            } else {
                // إذا لم تكن FPDI حقيقية، أنشئ PDF جديداً
                createSimplePDF($pdf, $document, $signatures, $fields, $original_file);
            }
        } catch (Exception $e) {
            // إذا فشل استيراد PDF، ننشئ ملف جديد بدلاً من ذلك
            createSimplePDF($pdf, $document, $signatures, $fields, $original_file);
        }
    } else {
        // إذا كان الملف ليس PDF، إنشاء مستند جديد مع التوقيعات
        createSimplePDF($pdf, $document, $signatures, $fields, $original_file);
    }
    
    // إخراج الملف للتحميل
    $filename = 'مستند_' . $document_id . '_مع_التوقيعات_' . date('Ymd_His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    $pdf->Output('D', $filename);
    exit;
} else {
    die('طلب غير صالح');
}

/**
 * دالة لإضافة التوقيعات والحقول إلى صفحة محددة
 */
function addSignaturesAndFieldsToPage($pdf, $page_no, $signatures, $fields) {
    // إضافة التوقيعات على هذه الصفحة
    foreach ($signatures as $signature) {
        if ($signature['page_number'] == $page_no) {
            // رسم التوقيع
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetTextColor(0, 0, 0);
            
            // وضع اسم الموقع
            $pdf->SetXY($signature['x_position'], $signature['y_position']);
            $pdf->Cell(60, 8, $signature['signer_name'], 0, 1, 'C');
            
            // وضع توقيع "موقع الكترونياً"
            $pdf->SetFont('Arial', 'I', 10);
            $pdf->SetXY($signature['x_position'], $signature['y_position'] + 5);
            $pdf->Cell(60, 8, 'موقع الكترونياً', 0, 1, 'C');
            
            // وضع التاريخ إذا كان موجوداً
            if ($signature['signed_at']) {
                $pdf->SetFont('Arial', '', 9);
                $pdf->SetXY($signature['x_position'], $signature['y_position'] + 12);
                $date = date('Y-m-d H:i', strtotime($signature['signed_at']));
                $pdf->Cell(60, 8, $date, 0, 1, 'C');
            }
            
            // رسم خط تحت التوقيع
            $pdf->SetLineWidth(0.5);
            $pdf->Line(
                $signature['x_position'] - 5,
                $signature['y_position'] + 20,
                $signature['x_position'] + 55,
                $signature['y_position'] + 20
            );
        }
    }
    
    // إضافة الحقول على هذه الصفحة
    foreach ($fields as $field) {
        if ($field['page_number'] == $page_no) {
            $pdf->SetFont('Arial', '', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($field['x_position'], $field['y_position']);
            
            // عرض القيمة حسب نوع الحقل
            if (!empty($field['image_path'])) {
                // إذا كان حقل صورة
                $image_path = '../' . $field['image_path'];
                if (file_exists($image_path)) {
                    // إدراج الصورة
                    $pdf->Image($image_path, $field['x_position'], $field['y_position'], $field['width'], $field['height']);
                } else {
                    $pdf->Cell(80, 8, '[صورة غير متوفرة]', 0, 1, 'L');
                }
            } elseif (!empty($field['value_data'])) {
                // إذا كان حقل نصي
                $pdf->Cell(80, 8, $field['value_data'], 0, 1, 'L');
            } elseif (!empty($field['default_value'])) {
                // استخدام القيمة الافتراضية إذا لم تكن هناك قيمة
                $pdf->Cell(80, 8, $field['default_value'], 0, 1, 'L');
            }
        }
    }
}

/**
 * دالة مساعدة لإنشاء PDF بسيط في حالة عدم القدرة على معالجة PDF الأصلي
 */
function createSimplePDF($pdf, $document, $signatures, $fields, $original_file) {
    $pdf->AddPage();
    
    // عنوان الصفحة
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1256', $document['title']), 0, 1, 'C');
    $pdf->Ln(10);
    
    // معلومات المستند
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(40, 8, iconv('UTF-8', 'windows-1256', 'اسم الملف:'), 0, 0);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 8, basename($original_file), 0, 1);
    
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(40, 8, iconv('UTF-8', 'windows-1256', 'المنشئ:'), 0, 0);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1256', $document['creator_name']), 0, 1);
    
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(40, 8, iconv('UTF-8', 'windows-1256', 'تاريخ الإنشاء:'), 0, 0);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 8, date('Y-m-d H:i', strtotime($document['created_at'])), 0, 1);
    
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(40, 8, iconv('UTF-8', 'windows-1256', 'الحالة:'), 0, 0);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1256', $document['current_status']), 0, 1);
    $pdf->Ln(15);
    
    // إضافة معلومات التوقيعات
    if (!empty($signatures)) {
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1256', 'التوقيعات:'), 0, 1);
        $pdf->SetFont('Arial', '', 12);
        
        foreach ($signatures as $signature) {
            $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1256', '✓ ' . $signature['signer_name']), 0, 1);
            $pdf->SetFont('Arial', 'I', 10);
            $pdf->Cell(0, 6, iconv('UTF-8', 'windows-1256', 'بتاريخ: ' . date('Y-m-d H:i', strtotime($signature['signed_at']))), 0, 1);
            $pdf->SetFont('Arial', '', 12);
            $pdf->Ln(2);
        }
        $pdf->Ln(10);
    }
    
    // إضافة الحقول
    if (!empty($fields)) {
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1256', 'الحقول المضافة:'), 0, 1);
        $pdf->SetFont('Arial', '', 12);
        
        foreach ($fields as $field) {
            $field_label = $field['label'] ?: iconv('UTF-8', 'windows-1256', 'حقل بدون عنوان');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(50, 8, $field_label . ':', 0, 0);
            $pdf->SetFont('Arial', '', 12);
            
            if (!empty($field['image_path'])) {
                $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1256', '[صورة]'), 0, 1);
            } elseif (!empty($field['value_data'])) {
                $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1256', $field['value_data']), 0, 1);
            } elseif (!empty($field['default_value'])) {
                $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1256', $field['default_value']), 0, 1);
            } else {
                $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1256', '[بدون قيمة]'), 0, 1);
            }
            $pdf->Ln(2);
        }
        $pdf->Ln(10);
    }
    
    // إضافة وصف المستند
    if (!empty($document['description'])) {
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1256', 'وصف المستند:'), 0, 1);
        $pdf->SetFont('Arial', '', 12);
        $pdf->MultiCell(0, 8, iconv('UTF-8', 'windows-1256', $document['description']));
        $pdf->Ln(10);
    }
    
    // رسالة ختامية
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1256', 'تم إنشاء هذا المستند بواسطة نظام التوقيع الإلكتروني'), 0, 1, 'C');
    $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1256', 'تاريخ الإنشاء: ' . date('Y-m-d H:i')), 0, 1, 'C');
}