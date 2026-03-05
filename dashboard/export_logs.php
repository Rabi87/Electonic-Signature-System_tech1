<?php
// export_logs.php
require_once 'includes/session.php';
checkLogin();
require_once 'includes/config.php';
require_once 'includes/database.php';

// التحقق من الصلاحيات
if ($_SESSION['role_name'] !== 'admin') {
    die('صلاحية غير كافية');
}

$db = getDB();

// معالجة معاملات التصدير
$export_type = $_GET['export'] ?? 'csv';
$search = $_GET['search'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$action = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// بناء شروط البحث
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(u.full_name LIKE :search OR ul.action LIKE :search2 OR ul.details LIKE :search3)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
    $params[':search3'] = "%{$search}%";
}

if (!empty($user_id)) {
    $where_conditions[] = "ul.user_id = :user_id";
    $params[':user_id'] = $user_id;
}

if (!empty($action)) {
    $where_conditions[] = "ul.action = :action";
    $params[':action'] = $action;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(ul.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(ul.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// جلب البيانات
$query = "
    SELECT ul.*, u.full_name, u.username, r.role_name
    FROM user_logs ul
    LEFT JOIN users u ON ul.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    {$where_sql}
    ORDER BY ul.created_at DESC
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($export_type === 'csv') {
    // رؤوس ملف CSV
    $headers = [
        'ID',
        'التاريخ والوقت',
        'المستخدم',
        'اسم المستخدم',
        'الدور',
        'الإجراء',
        'معرف المستند',
        'عنوان IP',
        'المتصفح',
        'التفاصيل'
    ];
    
    // إعدادات التصدير
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="system_logs_' . date('Y-m-d_H-i-s') . '.csv"');
    
    // فتح مخرجات الملف
    $output = fopen('php://output', 'w');
    
    // كتابة رأس الملف
    fputcsv($output, $headers);
    
    // كتابة البيانات
    foreach ($logs as $log) {
        $row = [
            $log['id'],
            $log['created_at'],
            $log['full_name'],
            $log['username'],
            $log['role_name'],
            $log['action'],
            $log['document_id'] ?? '',
            $log['ip_address'] ?? '',
            substr($log['user_agent'] ?? '', 0, 100),
            $log['details'] ?? ''
        ];
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}
?>