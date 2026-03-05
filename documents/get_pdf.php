<?php
// get_pdf.php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

if (!isset($_GET['id'])) {
    die('معرف المستند مطلوب');
}

$document_id = intval($_GET['id']);
$db = getDB();

$stmt = $db->prepare("SELECT file_path FROM documents WHERE id = ?");
$stmt->execute([$document_id]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    die('المستند غير موجود');
}

$file_path = $document['file_path'];

// البحث عن الملف
$found_path = null;
$paths_to_try = [
    $file_path,
    '../' . $file_path,
    '../../' . $file_path,
    '../../../' . $file_path,
    'uploads/documents/' . basename($file_path),
    '../uploads/documents/' . basename($file_path),
    '../../uploads/documents/' . basename($file_path),
];

foreach ($paths_to_try as $path) {
    if (file_exists($path)) {
        $found_path = $path;
        break;
    }
}

if (!$found_path) {
    die('الملف غير موجود');
}

// إرجاع ملف PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($found_path) . '"');
header('Content-Length: ' . filesize($found_path));
readfile($found_path);