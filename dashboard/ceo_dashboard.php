<?php
/**
 * لوحة تحكم رئيس الدائرة (department_manager) - نظام التوقيع الإلكتروني
 */

require_once '../includes/session.php';
checkLogin();

require_once '../includes/config.php';
require_once '../includes/database.php';

$pageTitle = 'لوحة التحكم';

// التحقق من أن المستخدم مسجل دخول وله دور department_manager
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'ceo') {
    header("Location: ../login.php");
    exit();
}

$db = getDB();
$_SESSION['role'] = 'department_manager';
$user_id = $_SESSION['user_id'];

// جلب بيانات القسم الخاص برئيس الدائرة
$user_department_query = "SELECT department_id FROM users WHERE id = :user_id";
$user_department_stmt = $db->prepare($user_department_query);
$user_department_stmt->execute([':user_id' => $user_id]);
$user_department = $user_department_stmt->fetch(PDO::FETCH_ASSOC);
$department_id = $user_department['department_id'] ?? null;

// جلب عدد الإشعارات غير المقروءة
$unread_count_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = 0";
$unread_count_stmt = $db->prepare($unread_count_query);
$unread_count_stmt->execute([':user_id' => $user_id]);
$unread_count = $unread_count_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// معالجة البحث والتصفية
$search = $_GET['search'] ?? '';
$document_type = $_GET['document_type'] ?? '';
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// معالجة تصفية الأهمية من الأزرار الدائرية
$importance = $_GET['importance'] ?? '';
if (!empty($importance)) {
    if ($importance === 'سري') {
        $priority = 'urgent';
    } elseif ($importance === 'عاجل') {
        $priority = 'high';
    } elseif ($importance === 'عادي') {
        $priority = 'normal';
    }
}

$viewMode = isset($_GET['view']) ? $_GET['view'] : 'list';




// التحقق إذا كان هناك مستند مطلوب فتحه
$open_document_id = $_GET['open_document'] ?? null;
if ($open_document_id) {
    // التحقق من أن المستند موجود ويتبع رئيس الدائرة
    $check_query = "
        SELECT d.id 
        FROM documents d
        LEFT JOIN document_workflow dw ON d.id = dw.document_id
        LEFT JOIN users u ON d.created_by = u.id
        WHERE d.id = :doc_id 
        AND (
            d.created_by = :user_id 
            OR d.current_holder_id = :user_id2 
            OR dw.to_user_id = :user_id3
            OR u.department_id = :dept_id
        )
    ";

    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute([
        ':doc_id' => $open_document_id,
        ':user_id' => $user_id,
        ':user_id2' => $user_id,
        ':user_id3' => $user_id,
        ':dept_id' => $department_id
    ]);

    if ($check_stmt->fetch()) {
        // عرض نافذة المستند تلقائياً
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    openDocumentInDashboard({$open_document_id});
                }, 1000);
            });
        </script>";
    }
}

// بناء شروط البحث
$where_conditions = [];
$params = [];

// تحديد نوع المستند
if ($document_type === 'incoming') {
    // المستندات الموجهة لرئيس الدائرة فقط
    $where_conditions[] = "(d.current_holder_id = :user_id OR dw.to_user_id = :user_id2) AND d.created_by != :user_id3";
    $params[':user_id'] = $user_id;
    $params[':user_id2'] = $user_id;
    $params[':user_id3'] = $user_id;
} elseif ($document_type === 'outgoing') {
    // المستندات التي أنشأها رئيس الدائرة فقط
    $where_conditions[] = "d.created_by = :user_id";
    $params[':user_id'] = $user_id;
} elseif ($document_type === 'department') {
    // المستندات الخاصة بالقسم فقط
    $where_conditions[] = "u.department_id = :dept_id AND d.created_by != :user_id";
    $params[':dept_id'] = $department_id;
    $params[':user_id'] = $user_id;

} else {
    // جميع المستندات المتعلقة برئيس الدائرة (التي أنشأها أو الموجهة إليه أو في قسمه)
    $where_conditions[] = "(d.created_by = :user_id OR d.current_holder_id = :user_id2 OR dw.to_user_id = :user_id3 OR u.department_id = :dept_id)";
    $params[':user_id'] = $user_id;
    $params[':user_id2'] = $user_id;
    $params[':user_id3'] = $user_id;
    $params[':dept_id'] = $department_id;
}

// تطبيق البحث النصي
if (!empty($search)) {
    $where_conditions[] = "(d.title LIKE :search OR d.description LIKE :search OR u.full_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

// تصفية الحالة
if (!empty($status)) {
    $where_conditions[] = "d.current_status = :status";
    $params[':status'] = $status;
}

// تصفية الأولوية
if (!empty($priority)) {
    $where_conditions[] = "d.priority = :priority";
    $params[':priority'] = $priority;
}

// تصفية التاريخ
if (!empty($date_from)) {
    $where_conditions[] = "DATE(d.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}
if (!empty($date_to)) {
    $where_conditions[] = "DATE(d.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

// استثناء المستندات المكتملة من العرض
$where_conditions[] = "d.current_status != 'completed,pending'";

// بناء جملة WHERE
$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// جلب إحصائيات الأهمية للمستندات المتعلقة برئيس الدائرة
// في قسم إحصائيات الأهمية، تحتاج إلى تعديل الاستعلامات لاستبعاد المستندات المكتملة أيضاً

// جلب إحصائيات الأهمية للمستندات المتعلقة برئيس الدائرة
$importance_stats_query = "
    SELECT 
        COALESCE(d.priority, 'normal') as priority,
        COUNT(DISTINCT d.id) as count
    FROM documents d
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN document_user_status dus ON d.id = dus.document_id AND dus.user_id = :user_id
    WHERE (d.created_by = :user_id OR d.current_holder_id = :user_id2 OR dw.to_user_id = :user_id3 OR u.department_id = :dept_id)
    AND (dus.status IS NULL OR dus.status != 'completed')  -- استبعاد المستندات المكتملة للمستخدم
    GROUP BY d.priority
";

$importance_stats_params = [
    ':user_id' => $user_id,
    ':user_id2' => $user_id,
    ':user_id3' => $user_id,
    ':dept_id' => $department_id
];

$importance_stmt = $db->prepare($importance_stats_query);
$importance_stmt->execute($importance_stats_params);
$importance_stats = $importance_stmt->fetchAll(PDO::FETCH_ASSOC);

// تهيئة مصفوفة إحصائيات الأهمية
$importance_data = [
    'سري' => 0,
    'عاجل' => 0,
    'عادي' => 0,
    'all' => 0
];

// حساب إجمالي المستندات للزر "الكل" (باستثناء المكتملة للمستخدم)
$total_all_query = "
    SELECT COUNT(DISTINCT d.id) as total 
    FROM documents d
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN document_user_status dus ON d.id = dus.document_id AND dus.user_id = :user_id
    WHERE (d.created_by = :user_id OR d.current_holder_id = :user_id2 OR dw.to_user_id = :user_id3 OR u.department_id = :dept_id)
    AND (dus.status IS NULL OR dus.status != 'completed')  -- استبعاد المستندات المكتملة للمستخدم
";
$total_all_stmt = $db->prepare($total_all_query);
$total_all_stmt->execute($importance_stats_params);
$total_all = $total_all_stmt->fetch(PDO::FETCH_ASSOC);
$importance_data['all'] = $total_all['total'] ?? 0;


// تعبئة إحصائيات الأهمية
foreach ($importance_stats as $stat) {
    if ($stat['priority'] === 'urgent') {
        $importance_data['سري'] = $stat['count'];
    } elseif ($stat['priority'] === 'high') {
        $importance_data['عاجل'] = $stat['count'];
    } elseif ($stat['priority'] === 'normal' || $stat['priority'] === 'medium' || $stat['priority'] === 'low') {
        // نجمع العادية والمتوسطة والمنخفضة تحت بند "عادي"
        $importance_data['عادي'] += $stat['count'];
    }
}
// جلب جميع الموظفين في النظام (لأن رئيس الدائرة يمكنه الوصول للجميع)
$all_users_query = $db->prepare("
    SELECT u.id, u.full_name, u.email, r.role_name, d.name as department_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE u.is_active = 1 AND u.id != :user_id
    ORDER BY r.role_name, u.full_name
");
$all_users_query->execute([':user_id' => $user_id]);
$all_users = $all_users_query->fetchAll(PDO::FETCH_ASSOC);

// جلب المستندات مع اسم المستخدم المستهدف
$query = "
    SELECT DISTINCT
        d.*,
        u.full_name as creator_name,
        u.email as creator_email,
        u2.full_name as current_holder_name,
        dep.name as department_name,
        (SELECT COUNT(*) FROM signatures s WHERE s.document_id = d.id) as signatures_count,
        (SELECT GROUP_CONCAT(DISTINCT su.full_name) FROM signatures sig 
         LEFT JOIN users su ON sig.user_id = su.id WHERE sig.document_id = d.id) as signatories,
        -- الحصول على اسم المستخدم المستهدف من آخر خطوة workflow
        (SELECT full_name FROM users WHERE id = (
            SELECT to_user_id 
            FROM document_workflow 
            WHERE document_id = d.id AND is_current_step = 1 
            LIMIT 1
        )) as assigned_to_name,
        -- جلب حالة المستخدم مباشرة من document_user_status
        dus.status as user_status,
        -- الأرقام الرسمية (مثل لوحة الديوان)
        (SELECT fv.value_data 
         FROM document_fields df 
         LEFT JOIN field_values fv ON df.id = fv.field_id 
         WHERE df.document_id = d.id 
           AND df.field_type = 'text'
           AND fv.value_data IS NOT NULL
           AND TRIM(fv.value_data) != ''
         ORDER BY fv.created_at DESC 
         LIMIT 1) as public_number,
        (SELECT fv.value_data 
         FROM document_fields df 
         LEFT JOIN field_values fv ON df.id = fv.field_id 
         WHERE df.document_id = d.id 
           AND df.field_type = 'text'
           AND fv.value_data IS NOT NULL
           AND TRIM(fv.value_data) != ''
         ORDER BY fv.created_at ASC 
         LIMIT 1) as private_number
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN users u2 ON d.current_holder_id = u2.id
    LEFT JOIN departments dep ON u.department_id = dep.id
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    LEFT JOIN document_user_status dus ON d.id = dus.document_id AND dus.user_id = :current_user_id
    {$where_sql}
    AND (dus.status IS NULL OR dus.status != 'completed')
    ORDER BY {$sort_by} {$sort_order}
    LIMIT 100
";

$params[':current_user_id'] = $user_id;

$stmt = $db->prepare($query);
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب إحصائيات رئيس الدائرة (باستثناء المكتملة)
$stats_query = "
    SELECT 
        COUNT(DISTINCT d.id) as total_documents,
        SUM(CASE WHEN d.created_by = :user_id THEN 1 ELSE 0 END) as outgoing,
        SUM(CASE WHEN d.created_by != :user_id2 AND (d.current_holder_id = :user_id3 OR dw.to_user_id = :user_id4) THEN 1 ELSE 0 END) as incoming,
        SUM(CASE WHEN u.department_id = :dept_id AND d.created_by != :user_id5 THEN 1 ELSE 0 END) as department_docs,
        SUM(CASE WHEN d.current_status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN d.current_status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN d.current_status = 'under_review' THEN 1 ELSE 0 END) as under_review,
        SUM(CASE WHEN d.current_status = 'completion_required' THEN 1 ELSE 0 END) as completion_required,
        SUM(CASE WHEN d.priority = 'urgent' THEN 1 ELSE 0 END) as urgent_docs,
        SUM(CASE WHEN d.current_status = 'partially_signed' THEN 1 ELSE 0 END) as partially_signed,
        SUM(CASE WHEN d.current_status = 'draft' THEN 1 ELSE 0 END) as draft,
        SUM(CASE WHEN d.current_status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM documents d
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    LEFT JOIN users u ON d.created_by = u.id
    WHERE (d.created_by = :user_id6 
        OR d.current_holder_id = :user_id7 
        OR dw.to_user_id = :user_id8
        OR u.department_id = :dept_id2)
    AND d.current_status != 'completed'
";

$stats_params = [
    ':user_id' => $user_id,
    ':user_id2' => $user_id,
    ':user_id3' => $user_id,
    ':user_id4' => $user_id,
    ':user_id5' => $user_id,
    ':user_id6' => $user_id,
    ':user_id7' => $user_id,
    ':user_id8' => $user_id,
    ':dept_id' => $department_id,
    ':dept_id2' => $department_id
];

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// جلب إحصائيات إضافية
$department_stats = $db->prepare("
    SELECT 
        COUNT(DISTINCT u.id) as total_employees,
        d.name as department_name
    FROM users u
    JOIN departments d ON u.department_id = d.id
    WHERE u.department_id = :dept_id AND u.is_active = 1 AND u.id != :user_id
");
$department_stats->execute([':dept_id' => $department_id, ':user_id' => $user_id]);
$dept_stats = $department_stats->fetch(PDO::FETCH_ASSOC);

// دمج الإحصائيات
$stats['total_employees'] = $dept_stats['total_employees'] ?? 0;
$stats['department_name'] = $dept_stats['department_name'] ?? 'غير محدد';

// جلب إحصائيات التوقيعات
$signatures_stats = $db->prepare("
    SELECT 
        COUNT(*) as total_signatures,
        SUM(CASE WHEN DATE(signed_at) = CURDATE() THEN 1 ELSE 0 END) as today_signatures
    FROM signatures 
    WHERE user_id = :user_id
");
$signatures_stats->execute([':user_id' => $user_id]);
$signatures_data = $signatures_stats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">


</head>

<body>

    <?php
    $user_initials = mb_substr($_SESSION['full_name'], 0, 1, 'UTF-8');
    ?>
    <div class="background-animation">
        <div class="floating-element"></div>
        <div class="floating-element"></div>
        <div class="floating-element"></div>
    </div>

    <div class="container">
        <?php include '../includes/header.php'; ?>

        <!-- قسم الفلاتر الدائرية -->
        <div class="filter-section">
            <div class="filters-container">
                <div style="display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 30px;">

                    <!-- زر إضافة مستند --><!--
                    <div class="circle-filter-container">
                        <a href="../documents/document_upload.php?for=department_manager&return_to=department_manager_dashboard" target="_self" class="circle-filter-btn add">
                            <i class="fas fa-plus"></i>
                        </a>
                        <span class="filter-label">إضافة مستند</span>
                    </div> -->

                    <!-- زر الكل -->
                    <div class="circle-filter-container">
                        <button class="circle-filter-btn all <?php echo empty($importance) ? 'active' : ''; ?>"
                            onclick="filterByImportance('')" title="عرض الكل">
                            <i class="fas fa-layer-group"></i>
                            <?php if ($importance_data['all'] > 0): ?>
                                <span class="circle-count"><?php echo $importance_data['all']; ?></span>
                            <?php endif; ?>
                        </button>
                        <span class="filter-label">الكل</span>
                    </div>

                    <!-- زر سري -->
                    <div class="circle-filter-container">
                        <button
                            class="circle-filter-btn secret <?php echo $importance === 'سري' ? 'active' : ''; ?> <?php echo $importance_data['سري'] > 0 ? 'flash' : ''; ?>"
                            onclick="filterByImportance('سري')" title="سرية">
                            <i class="fas fa-lock"></i>
                            <?php if ($importance_data['سري'] > 0): ?>
                                <span class="circle-count"><?php echo $importance_data['سري']; ?></span>
                            <?php endif; ?>
                        </button>
                        <span class="filter-label">سرية</span>
                    </div>

                    <!-- زر عاجل -->
                    <div class="circle-filter-container">
                        <button
                            class="circle-filter-btn urgent <?php echo $importance === 'عاجل' ? 'active' : ''; ?> <?php echo $importance_data['عاجل'] > 0 ? 'flash' : ''; ?>"
                            onclick="filterByImportance('عاجل')" title="عاجلة">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?php if ($importance_data['عاجل'] > 0): ?>
                                <span class="circle-count urgent"><?php echo $importance_data['عاجل']; ?></span>
                            <?php endif; ?>
                        </button>
                        <span class="filter-label">عاجلة</span>
                    </div>

                    <!-- زر عادي -->
                    <div class="circle-filter-container">
                        <button
                            class="circle-filter-btn normal <?php echo $importance === 'عادي' ? 'active' : ''; ?> <?php echo $importance_data['عادي'] > 0 ? 'flash' : ''; ?>"
                            onclick="filterByImportance('عادي')" title="عادية">
                            <i class="fas fa-file"></i>
                            <?php if ($importance_data['عادي'] > 0): ?>
                                <span class="circle-count normal"><?php echo $importance_data['عادي']; ?></span>
                            <?php endif; ?>
                        </button>
                        <span class="filter-label">عادية</span>
                    </div>

                    <!-- زر قسم --><!--
                    <div class="circle-filter-container">
                        <button class="circle-filter-btn dept <?php echo $document_type === 'department' ? 'active' : ''; ?>" onclick="filterByDepartment()" title="مستندات القسم">
                            <i class="fas fa-building"></i>
                            <?php if (($stats['department_docs'] ?? 0) > 0): ?>
                                <span class="circle-count dept"><?php echo $stats['department_docs']; ?></span>
                            <?php endif; ?>
                        </button>
                        <span class="filter-label">القسم</span>
                    </div> -->

                    <!-- زر تبديل الفلاتر --><!--
                    <div class="circle-filter-container">
                        <button id="toggleAdvancedFiltersBtn" class="circle-filter-btn normal">
                            <i class="fas fa-filter"></i>
                        </button>
                        <span id="toggleFiltersText">إظهار الفلاتر</span>
                    </div>-->

                    <!-- زر الأرشيف --><!--
                    <div class="circle-filter-container">
                        <button onclick="showArchiveModal()" class="circle-filter-btn arch">
                            <i class="fas fa-archive"></i>
                        </button>
                        <span class="filter-label">عرض الأرشيف</span>
                    </div>-->

                </div>
            </div>
        </div>
        <!--
        <div class="board-dashboard">
            <!-- إحصائيات سريعة --><!--
            <div class="stats-grid">
                <!-- بطاقة المستندات الصادرة --><!--
                <div class="stat-card outgoing">
                    <div class="stat-card-content">
                        <div class="stat-card-icon">
                            <i class="fas fa-paper-plane"></i>
                        </div>
                        <div class="stat-card-number">
                            <?php echo $stats['outgoing'] ?? 0; ?>
                        </div>
                        <div class="stat-card-title">
                            المستندات الصادرة
                        </div>
                        <div class="stat-card-description">
                            <i class="fas fa-user-check"></i>
                            من إنشائي
                        </div>
                    </div>
                </div>

                <!-- بطاقة المستندات الواردة --><!--
                <div class="stat-card incoming">
                    <div class="stat-card-content">
                        <div class="stat-card-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <div class="stat-card-number">
                            <?php echo $stats['incoming'] ?? 0; ?>
                        </div>
                        <div class="stat-card-title">
                            المستندات الواردة
                        </div>
                        <div class="stat-card-description">
                            <i class="fas fa-user-tag"></i>
                            موجهة لي
                        </div>
                    </div>
                </div>

                <!-- بطاقة مستندات القسم --><!--
                <div class="stat-card department">
                    <div class="stat-card-content">
                        <div class="stat-card-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="stat-card-number">
                            <?php echo $stats['department_docs'] ?? 0; ?>
                        </div>
                        <div class="stat-card-title">
                            مستندات القسم
                        </div>
                        <div class="stat-card-description">
                            <i class="fas fa-users"></i>
                            <?php echo htmlspecialchars($stats['department_name']); ?>
                        </div>
                    </div>
                </div>

                <!-- بطاقة المستندات المكتملة --><!--
                <div class="stat-card completed">
                    <div class="stat-card-content">
                        <div class="stat-card-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-card-number">
                            <?php echo $stats['completed'] ?? 0; ?>
                        </div>
                        <div class="stat-card-title">
                            المكتملة
                        </div>
                        <div class="stat-card-description">
                            <i class="fas fa-flag-checkered"></i>
                            تم إنهاؤها
                        </div>
                    </div>
                </div>

                <!-- بطاقة قيد الاستكمال --><!--
                <div class="stat-card completion_required">
                    <div class="stat-card-content">
                        <div class="stat-card-icon">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="stat-card-number">
                            <?php echo $stats['completion_required'] ?? 0; ?>
                        </div>
                        <div class="stat-card-title">
                            قيد الاستكمال
                        </div>
                        <div class="stat-card-description">
                            <i class="fas fa-edit"></i>
                            تحتاج إلى استكمال
                        </div>
                    </div>
                </div>

                <!-- بطاقة قيد الانتظار --><!--
                <div class="stat-card pending">
                    <div class="stat-card-content">
                        <div class="stat-card-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-card-number">
                            <?php echo $stats['pending'] ?? 0; ?>
                        </div>
                        <div class="stat-card-title">
                            قيد الانتظار
                        </div>
                        <div class="stat-card-description">
                            <i class="fas fa-hourglass-half"></i>
                            تحتاج إلى مراجعة
                        </div>
                    </div>
                </div>
            </div> 

            <!-- قسم الفلاتر المتقدمة -->
        <div id="advancedFiltersSection" class="advanced-filters-section">
            <div class="filters-header">
                <div>
                    <h3>
                        <i class="fas fa-filter"></i>
                        فلاتر متقدمة
                    </h3>
                    <div class="filters-subtitle">(تخصيص البحث)</div>
                </div>
                <button onclick="toggleAdvancedFilters()" class="close-filters-btn">
                    <i class="fas fa-times"></i> إغلاق
                </button>
            </div>

            <div class="filters-content">
                <form method="GET" id="filterForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label for="searchInput">بحث</label>
                            <input type="text" name="search" id="searchInput" class="form-control"
                                placeholder="عنوان، وصف، أو مرسل..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="filter-group">
                            <label for="documentType">نوع المستند</label>
                            <select name="document_type" id="documentType" class="form-control">
                                <option value="">جميع المستندات</option>
                                <option value="outgoing" <?php echo $document_type === 'outgoing' ? 'selected' : ''; ?>>
                                    الصادرة (مني)</option>
                                <option value="incoming" <?php echo $document_type === 'incoming' ? 'selected' : ''; ?>>
                                    الواردة (لي)</option>
                                <option value="department" <?php echo $document_type === 'department' ? 'selected' : ''; ?>>مستندات القسم</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="statusSelect">الحالة</label>
                            <select name="status" id="statusSelect" class="form-control">
                                <option value="">جميع الحالات</option>
                                <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>مسودة</option>
                                <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>
                                    قيد المراجعة</option>
                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>قيد
                                    الانتظار</option>
                                <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>مكتملة
                                </option>
                                <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>مرفوضة
                                </option>
                                <option value="partially_signed" <?php echo $status === 'partially_signed' ? 'selected' : ''; ?>>موقع جزئياً</option>
                                <option value="completion_required" <?php echo $status === 'completion_required' ? 'selected' : ''; ?>>مطلوب استكمال</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="prioritySelect">الأولوية</label>
                            <select name="priority" id="prioritySelect" class="form-control">
                                <option value="">جميع الأولويات</option>
                                <option value="normal" <?php echo $priority === 'normal' ? 'selected' : ''; ?>>عادي
                                </option>
                                <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>عاجل</option>
                                <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>سري
                                </option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="dateFrom">من تاريخ</label>
                            <input type="date" name="date_from" id="dateFrom" class="form-control"
                                value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>

                        <div class="filter-group">
                            <label for="dateTo">إلى تاريخ</label>
                            <input type="date" name="date_to" id="dateTo" class="form-control"
                                value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                    </div>

                    <div class="filters-actions">
                        <button type="submit" class="filter-btn apply">
                            <i class="fas fa-search"></i> تطبيق الفلاتر
                        </button>
                        <button type="button" onclick="resetFilters()" class="filter-btn reset">
                            <i class="fas fa-redo"></i> إعادة التعيين
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- أزرار تبديل طريقة العرض -->
        <div class="view-toggle" style="margin-top: 20px; text-align: center;">
            <button class="view-toggle-btn <?php echo $viewMode == 'list' ? 'active' : ''; ?>"
                onclick="changeViewMode('list')">
                <i class="fas fa-list"></i>
                قائمة
            </button>
            <button class="view-toggle-btn <?php echo $viewMode == 'cards' ? 'active' : ''; ?>"
                onclick="changeViewMode('cards')">
                <i class="fas fa-th-large"></i>
                بطاقات
            </button>
        </div>

        <?php if (empty($documents)): ?>
            <!--  <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h4>لا توجد مستندات</h4>
                    <p>ابدأ بإنشاء مستند جديد أو انتظر حتى يوجه لك المستخدمون مستندات</p>
                    <a href="../documents/document_upload.php?for=department_manager&return_to=department_manager_dashboard" class="btn" style="background: linear-gradient(135deg, #2ecc71, #27ae60); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
                        <i class="fas fa-plus"></i> إنشاء مستند جديد
                    </a>
                </div>-->
        <?php else: ?>

            <!-- عرض البطاقات -->
            <?php if ($viewMode == 'cards'): ?>
                <div class="documents-grid">
                    <?php foreach ($documents as $index => $doc):
                        $is_creator = ($doc['created_by'] == $user_id);
                        $is_assigned = ($doc['current_holder_id'] == $user_id);
                        $user_status = $doc['user_status'] ?? 'pending';
                        $is_department = ($doc['department_name'] == $stats['department_name']);
                        $doc_type = ($doc['created_by'] == $user_id) ? 'صادر' : ($is_department ? 'قسم' : 'وارد');
                        ?>
                        <div class="document-card">
                            <!-- رقعة نوع المستند -->
                            <div
                                class="type-ribbon <?php echo $doc_type == 'صادر' ? 'outgoing' : ($doc_type == 'قسم' ? 'department' : 'incoming'); ?>">
                                <?php echo $doc_type; ?>
                            </div>

                            <div class="document-header">
                                <div class="document-icon">
                                    <?php
                                    if ($doc['priority'] == 'urgent') {
                                        echo '🔒';
                                    } elseif ($doc['priority'] == 'high') {
                                        echo '🔥';
                                    } else {
                                        echo '📄';
                                    }
                                    ?>
                                </div>
                                <h3><?php echo htmlspecialchars($doc['title']); ?></h3>
                            </div>

                            <div class="document-body">
                                <div class="document-info">
                                    <!-- القسم -->
                                    <div class="info-item">
                                        <span class="info-label">القسم:</span>
                                        <span class="info-value">
                                            <?php echo htmlspecialchars($doc['department_name'] ?: 'غير معين'); ?>
                                        </span>
                                    </div>

                                    <!-- المرسل -->
                                   <!-- <div class="info-item">
                                        <span class="info-label">المرسل:</span>
                                        <span class="info-value">
                                            <?php echo htmlspecialchars($doc['creator_name']); ?>
                                            <?php if ($is_creator): ?>
                                                <span style="color: #164a40; font-size: 0.8rem;">(أنت)</span>
                                            <?php endif; ?>
                                        </span>
                                    </div> -->

                                    <!-- التاريخ -->
                                    <div class="info-item">
                                        <span class="info-label">التاريخ:</span>
                                        <span class="info-value">
                                            <?php echo date('Y-m-d', strtotime($doc['created_at'])); ?>
                                        </span>
                                    </div>

                                    <!-- حالتي -->
                                  <!--  <div class="info-item">
                                        <span class="info-label">حالتي:</span>
                                        <span class="info-value">
                                            <?php
                                            $status_labels = [
                                                'draft' => 'مسودة',
                                                'pending' => 'انتظار',
                                                'completion_required' => 'استكمال',
                                                'partially_signed' => 'موقع جزئياً',
                                                'partially_completed' => 'مكتمل جزئياً',
                                                'completed' => 'مكتمل',
                                                'responded' => 'تم الرد',
                                                'approved' => 'موافق',
                                                'rejected' => 'مرفوض'
                                            ];
                                            $status_label = $status_labels[$user_status] ?? $user_status;
                                            ?>
                                            <span class="status-badge status-<?php echo $user_status; ?>">
                                                <?php echo $status_label; ?>
                                            </span>
                                            <?php if ($is_assigned): ?>
                                                <span style="color: #e74c3c; font-size: 0.8rem;">(معك)</span>
                                            <?php endif; ?>
                                        </span>
                                    </div> -->

                                    <!-- الحالة العامة -->
                                  <!--  <div class="info-item">
                                        <span class="info-label">الحالة:</span>
                                        <span class="info-value">
                                            <?php
                                            $status_config = [
                                                'draft' => ['label' => 'مسودة', 'class' => 'pending'],
                                                'under_review' => ['label' => 'قيد المراجعة', 'class' => 'under_review'],
                                                'pending' => ['label' => 'قيد الانتظار', 'class' => 'pending'],
                                                'completed' => ['label' => 'مكتملة', 'class' => 'completed'],
                                                'rejected' => ['label' => 'مرفوض', 'class' => 'rejected'],
                                                'partially_signed' => ['label' => 'موقع جزئياً', 'class' => 'partially_signed'],
                                                'completion_required' => ['label' => 'مطلوب استكمال', 'class' => 'completion_required']
                                            ];
                                            $status_cfg = $status_config[$doc['current_status']] ?? ['label' => $doc['current_status'], 'class' => 'pending'];
                                            ?>
                                            <span class="status-badge status-<?php echo $status_cfg['class']; ?>">
                                                <?php echo $status_cfg['label']; ?>
                                            </span>
                                        </span>
                                    </div> -->

                                    <!-- الأولوية -->
                                    <div class="info-item">
                                        <span class="info-label">الأولوية:</span>
                                        <span class="info-value">
                                            <?php
                                            $priority_config = [
                                                'normal' => ['label' => 'عادي', 'class' => 'normal'],
                                                'high' => ['label' => 'عاجل', 'class' => 'high'],
                                                'urgent' => ['label' => 'سري', 'class' => 'urgent']
                                            ];
                                            $priority_cfg = $priority_config[$doc['priority']] ?? ['label' => $doc['priority'], 'class' => 'normal'];
                                            ?>
                                            <span class="priority-badge priority-<?php echo $priority_cfg['class']; ?>">
                                                <?php echo $priority_cfg['label']; ?>
                                            </span>
                                        </span>
                                    </div>

                                    <!-- الموجه إليه -->
                                   <!-- <?php if (!empty($doc['assigned_to_name'])): ?>
                                        <div class="info-item">
                                            <span class="info-label">عند:</span>
                                            <span class="info-value">
                                                <?php echo htmlspecialchars($doc['assigned_to_name']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?> -->
                                </div>

                                <!-- وصف مختصر -->
                                <?php if ($doc['description']): ?>
                                    <div style="margin: 15px 0; padding: 10px; background: #f8f9fa; border-radius: 8px;">
                                        <p style="margin: 0; font-size: 0.85rem; color: #6c757d; line-height: 1.5;">
                                            <?php echo htmlspecialchars(mb_substr($doc['description'], 0, 100, 'UTF-8')); ?>
                                            <?php if (mb_strlen($doc['description'], 'UTF-8') > 100): ?>...<?php endif; ?>
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <!-- أزرار الإجراءات -->
                                <div class="document-actions">
                                    <button class="card-btn view"
                                        onclick="window.open('../documents/view_document.php?id=<?php echo $doc['id']; ?>', '_blank')">
                                        <i class="fas fa-eye"></i>
                                    </button>

                                  <!--  <button class="card-btn forward"
                                        onclick="showAddWorkflowStepModal(<?php echo $doc['id']; ?>, '<?php echo $doc['priority']; ?>')">
                                        <i class="fas fa-forward"></i>
                                    </button>

                                    <button
                                        onclick="window.open('track_document.php?id=<?php echo $doc['id']; ?>', 'trackWindow', 'width=1200,height=700,scrollbars=yes')"
                                        class="card-btn" style="background: #441088ff; color: white;">
                                        <i class="fas fa-project-diagram"></i>
                                    </button>

                                    <?php if ($is_creator): ?>
                                        <button onclick="deleteDocument(<?php echo $doc['id']; ?>)" class="card-btn"
                                            style="background: #e74c3c; color: white;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($doc['file_path'] && file_exists('../' . $doc['file_path'])): ?>
                                        <button class="card-btn download"
                                            onclick="window.location.href='../<?php echo $doc['file_path']; ?>'">
                                            <i class="fas fa-download"></i>
                                        </button>
                                    <?php endif; ?> -->
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- عرض الجدول -->
            <?php else: ?>
                <div class="documents-table-container">
                    <div class="documents-header">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; flex-wrap: nowrap; gap: 20px; width: 100%;">

                            <!-- العنصر الأول: العنوان على اليمين -->
                            <div style="flex-shrink: 0; min-width: 150px;">
                                <h3 style="margin: 0; white-space: nowrap;">
                                    <i class="fas fa-file-alt"></i>المستندات
                                </h3>
                            </div>

                            <!-- العنصر الثاني: حقل البحث في المنتصف -->
                            <div class="table-search-container" style="flex: 1; max-width: 400px; min-width: 200px;">
                                <div style="position: relative;">
                                    <input type="text" id="instantTableSearch" class="form-control search-on"
                                        placeholder="ابحث في الجدول عن أي شيء...">
                                    <div
                                        style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #95a5a6; pointer-events: none;">
                                        <i class="fas fa-search"></i>
                                    </div>
                                    <div class="searching-indicator" style="display: none;">
                                        <i class="fas fa-spinner"></i>
                                    </div>
                                </div>
                                <div id="tableSearchInfo"
                                    style="font-size: 0.75rem; color: #95a5a6; margin-top: 5px; text-align: center; display: none;">
                                    <span id="searchResultsCount">0</span> نتيجة
                                </div>
                            </div>

                            <!-- العنصر الثالث: معلومات التحديث والإجمالي على اليسار -->
                            <div style="flex-shrink: 0; min-width: 180px;">
                                <div class="thired-text">
                                    <div><i class="fas fa-sync-alt"></i> آخر تحديث: <?php echo date('H:i:s'); ?></div>
                                   
                                </div>
                            </div>

                        </div>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th>الرقم</th> <!-- تم التعديل: من # إلى الرقم -->
                                    <th>المستند</th>
                                    <!--  <th>القسم</th>
                                        <th>المرسل</th> -->
                                 <!--   <th>حالتي</th> -->
                                    <!-- <th>الحالة</th> -->
                                    <th>الأولوية</th>
                                    <th style="text-align: center;">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $index => $doc):
                                    $is_creator = ($doc['created_by'] == $user_id);
                                    $is_assigned = ($doc['current_holder_id'] == $user_id);
                                    $user_status = $doc['user_status'] ?? 'pending';
                                    $is_department = ($doc['department_name'] == $stats['department_name']);
                                    $doc_type = ($doc['created_by'] == $user_id) ? 'صادر' : ($is_department ? 'قسم' : 'وارد');
                                    ?>
                                    <tr>
                                       
                                        <td style="text-align: center; vertical-align: middle; padding: 10px 5px;">
                                            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 60px;">
                                                <?php if (!empty($doc['public_number']) || !empty($doc['private_number'])): ?>
                                                    <?php if (!empty($doc['public_number'])): ?>
                                                        <div style="font-weight: bold; font-size: 1.1rem; color: #164a40; margin-bottom: 3px; padding: 4px 8px; background: #d4edda; border-radius: 4px; border: 1px solid #c3e6cb; width: fit-content;">
                                                            <i class="fas fa-building" style="margin-left: 5px; font-size: 0.9rem;"></i>
                                                            <?php echo htmlspecialchars($doc['public_number']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($doc['private_number'])): ?>
                                                        <div style="font-size: 0.85rem; color: #6c757d; padding: 3px 6px; background: #f8f9fa; border-radius: 3px; border: 1px dashed #dee2e6; width: fit-content; margin-top: 2px;">
                                                            <i class="fas fa-user" style="margin-left: 3px; font-size: 0.8rem;"></i>
                                                            <?php echo htmlspecialchars($doc['private_number']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div style="font-weight: bold; font-size: 1.2rem; color: #95a5a6; font-style: italic;">
                                                        <?php echo $index + 1; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td class="document-info-cell">
                                            <a href="../documents/view_document.php?id=<?php echo $doc['id']; ?>"
                                                style="text-decoration: none;">
                                                <span class="document-title">
                                                    <?php echo htmlspecialchars($doc['title']); ?>
                                                </span>
                                             <?php if ($doc['description']): ?>
                                                        <span class="document-description">
                                                            <?php echo htmlspecialchars(mb_substr($doc['description'], 0, 80, 'UTF-8')); ?>
                                                            <?php if (mb_strlen($doc['description'], 'UTF-8') > 80): ?>...<?php endif; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="document-type <?php echo $doc_type == 'صادر' ? 'outgoing' : ($doc_type == 'قسم' ? 'department' : 'incoming'); ?>">
                                                        <i class="fas <?php echo $doc_type == 'صادر' ? 'fa-paper-plane' : ($doc_type == 'قسم' ? 'fa-building' : 'fa-inbox'); ?>"></i>
                                                        <?php echo $doc_type; ?>
                                                    </span> 
                                            </a>
                                        </td>
                                        <!--  <td class="department-cell">
                                                <?php echo htmlspecialchars($doc['department_name'] ?: 'غير معين'); ?>
                                            </td>
                                            <td class="sender-cell">
                                                <?php echo htmlspecialchars($doc['creator_name']); ?>
                                                <?php if ($is_creator): ?>
                                                    <span class="you-badge">أنت</span>
                                                <?php endif; ?>
                                                <span class="sender-date">
                                                    <?php echo date('Y-m-d', strtotime($doc['created_at'])); ?>
                                                </span>
                                            </td>-->

                                      <!--  <td>
                                            <div class="status-container">
                                                <?php
                                                $status_labels = [
                                                    'pending' => ['label' => 'انتظار', 'class' => 'pending'],
                                                    'completion_required' => ['label' => 'استكمال', 'class' => 'completion_required'],
                                                    'partially_signed' => ['label' => 'موقع جزئياً', 'class' => 'partially_signed'],
                                                    'partially_completed' => ['label' => 'مكتمل جزئياً ⭐', 'class' => 'partially_signed'],
                                                    'completed' => ['label' => 'مكتمل', 'class' => 'completed'],
                                                    'responded' => ['label' => 'تم الرد', 'class' => 'completed'],
                                                    'approved' => ['label' => 'موافق', 'class' => 'completed'],
                                                    'rejected' => ['label' => 'مرفوض', 'class' => 'rejected']
                                                ];
                                                $status_info = $status_labels[$user_status] ?? ['label' => $user_status, 'class' => 'pending'];
                                                ?>
                                                <span class="status-badge status-<?php echo $status_info['class']; ?>">
                                                    <?php echo $status_info['label']; ?>
                                                </span>

                                              <?php if ($is_assigned): ?>
                                                    <span class="user-status assigned">
                                                        <i class="fas fa-user-check"></i> معك حالياً
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td> -->

                                        <!--  <td>
                                                <div class="status-container">
                                                    <?php
                                                    $status_config = [
                                                        'draft' => ['label' => 'مسودة', 'class' => 'pending'],
                                                        'under_review' => ['label' => 'قيد المراجعة', 'class' => 'under_review'],
                                                        'pending' => ['label' => 'قيد الانتظار', 'class' => 'pending'],
                                                        'completed' => ['label' => 'مكتملة', 'class' => 'completed'],
                                                        'rejected' => ['label' => 'مرفوض', 'class' => 'rejected'],
                                                        'partially_signed' => ['label' => 'موقع جزئياً', 'class' => 'partially_signed'],
                                                        'completion_required' => ['label' => 'مطلوب استكمال', 'class' => 'completion_required']
                                                    ];
                                                    $status_cfg = $status_config[$doc['current_status']] ?? ['label' => $doc['current_status'], 'class' => 'pending'];
                                                    ?>
                                                    <span class="status-badge status-<?php echo $status_cfg['class']; ?>">
                                                        <?php echo $status_cfg['label']; ?>
                                                    </span>

                                                    <?php if (!empty($doc['assigned_to_name']) && $doc['current_status'] !== 'completed' && $doc['current_status'] !== 'rejected'): ?>
                                                        <div class="assigned-to-info">
                                                            <i class="fas fa-user-tag"></i>
                                                            عند:
                                                            <strong><?php echo htmlspecialchars($doc['assigned_to_name']); ?></strong>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>-->

                                        <td>
                                            <?php
                                            $priority_config = [
                                                'normal' => ['label' => 'عادي', 'class' => 'normal'],
                                                'high' => ['label' => 'عاجل', 'class' => 'high'],
                                                'urgent' => ['label' => 'سري', 'class' => 'urgent']
                                            ];
                                            $priority_cfg = $priority_config[$doc['priority']] ?? ['label' => $doc['priority'], 'class' => 'normal'];
                                            ?>
                                            <span class="priority-badge priority-<?php echo $priority_cfg['class']; ?>">
                                                <?php echo $priority_cfg['label']; ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="action-buttons">
                                                <a href="../documents/view_document.php?id=<?php echo $doc['id']; ?>"
                                                    class="employee-btn view" title="عرض المستند">
                                                    <i class="fas fa-eye"></i>
                                                </a>


                                                <!--
                                                    <button onclick="showAddWorkflowStepModal(<?php echo $doc['id']; ?>, '<?php echo $doc['priority']; ?>')" class="board-btn forward" title="إرسال / استكمال">
                                                        <i class="fas fa-forward"></i>
                                                    </button>

                                                    <button onclick="openTrackPopup(<?php echo $doc['id']; ?>)" class="board-btn track" title="تتبع مسار المستند">
                                                        <i class="fas fa-project-diagram"></i>
                                                    </button>

                                                    <?php if ($is_creator): ?>
                                                        <button onclick="deleteDocument(<?php echo $doc['id']; ?>)" class="board-btn" style="background: #e74c3c; color: white;" title="حذف">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($doc['file_path'] && file_exists('../' . $doc['file_path'])): ?>
                                                        <a href="../<?php echo $doc['file_path']; ?>" download class="board-btn download" title="تحميل الملف">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    <?php endif; ?> -->
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- نافذة منبثقة لإضافة خطوات توقيع جديدة -->
        <div id="workflowStepModal" class="modal-overlay" style="display:none;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3><i class="fas fa-user-plus"></i> إستكمال / تعديل أهمية</h3>
                    <button type="button" onclick="closeWorkflowStepModal()"
                        style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">
                        &times;
                    </button>
                </div>
                <div class="modal-body">
                    <form id="addWorkflowStepForm" method="POST" action="../documents/add_workflow_step.php">
                        <input type="hidden" name="document_id" id="modalDocumentId">
                        <input type="hidden" name="update_priority" value="1">

                        <!-- قسم تعديل أهمية المستند -->
                        <div style="margin-bottom: 20px;" id="prioritySection">
                            <label class="form-label">
                                <i class="fas fa-exclamation-circle"></i> تعديل أهمية المستند
                                <span style="color: #e74c3c;">*</span>
                            </label>
                            <select name="priority" class="form-control" required id="prioritySelect">
                                <option value="normal">عادي</option>
                                <option value="high">عاجل</option>
                                <option value="urgent">سري</option>
                            </select>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label class="form-label">
                                المستخدم المستهدف <span style="color: #e74c3c;">*</span>
                            </label>
                            <select name="assigned_to" class="form-control" required id="assignedToSelect">
                                <option value="">اختر المستخدم</option>
                                <!-- جميع المستخدمين في النظام -->
                                <?php
                                $current_role = '';
                                foreach ($all_users as $user):
                                    if ($user['role_name'] != $current_role):
                                        if ($current_role != '')
                                            echo '</optgroup>';
                                        echo '<optgroup label="' . htmlspecialchars($user['role_name']) . '">';
                                        $current_role = $user['role_name'];
                                    endif;
                                    ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php
                                        echo htmlspecialchars($user['full_name']);
                                        if ($user['department_name']):
                                            echo ' - ' . htmlspecialchars($user['department_name']);
                                        endif;
                                        ?>
                                    </option>
                                <?php endforeach;
                                if ($current_role != '')
                                    echo '</optgroup>';
                                ?>
                            </select>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label class="form-label">
                                نوع الخطوة <span style="color: #e74c3c;">*</span>
                            </label>
                            <select name="step_type" class="form-control" required id="stepTypeSelect"
                                onchange="toggleStepFields()">
                                <option value="signature">استكمال</option>
                                <option value="approve">موافقة</option>
                                <option value="reject">رفض</option>
                            </select>
                        </div>

                        <div style="margin-bottom: 20px;" id="fieldsSection">
                            <label class="form-label">الحقول المطلوبة</label>
                            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                    <input type="checkbox" name="fields[]" value="signature" style="cursor: pointer;">
                                    توقيع
                                </label>
                                <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                    <input type="checkbox" name="fields[]" value="date" style="cursor: pointer;">
                                    تاريخ
                                </label>
                                <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                    <input type="checkbox" name="fields[]" value="note" style="cursor: pointer;">
                                    ملاحظة
                                </label>
                                <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                    <input type="checkbox" name="fields[]" value="image" style="cursor: pointer;">
                                    صورة
                                </label>
                            </div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label class="form-label">ملاحظة منك (صاحب الطلب)</label>
                            <textarea name="creator_note" class="form-control" rows="3"
                                placeholder="اكتب الملاحظة هنا... (ستظهر للمستخدم المستهدف)"></textarea>
                        </div>

                        <div class="modal-footer">
                            <button type="button" onclick="closeWorkflowStepModal()" class="btn btn-secondary">
                                <i class="fas fa-times"></i> إلغاء
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> إرسال
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- نافذة منبثقة لتتبع المسار -->
        <div id="trackPopup" class="popup-overlay" style="display:none;">
            <div class="popup-content" style="width: 90%; max-width: 1200px; height: 90vh;">
                <div class="popup-header">
                    <h3><i class="fas fa-project-diagram"></i> تتبع مسار المستند</h3>
                    <button onclick="closeTrackPopup()" class="close-btn">&times;</button>
                </div>
                <div class="popup-body">
                    <iframe id="trackIframe" style="width:100%; height:100%; border:none;"></iframe>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- Toast Notifications Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        // نفس دوال JavaScript المستخدمة في Board Dashboard
        let currentDocumentId = null;
        let currentAction = null;
        let currentTargetUserId = null;

        function resetFilters() {
            window.location.href = 'department_manager_dashboard.php';
        }

        function filterByImportance(importance) {
            const url = new URL(window.location.href);

            if (importance === '') {
                url.searchParams.delete('importance');
                url.searchParams.delete('priority');
            } else {
                url.searchParams.set('importance', importance);

                if (importance === 'سري') {
                    url.searchParams.set('priority', 'urgent');
                } else if (importance === 'عاجل') {
                    url.searchParams.set('priority', 'high');
                } else if (importance === 'عادي') {
                    url.searchParams.set('priority', 'normal');
                }
            }

            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }

        function filterByDepartment() {
            const url = new URL(window.location.href);
            url.searchParams.set('document_type', 'department');
            url.searchParams.delete('importance');
            url.searchParams.delete('priority');
            window.location.href = url.toString();
        }

        function toggleAdvancedFilters() {
            const filtersSection = document.getElementById('advancedFiltersSection');
            const toggleBtn = document.getElementById('toggleAdvancedFiltersBtn');
            const toggleText = document.getElementById('toggleFiltersText');

            if (filtersSection.style.display === 'none' || filtersSection.style.display === '') {
                filtersSection.style.display = 'block';
                filtersSection.style.animation = 'fadeIn 0.3s ease';
                toggleText.textContent = 'إخفاء الفلاتر';
                localStorage.setItem('advancedFiltersVisible', 'true');
            } else {
                filtersSection.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => {
                    filtersSection.style.display = 'none';
                }, 250);
                toggleText.textContent = 'إظهار الفلاتر';
                localStorage.setItem('advancedFiltersVisible', 'false');
            }
        }

        function changeViewMode(mode) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', mode);
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }

        function showAddWorkflowStepModal(documentId, currentPriority) {
            document.getElementById('modalDocumentId').value = documentId;
            document.getElementById('workflowStepModal').style.display = 'flex';

            if (currentPriority) {
                const prioritySelect = document.getElementById('prioritySelect');
                if (prioritySelect) {
                    prioritySelect.value = currentPriority;
                }
            }

            document.getElementById('stepTypeSelect').value = 'signature';
            document.getElementById('assignedToSelect').value = '';
            document.querySelector('textarea[name="creator_note"]').value = '';
            toggleStepFields();
        }

        function closeWorkflowStepModal() {
            document.getElementById('workflowStepModal').style.display = 'none';
        }

        function toggleStepFields() {
            const stepType = document.getElementById('stepTypeSelect').value;
            const fieldsSection = document.getElementById('fieldsSection');
            const prioritySection = document.getElementById('prioritySection');

            if (stepType === 'approve' || stepType === 'reject') {
                if (fieldsSection) fieldsSection.style.display = 'none';
                if (prioritySection) prioritySection.style.display = 'none';
                document.querySelectorAll('input[name="fields[]"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
            } else {
                if (fieldsSection) fieldsSection.style.display = 'block';
                if (prioritySection) prioritySection.style.display = 'block';
                document.querySelectorAll('input[name="fields[]"]').forEach(checkbox => {
                    if (checkbox.value !== 'image') {
                        checkbox.checked = true;
                    }
                });
            }
        }

        function openTrackPopup(documentId) {
            document.getElementById('trackIframe').src = 'track_document.php?id=' + documentId;
            document.getElementById('trackPopup').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeTrackPopup() {
            document.getElementById('trackPopup').style.display = 'none';
            document.getElementById('trackIframe').src = '';
            document.body.style.overflow = 'auto';
        }

        function deleteDocument(documentId) {
            const modal = document.createElement('div');
            modal.innerHTML = `
                <div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center;">
                    <div style="background:white; padding:20px; border-radius:10px; width:300px; text-align:center; box-shadow:0 5px 15px rgba(0,0,0,0.2);">
                        <h4 style="color:#e74c3c; margin-bottom:15px;"><i class="fas fa-exclamation-triangle"></i> تأكيد الحذف</h4>
                        <p style="font-weight:bold; color:#e74c3c; font-size:0.9rem; margin-bottom:20px;">⚠️ هذا الإجراء لا يمكن التراجع عنه</p>
                        <div style="display:flex; justify-content:center; gap:10px;">
                            <button onclick="cancelDelete()" style="background:#95a5a6; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer; flex:1;">
                                <i class="fas fa-times"></i> إلغاء
                            </button>
                            <button onclick="proceedDelete(${documentId})" style="background:#e74c3c; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer; flex:1;">
                                <i class="fas fa-trash"></i> حذف
                            </button>
                        </div>
                    </div>
                </div>
            `;
            modal.id = 'deleteModal';
            document.body.appendChild(modal);
        }

        function cancelDelete() {
            const modal = document.getElementById('deleteModal');
            if (modal) modal.remove();
        }

        function proceedDelete(documentId) {
            window.location.href = 'delete_document.php?document_id=' + documentId;
        }

        function showToast(title, message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i>
                </div>
                <div class="toast-content">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.classList.remove('show');
                    setTimeout(() => {
                        if (toast.parentNode) {
                            container.removeChild(toast);
                        }
                    }, 400);
                }
            }, 5000);
        }

        function openDocumentInDashboard(documentId) {
            const iframe = document.createElement('iframe');
            iframe.id = 'dashboardDocumentViewer';
            iframe.src = `../documents/view_document.php?id=${documentId}`;
            iframe.style.cssText = `
                position: fixed;
                top: 150px;
                left: 50%;
                transform: translateX(-50%);
                width: 90%;
                max-width: 1200px;
                height: 80vh;
                background: white;
                border: none;
                border-radius: 10px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                z-index: 99999;
            `;

            const closeBtn = document.createElement('button');
            closeBtn.innerHTML = '<i class="fas fa-times"></i>';
            closeBtn.style.cssText = `
                position: fixed;
                top: 155px;
                left: 50%;
                transform: translateX(-50%);
                margin-left: 43%;
                background: #e74c3c;
                color: white;
                border: none;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                font-size: 18px;
                cursor: pointer;
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
            `;
            closeBtn.onclick = function () {
                const iframe = document.getElementById('dashboardDocumentViewer');
                const overlay = document.getElementById('dashboardDocumentOverlay');
                const btn = document.querySelector('button[onclick*="closeDocumentViewer"]');

                if (iframe) iframe.remove();
                if (overlay) overlay.remove();
                if (btn) btn.remove();
            };

            const overlay = document.createElement('div');
            overlay.id = 'dashboardDocumentOverlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                z-index: 99998;
                backdrop-filter: blur(5px);
            `;
            overlay.onclick = closeBtn.onclick;

            document.body.appendChild(overlay);
            document.body.appendChild(closeBtn);
            document.body.appendChild(iframe);
        }

        // تحديث الصفحة كل 3 دقائق
        setTimeout(function () {
            window.location.reload();
        }, 180000);

        // تحميل الحالة عند فتح الصفحة
        document.addEventListener('DOMContentLoaded', function () {
            const filtersVisible = localStorage.getItem('advancedFiltersVisible');
            const filtersSection = document.getElementById('advancedFiltersSection');
            if (filtersVisible === 'true' && filtersSection) {
                setTimeout(() => {
                    if (filtersSection.style.display === 'none') {
                        toggleAdvancedFilters();
                    }
                }, 500);
            }

            // إغلاق النوافذ
            window.onclick = function (event) {
                const modal = document.getElementById('workflowStepModal');
                if (event.target === modal) {
                    closeWorkflowStepModal();
                }
            };

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeTrackPopup();
                    const deleteModal = document.getElementById('deleteModal');
                    if (deleteModal) {
                        cancelDelete();
                    }
                }
            });
        });
    </script>
</body>

</html>