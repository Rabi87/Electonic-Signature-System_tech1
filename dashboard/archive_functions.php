<?php
// archive_functions.php
require_once '../includes/database.php';

function getArchiveFolder($priority) {
    $folders = [
        'normal' => '../uploads/archive/normal/',
        'high' => '../uploads/archive/urgent/',
        'urgent' => '../uploads/archive/secret/'
    ];
    return $folders[$priority] ?? $folders['normal'];
}

function moveFileToArchive($currentPath, $priority) {
    if (!file_exists($currentPath)) {
        return ['success' => false, 'message' => 'الملف غير موجود'];
    }
    
    $archiveFolder = getArchiveFolder($priority);
    
    // إنشاء المجلد إذا لم يكن موجودًا
    if (!is_dir($archiveFolder)) {
        mkdir($archiveFolder, 0777, true);
    }
    
    $filename = basename($currentPath);
    $newPath = $archiveFolder . $filename;
    
    // التأكد من عدم وجود ملف بنفس الاسم
    $counter = 1;
    $fileInfo = pathinfo($filename);
    $baseName = $fileInfo['filename'];
    $extension = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
    
    while (file_exists($newPath)) {
        $newFilename = $baseName . '_' . $counter . $extension;
        $newPath = $archiveFolder . $newFilename;
        $counter++;
    }
    
    // نقل الملف
    if (rename($currentPath, $newPath)) {
        $newFilePath = str_replace('../', '', $newPath);
        return ['success' => true, 'path' => $newFilePath];
    }
    
    return ['success' => false, 'message' => 'فشل في نقل الملف'];
}

function moveFileFromArchive($currentPath) {
    if (!file_exists($currentPath)) {
        return ['success' => false, 'message' => 'الملف غير موجود'];
    }
    
    $originalFolder = '../uploads/documents/';
    
    // إنشاء المجلد إذا لم يكن موجودًا
    if (!is_dir($originalFolder)) {
        mkdir($originalFolder, 0777, true);
    }
    
    $filename = basename($currentPath);
    $newPath = $originalFolder . $filename;
    
    // التأكد من عدم وجود ملف بنفس الاسم
    $counter = 1;
    $fileInfo = pathinfo($filename);
    $baseName = $fileInfo['filename'];
    $extension = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
    
    while (file_exists($newPath)) {
        $newFilename = $baseName . '_' . $counter . $extension;
        $newPath = $originalFolder . $newFilename;
        $counter++;
    }
    
    // نقل الملف
    if (rename($currentPath, $newPath)) {
        $newFilePath = str_replace('../', '', $newPath);
        return ['success' => true, 'path' => $newFilePath];
    }
    
    return ['success' => false, 'message' => 'فشل في نقل الملف'];
}

function isFileInArchive($filePath) {
    return strpos($filePath, 'uploads/archive/') !== false;
}
?>