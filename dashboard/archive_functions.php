<?php
// archive_functions.php
require_once '../includes/database.php';

/**
 * إرجاع مسار مجلد الأرشيف حسب الأولوية
 */
function getArchiveFolder($priority) {
    $folders = [
        'normal' => '../uploads/archive/normal/',
        'high'   => '../uploads/archive/urgent/',
        'urgent' => '../uploads/archive/secret/'
    ];
    return $folders[$priority] ?? $folders['normal'];
}

/**
 * نسخ الملف إلى مجلد الأرشيف (مع الاحتفاظ بالأصلي)
 */
function copyFileToArchive($sourcePath, $priority) {
    if (!file_exists($sourcePath)) {
        return ['success' => false, 'message' => 'الملف الأصلي غير موجود'];
    }
    
    $archiveFolder = getArchiveFolder($priority);
    
    // إنشاء المجلد إذا لم يكن موجودًا
    if (!is_dir($archiveFolder)) {
        mkdir($archiveFolder, 0777, true);
    }
    
    $filename = basename($sourcePath);
    $destinationPath = $archiveFolder . $filename;
    
    // التأكد من عدم وجود ملف بنفس الاسم في الأرشيف
    $counter = 1;
    $fileInfo = pathinfo($filename);
    $baseName = $fileInfo['filename'];
    $extension = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
    
    while (file_exists($destinationPath)) {
        $newFilename = $baseName . '_' . $counter . $extension;
        $destinationPath = $archiveFolder . $newFilename;
        $counter++;
    }
    
    // نسخ الملف (وليس نقله)
    if (copy($sourcePath, $destinationPath)) {
        // تخزين المسار النسبي بدون ../
        $archivedFilePath = str_replace('../', '', $destinationPath);
        return ['success' => true, 'path' => $archivedFilePath];
    }
    
    return ['success' => false, 'message' => 'فشل في نسخ الملف'];
}

/**
 * حذف ملف مؤرشف
 */
function deleteArchiveFile($filePath) {
    $fullPath = '../' . $filePath;
    if (file_exists($fullPath)) {
        return unlink($fullPath);
    }
    return false;
}
?>