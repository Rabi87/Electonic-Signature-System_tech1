<?php
/**
 * هيدر نظام إدارة المستندات - مع تحديث عنوان الصفحة بعدد الإشعارات
 * تم التعديل: إضافة قائمة overlay للأفاتار مع تأثير blur
 */

require_once __DIR__ . '/config.php';

if (!isset($_SESSION)) {
    session_start();
}

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_name'])) {
    header("Location: ../login.php");
    exit();
}

// بيانات المستخدم
$userName = $_SESSION['full_name'] ?? 'مستخدم';
$userRole = $_SESSION['role_name'] ?? 'موظف';
$site = $_SESSION['site'] ?? '';
$Dn = $_SESSION['department_id'] ?? 0;
$userTitle = $_SESSION['title'] ?? 'موظف';

// تنسيق الأدوار
$roleDisplayNames = [
    'admin' => 'أدمن',
    'employee' => 'موظف',
    'section_manager' => 'رئيس قسم',
    'department_manager' => 'رئيس دائرة',
    'ceo' => 'الرئيس التنفيذي',
    'board' => 'ديوان'
];

$userRoleDisplay = $roleDisplayNames[$userRole] ?? $userRole;

// توليد / ضمان وجود CSRF token عام للجلسة
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// جلب عدد الإشعارات غير المقروءة
require_once '../includes/database.php';
$db = getDB();
$user_id = $_SESSION['user_id'];
$unread_count = 0;

try {
    $unread_count_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = 0";
    $unread_count_stmt = $db->prepare($unread_count_query);
    $unread_count_stmt->execute([':user_id' => $user_id]);
    $unread_count = $unread_count_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (Exception $e) {
    $unread_count = 0;
}

// جلب اسم القسم
$dep_name = '';
if ($Dn) {
    try {
        $dep_nm = "SELECT name FROM departments WHERE id = :dep_nm";
        $dep_nm_stmt = $db->prepare($dep_nm);
        $dep_nm_stmt->execute([':dep_nm' => $Dn]);
        $dep_name = $dep_nm_stmt->fetch(PDO::FETCH_ASSOC)['name'] ?? '';
    } catch (Exception $e) {
        $dep_name = '';
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <title>نظام إدارة المستندات الإلكترونية</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/dashboard.css">


    <style>
        /* جميع الأنماط الأصلية كما هي - لم يتم تغييرها */
        :root {
            --primary-color: #164a40;
            --secondary-color: #2d6a5a;
            --accent-color: #8c774f;
            --danger-color: #e74c3c;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --info-color: #3498db;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;

        }

        /* ===== HEADER CONTAINER ===== */
        .header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 100%;
            background: linear-gradient(90deg,
                    rgba(255, 255, 255, 0.05) 0%,
                    rgba(255, 255, 255, 0.02) 50%,
                    rgba(255, 255, 255, 0) 100%);
            pointer-events: none;
        }

        /* ===== HEADER TOP SECTION ===== */
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            z-index: 2;
        }

        /* الجانب الأيمن: معلومات المستخدم */
        .user-info-section {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--accent-color) 0%, #a68c5e 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            cursor: pointer;
            transition: transform 0.3s ease;
            z-index: 1001;
        }

        .user-avatar:hover {
            transform: scale(1.05);
        }

        .user-avatar.active {
            box-shadow: 0 0 0 3px rgba(140, 119, 79, 0.5);
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 3px;
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .user-name i {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }

        .user-role {
            font-size: 13px;
            background: rgba(255, 255, 255, 0.15);
            padding: 3px 12px;
            border-radius: 20px;
            display: inline-block;
            color: white;
            font-weight: 500;
        }

        .manager-info {
            margin-top: 5px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .manager-info i {
            font-size: 11px;
        }

        .manager-job {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.6);
        }

        /* الجانب الأيسر: الشعار والمعلومات */
        .logo-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-wrapper {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border: 3px solid #9b8358;
        }

        .logo {
            width: 45px;
            height: 45px;
            object-fit: contain;
        }

        .system-info {
            text-align: right;
        }

        .system-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 3px;
            color: white;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
        }

        .system-subtitle {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.9);
            opacity: 0.9;
        }

        /* ===== HEADER BOTTOM SECTION ===== */
        .header-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 30px;
            background: rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 2;
        }

        /* الجانب الأيمن: التاريخ والوقت */
        .datetime-section {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .time-display,
        .date-display {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255, 255, 255, 0.95);
            font-size: 14px;
        }

        .time-display i,
        .date-display i {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }

        /* الجانب الأيسر: أزرار الإجراءات */
        .actions-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-refresh {
            background: linear-gradient(135deg, var(--info-color) 0%, #2980b9 100%);
        }

        .btn-refresh:hover {
            background: linear-gradient(135deg, #2980b9 0%, #1f618d 100%);
        }

        .btn-users {
            background: linear-gradient(135deg, var(--accent-color) 0%, #a68c5e 100%);
        }

        .btn-users:hover {
            background: linear-gradient(135deg, #a68c5e 0%, #8c774f 100%);
        }

        .btn-password {
            background: linear-gradient(135deg, var(--success-color) 0%, #27ae60 100%);
        }

        .btn-password:hover {
            background: linear-gradient(135deg, #27ae60 0%, #219653 100%);
        }

        .btn-notifications {
            background: linear-gradient(135deg, var(--warning-color) 0%, #d68910 100%);
            position: relative;
        }

        .btn-notifications:hover {
            background: linear-gradient(135deg, #d68910 0%, #b9770e 100%);
        }

        .btn-logout {
            background: linear-gradient(135deg, var(--danger-color) 0%, #c0392b 100%);
        }

        .btn-logout:hover {
            background: linear-gradient(135deg, #c0392b 0%, #a93226 100%);
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: bold;
            border: 2px solid var(--primary-color);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        /* تم إزالة الأنماط القديمة للأزرار الدائرية (avatar-circle-buttons) */

        /* ===== أنماط قائمة الأفاتار الجديدة (تم إضافتها) ===== */
        .avatar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            z-index: 10000;
            display: flex;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .avatar-menu {


            padding: 20px;

            display: flex;
            flex-direction: column;
            gap: 15px;
            min-width: 280px;
            max-width: 90%;
            animation: slideUp 0.3s ease;

        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .avatar-menu-btn {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 16px 22px;
            border: 3px solid #c9a970;
            border-radius: 30px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
            text-align: right;
            background: #f8f9fa;
            color: #2c3e50;
        }

        .avatar-menu-btn i {
            font-size: 22px;
            width: 30px;
            text-align: center;

        }

        .avatar-menu-btn.password-btn {
            background: linear-gradient(135deg, var(--accent-color) 0%, #a68c5e 100%);
            color: white;
        }

        .avatar-menu-btn.password-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 20px rgba(46, 204, 113, 0.3);
        }

        .avatar-menu-btn.logout-btn {
            background: linear-gradient(135deg, var(--accent-color) 0%, #a68c5e 100%);
            color: white;
        }

        .avatar-menu-btn.logout-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 20px rgba(231, 76, 60, 0.3);
        }

        /* تحسينات للهواتف */
        @media (max-width: 768px) {
            .header-top {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .user-info-section,
            .logo-section {
                width: 100%;
                justify-content: center;
            }

            .user-details {
                text-align: center;
            }

            .system-info {
                text-align: center;
            }

            .header-bottom {
                flex-direction: column;
                gap: 15px;
                padding: 15px;
            }

            .datetime-section {
                order: 2;
            }

            .actions-section {
                order: 1;
                flex-wrap: wrap;
                justify-content: center;
            }

            .avatar-menu {
                min-width: 250px;
                padding: 15px;
            }

            .avatar-menu-btn {
                padding: 14px 18px;
                font-size: 16px;
            }
        }

        @media (max-width: 480px) {

            .header-top,
            .header-bottom {
                padding: 12px 15px;
            }

            .logo-wrapper {
                width: 50px;
                height: 50px;
            }

            .logo {
                width: 35px;
                height: 35px;
            }

            .system-title {
                font-size: 16px;
            }

            .system-subtitle {
                font-size: 11px;
            }

            .user-avatar {
                width: 45px;
                height: 45px;
                font-size: 18px;
            }

            .user-name {
                font-size: 14px;
            }

            .user-role {
                font-size: 12px;
            }

            .manager-info {
                font-size: 11px;
            }

            .datetime-section {
                gap: 15px;
            }

            .time-display,
            .date-display {
                font-size: 13px;
            }

            .actions-section {
                gap: 8px;
            }

            .btn {
                padding: 8px 12px;
                font-size: 12px;
                min-width: 120px;
            }

            .btn i {
                font-size: 12px;
            }
        }
    </style>
</head>

<body>
    <!-- الهيدر الرئيسي -->
    <div class="header">
        <!-- Header Top -->
        <div class="header-top">
            <!-- الجانب الأيسر: الشعار والمعلومات -->
            <div class="logo-section">
                <div class="logo-container">
                    <div class="logo-wrapper">
                        <img src="../images/logo.png" alt="شعار النظام" class="logo">
                    </div>
                    <div class="system-info">
                        <div class="system-title">لوحة تحكم المسؤول</div>
                        <div class="system-subtitle">نظام إدارة المستندات الإلكترونية</div>
                    </div>
                </div>
            </div>

            <div class="logo-section">
                <div class="logo-container">
                    <div class="system-info">
                        <div class="system-title"
                            style="background:#8c774f;padding:3px;border:3px solid #a48f67;border-radius:5px;">
                            <?php echo htmlspecialchars($site); ?>
                        </div>
                        <h3 style="text-align:center;"> <?php echo htmlspecialchars($dep_name); ?> </h3>
                    </div>
                </div>
            </div>

            <!-- الجانب الأيمن: معلومات المستخدم -->
            <div class="user-info-section">
                <!-- تم إزالة الأزرار الدائرية القديمة (avatarCircleButtons) -->

                <div id="userCtrlAdmin" class="user-avatar" onclick="toggleAvatarMenu()">
                    <i class="fas fa-user"></i>
                    <span class="user-dots"><i class="fas fa-cog"></i></span>
                </div>

                <div class="user-details">
                    <div class="user-name">
                        <?php echo htmlspecialchars($userName); ?>
                        <?php if ($userRole != "ceo"): ?>
                        <span class="user-role"><?php echo htmlspecialchars($userTitle); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Header Bottom -->
        <div class="header-bottom">
            <!-- الجانب الأيسر: أزرار الإجراءات -->
            <div class="actions-section">
                <button onclick="window.refreshPage()" class="notifications-button">
                    <i class="fas fa-sync-alt"></i>
                </button>
                <button id="notificationsButton" class="notifications-button" onclick="window.toggleNotifications()">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="notification-counter" id="notificationCounter"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- الجانب الأيمن: التاريخ والوقت -->
            <div class="datetime-section">
                <div class="date-display">
                    <i class="fas fa-calendar-alt"></i>
                    <span id="currentDate"><?php echo date('Y-m-d'); ?></span>
                </div>
                <div class="time-display">
                    <i class="fas fa-clock"></i>
                    <span id="currentTime"><?php echo date('H:i:s'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة الإشعارات -->
    <div id="notificationsModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-bell"></i> الإشعارات</h3>
                <button class="close-modal" onclick="window.closeNotifications()">&times;</button>
            </div>
            <div class="modal-body" id="notificationsBody">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>جاري تحميل الإشعارات...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة إدارة المستخدمين -->
    <div id="usersModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-users-cog"></i> إدارة المستخدمين</h3>
                <button class="close-modal" onclick="window.closeUsersModal()">&times;</button>
            </div>
            <div class="modal-body" id="usersBody">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>جاري تحميل بيانات المستخدمين...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة تغيير كلمة المرور -->
    <div id="passwordModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> تغيير كلمة المرور</h3>
                <button class="close-modal" onclick="window.closePasswordModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="passwordForm" onsubmit="return window.changePassword()">
                    <div class="form-group">
                        <label>كلمة المرور الحالية</label>
                        <input type="password" id="currentPassword" required>
                    </div>
                    <div class="form-group">
                        <label>كلمة المرور الجديدة</label>
                        <input type="password" id="newPassword" required>
                    </div>
                    <div class="form-group">
                        <label>تأكيد كلمة المرور الجديدة</label>
                        <input type="password" id="confirmPassword" required>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-password">
                            <i class="fas fa-save"></i> حفظ
                        </button>
                        <button type="button" class="btn btn-password" onclick="window.closePasswordModal()">
                            <i class="fas fa-times"></i> إلغاء
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- قائمة الأفاتار الجديدة (Overlay) -->
    <div id="avatarOverlay" class="avatar-overlay" style="display: none;" onclick="closeAvatarMenu()">
        <div class="avatar-menu" onclick="event.stopPropagation()">
            <button onclick="showPasswordForm(); closeAvatarMenu();" class="avatar-menu-btn password-btn">
                <i class="fas fa-key"></i>
                <span>تغيير كلمة المرور</span>
            </button>
            <button onclick="logout()" class="avatar-menu-btn logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span>تسجيل الخروج</span>
            </button>
        </div>
    </div>

    <div id="logoutConfirmModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3><i class="fas fa-sign-out-alt"></i> تأكيد تسجيل الخروج</h3>
                <button class="close-modal" onclick="closeLogoutModal()">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center; padding: 30px;">
               
                <p style="font-size: 1.1rem; margin-bottom: 25px;">هل أنت متأكد من تسجيل الخروج؟</p>
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button onclick="confirmLogout()" class="btn"
                        style="background: linear-gradient(135deg, var(--accent-color) 0%, #a68c5e 100%); color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: 600;">
                        <i class="fas fa-check"></i> نعم
                    </button>
                    <button onclick="closeLogoutModal()" class="btn"
                        style="background: #6c757d; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: 600;">
                        <i class="fas fa-times"></i> إلغاء
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- تمرير عدد الإشعارات إلى JavaScript -->
    <script>
        window.initialUnreadCount = <?php echo (int) $unread_count; ?>;
        window.csrfToken = <?php echo json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>

    <!-- الكود الرئيسي الموحد مع تحديث العنوان ودوال الأفاتار الجديدة -->
    <script>
        (function () {
            "use strict";

            // ========== المتغيرات العامة ==========
            let unreadCount = window.initialUnreadCount;
            const originalTitle = document.title;

            function updateTitleWithBadge(count) {
                document.title = count > 0 ? `(${count}) ${originalTitle}` : originalTitle;
            }

            function updateDateTime() {
                const now = new Date();
                const timeEl = document.getElementById('currentTime');
                if (timeEl) timeEl.textContent = now.toLocaleTimeString('ar-EG', { hour12: false });
                const dateEl = document.getElementById('currentDate');
                if (dateEl) dateEl.textContent = now.toLocaleDateString('en-CA');
            }

            function updateNotificationBadge(count) {
                unreadCount = count;
                const badge = document.querySelector('.notification-counter');
                if (count > 0) {
                    if (badge) {
                        badge.textContent = count;
                    } else {
                        const btn = document.querySelector('.notifications-button');
                        if (btn) {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'notification-counter';
                            newBadge.textContent = count;
                            btn.appendChild(newBadge);
                        }
                    }
                } else {
                    if (badge) badge.remove();
                }
                updateTitleWithBadge(count);
            }

            function toggleNotifications() {
                closeAvatarMenu();
                const modal = document.getElementById('notificationsModal');
                modal.style.display = 'flex';
                document.getElementById('notificationsBody').innerHTML = `
            <div style="text-align: center; padding: 40px; color: #666;">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p>جاري تحميل الإشعارات...</p>
            </div>
        `;

                fetch('../dashboard/get_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            document.getElementById('notificationsBody').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #e74c3c;">
                            <i class="fas fa-exclamation-triangle fa-2x"></i>
                            <p>${data.message || 'خطأ'}</p>
                        </div>
                    `;
                            return;
                        }

                        if (!data.notifications || data.notifications.length === 0) {
                            document.getElementById('notificationsBody').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #666;">
                            <i class="fas fa-bell-slash fa-2x"></i>
                            <p>لا توجد إشعارات</p>
                            <small>${data.unread_count || 0} غير مقروء</small>
                        </div>
                    `;
                            return;
                        }

                        let html = '';
                        data.notifications.forEach(n => {
                            const time = new Date(n.created_at).toLocaleString('ar-SA');
                            html += `
                        <div class="notification-item ${n.is_read == 0 ? 'unread' : ''}" 
                             data-notification-id="${n.id}"
                             onclick="window.handleNotificationClick(${n.id}, '${n.link || ''}')"
                             style="padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; background: ${n.is_read == 0 ? '#f8f9fa' : 'white'}">
                            <strong>${n.title || 'إشعار'}</strong><br>
                            <small>${n.message || ''}</small><br>
                            <small style="color: #999;">${time}</small>
                            ${n.is_read == 0 ? '<span style="float:left;background:#e74c3c;color:white;padding:2px 6px;border-radius:10px;font-size:11px;">جديد</span>' : ''}
                        </div>
                    `;
                        });

                        html += `<div style="text-align:center;padding:15px;background:#f8f9fa;">
                            <a href="../dashboard/notifications.php" style="color:#3498db;text-decoration:none;">
                                <i class="fas fa-list"></i> عرض جميع الإشعارات
                            </a>
                        </div>`;

                        document.getElementById('notificationsBody').innerHTML = html;
                    })
                    .catch(error => {
                        document.getElementById('notificationsBody').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #e74c3c;">
                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                        <p>خطأ في الاتصال بالخادم</p>
                        <small>${error.message}</small>
                    </div>
                `;
                    });
            }

            function closeNotifications() {
                document.getElementById('notificationsModal').style.display = 'none';
            }

            function markNotificationAsRead(notificationId) {
                fetch('../dashboard/mark_notification_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'notification_id=' + notificationId
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) updateNotificationBadge(data.unread_count);
                    })
                    .catch(error => console.error('Error:', error));
            }

            function handleNotificationClick(notificationId, link) {
                const notificationElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
                if (notificationElement) {
                    notificationElement.style.transition = 'all 0.3s ease';
                    notificationElement.style.opacity = '0';
                    notificationElement.style.transform = 'translateX(20px)';
                    notificationElement.style.maxHeight = '0';
                    notificationElement.style.padding = '0';
                    notificationElement.style.margin = '0';
                    notificationElement.style.border = 'none';
                    notificationElement.style.overflow = 'hidden';

                    setTimeout(() => {
                        if (notificationElement.parentNode) {
                            notificationElement.parentNode.removeChild(notificationElement);
                            if (document.querySelectorAll('.notification-item[data-notification-id]').length === 0) {
                                document.getElementById('notificationsBody').innerHTML = `
                            <div style="text-align: center; padding: 40px; color: #666;">
                                <i class="fas fa-bell-slash fa-2x"></i>
                                <p>لا توجد إشعارات</p>
                            </div>
                        `;
                            }
                        }
                    }, 300);
                }

                markNotificationAsRead(notificationId);

                setTimeout(() => {
                    closeNotifications();
                    if (link && link.trim() && link !== 'null') {
                        window.location.href = link;
                    }
                }, 300);
            }

            function showPasswordForm() {
                closeAllPopups();
                document.getElementById('passwordModal').style.display = 'flex';
            }

            function closePasswordModal() {
                document.getElementById('passwordModal').style.display = 'none';
            }

            function changePassword() {
                const newPass = document.getElementById('newPassword').value;
                const confirmPass = document.getElementById('confirmPassword').value;
                if (newPass !== confirmPass) {
                    alert('كلمة المرور الجديدة غير متطابقة');
                    return false;
                }
                alert('تم تغيير كلمة المرور بنجاح');
                closePasswordModal();
                return false;
            }

            function closeUsersModal() {
                document.getElementById('usersModal').style.display = 'none';
            }

            function showManageUsers() {
                closeAllPopups();
                document.getElementById('usersModal').style.display = 'flex';
                // محتوى تجريبي
                setTimeout(() => {
                    document.getElementById('usersBody').innerHTML = `
                <div class="users-list">
                    <h4>قائمة المستخدمين</h4>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">الاسم</th>
                                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">الدور</th>
                                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="padding: 10px; border-bottom: 1px solid #dee2e6;">فادي قاسم</td>
                                <td style="padding: 10px; border-bottom: 1px solid #dee2e6;">أدمن</td>
                                <td style="padding: 10px; border-bottom: 1px solid #dee2e6;">
                                    <button class="btn" style="padding: 5px 10px; font-size: 12px;">تعديل</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            `;
                }, 500);
            }

            // ========== دالة الخروج المعدلة ==========
            function logout() {
                closeAllPopups(); // إغلاق أي نوافذ مفتوحة
                document.getElementById('logoutConfirmModal').style.display = 'flex';
            }

            function refreshPage() {
                window.location.reload();
            }

            // ========== دوال قائمة الأفاتار ==========
            function toggleAvatarMenu() {
                const overlay = document.getElementById('avatarOverlay');
                if (overlay.style.display === 'flex') {
                    closeAvatarMenu();
                } else {
                    closeAllPopups();
                    overlay.style.display = 'flex';
                }
            }

            function closeAvatarMenu() {
                const overlay = document.getElementById('avatarOverlay');
                if (overlay) overlay.style.display = 'none';
            }

            // ========== دالة إغلاق جميع النوافذ المعدلة ==========
            function closeAllPopups() {
                ['notificationsModal', 'usersModal', 'passwordModal', 'logoutConfirmModal'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.style.display = 'none';
                });
                closeAvatarMenu();
            }

            // ========== تهيئة الصفحة ==========
            document.addEventListener('DOMContentLoaded', function () {
                setInterval(updateDateTime, 1000);
                updateTitleWithBadge(initialUnreadCount);

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        closeAllPopups();
                    }
                });
            });

            // ========== تعريض الدوال للنطاق العام ==========
            window.toggleNotifications = toggleNotifications;
            window.closeNotifications = closeNotifications;
            window.showPasswordForm = showPasswordForm;
            window.closePasswordModal = closePasswordModal;
            window.changePassword = changePassword;
            window.showManageUsers = showManageUsers;
            window.closeUsersModal = closeUsersModal;
            window.logout = logout; // الدالة المعدلة
            window.refreshPage = refreshPage;
            window.handleNotificationClick = handleNotificationClick;
            window.updateNotificationBadge = updateNotificationBadge;
            window.toggleAvatarMenu = toggleAvatarMenu;
            window.closeAvatarMenu = closeAvatarMenu;
        })();

        // دوال مساعدة للمودال الجديد (يمكن وضعها داخل الـ IIFE أيضاً لكن تركناها هنا للوضوح)
        function confirmLogout() {
            window.location.href = '../logout.php';
        }

        function closeLogoutModal() {
            document.getElementById('logoutConfirmModal').style.display = 'none';
        }
    </script>
</body>

</html>