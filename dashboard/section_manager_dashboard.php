<?php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';
$pageTitle = 'لوحة التحكم';

// التحقق من أن المستخدم مسجل دخول وله دور section_manager
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'section_manager') {
    header("Location: ../login.php");
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$department_id = $_SESSION['department_id'] ?? null;

// معالجة الموافقة أو الرفض إذا تم إرسال النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $document_id = $_POST['document_id'] ?? null;
    $reason = $_POST['reason'] ?? '';

    if ($document_id && ($action === 'approve' || $action === 'reject')) {
        try {
            $db->beginTransaction();

            // تحديث حالة المستند
            $new_status = 'completed';
            $stmt = $db->prepare("UPDATE documents SET current_status = ? WHERE id = ?");
            $stmt->execute([$new_status, $document_id]);

            // تحديث حالة المستخدم في document_user_status
            $user_status = ($action === 'approve') ? 'approved' : 'rejected';
            $action_required = 0;

            // التحقق إذا كان هناك سجل موجود
            $check_stmt = $db->prepare("SELECT id FROM document_user_status WHERE document_id = ? AND user_id = ?");
            $check_stmt->execute([$document_id, $user_id]);

            if ($check_stmt->rowCount() > 0) {
                // تحديث السجل الموجود
                $update_stmt = $db->prepare("
                    UPDATE document_user_status 
                    SET status = ?, action_required = ?, updated_at = NOW(), 
                        notes = CASE WHEN ? != '' THEN ? ELSE notes END
                    WHERE document_id = ? AND user_id = ?
                ");
                $update_stmt->execute([$user_status, $action_required, $reason, $reason, $document_id, $user_id]);
            } else {
                // إنشاء سجل جديد
                $insert_stmt = $db->prepare("
                    INSERT INTO document_user_status (document_id, user_id, status, action_required, created_at, updated_at, notes)
                    VALUES (?, ?, ?, ?, NOW(), NOW(), ?)
                ");
                $insert_stmt->execute([$document_id, $user_id, $user_status, $action_required, $reason]);
            }

            // تحديث حالة الموظف (صاحب المستند) في document_user_status
            $creator_stmt = $db->prepare("SELECT created_by FROM documents WHERE id = ?");
            $creator_stmt->execute([$document_id]);
            $creator_id = $creator_stmt->fetchColumn();

            if ($creator_id && $creator_id != $user_id) {
                $creator_status = ($action === 'approve') ? 'completed' : 'rejected';

                $check_creator_stmt = $db->prepare("SELECT id FROM document_user_status WHERE document_id = ? AND user_id = ?");
                $check_creator_stmt->execute([$document_id, $creator_id]);

                if ($check_creator_stmt->rowCount() > 0) {
                    $update_creator_stmt = $db->prepare("
                        UPDATE document_user_status 
                        SET status = ?, action_required = 0, updated_at = NOW()
                        WHERE document_id = ? AND user_id = ?
                    ");
                    $update_creator_stmt->execute([$creator_status, $document_id, $creator_id]);
                } else {
                    $insert_creator_stmt = $db->prepare("
                        INSERT INTO document_user_status (document_id, user_id, status, action_required, created_at, updated_at)
                        VALUES (?, ?, ?, 0, NOW(), NOW())
                    ");
                    $insert_creator_stmt->execute([$document_id, $creator_id, $creator_status]);
                }
            }

            // تحديث current_holder_id ليكون null
            $holder_stmt = $db->prepare("UPDATE documents SET current_holder_id = NULL WHERE id = ?");
            $holder_stmt->execute([$document_id]);

            // تحديث جميع خطوات الـ workflow
            $workflow_stmt = $db->prepare("UPDATE document_workflow SET is_current_step = 0 WHERE document_id = ?");
            $workflow_stmt->execute([$document_id]);

            // إضافة إشعار للموظف
            $notification_title = ($action === 'approve') ? 'تمت الموافقة على مستندك' : 'تم رفض مستندك';
            $notification_message = ($action === 'approve')
                ? 'تمت الموافقة على المستند الخاص بك من قبل رئيس القسم'
                : 'تم رفض المستند الخاص بك من قبل رئيس القسم' . ($reason ? ': ' . $reason : '');

            if ($creator_id && $creator_id != $user_id) {
                $notification_stmt = $db->prepare("
                    INSERT INTO notifications (user_id, title, message, link, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $notification_stmt->execute([
                    $creator_id,
                    $notification_title,
                    $notification_message,
                    '../documents/view_document.php?id=' . $document_id
                ]);
            }

            $db->commit();

            $_SESSION['success_message'] = ($action === 'approve')
                ? 'تمت الموافقة على المستند بنجاح'
                : 'تم رفض المستند بنجاح';
            header("Location: section_manager_dashboard.php");
            exit();

        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error_message'] = 'حدث خطأ أثناء معالجة الإجراء: ' . $e->getMessage();
        }
    }
}

// جلب الموظفين في نفس القسم
$employees = $db->prepare("
    SELECT u.id, u.full_name, u.email, u.is_active 
    FROM users u 
    WHERE u.supervisor_id = :user_id OR u.department_id = :dept_id
    ORDER BY u.full_name
");
$employees->execute([':user_id' => $user_id, ':dept_id' => $department_id]);
$section_employees = $employees->fetchAll(PDO::FETCH_ASSOC);

// جلب عدد الإشعارات غير المقروءة
$unread_count_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = 0";
$unread_count_stmt = $db->prepare($unread_count_query);
$unread_count_stmt->execute([':user_id' => $user_id]);
$unread_count = $unread_count_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// معالجة البحث والتصفية
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$employee_id = $_GET['employee_id'] ?? '';
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

// إعداد الترقيم
$records_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1)
    $page = 1;
$offset = ($page - 1) * $records_per_page;

// بناء شروط البحث
$where_conditions = [];
$params = [];

// ======================= تعديل جوهري هنا =======================
// استبدال الشرط القديم الذي كان يشمل كل القسم بشرط يركز على المشاركة المباشرة فقط
$where_conditions[] = "(d.created_by = :user_id OR d.current_holder_id = :user_id OR dw.to_user_id = :user_id OR df.assigned_to = :user_id)";
$params[':user_id'] = $user_id;   // معامل واحد فقط لجميع الحقول

// شرط استبعاد المسودات إلا إذا كان المستخدم هو منشئها
$where_conditions[] = "(d.current_status != 'draft' OR d.created_by = :user_id)";

if (!empty($search)) {
    $where_conditions[] = "(d.title LIKE :search OR d.description LIKE :search OR u.full_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if (!empty($employee_id) && is_numeric($employee_id)) {
    $where_conditions[] = "d.created_by = :employee_id";
    $params[':employee_id'] = $employee_id;
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

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// جلب إحصائيات الأهمية
$importance_stats_query = "
    SELECT 
        COALESCE(d.priority, 'normal') as priority,
        COUNT(DISTINCT d.id) as count
    FROM documents d
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    LEFT JOIN document_fields df ON d.id = df.document_id
    WHERE (d.created_by = :user_id OR d.current_holder_id = :user_id OR dw.to_user_id = :user_id OR df.assigned_to = :user_id)
    GROUP BY d.priority
";
$importance_stmt = $db->prepare($importance_stats_query);
$importance_stmt->execute([':user_id' => $user_id]);
$importance_stats = $importance_stmt->fetchAll(PDO::FETCH_ASSOC);

$importance_data = [
    'سري' => 0,
    'عاجل' => 0,
    'عادي' => 0,
    'all' => 0
];

$total_all_query = "
    SELECT COUNT(DISTINCT d.id) as total 
    FROM documents d
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    LEFT JOIN document_fields df ON d.id = df.document_id
    WHERE (d.created_by = :user_id OR d.current_holder_id = :user_id OR dw.to_user_id = :user_id OR df.assigned_to = :user_id)
";
$total_all_stmt = $db->prepare($total_all_query);
$total_all_stmt->execute([':user_id' => $user_id]);
$total_all = $total_all_stmt->fetch(PDO::FETCH_ASSOC);
$importance_data['all'] = $total_all['total'] ?? 0;

foreach ($importance_stats as $stat) {
    if ($stat['priority'] === 'urgent') {
        $importance_data['سري'] = $stat['count'];
    } elseif ($stat['priority'] === 'high') {
        $importance_data['عاجل'] = $stat['count'];
    } elseif ($stat['priority'] === 'normal' || $stat['priority'] === 'medium' || $stat['priority'] === 'low') {
        $importance_data['عادي'] += $stat['count'];
    }
}

// جلب المستندات (جميعها معاً)
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
    LEFT JOIN document_fields df ON d.id = df.document_id
    {$where_sql}
    GROUP BY d.id
    ORDER BY {$sort_by} {$sort_order}
    LIMIT {$records_per_page} OFFSET {$offset}
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$documents_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// حساب العدد الإجمالي للمستندات
$total_documents_query = "
    SELECT COUNT(DISTINCT d.id) as total
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN users u2 ON d.current_holder_id = u2.id
    LEFT JOIN departments dep ON u.department_id = dep.id
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    LEFT JOIN document_fields df ON d.id = df.document_id
    {$where_sql}
";
$total_stmt = $db->prepare($total_documents_query);
$total_stmt->execute($params);
$total_documents_result = $total_stmt->fetch(PDO::FETCH_ASSOC);
$total_documents = $total_documents_result['total'] ?? 0;
$total_pages = ceil($total_documents / $records_per_page);

// جلب حالة المستخدم لكل مستند
$documents = [];
foreach ($documents_raw as $doc) {
    $status_query = "SELECT status, action_required, notes FROM document_user_status WHERE document_id = :doc_id AND user_id = :user_id";
    $status_stmt = $db->prepare($status_query);
    $status_stmt->execute([':doc_id' => $doc['id'], ':user_id' => $user_id]);
    $user_status = $status_stmt->fetch(PDO::FETCH_ASSOC);

    $doc['user_status'] = $user_status['status'] ?? 'pending';
    $doc['action_required'] = $user_status['action_required'] ?? null;
    $doc['user_notes'] = $user_status['notes'] ?? null;

    $documents[] = $doc;
}

// جلب إحصائيات القسم
$stats_query = "
    SELECT 
        COUNT(DISTINCT d.id) as total_documents,
        SUM(CASE WHEN d.current_status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN d.current_status = 'under_review' THEN 1 ELSE 0 END) as under_review,
        SUM(CASE WHEN d.current_status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN d.current_status = 'draft' THEN 1 ELSE 0 END) as draft,
        SUM(CASE WHEN d.current_status = 'completion_required' THEN 1 ELSE 0 END) as completion_required,
        COUNT(DISTINCT u.id) as total_employees,
        SUM(CASE WHEN d.priority = 'urgent' THEN 1 ELSE 0 END) as urgent_docs,
        (SELECT COUNT(*) FROM users WHERE supervisor_id = :user_id) as direct_reports,
        (SELECT COUNT(*) FROM tasks WHERE assigned_to IN (SELECT id FROM users WHERE supervisor_id = :user_id OR department_id = :dept_id) AND status = 'pending') as pending_tasks,
        SUM(CASE WHEN d.current_holder_id = :user_id THEN 1 ELSE 0 END) as assigned_to_me,
        SUM(CASE WHEN d.created_by = :user_id THEN 1 ELSE 0 END) as my_documents,
        SUM(CASE WHEN d.current_status = 'partially_signed' THEN 1 ELSE 0 END) as partially_signed
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    LEFT JOIN document_fields df ON d.id = df.document_id
    WHERE (d.created_by = :user_id OR d.current_holder_id = :user_id OR dw.to_user_id = :user_id OR df.assigned_to = :user_id)
";

$stats_params = [
    ':user_id' => $user_id,
    ':dept_id' => $department_id   // هذا المعامل لا يزال مستخدماً في الاستعلام الفرعي pending_tasks
];
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$supervisor_info = null;
$supervisor_query = "
    SELECT u.id, u.full_name, r.role_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    WHERE u.id = (SELECT supervisor_id FROM users WHERE id = :user_id)
";
$supervisor_stmt = $db->prepare($supervisor_query);
$supervisor_stmt->execute([':user_id' => $user_id]);
$supervisor_info = $supervisor_stmt->fetch(PDO::FETCH_ASSOC);

// الموظفون التابعون مباشرة (الذين يشرف عليهم المستخدم الحالي)
$subordinates = [];
$subordinates_query = "
    SELECT u.id, u.full_name, r.role_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    WHERE u.supervisor_id = :user_id AND u.id != :user_id
    ORDER BY u.full_name
";
$subordinates_stmt = $db->prepare($subordinates_query);
$subordinates_stmt->execute([':user_id' => $user_id]);
$subordinates = $subordinates_stmt->fetchAll(PDO::FETCH_ASSOC);

function buildPaginationUrl($page_num) {
    $params = $_GET;
    $params['page'] = $page_num;
    return http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>

<body>
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
                        <a href="../documents/document_upload.php?for=section&return_to=section_manager_dashboard"
                            target="_self" class="circle-filter-btn add" style="text-decoration: none;">
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
                        <button class="circle-filter-btn secret <?php echo $importance === 'سري' ? 'active' : ''; ?> <?php echo $importance_data['سري'] > 0 ? 'flash' : ''; ?>"
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
                        <button class="circle-filter-btn urgent <?php echo $importance === 'عاجل' ? 'active' : ''; ?> <?php echo $importance_data['عاجل'] > 0 ? 'flash' : ''; ?>"
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
                        <button class="circle-filter-btn normal <?php echo $importance === 'عادي' ? 'active' : ''; ?> <?php echo $importance_data['عادي'] > 0 ? 'flash' : ''; ?>"
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
                        <button id="toggleAdvancedFiltersBtn" class="circle-filter-btn arch"
                            onclick="toggleAdvancedFilters()">
                            <i class="fas fa-sliders-h"></i>
                        </button>
                        <span id="toggleFiltersText">بحث متقدم</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="section-dashboard">
            <!-- إحصائيات سريعة -->
            <div class="stats-grid">
                <!-- بطاقة إجمالي المستندات -->
                <div class="stat-card total">
                    <div class="stat-card-content">
                        <div class="stat-card-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-card-number">
                            <?php echo $stats['total_documents'] ?? 0; ?>
                        </div>
                        <div class="stat-card-title">
                            إجمالي المستندات
                        </div>
                        <div class="stat-card-description">
                            <i class="fas fa-chart-line"></i>
                            جميع مستندات القسم
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

                <!-- بطاقة قيد المراجعة -->
                <div class="stat-card under_review">
                    <div class="stat-card-content">
                        <div class="stat-card-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <div class="stat-card-number">
                            <?php echo $stats['under_review'] ?? 0; ?>
                        </div>
                        <div class="stat-card-title">
                            قيد المراجعة
                        </div>
                        <div class="stat-card-description">
                            <i class="fas fa-eye"></i>
                            قيد التدقيق والمراجعة
                        </div>
                    </div>
                </div>

                <!-- بطاقة المكتملة -->
                <div class="stat-card completed">
                    <div class="stat-card-content">
                        <div class="stat-card-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-card-number">
                            <?php echo $stats['completed'] ?? 0; ?>
                        </div>
                        <div class="stat-card-title">
                            مكتملة
                        </div>
                        <div class="stat-card-description">
                            <i class="fas fa-flag-checkered"></i>
                            تم إنهاؤها بنجاح
                        </div>
                    </div>
                </div>
            </div>

            <!-- قسم الفلاتر المتقدمة -->
            <div id="advancedFiltersSection" class="advanced-filters-section" style="display: none;">
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
                                <label for="employeeSelect">الموظف</label>
                                <select name="employee_id" id="employeeSelect" class="form-control">
                                    <option value="">جميع الموظفين</option>
                                    <?php foreach ($section_employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" <?php echo $employee_id == $emp['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label for="statusSelect">الحالة العامة</label>
                                <select name="status" id="statusSelect" class="form-control">
                                    <option value="">جميع الحالات</option>
                                    <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>مسودة
                                    </option>
                                    <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>قيد المراجعة</option>
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>قيد
                                        الانتظار</option>
                                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>
                                        مكتملة</option>
                                    <option value="partially_signed" <?php echo $status === 'partially_signed' ? 'selected' : ''; ?>>موقع جزئياً</option>
                                    <option value="partially_completed" <?php echo $status === 'partially_completed' ? 'selected' : ''; ?>>مكتمل جزئياً</option>
                                    <option value="completion_required" <?php echo $status === 'completion_required' ? 'selected' : ''; ?>>مطلوب استكمال</option>
                                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>
                                        مرفوضة</option>
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
                    <p>ابدأ بإنشاء مستند جديد أو انتظر حتى يقوم الموظفون بإنشاء مستندات</p>
                    <a href="../documents/document_upload.php?for=section&return_to=section_manager_dashboard" class="btn"
                        style="background: linear-gradient(135deg, #2ecc71, #27ae60); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
                        <i class="fas fa-plus"></i> إنشاء مستند جديد
                    </a>
                </div>
            <?php else: ?>
                <?php if ($viewMode == 'cards'): ?>
                    <!-- عرض البطاقات (كما هو) -->
                    <div class="documents-grid">
                        <?php foreach ($documents as $index => $doc):
                            $is_creator = ($doc['created_by'] == $user_id);
                            $is_assigned = ($doc['current_holder_id'] == $user_id);
                            $user_status = $doc['user_status'] ?? 'pending';
                            $doc_type = ($doc['created_by'] == $user_id) ? 'my_documents' : 'assigned_to_me';
                            ?>
                            <div class="document-card">
                                <!-- رقعة نوع المستند -->
                                <div class="type-ribbon <?php echo $doc_type; ?>">
                                    <?php echo $doc_type == 'my_documents' ? 'صــادر' : 'وارد'; ?>
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
                                        </div>

                                        <!-- الحالة العامة -->
                                        <div class="info-item">
                                            <span class="info-label">الحالة:</span>
                                            <span class="info-value">
                                                <?php
                                                $status_config = [
                                                    'draft' => ['label' => 'مسودة', 'class' => 'draft'],
                                                    'under_review' => ['label' => 'قيد المراجعة', 'class' => 'under_review'],
                                                    'pending' => ['label' => 'قيد الانتظار', 'class' => 'pending'],
                                                    'completed' => ['label' => 'مكتملة', 'class' => 'completed'],
                                                    'rejected' => ['label' => 'مرفوض', 'class' => 'rejected'],
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
                                            <i class="fas fa-eye"></i> عرض
                                        </button>

                                        <?php if ($user_status == 'completion_required' || $user_status == 'partially_signed' || $user_status == 'completed'): ?>
                                            <button onclick="showAddWorkflowStepModal(<?php echo $doc['id']; ?>)"
                                                class="card-btn forward">
                                                <i class="fas fa-forward"></i> إرسال
                                            </button>
                                        <?php endif; ?>

                                        <button onclick="openTrackPopup(<?php echo $doc['id']; ?>)" class="card-btn track">
                                            <i class="fas fa-project-diagram"></i> تتبع
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <!-- جدول المستندات (موحد) -->
                    <div class="documents-table-container">
                        <div class="documents-header">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: nowrap; gap: 20px; width: 100%;">
                                <div style="flex-shrink: 0; min-width: 150px;">
                                    <h3 style="margin: 0; white-space: nowrap;">
                                        <i class="fas fa-file-alt"></i> جميع المستندات
                                    </h3>
                                </div>
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
                                <div style="flex-shrink: 0; min-width: 180px;">
                                    <div class="thired-text">
                                        <div><i class="fas fa-sync-alt"></i> آخر تحديث: <?php echo date('H:i:s'); ?></div>
                                        <div style="margin-top: 2px;"><i class="fas fa-layer-group"></i> إجمالي:
                                            <?php echo $total_documents; ?> مستند</div>
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
                                        <th>المرسل</th>
                                        <th>حالتي</th>
                                        <th>الحالة العامة</th>
                                        <th>الأولوية</th>
                                        <th style="text-align: center;">الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($documents as $index => $doc):
                                        $is_creator = ($doc['created_by'] == $user_id);
                                        $is_assigned = ($doc['current_holder_id'] == $user_id);
                                        $user_status = $doc['user_status'] ?? 'pending';
                                        $doc_type = ($doc['created_by'] == $user_id) ? 'my_documents' : 'assigned_to_me';
                                        ?>
                                        <tr class="document-row" data-searchable="<?php echo htmlspecialchars(json_encode([
                                            'title' => $doc['title'],
                                            'description' => $doc['description'] ?? '',
                                            'creator_name' => $doc['creator_name'],
                                            'department_name' => $doc['department_name'] ?? '',
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
                                                    <span
                                                        class="document-title"><?php echo htmlspecialchars($doc['title']); ?></span>
                                                    <?php if ($doc['description']): ?>
                                                        <span class="document-description">
                                                            <?php echo htmlspecialchars(mb_substr($doc['description'], 0, 80, 'UTF-8')); ?>
                                                            <?php if (mb_strlen($doc['description'], 'UTF-8') > 80): ?>...<?php endif; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="document-type <?php echo $doc_type; ?>">
                                                        <i
                                                            class="fas <?php echo $doc_type == 'my_documents' ? 'fa-edit' : 'fa-inbox'; ?>"></i>
                                                        <?php echo $doc_type == 'my_documents' ? 'الصادر' : 'الوارد'; ?>
                                                    </span>
                                                </a>
                                            </td>
                                            <td class="sender-cell">
                                                <?php echo htmlspecialchars($doc['creator_name']); ?>
                                                <?php if ($is_creator): ?><span class="you-badge">أنت</span><?php endif; ?>
                                                <span
                                                    class="sender-date"><?php echo date('Y-m-d', strtotime($doc['created_at'])); ?></span>
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
                                                        'approved' => ['label' => 'موافق', 'class' => 'completed'],
                                                        'rejected' => ['label' => 'مرفوض', 'class' => 'rejected']
                                                    ];
                                                    $status_info = $status_labels[$user_status] ?? ['label' => $user_status, 'class' => 'pending'];
                                                    ?>
                                                    <span class="status-badge status-<?php echo $status_info['class']; ?>">
                                                        <?php echo $status_info['label']; ?>
                                                    </span>
                                                    <?php if ($is_assigned): ?>
                                                        <span class="user-status assigned"><i class="fas fa-user-check"></i> معك
                                                            حالياً</span>
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
                                                        'partially_signed' => ['label' => 'موقع جزئياً', 'class' => 'partially_signed'],
                                                        'partially_completed' => ['label' => 'مكتمل جزئياً', 'class' => 'partially_completed'],
                                                        'completion_required' => ['label' => 'مطلوب استكمال', 'class' => 'completion_required']
                                                    ];
                                                    $status_cfg = $status_config[$doc['current_status']] ?? ['label' => $doc['current_status'], 'class' => 'pending'];
                                                    ?>
                                                    <span class="status-badge status-<?php echo $status_cfg['class']; ?>">
                                                        <?php echo $status_cfg['label']; ?>
                                                    </span>
                                                    <?php if (!empty($doc['assigned_to_name']) && $doc['current_status'] !== 'completed' && $doc['current_status'] !== 'rejected'): ?>
                                                        <div class="assigned-to-info">
                                                            <i class="fas fa-user-tag"></i> عند:
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
                                                    <?php if ($is_assigned): ?>
                                                        <a href="../documents/view_document.php?id=<?php echo $doc['id']; ?>"
                                                            class="employee-btn view" title="عرض المستند"><i class="fas fa-eye"></i></a>
                                                        <button onclick="showAddWorkflowStepModal(<?php echo $doc['id']; ?>)"
                                                            class="employee-btn forward" title="معالجة"><i
                                                                class="fas fa-forward"></i></button>
                                                    <?php endif; ?>
                                                    <button onclick="openTrackPopup(<?php echo $doc['id']; ?>)"
                                                        class="employee-btn track" title="تتبع المسار"><i
                                                            class="fas fa-project-diagram"></i></button>
                                                    <?php if ($doc['current_status'] == 'draft'): ?>
                                                        <button onclick="deleteDocument(<?php echo $doc['id']; ?>)" class="card-btn"
                                                            style="background: #e74c3c; color: white;" title="حذف المستند"><i
                                                                class="fas fa-trash"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pagination موحد -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-info">
                                عرض <?php echo count($documents); ?> من أصل <?php echo $total_documents; ?> مستند |
                                الصفحة <?php echo $page; ?> من <?php echo $total_pages; ?>
                            </div>
                            <ul class="pagination">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                        href="?<?php echo buildPaginationUrl($page - 1); ?>"
                                        aria-label="السابق"><i class="fas fa-chevron-right"></i></a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                if ($start > 1) {
                                    echo '<li class="page-item"><a class="page-link" href="?' . buildPaginationUrl(1) . '">1</a></li>';
                                    if ($start > 2)
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                for ($i = $start; $i <= $end; $i++) {
                                    $active = ($i == $page) ? 'active' : '';
                                    echo '<li class="page-item ' . $active . '"><a class="page-link" href="?' . buildPaginationUrl($i) . '">' . $i . '</a></li>';
                                }
                                if ($end < $total_pages) {
                                    if ($end < $total_pages - 1)
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    echo '<li class="page-item"><a class="page-link" href="?' . buildPaginationUrl($total_pages) . '">' . $total_pages . '</a></li>';
                                }
                                ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                        href="?<?php echo buildPaginationUrl($page + 1); ?>"
                                        aria-label="التالي"><i class="fas fa-chevron-left"></i></a>
                                </li>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- نافذة منبثقة لإضافة خطوات توقيع جديدة (استكمال) -->
    <div id="workflowStepModal" class="modal-overlay" style="display:none;">
        <div class="modal-content" style="max-width: 550px;">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i>إرسال للمعالجة</h3>
                <button type="button" onclick="closeWorkflowStepModal()"
                    style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">
                    &times;
                </button>
            </div>
            <div class="modal-body">
                <form id="addWorkflowStepForm" method="POST" action="../documents/add_workflow_step.php">
                    <input type="hidden" name="document_id" id="modalDocumentId">
                    <input type="hidden" name="fields" id="selectedFieldsInput" value="note">

                    <div style="margin-bottom: 20px;">
                        <label class="form-label">
                            المستخدم المستهدف <span style="color: #e74c3c;">*</span>
                        </label>
                        <select name="assigned_to" class="form-control" required id="assignedToSelect">
                            <option value="">اختر المستخدم</option>
                            <?php
                            // جلب المدير المباشر (رئيس المستخدم الحالي)
                            $supervisor_query = "SELECT supervisor_id FROM users WHERE id = :user_id";
                            $supervisor_stmt = $db->prepare($supervisor_query);
                            $supervisor_stmt->execute([':user_id' => $user_id]);
                            $supervisor_id = $supervisor_stmt->fetchColumn();

                            if ($supervisor_id) {
                                $supervisor_info_query = "
                                    SELECT u.id, u.full_name, r.role_name ,u.title
                                    FROM users u 
                                    LEFT JOIN roles r ON u.role_id = r.id 
                                    WHERE u.id = :supervisor_id
                                ";
                                $supervisor_info_stmt = $db->prepare($supervisor_info_query);
                                $supervisor_info_stmt->execute([':supervisor_id' => $supervisor_id]);
                                $supervisor = $supervisor_info_stmt->fetch();

                                if ($supervisor): ?>
                                    <option value="<?php echo $supervisor['id']; ?>">
                                        <?php echo htmlspecialchars($supervisor['full_name']) ?>
                                        (<?php echo htmlspecialchars($supervisor['title']); ?>)
                                    </option>
                                <?php endif;
                            }

                            // جلب الموظفين التابعين (الذين يشرف عليهم المستخدم الحالي)
                            $subordinates_query = "
                                SELECT u.id, u.full_name, r.role_name 
                                FROM users u 
                                LEFT JOIN roles r ON u.role_id = r.id 
                                WHERE u.supervisor_id = :user_id 
                                AND u.id != :user_id 
                                AND u.is_active = 1
                                ORDER BY u.full_name
                            ";
                            $subordinates_stmt = $db->prepare($subordinates_query);
                            $subordinates_stmt->execute([':user_id' => $user_id]);
                            $subordinates = $subordinates_stmt->fetchAll();

                            if (!empty($subordinates)): ?>
                                <optgroup label="الموظفين التابعين">
                                    <?php foreach ($subordinates as $sub): ?>
                                        <option value="<?php echo $sub['id']; ?>">
                                            <?php echo htmlspecialchars($sub['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label class="form-label">
                            نوع الخطوة <span style="color: #e74c3c;">*</span>
                        </label>
                        <select name="step_type" class="form-control" required id="stepTypeSelect"
                            onchange="toggleStepFields()">
                            <option value="signature">استكمال</option>
                            <option value="approve">موافق</option>
                            <option value="reject">رفض</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;" id="fieldsSection">
                
                        <div style="margin-bottom: 10px; color: #666; font-size: 0.9rem;">
                            <i class="fas fa-info-circle"></i> انقر على الأزرار لتحديد الحقول المطلوبة من
                            المستخدم
                        </div>

                        <div class="field-buttons-container"
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 10px; margin-bottom: 15px;">
                            <button type="button" class="field-button" data-field="note" data-selected="true">
                                <i class="fas fa-sticky-note"></i>
                                <span>ملاحظة</span>
                            </button>
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
    <div id="trackPopup" class="popup-overlay">
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

    <!-- تضمين ملف الجافاسكريبت الأساسي -->
    <script src="../assets/js/section_scr.js"></script>

    <!-- دوال إضافية للبحث المحسن -->
    <script>
        const unreadCount = <?php echo (int) $unread_count; ?>;

        // إلغاء أي تأثير لـ setFaviconWithBadge
        window.setFaviconWithBadge = function() {};

        // دوال البحث الفوري المحسن
        function performInstantSearchForTable(searchTerm, tableId, infoId, resultsCountId) {
            const table = document.getElementById(tableId);
            if (!table) return;
            const rows = table.querySelectorAll('.document-row');
            let matchCount = 0;
            rows.forEach(row => {
                row.classList.remove('highlight-search');
                const searchableData = row.getAttribute('data-searchable');
                let isMatch = false;
                if (searchableData) {
                    try {
                        const data = JSON.parse(searchableData);
                        const searchableText = Object.values(data)
                            .filter(v => v != null)
                            .map(v => String(v).toLowerCase())
                            .join(' ');
                        isMatch = searchableText.includes(searchTerm.toLowerCase());
                        if (!isMatch) {
                            const visibleText = row.textContent.toLowerCase();
                            isMatch = visibleText.includes(searchTerm.toLowerCase());
                        }
                    } catch (e) {}
                }
                if (isMatch) {
                    row.classList.remove('hidden-by-search');
                    row.classList.add('highlight-search');
                    matchCount++;
                } else {
                    row.classList.add('hidden-by-search');
                }
            });

            const searchInfo = document.getElementById(infoId);
            const resultsCount = document.getElementById(resultsCountId);
            if (searchTerm.trim() !== '') {
                if (resultsCount) resultsCount.textContent = matchCount;
                if (searchInfo) {
                    searchInfo.style.display = 'block';
                    if (matchCount === 0) {
                        searchInfo.innerHTML = '<span style="color:#e74c3c">لم يتم العثور على نتائج</span>';
                    } else {
                        searchInfo.innerHTML = `<span style="color:#27ae60">${matchCount}</span> نتيجة`;
                    }
                }
            } else {
                if (searchInfo) searchInfo.style.display = 'none';
            }
        }

        function addResetButtonToSearch(searchInput, tableId, infoId, resultsCountId) {
            const resetBtn = document.createElement('button');
            resetBtn.innerHTML = '<i class="fas fa-times"></i>';
            resetBtn.style.cssText = 'position:absolute; left:35px; top:50%; transform:translateY(-50%); background:none; border:none; color:#95a5a6; cursor:pointer; display:none;';
            resetBtn.addEventListener('click', function() {
                searchInput.value = '';
                performInstantSearchForTable('', tableId, infoId, resultsCountId);
                searchInput.focus();
            });
            searchInput.parentNode.appendChild(resetBtn);
            searchInput.addEventListener('input', function() {
                resetBtn.style.display = this.value.trim() ? 'block' : 'none';
            });
        }

        function initInstantSearch() {
            // الجدول الموحد
            const searchInput = document.getElementById('instantTableSearch');
            if (searchInput) {
                let timeout;
                searchInput.addEventListener('input', function(e) {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => {
                        performInstantSearchForTable(e.target.value.trim(), 'documentsTable', 'tableSearchInfo', 'searchResultsCount');
                    }, 300);
                });
                addResetButtonToSearch(searchInput, 'documentsTable', 'tableSearchInfo', 'searchResultsCount');
            }
        }

        // تهيئة عند تحميل الصفحة
        document.addEventListener('DOMContentLoaded', function() {
            // ربط نموذج الفلاتر
            const filterForm = document.getElementById('filterForm');
            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    let input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'importance';
                    input.value = '';
                    this.appendChild(input);
                });
            }

            // فتح/إغلاق الفلاتر بناءً على الحالة المحفوظة
            const filtersVisible = localStorage.getItem('advancedFiltersVisible');
            const filtersSection = document.getElementById('advancedFiltersSection');
            if (filtersVisible === 'true' && filtersSection) {
                setTimeout(() => {
                    if (filtersSection.style.display !== 'block') toggleAdvancedFilters();
                }, 500);
            }

            // تهيئة البحث الفوري
            initInstantSearch();

            // تعطيل عرض عدد الإشعارات على الأيقونة - إزالة استدعاء setFaviconWithBadge
            // if (typeof unreadCount !== 'undefined') {
            //     setFaviconWithBadge(unreadCount);
            // } else {
            //     const count = document.querySelector('meta[name="unread-count"]')?.content;
            //     if (count) setFaviconWithBadge(parseInt(count));
            // }

            // كشف الجهاز
            if (window.innerWidth <= 768) {
                const urlParams = new URLSearchParams(window.location.search);
                if (!urlParams.has('view')) {
                    urlParams.set('view', 'cards');
                    history.replaceState({}, '', `${window.location.pathname}?${urlParams}`);
                }
            }
        });

        // تحديث الصفحة كل 3 دقائق
        setTimeout(() => location.reload(), 180000);
    </script>
</body>

</html>