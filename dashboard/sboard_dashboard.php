<?php

/**
 * لوحة تحكم الديوان (board) - نظام التوقيع الإلكتروني
 * النسخة المعدلة: إظهار جميع المستندات وتعديل نظام الأرشفة
 */
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';
$pageTitle = 'لوحة التحكم';
// التحقق من أن المستخدم مسجل دخول وله دور board
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'sub_board') {
    header("Location: ../login.php");
    exit();
}

$db = getDB();
$_SESSION['role'] = 'sub_board';
$user_id = $_SESSION['user_id'];
$site = $_SESSION['site_name'] ?? null;

// جلب عدد الإشعارات غير المقروءة للديوان
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

// إعداد الترقيم (Pagination)
$records_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1)
    $page = 1;
$offset = ($page - 1) * $records_per_page;

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
    // التحقق من أن المستند موجود ويتبع الديوان
    $check_query = "
        SELECT d.id 
        FROM documents d
        LEFT JOIN document_workflow dw ON d.id = dw.document_id
        WHERE d.id = :doc_id AND (d.created_by = :user_id OR d.current_holder_id = :user_id2 OR dw.to_user_id = :user_id3)
    ";

    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute([
        ':doc_id' => $open_document_id,
        ':user_id' => $user_id,
        ':user_id2' => $user_id,
        ':user_id3' => $user_id
    ]);

    if ($check_stmt->fetch()) {
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

// إزالة شرط استبعاد المستندات المؤرشفة (نعرض جميع المستندات النشطة)
$where_conditions[] = "d.id NOT IN (SELECT document_id FROM user_archives WHERE user_id = :current_user)";
$params[':current_user'] = $user_id;

// إزالة شرط استبعاد المستندات المرفوضة أو الموافق عليها - الآن نعرض جميع المستندات

// تحديد نوع المستند
if ($document_type === 'incoming') {
    // المستندات الموجهة للديوان فقط
    $where_conditions[] = "(d.current_holder_id = :user_id OR dw.to_user_id = :user_id2) AND d.created_by != :user_id3";
    $params[':user_id'] = $user_id;
    $params[':user_id2'] = $user_id;
    $params[':user_id3'] = $user_id;
} elseif ($document_type === 'outgoing') {
    // المستندات التي أنشأها الديوان فقط
    $where_conditions[] = "d.created_by = :user_id";
    $params[':user_id'] = $user_id;
} else {
    // جميع المستندات المتعلقة بالديوان (التي أنشأها أو الموجهة إليه)
    $where_conditions[] = "(d.created_by = :user_id OR d.current_holder_id = :user_id2 OR dw.to_user_id = :user_id3)";
    $params[':user_id'] = $user_id;
    $params[':user_id2'] = $user_id;
    $params[':user_id3'] = $user_id;
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

// بناء جملة WHERE
$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// جلب إحصائيات الأهمية للمستندات المتعلقة بالديوان (إزالة شرط الموافق عليها أو المرفوضة)
$importance_stats_query = "
    SELECT 
        COALESCE(d.priority, 'normal') as priority,
        COUNT(DISTINCT d.id) as count
    FROM documents d
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    WHERE d.archived = 0
    AND (d.created_by = :user_id OR d.current_holder_id = :user_id2 OR dw.to_user_id = :user_id3)
    GROUP BY d.priority
";
$importance_stats_params = [
    ':user_id' => $user_id,
    ':user_id2' => $user_id,
    ':user_id3' => $user_id
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

// حساب إجمالي المستندات للزر "الكل" (إزالة شرط الموافق عليها أو المرفوضة)
$total_all_query = "
    SELECT COUNT(DISTINCT d.id) as total 
    FROM documents d
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    WHERE d.archived = 0
    AND (d.created_by = :user_id OR d.current_holder_id = :user_id2 OR dw.to_user_id = :user_id3)
";

$total_all_stmt = $db->prepare($total_all_query);
$total_all_stmt->execute([
    ':user_id' => $user_id,
    ':user_id2' => $user_id,
    ':user_id3' => $user_id
]);
$total_all = $total_all_stmt->fetch(PDO::FETCH_ASSOC);
$importance_data['all'] = $total_all['total'] ?? 0;

// تعبئة إحصائيات الأهمية
foreach ($importance_stats as $stat) {
    if ($stat['priority'] === 'urgent') {
        $importance_data['سري'] = $stat['count'];
    } elseif ($stat['priority'] === 'high') {
        $importance_data['عاجل'] = $stat['count'];
    } elseif ($stat['priority'] === 'normal' || $stat['priority'] === 'medium' || $stat['priority'] === 'low') {
        $importance_data['عادي'] += $stat['count'];
    }
}

// جلب جميع الموظفين في النظام
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

// جلب المستندات مع اسم المستخدم المستهدف والأرقام
// استبدال الاستعلام الحالي بهذا:
$query = "
   SELECT DISTINCT
        d.*,
        u.full_name as creator_name,
        u.email as creator_email,
        u.site as creator_site,
        u.title as job_title,
        u2.full_name as current_holder_name,
        dep.name as department_name,
        (SELECT COUNT(*) FROM signatures s WHERE s.document_id = d.id) as signatures_count,
        (SELECT GROUP_CONCAT(DISTINCT su.full_name) FROM signatures sig 
         LEFT JOIN users su ON sig.user_id = su.id WHERE sig.document_id = d.id) as signatories,
        (SELECT full_name FROM users WHERE id = (
            SELECT to_user_id 
            FROM document_workflow 
            WHERE document_id = d.id AND is_current_step = 1 
            LIMIT 1
        )) as assigned_to_name,
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
    {$where_sql}
    ORDER BY {$sort_by} {$sort_order}
    LIMIT {$records_per_page} OFFSET {$offset}
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$documents_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// حساب العدد الإجمالي للمستندات (للتقسيم)
$total_documents_query = "
    SELECT COUNT(DISTINCT d.id) as total
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN users u2 ON d.current_holder_id = u2.id
    LEFT JOIN departments dep ON u.department_id = dep.id
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    {$where_sql}
";

$total_stmt = $db->prepare($total_documents_query);
$total_stmt->execute($params);
$total_documents_result = $total_stmt->fetch(PDO::FETCH_ASSOC);
$total_documents = $total_documents_result['total'] ?? 0;
$total_pages = ceil($total_documents / $records_per_page);

// جلب حالة المستخدم لكل مستند (يمكن أن تكون approved, rejected, pending, etc.)
$documents = [];
foreach ($documents_raw as $doc) {
    $status_query = "SELECT status FROM document_user_status WHERE document_id = :doc_id AND user_id = :user_id";
    $status_stmt = $db->prepare($status_query);
    $status_stmt->execute([':doc_id' => $doc['id'], ':user_id' => $user_id]);
    $user_status = $status_stmt->fetch(PDO::FETCH_ASSOC);

    $doc['user_status'] = $user_status['status'] ?? 'pending';
    $documents[] = $doc;
}

// جلب إحصائيات الديوان (إزالة شرط الموافق عليها أو المرفوضة)
$stats_query = "
    SELECT 
        COUNT(DISTINCT d.id) as total_documents,
        SUM(CASE WHEN d.created_by = :user_id THEN 1 ELSE 0 END) as outgoing,
        SUM(CASE WHEN d.created_by != :user_id2 AND (d.current_holder_id = :user_id3 OR dw.to_user_id = :user_id4) THEN 1 ELSE 0 END) as incoming,
        SUM(CASE WHEN d.current_status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN d.current_status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN d.current_status = 'under_review' THEN 1 ELSE 0 END) as under_review,
        SUM(CASE WHEN d.current_status = 'completion_required' THEN 1 ELSE 0 END) as completion_required,
        SUM(CASE WHEN d.priority = 'urgent' THEN 1 ELSE 0 END) as urgent_docs,
        SUM(CASE WHEN d.current_status = 'partially_signed' THEN 1 ELSE 0 END) as partially_signed,
        SUM(CASE WHEN d.current_status = 'draft' THEN 1 ELSE 0 END) as draft,
        SUM(CASE WHEN d.current_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN d.current_status = 'approved' THEN 1 ELSE 0 END) as approved
    FROM documents d
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    WHERE d.archived = 0
    AND (d.created_by = :user_id5 OR d.current_holder_id = :user_id6 OR dw.to_user_id = :user_id7)
";

$stats_params = [
    ':user_id' => $user_id,
    ':user_id2' => $user_id,
    ':user_id3' => $user_id,
    ':user_id4' => $user_id,
    ':user_id5' => $user_id,
    ':user_id6' => $user_id,
    ':user_id7' => $user_id
];

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// جلب عدد المستندات المؤرشفة للمستخدم
$archived_count_query = "SELECT COUNT(*) as total FROM user_archives WHERE user_id = :user_id";
$archived_count_stmt = $db->prepare($archived_count_query);
$archived_count_stmt->execute([':user_id' => $user_id]);
$archived_count = $archived_count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// جلب إحصائيات إضافية للديوان
$additional_stats = $db->prepare("
    SELECT 
        COUNT(DISTINCT u.id) as total_users,
        (SELECT COUNT(*) FROM departments) as total_departments
    FROM users u
    WHERE u.is_active = 1
");
$additional_stats->execute();
$additional_stats_data = $additional_stats->fetch(PDO::FETCH_ASSOC);

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

// دمج الإحصائيات
$stats['total_users'] = $additional_stats_data['total_users'] ?? 0;
$stats['total_departments'] = $additional_stats_data['total_departments'] ?? 0;

// تحديد مسار المجلدات حسب الأولوية
$archive_base_path = '../uploads/archive/';
$archive_folders = [
    'normal' => $archive_base_path . 'normal/',
    'urgent' => $archive_base_path . 'urgent/',
    'secret' => $archive_base_path . 'secret/'
];

// التأكد من وجود المجلدات
foreach ($archive_folders as $folder) {
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/dashboard.css">

</head>

<body>

    <?php
    // إنشاء رأس خاص بالديوان
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

                    <!-- زر إضافة مستند -->
                    <div class="circle-filter-container">
                        <a href="../documents/document_upload.php?for=sboard&return_to=sboard_dashboard" target="_self"
                            class="circle-filter-btn add" style="
text-decoration: none;">
                            <i class="fas fa-plus"></i>
                        </a>
                        <span class="filter-label">جديد</span>
                    </div>

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

                    <!-- زر تبديل الفلاتر -->
                    <div class="circle-filter-container">
                        <button id="toggleAdvancedFiltersBtn" class="circle-filter-btn normal">
                            <i class="fas fa-sliders"></i>
                        </button>
                        <span id="toggleFiltersText">بحث متقدم</span>
                    </div>

                 <!-- زر الأرشيف -->
                    <div class="circle-filter-container">
                        <a href="board_archive.php" class="circle-filter-btn arch" style="text-decoration: none; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-archive"></i>
                            <?php if ($archived_count > 0): ?>
                                <span class="circle-count"><?php echo $archived_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <span class="filter-label">عرض الأرشيف</span>
                    </div>

                </div>
            </div>
        </div>

        <div class="board-dashboard">
            <!-- إحصائيات سريعة -->
            <div class="stats-grid">
                <!-- بطاقة المستندات الصادرة -->
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
                            من إنشاء الديوان
                        </div>
                    </div>
                </div>

                <!-- بطاقة المستندات الواردة -->
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
                            موجهة للديوان
                        </div>
                    </div>
                </div>

                <!-- بطاقة المستندات المكتملة -->
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
                            تم إنهاؤها بنجاح
                        </div>
                    </div>
                </div>

                <!-- بطاقة قيد الاستكمال -->
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

                <!-- بطاقة قيد الانتظار -->
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
                                    placeholder="عنوان، وصف، أو مرسل..."
                                    value="<?php echo htmlspecialchars($search); ?>">
                            </div>

                            <div class="filter-group">
                                <label for="documentType">نوع المستند</label>
                                <select name="document_type" id="documentType" class="form-control">
                                    <option value="">جميع المستندات المتعلقة بالديوان</option>
                                    <option value="outgoing" <?php echo $document_type === 'outgoing' ? 'selected' : ''; ?>>
                                        الصادرة (من الديوان)
                                    </option>
                                    <option value="incoming" <?php echo $document_type === 'incoming' ? 'selected' : ''; ?>>
                                        الواردة (للديوان)
                                    </option>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label for="statusSelect">الحالة</label>
                                <select name="status" id="statusSelect" class="form-control">
                                    <option value="">جميع الحالات</option>
                                    <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>مسودة
                                    </option>
                                    <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>
                                        قيد المراجعة</option>
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>قيد
                                        الانتظار</option>
                                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>
                                        مكتملة
                                    </option>
                                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>
                                        مرفوضة
                                    </option>
                                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>
                                        موافق عليها
                                    </option>
                                    <option value="partially_signed" <?php echo $status === 'partially_signed' ? 'selected' : ''; ?>>موقع جزئياً</option>
                                    <option value="partially_completed" <?php echo $status === 'partially_completed' ? 'selected' : ''; ?>>مكتمل جزئياً</option>
                                    <option value="completion_required" <?php echo $status === 'completion_required' ? 'selected' : ''; ?>>مطلوب استكمال</option>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label for="prioritySelect">الأولوية</label>
                                <select name="priority" id="prioritySelect" class="form-control">
                                    <option value="">جميع الأولويات</option>
                                    <option value="normal" <?php echo $priority === 'normal' ? 'selected' : ''; ?>>عادي
                                    </option>
                                    <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>عاجل
                                    </option>
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
                            <button type="button" onclick="exportResults()" class="filter-btn export">
                                <i class="fas fa-file-export"></i> تصدير النتائج
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
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h4>لا توجد مستندات</h4>
                    <p>ابدأ بإنشاء مستند جديد أو انتظر حتى يوجه لك المستخدمون مستندات</p>
                    <a href="../documents/document_upload.php?for=sboard&return_to=sboard_dashboard" class="btn"
                        style="background: linear-gradient(135deg, #2ecc71, #27ae60); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
                        <i class="fas fa-plus"></i> إنشاء مستند جديد
                    </a>
                </div>
            <?php else: ?>

                <!-- عرض البطاقات -->
                <?php if ($viewMode == 'cards'): ?>
                    <div class="documents-grid">
                        <?php foreach ($documents as $index => $doc):
                            $is_creator = ($doc['created_by'] == $user_id);
                            $is_assigned = ($doc['current_holder_id'] == $user_id);
                            $user_status = $doc['user_status'] ?? 'pending';
                            $doc_type = ($doc['created_by'] == $user_id) ? 'صادر' : 'وارد';
                        ?>
                            <div class="document-card">
                                <!-- رقعة نوع المستند -->
                                <div class="type-ribbon <?php echo $doc_type == 'صادر' ? 'outgoing' : 'incoming'; ?>">
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
                                        <!-- الأرقام -->
                                        <?php if (!empty($doc['public_number']) || !empty($doc['private_number'])): ?>
                                            <div class="info-item"
                                                style="grid-column: 1 / -1; background: #f8f9fa; padding: 8px; border-radius: 6px; margin-top: 5px;">
                                                <div style="display: flex; justify-content: space-between; align-items: center;">

                                                    <div
                                                        style="display: flex; flex-direction: column; align-items: flex-end; gap: 3px;">
                                                        <?php if (!empty($doc['public_number'])): ?>
                                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                                <span style="font-size: 0.8rem; color: #7f8c8d;"> الديوان العام:</span>
                                                                <span
                                                                    style="font-weight: bold; color: #164a40; background: #d4edda; padding: 2px 8px; border-radius: 12px; border: 1px solid #c3e6cb;">
                                                                    <?php echo htmlspecialchars($doc['public_number']); ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if (!empty($doc['private_number'])): ?>
                                                            <div style="display: flex; align-items: center; gap: 5px; margin-top: 2px;">
                                                                <span style="font-size: 0.8rem; color: #7f8c8d;"> رقم الطلب:</span>
                                                                <span
                                                                    style="color: #6c757d; background: #e9ecef; padding: 2px 8px; border-radius: 12px; border: 1px dashed #ced4da;">
                                                                    <?php echo htmlspecialchars($doc['private_number']); ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <!-- القسم -->
                                        <div class="info-item">
                                            <span class="info-label">المسمى الوظيفي:</span>
                                            <span class="info-value">
                                                <?php echo htmlspecialchars($doc['job_title'] ?: 'غير معين'); ?>
                                            </span>
                                        </div>

                                        <!-- المرسل -->
                                        <div class="info-item">
                                            <span class="info-label">المرسل:</span>
                                            <span class="info-value">
                                                <?php echo htmlspecialchars($doc['creator_name']); ?>
                                                <?php if ($is_creator): ?>
                                                    <span style="color: #164a40; font-size: 0.8rem;">(أنت)</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>

                                        <!-- التاريخ -->
                                        <div class="info-item">
                                            <span class="info-label">التاريخ:</span>
                                            <span class="info-value">
                                                <?php echo date('Y-m-d', strtotime($doc['created_at'])); ?>
                                            </span>
                                        </div>

                                        <!-- حالتي -->
                                        <div class="info-item">
                                            <span class="info-label">حالتي:</span>
                                            <span class="info-value">
                                                <?php
                                                $status_labels = [
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
                                        </div>

                                        <!-- الحالة العامة -->
                                        <div class="info-item">
                                            <span class="info-label">الحالة:</span>
                                            <span class="info-value">
                                                <?php
                                                $status_config = [
                                                    'draft' => ['label' => 'مسودة', 'class' => 'pending'],
                                                    'under_review' => ['label' => 'قيد المراجعة', 'class' => 'under_review'],
                                                    'pending' => ['label' => 'قيد الانتظار', 'class' => 'pending'],
                                                    'completed' => ['label' => 'مكتملة', 'class' => 'completed'],
                                                    'rejected' => ['label' => 'مرفوض', 'class' => 'rejected'],
                                                    'approved' => ['label' => 'موافق عليها', 'class' => 'approved'],
                                                    'partially_signed' => ['label' => 'موقع جزئياً', 'class' => 'partially_signed'],
                                                    'partially_completed' => ['label' => 'مكتمل جزئياً', 'class' => 'partially_completed'],
                                                    'completion_required' => ['label' => 'مطلوب استكمال', 'class' => 'completion_required']
                                                ];
                                                $status_cfg = $status_config[$doc['current_status']] ?? ['label' => $doc['current_status'], 'class' => 'pending'];
                                                ?>
                                                <span class="status-badge status-<?php echo $status_cfg['class']; ?>">
                                                    <?php echo $status_cfg['label']; ?>
                                                </span>
                                            </span>
                                        </div>

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
                                        <?php if (!empty($doc['assigned_to_name'])): ?>
                                            <div class="info-item">
                                                <span class="info-label">عند:</span>
                                                <span class="info-value">
                                                    <?php echo htmlspecialchars($doc['assigned_to_name']); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
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

                                        <button class="card-btn forward"
                                            onclick="showAddWorkflowStepModal(<?php echo $doc['id']; ?>, '<?php echo $doc['priority']; ?>')">
                                            <i class="fas fa-forward"></i>
                                        </button>

                                        <button
                                            onclick="window.open('track_document.php?id=<?php echo $doc['id']; ?>', 'trackWindow', 'width=1200,height=700,scrollbars=yes')"
                                            class="card-btn" style="background: #441088ff; color: white;">
                                            <i class="fas fa-project-diagram"></i>
                                        </button>

                                        <button onclick="deleteDocument(<?php echo $doc['id']; ?>)" class="card-btn"
                                            style="background: #e74c3c; color: white;">
                                            <i class="fas fa-trash"></i>
                                        </button>

                                        <button onclick="archiveBoardDocument(<?php echo $doc['id']; ?>, '<?php echo $doc['priority']; ?>', '<?php echo addslashes($doc['title']); ?>')"
                                            class="employee-btn"
                                            style="background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white;"
                                            title="أرشفة المستند">
                                            <i class="fas fa-archive"></i>
                                        </button>


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
                                        <div style="margin-top: 2px;"><i class="fas fa-layer-group"></i> إجمالي:
                                            <?php echo $total_documents; ?> مستند
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <div style="overflow-x: auto;">
                            <table class="documents-table" id="documentsTable">
                                <thead>
                                    <tr>
                                        <th>الرقم</th>
                                        <th>المستند</th>
                                        <th>القسم</th>
                                        <th>المرسل</th>
                                        <th>حالتي</th>
                                        <th>الحالة</th>
                                        <th>الأولوية</th>
                                        <th style="text-align: center;">الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($documents as $index => $doc):
                                        $is_creator = ($doc['created_by'] == $user_id);
                                        $is_assigned = ($doc['current_holder_id'] == $user_id);
                                        $user_status = $doc['user_status'] ?? 'pending';
                                        $doc_type = ($doc['created_by'] == $user_id) ? 'صادر' : 'وارد';
                                    ?>
                                        <tr class="document-row" data-searchable="<?php echo htmlspecialchars(json_encode([
                                                                                        'title' => $doc['title'],
                                                                                        'description' => $doc['description'] ?? '',
                                                                                        'creator_name' => $doc['creator_name'],
                                                                                        'job_title' => $doc['job_title'] ?? '',
                                                                                        'public_number' => $doc['public_number'] ?? '',
                                                                                        'private_number' => $doc['private_number'] ?? '',
                                                                                        'current_status' => $doc['current_status'],
                                                                                        'priority' => $doc['priority'],
                                                                                        'assigned_to_name' => $doc['assigned_to_name'] ?? '',
                                                                                        'created_at' => $doc['created_at']
                                                                                    ]), ENT_QUOTES, 'UTF-8'); ?>">
                                            <td style="text-align: center; vertical-align: middle; padding: 10px 5px;">
                                                <div
                                                    style="display: flex; flex-direction: column; align-items: right; justify-content: center; min-height: 60px;">
                                                    <?php if (!empty($doc['public_number']) || !empty($doc['private_number'])): ?>
                                                        <?php if (!empty($doc['public_number'])): ?>
                                                            <div
                                                                style="font-weight: bold; font-size: 1.1rem; color: #164a40; margin-bottom: 3px; padding: 4px 8px; background: #d4edda; border-radius: 4px; border: 1px solid #c3e6cb; width: fit-content;">
                                                                <i class="fas fa-building" style="margin-left: 5px; font-size: 0.9rem;"></i>
                                                                <?php echo htmlspecialchars($doc['public_number']); ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if (!empty($doc['private_number'])): ?>
                                                            <div
                                                                style="font-size: 0.85rem; color: #6c757d; padding: 3px 6px; background: #f8f9fa; border-radius: 3px; border: 1px dashed #dee2e6; width: fit-content; margin-top: 2px;">
                                                                <i class="fas fa-user" style="margin-left: 3px; font-size: 0.8rem;"></i>
                                                                <?php echo htmlspecialchars($doc['private_number']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div
                                                            style="font-weight: bold; font-size: 1.2rem; color: #95a5a6; font-style: italic;">
                                                            <?php echo $index + 1 + $offset; ?>
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
                                                    <span
                                                        class="document-type <?php echo $doc_type == 'صادر' ? 'outgoing' : 'incoming'; ?>">
                                                        <i
                                                            class="fas <?php echo $doc_type == 'صادر' ? 'fa-paper-plane' : 'fa-inbox'; ?>"></i>
                                                        <?php echo $doc_type; ?>
                                                    </span>
                                                </a>
                                            </td>

                                            <td class="department-cell">
                                                <?php echo htmlspecialchars($doc['creator_site'] ?: 'غير معين'); ?>
                                                <div class="assigned-to-info">
                                                    <strong><?php echo htmlspecialchars($doc['job_title'] ?: 'غير معين'); ?></strong>
                                                </div>
                                            </td>

                                            <td class="sender-cell">
                                                <?php echo htmlspecialchars($doc['creator_name']); ?>
                                                <?php if ($is_creator): ?>
                                                    <span class="you-badge">أنت</span>
                                                <?php endif; ?>
                                                <span class="sender-date">
                                                    <?php echo date('Y-m-d', strtotime($doc['created_at'])); ?>
                                                </span>
                                            </td>

                                            <td>
                                                <div class="status-container">
                                                    <?php
                                                    $status_labels = [
                                                        'draft' => ['label' => 'مسودة', 'class' => 'draft'],
                                                        'pending' => ['label' => 'انتظار', 'class' => 'pending'],
                                                        'completion_required' => ['label' => 'استكمال', 'class' => 'completion_required'],
                                                        'partially_signed' => ['label' => 'موقع جزئياً', 'class' => 'partially_signed'],
                                                        'partially_completed' => ['label' => 'مكتمل جزئياً', 'class' => 'partially_completed'],
                                                        'completed' => ['label' => 'مكتمل', 'class' => 'completed'],
                                                        'responded' => ['label' => 'تم الرد', 'class' => 'completed'],
                                                        'approved' => ['label' => 'موافق', 'class' => 'approved'],
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
                                            </td>

                                            <td>
                                                <div class="status-container">
                                                    <?php
                                                    $status_config = [
                                                        'draft' => ['label' => 'مسودة', 'class' => 'draft'],
                                                        'under_review' => ['label' => 'قيد المراجعة', 'class' => 'under_review'],
                                                        'pending' => ['label' => 'قيد الانتظار', 'class' => 'pending'],
                                                        'completed' => ['label' => 'مكتملة', 'class' => 'completed'],
                                                        'rejected' => ['label' => 'مرفوض', 'class' => 'rejected'],
                                                        'approved' => ['label' => 'موافق عليها', 'class' => 'approved'],
                                                        'partially_signed' => ['label' => 'موقع جزئياً', 'class' => 'partially_signed'],
                                                        'partially_completed' => ['label' => 'مكتمل جزئياً', 'class' => 'partially_completed'],
                                                        'completion_required' => ['label' => 'مطلوب استكمال', 'class' => 'completion_required']
                                                    ];
                                                    $status_cfg = $status_config[$doc['current_status']] ?? ['label' => $doc['current_status'], 'class' => 'pending'];
                                                    ?>
                                                    <span class="status-badge status-<?php echo $status_cfg['class']; ?>">
                                                        <?php echo $status_cfg['label']; ?>
                                                    </span>

                                                    <?php if (!empty($doc['assigned_to_name']) && $doc['current_status'] !== 'completed' && $doc['current_status'] !== 'rejected' && $doc['current_status'] !== 'approved'): ?>
                                                        <div class="assigned-to-info">
                                                            <i class="fas fa-user-tag"></i>
                                                            عند:
                                                            <strong><?php echo htmlspecialchars($doc['assigned_to_name']); ?></strong>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

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

                                                    <button
                                                        onclick="showAddWorkflowStepModal(<?php echo $doc['id']; ?>, '<?php echo $doc['priority']; ?>')"
                                                        class="employee-btn forward" title="إرسال / استكمال">
                                                        <i class="fas fa-forward"></i>
                                                    </button>

                                                    <button onclick="openTrackPopup(<?php echo $doc['id']; ?>)"
                                                        class="employee-btn track" title="تتبع مسار المستند">
                                                        <i class="fas fa-project-diagram"></i>
                                                    </button>

                                                    <button onclick="deleteDocument(<?php echo $doc['id']; ?>)" class="employee-btn"
                                                        style="background: #e74c3c; color: white;" title="حذف">
                                                        <i class="fas fa-trash"></i>
                                                    </button>


                                                    <button onclick="archiveBoardDocument(<?php echo $doc['id']; ?>, '<?php echo $doc['priority']; ?>', '<?php echo addslashes($doc['title']); ?>')"
                                                        class="employee-btn"
                                                        style="background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white;"
                                                        title="أرشفة المستند">
                                                        <i class="fas fa-archive"></i>
                                                    </button>

                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- قسم الترقيم (Pagination) -->
                        <div class="pagination-container">
                            <div class="pagination-info">
                                عرض <?php echo count($documents); ?> من أصل <?php echo $total_documents; ?> مستند | الصفحة
                                <?php echo $page; ?> من <?php echo $total_pages; ?>
                            </div>

                            <?php if ($total_pages > 1):
                                // دالة مساعدة لبناء رابط الترقيم مع الحفاظ على جميع المعلمات
                                function buildPaginationUrl($page_num)
                                {
                                    $params = $_GET;
                                    $params['page'] = $page_num;
                                    return http_build_query($params);
                                }
                            ?>
                                <ul class="pagination">
                                    <!-- زر الصفحة السابقة -->
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo buildPaginationUrl($page - 1); ?>"
                                            aria-label="السابق">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>

                                    <!-- عرض أرقام الصفحات -->
                                    <?php
                                    // حساب بداية ونهاية عرض الصفحات
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);

                                    // إذا كانت الصفحة الأولى ليست ضمن النطاق، عرض الرقم 1 و ...
                                    if ($start_page > 1) {
                                        echo '<li class="page-item"><a class="page-link" href="?' . buildPaginationUrl(1) . '">1</a></li>';
                                        if ($start_page > 2) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                    }

                                    // عرض أرقام الصفحات في النطاق
                                    for ($i = $start_page; $i <= $end_page; $i++) {
                                        $active_class = ($i == $page) ? 'active' : '';
                                        echo '<li class="page-item ' . $active_class . '">';
                                        echo '<a class="page-link" href="?' . buildPaginationUrl($i) . '">' . $i . '</a>';
                                        echo '</li>';
                                    }

                                    // إذا كانت الصفحة الأخيرة ليست ضمن النطاق، عرض ... و الرقم الأخير
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                        echo '<li class="page-item"><a class="page-link" href="?' . buildPaginationUrl($total_pages) . '">' . $total_pages . '</a></li>';
                                    }
                                    ?>

                                    <!-- زر الصفحة التالية -->
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo buildPaginationUrl($page + 1); ?>"
                                            aria-label="التالي">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- نافذة منبثقة لإضافة خطوات توقيع جديدة (استكمال) -->
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

                            <div style="margin-bottom: 20px;" id="prioritySection">
                                <label class="form-label">
                                    <i class="fas fa-exclamation-circle"></i> تعديل أهمية المستند (اختياري)
                                </label>
                                <select name="priority" class="form-control" id="prioritySelectModal">
                                    <option value="normal">عادي</option>
                                    <option value="high">عاجل</option>
                                    <option value="urgent">سري</option>
                                </select>
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label class="form-label">
                                    المستخدم المستهدف <span style="color: #e74c3c;">*</span>
                                </label>
                                <?php
                                // مصفوفة ترجمة الأدوار (نفس المصفوفة المستخدمة في pboard)
                                $role_translations = [
                                    'admin' => 'مدير النظام',
                                    'employee' => 'موظفين',
                                    'board' => 'ديوان عام',
                                    'section_manager' => 'مدراء الأقسام',
                                    'department_manager' => 'مدراء الدوائر',
                                    'private_board' => 'دواوين الأقسام',
                                    'sub_board' => 'دواوين العامة',
                                    'office_manager' => 'مدراء المكاتب',
                                    'deputy_ceo' => 'نائب المدير',
                                    'ceo' => 'المدير التنفيذي',
                                ];
                                ?>
                                <select name="assigned_to" class="form-control" required id="assignedToSelect">
                                    <option value="">اختر المستخدم</option>
                                    <?php
                                    $current_role = '';
                                    foreach ($all_users as $user):
                                        $translated_role = $role_translations[$user['role_name']] ?? $user['role_name'];

                                        if ($translated_role != $current_role):
                                            if ($current_role != '')
                                                echo '</optgroup>';
                                            echo '<optgroup label="' . htmlspecialchars($translated_role) . '">';
                                            $current_role = $translated_role;
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
                                        <input type="checkbox" name="fields[]" value="note" style="cursor: pointer;">
                                        ملاحظة
                                    </label>
                                </div>
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label class="form-label">ملاحظة منك (صاحب الطلب)</label>
                                <textarea name="creator_note" class="form-control" rows="3"
                                    placeholder="اكتب الملاحظة هنا... (ستظهر للمستخدم المستهدف)"></textarea>
                            </div>

                            <div class="modal-footer">
                                <button type="button" onclick="closeWorkflowStepModal()" class="btnx btn-secondary">
                                    <i class="fas fa-times"></i> إلغاء
                                </button>
                                <button type="submit" class="btnx btn-success">
                                    <i class="fas fa-paper-plane"></i> إرسال
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <!-- نافذة منبثقة لتتبع المسار -->
            <div id="trackPopup" class="popup-overlay" style="display:none;">
                <div class="popup-content">
                    <div class="popup-header">
                        <h3><i class="fas fa-project-diagram"></i> تتبع مسار المستند</h3>
                        <button onclick="closeTrackPopup()" class="close-btn">&times;</button>
                    </div>
                    <div class="popup-body">
                        <iframe id="trackIframe"></iframe>
                    </div>
                </div>
            </div>

            <!-- مودال تأكيد الأرشفة -->
            <div id="archiveConfirmModal" class="modal-overlay" style="display: none;">
                <div class="modal-content" style="max-width: 450px;">
                    <div class="modal-header" style="background: linear-gradient(135deg, #9b59b6, #8e44ad);">
                        <h3><i class="fas fa-archive"></i> تأكيد الأرشفة</h3>
                        <button type="button" onclick="closeArchiveConfirmModal()" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">&times;</button>
                    </div>
                    <div class="modal-body" style="padding: 25px; text-align: center;">
                        <i class="fas fa-question-circle" style="font-size: 4rem; color: #9b59b6; margin-bottom: 15px;"></i>
                        <p style="font-size: 1.1rem; margin-bottom: 25px; color: #34495e;">هل أنت متأكد من أرشفة هذا المستند؟</p>
                        <p style="font-size: 0.9rem; color: #7f8c8d; margin-bottom: 20px;" id="archiveDocumentTitle"></p>
                        <div style="display: flex; gap: 15px; justify-content: center;">
                            <button onclick="proceedArchive()" class="btnx" style="background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white; padding: 12px 30px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
                                <i class="fas fa-check"></i> تأكيد الأرشفة
                            </button>
                            <button onclick="closeArchiveConfirmModal()" class="btnx btn-secondary" style="background: #95a5a6; color: white; padding: 12px 30px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
                                <i class="fas fa-times"></i> إلغاء
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notifications Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script src="../assets/js/board_scr.js"></script>
</body>

</html>