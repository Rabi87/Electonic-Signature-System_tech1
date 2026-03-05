<?php
// includes/helpers.php

/**
 * تحديد لوحة التحكم المناسبة بناءً على دور المستخدم
 */
function getDashboardUrl()
{
    if (!isset($_SESSION['role_name'])) {
        return '../login.php';
    }

    switch ($_SESSION['role_name']) {
        case 'board':
            return 'board_dashboard.php';
        case 'private_board':
            return 'pboard_dashboard.php';
        case 'sub_board':
            return 'sboard_dashboard.php';
        case 'office_manager':
            return 'office_mgr_dashboard.php';
        case 'deputy_ceo':
            return 'deputy_ceo_dashboard.php';
        case 'ceo':
            return 'ceo_dashboard.php';
        case 'employee':
            return 'employee_dashboard.php';
        case 'section_manager':
            return 'section_manager_dashboard.php';
        case 'department_manager':
            return 'department_manager_dashboard.php';
        default:
            return 'dashboard.php';
    }
}

/**
 * الحصول على اسم الدور باللغة العربية
 */
function getRoleName($role)
{
    $roles = [
        'admin' => 'مدير النظام',
        'supervisor' => 'مشرف',
        'employee' => 'موظف',
        'manager' => 'مدير'
    ];

    return $roles[$role] ?? $role;
}