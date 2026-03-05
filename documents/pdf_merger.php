<?php
require_once '../includes/session.php';
checkLogin();
require_once '../fpdi/autoload.php';

use setasign\Fpdi\Fpdi;

function mergePDFs($originalPath, $attachmentPath, $outputPath, $position = 'end') {
    try {
        $pdf = new Fpdi();
        
        if ($position === 'beginning') {
            // إضافة المرفق أولاً
            $pageCount = $pdf->setSourceFile($attachmentPath);
            for ($i = 1; $i <= $pageCount; $i++) {
                $templateId = $pdf->importPage($i);
                $pdf->AddPage();
                $pdf->useTemplate($templateId);
            }
            
            // إضافة المستند الأصلي
            $pageCount = $pdf->setSourceFile($originalPath);
            for ($i = 1; $i <= $pageCount; $i++) {
                $templateId = $pdf->importPage($i);
                $pdf->AddPage();
                $pdf->useTemplate($templateId);
            }
        } else {
            // إضافة المستند الأصلي أولاً
            $pageCount = $pdf->setSourceFile($originalPath);
            for ($i = 1; $i <= $pageCount; $i++) {
                $templateId = $pdf->importPage($i);
                $pdf->AddPage();
                $pdf->useTemplate($templateId);
            }
            
            // إضافة المرفق
            $pageCount = $pdf->setSourceFile($attachmentPath);
            for ($i = 1; $i <= $pageCount; $i++) {
                $templateId = $pdf->importPage($i);
                $pdf->AddPage();
                $pdf->useTemplate($templateId);
            }
        }
        
        // حفظ الملف المدمج
        $pdf->Output($outputPath, 'F');
        
        return true;
    } catch (Exception $e) {
        error_log('PDF Merge Error: ' . $e->getMessage());
        return false;
    }
}