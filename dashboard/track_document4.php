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

// ═══════════════════════════════════════════════════════
//  جلب أعضاء الإدارة مع ترتيبهم التسلسلي في مخطط النجمة
//  الديوان الخاص يرسل لـ: موظف → رئيس قسم → مدير دائرة
//  (كل واحد على حدة - لا خطوط بينهم)
// ═══════════════════════════════════════════════════════
$dept_members_query = "
    SELECT
        u.id AS user_id,
        u.full_name,
        r.role_name,
        r.id AS role_id,
        dep.id AS dept_id,
        dep.name AS dept_name,
        COALESCE(dus.status, 'pending') AS status,
        dw.action_date AS workflow_date,
        COALESCE(dus.updated_at, dw.action_date) AS display_date
    FROM document_workflow dw
    JOIN users u        ON dw.to_user_id  = u.id
    JOIN roles r        ON u.role_id      = r.id
    LEFT JOIN departments dep ON u.department_id = dep.id
    LEFT JOIN document_user_status dus
           ON dus.document_id = dw.document_id AND dus.user_id = u.id
    WHERE dw.document_id = :doc_id
      AND r.role_name IN ('employee','section_manager','department_manager')
    GROUP BY u.id
    UNION
    SELECT
        u.id AS user_id,
        u.full_name,
        r.role_name,
        r.id AS role_id,
        dep.id AS dept_id,
        dep.name AS dept_name,
        'pending' AS status,
        d.created_at AS workflow_date,
        d.created_at AS display_date
    FROM documents d
    JOIN users u   ON d.created_by = u.id
    JOIN roles r   ON u.role_id    = r.id
    LEFT JOIN departments dep ON u.department_id = dep.id
    WHERE d.id = :doc_id2
      AND r.role_name IN ('employee','section_manager','department_manager')
      AND NOT EXISTS (
          SELECT 1 FROM document_workflow dw2
          WHERE dw2.document_id = d.id AND dw2.to_user_id = u.id
      )
    ORDER BY workflow_date ASC
";
$st = $db->prepare($dept_members_query);
$st->execute([':doc_id' => $document_id, ':doc_id2' => $document_id]);
$dept_members_raw = $st->fetchAll(PDO::FETCH_ASSOC);

// ترتيب تسلسل الأدوار داخل الإدارة للتراسل مع الديوان الخاص
$role_seq = ['employee' => 1, 'section_manager' => 2, 'department_manager' => 3];
$role_labels_dept = [
    'employee' => 'موظف',
    'section_manager' => 'رئيس القسم',
    'department_manager' => 'مدير الدائرة'
];

// تجميع حسب إدارة، مرتبين تسلسلياً
$dept_groups = [];
foreach ($dept_members_raw as $m) {
    $did   = $m['dept_id'] ?: 'none';
    $dname = $m['dept_name'] ?: 'بدون إدارة';
    if (!isset($dept_groups[$did]))
        $dept_groups[$did] = ['id' => $did, 'name' => $dname, 'members' => []];

    $is_cur = ($document['current_holder_id'] == $m['user_id']);
    if ($is_cur) {
        $col = 'blue'; $stxt = 'قيد المعالجة';
    } else {
        switch ($m['status']) {
            case 'completed': case 'approved':
                $col = 'green'; $stxt = 'مكتمل'; break;
            case 'rejected':
                $col = 'red'; $stxt = 'مرفوض'; break;
            case 'partially_signed': case 'partially_completed':
                $col = 'orange'; $stxt = 'قيد التعبئة'; break;
            default:
                $col = 'gray'; $stxt = 'بانتظار';
        }
    }

    $dept_groups[$did]['members'][] = [
        'user_id'     => $m['user_id'],
        'name'        => $m['full_name'],
        'role'        => $role_labels_dept[$m['role_name']] ?? $m['role_name'],
        'role_name'   => $m['role_name'],
        'seq'         => $role_seq[$m['role_name']] ?? 9,
        'color'       => $col,
        'status_text' => $stxt,
        'date'        => $m['display_date'] ? date('Y-m-d H:i', strtotime($m['display_date'])) : '',
        'is_current'  => $is_cur,
    ];
}

// ترتيب أعضاء كل إدارة: موظف → رئيس قسم → مدير دائرة
foreach ($dept_groups as &$dg)
    usort($dg['members'], fn($a, $b) => $a['seq'] <=> $b['seq']);
unset($dg);
$dept_groups = array_values($dept_groups);

// ═══════════════════════════════════════════════════════
//  جلب أدوار الديوان بالترتيب التسلسلي
//  private_board → sub_board → board → ...
// ═══════════════════════════════════════════════════════
$board_query = "
    SELECT
        u.id AS user_id, u.full_name,
        r.role_name, r.id AS role_id,
        COALESCE(dus.status, 'pending') AS status,
        COALESCE(dus.updated_at, dw.action_date) AS display_date
    FROM document_workflow dw
    JOIN users u ON dw.to_user_id = u.id
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN document_user_status dus
           ON dus.document_id = dw.document_id AND dus.user_id = u.id
    WHERE dw.document_id = :doc_id
      AND r.role_name IN ('private_board','sub_board','board','office_manager','deputy_ceo','ceo','admin')
    GROUP BY u.id
    UNION
    SELECT
        u.id AS user_id, u.full_name,
        r.role_name, r.id AS role_id,
        'pending' AS status,
        d.created_at AS display_date
    FROM documents d
    JOIN users u ON d.created_by = u.id
    JOIN roles r ON u.role_id = r.id
    WHERE d.id = :doc_id2
      AND r.role_name IN ('private_board','sub_board','board','office_manager','deputy_ceo','ceo','admin')
      AND NOT EXISTS (
          SELECT 1 FROM document_workflow dw2
          WHERE dw2.document_id = d.id AND dw2.to_user_id = u.id
      )
";
$st = $db->prepare($board_query);
$st->execute([':doc_id' => $document_id, ':doc_id2' => $document_id]);
$board_raw = $st->fetchAll(PDO::FETCH_ASSOC);

$board_seq = [
    'private_board' => 1, 'sub_board' => 2, 'board' => 3,
    'office_manager' => 4, 'deputy_ceo' => 5, 'ceo' => 6, 'admin' => 7
];
$board_labels = [
    'private_board' => 'الديوان الخاص', 'sub_board' => 'الديوان الفرعي',
    'board' => 'الديوان', 'office_manager' => 'مدير المكتب',
    'deputy_ceo' => 'نائب الرئيس', 'ceo' => 'الرئيس التنفيذي', 'admin' => 'المسؤول'
];

$board_by_role = [];
foreach ($board_raw as $bm) {
    $rn = $bm['role_name'];
    if (!isset($board_by_role[$rn])) $board_by_role[$rn] = [];
    $is_cur = ($document['current_holder_id'] == $bm['user_id']);
    if ($is_cur) {
        $col = 'blue'; $stxt = 'قيد المعالجة';
    } else {
        switch ($bm['status']) {
            case 'completed': case 'approved': $col = 'green';  $stxt = 'مكتمل';        break;
            case 'rejected':                   $col = 'red';    $stxt = 'مرفوض';        break;
            case 'partially_signed': case 'partially_completed': $col = 'orange'; $stxt = 'قيد التعبئة'; break;
            default: $col = 'gray'; $stxt = 'بانتظار';
        }
    }
    $board_by_role[$rn][] = [
        'user_id' => $bm['user_id'], 'name' => $bm['full_name'],
        'role' => $board_labels[$rn] ?? $rn, 'role_name' => $rn,
        'color' => $col, 'status_text' => $stxt,
        'date' => $bm['display_date'] ? date('Y-m-d H:i', strtotime($bm['display_date'])) : '',
        'is_current' => $is_cur,
    ];
}
uksort($board_by_role, fn($a, $b) => ($board_seq[$a] ?? 99) <=> ($board_seq[$b] ?? 99));

$prio = ['blue' => 0, 'green' => 1, 'orange' => 2, 'red' => 3, 'gray' => 4];
$board_nodes = [];
foreach ($board_by_role as $rn => $members) {
    usort($members, fn($a, $b) => ($prio[$a['color']] ?? 5) <=> ($prio[$b['color']] ?? 5));
    $rep = $members[0];
    $board_nodes[] = [
        'role_name'   => $rep['role'],
        'role_key'    => $rn,
        'users'       => $members,
        'color'       => $rep['color'],
        'status_text' => $rep['status_text'],
        'date'        => $rep['date'],
        'user_id'     => $rep['user_id'],
        'name'        => $rep['name'],
        'seq'         => $board_seq[$rn] ?? 99,
    ];
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تتبع مسار المستند</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<style>
:root{
  --bg:#0d1117; --surf:#161b22; --surf2:#21262d; --surf3:#2d333b;
  --border:#30363d; --border2:#3d444d;
  --text:#cdd9e5; --muted:#768390; --muted2:#545d68;
  --blue:#539bf5; --green:#57ab5a; --red:#e5534b;
  --orange:#c69026; --gray:#545d68; --purple:#b083f0;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);font-family:'Cairo',sans-serif;color:var(--text);min-height:100vh;}
.page{max-width:1400px;margin:0 auto;padding:24px 20px;}

.hdr{background:var(--surf);border:1px solid var(--border);border-radius:12px;padding:20px 26px;margin-bottom:18px;}
.back-btn{display:inline-flex;align-items:center;gap:8px;color:var(--blue);text-decoration:none;
  font-size:.85rem;border:1px solid var(--border);border-radius:6px;padding:5px 14px;
  margin-bottom:14px;transition:background .2s;}
.back-btn:hover{background:var(--surf2);text-decoration:none;color:var(--blue);}
.meta{display:flex;flex-wrap:wrap;gap:12px;}
.pill{display:flex;align-items:center;gap:7px;background:var(--surf2);border:1px solid var(--border);
  border-radius:20px;padding:5px 14px;font-size:.82rem;}
.pill i{color:var(--blue);font-size:.8rem;}
.pill strong{color:#fff;}

.legend{display:flex;flex-wrap:wrap;justify-content:center;gap:18px;margin-bottom:20px;align-items:center;}
.leg{display:flex;align-items:center;gap:6px;font-size:.78rem;color:var(--muted);}
.leg-dot{width:10px;height:10px;border-radius:50%;}

.graph-card{background:var(--surf);border:1px solid var(--border);border-radius:12px;
  padding:32px 20px 32px 28px;margin-bottom:20px;overflow-x:auto;}
.graph-title{font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:28px;
  display:flex;align-items:center;gap:8px;}
.graph-title i{color:var(--blue);}
#svg-track{display:block;overflow:visible;}

#tip{position:fixed;z-index:9999;background:var(--surf);border:1px solid var(--border2);
  border-radius:10px;padding:13px 16px;min-width:185px;max-width:250px;
  pointer-events:none;opacity:0;transition:opacity .15s;
  box-shadow:0 16px 36px rgba(0,0,0,.6);}
#tip.on{opacity:1;}
.tip-role{font-size:.9rem;font-weight:700;color:#fff;margin-bottom:6px;}
.tip-user{font-size:.78rem;color:var(--muted);margin-bottom:3px;display:flex;align-items:center;gap:5px;}
.tip-date{font-size:.72rem;color:var(--muted2);direction:ltr;margin-bottom:7px;}
.tip-badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:700;}
.tip-badge.blue  {background:rgba(83,155,245,.18); color:var(--blue);}
.tip-badge.green {background:rgba(87,171,90,.18);  color:var(--green);}
.tip-badge.red   {background:rgba(229,83,75,.18);  color:var(--red);}
.tip-badge.orange{background:rgba(198,144,38,.18); color:var(--orange);}
.tip-badge.gray  {background:rgba(84,93,104,.3);   color:var(--muted);}
.tip-badge.purple{background:rgba(176,131,240,.18);color:var(--purple);}

.r-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);
  z-index:5000;align-items:center;justify-content:center;}
.r-overlay.on{display:flex;}
.r-box{background:var(--surf);border:1px solid var(--border);border-radius:12px;
  width:90%;max-width:420px;overflow:hidden;}
.r-hd{background:#1d4ed8;color:#fff;padding:14px 20px;
  display:flex;justify-content:space-between;align-items:center;font-weight:700;font-size:.9rem;}
.r-hd button{background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;line-height:1;}
.r-bd{padding:20px;}
.r-bd p{color:var(--text);font-size:.88rem;margin-bottom:12px;}
.r-bd textarea{width:100%;background:var(--surf2);border:1px solid var(--border);
  color:var(--text);border-radius:8px;padding:10px;font-family:'Cairo',sans-serif;
  resize:vertical;font-size:.85rem;}
.r-ft{background:var(--surf2);padding:12px 20px;display:flex;justify-content:flex-end;gap:10px;}
.btn{padding:7px 18px;border-radius:7px;border:none;cursor:pointer;
  font-family:'Cairo',sans-serif;font-size:.85rem;font-weight:600;}
.btn-cancel{background:var(--surf3);color:var(--muted);border:1px solid var(--border);}
.btn-send{background:#1d4ed8;color:#fff;}

.sigs{background:var(--surf);border:1px solid var(--border);border-radius:12px;
  padding:22px 26px;margin-top:18px;}
.sigs-title{font-size:.95rem;font-weight:700;margin-bottom:16px;
  display:flex;align-items:center;gap:8px;}
.sigs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;}
.sig{background:var(--surf2);border:1px solid var(--border);border-radius:9px;
  padding:12px;display:flex;gap:11px;align-items:center;transition:border-color .2s;}
.sig:hover{border-color:var(--blue);}
.sig-av{width:38px;height:38px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(135deg,#57ab5a,#2d6a2f);
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-weight:700;font-size:.95rem;}
.sig-name{font-weight:600;color:#fff;font-size:.88rem;}
.sig-sub{font-size:.73rem;color:var(--muted);line-height:1.7;}

@keyframes pulse{0%{opacity:1;}50%{opacity:.4;}100%{opacity:1;}}
</style>
</head>
<body>
<div class="page">

<!-- Header -->
<div class="hdr">
  <a href="javascript:history.back()" class="back-btn">
    <i class="fas fa-arrow-right"></i> العودة
  </a>
  <div class="meta">
    <div class="pill"><i class="fas fa-file-alt"></i><span>رقم المستند:</span>
      <strong><?php echo $document_display_number; ?></strong></div>
    <div class="pill"><i class="fas fa-tag"></i><span>العنوان:</span>
      <strong><?php echo htmlspecialchars($document['title']); ?></strong></div>
    <div class="pill"><i class="fas fa-circle"></i><span>الحالة:</span>
      <?php
      $slabels = ['draft'=>'مسودة','pending'=>'قيد الانتظار','under_review'=>'قيد المراجعة',
                  'completed'=>'مكتمل','partially_signed'=>'قيد التعبئة','rejected'=>'مرفوض',
                  'partially_completed'=>'مكتملة جزئياً','completion_required'=>'استكمال'];
      ?>
      <strong><?php echo $slabels[$document['current_status']] ?? $document['current_status']; ?></strong>
    </div>
  </div>
</div>

<!-- Legend -->
<div class="legend">
  <div class="leg"><div class="leg-dot" style="background:#539bf5;box-shadow:0 0 5px #539bf5aa;"></div><span>قيد المعالجة</span></div>
  <div class="leg"><div class="leg-dot" style="background:#57ab5a;"></div><span>مكتمل</span></div>
  <div class="leg"><div class="leg-dot" style="background:#e5534b;"></div><span>مرفوض</span></div>
  <div class="leg"><div class="leg-dot" style="background:#c69026;"></div><span>قيد التعبئة</span></div>
  <div class="leg"><div class="leg-dot" style="background:#545d68;"></div><span>بانتظار</span></div>
  <div class="leg"><div class="leg-dot" style="background:#b083f0;"></div><span>مسار الديوان</span></div>
  <!-- سهم إرسال -->
  <div class="leg">
    <svg width="36" height="12" style="overflow:visible">
      <line x1="2" y1="6" x2="26" y2="6" stroke="#539bf5" stroke-width="1.5" stroke-dasharray="4,2"/>
      <polygon points="26,3 34,6 26,9" fill="#539bf5"/>
    </svg>
    <span>إرسال (ديوان خاص)</span>
  </div>
  <!-- سهم استقبال -->
  <div class="leg">
    <svg width="36" height="12" style="overflow:visible">
      <line x1="2" y1="6" x2="26" y2="6" stroke="#57ab5a" stroke-width="1.5" stroke-dasharray="4,2"/>
      <polygon points="26,3 34,6 26,9" fill="#57ab5a"/>
    </svg>
    <span>رد الإدارة</span>
  </div>
</div>

<!-- Graph -->
<div class="graph-card">
  <div class="graph-title"><i class="fas fa-code-branch"></i> مسار تتبع المستند</div>

  <script>
  const DEPT_GROUPS = <?php echo json_encode($dept_groups, JSON_UNESCAPED_UNICODE); ?>;
  const BOARD_NODES = <?php echo json_encode($board_nodes, JSON_UNESCAPED_UNICODE); ?>;
  </script>

  <svg id="svg-track" xmlns="http://www.w3.org/2000/svg"></svg>
</div>

<div id="tip"></div>

<!-- Reminder Modal -->
<div id="rModal" class="r-overlay">
  <div class="r-box">
    <div class="r-hd">
      <span><i class="fas fa-bell"></i> إرسال تذكير</span>
      <button onclick="closeR()">×</button>
    </div>
    <div class="r-bd">
      <p>إرسال تذكير إلى <strong id="rName">...</strong></p>
      <input type="hidden" id="rUid">
      <input type="hidden" id="rDoc" value="<?php echo $document_id; ?>">
      <textarea id="rMsg" rows="3" placeholder="رسالة اختيارية..."></textarea>
    </div>
    <div class="r-ft">
      <button class="btn btn-cancel" onclick="closeR()">إلغاء</button>
      <button class="btn btn-send" onclick="sendR()">
        <i class="fas fa-paper-plane"></i> إرسال
      </button>
    </div>
  </div>
</div>

<?php if (!empty($signatures)): ?>
<div class="sigs">
  <div class="sigs-title"><i class="fas fa-signature" style="color:var(--blue)"></i> التواقيع</div>
  <div class="sigs-grid">
    <?php foreach ($signatures as $sig): ?>
    <div class="sig">
      <div class="sig-av"><?php echo mb_substr($sig['full_name'], 0, 1); ?></div>
      <div>
        <div class="sig-name"><?php echo htmlspecialchars($sig['full_name']); ?></div>
        <div class="sig-sub">
          <?php echo htmlspecialchars($sig['role_name'] ?? ''); ?>
          <?php if (!empty($sig['department_name'])): ?>
            · <?php echo htmlspecialchars($sig['department_name']); ?>
          <?php endif; ?><br>
          <?php echo date('Y-m-d H:i', strtotime($sig['signed_at'])); ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</div>

<script>
// ════════════════════════════════════════════════════════════════
//  STAR TOPOLOGY GRAPH RENDERER
//
//  الفكرة:
//  ┌──────────────────────────────────────────────────────────┐
//  │                                                          │
//  │  [صندوق إدارة 1]                                        │
//  │    موظف أ         ←send→                                │
//  │    رئيس قسم ب     ←send→  ◉ الديوان الخاص ──→ ◉ ──→ ◉ │
//  │    مدير دائرة ج   ←send→                                │
//  │                                                          │
//  │  [صندوق إدارة 2]                                        │
//  │    موظف د         ←send→                                │
//  │    ...                                                   │
//  └──────────────────────────────────────────────────────────┘
//
//  كل عضو في الإدارة ←→ الديوان الخاص  (سهمان منفصلان: إرسال + استقبال)
//  لا يوجد خطوط بين أعضاء الإدارة أنفسهم
//  بعد اكتمال الإدارة: الديوان الخاص → الديوان الفرعي → الديوان → ...
// ════════════════════════════════════════════════════════════════
(function () {
"use strict";

const SVG = document.getElementById('svg-track');
const TIP = document.getElementById('tip');
const NS  = 'http://www.w3.org/2000/svg';

const C = {
  blue:   '#539bf5',
  green:  '#57ab5a',
  red:    '#e5534b',
  orange: '#c69026',
  gray:   '#545d68',
  purple: '#b083f0'
};

// ── Layout ──
const L = {
  padT:      44,   // padding top
  padL:      24,   // padding left (صناديق الإدارة تبدأ من هنا)
  deptW:     220,  // عرض صندوق الإدارة
  deptHdrH:  32,   // ارتفاع رأس الصندوق
  rowH:      44,   // ارتفاع كل عضو داخل الصندوق
  deptGap:   50,   // مسافة رأسية بين الصناديق
  gap:       80,   // مسافة بين حافة الصناديق وحافة دائرة الديوان الخاص
  privR:     26,   // نصف قطر دائرة الديوان الخاص
  boardR:    22,   // نصف قطر دوائر الديوان الأخرى
  boardStep: 155,  // مسافة أفقية بين دوائر الديوان
};

// ── SVG helpers ──
function el(tag, attrs, parent) {
  const e = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
  if (parent) parent.appendChild(e);
  return e;
}

function label(x, y, text, fill, size, anchor, weight, parent) {
  const t = el('text', {
    x, y,
    'text-anchor': anchor || 'middle',
    fill: fill || '#cdd9e5',
    'font-size': size || 11,
    'font-family': 'Cairo, sans-serif',
    'font-weight': weight || '400',
    'pointer-events': 'none'
  }, parent || SVG);
  t.textContent = text;
  return t;
}

// Arrow marker defs
const _markers = {};
function marker(color) {
  const id = 'mk' + color.replace('#', '');
  if (_markers[id]) return 'url(#' + id + ')';
  _markers[id] = true;
  let defs = SVG.querySelector('defs');
  if (!defs) defs = el('defs', {}, SVG);
  const m = el('marker', { id, markerWidth: '8', markerHeight: '8', refX: '6', refY: '4', orient: 'auto' }, defs);
  el('polygon', { points: '0,0 8,4 0,8', fill: color }, m);
  return 'url(#' + id + ')';
}

// ── Draw a curved arrow between two points ──
// offset: lateral curve offset to separate send/recv lanes
function curvedArrow(x1, y1, x2, y2, color, offset) {
  offset = offset || 0;
  const mx = (x1 + x2) / 2 + offset;
  const my = (y1 + y2) / 2;
  const d = `M${x1},${y1} Q${mx},${my} ${x2},${y2}`;
  el('path', {
    d,
    fill: 'none',
    stroke: color,
    'stroke-width': '1.8',
    'stroke-dasharray': '5,3',
    'marker-end': marker(color),
    opacity: '0.9'
  }, SVG);
}

// ── Straight arrow ──
function straightArrow(x1, y1, x2, y2, color) {
  const dx = x2 - x1, dy = y2 - y1;
  const len = Math.sqrt(dx*dx + dy*dy);
  // shorten end by 8px for arrowhead clearance
  const ex = x2 - (dx/len)*8, ey = y2 - (dy/len)*8;
  el('line', { x1, y1, x2: ex, y2: ey, stroke: color, 'stroke-width': '2', 'marker-end': marker(color) }, SVG);
}

// ── Tooltip ──
function showTip(evt, d) {
  const users = d.users || [{ name: d.name, role: d.role, color: d.color, status_text: d.status_text }];
  TIP.innerHTML = `
    <div class="tip-role">${d.role_name || d.role || ''}</div>
    ${users.map(u => `<div class="tip-user">
      <svg width="9" height="9" style="flex-shrink:0"><circle cx="4.5" cy="4.5" r="4.5" fill="${C[u.color]||C.gray}"/></svg>
      ${u.name}<span style="color:var(--muted2);font-size:9px;margin-right:4px">(${u.role||''})</span>
    </div>`).join('')}
    ${d.date ? `<div class="tip-date">${d.date}</div>` : ''}
    <span class="tip-badge ${d.color}">${d.status_text}</span>`;
  TIP.classList.add('on');
  moveTip(evt);
}
function memberTip(evt, m) {
  TIP.innerHTML = `
    <div class="tip-role">${m.name}</div>
    <div class="tip-user"><i class="fas fa-id-badge" style="font-size:9px;color:var(--muted)"></i>${m.role}</div>
    ${m.date ? `<div class="tip-date">${m.date}</div>` : ''}
    <span class="tip-badge ${m.color}">${m.status_text}</span>`;
  TIP.classList.add('on');
  moveTip(evt);
}
function moveTip(e) { TIP.style.left = (e.clientX + 14) + 'px'; TIP.style.top = (e.clientY - 8) + 'px'; }
function hideTip() { TIP.classList.remove('on'); }

// ── Reminder modal ──
window.openR = (uid, uname) => {
  document.getElementById('rUid').value = uid;
  document.getElementById('rName').textContent = uname;
  document.getElementById('rModal').classList.add('on');
};
window.closeR = () => document.getElementById('rModal').classList.remove('on');
document.getElementById('rModal').addEventListener('click', e => { if (e.target.id === 'rModal') closeR(); });
window.sendR = () => {
  const uid = document.getElementById('rUid').value;
  const doc = document.getElementById('rDoc').value;
  const msg = document.getElementById('rMsg').value;
  fetch('notification_fix.php?action=create_reminder', {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ document_id: doc, target_user_id: uid, custom_message: msg })
  }).then(r => r.json())
    .then(d => { alert(d.success ? '✅ تم الإرسال' : '❌ ' + d.message); if (d.success) closeR(); })
    .catch(() => alert('خطأ في الإرسال'));
};

// ── Dept box colours (one per dept index) ──
const DCOLS = ['#388bfd','#57ab5a','#b083f0','#e3b341','#e5534b','#3bc9db','#f0883e'];
function deptCol(i) { return DCOLS[i % DCOLS.length]; }

// ════════════════════════════════════════════════════════════════
//  MAIN DRAW
// ════════════════════════════════════════════════════════════════
function draw() {
  SVG.innerHTML = '';

  // ── compute dept box heights & Y positions ──
  const depts = DEPT_GROUPS.map((dg, i) => ({
    ...dg,
    col:  deptCol(i),
    boxH: L.deptHdrH + dg.members.length * L.rowH
  }));

  let curY = L.padT;
  depts.forEach(d => {
    d.y   = curY;
    d.midY = curY + d.boxH / 2;
    curY  += d.boxH + L.deptGap;
  });
  const totalDeptH = curY - L.deptGap;

  // ── private_board position: vertically centred ──
  const PRIV_X = L.padL + L.deptW + L.gap + L.privR;
  const PRIV_Y = L.padT + (totalDeptH - L.padT) / 2;

  // ── other board nodes: horizontal chain to the left ──
  const privNode  = BOARD_NODES.find(b => b.role_key === 'private_board');
  const restBoard = BOARD_NODES.filter(b => b.role_key !== 'private_board');
  restBoard.forEach((b, i) => {
    b._cx = PRIV_X + L.privR + L.boardStep * (i + 1);
    b._cy = PRIV_Y;
  });

  // ── SVG dimensions ──
  const svgW = Math.max(
    L.padL + L.deptW + L.gap + L.privR * 2 + 80,
    restBoard.length
      ? restBoard[restBoard.length - 1]._cx + L.boardR + 100
      : PRIV_X + 100
  );
  const svgH = Math.max(totalDeptH + 60, PRIV_Y + L.privR + 60);

  SVG.setAttribute('viewBox', `0 0 ${svgW} ${svgH}`);
  SVG.setAttribute('height', svgH);
  SVG.style.minWidth = svgW + 'px';

  // ────────────────────────────────────────────────
  //  1.  خط مسار الديوان الأفقي
  // ────────────────────────────────────────────────
  if (restBoard.length > 0) {
    el('line', {
      x1: PRIV_X + L.privR + 6,
      y1: PRIV_Y,
      x2: restBoard[restBoard.length - 1]._cx - L.boardR - 6,
      y2: PRIV_Y,
      stroke: '#b083f0',
      'stroke-width': '2.5',
      'stroke-dasharray': '7,3'
    }, SVG);
  }

  // ────────────────────────────────────────────────
  //  2.  أسهم بين الديوان الخاص ← → كل عضو إدارة
  //      (سهم إرسال + سهم استقبال منفصلان)
  //      مرتّبان: موظف أولاً، ثم رئيس قسم، ثم مدير دائرة
  // ────────────────────────────────────────────────
  // نحتاج إحداثيات كل عضو داخل صندوقه (نحسبها هنا مبكراً)
  depts.forEach(dg => {
    dg.members.forEach((m, mi) => {
      m._rowY = dg.y + L.deptHdrH + mi * L.rowH + L.rowH / 2;
      m._rowX_right = L.padL + L.deptW;  // الحافة اليمنى للصندوق
    });
  });

  depts.forEach(dg => {
    dg.members.forEach((m, mi) => {
      const memberY = m._rowY;
      const memberX = m._rowX_right + 4;  // نقطة انطلاق من حافة الصندوق

      // الترتيب التسلسلي يعني أن التراسل يحدث واحداً تلو الآخر
      // نفصل أسهم الإرسال والاستقبال بتحريك صغير
      const sendOffset = -12; // يتقوس لأعلى قليلاً (إرسال من الديوان الخاص)
      const recvOffset = +12; // يتقوس لأسفل قليلاً (رد من الإدارة)

      // سهم إرسال: الديوان الخاص → العضو
      curvedArrow(
        PRIV_X - L.privR - 4, PRIV_Y,
        memberX, memberY,
        C.blue, sendOffset
      );

      // سهم استقبال: العضو → الديوان الخاص
      curvedArrow(
        memberX, memberY,
        PRIV_X - L.privR - 4, PRIV_Y,
        C.green, recvOffset
      );

      // رقم الترتيب التسلسلي على السهم
      const numX = (PRIV_X - L.privR - 4 + memberX) / 2;
      const numY = memberY - 8;
      el('circle', { cx: numX, cy: numY - 3, r: 9, fill: '#21262d', stroke: '#30363d', 'stroke-width': '1' }, SVG);
      label(numX, numY + 1, String(mi + 1), '#768390', 9, 'middle', '700');
    });
  });

  // ────────────────────────────────────────────────
  //  3.  صناديق الإدارة
  // ────────────────────────────────────────────────
  depts.forEach((dg, di) => {
    drawDeptBox(dg, di);
  });

  // ────────────────────────────────────────────────
  //  4.  دائرة الديوان الخاص
  // ────────────────────────────────────────────────
  if (privNode) {
    drawBoardCircle(PRIV_X, PRIV_Y, privNode, L.privR, true);
  } else {
    // لو لم يكن في المستند بعد - نرسمه رمادياً
    const ghost = { role_name: 'الديوان الخاص', color: 'gray', status_text: 'بانتظار', date: '', users: [], name: '' };
    drawBoardCircle(PRIV_X, PRIV_Y, ghost, L.privR, true);
  }

  // ────────────────────────────────────────────────
  //  5.  باقي دوائر الديوان + أسهم تسلسلية بينها
  // ────────────────────────────────────────────────
  restBoard.forEach((b, i) => {
    const prevX = i === 0 ? PRIV_X + L.privR : restBoard[i-1]._cx + L.boardR;
    const prevY = PRIV_Y;
    // سهم بين الدوائر
    straightArrow(prevX + 6, prevY, b._cx - L.boardR - 4, b._cy, '#b083f0');
    drawBoardCircle(b._cx, b._cy, b, L.boardR, false);
  });

  // ────────────────────────────────────────────────
  //  6.  تسميات المسارات
  // ────────────────────────────────────────────────
  if (depts.length > 0)
    label(L.padL + L.deptW / 2, L.padT - 16, 'الإدارات', '#768390', 10, 'middle', '700');

  if (privNode || true)
    label(PRIV_X, PRIV_Y - L.privR - 16, 'الديوان الخاص', '#b083f0', 10, 'middle', '700');

  if (restBoard.length > 0)
    label(
      PRIV_X + L.privR + ((restBoard[restBoard.length-1]._cx - PRIV_X - L.privR) / 2),
      PRIV_Y - L.boardR - 18,
      'مسار الديوان', '#b083f0', 10, 'middle', '700'
    );
}

// ════════════════════════════════════════════════════════
//  drawDeptBox — صندوق الإدارة مع الأعضاء التسلسليين
// ════════════════════════════════════════════════════════
function drawDeptBox(dg, di) {
  const x = L.padL, y = dg.y, w = L.deptW, h = dg.boxH;
  const col = dg.col;

  // ظل
  el('rect', { x: x + 3, y: y + 3, width: w, height: h, rx: 9, fill: '#00000055' }, SVG);

  // حاشية ملونة يمين
  el('rect', { x: x - 4, y, width: 4, height: h, rx: 2, fill: col }, SVG);

  // الإطار الرئيسي
  el('rect', { x, y, width: w, height: h, rx: 9, fill: '#21262d', stroke: col + '44', 'stroke-width': '1.5' }, SVG);

  // رأس الصندوق
  el('rect', { x, y, width: w, height: L.deptHdrH, rx: 9, fill: col + '22' }, SVG);
  el('rect', { x, y: y + 9, width: w, height: L.deptHdrH - 9, fill: col + '22' }, SVG);

  // أيقونة + اسم الإدارة
  el('circle', { cx: x + 16, cy: y + L.deptHdrH/2, r: 5, fill: col }, SVG);
  label(x + 26, y + L.deptHdrH/2 + 4, dg.name, '#ffffff', 11, 'start', '700');

  // الأعضاء
  dg.members.forEach((m, mi) => {
    const rowY = y + L.deptHdrH + mi * L.rowH;
    const midY = rowY + L.rowH / 2;

    // خط فاصل
    el('line', { x1: x + 10, y1: rowY, x2: x + w - 10, y2: rowY, stroke: '#30363d', 'stroke-width': '1' }, SVG);

    // رقم التسلسل في الخلفية
    el('circle', { cx: x + 18, cy: midY, r: 10, fill: '#2d333b', stroke: '#3d444d', 'stroke-width': '1' }, SVG);
    label(x + 18, midY + 4, String(mi + 1), C[m.color] || C.gray, 10, 'middle', '700');

    // نقطة الحالة
    const dotX = x + w - 18;
    const dotC = el('circle', { cx: dotX, cy: midY, r: 7, fill: C[m.color] || C.gray, cursor: 'pointer' }, SVG);
    if (m.color === 'blue') dotC.style.animation = 'pulse 1.9s ease-in-out infinite';

    // اسم + دور
    label(x + 34, midY - 4, m.name, '#cdd9e5', 11, 'start', '600');
    label(x + 34, midY + 11, m.role, '#768390', 9, 'start', '400');

    // نقطة الاتصال (يمين الصف) — حيث تنطلق الأسهم
    el('circle', { cx: x + w, cy: midY, r: 3, fill: '#3d444d' }, SVG);

    // hover zone
    const hz = el('rect', { x, y: rowY, width: w, height: L.rowH, fill: 'transparent', cursor: 'pointer' }, SVG);
    hz.addEventListener('mouseenter', e => memberTip(e, m));
    hz.addEventListener('mousemove',  e => moveTip(e));
    hz.addEventListener('mouseleave', hideTip);
    if (m.color === 'blue' && m.user_id)
      hz.addEventListener('click', () => openR(m.user_id, m.name));
  });
}

// ════════════════════════════════════════════════════════
//  drawBoardCircle — دائرة الديوان
// ════════════════════════════════════════════════════════
function drawBoardCircle(cx, cy, node, r, isPriv) {
  const col      = C[node.color] || C.gray;
  const ringCol  = isPriv ? '#b083f0' : '#7b5af0';
  const isActive = node.color === 'blue';

  // هالة
  el('circle', { cx, cy, r: r + 9, fill: col, opacity: '0.1' }, SVG);
  // حلقة خارجية
  el('circle', { cx, cy, r: r + 4, fill: 'none', stroke: ringCol, 'stroke-width': isPriv ? '2' : '1.5', opacity: '0.7' }, SVG);
  // الدائرة
  const c = el('circle', { cx, cy, r, fill: col, cursor: 'pointer' }, SVG);
  if (isActive) c.style.animation = 'pulse 1.9s ease-in-out infinite';

  // أيقونة نصية
  const icons = { green: '✓', red: '✕', blue: '◉', orange: '◷', gray: '◌' };
  label(cx, cy + 5, icons[node.color] || '◌', '#fff', isPriv ? 15 : 13, 'middle', '700');

  // اسم المستخدم أسفل الدائرة
  if (node.name) label(cx, cy + r + 15, node.name, '#768390', 9, 'middle', '400');

  // hover + click
  c.addEventListener('mouseenter', e => showTip(e, node));
  c.addEventListener('mousemove',  e => moveTip(e));
  c.addEventListener('mouseleave', hideTip);
  if (node.color === 'blue' && node.user_id)
    c.addEventListener('click', () => openR(node.user_id, node.name));
}

// ── Run ──
draw();
window.addEventListener('resize', draw);

})();
</script>
</body>
</html>