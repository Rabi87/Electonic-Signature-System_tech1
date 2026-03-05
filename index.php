<?php
/**
 * الصفحة الرئيسية - نظام التوقيع الإلكتروني
 */

// بدء الجلسة إذا لم تكن قد بدأت
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// توجيه المستخدم بناءً على حالة تسجيل الدخول
if (isset($_SESSION['user_id'])) {
    // المستخدم مسجل دخول، نقله للوحة التحكم المناسبة
    $role = $_SESSION['role_name'] ?? '';

    switch ($role) {
        case 'admin':
            header("Location: dashboard/dashboard_admin.php");
            break;
        case 'private_board':
            header("Location: dashboard/pboard_dashboard.php");
            break;
        case 'sub_board':
            header("Location: dashboard/sboard_dashboard.php");
            break;
        case 'ceo':
            header("Location: dashboard/ceo_dashboard.php");
            break;
        case 'department_manager':
            header("Location: dashboard/department_manager_dashboard.php");
            break;
        case 'section_manager':
            header("Location: dashboard/section_manager_dashboard.php");
            break;
        case 'employee':
            header("Location: dashboard/employee_dashboard.php");
            break;
        default:
            header("Location: dashboard/employee_dashboard.php");
            break;
    }
    exit();
} else {
    // المستخدم غير مسجل دخول، نقله لصفحة تسجيل الدخول
    header("Location: login.php");
    exit();
}
?>