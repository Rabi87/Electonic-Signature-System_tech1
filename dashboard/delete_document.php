<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/database.php';

// تحقق من الدخول
if (!isset($_SESSION['user_id']) ) {
    $_SESSION['error'] = "غير مسموح لك بالدخول";
    header("Location: board_dashboard.php");
    exit();
}

// تأكد من وجود معرف المستند
if (!isset($_GET['document_id'])) {
    $_SESSION['error'] = "لم يتم تحديد مستند";
    header("Location: board_dashboard.php");
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$document_id = $_GET['document_id'];

// تحقق أن المستند ملك المستخدم
$check = $db->prepare("SELECT id, title FROM documents WHERE id = ? AND created_by = ?");
$check->execute([$document_id, $user_id]);
$document = $check->fetch();

if (!$document) {
    $_SESSION['error'] = "لا يمكن حذف هذا المستند لأنه ليس من إنشائك";
    header("Location: board_dashboard.php");
    exit();
}

// احذف المستند مباشرة
$delete = $db->prepare("DELETE FROM documents WHERE id = ?");
if ($delete->execute([$document_id])) {
    $_SESSION['success'] = "تم حذف المستند بنجاح";
} else {
    $_SESSION['error'] = "حدث خطأ أثناء الحذف";
}

header("Location: board_dashboard.php");
exit();
?>