<?php
// [file name]: employee_dashboard.php (مثال)
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من الدور
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$role_name = $_SESSION['role_name'];
$department_id = $_SESSION['department_id'] ?? null;

// جلب بيانات المؤشرات
require_once '../includes/dashboard_indicators.php';
$indicators = getDocumentIndicators($db, $user_id, $role_name, $department_id);

// معالجة البحث والتصفية
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// بناء الاستعلام لجلب المستندات
$where_conditions = ["d.current_holder_id = :user_id"];
$params = [':user_id' => $user_id];

// تطبيق الفلاتر
if (!empty($search)) {
    $where_conditions[] = "(d.title LIKE :search OR d.description LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if (!empty($status)) {
    $where_conditions[] = "d.current_status = :status";
    $params[':status'] = $status;
}

if (!empty($priority)) {
    $where_conditions[] = "d.priority = :priority";
    $params[':priority'] = $priority;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(d.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(d.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_sql = 'WHERE ' . implode(' AND ', $where_conditions);

// جلب المستندات
$query = "
    SELECT 
        d.*,
        u.full_name as creator_name,
        u.email as creator_email,
        dep.name as department_name,
        (SELECT COUNT(*) FROM signatures s WHERE s.document_id = d.id) as signatures_count,
        (SELECT COUNT(*) FROM document_comments dc WHERE dc.document_id = d.id) as comments_count
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN departments dep ON u.department_id = dep.id
    {$where_sql}
    ORDER BY {$sort_by} {$sort_order}
    LIMIT 100
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تضمين الهيدر
include_once '../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- المؤشرات الدائرية -->
    <?php displayIndicators($indicators); ?>
    
    <!-- الفلاتر -->
    <?php 
    require_once '../includes/dashboard_filters.php';
    displayDashboardFilters($search, $status, $priority, $date_from, $date_to);
    ?>
    
    <!-- المحتوى (جدول/بطاقات) -->
    <?php 
    require_once '../includes/dashboard_content.php';
    $view_type = $_GET['view'] ?? 'table';
    displayDocumentsContent($documents, $view_type);
    ?>
</div>

<?php include_once '../includes/footer.php'; ?>