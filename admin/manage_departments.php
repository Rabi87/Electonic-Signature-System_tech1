<?php
/**
 * إدارة الأقسام - نظام التوقيع الإلكتروني
 * 
 * هذا الملف يمكن المسؤول من إدارة الأقسام في النظام
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

// جلب جميع الأقسام مع معلومات المدير وعدد الموظفين
$departments = $db->query("
    SELECT d.*, 
           u.full_name as manager_name,
           COUNT(emp.id) as employee_count
    FROM departments d 
    LEFT JOIN users u ON d.manager_id = u.id 
    LEFT JOIN users emp ON d.id = emp.department_id 
    GROUP BY d.id 
    ORDER BY d.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// جلب المديرين المحتملين (المستخدمين النشطين)
$managers = $db->query("
    SELECT id, full_name, email 
    FROM users 
    WHERE is_active = 1 
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

// معالجة طلبات AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];

    header('Content-Type: application/json');

    try {
        switch ($action) {
            case 'add_department':
                $name = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $manager_id = $_POST['manager_id'] ?? null;

                if (empty($name)) {
                    $response['message'] = 'اسم القسم مطلوب';
                    break;
                }

                // التحقق من عدم تكرار اسم القسم
                $check_stmt = $db->prepare("SELECT id FROM departments WHERE name = ?");
                $check_stmt->execute([$name]);
                if ($check_stmt->fetch()) {
                    $response['message'] = 'اسم القسم موجود مسبقاً';
                    break;
                }

                // إضافة القسم - يجب أن يقبل manager_id كـ NULL
                $stmt = $db->prepare("INSERT INTO departments (name, description, manager_id, created_at) VALUES (?, ?, ?, NOW())");

                // تحويل القيمة الفارغة إلى NULL
                $manager_id = (!empty($manager_id) && $manager_id !== '') ? $manager_id : null;

                $result = $stmt->execute([$name, $description, $manager_id]);
                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم إضافة القسم بنجاح';
                    $response['dept_id'] = $db->lastInsertId();
                } else {
                    $response['message'] = 'حدث خطأ في إضافة القسم';
                }
                break;

            case 'update_department':
                $dept_id = $_POST['dept_id'] ?? 0;
                $name = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $manager_id = $_POST['manager_id'] ?? null;

                if (empty($dept_id) || empty($name)) {
                    $response['message'] = 'بيانات غير كافية';
                    break;
                }

                // تحديث القسم
                $stmt = $db->prepare("UPDATE departments SET name = ?, description = ?, manager_id = ? WHERE id = ?");
                $result = $stmt->execute([$name, $description, $manager_id, $dept_id]);

                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم تحديث القسم بنجاح';
                } else {
                    $response['message'] = 'حدث خطأ في تحديث القسم';
                }
                break;

            case 'delete_department':
                $dept_id = $_POST['dept_id'] ?? 0;

                if (empty($dept_id)) {
                    $response['message'] = 'معرف القسم مطلوب';
                    break;
                }

                // التحقق من وجود موظفين في هذا القسم
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
                $check_stmt->execute([$dept_id]);
                $employee_count = $check_stmt->fetchColumn();

                if ($employee_count > 0) {
                    $response['message'] = 'لا يمكن حذف القسم لأنه يحتوي على موظفين';
                    break;
                }

                // حذف القسم
                $stmt = $db->prepare("DELETE FROM departments WHERE id = ?");
                $result = $stmt->execute([$dept_id]);

                if ($result) {
                    $response['success'] = true;
                    $response['message'] = 'تم حذف القسم بنجاح';
                } else {
                    $response['message'] = 'حدث خطأ في حذف القسم';
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
    <title>إدارة الأقسام - نظام التوقيع الإلكتروني</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .departments-management {
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

        .total-departments {
            border-top-color: #3498db;
        }

        .total-employees {
            border-top-color: #2ecc71;
        }

        .with-manager {
            border-top-color: #9b59b6;
        }

        .avg-employees {
            border-top-color: #f39c12;
        }

        /* البطاقات */
        .departments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .departments-grid {
                grid-template-columns: 1fr;
            }
        }

        .department-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s;
            border: 1px solid #e9ecef;
        }

        .department-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .department-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 20px;
        }

        .department-header h3 {
            margin: 0 0 10px;
            font-size: 1.2rem;
        }

        .department-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .department-body {
            padding: 20px;
        }

        .department-info {
            margin-bottom: 15px;
        }

        .department-info p {
            margin: 5px 0;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .department-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }

        .stat {
            text-align: center;
        }

        .stat .number {
            display: block;
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c3e50;
        }

        .stat .label {
            display: block;
            font-size: 0.8rem;
            color: #6c757d;
        }

        /* أزرار الإجراءات */
        .department-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        /* معلومات مهمة */
        .info-box {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-box i {
            color: #3498db;
            font-size: 1.2rem;
        }

        .info-box p {
            margin: 0;
            color: #0066cc;
            flex: 1;
        }

        /* الحالة الفارغة */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #dee2e6;
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
            max-width: 500px;
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
            color: #6c757d;
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* تصميم متجاوب */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .department-actions {
                flex-wrap: wrap;
            }

            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
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
                        <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                    </h3>
                    <p style="margin: 0; font-size: 0.85rem; opacity: 0.8;">
                        <?php echo htmlspecialchars($_SESSION['role_name']); ?>
                    </p>
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
                    style="color: white; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                    <i class="fas fa-user-tag"></i> الأدوار
                </a>
                <a href="manage_departments.php"
                    style="color: white; text-decoration: none; display: flex; align-items: center; gap: 5px; background: #3498db; padding: 8px 15px; border-radius: 5px;">
                    <i class="fas fa-building"></i> الأقسام
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

    <div class="departments-management">
        <div class="page-header">
            <h1><i class="fas fa-building"></i> إدارة الأقسام</h1>
            <button class="btn btn-primary" onclick="openAddDepartmentModal()">
                <i class="fas fa-plus"></i> إضافة قسم جديد
            </button>
        </div>

        <!-- إحصائيات -->
        <div class="stats-cards">
            <div class="stat-card total-departments">
                <h3><?php echo count($departments); ?></h3>
                <p>إجمالي الأقسام</p>
            </div>
            <div class="stat-card total-employees">
                <?php
                $total_employees = array_sum(array_column($departments, 'employee_count'));
                ?>
                <h3><?php echo $total_employees; ?></h3>
                <p>إجمالي الموظفين</p>
            </div>
            <div class="stat-card with-manager">
                <?php
                $departments_with_manager = count(array_filter($departments, fn($d) => !empty($d['manager_id'])));
                ?>
                <h3><?php echo $departments_with_manager; ?></h3>
                <p>أقسام لها مدير</p>
            </div>
            <div class="stat-card avg-employees">
                <?php
                $avg_employees = count($departments) > 0 ? round($total_employees / count($departments), 1) : 0;
                ?>
                <h3><?php echo $avg_employees; ?></h3>
                <p>متوسط الموظفين</p>
            </div>
        </div>

        <!-- معلومات مهمة -->
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <p>الأقسام تساعد في تنظيم الموظفين وتحديد التسلسل الإداري. يمكن تعيين مدير لكل قسم لإدارة موظفيه.</p>
        </div>

        <!-- قائمة الأقسام -->
        <div class="departments-grid">
            <?php if (empty($departments)): ?>
                <div class="empty-state">
                    <i class="fas fa-building"></i>
                    <h3>لا توجد أقسام</h3>
                    <p>ابدأ بإضافة أقسام جديدة لتنظيم الموظفين</p>
                    <button class="btn btn-primary" onclick="openAddDepartmentModal()">
                        <i class="fas fa-plus"></i> إضافة أول قسم
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($departments as $dept): ?>
                    <div class="department-card">
                        <div class="department-header">
                            <h3><?php echo htmlspecialchars($dept['name']); ?></h3>
                            <p><?php echo htmlspecialchars($dept['description'] ?: 'لا يوجد وصف'); ?></p>
                        </div>

                        <div class="department-body">
                            <div class="department-info">
                                <p>
                                    <i class="fas fa-user-tie" style="color: #3498db;"></i>
                                    <strong>المدير:</strong>
                                    <?php if ($dept['manager_name']): ?>
                                        <?php echo htmlspecialchars($dept['manager_name']); ?>
                                    <?php else: ?>
                                        <span style="color: #6c757d;">لم يتم تعيين مدير</span>
                                    <?php endif; ?>
                                </p>

                                <p>
                                    <i class="fas fa-calendar" style="color: #2ecc71;"></i>
                                    <strong>تاريخ الإنشاء:</strong> <?php echo date('Y-m-d', strtotime($dept['created_at'])); ?>
                                </p>
                            </div>

                            <div class="department-stats">
                                <div class="stat">
                                    <span class="number"><?php echo $dept['employee_count']; ?></span>
                                    <span class="label">موظف</span>
                                </div>
                                <div class="stat">
                                    <span class="number"><?php echo $dept['manager_id'] ? '✓' : '✗'; ?></span>
                                    <span class="label">مدير معين</span>
                                </div>
                            </div>

                            <div class="department-actions">
                                <button class="btn btn-sm btn-warning"
                                    onclick="openEditDepartmentModal(<?php echo htmlspecialchars(json_encode($dept)); ?>)">
                                    <i class="fas fa-edit"></i> تعديل
                                </button>
                                <button class="btn btn-sm btn-info"
                                    onclick="viewDepartmentEmployees(<?php echo $dept['id']; ?>, '<?php echo htmlspecialchars($dept['name']); ?>')">
                                    <i class="fas fa-users"></i> الموظفين
                                </button>
                                <?php if ($dept['employee_count'] == 0): ?>
                                    <button class="btn btn-sm btn-danger"
                                        onclick="confirmDeleteDepartment(<?php echo $dept['id']; ?>, '<?php echo htmlspecialchars($dept['name']); ?>')">
                                        <i class="fas fa-trash"></i> حذف
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- === النماذج المنبثقة === -->

    <!-- نافذة إضافة قسم -->
    <div id="addDepartmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> إضافة قسم جديد</h3>
                <button class="close-modal" onclick="closeModal('addDepartmentModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addDepartmentForm">
                    <div class="form-group">
                        <label class="form-label">اسم القسم *</label>
                        <input type="text" name="name" class="form-control" required placeholder="مثل: قسم المبيعات">
                    </div>
                    <div class="form-group">
                        <label class="form-label">الوصف</label>
                        <textarea name="description" class="form-control" rows="3"
                            placeholder="وصف مختصر للقسم"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">مدير القسم (اختياري - يمكن تعيينه لاحقاً)</label>
                        <select name="manager_id" class="form-control">
                            <option value="">اختر مدير القسم</option>
                            <?php foreach ($managers as $manager): ?>
                                <option value="<?php echo $manager['id']; ?>">
                                    <?php echo htmlspecialchars($manager['full_name']); ?>
                                    (<?php echo htmlspecialchars($manager['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #666; display: block; margin-top: 5px;">
                            يمكنك ترك هذا الحقل فارغاً وإضافة المدير لاحقاً بعد إضافة المستخدمين
                        </small>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                        <i class="fas fa-save"></i> حفظ القسم
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- نافذة تعديل قسم -->
    <div id="editDepartmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> تعديل بيانات القسم</h3>
                <button class="close-modal" onclick="closeModal('editDepartmentModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editDepartmentForm">
                    <input type="hidden" name="dept_id" id="edit_dept_id">
                    <div class="form-group">
                        <label class="form-label">اسم القسم *</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الوصف</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">مدير القسم</label>
                        <select name="manager_id" id="edit_manager_id" class="form-control">
                            <option value="">اختر مدير القسم</option>
                            <?php foreach ($managers as $manager): ?>
                                <option value="<?php echo $manager['id']; ?>">
                                    <?php echo htmlspecialchars($manager['full_name']); ?>
                                    (<?php echo htmlspecialchars($manager['email']); ?>)
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
        // دالة عامة لإغلاق النماذج
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // دالة لفتح نافذة إضافة قسم
        function openAddDepartmentModal() {
            document.getElementById('addDepartmentModal').style.display = 'block';
        }

        // دالة لفتح نافذة تعديل قسم
        function openEditDepartmentModal(dept) {
            document.getElementById('edit_dept_id').value = dept.id;
            document.getElementById('edit_name').value = dept.name;
            document.getElementById('edit_description').value = dept.description || '';
            document.getElementById('edit_manager_id').value = dept.manager_id || '';
            document.getElementById('editDepartmentModal').style.display = 'block';
        }

        // إغلاق النماذج عند النقر خارجها
        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // إضافة قسم جديد
        document.getElementById('addDepartmentForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'add_department');

            fetch('manage_departments.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeModal('addDepartmentModal');
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

        // تعديل بيانات القسم
        document.getElementById('editDepartmentForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'update_department');

            fetch('manage_departments.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeModal('editDepartmentModal');
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

        // تأكيد حذف القسم
        function confirmDeleteDepartment(deptId, deptName) {
            if (!confirm('هل أنت متأكد من حذف قسم "' + deptName + '"?\n\nهذا الإجراء لا يمكن التراجع عنه.')) return;

            const formData = new FormData();
            formData.append('action', 'delete_department');
            formData.append('dept_id', deptId);

            fetch('manage_departments.php', {
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

        // عرض موظفي القسم
        function viewDepartmentEmployees(deptId, deptName) {
            // يمكن توجيه المستخدم إلى صفحة عرض الموظفين للقسم
            window.location.href = 'department_employees.php?dept_id=' + deptId + '&dept_name=' + encodeURIComponent(deptName);
        }

        // رسالة ترحيب في وحدة التحكم
        console.log('%cإدارة الأقسام', 'color: #3498db; font-size: 16px; font-weight: bold;');
        console.log('إجمالي الأقسام: <?php echo count($departments); ?>');
        console.log('إجمالي الموظفين: <?php echo $total_employees; ?>');
    </script>
</body>

</html>