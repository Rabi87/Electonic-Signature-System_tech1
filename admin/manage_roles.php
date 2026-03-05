<?php
/**
 * إدارة الأدوار - نظام التوقيع الإلكتروني
 * 
 * هذا الملف يمكن المسؤول من إدارة الأدوار والصلاحيات في النظام
 */
require_once '../includes/session.php';
checkLogin();
// تحميل ملفات الإعدادات أولاً
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من أن المستخدم مسجل دخول وهو مسؤول
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$db = getDB();

// جلب جميع الأدوار مع عدد المستخدمين في كل دور - مرتبة حسب التسلسل الهرمي
$roles = $db->query("
    SELECT r.*, COUNT(u.id) as user_count 
    FROM roles r 
    LEFT JOIN users u ON r.id = u.role_id 
    GROUP BY r.id 
    ORDER BY 
        CASE 
            WHEN r.role_name = 'board' THEN 1
            WHEN r.role_name = 'ceo' THEN 2
            WHEN r.role_name = 'admin' THEN 3
            WHEN r.role_name = 'department_manager' THEN 4
            WHEN r.role_name = 'section_manager' THEN 5
            ELSE 6
        END,
        r.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// جلب جميع الصلاحيات
$permissions = $db->query("SELECT * FROM permissions ORDER BY permission_name")->fetchAll(PDO::FETCH_ASSOC);

// جدد صلاحيات كل دور
$role_permissions = [];
foreach ($roles as $role) {
    $stmt = $db->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
    $stmt->execute([$role['id']]);
    $role_permissions[$role['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}


// معالجة طلبات AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];

    header('Content-Type: application/json');

    try {
        switch ($action) {
            case 'add_role':
                $role_name = $_POST['role_name'] ?? '';
                $description = $_POST['description'] ?? '';
                $parent_role_id = $_POST['parent_role_id'] ?? null;
if ($parent_role_id === '') {
    $parent_role_id = null;
}

                if (empty($role_name)) {
                    $response['message'] = 'اسم الدور مطلوب';
                    break;
                }

                // التحقق من عدم تكرار اسم الدور
                $check_stmt = $db->prepare("SELECT id FROM roles WHERE role_name = ?");
                $check_stmt->execute([$role_name]);
                if ($check_stmt->fetch()) {
                    $response['message'] = 'اسم الدور موجود مسبقاً';
                    break;
                }

                // منع جعل دور "board" تابعاً لأي دور آخر
                if ($role_name == 'board' && !empty($parent_role_id)) {
                    $response['message'] = 'دور "الديوان" يجب أن يكون أعلى دور ولا يمكن أن يكون تابعاً لأي دور آخر';
                    break;
                }

                // التحقق من عدم جعل دور تابع لنفسه
                if (!empty($parent_role_id)) {
                    $parent_stmt = $db->prepare("SELECT role_name FROM roles WHERE id = ?");
                    $parent_stmt->execute([$parent_role_id]);
                    $parent_role = $parent_stmt->fetch();

                    // لا يمكن جعل الديوان تابعاً لأي دور
                    if ($role_name == 'board' && $parent_role) {
                        $response['message'] = 'دور الديوان لا يمكن أن يكون تابعاً لأي دور آخر';
                        break;
                    }
                }

                // إضافة الدور
                $stmt = $db->prepare("INSERT INTO roles (role_name, description, parent_role_id) VALUES (?, ?, ?)");
                $result = $stmt->execute([$role_name, $description, $parent_role_id]);

                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم إضافة الدور بنجاح';
                    $response['role_id'] = $db->lastInsertId();
                } else {
                    $response['message'] = 'حدث خطأ في إضافة الدور';
                }
                break;

            case 'update_role':
                $role_id = $_POST['role_id'] ?? 0;
                $role_name = $_POST['role_name'] ?? '';
                $description = $_POST['description'] ?? '';
                $parent_role_id = $_POST['parent_role_id'] ?? null;
                if ($parent_role_id === '') {
                    $parent_role_id = null;
                }

                if (empty($role_id) || empty($role_name)) {
                    $response['message'] = 'بيانات غير كافية';
                    break;
                }

                // جدد الدور الحالي لمعرفة إذا كان "board"
                $current_stmt = $db->prepare("SELECT role_name FROM roles WHERE id = ?");
                $current_stmt->execute([$role_id]);
                $current_role = $current_stmt->fetch();

                // إذا كان الدور هو "board" فلا يمكن تغيير اسمه
                if ($current_role && $current_role['role_name'] == 'board' && $role_name != 'board') {
                    $response['message'] = 'لا يمكن تغيير اسم دور "الديوان"';
                    break;
                }

                // التحقق من عدم تكرار اسم الدور (باستثناء الدور الحالي)
                $check_stmt = $db->prepare("SELECT id FROM roles WHERE role_name = ? AND id != ?");
                $check_stmt->execute([$role_name, $role_id]);
                if ($check_stmt->fetch()) {
                    $response['message'] = 'اسم الدور موجود مسبقاً';
                    break;
                }

                // منع جعل دور "board" تابعاً لأي دور آخر
                if ($role_name == 'board' && !empty($parent_role_id)) {
                    $response['message'] = 'دور "الديوان" يجب أن يكون أعلى دور ولا يمكن أن يكون تابعاً لأي دور آخر';
                    break;
                }

                // منع إنشاء تسلسل دوري
                if (!empty($parent_role_id)) {
                    if ($parent_role_id == $role_id) {
                        $response['message'] = 'لا يمكن جعل الدور تابعاً لنفسه';
                        break;
                    }

                    // التحقق من التبعية الدورية
                    $current_parent = $parent_role_id;
                    $visited = [$role_id];

                    while ($current_parent) {
                        if (in_array($current_parent, $visited)) {
                            $response['message'] = 'لا يمكن إنشاء تبعية دورية (الدور تابع لدور يتبعه)';
                            break 2;
                        }

                        $visited[] = $current_parent;
                        $stmt = $db->prepare("SELECT parent_role_id FROM roles WHERE id = ?");
                        $stmt->execute([$current_parent]);
                        $result = $stmt->fetch();
                        $current_parent = $result ? $result['parent_role_id'] : null;
                    }
                }

                // تحديث الدور
                $stmt = $db->prepare("UPDATE roles SET role_name = ?, description = ?, parent_role_id = ? WHERE id = ?");
                $result = $stmt->execute([$role_name, $description, $parent_role_id, $role_id]);

                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم تحديث الدور بنجاح';
                } else {
                    $response['message'] = 'حدث خطأ في تحديث الدور';
                }
                break;

            case 'delete_role':
                $role_id = $_POST['role_id'] ?? 0;

                if (empty($role_id)) {
                    $response['message'] = 'معرف الدور مطلوب';
                    break;
                }

                // لا يمكن حذف دور الديوان
                if ($role_id == 1) {
                    $response['message'] = 'لا يمكن حذف دور الديوان';
                    break;
                }

                // لا يمكن حذف دور "board"
                $check_stmt = $db->prepare("SELECT role_name FROM roles WHERE id = ?");
                $check_stmt->execute([$role_id]);
                $role_data = $check_stmt->fetch();

                if ($role_data && $role_data['role_name'] == 'board') {
                    $response['message'] = 'لا يمكن حذف دور "الديوان"';
                    break;
                }

                // التحقق من وجود مستخدمين في هذا الدور
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
                $check_stmt->execute([$role_id]);
                $user_count = $check_stmt->fetchColumn();

                if ($user_count > 0) {
                    $response['message'] = 'لا يمكن حذف الدور لأنه يحتوي على مستخدمين';
                    break;
                }

                // التحقق من وجود أدوار فرعية
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM roles WHERE parent_role_id = ?");
                $check_stmt->execute([$role_id]);
                $child_count = $check_stmt->fetchColumn();

                if ($child_count > 0) {
                    $response['message'] = 'لا يمكن حذف الدور لأنه يحتوي على أدوار فرعية';
                    break;
                }

                // حذف صلاحيات الدور أولاً
                $delete_perms_stmt = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                $delete_perms_stmt->execute([$role_id]);

                // حذف الدور
                $stmt = $db->prepare("DELETE FROM roles WHERE id = ?");
                $result = $stmt->execute([$role_id]);

                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم حذف الدور بنجاح';
                } else {
                    $response['message'] = 'حدث خطأ في حذف الدور';
                }
                break;

            case 'update_permissions':
                $role_id = $_POST['role_id'] ?? 0;
                $permissions_list = $_POST['permissions'] ?? [];

                if (empty($role_id)) {
                    $response['message'] = 'معرف الدور مطلوب';
                    break;
                }

                // حذف الصلاحيات القديمة
                $delete_stmt = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                $delete_stmt->execute([$role_id]);

                // إضافة الصلاحيات الجديدة
                if (!empty($permissions_list)) {
                    $insert_sql = "INSERT INTO role_permissions (role_id, permission_id) VALUES ";
                    $insert_values = [];
                    $insert_params = [];

                    foreach ($permissions_list as $perm_id) {
                        $insert_values[] = "(?, ?)";
                        $insert_params[] = $role_id;
                        $insert_params[] = $perm_id;
                    }

                    $insert_sql .= implode(', ', $insert_values);
                    $insert_stmt = $db->prepare($insert_sql);
                    $insert_stmt->execute($insert_params);
                }

                $response['success'] = true;
                $response['message'] = 'تم تحديث الصلاحيات بنجاح';
                break;

            case 'add_permission':
                $permission_name = $_POST['permission_name'] ?? '';
                $description = $_POST['description'] ?? '';

                if (empty($permission_name)) {
                    $response['message'] = 'اسم الصلاحية مطلوب';
                    break;
                }

                // التحقق من عدم تكرار اسم الصلاحية
                $check_stmt = $db->prepare("SELECT id FROM permissions WHERE permission_name = ?");
                $check_stmt->execute([$permission_name]);
                if ($check_stmt->fetch()) {
                    $response['message'] = 'اسم الصلاحية موجود مسبقاً';
                    break;
                }

                // إضافة الصلاحية
                $stmt = $db->prepare("INSERT INTO permissions (permission_name, description) VALUES (?, ?)");
                $result = $stmt->execute([$permission_name, $description]);

                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم إضافة الصلاحية بنجاح';
                    $response['permission_id'] = $db->lastInsertId();
                } else {
                    $response['message'] = 'حدث خطأ في إضافة الصلاحية';
                }
                break;

            default:
                $response['message'] = 'عملية غير معروفة';
        }
    } catch (PDOException $e) {
        $response['message'] = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
    }

    echo json_encode($response);
    exit();
}

// API للحصول على صلاحيات دور معين
if (isset($_GET['action']) && $_GET['action'] == 'get_permissions') {
    $role_id = $_GET['role_id'] ?? 0;

    // جلب جميع الصلاحيات
    $permissions = $db->query("SELECT * FROM permissions ORDER BY permission_name")->fetchAll(PDO::FETCH_ASSOC);

    // جدد صلاحيات الدور المحدد
    $stmt = $db->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
    $stmt->execute([$role_id]);
    $role_permissions = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'permissions' => $permissions,
        'role_permissions' => $role_permissions
    ]);
    exit();
}



// دالة لبناء شجرة الأدوار
function buildRoleTree($roles, $parent_id = null, $level = 0)
{
    $html = '';

    // فرز الأدوار حسب التسلسل الهرمي
    usort($roles, function ($a, $b) {
        $order = ['board' => 1, 'ceo' => 2, 'admin' => 3, 'department_manager' => 4, 'section_manager' => 5];
        $orderA = $order[$a['role_name']] ?? 6;
        $orderB = $order[$b['role_name']] ?? 6;
        return $orderA - $orderB;
    });

    foreach ($roles as $role) {
        if ($role['parent_role_id'] == $parent_id) {
            // تحديد أيقونة حسب نوع الدور
            $icon = 'fas fa-user-tag';
            $role_class = '';

            switch ($role['role_name']) {
                case 'board':
                    $icon = 'fas fa-crown';
                    $role_class = 'board-role';
                    break;
                case 'ceo':
                    $icon = 'fas fa-user-tie';
                    $role_class = 'ceo-role';
                    break;
                case 'admin':
                    $icon = 'fas fa-user-shield';
                    $role_class = 'admin-role';
                    break;
                case 'department_manager':
                    $icon = 'fas fa-user-cog';
                    $role_class = 'dept-manager-role';
                    break;
                case 'section_manager':
                    $icon = 'fas fa-user-md';
                    $role_class = 'section-manager-role';
                    break;
            }

            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level * 2);
            $html .= '<li class="' . $role_class . '">';
            $html .= $indent . '<i class="' . $icon . '"></i> ';
            $html .= '<strong>' . htmlspecialchars($role['role_name']) . '</strong>';

            // إضافة وصف إذا موجود
            if ($role['description']) {
                $html .= '<br><small style="color: #6c757d; margin-right: 10px;">' . htmlspecialchars($role['description']) . '</small>';
            }

            $html .= ' <span class="badge badge-warning">' . $role['user_count'] . ' مستخدم</span>';

            // إضافة أدوار فرعية
            $children = buildRoleTree($roles, $role['id'], $level + 1);
            if ($children) {
                $html .= '<ul>' . $children . '</ul>';
            }

            $html .= '</li>';
        }
    }
    return $html;
}
// دالة للحصول على التسلسل الهرمي الكامل لدور معين (الإصدار المصحح)
function getRoleHierarchy($roles, $role_id)
{
    $hierarchy = [];
    $current_id = $role_id;
    $visited = []; // لمنع الحلقات اللا نهائية

    while ($current_id && !in_array($current_id, $visited)) {
        $visited[] = $current_id;
        $found = false;

        foreach ($roles as $role) {
            if ($role['id'] == $current_id) {
                $hierarchy[] = $role;
                $current_id = $role['parent_role_id'];
                $found = true;
                break;
            }
        }

        if (!$found) {
            break;
        }

        // لمنع الحلقات اللا نهائية، نحدد حد أقصى
        if (count($visited) > count($roles) * 2) {
            break;
        }
    }

    return array_reverse($hierarchy); // من الأعلى إلى الأسفل
}
?>
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الأدوار - نظام التوقيع الإلكتروني</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .roles-management {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }

        .page-header h1 {
            color: #2c3e50;
            font-size: 1.8rem;
            margin: 0;
        }

        /* إحصائيات */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            text-align: center;
            border-top: 4px solid #3498db;
        }

        .stat-card h3 {
            font-size: 2.5rem;
            margin: 0 0 10px;
            color: #2c3e50;
        }

        .stat-card p {
            margin: 0;
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .total-roles {
            border-top-color: #3498db;
        }

        .total-permissions {
            border-top-color: #2ecc71;
        }

        .hierarchy-roles {
            border-top-color: #9b59b6;
        }

        /* التسلسل الهرمي */
        .role-hierarchy-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            padding: 25px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .hierarchy-levels {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 20px;
        }

        .hierarchy-level {
            display: flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.1);
            padding: 12px 20px;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .hierarchy-level:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(-5px);
        }

        .hierarchy-level .level-number {
            background: white;
            color: #667eea;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 15px;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .hierarchy-level .level-info {
            flex: 1;
        }

        .hierarchy-level .level-info h4 {
            margin: 0 0 5px;
            font-size: 1.1rem;
        }

        .hierarchy-level .level-info p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* أقسام الصفحة */
        .sections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 30px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .sections-grid {
                grid-template-columns: 1fr;
            }
        }

        .section-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .section-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #eaeaea;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-body {
            padding: 20px;
        }

        /* جدول الأدوار */
        .roles-table {
            width: 100%;
            border-collapse: collapse;
        }

        .roles-table th {
            padding: 15px 10px;
            text-align: right;
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            color: #495057;
            font-weight: 600;
        }

        .roles-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #dee2e6;
            color: #495057;
        }

        .roles-table tr:hover {
            background: #f8f9fa;
        }

        /* تنسيقات خاصة للأدوار */
        .board-role {
            color: #e74c3c;
            font-weight: bold;
        }

        .ceo-role {
            color: #3498db;
        }

        .admin-role {
            color: #2ecc71;
        }

        .dept-manager-role {
            color: #f39c12;
        }

        .section-manager-role {
            color: #9b59b6;
        }

        /* الصلاحيات */
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .permission-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .permission-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 3px;
        }

        .permission-item label {
            cursor: pointer;
            user-select: none;
            flex: 1;
        }

        .permission-item small {
            display: block;
            color: #6c757d;
            margin-top: 5px;
            font-size: 0.85rem;
        }

        /* أزرار الإجراءات */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        /* شجرة الأدوار */
        .role-tree-container {
            max-height: 400px;
            overflow-y: auto;
            padding: 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            background: #fdfdfd;
        }

        .role-tree {
            list-style: none;
            padding-right: 0;
        }

        .role-tree ul {
            list-style: none;
            padding-right: 25px;
            margin-top: 10px;
            border-right: 2px dashed #dee2e6;
        }

        .role-tree li {
            margin-bottom: 10px;
            position: relative;
            padding: 8px 0;
        }

        .role-tree li:before {
            content: "├─ ";
            position: absolute;
            right: -20px;
            color: #6c757d;
            font-size: 1.2rem;
        }

        .role-tree li:last-child:before {
            content: "└─ ";
        }

        /* رسائل المعلومات */
        .info-box {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .info-box h4 {
            margin-top: 0;
            color: #0066cc;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* النماذج المنبثقة */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            max-height: 80vh;
            overflow-y: auto;
            width: 90%;
            max-width: 600px;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #7f8c8d;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-family: 'Cairo', sans-serif;
            font-size: 1rem;
        }

        .form-control:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        /* تصميم متجاوب */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .action-buttons {
                flex-wrap: wrap;
            }

            .modal-content {
                width: 95%;
                margin: 10% auto;
            }

            .role-hierarchy-info {
                padding: 15px;
            }

            .hierarchy-levels {
                gap: 10px;
            }
        }

        /* تنسيقات إضافية */
        .badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .badge-info {
            background: #17a2b8;
            color: white;
        }

        .badge-warning {
            background: #ffc107;
            color: #212529;
        }

        .badge-success {
            background: #28a745;
            color: white;
        }

        .badge-danger {
            background: #dc3545;
            color: white;
        }

        /* تحسينات للشجرة */
        .tree-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }

        .legend-board {
            background: #e74c3c;
        }

        .legend-ceo {
            background: #3498db;
        }

        .legend-admin {
            background: #2ecc71;
        }

        .legend-dept {
            background: #f39c12;
        }

        .legend-section {
            background: #9b59b6;
        }
    </style>
</head>

<body>
    <?php
    // إنشاء رأس بسيط إذا لم يكن الملف موجوداً
    if (!file_exists('../includes/admin_header.php')): ?>
        <div
            style="background: #2c3e50; color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 15px;">
                <div
                    style="width: 40px; height: 40px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                    <?php echo mb_substr($_SESSION['full_name'], 0, 1, 'UTF-8'); ?>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.2rem;">مرحباً،
                        <?php echo htmlspecialchars($_SESSION['full_name']); ?></h3>
                    <p style="margin: 0; font-size: 0.85rem; opacity: 0.8;">
                        <?php echo htmlspecialchars($_SESSION['role_name']); ?></p>
                </div>
            </div>

            <nav style="display: flex; gap: 20px; align-items: center;">
                <a href="dashboard_admin.php"
                    style="color: white; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                    <i class="fas fa-tachometer-alt"></i> الرئيسية
                </a>
                <a href="manage_users.php"
                    style="color: white; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                    <i class="fas fa-users"></i> المستخدمين
                </a>
                <a href="manage_roles.php"
                    style="color: white; text-decoration: none; display: flex; align-items: center; gap: 5px; background: #3498db; padding: 8px 15px; border-radius: 5px;">
                    <i class="fas fa-user-tag"></i> الأدوار
                </a>
                <a href="../logout.php"
                    style="color: white; text-decoration: none; display: flex; align-items: center; gap: 5px; background: #e74c3c; padding: 8px 15px; border-radius: 5px;">
                    <i class="fas fa-sign-out-alt"></i> خروج
                </a>
            </nav>
        </div>
    <?php else: ?>
        <?php include '../includes/admin_header.php'; ?>
    <?php endif; ?>

    <div class="roles-management">
        <div class="page-header">
            <h1><i class="fas fa-user-tag"></i> إدارة الأدوار والصلاحيات</h1>
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="openAddRoleModal()">
                    <i class="fas fa-plus"></i> إضافة دور جديد
                </button>
                <button class="btn btn-success" onclick="openAddPermissionModal()">
                    <i class="fas fa-key"></i> إضافة صلاحية
                </button>
            </div>
        </div>

        <!-- معلومات التسلسل الهرمي -->
        <div class="role-hierarchy-info">
            <h3 style="margin-top: 0; color: white; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-sitemap"></i> التسلسل الهرمي الإداري للنظام
            </h3>

            <div class="hierarchy-levels">
                <div class="hierarchy-level">
                    <div class="level-number">1</div>
                    <div class="level-info">
                        <h4>الديوان (Board)</h4>
                        <p>أعلى سلطة إدارية في النظام، يشرف على المدير التنفيذي والعمليات الإستراتيجية</p>
                    </div>
                </div>

                <div class="hierarchy-level">
                    <div class="level-number">2</div>
                    <div class="level-info">
                        <h4>المدير التنفيذي (CEO)</h4>
                        <p>يتبع للديوان، يشرف على المديرين التنفيذيين والعمليات التشغيلية</p>
                    </div>
                </div>

                <div class="hierarchy-level">
                    <div class="level-number">3</div>
                    <div class="level-info">
                        <h4>مدير القسم (Department Manager)</h4>
                        <p>يتبع للمدير التنفيذي، يدير قسم محدد في المنظمة</p>
                    </div>
                </div>

                <div class="hierarchy-level">
                    <div class="level-number">4</div>
                    <div class="level-info">
                        <h4>مدير الشعبة (Section Manager)</h4>
                        <p>يتبع لمدير القسم، يدير شعبة محددة داخل القسم</p>
                    </div>
                </div>

                <div class="hierarchy-level">
                    <div class="level-number">5</div>
                    <div class="level-info">
                        <h4>المسؤول (Admin)</h4>
                        <p>يتمتع بصلاحيات تقنية لإدارة النظام والصيانة</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- إحصائيات -->
        <div class="stats-cards">
            <div class="stat-card total-roles">
                <h3><?php echo count($roles); ?></h3>
                <p>إجمالي الأدوار</p>
            </div>
            <div class="stat-card total-permissions">
                <h3><?php echo count($permissions); ?></h3>
                <p>إجمالي الصلاحيات</p>
            </div>
            <div class="stat-card hierarchy-roles">
                <h3><?php echo count(array_filter($roles, fn($r) => !empty($r['parent_role_id']))); ?></h3>
                <p>أدوار فرعية</p>
            </div>
        </div>

        <!-- أسطورة الألوان -->
        <div class="tree-legend">
            <div class="legend-item">
                <div class="legend-color legend-board"></div>
                <span>الديوان (Board)</span>
            </div>
            <div class="legend-item">
                <div class="legend-color legend-ceo"></div>
                <span>المدير التنفيذي (CEO)</span>
            </div>
            <div class="legend-item">
                <div class="legend-color legend-admin"></div>
                <span>المسؤول (Admin)</span>
            </div>
            <div class="legend-item">
                <div class="legend-color legend-dept"></div>
                <span>مدير القسم</span>
            </div>
            <div class="legend-item">
                <div class="legend-color legend-section"></div>
                <span>مدير الشعبة</span>
            </div>
        </div>

        <!-- أقسام الصفحة -->
        <div class="sections-grid">
            <!-- قسم الأدوار -->
            <div class="section-card">
                <div class="section-header">
                    <h2><i class="fas fa-list"></i> قائمة الأدوار</h2>
                </div>
                <div class="section-body">
                    <?php if (empty($roles)): ?>
                        <div style="text-align: center; padding: 20px; color: #6c757d;">
                            <i class="fas fa-user-tag" style="font-size: 3rem; margin-bottom: 15px; color: #dee2e6;"></i>
                            <p>لا توجد أدوار مضافة بعد</p>
                            <button class="btn btn-primary" onclick="openAddRoleModal()">
                                <i class="fas fa-plus"></i> إضافة أول دور
                            </button>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="roles-table">
                                <thead>
                                    <tr>
                                        <th>اسم الدور</th>
                                        <th>الوصف</th>
                                        <th>التسلسل</th>
                                        <th>المستخدمين</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($roles as $role): ?>
                                        <?php
                                        $hierarchy = getRoleHierarchy($roles, $role['id']);
                                        $hierarchy_text = '';
                                        $count = count($hierarchy);

                                        foreach ($hierarchy as $index => $h_role) {
                                            $hierarchy_text .= $h_role['role_name'];
                                            if ($index < $count - 1) {
                                                $hierarchy_text .= ' → ';
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong style="<?php
                                                if ($role['role_name'] == 'board')
                                                    echo 'color: #e74c3c;';
                                                elseif ($role['role_name'] == 'ceo')
                                                    echo 'color: #3498db;';
                                                elseif ($role['role_name'] == 'admin')
                                                    echo 'color: #2ecc71;';
                                                elseif ($role['role_name'] == 'department_manager')
                                                    echo 'color: #f39c12;';
                                                elseif ($role['role_name'] == 'section_manager')
                                                    echo 'color: #9b59b6;';
                                                ?>">
                                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                                </strong>
                                                <?php if ($role['parent_role_id']): ?>
                                                    <br><small style="color: #6c757d;">(دور فرعي)</small>
                                                <?php else: ?>
                                                    <br><small style="color: #6c757d;">(دور رئيسي)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($role['description'] ?: '-'); ?></td>
                                            <td>
                                                <small style="color: #6c757d; font-size: 0.85rem;">
                                                    <?php echo $hierarchy_text; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span
                                                    class="badge <?php echo $role['user_count'] > 0 ? 'badge-info' : 'badge-warning'; ?>">
                                                    <?php echo $role['user_count']; ?> مستخدم
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-warning"
                                                        onclick="openEditRoleModal(<?php echo htmlspecialchars(json_encode($role)); ?>)">
                                                        <i class="fas fa-edit"></i> تعديل
                                                    </button>
                                                    <button class="btn btn-sm btn-info"
                                                        onclick="openPermissionsModal(<?php echo $role['id']; ?>, '<?php echo htmlspecialchars($role['role_name']); ?>')">
                                                        <i class="fas fa-key"></i> صلاحيات
                                                    </button>
                                                    <?php if ($role['id'] != 1 && $role['user_count'] == 0 && $role['role_name'] != 'board'): ?>
                                                        <button class="btn btn-sm btn-danger"
                                                            onclick="confirmDeleteRole(<?php echo $role['id']; ?>, '<?php echo htmlspecialchars($role['role_name']); ?>')">
                                                            <i class="fas fa-trash"></i> حذف
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- قسم الصلاحيات -->
           <!-- <div class="section-card">
                <div class="section-header">
                    <h2><i class="fas fa-key"></i> قائمة الصلاحيات</h2>
                </div>
                <div class="section-body">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 style="margin: 0; color: #495057;">جميع الصلاحيات المتاحة</h3>
                        <button class="btn btn-sm btn-success" onclick="openAddPermissionModal()">
                            <i class="fas fa-plus"></i> إضافة صلاحية
                        </button>
                    </div>

                    <?php if (empty($permissions)): ?>
                        <div style="text-align: center; padding: 20px; color: #6c757d;">
                            <i class="fas fa-key" style="font-size: 3rem; margin-bottom: 15px; color: #dee2e6;"></i>
                            <p>لا توجد صلاحيات مضافة بعد</p>
                        </div>
                    <?php else: ?>
                        <div class="permissions-grid">
                            <?php foreach ($permissions as $permission): ?>
                                <div class="permission-item">
                                    <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; flex: 1;">
                                        <strong><?php echo htmlspecialchars($permission['permission_name']); ?></strong>
                                        <?php if ($permission['description']): ?>
                                            <br><small><?php echo htmlspecialchars($permission['description']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- شجرة الأدوار -->
     <!--   <div class="section-card" style="margin-top: 30px;">
            <div class="section-header">
                <h2><i class="fas fa-sitemap"></i> الشجرة الهرمية للأدوار</h2>
            </div>
            <div class="section-body">
                <div class="role-tree-container">
                    <ul class="role-tree">
                        <?php echo buildRoleTree($roles); ?>
                    </ul>
                </div>

                <div
                    style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; border: 1px solid #e9ecef;">
                    <p style="margin: 0; color: #6c757d; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-info-circle"></i> الشجرة توضح التسلسل الهرمي للأدوار. الأدوار الفرعية ترث
                        الصلاحيات من الأدوار الرئيسية.
                    </p>
                </div>
            </div>
        </div> -->
    </div>

    <!-- === النماذج المنبثقة === -->

    <!-- نافذة إضافة دور جديد -->
    <div id="addRoleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> إضافة دور جديد</h3>
                <button class="close-modal" onclick="closeModal('addRoleModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addRoleForm">
                    <div class="form-group">
                        <label class="form-label">اسم الدور *</label>
                        <input type="text" name="role_name" class="form-control" required
                            placeholder="مثل: board, ceo, department_manager">
                        <small style="color: #6c757d; display: block; margin-top: 5px;">
                            استخدم أسماء إنجليزية بدون مسافات، مثل: board, ceo, admin
                        </small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الوصف</label>
                        <textarea name="description" class="form-control" rows="3"
                            placeholder="وصف مختصر للدور"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الدور الرئيسي (اختياري)</label>
                        <select name="parent_role_id" class="form-control">
                            <option value="">بدون دور رئيسي (دور رئيسي في التسلسل الإداري)</option>
                            <?php
                            // فرز الأدوار حسب التسلسل الهرمي
                            usort($roles, function ($a, $b) {
                                $order = ['board' => 1, 'ceo' => 2, 'admin' => 3, 'department_manager' => 4, 'section_manager' => 5];
                                $orderA = $order[$a['role_name']] ?? 6;
                                $orderB = $order[$b['role_name']] ?? 6;
                                return $orderA - $orderB;
                            });

                            foreach ($roles as $role):
                                if ($role['role_name'] != 'board' || empty($role['parent_role_id'])) {
                                    ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                        <?php if ($role['parent_role_id']): ?>
                                            (تابع لـ <?php
                                            foreach ($roles as $parent) {
                                                if ($parent['id'] == $role['parent_role_id']) {
                                                    echo htmlspecialchars($parent['role_name']);
                                                    break;
                                                }
                                            }
                                            ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php
                                }
                            endforeach;
                            ?>
                        </select>
                        <small style="color: #6c757d; display: block; margin-top: 5px;">
                            ملاحظة: دور "board" يجب أن يكون دائماً دوراً رئيسياً بدون تبعية
                        </small>
                    </div>
                    <div class="alert alert-info"
                        style="padding: 10px; background: #e7f3ff; border-radius: 5px; margin-bottom: 15px;">
                        <i class="fas fa-info-circle"></i>
                        <strong>تذكر:</strong> التسلسل الهرمي هو: board → ceo → department_manager → section_manager
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                        <i class="fas fa-save"></i> حفظ الدور
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- نافذة تعديل دور -->
    <div id="editRoleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> تعديل الدور</h3>
                <button class="close-modal" onclick="closeModal('editRoleModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editRoleForm">
                    <input type="hidden" name="role_id" id="edit_role_id">
                    <div class="form-group">
                        <label class="form-label">اسم الدور *</label>
                        <input type="text" name="role_name" id="edit_role_name" class="form-control" required>
                        <small style="color: #6c757d; display: block; margin-top: 5px;" id="edit_role_help">
                            <!-- سيتم تعبئته بالجافاسكريبت -->
                        </small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الوصف</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الدور الرئيسي (اختياري)</label>
                        <select name="parent_role_id" id="edit_parent_role_id" class="form-control">
                            <option value="">بدون دور رئيسي</option>
                            <?php
                            // فرز الأدوار حسب التسلسل الهرمي
                            usort($roles, function ($a, $b) {
                                $order = ['board' => 1, 'ceo' => 2, 'admin' => 3, 'department_manager' => 4, 'section_manager' => 5];
                                $orderA = $order[$a['role_name']] ?? 6;
                                $orderB = $order[$b['role_name']] ?? 6;
                                return $orderA - $orderB;
                            });

                            foreach ($roles as $role):
                                ?>
                                <option value="<?php echo $role['id']; ?>">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                        <i class="fas fa-save"></i> حفظ التغييرات
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- نافذة إدارة الصلاحيات -->
    <div id="permissionsModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> إدارة الصلاحيات - <span id="permissions_role_name"></span></h3>
                <button class="close-modal" onclick="closeModal('permissionsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="permissions_role_id">
                <div class="permissions-grid" id="permissionsContainer">
                    <!-- سيتم ملؤها بالجافاسكريبت -->
                </div>
                <div style="margin-top: 20px; text-align: center;">
                    <button class="btn btn-success" onclick="savePermissions()" style="padding: 10px 30px;">
                        <i class="fas fa-save"></i> حفظ الصلاحيات
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة إضافة صلاحية -->
    <div id="addPermissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> إضافة صلاحية جديدة</h3>
                <button class="close-modal" onclick="closeModal('addPermissionModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addPermissionForm">
                    <div class="form-group">
                        <label class="form-label">اسم الصلاحية *</label>
                        <input type="text" name="permission_name" class="form-control" required
                            placeholder="مثل: create_user, edit_document">
                        <small style="color: #6c757d; display: block; margin-top: 5px;">
                            استخدم أسماء إنجليزية بدون مسافات
                        </small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الوصف</label>
                        <textarea name="description" class="form-control" rows="3"
                            placeholder="وصف الصلاحية"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                        <i class="fas fa-save"></i> حفظ الصلاحية
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // دالة عامة لإغلاق النماذج
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // دالة لفتح نافذة إضافة دور
        function openAddRoleModal() {
            document.getElementById('addRoleModal').style.display = 'block';
        }

        // دالة لفتح نافذة تعديل دور
        function openEditRoleModal(role) {
            document.getElementById('edit_role_id').value = role.id;
            document.getElementById('edit_role_name').value = role.role_name;
            document.getElementById('edit_description').value = role.description || '';
            document.getElementById('edit_parent_role_id').value = role.parent_role_id || '';

            // إضافة نص مساعد إذا كان الدور هو board
            const helpText = document.getElementById('edit_role_help');
            if (role.role_name === 'board') {
                helpText.innerHTML = '<i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i> هذا دور "الديوان" - لا يمكن تغيير اسمه أو جعله تابعاً لدور آخر';
                document.getElementById('edit_role_name').readOnly = true;
                document.getElementById('edit_parent_role_id').disabled = true;
            } else {
                helpText.innerHTML = 'استخدم أسماء إنجليزية بدون مسافات';
                document.getElementById('edit_role_name').readOnly = false;
                document.getElementById('edit_parent_role_id').disabled = false;
            }

            document.getElementById('editRoleModal').style.display = 'block';
        }

        // دالة لفتح نافذة الصلاحيات
        function openPermissionsModal(roleId, roleName) {
            document.getElementById('permissions_role_id').value = roleId;
            document.getElementById('permissions_role_name').textContent = roleName;

            // جلب الصلاحيات الحالية للدور
            fetch('manage_roles.php?action=get_permissions&role_id=' + roleId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderPermissions(data.permissions, data.role_permissions);
                        document.getElementById('permissionsModal').style.display = 'block';
                    } else {
                        alert('حدث خطأ في جلب الصلاحيات');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في الاتصال بالخادم');
                });
        }

        // دالة لعرض الصلاحيات
        function renderPermissions(permissions, rolePermissions) {
            const container = document.getElementById('permissionsContainer');
            let html = '';

            if (permissions.length === 0) {
                html = '<div style="text-align: center; padding: 20px; color: #6c757d;"><p>لا توجد صلاحيات مضافة</p></div>';
            } else {
                // تصنيف الصلاحيات حسب النوع
                const categories = {};
                permissions.forEach(permission => {
                    const parts = permission.permission_name.split('_');
                    const category = parts.length > 1 ? parts[0] : 'عام';

                    if (!categories[category]) {
                        categories[category] = [];
                    }
                    categories[category].push(permission);
                });

                // عرض الصلاحيات مصنفة
                for (const [category, categoryPermissions] of Object.entries(categories)) {
                    html += `<h4 style="color: #495057; margin-top: 20px; margin-bottom: 10px;">${category.toUpperCase()}</h4>`;

                    categoryPermissions.forEach(permission => {
                        const isChecked = rolePermissions.includes(permission.id.toString());
                        html += `
                            <div class="permission-item">
                                <input type="checkbox" 
                                       id="perm_${permission.id}" 
                                       value="${permission.id}"
                                       ${isChecked ? 'checked' : ''}>
                                <label for="perm_${permission.id}">
                                    <strong>${permission.permission_name}</strong>
                                    ${permission.description ? `<br><small>${permission.description}</small>` : ''}
                                </label>
                            </div>
                        `;
                    });
                }
            }

            container.innerHTML = html;
        }

        // دالة لحفظ الصلاحيات
        function savePermissions() {
            const roleId = document.getElementById('permissions_role_id').value;
            const checkboxes = document.querySelectorAll('#permissionsContainer input[type="checkbox"]:checked');
            const permissions = Array.from(checkboxes).map(cb => cb.value);

            const formData = new FormData();
            formData.append('action', 'update_permissions');
            formData.append('role_id', roleId);
            formData.append('permissions', JSON.stringify(permissions));

            fetch('manage_roles.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeModal('permissionsModal');
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في الاتصال بالخادم');
                });
        }

        // دالة لفتح نافذة إضافة صلاحية
        function openAddPermissionModal() {
            document.getElementById('addPermissionModal').style.display = 'block';
        }

        // إغلاق النماذج عند النقر خارجها
        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // معالجة إضافة دور جديد
        document.getElementById('addRoleForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const roleName = this.querySelector('[name="role_name"]').value;

            // تحقق إضافي إذا كان الدور هو board
            if (roleName.toLowerCase() === 'board') {
                const parentId = this.querySelector('[name="parent_role_id"]').value;
                if (parentId) {
                    alert('دور "board" يجب أن يكون دوراً رئيسياً بدون تبعية لأي دور آخر');
                    return;
                }
            }

            const formData = new FormData(this);
            formData.append('action', 'add_role');

            fetch('manage_roles.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeModal('addRoleModal');
                        location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في الاتصال بالخادم');
                });
        });

        // معالجة تعديل دور
        document.getElementById('editRoleForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const roleName = this.querySelector('[name="role_name"]').value;
            const roleId = this.querySelector('[name="role_id"]').value;
            const parentId = this.querySelector('[name="parent_role_id"]').value;

            // تحقق إضافي إذا كان الدور هو board
            if (roleName.toLowerCase() === 'board') {
                if (parentId) {
                    alert('دور "board" يجب أن يكون دوراً رئيسياً بدون تبعية لأي دور آخر');
                    return;
                }

                // منع تغيير ID الدور إذا كان board
                if (roleId != 1) {
                    alert('لا يمكن تغيير دور آخر ليصبح "board". دور "board" يجب أن يكون له ID = 1');
                    return;
                }
            }

            // تحقق من عدم جعل الدور تابعاً لنفسه
            if (parentId == roleId) {
                alert('لا يمكن جعل الدور تابعاً لنفسه');
                return;
            }

            const formData = new FormData(this);
            formData.append('action', 'update_role');

            fetch('manage_roles.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeModal('editRoleModal');
                        location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في الاتصال بالخادم');
                });
        });

        // معالجة إضافة صلاحية جديدة
        document.getElementById('addPermissionForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'add_permission');

            fetch('manage_roles.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeModal('addPermissionModal');
                        location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في الاتصال بالخادم');
                });
        });

        // تأكيد حذف الدور
        function confirmDeleteRole(roleId, roleName) {
            const message = `هل أنت متأكد من حذف الدور "${roleName}"؟\n\nهذا الإجراء لا يمكن التراجع عنه.`;

            if (confirm(message)) {
                const formData = new FormData();
                formData.append('action', 'delete_role');
                formData.append('role_id', roleId);

                fetch('manage_roles.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            location.reload();
                        } else {
                            alert(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('حدث خطأ في الاتصال بالخادم');
                    });
            }
        }

        // تحقق عند محاولة إدخال دور board
        document.querySelector('#addRoleForm [name="role_name"]')?.addEventListener('input', function (e) {
            if (this.value.toLowerCase() === 'board') {
                const parentSelect = document.querySelector('#addRoleForm [name="parent_role_id"]');
                parentSelect.value = '';
                parentSelect.disabled = true;

                // إضافة رسالة تحذير
                let warning = document.getElementById('board-warning');
                if (!warning) {
                    warning = document.createElement('div');
                    warning.id = 'board-warning';
                    warning.className = 'alert alert-warning';
                    warning.style.marginTop = '10px';
                    warning.style.padding = '10px';
                    warning.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <strong>ملاحظة:</strong> دور "board" يجب أن يكون دائماً دوراً رئيسياً بدون تبعية لأي دور آخر';
                    this.parentNode.appendChild(warning);
                }
            } else {
                const parentSelect = document.querySelector('#addRoleForm [name="parent_role_id"]');
                parentSelect.disabled = false;

                // إزالة رسالة التحذير
                const warning = document.getElementById('board-warning');
                if (warning) {
                    warning.remove();
                }
            }
        });
    </script>
</body>

</html>