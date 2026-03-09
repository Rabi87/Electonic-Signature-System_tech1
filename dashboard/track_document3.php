<?php

/**
 * صفحة تتبع مسار المستند
 */
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من وجود معرف المستند
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: " . ($_SESSION['role_name'] === 'employee' ? 'employee_dashboard.php' : 'section_manager_dashboard.php'));
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$document_id = $_GET['id'];

// جلب معلومات المستند
$document_query = "
    SELECT d.*, u.full_name as creator_name, u.role_id as creator_role_id,
           r.role_name as creator_role_name, d.current_holder_id
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE d.id = :doc_id
";

$doc_stmt = $db->prepare($document_query);
$doc_stmt->execute([':doc_id' => $document_id]);
$document = $doc_stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    $_SESSION['error_message'] = 'المستند غير موجود';
    header("Location: " . ($_SESSION['role_name'] === 'employee' ? 'employee_dashboard.php' : 'section_manager_dashboard.php'));
    exit();
}

// ✅ جلب أول حقل نصي (text field) وقيمته من field_values
$text_field_query = "
    SELECT 
        df.id as field_id,
        df.default_value,
        fv.value_data as field_value
    FROM document_fields df 
    LEFT JOIN field_values fv ON df.id = fv.field_id
    WHERE df.document_id = :doc_id 
    AND df.field_type = 'text' 
    ORDER BY df.field_order ASC, df.id ASC, fv.created_at DESC
    LIMIT 1
";

$text_field_stmt = $db->prepare($text_field_query);
$text_field_stmt->execute([':doc_id' => $document_id]);
$text_field = $text_field_stmt->fetch(PDO::FETCH_ASSOC);

// ✅ تحديد رقم المستند المعروض (القيمة من field_values أو default_value أو ID كبديل)
$document_display_number = '';
if ($text_field && !empty($text_field['field_value'])) {
    $document_display_number = htmlspecialchars($text_field['field_value']);
} elseif ($text_field && !empty($text_field['default_value'])) {
    $document_display_number = htmlspecialchars($text_field['default_value']);
} else {
    $document_display_number = '#' . $document['id'];
}

// التحقق من صلاحية المستخدم لعرض هذا المستند
$access_check_query = "
    SELECT 1 FROM documents d
    WHERE d.id = :doc_id 
    AND (
        d.created_by = :user_id 
        OR d.current_holder_id = :user_id2 
        OR EXISTS (
            SELECT 1 FROM document_workflow dw 
            WHERE dw.document_id = d.id AND dw.to_user_id = :user_id3
        )
        OR EXISTS (
            SELECT 1 FROM signatures s 
            WHERE s.document_id = d.id AND s.user_id = :user_id4
        )
        OR :is_admin = 1
    )
";

$is_admin = in_array($_SESSION['role_name'], ['admin', 'ceo', 'department_manager']) ? 1 : 0;

$access_stmt = $db->prepare($access_check_query);
$access_stmt->execute([
    ':doc_id' => $document_id,
    ':user_id' => $user_id,
    ':user_id2' => $user_id,
    ':user_id3' => $user_id,
    ':user_id4' => $user_id,
    ':is_admin' => $is_admin
]);

$has_access = $access_stmt->fetch(PDO::FETCH_ASSOC);

if (!$has_access) {
    $_SESSION['error_message'] = 'لا تملك صلاحية عرض هذا المستند';
    header("Location: " . ($_SESSION['role_name'] === 'employee' ? 'employee_dashboard.php' : 'section_manager_dashboard.php'));
    exit();
}

// جلب جميع الأدوار التي شاركت في سير العمل (مع الترتيب الثابت للأدوار)
$roles_order = ['employee' => 1, 'section_manager' => 2, 'department_manager' => 3, 'ceo' => 4, 'admin' => 5, 'sub_board' => 6, 'board' => 7, 'private_board' => 8, 'deputy_ceo' => 9, 'office_manager' => 10];

// استعلام مبسط يجلب المستخدمين الذين تلقوا المستند فعلياً
$roles_query = "
    SELECT DISTINCT 
        r.id, 
        r.role_name, 
        r.description,
        MIN(dw.action_date) as first_action_date,
        MAX(dw.action_date) as last_action_date,
        GROUP_CONCAT(DISTINCT u.id SEPARATOR ',') as user_ids,
        GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') as user_names
    FROM roles r
    JOIN users u ON u.role_id = r.id
    JOIN document_workflow dw ON dw.to_user_id = u.id AND dw.document_id = :doc_id
    WHERE dw.document_id = :doc_id2
    GROUP BY r.id, r.role_name, r.description
    UNION
    SELECT 
        r.id, 
        r.role_name, 
        r.description,
        d.created_at as first_action_date,
        d.created_at as last_action_date,
        u.id as user_ids,
        u.full_name as user_names
    FROM documents d
    JOIN users u ON d.created_by = u.id
    JOIN roles r ON u.role_id = r.id
    WHERE d.id = :doc_id3
    AND NOT EXISTS (
        SELECT 1 FROM document_workflow dw2 
        WHERE dw2.document_id = d.id 
        AND dw2.to_user_id = u.id
    )
    ORDER BY 
        CASE role_name
            WHEN 'employee' THEN 1
            WHEN 'section_manager' THEN 2
            WHEN 'department_manager' THEN 3
            WHEN 'board' THEN 4
            WHEN 'admin' THEN 5
            WHEN 'ceo' THEN 6
            WHEN 'private_board' THEN 7
            WHEN 'deputy_ceo' THEN 8
            WHEN 'sub_board' THEN 9
            WHEN 'office_manager' THEN 10
            ELSE 9
        END,
        first_action_date ASC
";

$roles_stmt = $db->prepare($roles_query);
$roles_stmt->execute([
    ':doc_id' => $document_id,
    ':doc_id2' => $document_id,
    ':doc_id3' => $document_id
]);
$roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// إذا لم تكن هناك أدوار، نضيف دور المنشئ فقط
if (empty($roles)) {
    $roles[] = [
        'id' => $document['creator_role_id'],
        'role_name' => $document['creator_role_name'],
        'description' => '',
        'first_action_date' => $document['created_at'],
        'last_action_date' => $document['created_at'],
        'user_ids' => $document['created_by'],
        'user_names' => $document['creator_name']
    ];
}

// جلب الحالات المحددة لكل مستخدم من document_user_status
$user_status_query = "
    SELECT dus.*, u.full_name, u.role_id, r.role_name
    FROM document_user_status dus
    LEFT JOIN users u ON dus.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE dus.document_id = :doc_id
    ORDER BY dus.updated_at DESC
";

$user_status_stmt = $db->prepare($user_status_query);
$user_status_stmt->execute([':doc_id' => $document_id]);
$user_statuses = $user_status_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب معلومات المستخدم الحالي للمستند
$current_holder_info = null;
if ($document['current_holder_id']) {
    $holder_query = "SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = :holder_id";
    $holder_stmt = $db->prepare($holder_query);
    $holder_stmt->execute([':holder_id' => $document['current_holder_id']]);
    $current_holder_info = $holder_stmt->fetch(PDO::FETCH_ASSOC);
}

// تحويل أسماء الأدوار إلى العربية
$role_translations = [
    'admin' => 'المسؤول',
    'ceo' => 'الرئيس التنفيذي',
    'department_manager' => 'مدير الدائرة',
    'section_manager' => 'رئيس القسم',
    'employee' => 'موظف',
    'board' => 'الديوان',
    'private_board' => 'الديوان الخاص',
    'sub_board' => 'الديوان الفرعي',
    'office_manager' => 'مدير المكتب',
    'deputy_ceo' => 'نائب الرئيس التنفيذي'
];

// تجميع البيانات للعرض
$timeline_data = [];

foreach ($roles as $role) {
    $role_id = $role['id'];
    $role_name = $role_translations[$role['role_name']] ?? $role['role_name'];

    // تقسيم معرفات المستخدمين وأسمائهم
    $user_ids = explode(',', $role['user_ids']);
    $user_names = explode(', ', $role['user_names']);

    // البحث عن حالة هذا الدور من document_user_status
    $role_status = 'pending'; // الحالة الافتراضية
    $role_updated_at = null;
    $current_user_name = '';

    // الحل السحري: عرض اسم المنشئ أولاً إذا كان في هذا الدور
    if (in_array($document['created_by'], $user_ids)) {
        $key = array_search($document['created_by'], $user_ids);
        $current_user_name = $user_names[$key];
    } else if (!empty($user_names)) {
        $current_user_name = $user_names[0];
    }

    // البحث عن حالة هذا المستخدم
    if (!empty($current_user_name)) {
        foreach ($user_statuses as $status) {
            if ($status['role_id'] == $role_id && strpos($role['user_names'], $status['full_name']) !== false) {
                $role_status = $status['status'];
                $role_updated_at = $status['updated_at'];
                break;
            }
        }
    }

    // التحقق إذا كان المستخدم الحالي ينتمي لهذا الدور
    $is_current = false;
    if ($current_holder_info && $current_holder_info['role_id'] == $role_id) {
        $is_current = true;
    }

    // تحديد أيقونة ولون الحالة
    $icon = 'fas fa-clock';
    $color = 'gray';
    $status_text = '';

    if ($is_current) {
        $icon = 'fas fa-user-check';
        $color = 'blue';
        $status_text = 'قيد المعالجة';
    } else {
        switch ($role_status) {
            case 'completed':
            case 'approved':
                $icon = 'fas fa-check-circle';
                $color = 'green';
                $status_text = 'مكتمل';
                break;
            case 'rejected':
                $icon = 'fas fa-times-circle';
                $color = 'red';
                $status_text = 'مرفوض';
                break;
            case 'partially_signed':
            case 'partially_completed':
                $icon = 'fas fa-clock';
                $color = 'orange';
                $status_text = 'قيد التعبئة';
                break;
            default:
                $icon = 'fas fa-clock';
                $color = 'gray';
                $status_text = 'بانتظار';
        }
    }

    // الحصول على التاريخ المناسب للعرض
    $display_date = '';
    if ($role_updated_at) {
        $display_date = date('Y-m-d H:i', strtotime($role_updated_at));
    } elseif ($role['first_action_date']) {
        $display_date = date('Y-m-d H:i', strtotime($role['first_action_date']));
    }

    // الحصول على قسم المستخدم
    $current_user_department = '';
    if (!empty($current_user_name)) {
        $dept_query = "SELECT d.name FROM users u 
                      LEFT JOIN departments d ON u.department_id = d.id 
                      WHERE u.full_name = :user_name LIMIT 1";
        $dept_stmt = $db->prepare($dept_query);
        $dept_stmt->execute([':user_name' => $current_user_name]);
        $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
        $current_user_department = $dept_result ? $dept_result['name'] : '';
    }

    // تحديد معرف المستخدم الحالي
    $current_user_id_in_role = null;
    if (!empty($user_ids)) {
        $user_ids_array = explode(',', $role['user_ids']);
        $current_user_id_in_role = $user_ids_array[0]; // نأخذ أول مستخدم
    }

    // إضافة البيانات إلى المصفوفة
    $timeline_data[] = [
        'role_id' => $role_id,
        'role_name' => $role_name,
        'status' => $role_status,
        'status_text' => $status_text,
        'icon' => $icon,
        'color' => $color,
        'date' => $display_date,
        'is_current' => $is_current,
        'current_user_name' => $current_user_name,
        'current_user_department' => $current_user_department,
        'current_user_id' => $current_user_id_in_role
    ];
}

// جلب التواقيع مع معلومات القسم
$signatures_query = "
    SELECT s.*, u.full_name, u.department_id, r.role_name
    FROM signatures s
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE s.document_id = :doc_id
    ORDER BY s.signed_at ASC
";

$signatures_stmt = $db->prepare($signatures_query);
$signatures_stmt->execute([':doc_id' => $document_id]);
$signatures = $signatures_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب أسماء الأقسام للتواقيع
$departments = [];
if (!empty($signatures)) {
    $dept_ids = array_filter(array_column($signatures, 'department_id'));
    if (!empty($dept_ids)) {
        $placeholders = implode(',', array_fill(0, count($dept_ids), '?'));
        $dept_query = "SELECT id, name FROM departments WHERE id IN ($placeholders)";
        $dept_stmt = $db->prepare($dept_query);
        $dept_stmt->execute($dept_ids);
        $dept_results = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($dept_results as $dept) {
            $departments[$dept['id']] = $dept['name'];
        }
    }
}

// إضافة اسم القسم لكل توقيع
foreach ($signatures as &$signature) {
    $signature['department_name'] = isset($signature['department_id']) && isset($departments[$signature['department_id']])
        ? $departments[$signature['department_id']]
        : '';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تتبع مسار المستند - نظام التوقيع الإلكتروني</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ===== Git Graph Tracking - متغيرات النظام ===== */
        :root {
            --main-line: #3b82f6;
            --board-line: #8b5cf6;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #f59e0b;
            --pending: #94a3b8;
            --processing: #3b82f6;
            --bg: #0f172a;
            --surface: #1e293b;
            --surface2: #263347;
            --border: #334155;
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --node-size: 44px;
            --track-width: 3px;
        }

        * { box-sizing: border-box; }

        body {
            background: var(--bg);
            font-family: 'Cairo', sans-serif;
            color: var(--text);
            min-height: 100vh;
        }

        /* ===== Header ===== */
        .tracking-container { padding: 20px; max-width: 1100px; margin: 0 auto; }

        .header-section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px 30px;
            margin-bottom: 24px;
        }

        .back-btn {
            background: var(--surface2);
            color: var(--text);
            border: 1px solid var(--border);
            padding: 8px 18px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .back-btn:hover { background: var(--border); color: white; text-decoration: none; }

        .document-info {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            flex-wrap: wrap;
            gap: 24px;
        }

        .doc-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--surface2);
            border: 1px solid var(--border);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.88rem;
        }
        .doc-badge i { color: #60a5fa; }
        .doc-badge .badge-val { font-weight: 700; color: white; }

        /* ===== Legend ===== */
        .legend-bar {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 0.82rem;
            color: var(--text-muted);
        }
        .legend-dot {
            width: 13px; height: 13px;
            border-radius: 50%;
        }

        /* ===== Git Graph Container ===== */
        .git-graph {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 40px 30px;
            margin-bottom: 24px;
            position: relative;
            overflow: visible;
        }

        .git-graph-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 40px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .git-graph-title i { color: #60a5fa; }

        /* ===== SVG Graph ===== */
        #gitGraphSvg {
            width: 100%;
            overflow: visible;
            display: block;
        }

        /* ===== Node Tooltip ===== */
        .node-tooltip {
            position: fixed;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 18px;
            min-width: 200px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.5);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s;
            z-index: 1000;
        }
        .node-tooltip.visible { opacity: 1; }
        .tooltip-role {
            font-weight: 700;
            font-size: 1rem;
            color: white;
            margin-bottom: 6px;
        }
        .tooltip-user {
            font-size: 0.85rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
        }
        .tooltip-date {
            font-size: 0.78rem;
            color: var(--text-muted);
            direction: ltr;
            margin-bottom: 6px;
        }
        .tooltip-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
        }
        .tooltip-status.green { background: rgba(34,197,94,0.2); color: #22c55e; }
        .tooltip-status.blue { background: rgba(59,130,246,0.2); color: #60a5fa; }
        .tooltip-status.gray { background: rgba(148,163,184,0.15); color: #94a3b8; }
        .tooltip-status.red { background: rgba(239,68,68,0.2); color: #ef4444; }
        .tooltip-status.orange { background: rgba(245,158,11,0.2); color: #f59e0b; }

        /* ===== Signatures Section ===== */
        .signatures-section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 25px 30px;
            margin-top: 20px;
        }
        .signatures-section h3 {
            color: var(--text);
            margin-bottom: 20px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .signatures-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }
        .signature-card {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px;
            display: flex;
            align-items: center;
            gap: 13px;
            background: var(--surface2);
            transition: border-color 0.2s;
        }
        .signature-card:hover { border-color: #60a5fa; }
        .signature-avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            font-weight: bold;
            flex-shrink: 0;
        }
        .signature-name { font-weight: 600; color: white; margin-bottom: 2px; font-size: 0.92rem; }
        .signature-role { font-size: 0.78rem; color: var(--text-muted); margin-bottom: 2px; }
        .signature-date { font-size: 0.75rem; color: var(--text-muted); }

        /* ===== Reminder Modal ===== */
        .reminder-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .reminder-modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            width: 90%;
            max-width: 420px;
            border-radius: 14px;
            overflow: hidden;
        }
        .reminder-modal-header {
            padding: 16px 22px;
            background: #1d4ed8;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
        }
        .reminder-modal-body { padding: 22px; }
        .reminder-modal-body p { color: var(--text); margin-bottom: 12px; }
        .reminder-modal-body textarea {
            width: 100%;
            background: var(--surface2);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 8px;
            padding: 10px;
            font-family: 'Cairo', sans-serif;
            resize: vertical;
        }
        .reminder-modal-footer {
            padding: 14px 22px;
            background: var(--surface2);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        @keyframes pulse-proc {
            0%   { box-shadow: 0 0 0 0 rgba(59,130,246,0.6); }
            70%  { box-shadow: 0 0 0 10px rgba(59,130,246,0); }
            100% { box-shadow: 0 0 0 0 rgba(59,130,246,0); }
        }
    </style>
</head>

<body>
    <div class="tracking-container">

        <!-- رأس الصفحة -->
        <div class="header-section">
            <a href="javascript:history.back()" class="back-btn">
                <i class="fas fa-arrow-right"></i> العودة
            </a>
            <div class="document-info">
                <div class="doc-badge">
                    <i class="fas fa-file-alt"></i>
                    <span>رقم المستند:</span>
                    <span class="badge-val"><?php echo $document_display_number; ?></span>
                </div>
                <div class="doc-badge">
                    <i class="fas fa-tag"></i>
                    <span>العنوان:</span>
                    <span class="badge-val"><?php echo htmlspecialchars($document['title']); ?></span>
                </div>
                <div class="doc-badge">
                    <i class="fas fa-circle"></i>
                    <span>الحالة:</span>
                    <?php
                    $status_colors = [
                        'draft' => 'secondary',
                        'pending' => 'secondary',
                        'under_review' => 'info',
                        'completed' => 'success',
                        'partially_signed' => 'warning',
                        'rejected' => 'danger',
                        'completion_required' => 'warning'
                    ];
                    $status_labels = [
                        'draft' => 'مسودة',
                        'pending' => 'قيد الانتظار',
                        'under_review' => 'قيد المراجعة',
                        'completed' => 'مكتمل',
                        'partially_signed' => 'قيد التعبئة',
                        'rejected' => 'مرفوض',
                        'partially_completed' => 'مكتملة جزئياً',
                        'completion_required' => 'استكمال'
                    ];
                    $status_class = $status_colors[$document['current_status']] ?? 'secondary';
                    $status_text_badge = $status_labels[$document['current_status']] ?? $document['current_status'];
                    ?>
                    <span class="badge-val"><?php echo $status_text_badge; ?></span>
                </div>
            </div>
        </div>

        <!-- مفتاح الألوان -->
        <div class="legend-bar">
            <div class="legend-item">
                <div class="legend-dot" style="background:#3b82f6; box-shadow:0 0 6px #3b82f6;"></div>
                <span>قيد المعالجة</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot" style="background:#22c55e;"></div>
                <span>مكتمل</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot" style="background:#ef4444;"></div>
                <span>مرفوض</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot" style="background:#94a3b8;"></div>
                <span>بانتظار</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot" style="background:#8b5cf6;"></div>
                <span>مسار الديوان</span>
            </div>
        </div>

        <!-- ===== Git Graph ===== -->
        <div class="git-graph">
            <div class="git-graph-title">
                <i class="fas fa-code-branch"></i>
                مسار تتبع المستند
            </div>

            <?php
            // تصنيف الأدوار إلى: مسار رئيسي + مسار الديوان
            $main_track_names  = ['موظف', 'رئيس القسم', 'مدير الدائرة'];
            $board_track_names = ['الديوان الخاص', 'الديوان الفرعي', 'الديوان', 'مدير المكتب', 'نائب الرئيس التنفيذي', 'الرئيس التنفيذي'];

            $main_track  = array_values(array_filter($timeline_data, fn($s) => in_array($s['role_name'], $main_track_names)));
            $board_track = array_values(array_filter($timeline_data, fn($s) => in_array($s['role_name'], $board_track_names)));

            $main_order  = ['موظف' => 1, 'رئيس القسم' => 2, 'مدير الدائرة' => 3];
            $board_order = ['الديوان الخاص' => 1, 'الديوان الفرعي' => 2, 'الديوان' => 3, 'مدير المكتب' => 4, 'نائب الرئيس التنفيذي' => 5, 'الرئيس التنفيذي' => 6];

            usort($main_track,  fn($a,$b) => ($main_order[$a['role_name']] ?? 99) <=> ($main_order[$b['role_name']] ?? 99));
            usort($board_track, fn($a,$b) => ($board_order[$a['role_name']] ?? 99) <=> ($board_order[$b['role_name']] ?? 99));

            // ضم الكل لإرسالها إلى JavaScript
            $all_steps = array_merge($main_track, $board_track);
            ?>

            <!-- بيانات PHP تُرسل إلى JS -->
            <script>
            const mainTrack  = <?php echo json_encode(array_values($main_track),  JSON_UNESCAPED_UNICODE); ?>;
            const boardTrack = <?php echo json_encode(array_values($board_track), JSON_UNESCAPED_UNICODE); ?>;
            </script>

            <svg id="gitGraphSvg" xmlns="http://www.w3.org/2000/svg"></svg>
        </div>

        <!-- Tooltip -->
        <div class="node-tooltip" id="nodeTooltip"></div>

        <!-- نافذة التذكير -->
        <div id="reminderModal" class="reminder-modal">
            <div class="reminder-modal-content">
                <div class="reminder-modal-header">
                    <h4 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-bell"></i> إرسال تذكير
                    </h4>
                    <button onclick="closeReminderModal()"
                        style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">
                        ×
                    </button>
                </div>
                <div class="reminder-modal-body">
                    <p>هل تريد إرسال تذكير إلى
                        <strong id="reminderUserName">...</strong>
                        لتفقد هذا المستند؟
                    </p>
                    <input type="hidden" id="reminderTargetUserId">
                    <input type="hidden" id="reminderDocumentId" value="<?php echo $document_id; ?>">

                    <div style="margin-top: 20px;">
                        <textarea id="reminderMessage" placeholder="أضف رسالة شخصية (اختياري)..."
                            rows="3"></textarea>
                    </div>
                </div>
                <div class="reminder-modal-footer">
                    <button onclick="closeReminderModal()"
                        style="background: #95a5a6; color: white; padding: 8px 15px; border: none; border-radius: 5px; cursor: pointer;">
                        إلغاء
                    </button>
                    <button onclick="sendReminder()"
                        style="background: #3498db; color: white; padding: 8px 15px; border: none; border-radius: 5px; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                        <i class="fas fa-paper-plane"></i> إرسال
                    </button>
                </div>
            </div>
        </div>

        <!-- قسم التواقيع -->
        <?php if (!empty($signatures)): ?>
            <div class="signatures-section">
                <h3 style="margin-bottom: 15px;"><i class="fas fa-signature"></i> التواقيع</h3>
                <div class="signatures-grid">
                    <?php foreach ($signatures as $signature): ?>
                        <div class="signature-card">
                            <div class="signature-avatar">
                                <?php echo mb_substr($signature['full_name'], 0, 1); ?>
                            </div>
                            <div class="signature-info">
                                <div class="signature-name"><?php echo htmlspecialchars($signature['full_name']); ?></div>
                                <div class="signature-role"><?php echo htmlspecialchars($signature['role_name']); ?></div>
                                <?php if (!empty($signature['department_name'])): ?>
                                    <div class="signature-department">
                                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($signature['department_name']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="signature-date">
                                    <i class="far fa-clock"></i> <?php echo date('Y-m-d H:i', strtotime($signature['signed_at'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
        // متغيرات عامة
        let currentReminderStep = null;

        // دالة فتح نافذة التذكير
        function openReminderModal(userId, userName) {
            document.getElementById('reminderTargetUserId').value = userId;
            document.getElementById('reminderUserName').textContent = userName;
            document.getElementById('reminderModal').style.display = 'flex';

            // تعيين النص الافتراضي
            document.getElementById('reminderMessage').value =
                `يرجى تفقد المستند "${document.querySelector('.document-info span:nth-child(2)')?.textContent || ''}" في أسرع وقت ممكن.`;
        }

        // دالة إغلاق نافذة التذكير
        function closeReminderModal() {
            document.getElementById('reminderModal').style.display = 'none';
            currentReminderStep = null;
        }

        // دالة إرسال التذكير
        function sendReminder() {
            const targetUserId = document.getElementById('reminderTargetUserId').value;
            const documentId = document.getElementById('reminderDocumentId').value;
            const message = document.getElementById('reminderMessage').value;

            fetch('notification_fix.php?action=create_reminder', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        'document_id': documentId,
                        'target_user_id': targetUserId,
                        'custom_message': message
                    })
                })
                .then(r => r.json())
                .then(data => {
                    alert(data.success ? '✅ تم إرسال التذكير بنجاح' : '❌ ' + data.message);
                    if (data.success) closeReminderModal();
                })
                .catch(() => alert('حدث خطأ أثناء إرسال التذكير'));
        }

        // ===== Git Graph Renderer =====
        document.addEventListener('DOMContentLoaded', function () {

            // إغلاق نافذة التذكير عند النقر خارجها
            document.getElementById('reminderModal').addEventListener('click', function(e) {
                if (e.target === this) closeReminderModal();
            });

            const svg   = document.getElementById('gitGraphSvg');
            const tip   = document.getElementById('nodeTooltip');

            // ألوان الحالات
            const colorMap = {
                green:  '#22c55e',
                blue:   '#3b82f6',
                red:    '#ef4444',
                orange: '#f59e0b',
                gray:   '#64748b'
            };

            // ثوابت الرسم
            const R         = 20;   // نصف قطر العقدة
            const PAD_X     = 60;   // هامش أفقي
            const PAD_Y     = 60;   // هامش عمودي
            const STEP_X    = 130;  // المسافة الأفقية بين العقد
            const STEP_Y    = 90;   // المسافة الرأسية بين العقد
            const MAIN_X    = 40;   // عمود المسار الرئيسي (x)
            const BOARD_X   = 160;  // عمود مسار الديوان (x)

            // حساب الإحداثيات
            // المسار الرئيسي: عمودي، من أعلى لأسفل
            // مسار الديوان: يتفرع من "مدير الدائرة" (آخر عقدة رئيسية) وينتشر لليسار أفقياً

            const mainCount  = mainTrack.length;
            const boardCount = boardTrack.length;

            // نقطة التفرع: تحت آخر عقدة رئيسية، أو إذا لا يوجد مسار رئيسي عند الأعلى
            const branchNodeIdx = mainCount > 0 ? mainCount - 1 : -1;
            const branchY = PAD_Y + branchNodeIdx * STEP_Y;

            // ارتفاع SVG
            const boardRowY = branchY + STEP_Y; // الصف الذي تظهر فيه عقد الديوان
            const totalH = Math.max(
                PAD_Y + (mainCount - 1) * STEP_Y + PAD_Y,
                boardRowY + (boardCount > 0 ? STEP_Y : 0) + PAD_Y
            );

            // عرض SVG: يكفي لعقد الديوان الأفقية
            const totalW = Math.max(
                MAIN_X + PAD_X + 100,
                BOARD_X + (boardCount > 0 ? (boardCount - 1) * STEP_X : 0) + PAD_X + 100
            );

            svg.setAttribute('viewBox', `0 0 ${totalW} ${totalH}`);
            svg.setAttribute('height', totalH);

            const ns = 'http://www.w3.org/2000/svg';

            function el(tag, attrs, parent) {
                const e = document.createElementNS(ns, tag);
                for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
                if (parent) parent.appendChild(e);
                return e;
            }

            // --- رسم خط المسار الرئيسي ---
            if (mainCount > 1) {
                el('line', {
                    x1: MAIN_X, y1: PAD_Y,
                    x2: MAIN_X, y2: PAD_Y + (mainCount - 1) * STEP_Y,
                    stroke: '#3b82f6', 'stroke-width': 3,
                    'stroke-dasharray': '6,3'
                }, svg);
            }

            // --- رسم خط التفرع (من المسار الرئيسي إلى بداية مسار الديوان) ---
            if (mainCount > 0 && boardCount > 0) {
                // خط رأسي نزولي من آخر عقدة رئيسية
                el('line', {
                    x1: MAIN_X, y1: branchY,
                    x2: MAIN_X, y2: boardRowY,
                    stroke: '#8b5cf6', 'stroke-width': 3
                }, svg);
                // خط أفقي من المسار الرئيسي إلى بداية مسار الديوان
                el('line', {
                    x1: MAIN_X, y1: boardRowY,
                    x2: BOARD_X, y2: boardRowY,
                    stroke: '#8b5cf6', 'stroke-width': 3
                }, svg);
            }

            // --- رسم خط مسار الديوان (أفقي) ---
            if (boardCount > 1) {
                el('line', {
                    x1: BOARD_X, y1: boardRowY,
                    x2: BOARD_X + (boardCount - 1) * STEP_X, y2: boardRowY,
                    stroke: '#8b5cf6', 'stroke-width': 3,
                    'stroke-dasharray': '6,3'
                }, svg);
            }

            // دالة رسم عقدة
            function drawNode(step, cx, cy, trackColor) {
                const col = colorMap[step.color] || '#64748b';
                const isProcessing = step.color === 'blue';

                // ظل
                el('circle', { cx, cy, r: R + 6, fill: col, opacity: '0.15' }, svg);

                // الدائرة الخارجية (حلقة)
                el('circle', { cx, cy, r: R + 3, fill: 'none', stroke: col, 'stroke-width': 2 }, svg);

                // الدائرة الداخلية
                const circle = el('circle', {
                    cx, cy, r: R,
                    fill: col,
                    style: isProcessing ? 'animation: pulse-proc 2s infinite;' : '',
                    cursor: 'pointer'
                }, svg);

                // أيقونة (نص)
                const iconMap = {
                    'fas fa-check-circle':  '✓',
                    'fas fa-times-circle':  '✕',
                    'fas fa-user-check':    '◉',
                    'fas fa-clock':         '◷',
                };
                const iconChar = iconMap[step.icon] || '●';
                const txt = el('text', {
                    x: cx, y: cy + 5,
                    'text-anchor': 'middle',
                    fill: 'white',
                    'font-size': '14',
                    'font-family': 'Cairo, sans-serif',
                    'pointer-events': 'none'
                }, svg);
                txt.textContent = iconChar;

                // تسمية الدور (أسفل/يمين العقدة)
                const labelX = cx + R + 8;
                const labelY = cy - 6;
                const lbl = el('text', {
                    x: labelX, y: labelY,
                    'text-anchor': 'start',
                    fill: '#e2e8f0',
                    'font-size': '12',
                    'font-family': 'Cairo, sans-serif',
                    'font-weight': '600',
                    'pointer-events': 'none'
                }, svg);
                lbl.textContent = step.role_name;

                // اسم المستخدم (سطر ثانٍ)
                if (step.current_user_name) {
                    const uLbl = el('text', {
                        x: labelX, y: labelY + 16,
                        'text-anchor': 'start',
                        fill: '#94a3b8',
                        'font-size': '10',
                        'font-family': 'Cairo, sans-serif',
                        'pointer-events': 'none'
                    }, svg);
                    uLbl.textContent = step.current_user_name;
                }

                // Tooltip on hover
                const showTip = (e) => {
                    const statusText = { green:'مكتمل', blue:'قيد المعالجة', red:'مرفوض', orange:'قيد التعبئة', gray:'بانتظار' };
                    tip.innerHTML = `
                        <div class="tooltip-role">${step.role_name}</div>
                        ${step.current_user_name ? `<div class="tooltip-user"><i class="fas fa-user" style="font-size:10px"></i> ${step.current_user_name}</div>` : ''}
                        ${step.current_user_department ? `<div class="tooltip-user"><i class="fas fa-building" style="font-size:10px"></i> ${step.current_user_department}</div>` : ''}
                        ${step.date ? `<div class="tooltip-date">${step.date}</div>` : ''}
                        <span class="tooltip-status ${step.color}">${statusText[step.color] || step.status_text}</span>
                    `;
                    tip.classList.add('visible');
                    moveTip(e);
                };
                const moveTip = (e) => {
                    tip.style.left = (e.clientX + 14) + 'px';
                    tip.style.top  = (e.clientY - 10) + 'px';
                };
                const hideTip = () => tip.classList.remove('visible');

                circle.addEventListener('mouseenter', showTip);
                circle.addEventListener('mousemove',  moveTip);
                circle.addEventListener('mouseleave', hideTip);

                // النقر: فتح تذكير للعقدة الزرقاء
                if (step.color === 'blue' && step.current_user_id && step.current_user_name) {
                    circle.style.cursor = 'pointer';
                    circle.addEventListener('click', () => {
                        openReminderModal(step.current_user_id, step.current_user_name);
                    });
                }
            }

            // --- رسم عقد المسار الرئيسي ---
            mainTrack.forEach((step, i) => {
                const cx = MAIN_X;
                const cy = PAD_Y + i * STEP_Y;
                drawNode(step, cx, cy, '#3b82f6');
            });

            // --- رسم عقد مسار الديوان ---
            boardTrack.forEach((step, i) => {
                const cx = BOARD_X + i * STEP_X;
                const cy = boardRowY;
                drawNode(step, cx, cy, '#8b5cf6');
            });

            // تسميات المسارات
            if (mainCount > 0) {
                const lbl = el('text', {
                    x: MAIN_X - R - 5, y: PAD_Y - 12,
                    'text-anchor': 'middle',
                    fill: '#60a5fa',
                    'font-size': '11',
                    'font-family': 'Cairo, sans-serif',
                    'font-weight': '700'
                }, svg);
                lbl.textContent = 'main';
            }
            if (boardCount > 0) {
                const lbl = el('text', {
                    x: BOARD_X, y: boardRowY - R - 12,
                    'text-anchor': 'middle',
                    fill: '#a78bfa',
                    'font-size': '11',
                    'font-family': 'Cairo, sans-serif',
                    'font-weight': '700'
                }, svg);
                lbl.textContent = 'diwan';
            }
        });

        function goBack() {
            window.history.back();
        }
    </script>
</body>

</html>