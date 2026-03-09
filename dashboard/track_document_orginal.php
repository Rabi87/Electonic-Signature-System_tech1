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
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --success-color: #28a745;
            --primary-color: #007bff;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --secondary-color: #6c757d;
            --info-color: #17a2b8;
            --orange-color: #fd7e14;
        }

        body {
            background: #f8f9fa;
            font-family: 'Cairo', sans-serif;
        }

        .tracking-container {
            padding: 15px;
        }

        .order-tracking {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .header-section {
            background: white;
            color: #333;
            padding: 50px;
            border-radius: 10px;



        }

        .back-btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
            transition: background 0.3s;
        }

        .back-btn:hover {
            background: #2980b9;
            color: white;
            text-decoration: none;
        }

        .document-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 15px;
        }

        .document-title {

            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 5px;
            padding: 3px 10px;

        }

        .document-id {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 5px;
            padding: 3px 10px;


        }

        /* تتبع المسار الأفقي - تصميم جديد */
        .order-tracking {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            justify-content: center;
        }

        .tracking-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .tracking-header h2 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 1.8rem;
        }

        .tracking-header p {
            color: #7f8c8d;
            font-size: 1rem;
        }

        /* التصميم الجديد المدمج (رأسي + أفقي) */
        .tracking-combined {
            display: flex;
            align-items: stretch;
            justify-content: center;
            gap: 30px;
            margin: 40px 0;
            flex-wrap: wrap;
        }

        /* الجهة اليمنى: العمود الرأسي */
        .vertical-steps {
            display: flex;
            flex-direction: column;
            justify-content: space-around;
            gap: 30px;
            position: relative;
            min-width: 200px;
            margin-left: -30px;
            /* لجذب الدوائر نحو الخط الرابط */
        }

        .vertical-step {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }

        .vertical-step .step-circle {
            margin: 0;
        }

        /* إزالة الأنماط التي تجعل المعلومات ظاهرة دائماً */
        .vertical-step .step-info {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            left: auto;
            right: 70px;
            /* يظهر على يسار الدائرة */
            width: 200px;
        }

        .vertical-step .step-info::before {
            left: auto;
            right: -8px;
            transform: translateY(-50%);
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-bottom: 8px solid white;
            border-top: none;
            top: 50%;
        }

        /* الخط الأفقي الرابط */
        .horizontal-connector {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            width: 60px;
        }

        .connector-line {
            width: 4px;
            height: 100%;
            background: #3498db;
            border-radius: 2px;
        }

        .connector-arrow {
            background: #3498db;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-top: 10px;
        }

        /* الجهة اليسرى: المسار الأفقي */
        .horizontal-steps {
            display: flex;
            align-items: center;
            gap: 30px;
            position: relative;
            flex-wrap: wrap;
        }

        .horizontal-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }

        /* الخطوط بين الدوائر الأفقية */
        .horizontal-step .step-connector {
            position: absolute;
            top: 25px;
            right: -30px;
            width: 30px;
            height: 4px;
            background: #e0e0e0;
        }

        .horizontal-step .step-connector.active {
            background: #28a745;
        }

        /* إعادة استخدام الأنماط القديمة للدوائر */
        .step-circle {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 15px;
            border: 4px solid white;
            box-shadow: 0 0 0 4px #e0e0e0;
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
        }

        .step-circle.green {
            background-color: #28a745;
            box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.3);
            color: white;
        }

        .step-circle.blue {
            background-color: #007bff;
            box-shadow: 0 0 0 4px rgba(0, 123, 255, 0.3);
            color: white;
            animation: pulse-blue 2s infinite;
            position: relative;
        }

        .step-circle.blue:hover::after {
            content: 'تفقد المستند';
            position: absolute;
            top: -35px;
            right: 50%;
            transform: translateX(50%);
            background: #2c3e50;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            white-space: nowrap;
            z-index: 100;
            font-family: 'Cairo', sans-serif;
        }

        .step-circle.blue:hover::before {
            content: '';
            position: absolute;
            top: -8px;
            right: 50%;
            transform: translateX(50%);
            width: 0;
            height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 5px solid #2c3e50;
            z-index: 100;
        }

        .step-circle.gray {
            background-color: #6c757d;
            box-shadow: 0 0 0 4px rgba(108, 117, 125, 0.3);
            color: white;
        }

        .step-circle.red {
            background-color: #dc3545;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.3);
            color: white;
        }

        .step-circle.orange {
            background-color: #fd7e14;
            box-shadow: 0 0 0 4px rgba(253, 126, 20, 0.3);
            color: white;
        }

        .step-info {
            text-align: center;
            margin-top: 10px;
            padding: 0 5px;
            width: 100%;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            position: absolute;
            top: 60px;
            background: white;
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 30;
            width: 200px;
            min-height: 80px;
        }

        .step-circle:hover+.step-info,
        .step-info:hover {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .step-info::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-bottom: 8px solid white;
        }

        .step-title {
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 1.1rem;
        }

        .step-date {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 8px;
            direction: ltr;
            text-align: center;
        }

        .step-user {
            font-size: 0.85rem;
            color: #495057;
            background: #f8f9fa;
            padding: 3px 8px;
            border-radius: 4px;
            margin-top: 5px;
            display: inline-block;
        }

        .step-department {
            display: block;
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 2px;
        }

        @keyframes pulse-blue {
            0% {
                box-shadow: 0 0 0 0 rgba(0, 123, 255, 0.4);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(0, 123, 255, 0.73);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(0, 123, 255, 0);
            }
        }

        /* قسم التواقيع */
        .signatures-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-top: 20px;
        }

        .signatures-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .signature-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .signature-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .signature-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            font-weight: bold;
        }

        .signature-info {
            flex: 1;
        }

        .signature-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 3px;
        }

        .signature-role {
            font-size: 0.85rem;
            color: #7f8c8d;
            margin-bottom: 2px;
        }

        .signature-department {
            font-size: 0.75rem;
            color: #6c757d;
            margin-bottom: 5px;
        }

        .signature-date {
            font-size: 0.8rem;
            color: #95a5a6;
        }

        /* تصميم متجاوب */
        @media (max-width: 768px) {
            .tracking-combined {
                flex-direction: column;
                align-items: center;
            }

            .horizontal-connector {
                width: 100%;
                height: 60px;
                flex-direction: row;
            }

            .connector-line {
                width: 100%;
                height: 4px;
            }

            .connector-arrow {
                margin-top: 0;
                margin-right: 10px;
            }

            .horizontal-steps {
                flex-direction: column;
            }

            .horizontal-step .step-connector {
                display: none;
            }

            .vertical-steps {
                margin-left: 0;
            }

            .vertical-step .step-info {
                right: 50px;
            }
        }

        .step-circle.clickable {
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .step-circle.clickable:hover {
            transform: scale(1.1);
            box-shadow: 0 0 20px rgba(0, 123, 255, 0.5);
        }

        /* نافذة منبثقة للتذكير */
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
            background: white;
            width: 90%;
            max-width: 400px;
            border-radius: 10px;
            overflow: hidden;
        }

        .reminder-modal-header {
            padding: 15px 20px;
            background: #3498db;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .reminder-modal-body {
            padding: 20px;
        }

        .reminder-modal-footer {
            padding: 15px 20px;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .order-tracking {
            display: flex;
            gap: 30px;
        }
    </style>
</head>

<body>
    <div class="tracking-container">

        <!-- رأس الصفحة مع معلومات المستند -->
        <div class="header-section">
            <a href="javascript:history.back()" class="back-btn">
                <i class="fas fa-arrow-right"></i> العودة
            </a>
            <div class="document-info">
                <div>
                    <i class="fas fa-file-alt text-primary"></i>
                    <span class="fw-bold">رقم المستند:</span>
                    <span class="badge bg-light text-dark"><?php echo $document_display_number; ?></span>
                </div>
                <div>
                    <i class="fas fa-tag text-primary"></i>
                    <span class="fw-bold">العنوان:</span>
                    <span><?php echo htmlspecialchars($document['title']); ?></span>
                </div>
                <div>
                    <i class="fas fa-circle"></i>
                    <span class="fw-bold">الحالة:</span>
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
                    $status_text = $status_labels[$document['current_status']] ?? $document['current_status'];
                    ?>
                    <span class="badge bg-<?php echo $status_class; ?> badge-status"><?php echo $status_text; ?></span>
                </div>
            </div>
        </div>

        <!-- مفتاح الألوان -->
        <div style="display: flex; justify-content: center; gap: 20px; margin-top: 30px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 20px; height: 20px; border-radius: 50%; background-color: #007bff; animation: pulse-blue 2s infinite;"></div>
                <span style="font-size: 0.85rem;">قيد المعالجة</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 20px; height: 20px; border-radius: 50%; background-color: #28a745;"></div>
                <span style="font-size: 0.85rem;">مكتمل</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 20px; height: 20px; border-radius: 50%; background-color: #dc3545;"></div>
                <span style="font-size: 0.85rem;">مرفوض</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 20px; height: 20px; border-radius: 50%; background-color: #6c757d;"></div>
                <span style="font-size: 0.85rem;">بانتظار</span>
            </div>
        </div>

        <!-- بداية المسار المدمج (رأسي + أفقي) -->
        <div class="tracking-combined">
            <!-- الجهة اليمنى: عرض رأسي للأدوار الأولى (موظف، رئيس قسم، مدير دائرة) -->
            <div class="vertical-steps">
                <?php
                // فلترة الأدوار الرأسية: الموظف، رئيس القسم، مدير الدائرة
                $vertical_roles = array_filter($timeline_data, function ($step) {
                    return in_array($step['role_name'], ['موظف', 'رئيس القسم', 'مدير الدائرة']);
                });
                // إعادة ترتيبهم حسب التسلسل الصحيح (موظف ← رئيس قسم ← مدير دائرة) - نفترض أنهم بالترتيب
                usort($vertical_roles, function ($a, $b) {
                    $order = ['موظف' => 1, 'رئيس القسم' => 2, 'مدير الدائرة' => 3];
                    return ($order[$a['role_name']] ?? 99) <=> ($order[$b['role_name']] ?? 99);
                });

                foreach ($vertical_roles as $index => $step):
                ?>
                    <div class="tracking-step vertical-step" data-user-id="<?php echo $step['current_user_id'] ?? ''; ?>"
                        data-role-id="<?php echo $step['role_id']; ?>"
                        data-is-current="<?php echo $step['is_current'] ? 'true' : 'false'; ?>">

                        <!-- الدائرة -->
                        <div class="step-circle <?php echo $step['color']; ?> clickable">
                            <i class="<?php echo $step['icon']; ?>"></i>
                        </div>

                        <!-- المعلومات (تظهر عند hover) -->
                        <div class="step-info">
                            <div class="step-title"><?php echo htmlspecialchars($step['role_name']); ?></div>
                            <?php if (!empty($step['date'])): ?>
                                <div class="step-date">
                                    <i class="far fa-calendar"></i> <?php echo $step['date']; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($step['current_user_name'])): ?>
                                <div class="step-user">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($step['current_user_name']); ?>
                                    <?php if (!empty($step['current_user_department'])): ?>
                                        <span class="step-department">
                                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($step['current_user_department']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="step-status" style="margin-top:5px;"><?php echo $step['status_text']; ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- الخط الأفقي الرابط بين العمود الرأسي والمسار الأفقي -->
            <div class="horizontal-connector">
                <div class="connector-line"></div>
                <div class="connector-arrow">→</div>
            </div>

            <!-- الجهة اليسرى: عرض أفقي للأدوار العليا (الديوان الخاص، الديوان، نائب الرئيس التنفيذي، الرئيس التنفيذي) -->
            <div class="horizontal-steps">
                <?php
                // فلترة الأدوار الأفقية: الديوان الخاص (private_board) ، الديوان (board)، نائب الرئيس التنفيذي (deputy_ceo)، الرئيس التنفيذي (ceo)
                $horizontal_roles = array_filter($timeline_data, function ($step) {
                    return in_array($step['role_name'], ['الديوان الخاص', 'الديوان', 'نائب الرئيس التنفيذي', 'الرئيس التنفيذي', 'الديوان الفرعي','مدير المكتب']);
                });
                // ترتيبهم حسب التسلسل الصحيح: الديوان الخاص ← الديوان ← نائب الرئيس التنفيذي ← الرئيس التنفيذي
                usort($horizontal_roles, function ($a, $b) {
                    $order = [
                        'الديوان الخاص' => 1,
                        'الديوان الفرعي' => 2,
                        'الديوان' => 3,
                        'مدير المكتب' => 4,
                        'نائب الرئيس التنفيذي' => 5,
                        'الرئيس التنفيذي' => 6
                    ];
                    return ($order[$a['role_name']] ?? 99) <=> ($order[$b['role_name']] ?? 99);
                });

                foreach ($horizontal_roles as $index => $step):
                ?>
                    <div class="tracking-step horizontal-step" data-user-id="<?php echo $step['current_user_id'] ?? ''; ?>"
                        data-role-id="<?php echo $step['role_id']; ?>"
                        data-is-current="<?php echo $step['is_current'] ? 'true' : 'false'; ?>">

                        <!-- التوصيل بين الدوائر الأفقية (ما عدا الأولى) -->
                        <?php if ($index > 0): ?>
                            <?php
                            $prev_step = $horizontal_roles[$index - 1];
                            $is_connector_active = ($prev_step['color'] != 'gray' && $step['color'] != 'gray');
                            ?>
                            <div class="step-connector <?php echo $is_connector_active ? 'active' : ''; ?>"></div>
                        <?php endif; ?>

                        <!-- الدائرة -->
                        <div class="step-circle <?php echo $step['color']; ?> clickable">
                            <i class="<?php echo $step['icon']; ?>"></i>
                        </div>

                        <!-- المعلومات المخفية (تظهر عند hover) -->
                        <div class="step-info">
                            <div class="step-title"><?php echo htmlspecialchars($step['role_name']); ?></div>
                            <?php if (!empty($step['date'])): ?>
                                <div class="step-date">
                                    <i class="far fa-calendar"></i> <?php echo $step['date']; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($step['current_user_name'])): ?>
                                <div class="step-user">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($step['current_user_name']); ?>
                                    <?php if (!empty($step['current_user_department'])): ?>
                                        <span class="step-department">
                                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($step['current_user_department']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="step-status" style="margin-top:5px;"><?php echo $step['status_text']; ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

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
                            style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; resize: vertical;"
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
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'document_id': documentId,
                        'target_user_id': targetUserId,
                        'custom_message': message
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ تم إرسال التذكير بنجاح');
                        closeReminderModal();
                    } else {
                        alert('❌ ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ أثناء إرسال التذكير');
                });
        }

        // تعديل الدوائر لتكون قابلة للنقر واظهار المعلومات
        document.addEventListener('DOMContentLoaded', function() {
            // البحث عن جميع الدوائر
            const stepCircles = document.querySelectorAll('.step-circle');

            stepCircles.forEach(circle => {
                // إضافة حدث النقر لإرسال التذكير (للدوائر الزرقاء فقط)
                if (circle.classList.contains('blue')) {
                    circle.classList.add('clickable');

                    circle.addEventListener('click', function() {
                        // البحث عن معلومات المستخدم في هذه الخطوة
                        const stepElement = this.closest('.tracking-step');
                        const userId = stepElement.getAttribute('data-user-id');
                        const stepInfo = stepElement.querySelector('.step-info');
                        const userName = stepInfo.querySelector('.step-user')?.textContent.trim();

                        if (userName) {
                            // استخراج اسم المستخدم (إزالة الأيقونة)
                            const cleanUserName = userName.replace('👤', '').trim();

                            // التحقق من وجود معرف المستخدم
                            if (userId && userId !== '' && userId !== 'null' && userId !== 'undefined') {
                                openReminderModal(userId, cleanUserName);
                            } else {
                                const manualUserId = prompt(`لم يتم العثور على معرف المستخدم تلقائيًا.\nيرجى إدخال معرف المستخدم لـ "${cleanUserName}":`);
                                if (manualUserId) {
                                    openReminderModal(manualUserId, cleanUserName);
                                }
                            }
                        } else {
                            alert('لم يتم العثور على اسم المستخدم لهذه الخطوة.');
                        }
                    });
                }

                // إضافة تأثير hover للدائرة
                circle.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.1)';
                });

                circle.addEventListener('mouseleave', function() {
                    if (!this.classList.contains('blue')) {
                        this.style.transform = 'scale(1)';
                    }
                });
            });

            // إغلاق النافذة عند النقر خارجها
            document.getElementById('reminderModal').addEventListener('click', function(event) {
                if (event.target === this) {
                    closeReminderModal();
                }
            });

            // تحريك الصفحة تلقائياً إلى الخطوة الحالية (إن وجدت)
            const currentStep = document.querySelector('.step-circle.blue');
            if (currentStep) {
                setTimeout(() => {
                    currentStep.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }, 500);
            }

            // إضافة تأثيرات للخطوات
            stepCircles.forEach((circle, index) => {
                // تأخير ظهور العناصر تدريجياً
                setTimeout(() => {
                    circle.style.opacity = '1';
                    circle.style.transform = 'scale(1)';
                }, index * 200);
            });
        });

        function goBack() {
            window.history.back();
        }
    </script>
</body>

</html>