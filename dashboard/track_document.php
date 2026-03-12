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
        $status_text = 'للاستكمال';
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
        1 AS received,
        MIN(dw.action_date) AS workflow_date,
        COALESCE(MAX(dus.updated_at), MIN(dw.action_date)) AS display_date
    FROM document_workflow dw
    JOIN users u        ON dw.to_user_id  = u.id
    JOIN roles r        ON u.role_id      = r.id
    LEFT JOIN departments dep ON u.department_id = dep.id
    LEFT JOIN document_user_status dus
           ON dus.document_id = dw.document_id AND dus.user_id = u.id
    WHERE dw.document_id = :doc_id
      AND r.role_name IN ('employee','section_manager','department_manager')
    GROUP BY u.id, u.full_name, r.role_name, r.id, dep.id, dep.name, dus.status
    UNION
    SELECT
        u.id AS user_id,
        u.full_name,
        r.role_name,
        r.id AS role_id,
        dep.id AS dept_id,
        dep.name AS dept_name,
        'pending' AS status,
        0 AS received,
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

// جلب آخر action قام به كل مستخدم كـ مُرسِل في هذا المستند
// هذا يخبرنا: هل أرسل العضو شيئاً للديوان؟ وماذا أرسل؟
$actions_query = $db->prepare("
    SELECT from_user_id, action_type
    FROM document_workflow
    WHERE document_id = :doc_id
      AND from_user_id IS NOT NULL
    ORDER BY action_date DESC
");
$actions_query->execute([':doc_id' => $document_id]);
// نأخذ آخر action لكل مستخدم
$last_action_by_user = [];
foreach ($actions_query->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $uid = $row['from_user_id'];
  if (!isset($last_action_by_user[$uid])) {
    $last_action_by_user[$uid] = $row['action_type'];
  }
}

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
    $col = 'blue';
    $stxt = 'قيد المعالجة';
  } else {
    switch ($m['status']) {
      case 'completed':
      case 'approved':
        $col = 'green';
        $stxt = 'مكتمل';
        break;
      case 'rejected':
        $col = 'red';
        $stxt = 'مرفوض';
        break;
      case 'partially_signed':
      case 'partially_completed':
      case 'completion_required':
        $col = 'orange';
        $stxt = 'للاستكمال';
        break;
      default:
        $col = 'gray';
        $stxt = 'بانتظار';
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
    'received'    => (int)($m['received'] ?? 0),
    'last_action' => $last_action_by_user[$m['user_id']] ?? null,
  ];
}

// ترتيب أعضاء كل إدارة: موظف → رئيس قسم → مدير دائرة
foreach ($dept_groups as &$dg) {
  usort($dg['members'], function ($a, $b) {
    return $a['seq'] - $b['seq'];
  });
}
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
        u.department_id AS dept_id,
        dep.name AS dept_name,
        COALESCE(dus.status, 'pending') AS status,
        COALESCE(MAX(dus.updated_at), MIN(dw.action_date)) AS display_date
    FROM document_workflow dw
    JOIN users u ON dw.to_user_id = u.id
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments dep ON u.department_id = dep.id
    LEFT JOIN document_user_status dus
           ON dus.document_id = dw.document_id AND dus.user_id = u.id
    WHERE dw.document_id = :doc_id
      AND r.role_name IN ('private_board','sub_board','board','office_manager','deputy_ceo','ceo','admin')
    GROUP BY u.id, u.full_name, r.role_name, r.id, u.department_id, dep.name, dus.status
    UNION
    SELECT
        u.id AS user_id, u.full_name,
        r.role_name, r.id AS role_id,
        u.department_id AS dept_id,
        dep.name AS dept_name,
        'pending' AS status,
        d.created_at AS display_date
    FROM documents d
    JOIN users u ON d.created_by = u.id
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments dep ON u.department_id = dep.id
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
  'private_board' => 1,
  'sub_board' => 2,
  'board' => 3,
  'office_manager' => 4,
  'deputy_ceo' => 5,
  'ceo' => 6,
  'admin' => 7
];
$board_labels = [
  'private_board' => 'الديوان الخاص',
  'sub_board' => 'الديوان الفرعي',
  'board' => 'الديوان',
  'office_manager' => 'مدير المكتب',
  'deputy_ceo' => 'نائب الرئيس',
  'ceo' => 'الرئيس التنفيذي',
  'admin' => 'المسؤول'
];

$prio = ['blue' => 0, 'green' => 1, 'orange' => 2, 'red' => 3, 'gray' => 4];

// دالة تحديد لون العقدة
function nodeColor($status, $is_cur)
{
  if ($is_cur) return ['blue', 'قيد المعالجة'];
  switch ($status) {
    case 'completed':
    case 'approved':
      return ['green', 'مكتمل'];
    case 'rejected':
      return ['red', 'مرفوض'];
    case 'partially_signed':
    case 'partially_completed':
      return ['orange', 'للاستكمال'];
    default:
      return ['gray', 'بانتظار'];
  }
}

// ── فصل private_board عن باقي أدوار الديوان ──
// كل ديوان خاص يُعرَّف بـ user_id الخاص به (وليس فقط dept_id)
// لأن ممكن تكون هناك إدارتين مختلفتين لكل منهما ديوان خاص مستقل
$private_boards_by_dept = [];  // dept_id → node
$chain_raw = [];

foreach ($board_raw as $bm) {
  $is_cur = ($document['current_holder_id'] == $bm['user_id']);
  [$col, $stxt] = nodeColor($bm['status'], $is_cur);

  if ($bm['role_name'] === 'private_board') {
    // المفتاح: dept_id إن وُجد، وإلا user_id لضمان ظهور كل ديوان خاص
    $key = !empty($bm['dept_id']) ? 'dept_' . $bm['dept_id'] : 'user_' . $bm['user_id'];
    $node = [
      'user_id'     => $bm['user_id'],
      'name'        => $bm['full_name'],
      'role'        => 'الديوان الخاص',
      'role_key'    => 'private_board',
      'dept_id'     => $bm['dept_id'] ?? null,
      'dept_name'   => $bm['dept_name'] ?? '',
      'color'       => $col,
      'status_text' => $stxt,
      'date'        => $bm['display_date'] ? date('Y-m-d H:i', strtotime($bm['display_date'])) : '',
      'is_current'  => $is_cur,
    ];
    // نحتفظ بالأعلى أولوية (الأكثر تقدماً)
    if (
      !isset($private_boards_by_dept[$key]) ||
      ($prio[$col] ?? 5) < ($prio[$private_boards_by_dept[$key]['color']] ?? 5)
    ) {
      $private_boards_by_dept[$key] = $node;
    }
  } else {
    $chain_raw[] = $bm;
  }
}

// ── ربط الديوان الخاص بإدارته في dept_groups ──
// أولاً: نربط بـ dept_id المطابق
// ثانياً: الدواوين الخاصة غير المرتبطة تبقى في $orphan_private_boards
$used_pb_keys = [];
foreach ($dept_groups as &$dg) {
  $did = $dg['id'];
  $key = 'dept_' . $did;
  if (isset($private_boards_by_dept[$key])) {
    $dg['private_board'] = $private_boards_by_dept[$key];
    $used_pb_keys[] = $key;
  } else {
    $dg['private_board'] = null;
  }
}
unset($dg);

// الدواوين الخاصة غير المرتبطة بإدارة محددة
$orphan_private_boards = array_filter(
  $private_boards_by_dept,
  fn($k) => !in_array($k, $used_pb_keys),
  ARRAY_FILTER_USE_KEY
);

// ── بناء سلسلة الديوان (sub_board → board → ...) ──
$board_by_role = [];
foreach ($chain_raw as $bm) {
  $rn = $bm['role_name'];
  if (!isset($board_by_role[$rn])) $board_by_role[$rn] = [];
  $is_cur = ($document['current_holder_id'] == $bm['user_id']);
  [$col, $stxt] = nodeColor($bm['status'], $is_cur);
  $board_by_role[$rn][] = [
    'user_id' => $bm['user_id'],
    'name' => $bm['full_name'],
    'role' => $board_labels[$rn] ?? $rn,
    'role_name' => $rn,
    'color' => $col,
    'status_text' => $stxt,
    'date' => $bm['display_date'] ? date('Y-m-d H:i', strtotime($bm['display_date'])) : '',
    'is_current' => $is_cur,
  ];
}
uksort($board_by_role, fn($a, $b) => ($board_seq[$a] ?? 99) - ($board_seq[$b] ?? 99));

$board_nodes = [];
foreach ($board_by_role as $rn => $members) {
  usort($members, fn($a, $b) => ($prio[$a['color']] ?? 5) - ($prio[$b['color']] ?? 5));
  $rep = $members[0];
  $board_nodes[] = [
    'role_name' => $rep['role'],
    'role_key' => $rn,
    'users' => $members,
    'color' => $rep['color'],
    'status_text' => $rep['status_text'],
    'date' => $rep['date'],
    'user_id' => $rep['user_id'],
    'name' => $rep['name'],
    'seq' => $board_seq[$rn] ?? 99,
  ];
}

// الدواوين الخاصة اليتيمة (لإدارات ليست في dept_groups)
$orphan_pb_list = array_values($orphan_private_boards);

// جلب آخر action أرسله كل مستخدم في سلسلة الديوان
$chain_actions_query = $db->prepare("
    SELECT from_user_id, action_type
    FROM document_workflow
    WHERE document_id = :doc_id
      AND from_user_id IS NOT NULL
    ORDER BY action_date DESC
");
$chain_actions_query->execute([':doc_id' => $document_id]);
$chain_last_action = [];
foreach ($chain_actions_query->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $uid = $row['from_user_id'];
  if (!isset($chain_last_action[$uid])) {
    $chain_last_action[$uid] = $row['action_type'];
  }
}

// أضف last_action لكل node في سلسلة الديوان
foreach ($board_nodes as &$bn) {
  $bn['last_action'] = $chain_last_action[$bn['user_id']] ?? null;
}
unset($bn);

// أضف last_action لكل ديوان خاص في dept_groups
foreach ($dept_groups as &$dg) {
  if ($dg['private_board']) {
    $pb_uid = $dg['private_board']['user_id'];
    $dg['private_board']['last_action'] = $chain_last_action[$pb_uid] ?? null;
  }
}
unset($dg);

// أضف last_action لكل ديوان خاص يتيم
foreach ($orphan_pb_list as &$opb) {
  $opb['last_action'] = $chain_last_action[$opb['user_id']] ?? null;
}
unset($opb);

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
    :root {
      --bg: #ffffff;
      --surf: #f6f8fa;
      --surf2: #eaeef2;
      --surf3: #d0d7de;
      --border: #d0d7de;
      --border2: #c6cdd4;
      --text: #1f2328;
      --muted: #636c76;
      --muted2: #818b98;
      --blue: #0969da;
      --green: #1a7f37;
      --red: #cf222e;
      --orange: #9a6700;
      --gray: #818b98;
      --purple: #8250df;
    }

    html {
      background: #ffffff !important;
    }

    body {
      background: #ffffff !important;
      background-color: #ffffff !important;
      color: #1f2328 !important;
      font-family: 'Cairo', sans-serif !important;
      min-height: 100vh;
      margin: 0 !important;
      padding: 0 !important;
    }

    * {
      box-sizing: border-box;
    }

    .container,
    .wrapper,
    .main-content,
    .content-area,
    main,
    section {
      background: transparent !important;
      color: inherit !important;
    }

    .hdr,
    .graph-card,
    .sigs {
      background: #f6f8fa !important;
      color: #1f2328 !important;
    }

    .pill {
      background: #eaeef2 !important;
      color: #1f2328 !important;
    }

    #tip,
    .r-box {
      background: #ffffff !important;
      color: #1f2328 !important;
    }

    .page {
      max-width: 1400px;
      margin: 0 auto;
      padding: 24px 20px;
    }

    .hdr {
      background: var(--surf);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px 26px;
      margin-bottom: 18px;
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--blue);
      text-decoration: none;
      font-size: .85rem;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 5px 14px;
      margin-bottom: 14px;
      transition: background .2s;
    }

    .back-btn:hover {
      background: var(--surf2);
      text-decoration: none;
      color: var(--blue);
    }

    .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
    }

    .pill {
      display: flex;
      align-items: center;
      gap: 7px;
      background: var(--surf2);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 5px 14px;
      font-size: .82rem;
    }

    .pill i {
      color: var(--blue);
      font-size: .8rem;
    }

    .pill strong {
      color: #1f2328;
    }

    .legend {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 18px;
      margin-bottom: 20px;
      align-items: center;
    }

    .leg {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: .78rem;
      color: var(--muted);
    }

    .leg-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
    }

    .graph-card {
      background: var(--surf);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 32px 20px 32px 28px;
      margin-bottom: 20px;
      overflow-x: auto;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .graph-title {
      font-size: .95rem;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 28px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .graph-title i {
      color: var(--blue);
    }

    #svg-track {
      display: block;
      overflow: visible;
    }

    #tip {
      position: fixed;
      z-index: 9999;
      background: var(--surf);
      border: 1px solid var(--border2);
      border-radius: 10px;
      padding: 13px 16px;
      min-width: 185px;
      max-width: 250px;
      pointer-events: none;
      opacity: 0;
      transition: opacity .15s;
      box-shadow: 0 4px 16px rgba(0, 0, 0, .12);
    }

    #tip.on {
      opacity: 1;
    }

    .tip-role {
      font-size: .9rem;
      font-weight: 700;
      color: #1f2328;
      margin-bottom: 6px;
    }

    .tip-user {
      font-size: .78rem;
      color: var(--muted);
      margin-bottom: 3px;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .tip-date {
      font-size: .72rem;
      color: var(--muted2);
      direction: ltr;
      margin-bottom: 7px;
    }

    .tip-badge {
      display: inline-block;
      padding: 2px 9px;
      border-radius: 20px;
      font-size: .72rem;
      font-weight: 700;
    }

    .tip-badge.blue {
      background: rgba(83, 155, 245, .18);
      color: var(--blue);
    }

    .tip-badge.green {
      background: rgba(87, 171, 90, .18);
      color: var(--green);
    }

    .tip-badge.red {
      background: rgba(229, 83, 75, .18);
      color: var(--red);
    }

    .tip-badge.orange {
      background: rgba(198, 144, 38, .18);
      color: var(--orange);
    }

    .tip-badge.gray {
      background: rgba(84, 93, 104, .3);
      color: var(--muted);
    }

    .tip-badge.purple {
      background: rgba(176, 131, 240, .18);
      color: var(--purple);
    }

    .r-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .45);
      z-index: 5000;
      align-items: center;
      justify-content: center;
    }

    .r-overlay.on {
      display: flex;
    }

    .r-box {
      background: var(--surf);
      border: 1px solid var(--border);
      border-radius: 12px;
      width: 90%;
      max-width: 420px;
      overflow: hidden;
    }

    .r-hd {
      background: #1d4ed8;
      color: #fff;
      padding: 14px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 700;
      font-size: .9rem;
    }

    .r-hd button {
      background: none;
      border: none;
      color: #fff;
      font-size: 1.4rem;
      cursor: pointer;
      line-height: 1;
    }

    .r-bd {
      padding: 20px;
    }

    .r-bd p {
      color: var(--text);
      font-size: .88rem;
      margin-bottom: 12px;
    }

    .r-bd textarea {
      width: 100%;
      background: var(--surf2);
      border: 1px solid var(--border);
      color: var(--text);
      border-radius: 8px;
      padding: 10px;
      font-family: 'Cairo', sans-serif;
      resize: vertical;
      font-size: .85rem;
    }

    .r-ft {
      background: var(--surf2);
      padding: 12px 20px;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .btn {
      padding: 7px 18px;
      border-radius: 7px;
      border: none;
      cursor: pointer;
      font-family: 'Cairo', sans-serif;
      font-size: .85rem;
      font-weight: 600;
    }

    .btn-cancel {
      background: var(--surf3);
      color: var(--muted);
      border: 1px solid var(--border);
    }

    .btn-send {
      background: #1d4ed8;
      color: #fff;
    }

    .sigs {
      background: var(--surf);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 22px 26px;
      margin-top: 18px;
    }

    .sigs-title {
      font-size: .95rem;
      font-weight: 700;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .sigs-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 12px;
    }

    .sig {
      background: var(--surf2);
      border: 1px solid var(--border);
      border-radius: 9px;
      padding: 12px;
      display: flex;
      gap: 11px;
      align-items: center;
      transition: border-color .2s;
    }

    .sig:hover {
      border-color: var(--blue);
    }

    .sig-av {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      flex-shrink: 0;
      background: linear-gradient(135deg, #57ab5a, #2d6a2f);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-weight: 700;
      font-size: .95rem;
    }

    .sig-name {
      font-weight: 600;
      color: #1f2328;
      font-size: .88rem;
    }

    .sig-sub {
      font-size: .73rem;
      color: var(--muted);
      line-height: 1.7;
    }

    @keyframes pulse {
      0% {
        opacity: 1;
      }

      50% {
        opacity: .4;
      }

      100% {
        opacity: 1;
      }
    }
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
          <strong><?php echo $document_display_number; ?></strong>
        </div>
        <div class="pill"><i class="fas fa-tag"></i><span>العنوان:</span>
          <strong><?php echo htmlspecialchars($document['title']); ?></strong>
        </div>
        <div class="pill"><i class="fas fa-circle"></i><span>الحالة:</span>
          <?php
          $slabels = [
            'draft' => 'مسودة',
            'pending' => 'قيد الانتظار',
            'under_review' => 'قيد المراجعة',
            'completed' => 'مكتمل',
            'partially_signed' => 'للاستكمال',
            'rejected' => 'مرفوض',
            'partially_completed' => 'مكتملة جزئياً',
            'completion_required' => 'استكمال'
          ];
          ?>
          <strong><?php echo $slabels[$document['current_status']] ?? $document['current_status']; ?></strong>
        </div>
      </div>
    </div>

    <!-- Legend -->
    <div class="legend">
      <div class="leg">
        <div class="leg-dot" style="background:#539bf5;box-shadow:0 0 5px #539bf5aa;"></div><span>قيد المعالجة</span>
      </div>
      <div class="leg">
        <div class="leg-dot" style="background:#57ab5a;"></div><span>مكتمل</span>
      </div>
      <div class="leg">
        <div class="leg-dot" style="background:#e5534b;"></div><span>مرفوض</span>
      </div>
      <div class="leg">
        <div class="leg-dot" style="background:#d4a017;"></div><span>للاستكمال</span>
      </div>
      <div class="leg">
        <div class="leg-dot" style="background:#545d68;"></div><span>بانتظار</span>
      </div>
      <div class="leg">
        <div class="leg-dot" style="background:#b083f0;"></div><span>مسار الديوان</span>
      </div>
      <!-- سهم إرسال -->
      <div class="leg">
        <svg width="36" height="12" style="overflow:visible">
          <line x1="2" y1="6" x2="26" y2="6" stroke="#539bf5" stroke-width="1.5" stroke-dasharray="4,2" />
          <polygon points="26,3 34,6 26,9" fill="#539bf5" />
        </svg>
        <span>إرسال (ديوان خاص)</span>
      </div>
      <!-- سهم استقبال -->
      <div class="leg">
        <svg width="36" height="12" style="overflow:visible">
          <line x1="2" y1="6" x2="26" y2="6" stroke="#57ab5a" stroke-width="1.5" stroke-dasharray="4,2" />
          <polygon points="26,3 34,6 26,9" fill="#57ab5a" />
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
        const ORPHAN_PB = <?php echo json_encode($orphan_pb_list, JSON_UNESCAPED_UNICODE); ?>;
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
          <input type="hidden" id="rSender" value="<?php echo (int)$user_id; ?>">
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
    (function() {
      "use strict";

      const SVG = document.getElementById('svg-track');
      const TIP = document.getElementById('tip');
      const NS = 'http://www.w3.org/2000/svg';

      const C = {
        blue: '#0969da',
        green: '#1a7f37',
        red: '#cf222e',
        orange: '#9a6700',
        gray: '#818b98',
        purple: '#8250df',
        yellow: '#d4a017'
      };
      const BG = {
        page: '#ffffff',
        card: '#f6f8fa',
        border: '#d0d7de',
        text: '#1f2328',
        muted: '#636c76',
      };

      const L = {
        padT: 70,
        padL: 30,
        R: 28,
        rowH: 90,
        deptGap: 80,
        colW: 110,
        boardStepX: 150,
      };

      // ── helpers ──
      function el(tag, attrs, parent) {
        const e = document.createElementNS(NS, tag);
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, String(v)));
        if (parent) parent.appendChild(e);
        return e;
      }

      // ── Arrow markers ──
      const _mk = {};

      function mk(col) {
        const id = 'mk_' + col.replace('#', '');
        if (_mk[id]) return 'url(#' + id + ')';
        _mk[id] = true;
        let defs = SVG.querySelector('defs');
        if (!defs) defs = el('defs', {}, SVG);
        const m = el('marker', {
          id,
          markerWidth: '9',
          markerHeight: '9',
          refX: '8',
          refY: '4.5',
          orient: 'auto'
        }, defs);
        el('polygon', {
          points: '0,1 9,4.5 0,8',
          fill: col
        }, m);
        return 'url(#' + id + ')';
      }

      // ── curved arrow: dashed=إرسال، solid=رد/موافقة/رفض ──
      function arrow(x1, y1, x2, y2, col, dashed, bend) {
        bend = bend || 0;
        const mx = (x1 + x2) / 2,
          my = (y1 + y2) / 2 + bend;
        const dx = x2 - x1,
          dy = y2 - y1,
          len = Math.sqrt(dx * dx + dy * dy) || 1;
        const ex = x2 - (dx / len) * 9,
          ey = y2 - (dy / len) * 9;
        el('path', {
          d: 'M' + x1 + ',' + y1 + ' Q' + mx + ',' + my + ' ' + ex + ',' + ey,
          fill: 'none',
          stroke: col,
          'stroke-width': dashed ? '1.9' : '2.4',
          'stroke-dasharray': dashed ? '7,4' : 'none',
          'marker-end': mk(col),
          opacity: '0.9'
        }, SVG);
      }

      // ── Tooltips ──
      function showTip(e, d) {
        const users = d.users || [{
          name: d.name,
          role: d.role || d.role_name,
          color: d.color,
          status_text: d.status_text
        }];
        TIP.innerHTML = '<div class="tip-role">' + (d.role_name || d.role || '') + '</div>' +
          users.map(u => '<div class="tip-user"><svg width="9" height="9" style="flex-shrink:0">' +
            '<circle cx="4.5" cy="4.5" r="4.5" fill="' + (C[u.color] || C.gray) + '"/></svg> ' +
            u.name + '<span style="color:var(--muted2);font-size:9px;margin-right:4px">(' +
            (u.role || '') + ')</span></div>').join('') +
          (d.date ? '<div class="tip-date">' + d.date + '</div>' : '') +
          '<span class="tip-badge ' + d.color + '">' + d.status_text + '</span>';
        TIP.classList.add('on');
        mv(e);
      }

      function memTip(e, m) {
        TIP.innerHTML = '<div class="tip-role">' + m.name + '</div>' +
          '<div class="tip-user"><i class="fas fa-id-badge" style="font-size:9px;color:#636c76"></i> ' + m.role + '</div>' +
          (m.date ? '<div class="tip-date">' + m.date + '</div>' : '') +
          '<span class="tip-badge ' + m.color + '">' + m.status_text + '</span>';
        TIP.classList.add('on');
        mv(e);
      }

      function mv(e) {
        TIP.style.left = (e.clientX + 14) + 'px';
        TIP.style.top = (e.clientY - 8) + 'px';
      }

      function hideTip() {
        TIP.classList.remove('on');
      }

      window.openR = (uid, uname) => {
        document.getElementById('rUid').value = uid;
        document.getElementById('rName').textContent = uname;
        document.getElementById('rModal').classList.add('on');
      };
      window.closeR = () => document.getElementById('rModal').classList.remove('on');
      document.getElementById('rModal').addEventListener('click', e => {
        if (e.target.id === 'rModal') closeR();
      });

      // ── إرسال التذكير ──
      window.sendR = async () => {
        const uid = document.getElementById('rUid').value;
        const doc = document.getElementById('rDoc').value;
        const msg = document.getElementById('rMsg').value.trim();
        const btn = document.querySelector('#rModal .btn-send');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...';
        try {
          const fd = new FormData();
          fd.append('user_id', uid);
          fd.append('document_id', doc);
          fd.append('sender_id', document.getElementById('rSender').value);
          if (msg) fd.append('message', msg);
          const res = await fetch('../documents/send_reminder.php', {
            method: 'POST',
            body: fd
          });
          const text = await res.text();
          let data;
          try {
            data = JSON.parse(text);
          } catch (je) {
            showToast('خطأ في الخادم: ' + text.substring(0, 120), 'error');
            return;
          }
          if (data.success) {
            closeR();
            document.getElementById('rMsg').value = '';
          }
          showToast(data.message, data.success ? 'success' : 'error');
        } catch (e) {
          showToast('تعذّر الوصول إلى الخادم: ' + e.message, 'error');
        } finally {
          btn.disabled = false;
          btn.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال';
        }
      };

      // ── Toast ──
      function showToast(msg, type = 'success') {
        const t = document.createElement('div');
        t.style.cssText = `position:fixed;bottom:30px;left:50%;transform:translateX(-50%);
    background:${type==='success'?'#1a7f37':'#cf222e'};color:#fff;padding:12px 24px;
    border-radius:8px;font-family:Cairo,sans-serif;font-size:.9rem;font-weight:600;
    z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.25);direction:rtl;`;
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 3500);
      }

      const DCOLS = ['#0969da', '#1a7f37', '#8250df', '#9a6700', '#cf222e', '#0a7d8c', '#bc4c00'];
      const dc = i => DCOLS[i % DCOLS.length];

      // ══════════════════════════════════════════
      //  DRAW
      // ══════════════════════════════════════════
      function draw() {
        SVG.innerHTML = '';
        const R = L.R;

        const depts = DEPT_GROUPS.map((dg, i) => ({
          ...dg,
          col: dc(i),
          boxH: dg.members.length * L.rowH
        }));

        const COL0 = L.padL + R; // أعضاء الإدارة
        const COL1 = COL0 + R + L.colW; // الديوان الخاص
        const COL2 = COL1 + R + L.colW; // بداية سلسلة الديوان

        let cy = L.padT;
        depts.forEach(d => {
          d.y = cy;
          cy += d.boxH + L.deptGap;
        });

        const orphans = typeof ORPHAN_PB !== 'undefined' ? ORPHAN_PB : [];
        let oCy = cy;
        orphans.forEach(pb => {
          pb._cy = oCy + R;
          oCy += R * 2 + L.deptGap * 0.5;
        });

        const totalH = Math.max(cy - L.deptGap, oCy);
        const CHAIN_Y = L.padT + (totalH - L.padT) / 2;

        BOARD_NODES.forEach((b, i) => {
          b._cx = COL2 + i * L.boardStepX;
          b._cy = CHAIN_Y;
        });

        const lastCX = BOARD_NODES.length ?
          BOARD_NODES[BOARD_NODES.length - 1]._cx + R + 80 : COL2 + R + 80;
        const svgW = Math.max(lastCX, COL2 + R + 80);
        const svgH = Math.max(totalH + 80, CHAIN_Y + R + 80);
        SVG.setAttribute('viewBox', '0 0 ' + svgW + ' ' + svgH);
        SVG.setAttribute('height', svgH);
        SVG.style.minWidth = svgW + 'px';

        // ── 1. دوائر الإدارات ──
        depts.forEach((dg, di) => drawDeptGroup(dg, di, COL0, COL1));

        // ── 2. الدواوين الخاصة اليتيمة ──
        orphans.forEach(pb => drawPrivCircle(COL1, pb._cy, pb, R));

        // ── 3. سلسلة الديوان ──
        BOARD_NODES.forEach((b, i) => drawChainCircle(b._cx, b._cy, b, R));

        // ── 4. كل الأسهم آخراً (فوق كل شيء) ──

        // 4a. أسهم أعضاء الإدارة ↔ الديوان الخاص
        depts.forEach(dg => {
          const pb = dg.private_board;
          if (!pb) return;
          const pbY = dg.y + dg.boxH / 2;
          const pbL = COL1 - R; // يسار الديوان الخاص
          const pbR = COL1 + R; // يمين الديوان الخاص

          dg.members.forEach((m, mi) => {
            // لا أسهم لمن لم يصله المستند أبداً
            if (!m.received) return;

            const mcy = dg.y + mi * L.rowH + L.rowH / 2;
            const mRight = COL0 + R;
            const dy = mcy - pbY;
            const bend = Math.max(22, Math.abs(dy) * 0.2);
            const s = dy >= 0 ? -1 : 1;

            // سهم الإرسال دائماً: الديوان ← العضو (منقط أزرق)
            arrow(pbL, pbY, mRight, mcy, C.blue, true, s * bend);

            // سهم الرد: العضو → الديوان
            // يظهر فقط إذا أرسل العضو شيئاً فعلاً (last_action موجود)
            const act = m.last_action;
            if (act) {
              const retCol = act === 'approve' ? C.green :
                act === 'reject' ? C.red :
                C.orange; // complete أو أي action آخر = برتقالي (انتظار استكمال)
              arrow(mRight, mcy, pbL, pbY, retCol, false, -s * bend);
            }
          });

          // 4b. الديوان الخاص ↔ الديوان الفرعي
          if (BOARD_NODES.length > 0) {
            const sub = BOARD_NODES[0];
            const subL = sub._cx - R;
            const dy2 = pbY - CHAIN_Y;
            const bend2 = Math.max(20, Math.abs(dy2) * 0.18);
            const s2 = dy2 >= 0 ? 1 : -1;

            // سهم الإرسال: الديوان الخاص → الفرعي (منقط أزرق) إذا أرسل الديوان الخاص
            if (pb.last_action)
              arrow(pbR, pbY, subL, CHAIN_Y, C.blue, true, s2 * bend2);

            // سهم الرد: الفرعي → الديوان الخاص بلون حسب last_action الفرعي
            if (sub.last_action) {
              const retCol = sub.last_action === 'approve' ? C.green :
                sub.last_action === 'reject' ? C.red :
                C.yellow;
              arrow(subL, CHAIN_Y, pbR, pbY, retCol, false, -s2 * bend2);
            }
          }
        });

        // 4c. أسهم الدواوين اليتيمة ↔ السلسلة
        orphans.forEach(pb => {
          if (BOARD_NODES.length === 0) return;
          const sub = BOARD_NODES[0];
          const subL = sub._cx - R;
          const dy2 = pb._cy - CHAIN_Y;
          const bend2 = Math.max(20, Math.abs(dy2) * 0.18);
          const s2 = dy2 >= 0 ? 1 : -1;

          if (pb.last_action)
            arrow(COL1 + R, pb._cy, subL, CHAIN_Y, C.blue, true, s2 * bend2);

          if (sub.last_action) {
            const retCol = sub.last_action === 'approve' ? C.green :
              sub.last_action === 'reject' ? C.red :
              C.yellow;
            arrow(subL, CHAIN_Y, COL1 + R, pb._cy, retCol, false, -s2 * bend2);
          }
        });

        // 4d. أسهم بين عقد سلسلة الديوان (sub→board→...)
        for (let i = 1; i < BOARD_NODES.length; i++) {
          const prev = BOARD_NODES[i - 1];
          const curr = BOARD_NODES[i];
          const bend = 22;

          // إرسال: السابق → الحالي إذا أرسل السابق شيئاً
          if (prev.last_action)
            arrow(prev._cx + R, CHAIN_Y, curr._cx - R, CHAIN_Y, C.blue, true, -bend);

          // رد: الحالي → السابق بلون حسب last_action الحالي
          if (curr.last_action) {
            const retCol = curr.last_action === 'approve' ? C.green :
              curr.last_action === 'reject' ? C.red :
              C.yellow;
            arrow(curr._cx - R, CHAIN_Y, prev._cx + R, CHAIN_Y, retCol, false, +bend);
          }
        }
      }

      // ══════════════════════════════════════════
      //  drawDeptGroup
      // ══════════════════════════════════════════
      function drawDeptGroup(dg, di, cx0, cx1) {
        const R = L.R;
        // اختيار لون مميز للإدارة بناءً على رقمها (di)
        const deptColor = dc(di); // dc هي دالة موجودة مسبقاً تعيد لوناً

        // ── رسم خلفية المجموعة ─────────────────────────
        const padding = 30; // مسافة إضافية حول المجموعة
        const rectX = cx0 - R - padding;
        const rectWidth = (cx1 + R + padding) - rectX;
        el('rect', {
          x: rectX,
          y: dg.y - padding,
          width: rectWidth,
          height: dg.boxH + padding * 2,
          fill: deptColor,
          opacity: '0.08', // خفيف جداً (8%) حتى تبقى الدوائر واضحة
          rx: '16', // زوايا دائرية
          stroke: 'none'
        }, SVG);

        // ── كتابة اسم الإدارة فوق الخلفية ─────────────
        el('text', {
          x:(cx0 + cx1) / 2, // توسيط النص فوق عمود الأعضاء
          y: dg.y - padding +25, // أعلى الخلفية بقليل
          'text-anchor': 'middle',
          fill: deptColor,
          'font-size': '14',
          'font-weight': '700',
          'font-family': 'Cairo, sans-serif'
        }, SVG).textContent = dg.name;

        // ── رسم دوائر الأعضاء (بدون تغيير) ────────────
        dg.members.forEach((m, mi) => {
          const mcy = dg.y + mi * L.rowH + L.rowH / 2;
          drawMemberCircle(m, mi, cx0, mcy);
        });

        // ── رسم الديوان الخاص (بدون تغيير) ────────────
        const pb = dg.private_board;
        const pbY = dg.y + dg.boxH / 2;
        if (pb) {
          drawPrivCircle(cx1, pbY, pb, R);
        } else {
          el('circle', {
            cx: cx1,
            cy: pbY,
            r: R,
            fill: 'none',
            stroke: '#d0d7de',
            'stroke-width': '1.5',
            'stroke-dasharray': '5,3'
          }, SVG);
          el('text', {
            x: cx1,
            y: pbY + 6,
            'text-anchor': 'middle',
            fill: '#636c76',
            'font-size': '18',
            'pointer-events': 'none'
          }, SVG).textContent = '?';
        }
      }
      // ── دائرة عضو ──
      function drawMemberCircle(m, mi, cx, mcy) {
        const R = L.R,
          cc = C[m.color] || C.gray;
        el('circle', {
          cx,
          cy: mcy,
          r: R + 8,
          fill: cc,
          opacity: '0.07'
        }, SVG);
        el('circle', {
          cx,
          cy: mcy,
          r: R + 4,
          fill: 'none',
          stroke: cc,
          'stroke-width': '1',
          opacity: '0.3'
        }, SVG);
        const c = el('circle', {
          cx,
          cy: mcy,
          r: R,
          fill: cc,
          cursor: 'pointer'
        }, SVG);
        if (m.color === 'blue') c.style.animation = 'pulse 1.9s ease-in-out infinite';
        const ic = {
          green: '\u2713',
          red: '\u2715',
          blue: '\u25c9',
          orange: '\u25f7',
          gray: '\u25cb'
        } [m.color] || '\u25cb';
        el('text', {
          x: cx,
          y: mcy + 5.5,
          'text-anchor': 'middle',
          fill: '#fff',
          'font-size': '14',
          'font-family': 'Cairo,sans-serif',
          'font-weight': '700',
          'pointer-events': 'none'
        }, SVG).textContent = ic;
        // رقم التسلسل فقط
        const nx = cx - R + 6,
          ny = mcy - R + 6;
        el('circle', {
          cx: nx,
          cy: ny,
          r: 10,
          fill: BG.card,
          stroke: BG.border,
          'stroke-width': '1'
        }, SVG);
        el('text', {
          x: nx,
          y: ny + 4.5,
          'text-anchor': 'middle',
          fill: cc,
          'font-size': '9',
          'font-family': 'Cairo,sans-serif',
          'font-weight': '800',
          'pointer-events': 'none'
        }, SVG).textContent = String(mi + 1);
        const hz = el('circle', {
          cx,
          cy: mcy,
          r: R,
          fill: 'transparent',
          cursor: 'pointer'
        }, SVG);
        hz.addEventListener('mouseenter', e => memTip(e, m));
        hz.addEventListener('mousemove', e => mv(e));
        hz.addEventListener('mouseleave', hideTip);
        if (m.color === 'blue' && m.user_id)
          hz.addEventListener('click', () => openR(m.user_id, m.name));
      }

      // ── دائرة الديوان الخاص ──
      function drawPrivCircle(cx, cy, node, r) {
        const col = C[node.color] || C.gray;
        el('circle', {
          cx,
          cy,
          r: r + 10,
          fill: col,
          opacity: '0.07'
        }, SVG);
        el('circle', {
          cx,
          cy,
          r: r + 5,
          fill: 'none',
          stroke: '#8250df',
          'stroke-width': '1.5',
          opacity: '0.5'
        }, SVG);
        const c = el('circle', {
          cx,
          cy,
          r,
          fill: col,
          cursor: 'pointer'
        }, SVG);
        if (node.color === 'blue') c.style.animation = 'pulse 1.9s ease-in-out infinite';
        const ic = {
          green: '\u2713',
          red: '\u2715',
          blue: '\u25c9',
          orange: '\u25f7',
          gray: '\u25cb'
        } [node.color] || '\u25cb';
        el('text', {
          x: cx,
          y: cy + 6,
          'text-anchor': 'middle',
          fill: '#fff',
          'font-size': '14',
          'font-family': 'Cairo,sans-serif',
          'font-weight': '700',
          'pointer-events': 'none'
        }, SVG).textContent = ic;
        const nd = {
          ...node,
          role_name: 'الديوان الخاص',
          users: node.users || [{
            name: node.name,
            role: 'الديوان الخاص',
            color: node.color,
            status_text: node.status_text
          }]
        };
        c.addEventListener('mouseenter', e => showTip(e, nd));
        c.addEventListener('mousemove', e => mv(e));
        c.addEventListener('mouseleave', hideTip);
        if (node.color === 'blue' && node.user_id)
          c.addEventListener('click', () => openR(node.user_id, node.name));
      }

      // ── دائرة سلسلة الديوان ──
      function drawChainCircle(cx, cy, node, r) {
        const col = C[node.color] || C.gray;
        el('circle', {
          cx,
          cy,
          r: r + 10,
          fill: col,
          opacity: '0.07'
        }, SVG);
        el('circle', {
          cx,
          cy,
          r: r + 5,
          fill: 'none',
          stroke: '#8250df',
          'stroke-width': '1.5',
          opacity: '0.5'
        }, SVG);
        const c = el('circle', {
          cx,
          cy,
          r,
          fill: col,
          cursor: 'pointer'
        }, SVG);
        if (node.color === 'blue') c.style.animation = 'pulse 1.9s ease-in-out infinite';
        const ic = {
          green: '\u2713',
          red: '\u2715',
          blue: '\u25c9',
          orange: '\u25f7',
          gray: '\u25cb'
        } [node.color] || '\u25cb';
        el('text', {
          x: cx,
          y: cy + 6,
          'text-anchor': 'middle',
          fill: '#fff',
          'font-size': '14',
          'font-family': 'Cairo,sans-serif',
          'font-weight': '700',
          'pointer-events': 'none'
        }, SVG).textContent = ic;
        c.addEventListener('mouseenter', e => showTip(e, node));
        c.addEventListener('mousemove', e => mv(e));
        c.addEventListener('mouseleave', hideTip);
        if (node.color === 'blue' && node.user_id)
          c.addEventListener('click', () => openR(node.user_id, node.name));
      }

      draw();
      window.addEventListener('resize', draw);
    })();
  </script>
</body>

</html>