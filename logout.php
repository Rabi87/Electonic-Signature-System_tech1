<?php
// بدء الجلسة أولاً
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// تسجيل الخروج إذا كان المستخدم مسجلاً
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/includes/functions.php'; // تأكد من المسار الصحيح
    logUserActivity($_SESSION['user_id'], 'LOGOUT', 'تسجيل خروج', null);
}


// تدمير جميع متغيرات الجلسة
$_SESSION = array();


// إذا كنت تريد تدمير الجلسة تماماً، قم أيضاً بحذف كوكيز الجلسة
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}


// أخيراً، قم بتدمير الجلسة
session_destroy();

// توجيه المستخدم إلى صفحة تسجيل الدخول
header("Location: login.php");
exit();
?>