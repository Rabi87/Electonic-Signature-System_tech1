<?php
/**
 * تسجيل نشاط المستخدم
 * @param int $user_id معرف المستخدم
 * @param string $action نوع الإجراء (مثل 'LOGIN', 'UPLOAD', 'DELETE')
 * @param string $description وصف النشاط
 * @param int|null $document_id معرف المستند المرتبط (إن وجد)
 * @param array $additional_data بيانات إضافية (تُحول إلى JSON)
 */
function logUserActivity($user_id, $action, $description = '', $document_id = null, $additional_data = []) {
    // إذا كان $db غير معرف كمتغير عام، نستخدم الدالة getDB()
    if (!isset($GLOBALS['db'])) {
        require_once __DIR__ . '/database.php';
        $db = getDB();
    } else {
        $db = $GLOBALS['db'];
    }

    // الحصول على معلومات المستخدم الحالية (يمكن أخذها من الجلسة مباشرة لتجنب استعلام إضافي)
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
        $username = $_SESSION['username'] ?? null;
        $full_name = $_SESSION['full_name'] ?? null;
        $role_name = $_SESSION['role_name'] ?? null;
    } else {
        // إذا لم تكن الجلسة متطابقة (مثلاً في حالة تسجيل الدخول)، نجلب من قاعدة البيانات
        $stmt = $db->prepare("SELECT username, full_name, role_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $username = $user['username'] ?? null;
        $full_name = $user['full_name'] ?? null;
        $role_name = $user['role_name'] ?? null;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt = $db->prepare("
        INSERT INTO user_activity_logs 
        (user_id, username, full_name, role_name, action, description, ip_address, user_agent, document_id, additional_data, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $user_id,
        $username,
        $full_name,
        $role_name,
        $action,
        $description,
        $ip,
        $user_agent,
        $document_id,
        json_encode($additional_data, JSON_UNESCAPED_UNICODE)
    ]);
}