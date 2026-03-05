<?php
// session.php - وضع هذا الملف في includes/

function checkLogin() {
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php");
        exit();
    }
    
    // التحقق من انتهاء الجلسة (30 دقيقة)
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 1800)) {
        session_destroy();
        header("Location: ../login.php");
        exit();
    }
    
    // تجديد وقت الجلسة
    $_SESSION['login_time'] = time();
    
    return true;
}

function getUserRole() {
    return $_SESSION['role_name'] ?? null;
}

function checkRoleAccess($allowedRoles = []) {
    $userRole = getUserRole();
    
    if (!in_array($userRole, $allowedRoles)) {
        header("Location: ../unauthorized.php");
        exit();
    }
}
?>