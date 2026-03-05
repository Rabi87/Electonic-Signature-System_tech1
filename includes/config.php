<?php
// منع التحميل المزدوج
if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

// إعدادات الاتصال بقاعدة البيانات
define('DB_HOST', 'mysql');
define('DB_NAME', 'electronic_signature');
define('DB_USER', 'app_user');
define('DB_PASS', 'secure_password_123');
define('DB_PORT', '3306');

// إعدادات التطبيق
define('APP_NAME', 'نظام التوقيع الإلكتروني');
define('APP_URL', 'http://localhost/electronic-signature-system');
define('UPLOAD_PATH', 'uploads/documents/');
define('SIGNATURE_PATH', 'uploads/signatures/');

// وضع التشغيل للتطبيق: production أو development
if (!defined('APP_ENV')) {
    define('APP_ENV', 'production');
}

// تحديد منطقة الوقت
date_default_timezone_set('Asia/Riyadh');

// إعداد عرض الأخطاء بناءً على وضع التشغيل
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    // في بيئة الإنتاج: تسجيل الأخطاء فقط بدون عرضها للمستخدم
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

?>