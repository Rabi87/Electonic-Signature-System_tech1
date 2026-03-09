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
    <title>تتبع مسار المستند</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
/* =============================================
   تتبع المستند - تصميم احترافي داكن
   ============================================= */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg-deep:      #07091a;
    --bg-card:      #0e1228;
    --bg-card2:     #121630;
    --border:       rgba(99,102,241,0.2);
    --border-glow:  rgba(99,102,241,0.5);
    --accent:       #6366f1;
    --accent2:      #818cf8;
    --green:        #10b981;
    --blue:         #3b82f6;
    --red:          #ef4444;
    --orange:       #f59e0b;
    --gray:         #6b7280;
    --text-primary: #f1f5f9;
    --text-muted:   #94a3b8;
    --text-dim:     #475569;
    --shadow-glow:  0 0 30px rgba(99,102,241,0.15);
}

html, body {
    min-height: 100vh;
    font-family: 'Cairo', sans-serif;
    background: var(--bg-deep);
    color: var(--text-primary);
    direction: rtl;
}

/* === خلفية الصفحة === */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 80% 50% at 20% 0%, rgba(99,102,241,0.12) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 80% 100%, rgba(16,185,129,0.08) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
}

.page-wrap {
    position: relative;
    z-index: 1;
    max-width: 1100px;
    margin: 0 auto;
    padding: 24px 20px 48px;
}

/* ===========================
   بطاقة رأس المستند
   =========================== */
.doc-header-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 24px 28px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    box-shadow: var(--shadow-glow);
    position: relative;
    overflow: hidden;
}

.doc-header-card::before {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 200px; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(99,102,241,0.06));
    pointer-events: none;
}

.doc-header-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.doc-icon-wrap {
    width: 52px; height: 52px;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(99,102,241,0.3), rgba(99,102,241,0.1));
    border: 1px solid rgba(99,102,241,0.4);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; color: var(--accent2);
    flex-shrink: 0;
}

.doc-meta h1 {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 4px;
    line-height: 1.3;
}

.doc-meta-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.doc-num-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 8px;
    background: rgba(99,102,241,0.15);
    border: 1px solid rgba(99,102,241,0.3);
    font-size: 0.78rem;
    color: var(--accent2);
    font-weight: 600;
}

.doc-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 700;
}

.doc-status-badge.completed { background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.35); color: #34d399; }
.doc-status-badge.pending   { background: rgba(107,114,128,0.15); border: 1px solid rgba(107,114,128,0.35); color: #9ca3af; }
.doc-status-badge.review    { background: rgba(59,130,246,0.15);  border: 1px solid rgba(59,130,246,0.35);  color: #60a5fa; }
.doc-status-badge.rejected  { background: rgba(239,68,68,0.15);   border: 1px solid rgba(239,68,68,0.35);   color: #f87171; }
.doc-status-badge.warning   { background: rgba(245,158,11,0.15);  border: 1px solid rgba(245,158,11,0.35);  color: #fbbf24; }

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: rgba(99,102,241,0.12);
    border: 1px solid rgba(99,102,241,0.3);
    border-radius: 12px;
    color: var(--accent2);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.25s ease;
    white-space: nowrap;
    font-family: 'Cairo', sans-serif;
    cursor: pointer;
}

.back-btn:hover {
    background: rgba(99,102,241,0.22);
    border-color: rgba(99,102,241,0.6);
    color: #fff;
    transform: translateX(3px);
    text-decoration: none;
}

/* ===========================
   بطاقة مفتاح الألوان
   =========================== */
.legend-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 24px;
    flex-wrap: wrap;
    padding: 14px 20px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
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
    width: 10px; height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.legend-dot.blue   { background: var(--blue);   box-shadow: 0 0 6px var(--blue); }
.legend-dot.green  { background: var(--green);  box-shadow: 0 0 6px var(--green); }
.legend-dot.red    { background: var(--red);    box-shadow: 0 0 6px var(--red); }
.legend-dot.orange { background: var(--orange); box-shadow: 0 0 6px var(--orange); }
.legend-dot.gray   { background: var(--gray); }

/* ===========================
   بطاقة المسار الرئيسية
   =========================== */
.track-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 32px 28px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-glow);
    overflow: hidden;
    position: relative;
}

.track-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 36px;
}

.track-card-header-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: linear-gradient(135deg, rgba(99,102,241,0.3), rgba(99,102,241,0.1));
    border: 1px solid rgba(99,102,241,0.35);
    display: flex; align-items: center; justify-content: center;
    color: var(--accent2);
    font-size: 0.95rem;
}

.track-card-header h2 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.track-card-header span {
    font-size: 0.78rem;
    color: var(--text-dim);
    display: block;
    margin-top: 1px;
}

/* ===========================
   القسم الرأسي (موظف - رئيس قسم - مدير)
   =========================== */
.section-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* عرض رأسي - المستوى الأدنى */
.vertical-flow {
    display: flex;
    flex-direction: column;
    gap: 0;
    position: relative;
    margin-bottom: 32px;
}

.v-step {
    display: flex;
    align-items: stretch;
    gap: 0;
    position: relative;
    animation: stepFadeIn 0.5s ease both;
}

@keyframes stepFadeIn {
    from { opacity: 0; transform: translateX(20px); }
    to   { opacity: 1; transform: translateX(0); }
}

/* الخط الرأسي */
.v-step-connector {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 48px;
    flex-shrink: 0;
}

.v-step-dot {
    width: 44px; height: 44px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    border: 2px solid;
    position: relative;
    z-index: 2;
    flex-shrink: 0;
    cursor: pointer;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.v-step-dot:hover { transform: scale(1.12); }

.v-step-dot.green  { background: rgba(16,185,129,0.15);  border-color: var(--green);  color: var(--green);  box-shadow: 0 0 18px rgba(16,185,129,0.25); }
.v-step-dot.blue   { background: rgba(59,130,246,0.15);  border-color: var(--blue);   color: var(--blue);   box-shadow: 0 0 18px rgba(59,130,246,0.35); animation: glow-blue 2s ease-in-out infinite; }
.v-step-dot.red    { background: rgba(239,68,68,0.15);   border-color: var(--red);    color: var(--red);    box-shadow: 0 0 18px rgba(239,68,68,0.25); }
.v-step-dot.orange { background: rgba(245,158,11,0.15);  border-color: var(--orange); color: var(--orange); box-shadow: 0 0 18px rgba(245,158,11,0.25); }
.v-step-dot.gray   { background: rgba(107,114,128,0.1);  border-color: var(--gray);   color: var(--gray); }

@keyframes glow-blue {
    0%,100% { box-shadow: 0 0 14px rgba(59,130,246,0.4); }
    50%      { box-shadow: 0 0 28px rgba(59,130,246,0.7), 0 0 50px rgba(59,130,246,0.25); }
}

.v-step-line {
    width: 2px;
    flex: 1;
    min-height: 28px;
    background: linear-gradient(to bottom, var(--border-glow), var(--border));
    margin: 4px auto;
}

/* بطاقة المعلومات الرأسية */
.v-step-card {
    flex: 1;
    background: var(--bg-card2);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px 18px;
    margin-right: 14px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    transition: border-color 0.25s ease, box-shadow 0.25s ease;
    cursor: pointer;
}

.v-step-card:hover {
    border-color: var(--border-glow);
    box-shadow: 0 0 20px rgba(99,102,241,0.1);
}

.v-step-card.active-step {
    border-color: rgba(59,130,246,0.5);
    background: rgba(59,130,246,0.06);
    box-shadow: 0 0 20px rgba(59,130,246,0.12);
}

.v-step-role {
    font-weight: 700;
    font-size: 0.9rem;
    color: var(--text-primary);
    margin-bottom: 3px;
}

.v-step-user {
    font-size: 0.8rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 5px;
}

.v-step-user i { font-size: 0.72rem; color: var(--text-dim); }

.v-step-dept {
    font-size: 0.75rem;
    color: var(--text-dim);
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 2px;
}

.v-step-right {
    text-align: left;
    flex-shrink: 0;
}

.v-step-status-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.73rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.chip-green  { background: rgba(16,185,129,0.15);  color: #34d399;  border: 1px solid rgba(16,185,129,0.3); }
.chip-blue   { background: rgba(59,130,246,0.15);  color: #60a5fa;  border: 1px solid rgba(59,130,246,0.3); }
.chip-red    { background: rgba(239,68,68,0.15);   color: #f87171;  border: 1px solid rgba(239,68,68,0.3); }
.chip-orange { background: rgba(245,158,11,0.15);  color: #fbbf24;  border: 1px solid rgba(245,158,11,0.3); }
.chip-gray   { background: rgba(107,114,128,0.1);  color: #9ca3af;  border: 1px solid rgba(107,114,128,0.25); }

.v-step-date {
    font-size: 0.72rem;
    color: var(--text-dim);
    direction: ltr;
    text-align: right;
}

/* ===========================
   سهم الانتقال للمستويات العليا
   =========================== */
.flow-arrow {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin: 12px 0 24px;
    color: var(--text-dim);
    font-size: 0.8rem;
}

.flow-arrow-line {
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
}

.flow-arrow-icon {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(99,102,241,0.12);
    border: 1px solid var(--border-glow);
    display: flex; align-items: center; justify-content: center;
    color: var(--accent2);
    font-size: 0.9rem;
    animation: arrowPulse 2s ease-in-out infinite;
}

@keyframes arrowPulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(99,102,241,0.3); }
    50%      { box-shadow: 0 0 0 8px rgba(99,102,241,0); }
}

/* ===========================
   العرض الأفقي - المستويات العليا
   =========================== */
.horizontal-flow {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    gap: 0;
    flex-wrap: wrap;
    position: relative;
}

.h-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    animation: stepFadeIn 0.5s ease both;
}

.h-step-connector {
    display: flex;
    align-items: center;
    padding-top: 22px;
    width: 48px;
    flex-shrink: 0;
}

.h-connector-line {
    width: 100%;
    height: 2px;
    border-radius: 1px;
}

.h-connector-line.done    { background: var(--green); }
.h-connector-line.active  { background: linear-gradient(90deg, var(--green), var(--blue)); }
.h-connector-line.pending { background: var(--border); }

.h-step-dot {
    width: 48px; height: 48px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    border: 2px solid;
    cursor: pointer;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    position: relative;
    flex-shrink: 0;
}

.h-step-dot:hover { transform: scale(1.12); }

.h-step-dot.green  { background: rgba(16,185,129,0.15);  border-color: var(--green);  color: var(--green);  box-shadow: 0 0 18px rgba(16,185,129,0.3); }
.h-step-dot.blue   { background: rgba(59,130,246,0.15);  border-color: var(--blue);   color: var(--blue);   animation: glow-blue 2s ease-in-out infinite; }
.h-step-dot.red    { background: rgba(239,68,68,0.15);   border-color: var(--red);    color: var(--red);    box-shadow: 0 0 18px rgba(239,68,68,0.3); }
.h-step-dot.orange { background: rgba(245,158,11,0.15);  border-color: var(--orange); color: var(--orange); box-shadow: 0 0 18px rgba(245,158,11,0.3); }
.h-step-dot.gray   { background: rgba(107,114,128,0.1);  border-color: var(--gray);   color: var(--gray); }

.h-step-info {
    margin-top: 12px;
    text-align: center;
    width: 100px;
}

.h-step-role {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 4px;
    line-height: 1.2;
}

.h-step-user-name {
    font-size: 0.73rem;
    color: var(--text-muted);
    margin-bottom: 4px;
    line-height: 1.3;
}

.h-step-status-chip {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 0.68rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.h-step-date {
    font-size: 0.68rem;
    color: var(--text-dim);
    direction: ltr;
}

/* ===========================
   بطاقة حامل المستند الحالي
   =========================== */
.current-holder-bar {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    background: rgba(59,130,246,0.08);
    border: 1px solid rgba(59,130,246,0.3);
    border-radius: 14px;
    margin-top: 28px;
}

.holder-pulse {
    width: 10px; height: 10px;
    border-radius: 50%;
    background: var(--blue);
    box-shadow: 0 0 0 0 rgba(59,130,246,0.4);
    animation: holderPulse 1.5s ease-in-out infinite;
    flex-shrink: 0;
}

@keyframes holderPulse {
    0%   { box-shadow: 0 0 0 0 rgba(59,130,246,0.5); }
    70%  { box-shadow: 0 0 0 8px rgba(59,130,246,0); }
    100% { box-shadow: 0 0 0 0 rgba(59,130,246,0); }
}

.holder-text {
    font-size: 0.85rem;
    color: #93c5fd;
}

.holder-name {
    font-weight: 700;
    color: #bfdbfe;
}

/* ===========================
   قسم التواقيع
   =========================== */
.sigs-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 28px;
    box-shadow: var(--shadow-glow);
}

.sigs-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}

.sigs-header-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: linear-gradient(135deg, rgba(16,185,129,0.25), rgba(16,185,129,0.08));
    border: 1px solid rgba(16,185,129,0.3);
    display: flex; align-items: center; justify-content: center;
    color: #34d399;
    font-size: 0.95rem;
}

.sigs-header h2 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.sigs-count {
    margin-right: auto;
    background: rgba(16,185,129,0.12);
    border: 1px solid rgba(16,185,129,0.25);
    color: #34d399;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
}

.sigs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 14px;
}

.sig-card {
    display: flex;
    align-items: center;
    gap: 14px;
    background: var(--bg-card2);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px 16px;
    transition: border-color 0.25s ease, box-shadow 0.25s ease, transform 0.25s ease;
    animation: stepFadeIn 0.4s ease both;
}

.sig-card:hover {
    border-color: rgba(16,185,129,0.4);
    box-shadow: 0 0 20px rgba(16,185,129,0.08);
    transform: translateY(-2px);
}

.sig-avatar {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #059669, #10b981);
    display: flex; align-items: center; justify-content: center;
    color: white;
    font-size: 1.1rem;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 0 14px rgba(16,185,129,0.3);
}

.sig-name {
    font-weight: 700;
    font-size: 0.88rem;
    color: var(--text-primary);
    margin-bottom: 3px;
}

.sig-role {
    font-size: 0.78rem;
    color: var(--text-muted);
    margin-bottom: 2px;
}

.sig-dept {
    font-size: 0.73rem;
    color: var(--text-dim);
    margin-bottom: 3px;
}

.sig-date {
    font-size: 0.72rem;
    color: var(--text-dim);
    display: flex;
    align-items: center;
    gap: 4px;
    direction: ltr;
}

.sig-date i { color: #34d399; }

/* ===========================
   نافذة التذكير
   =========================== */
.reminder-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.75);
    backdrop-filter: blur(6px);
    z-index: 9000;
    align-items: center;
    justify-content: center;
}

.reminder-modal-box {
    background: var(--bg-card);
    border: 1px solid var(--border-glow);
    border-radius: 18px;
    width: 90%;
    max-width: 420px;
    overflow: hidden;
    box-shadow: 0 25px 60px rgba(0,0,0,0.5);
    animation: modalPop 0.35s cubic-bezier(0.34,1.56,0.64,1) both;
}

@keyframes modalPop {
    from { opacity: 0; transform: scale(0.9) translateY(20px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.reminder-modal-head {
    padding: 18px 22px;
    background: linear-gradient(135deg, rgba(59,130,246,0.3), rgba(99,102,241,0.2));
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.reminder-modal-head h4 {
    font-size: 0.95rem;
    font-weight: 700;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-close-x {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    border: none;
    color: #fff;
    cursor: pointer;
    font-size: 1rem;
    display: flex; align-items: center; justify-content: center;
    transition: background 0.2s;
}

.modal-close-x:hover { background: rgba(239,68,68,0.4); }

.reminder-modal-body {
    padding: 22px;
}

.reminder-modal-body p {
    font-size: 0.9rem;
    color: var(--text-muted);
    margin-bottom: 14px;
    line-height: 1.7;
}

.reminder-modal-body strong {
    color: var(--text-primary);
}

.reminder-textarea {
    width: 100%;
    padding: 12px 14px;
    background: var(--bg-deep);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text-primary);
    font-family: 'Cairo', sans-serif;
    font-size: 0.85rem;
    resize: vertical;
    min-height: 80px;
    transition: border-color 0.2s;
}

.reminder-textarea:focus {
    outline: none;
    border-color: var(--accent);
}

.reminder-modal-foot {
    padding: 16px 22px;
    background: rgba(0,0,0,0.2);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.btn-cancel {
    padding: 9px 18px;
    background: rgba(107,114,128,0.15);
    border: 1px solid rgba(107,114,128,0.3);
    border-radius: 10px;
    color: var(--text-muted);
    font-family: 'Cairo', sans-serif;
    font-size: 0.83rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-cancel:hover { background: rgba(107,114,128,0.25); color: var(--text-primary); }

.btn-send {
    padding: 9px 20px;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    border: none;
    border-radius: 10px;
    color: #fff;
    font-family: 'Cairo', sans-serif;
    font-size: 0.83rem;
    font-weight: 700;
    cursor: pointer;
    display: flex; align-items: center; gap: 6px;
    transition: all 0.2s;
}

.btn-send:hover {
    box-shadow: 0 4px 16px rgba(99,102,241,0.45);
    transform: translateY(-1px);
}

/* متجاوب */
@media (max-width: 640px) {
    .page-wrap { padding: 14px 12px 40px; }
    .doc-header-card { padding: 16px; }
    .track-card { padding: 20px 14px; }
    .horizontal-flow { gap: 0; }
    .h-step-info { width: 80px; }
    .h-step-info .h-step-role { font-size: 0.75rem; }
    .v-step-card { padding: 12px 14px; }
}
    </style>
</head>
<body>
<div class="page-wrap">

    <!-- رأس المستند -->
    <div class="doc-header-card">
        <div class="doc-header-left">
            <div class="doc-icon-wrap"><i class="fas fa-file-alt"></i></div>
            <div class="doc-meta">
                <h1><?php echo htmlspecialchars($document['title']); ?></h1>
                <div class="doc-meta-row">
                    <span class="doc-num-badge"><i class="fas fa-hashtag"></i> <?php echo $document_display_number; ?></span>
                    <?php
                    $sc = $document['current_status'];
                    $sl = ['draft'=>['مسودة','pending'],'pending'=>['قيد الانتظار','pending'],'under_review'=>['قيد المراجعة','review'],'completed'=>['مكتمل','completed'],'partially_signed'=>['قيد التعبئة','warning'],'rejected'=>['مرفوض','rejected'],'partially_completed'=>['مكتمل جزئياً','warning'],'completion_required'=>['استكمال','warning'],'approved'=>['موافق عليها','completed']];
                    $si = $sl[$sc] ?? [$sc, 'pending'];
                    $sicons = ['completed'=>'fa-check-circle','pending'=>'fa-clock','review'=>'fa-sync','rejected'=>'fa-times-circle','warning'=>'fa-exclamation-circle'];
                    ?>
                    <span class="doc-status-badge <?php echo $si[1]; ?>">
                        <i class="fas <?php echo $sicons[$si[1]] ?? 'fa-circle'; ?>"></i>
                        <?php echo $si[0]; ?>
                    </span>
                </div>
            </div>
        </div>
        <a href="javascript:history.back()" class="back-btn">
            <i class="fas fa-arrow-right"></i> العودة
        </a>
    </div>

    <!-- مفتاح الألوان -->
    <div class="legend-bar">
        <div class="legend-item"><div class="legend-dot blue"></div> قيد المعالجة</div>
        <div class="legend-item"><div class="legend-dot green"></div> مكتمل</div>
        <div class="legend-item"><div class="legend-dot red"></div> مرفوض</div>
        <div class="legend-item"><div class="legend-dot orange"></div> قيد التعبئة</div>
        <div class="legend-item"><div class="legend-dot gray"></div> بانتظار</div>
    </div>

    <!-- بطاقة المسار -->
    <div class="track-card">
        <div class="track-card-header">
            <div class="track-card-header-icon"><i class="fas fa-project-diagram"></i></div>
            <div>
                <h2>مسار المستند</h2>
                <span>تتبع خطوات المعالجة والموافقة</span>
            </div>
        </div>

        <?php
        /* تحديد الخريطة اللونية */
        $color_map = ['green'=>'chip-green','blue'=>'chip-blue','red'=>'chip-red','orange'=>'chip-orange','gray'=>'chip-gray'];
        $icon_color = ['green'=>'fa-check-circle','blue'=>'fa-user-check','red'=>'fa-times-circle','orange'=>'fa-clock','gray'=>'fa-clock'];

        /* فلترة الأدوار الرأسية */
        $vertical_roles = array_filter($timeline_data, fn($s) => in_array($s['role_name'], ['موظف','رئيس القسم','مدير الدائرة']));
        usort($vertical_roles, fn($a,$b) => (['موظف'=>1,'رئيس القسم'=>2,'مدير الدائرة'=>3][$a['role_name']]??99) <=> (['موظف'=>1,'رئيس القسم'=>2,'مدير الدائرة'=>3][$b['role_name']]??99));

        /* فلترة الأدوار الأفقية */
        $horizontal_roles = array_filter($timeline_data, fn($s) => in_array($s['role_name'], ['الديوان الخاص','الديوان الفرعي','الديوان','مدير المكتب','نائب الرئيس التنفيذي','الرئيس التنفيذي']));
        $horder = ['الديوان الخاص'=>1,'الديوان الفرعي'=>2,'الديوان'=>3,'مدير المكتب'=>4,'نائب الرئيس التنفيذي'=>5,'الرئيس التنفيذي'=>6];
        usort($horizontal_roles, fn($a,$b) => ($horder[$a['role_name']]??99) <=> ($horder[$b['role_name']]??99));
        $horizontal_roles = array_values($horizontal_roles);
        ?>

        <?php if (!empty($vertical_roles)): ?>
        <!-- القسم الرأسي -->
        <div class="section-label"><i class="fas fa-sitemap"></i> المستويات الإدارية الأولى</div>
        <div class="vertical-flow">
            <?php $vi = 0; foreach ($vertical_roles as $step):
                $vi++;
                $isLast = ($vi === count($vertical_roles));
                $chipClass = $color_map[$step['color']] ?? 'chip-gray';
                $icoClass  = $icon_color[$step['color']] ?? 'fa-clock';
            ?>
            <div class="v-step tracking-step" data-user-id="<?php echo $step['current_user_id'] ?? ''; ?>"
                 data-role-id="<?php echo $step['role_id']; ?>"
                 data-is-current="<?php echo $step['is_current'] ? 'true':'false'; ?>"
                 style="animation-delay: <?php echo ($vi-1)*0.1; ?>s">
                <div class="v-step-connector">
                    <div class="v-step-dot <?php echo $step['color']; ?>">
                        <i class="fas <?php echo $icoClass; ?>"></i>
                    </div>
                    <?php if (!$isLast): ?><div class="v-step-line"></div><?php endif; ?>
                </div>
                <div class="v-step-card <?php echo $step['is_current'] ? 'active-step':''; ?>">
                    <div>
                        <div class="v-step-role"><?php echo htmlspecialchars($step['role_name']); ?></div>
                        <?php if (!empty($step['current_user_name'])): ?>
                        <div class="v-step-user"><i class="fas fa-user"></i> <?php echo htmlspecialchars($step['current_user_name']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($step['current_user_department'])): ?>
                        <div class="v-step-dept"><i class="fas fa-building"></i> <?php echo htmlspecialchars($step['current_user_department']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="v-step-right">
                        <div class="v-step-status-chip <?php echo $chipClass; ?>"><i class="fas <?php echo $icoClass; ?>"></i> <?php echo $step['status_text']; ?></div>
                        <?php if (!empty($step['date'])): ?>
                        <div class="v-step-date"><?php echo $step['date']; ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($horizontal_roles)): ?>
        <!-- سهم الانتقال -->
        <div class="flow-arrow">
            <div class="flow-arrow-line"></div>
            <div class="flow-arrow-icon"><i class="fas fa-chevron-down"></i></div>
            <div class="flow-arrow-line"></div>
        </div>

        <!-- القسم الأفقي -->
        <div class="section-label"><i class="fas fa-crown"></i> المستويات القيادية</div>
        <div class="horizontal-flow">
            <?php foreach ($horizontal_roles as $hi => $step):
                $chipClass = $color_map[$step['color']] ?? 'chip-gray';
                $icoClass  = $icon_color[$step['color']] ?? 'fa-clock';
                // تحديد حالة الخط الرابط
                $connClass = 'pending';
                if ($hi > 0) {
                    $prev = $horizontal_roles[$hi-1];
                    if ($prev['color'] === 'green' && $step['color'] === 'green') $connClass = 'done';
                    elseif ($prev['color'] === 'green' && $step['color'] === 'blue') $connClass = 'active';
                }
            ?>
            <?php if ($hi > 0): ?>
            <div class="h-step-connector">
                <div class="h-connector-line <?php echo $connClass; ?>"></div>
            </div>
            <?php endif; ?>

            <div class="h-step tracking-step" data-user-id="<?php echo $step['current_user_id'] ?? ''; ?>"
                 data-role-id="<?php echo $step['role_id']; ?>"
                 data-is-current="<?php echo $step['is_current'] ? 'true':'false'; ?>"
                 style="animation-delay: <?php echo $hi*0.12; ?>s">
                <div class="h-step-dot <?php echo $step['color']; ?>">
                    <i class="fas <?php echo $icoClass; ?>"></i>
                </div>
                <div class="h-step-info">
                    <div class="h-step-role"><?php echo htmlspecialchars($step['role_name']); ?></div>
                    <?php if (!empty($step['current_user_name'])): ?>
                    <div class="h-step-user-name"><?php echo htmlspecialchars($step['current_user_name']); ?></div>
                    <?php endif; ?>
                    <div class="h-step-status-chip <?php echo $chipClass; ?>"><?php echo $step['status_text']; ?></div>
                    <?php if (!empty($step['date'])): ?>
                    <div class="h-step-date"><?php echo $step['date']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($current_holder_info): ?>
        <div class="current-holder-bar">
            <div class="holder-pulse"></div>
            <div class="holder-text">
                المستند حالياً عند:
                <span class="holder-name"> <?php echo htmlspecialchars($current_holder_info['full_name']); ?></span>
                <span style="color:var(--text-dim); font-size:0.8rem"> — <?php echo htmlspecialchars($current_holder_info['role_name'] ?? ''); ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- قسم التواقيع -->
    <?php if (!empty($signatures)): ?>
    <div class="sigs-card">
        <div class="sigs-header">
            <div class="sigs-header-icon"><i class="fas fa-signature"></i></div>
            <h2>التواقيع</h2>
            <span class="sigs-count"><?php echo count($signatures); ?> توقيع</span>
        </div>
        <div class="sigs-grid">
            <?php foreach ($signatures as $si => $sig): ?>
            <div class="sig-card" style="animation-delay:<?php echo $si*0.07; ?>s">
                <div class="sig-avatar"><?php echo mb_substr($sig['full_name'],0,1); ?></div>
                <div>
                    <div class="sig-name"><?php echo htmlspecialchars($sig['full_name']); ?></div>
                    <div class="sig-role"><?php echo htmlspecialchars($sig['role_name']); ?></div>
                    <?php if (!empty($sig['department_name'])): ?>
                    <div class="sig-dept"><i class="fas fa-building"></i> <?php echo htmlspecialchars($sig['department_name']); ?></div>
                    <?php endif; ?>
                    <div class="sig-date"><i class="fas fa-check-circle"></i> <?php echo date('Y-m-d H:i', strtotime($sig['signed_at'])); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- نافذة التذكير -->
<div id="reminderModal" class="reminder-modal-overlay">
    <div class="reminder-modal-box">
        <div class="reminder-modal-head">
            <h4><i class="fas fa-bell"></i> إرسال تذكير</h4>
            <button class="modal-close-x" onclick="closeReminderModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="reminder-modal-body">
            <p>هل تريد إرسال تذكير إلى <strong id="reminderUserName">...</strong> لتفقد هذا المستند؟</p>
            <input type="hidden" id="reminderTargetUserId">
            <input type="hidden" id="reminderDocumentId" value="<?php echo $document_id; ?>">
            <textarea id="reminderMessage" class="reminder-textarea" placeholder="أضف رسالة شخصية (اختياري)..." rows="3"></textarea>
        </div>
        <div class="reminder-modal-foot">
            <button class="btn-cancel" onclick="closeReminderModal()">إلغاء</button>
            <button class="btn-send" onclick="sendReminder()"><i class="fas fa-paper-plane"></i> إرسال</button>
        </div>
    </div>
</div>

<script>
function openReminderModal(userId, userName) {
    document.getElementById('reminderTargetUserId').value = userId;
    document.getElementById('reminderUserName').textContent = userName;
    document.getElementById('reminderModal').style.display = 'flex';
}

function closeReminderModal() {
    document.getElementById('reminderModal').style.display = 'none';
}

function sendReminder() {
    const targetUserId  = document.getElementById('reminderTargetUserId').value;
    const documentId    = document.getElementById('reminderDocumentId').value;
    const message       = document.getElementById('reminderMessage').value;
    fetch('notification_fix.php?action=create_reminder', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ document_id: documentId, target_user_id: targetUserId, custom_message: message })
    })
    .then(r => r.json())
    .then(data => { alert(data.success ? '✅ تم إرسال التذكير بنجاح' : '❌ ' + data.message); if (data.success) closeReminderModal(); })
    .catch(() => alert('حدث خطأ أثناء إرسال التذكير'));
}

document.addEventListener('DOMContentLoaded', function() {
    /* تفعيل النقر على الدوائر الزرقاء */
    document.querySelectorAll('.tracking-step').forEach(step => {
        const dot = step.querySelector('.v-step-dot, .h-step-dot');
        if (!dot) return;
        const isCurrent = step.dataset.isCurrent === 'true';
        if (isCurrent) {
            dot.style.cursor = 'pointer';
            dot.title = 'انقر لإرسال تذكير';
            dot.addEventListener('click', function() {
                const userId   = step.dataset.userId;
                const nameEl   = step.querySelector('.v-step-user, .h-step-user-name');
                const userName = nameEl ? nameEl.textContent.trim() : 'المستخدم';
                if (userId) openReminderModal(userId, userName);
                else {
                    const id = prompt('أدخل معرف المستخدم:');
                    if (id) openReminderModal(id, userName);
                }
            });
        }
    });

    /* إغلاق نافذة التذكير بالنقر خارجها */
    document.getElementById('reminderModal').addEventListener('click', function(e) {
        if (e.target === this) closeReminderModal();
    });

    /* تمرير تلقائي للخطوة النشطة */
    const active = document.querySelector('.v-step-dot.blue, .h-step-dot.blue');
    if (active) setTimeout(() => active.scrollIntoView({ behavior: 'smooth', block: 'center' }), 600);
});
</script>
</body>
</html>
