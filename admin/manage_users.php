<?php
/**
 * إدارة المستخدمين - نظام التوقيع الإلكتروني
 * النسخة المحسنة مع إضافة المدير المباشر
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

// جلب جميع المستخدمين مع معلومات الأدوار والأقسام والمديرين
$search = '';
$where_conditions = [];
$params = [];

// معالجة البحث
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = trim($_GET['search']);
    $where_conditions[] = "(u.username LIKE :search OR u.full_name LIKE :search )";
    $params[':search'] = "%{$search}%";
}

// بناء استعلام البحث
$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// جلب المستخدمين
$stmt = $db->prepare("
    SELECT u.*, 
           r.role_name, 
           d.name as department_name,
           su.full_name as supervisor_name,
           su.username as supervisor_username
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    LEFT JOIN departments d ON u.department_id = d.id 
    LEFT JOIN users su ON u.supervisor_id = su.id 
    {$where_sql}
    ORDER BY u.created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب الأدوار والأقسام والمديرين المحتملين للنموذج
$roles = $db->query("SELECT * FROM roles ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $db->query("SELECT * FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// جلب جميع المستخدمين النشطين ليختار منهم المديرين
$potential_supervisors = $db->query("
    SELECT u.id, u.username, u.full_name, r.role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    WHERE u.is_active = 1 
    AND r.role_name IN ('section_manager', 'department_manager', 'ceo', 'admin', 'board','private_board','sub_board','office_manager','deputy_ceo')
    ORDER BY 
        CASE r.role_name 
            WHEN 'board' THEN 1
            WHEN 'ceo' THEN 2
            WHEN 'department_manager' THEN 3
            WHEN 'section_manager' THEN 4
            WHEN 'admin' THEN 5
            WHEN 'private_board' THEN 6
            WHEN 'sub_board' THEN 7
            WHEN 'deputy_ceo' THEN 8
            WHEN 'office_manager' THEN 9
            ELSE 6
        END,
        u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

// معالجة طلبات AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];

    header('Content-Type: application/json');

    try {
        switch ($action) {
            case 'add_user':
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $confirm_password = trim($_POST['confirm_password'] ?? '');
                $site = trim($_POST['site'] ?? '');
                $title = trim($_POST['title'] ?? '');
                $full_name = trim($_POST['full_name'] ?? '');
                $role_id = $_POST['role_id'] ?? '';
                $department_id = $_POST['department_id'] ?? '';
                $supervisor_id = $_POST['supervisor_id'] ?? null;

                // تحسين رسائل الخطأ
                $errors = [];

                // التحقق من البيانات المطلوبة
                if (empty($username))
                    $errors[] = 'اسم المستخدم';
                if (empty($password))
                    $errors[] = 'كلمة المرور';
                if (empty($confirm_password))
                    $errors[] = 'تأكيد كلمة المرور';
                if (empty($full_name))
                    $errors[] = 'الاسم الكامل';
                if (empty($role_id))
                    $errors[] = 'الدور';
                if (empty($site))
                    $errors[] = 'الجهة العاملة';
                if (empty($title))
                    $errors[] = 'المسمى الوظيفي';

                if (!empty($errors)) {
                    $response['message'] = 'الحقول التالية مطلوبة: ' . implode('، ', $errors);
                    break;
                }

                // التحقق من تطابق كلمة المرور
                if ($password !== $confirm_password) {
                    $response['message'] = 'كلمة المرور وتأكيدها غير متطابقتين';
                    break;
                }

                // التحقق من قوة كلمة المرور
                if (strlen($password) < 6) {
                    $response['message'] = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
                    break;
                }

                // التحقق من عدم وجود اسم مستخدم مكرر
                $check_stmt = $db->prepare("SELECT id FROM users WHERE username = :username");
                $check_stmt->execute([':username' => $username]);
                if ($check_stmt->fetch()) {
                    $response['message'] = 'اسم المستخدم موجود مسبقاً';
                    break;
                }

                // إضافة المستخدم مع تضمين حقل site
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    INSERT INTO users (username, password, email, site, full_name, role_id, department_id, supervisor_id, is_active, created_at, title) 
                    VALUES (:username, :password, :email, :site, :full_name, :role_id, :department_id, :supervisor_id, 1, NOW(), :title)
                ");

                // تعيين قيمة افتراضية للبريد الإلكتروني
                $default_email = $username . '@example.com';

                $result = $stmt->execute([
                    ':username' => $username,
                    ':password' => $hashed_password,
                    ':email' => $default_email,
                    ':site' => $site,
                    ':full_name' => $full_name,
                    ':role_id' => $role_id,
                    ':title' => $title,
                    ':department_id' => $department_id ?: null,
                    ':supervisor_id' => $supervisor_id ?: null
                ]);

                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم إضافة المستخدم بنجاح';
                    $response['user_id'] = $db->lastInsertId();
                } else {
                    $response['message'] = 'حدث خطأ في إضافة المستخدم';
                }
                break;

            case 'toggle_status':
                $user_id = $_POST['user_id'] ?? '';
                $status = $_POST['status'] ?? '';

                $stmt = $db->prepare("UPDATE users SET is_active = :status WHERE id = :id");
                $result = $stmt->execute([':status' => $status, ':id' => $user_id]);

                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم تغيير حالة المستخدم بنجاح';
                } else {
                    $response['message'] = 'حدث خطأ في تغيير حالة المستخدم';
                }
                break;

            case 'delete_user':
                $user_id = $_POST['user_id'] ?? '';

                // لا يمكن حذف المستخدم الرئيسي (id = 1)
                if ($user_id == 1) {
                    $response['message'] = 'لا يمكن حذف المستخدم المسؤول الرئيسي';
                    break;
                }

                $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
                $result = $stmt->execute([':id' => $user_id]);

                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم حذف المستخدم بنجاح';
                } else {
                    $response['message'] = 'حدث خطأ في حذف المستخدم';
                }
                break;

            case 'edit_user':
                $user_id = $_POST['user_id'] ?? '';
                $full_name = trim($_POST['full_name'] ?? '');
                $site = trim($_POST['site'] ?? '');
                $title = trim($_POST['title'] ?? '');
                $role_id = $_POST['role_id'] ?? '';
                $department_id = $_POST['department_id'] ?? '';
                $supervisor_id = $_POST['supervisor_id'] ?? null;

                // التحقق من البيانات المطلوبة
                if (empty($full_name) || empty($role_id) || empty($site) || empty($title)) {
                    $response['message'] = 'جميع الحقول المطلوبة يجب ملؤها';
                    break;
                }

                // تحديث بيانات المستخدم مع تضمين حقل site
                $stmt = $db->prepare("
                    UPDATE users 
                    SET full_name = :full_name, 
                        site = :site,
                        role_id = :role_id, 
                        department_id = :department_id,
                        supervisor_id = :supervisor_id,
                        title = :title
                    WHERE id = :id
                ");

                $result = $stmt->execute([
                    ':full_name' => $full_name,
                    ':site' => $site,
                    ':role_id' => $role_id,
                    ':department_id' => $department_id ?: null,
                    ':supervisor_id' => $supervisor_id ?: null,
                    ':title' => $title,
                    ':id' => $user_id
                ]);

                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم تحديث بيانات المستخدم بنجاح';
                } else {
                    $response['message'] = 'حدث خطأ في تحديث بيانات المستخدم';
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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المستخدمين - نظام التوقيع الإلكتروني</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        /* تحسينات على الأنماط */
        .users-management {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
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
        }

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

        .total-users {
            border-top-color: #3498db;
        }

        .active-users {
            border-top-color: #2ecc71;
        }

        .inactive-users {
            border-top-color: #e74c3c;
        }

        .admins {
            border-top-color: #9b59b6;
        }

        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            gap: 15px;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            display: flex;
            gap: 10px;
            min-width: 300px;
        }

        .search-box input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: 'Cairo', sans-serif;
        }

        .users-table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
            background: #f8f9fa;
            padding: 15px 10px;
            text-align: right;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #dee2e6;
        }

        .users-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e9ecef;
            color: #495057;
        }

        .users-table tr:hover {
            background: #f8f9fa;
        }

        .user-status {
            padding: 5px 12px;
            border-radius: 20px;
            border: none;
            font-size: 0.85rem;
            cursor: pointer;
            font-family: 'Cairo', sans-serif;
            transition: all 0.3s;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            margin-left: 5px;
            font-family: 'Cairo', sans-serif;
            transition: all 0.3s;
        }

        .btn-edit {
            background: #17a2b8;
            color: white;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }

        .action-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

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
            width: 90%;
            max-width: 600px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            max-height: 80vh;
            overflow-y: auto;
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
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #7f8c8d;
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
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: 'Cairo', sans-serif;
        }

        .supervisor-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-top: 5px;
            font-size: 0.9rem;
            color: #666;
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .alert i {
            font-size: 1.2rem;
        }

        .supervisor-column {
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .form-control:invalid {
            border-color: #dc3545;
        }

        .form-control:valid {
            border-color: #28a745;
        }

        .error-message {
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 5px;
            display: none;
        }
    </style>
</head>

<body>
    <?php include '../includes/admin_header.php'; ?>

    <div class="users-management">
        <div class="page-header">
            <h1><i class="fas fa-users"></i> إدارة المستخدمين</h1>
            <button class="btn btn-primary" onclick="openAddUserModal()">
                <i class="fas fa-user-plus"></i> إضافة مستخدم جديد
            </button>
        </div>

        <!-- إحصائيات المستخدمين -->
        <div class="stats-cards">
            <div class="stat-card total-users">
                <h3><?php echo count($users); ?></h3>
                <p>إجمالي المستخدمين</p>
            </div>
            <div class="stat-card active-users">
                <h3><?php echo count(array_filter($users, fn($u) => $u['is_active'])); ?></h3>
                <p>المستخدمين النشطين</p>
            </div>
            <div class="stat-card inactive-users">
                <h3><?php echo count(array_filter($users, fn($u) => !$u['is_active'])); ?></h3>
                <p>المستخدمين غير النشطين</p>
            </div>
            <div class="stat-card admins">
                <h3><?php echo count(array_filter($users, fn($u) => $u['role_name'] == 'admin')); ?></h3>
                <p>المسؤولين</p>
            </div>
        </div>

        <!-- شريط البحث والإجراءات -->
        <div class="action-bar">
            <form method="GET" class="search-box">
                <input type="text" name="search" placeholder="بحث باسم المستخدم أو الاسم الكامل..."
                    value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> بحث
                </button>
                <?php if ($search): ?>
                    <a href="manage_users.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> إلغاء البحث
                    </a>
                <?php endif; ?>
            </form>

            <div class="action-buttons">
                <button class="btn btn-success" onclick="exportUsers()">
                    <i class="fas fa-file-export"></i> تصدير
                </button>
                <button class="btn btn-info" onclick="showImportModal()">
                    <i class="fas fa-file-import"></i> استيراد
                </button>
            </div>
        </div>

        <!-- جدول المستخدمين -->
        <div class="users-table-container">
            <?php if (empty($users)): ?>
                <div style="padding: 40px; text-align: center; color: #7f8c8d;">
                    <i class="fas fa-users-slash" style="font-size: 3rem; margin-bottom: 20px; color: #ddd;"></i>
                    <h3>لا توجد مستخدمين</h3>
                    <p>ابدأ بإضافة مستخدمين جدد إلى النظام</p>
                    <button class="btn btn-primary" onclick="openAddUserModal()">
                        <i class="fas fa-user-plus"></i> إضافة أول مستخدم
                    </button>
                </div>
            <?php else: ?>
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم المستخدم</th>
                            <th>الاسم الكامل</th>
                            <th>الجهة التابعة</th>
                            <th>الدور</th>
                            <th>القسم</th>
                            <th>المسمى</th>
                            <th>المدير المباشر</th>
                            <th>الحالة</th>
                            <th>تاريخ التسجيل</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $index => $user): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                    <?php if ($user['id'] == 1): ?>
                                        <span
                                            style="background: #ffc107; color: #856404; padding: 2px 8px; border-radius: 3px; font-size: 0.8rem; margin-right: 5px;">رئيسي</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['site']); ?></td>
                                <td>
                                    <span
                                        style="background: #e9ecef; padding: 3px 10px; border-radius: 20px; font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($user['role_name']); ?>
                                    </span>
                                </td>
                                <td><?php echo $user['department_name'] ? htmlspecialchars($user['department_name']) : '<span style="color: #999;">لا يوجد</span>'; ?>
                                </td>
                                <td><?php echo $user['title'] ? htmlspecialchars($user['title']) : '<span style="color: #999;">لا يوجد</span>'; ?>
                                </td>
                                <td class="supervisor-column">
                                    <?php if ($user['supervisor_name']): ?>
                                        <div
                                            title="<?php echo htmlspecialchars($user['supervisor_name'] . ' (' . $user['supervisor_username'] . ')'); ?>">
                                            <?php echo htmlspecialchars($user['supervisor_name']); ?>
                                            <div style="font-size: 0.8rem; color: #666;">
                                                @<?php echo htmlspecialchars($user['supervisor_username']); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;">لا يوجد</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button
                                        class="user-status <?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>"
                                        onclick="toggleUserStatus(<?php echo $user['id']; ?>, <?php echo $user['is_active'] ? 0 : 1; ?>)">
                                        <?php echo $user['is_active'] ? 'نشط' : 'غير نشط'; ?>
                                    </button>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <button class="action-btn btn-edit"
                                        onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                        <i class="fas fa-edit"></i> تعديل
                                    </button>
                                    <?php if ($user['id'] != 1): ?>
                                        <button class="action-btn btn-delete" onclick="confirmDelete(<?php echo $user['id']; ?>)">
                                            <i class="fas fa-trash"></i> حذف
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- رسالة معلومات -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>معلومة:</strong> يمكنك تحرير بيانات أي مستخدم بالنقر على زر "تعديل". المستخدم المسؤول الرئيسي (ID:
            1) لا يمكن حذفه.
        </div>
    </div>

    <!-- نافذة إضافة مستخدم -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> إضافة مستخدم جديد</h3>
                <button class="close-modal" onclick="closeModal('addUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addUserForm">
                    <div class="form-group">
                        <label class="form-label">اسم المستخدم *</label>
                        <input type="text" name="username" class="form-control" required minlength="3">
                        <div class="error-message" id="username-error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الاسم الكامل *</label>
                        <input type="text" name="full_name" class="form-control" required>
                        <div class="error-message" id="fullname-error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الجهة التابعة *</label>
                        <input type="text" name="site" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">كلمة المرور *</label>
                        <input type="password" name="password" id="password" class="form-control" required
                            minlength="6">
                        <div class="error-message" id="password-error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">تأكيد كلمة المرور *</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                        <div class="error-message" id="confirm-password-error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الدور *</label>
                        <select name="role_id" class="form-control" required>
                            <option value="">اختر دور المستخدم</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-message" id="role-error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">القسم</label>
                        <select name="department_id" class="form-control">
                            <option value="">اختر القسم</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">المسمى الوظيفي</label>
                        <input type="text" name="title" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">المدير المباشر</label>
                        <select name="supervisor_id" class="form-control">
                            <option value="">اختر المدير المباشر</option>
                            <?php foreach ($potential_supervisors as $supervisor): ?>
                                <option value="<?php echo $supervisor['id']; ?>">
                                    <?php echo htmlspecialchars($supervisor['full_name'] . ' (' . $supervisor['username'] . ') - ' . $supervisor['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                        <i class="fas fa-save"></i> حفظ المستخدم
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- نافذة تعديل مستخدم -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> تعديل بيانات المستخدم</h3>
                <button class="close-modal" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editUserForm">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="form-group">
                        <label class="form-label">الاسم الكامل *</label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الجهة التابعة *</label>
                        <input type="text" name="site" id="edit_site" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الدور *</label>
                        <select name="role_id" id="edit_role_id" class="form-control" required>
                            <option value="">اختر دور المستخدم</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">القسم</label>
                        <select name="department_id" id="edit_department_id" class="form-control">
                            <option value="">اختر القسم</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">المسمى الوظيفي*</label>
                        <input type="text" name="title" id="edit_title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">المدير المباشر</label>
                        <select name="supervisor_id" id="edit_supervisor_id" class="form-control">
                            <option value="">اختر المدير المباشر</option>
                            <?php foreach ($potential_supervisors as $supervisor): ?>
                                <option value="<?php echo $supervisor['id']; ?>">
                                    <?php echo htmlspecialchars($supervisor['full_name'] . ' (' . $supervisor['username'] . ') - ' . $supervisor['role_name']); ?>
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

    <script>
        function openAddUserModal() {
            document.getElementById('addUserModal').style.display = 'block';
        }

        function openEditUserModal(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_full_name').value = user.full_name;
            document.getElementById('edit_site').value = user.site;
            document.getElementById('edit_title').value = user.title || '';
            document.getElementById('edit_role_id').value = user.role_id;
            document.getElementById('edit_department_id').value = user.department_id || '';
            document.getElementById('edit_supervisor_id').value = user.supervisor_id || '';
            document.getElementById('editUserModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function toggleUserStatus(userId, newStatus) {
            if (!confirm('هل أنت متأكد من تغيير حالة المستخدم؟')) return;

            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('user_id', userId);
            formData.append('status', newStatus);

            fetch('manage_users.php', {
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

        function confirmDelete(userId) {
            if (!confirm('هل أنت متأكد من حذف هذا المستخدم؟\n\nهذا الإجراء لا يمكن التراجع عنه.')) return;

            const formData = new FormData();
            formData.append('action', 'delete_user');
            formData.append('user_id', userId);

            fetch('manage_users.php', {
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

        // إغلاق النماذج عند النقر خارجها
        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // إضافة مستخدم جديد
        document.getElementById('addUserForm').onsubmit = function (e) {
            e.preventDefault();

            const formData = new FormData(this);
            const username = formData.get('username');
            const password = formData.get('password');
            const confirm_password = formData.get('confirm_password');
            const site = formData.get('site');
            const full_name = formData.get('full_name');
            const role_id = formData.get('role_id');

            if (!username || !password || !confirm_password || !full_name || !role_id || !site) {
                alert('جميع الحقول المطلوبة يجب ملؤها');
                return;
            }

            if (password !== confirm_password) {
                alert('كلمة المرور وتأكيدها غير متطابقتين');
                return;
            }

            formData.append('action', 'add_user');

            fetch('manage_users.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeModal('addUserModal');
                        location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('خطأ في الاتصال بالخادم');
                });
        };

        // تعديل مستخدم
        document.getElementById('editUserForm').onsubmit = function (e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'edit_user');

            fetch('manage_users.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeModal('editUserModal');
                        location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في الاتصال بالخادم');
                });
        };

        // دوال أخرى
        function exportUsers() {
            if (confirm('هل تريد تصدير قائمة المستخدمين بصيغة CSV؟')) {
                window.location.href = 'export_users.php';
            }
        }

        function showImportModal() {
            alert('ميزة استيراد المستخدمين ستكون متاحة قريباً');
        }
    </script>
</body>

</html>