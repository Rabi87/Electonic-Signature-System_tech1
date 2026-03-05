<?php
require_once 'config.php';
require_once 'database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // في النسخة النهائية، سيتم التحقق من قاعدة البيانات
    // هذا مجرد نموذج أولي
    
    // مثال للتحقق البسيط
    if ($username === 'admin' && $password === 'admin123') {
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'admin';
        $_SESSION['full_name'] = 'المسؤول العام';
        $_SESSION['role_id'] = 1;
        $_SESSION['role_name'] = 'admin';
        
        header("Location: ../dashboard/dashboard_admin.php");
        exit();
    } else {
        header("Location: ../login.php?error=1");
        exit();
    }
}
?>