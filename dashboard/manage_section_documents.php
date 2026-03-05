<?php
/**
 * صفحة إدارة موظفي القسم - رئيس القسم
 */
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من أن المستخدم مسجل دخول وله دور section_manager
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'section_manager') {
    header("Location: ../login.php");
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$department_id = $_SESSION['department_id'] ?? null;

// معالجة البحث والتصفية
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'full_name';
$sort_order = $_GET['sort_order'] ?? 'ASC';

// جلب الموظفين في القسم
$where_conditions = ["(u.supervisor_id = :user_id OR u.department_id = :dept_id)"];
$params = [
    ':user_id' => $user_id,
    ':dept_id' => $department_id
];

// تطبيق البحث النصي
if (!empty($search)) {
    $where_conditions[] = "(u.full_name LIKE :search OR u.email LIKE :search OR u.username LIKE :search)";
    $params[':search'] = "%{$search}%";
}

// تصفية حسب الحالة
if (!empty($status)) {
    if ($status === 'active') {
        $where_conditions[] = "u.is_active = 1";
    } elseif ($status === 'inactive') {
        $where_conditions[] = "u.is_active = 0";
    }
}

// بناء جملة WHERE
$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// جلب الموظفين مع إحصائياتهم
$query = "
    SELECT 
        u.*,
        d.name as department_name,
        u2.full_name as supervisor_name,
        COUNT(DISTINCT doc.id) as documents_count,
        COUNT(DISTINCT CASE WHEN doc.current_status = 'completed' THEN doc.id END) as completed_docs,
        COUNT(DISTINCT CASE WHEN doc.current_status = 'pending' THEN doc.id END) as pending_docs,
        MAX(doc.created_at) as last_document_date
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN users u2 ON u.supervisor_id = u2.id
    LEFT JOIN documents doc ON u.id = doc.created_by
    {$where_sql}
    GROUP BY u.id
    ORDER BY {$sort_by} {$sort_order}
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN u.is_active = 0 THEN 1 ELSE 0 END) as inactive,
        AVG(emp_stats.doc_count) as avg_documents,
        SUM(emp_stats.completed_docs) as total_completed
    FROM users u
    LEFT JOIN (
        SELECT created_by, 
               COUNT(*) as doc_count,
               SUM(CASE WHEN current_status = 'completed' THEN 1 ELSE 0 END) as completed_docs
        FROM documents
        GROUP BY created_by
    ) emp_stats ON u.id = emp_stats.created_by
    WHERE u.supervisor_id = :user_id OR u.department_id = :dept_id
";

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute([':user_id' => $user_id, ':dept_id' => $department_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// معالجة إضافة موظف جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // التحقق من البيانات
    $errors = [];
    
    if (empty($username)) $errors[] = 'اسم المستخدم مطلوب';
    if (empty($email)) $errors[] = 'البريد الإلكتروني مطلوب';
    if (empty($full_name)) $errors[] = 'الاسم الكامل مطلوب';
    if (empty($password)) $errors[] = 'كلمة المرور مطلوبة';
    if ($password !== $confirm_password) $errors[] = 'كلمة المرور غير متطابقة';
    
    // التحقق من عدم تكرار اسم المستخدم أو البريد الإلكتروني
    if (empty($errors)) {
        $check_stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check_stmt->execute([$username, $email]);
        if ($check_stmt->fetch()) {
            $errors[] = 'اسم المستخدم أو البريد الإلكتروني موجود مسبقاً';
        }
    }
    
    if (empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $insert_stmt = $db->prepare("
                INSERT INTO users (username, password, email, full_name, role_id, 
                                 department_id, supervisor_id, is_active, created_at)
                VALUES (?, ?, ?, ?, 5, ?, ?, 1, NOW())
            ");
            
            $insert_stmt->execute([
                $username,
                $hashed_password,
                $email,
                $full_name,
                $department_id,
                $user_id
            ]);
            
            $new_employee_id = $db->lastInsertId();
            $success_message = "تم إضافة الموظف بنجاح";
            
            // إعادة تحميل الصفحة لإظهار الموظف الجديد
            header("Location: section_employees.php?success=1");
            exit();
            
        } catch (Exception $e) {
            $errors[] = "حدث خطأ: " . $e->getMessage();
        }
    }
}

// التحقق من وجود رسالة نجاح في URL
if (isset($_GET['success'])) {
    $success_message = "تمت العملية بنجاح!";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة موظفي القسم - رئيس القسم</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #9b59b6;
            --secondary-color: #8e44ad;
            --success-color: #27ae60;
            --warning-color: #f39c12;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Cairo', sans-serif;
        }
        
        body {
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
            color: white;
            padding: 0;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
            max-width: 1400px;
            margin: 0 auto;
            height: 70px;
        }
        
        .page-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .page-title {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            border-right: 5px solid var(--primary-color);
        }
        
        .page-title h1 {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #2c3e50;
            margin: 0;
        }
        
        .page-title h1 i {
            color: var(--primary-color);
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
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            border-top: 4px solid var(--primary-color);
            text-align: center;
        }
        
        .stat-card.total { border-top-color: #9b59b6; }
        .stat-card.active { border-top-color: #2ecc71; }
        .stat-card.inactive { border-top-color: #e74c3c; }
        .stat-card.avg-docs { border-top-color: #3498db; }
        .stat-card.completed { border-top-color: #2ecc71; }
        
        .stat-number {
            font-size: 2.2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .filters-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
            color: white;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(155, 89, 182, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            color: white;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
        }
        
        .employees-table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .table-header {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h3 {
            margin: 0;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .employees-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .employees-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: right;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #e9ecef;
        }
        
        .employees-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .employees-table tr:hover {
            background: #f8f9fa;
        }
        
        .employee-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .employee-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
        }
        
        .employee-details {
            flex: 1;
        }
        
        .employee-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 3px;
        }
        
        .employee-email {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        
        .stats-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.1rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 3px;
        }
        
        .stat-label-small {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .action-btn {
            padding: 6px 10px;
            border: none;
            border-radius: 4px;
            font-size: 0.85rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }
        
        .action-btn.view {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .action-btn.edit {
            background: #fff3e0;
            color: #ef6c00;
        }
        
        .action-btn.deactivate {
            background: #ffebee;
            color: #c62828;
        }
        
        .action-btn.activate {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #dee2e6;
        }
        
        .nav-links {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 6px;
            background: rgba(255,255,255,0.15);
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }
        
        .nav-links a:hover {
            background: rgba(255,255,255,0.25);
        }
        
        .nav-links a.active {
            background: rgba(255,255,255,0.25);
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-header {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
            color: white;
            padding: 20px;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        @media (max-width: 768px) {
            .page-container {
                padding: 0 15px;
            }
            
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .filter-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .employees-table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div style="display: flex; align-items: center; gap: 15px;">
                <div style="width: 40px; height: 40px; background: white; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #9b59b6; font-size: 1.3rem;">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.1rem; font-weight: 600;">نظام التوقيع الإلكتروني</h3>
                    <p style="margin: 0; font-size: 0.8rem; opacity: 0.8;">إدارة موظفي القسم</p>
                </div>
            </div>
            
            <nav class="nav-links">
                <a href="section_manager_dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> الرئيسية
                </a>
                <a href="manage_section_documents.php">
                    <i class="fas fa-file-alt"></i> المستندات
                </a>
                <a href="section_employees.php" class="active">
                    <i class="fas fa-users"></i> الموظفين
                </a>
            </nav>
        </div>
    </div>
    
    <div class="page-container">
        <!-- الرسائل -->
        <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($errors) && !empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php foreach ($errors as $error): ?>
            <div><?php echo $error; ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- عنوان الصفحة -->
        <div class="page-title">
            <h1>
                <i class="fas fa-users"></i>
                إدارة موظفي القسم
            </h1>
            <p style="margin-top: 10px; color: #6c757d;">
                إدارة الموظفين في قسمك. يمكنك إضافة موظفين جدد، تعديل معلوماتهم، أو تعطيل حساباتهم.
            </p>
        </div>
        
        <!-- الإحصائيات -->
        <div class="stats-cards">
            <div class="stat-card total">
                <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">إجمالي الموظفين</div>
            </div>
            
            <div class="stat-card active">
                <div class="stat-number"><?php echo $stats['active'] ?? 0; ?></div>
                <div class="stat-label">نشطين</div>
            </div>
            
            <div class="stat-card inactive">
                <div class="stat-number"><?php echo $stats['inactive'] ?? 0; ?></div>
                <div class="stat-label">غير نشطين</div>
            </div>
            
            <div class="stat-card avg-docs">
                <div class="stat-number"><?php echo round($stats['avg_documents'] ?? 0, 1); ?></div>
                <div class="stat-label">متوسط المستندات</div>
            </div>
            
            <div class="stat-card completed">
                <div class="stat-number"><?php echo $stats['total_completed'] ?? 0; ?></div>
                <div class="stat-label">مستندات مكتملة</div>
            </div>
        </div>
        
        <!-- الفلاتر والإجراءات -->
        <div class="filters-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-filter"></i> فلاتر البحث
                </h3>
                
                <button type="button" class="btn btn-success" onclick="openAddEmployeeModal()">
                    <i class="fas fa-user-plus"></i> إضافة موظف جديد
                </button>
            </div>
            
            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="form-group">
                        <label>بحث</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="اسم، بريد، أو اسم مستخدم..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>الحالة</label>
                        <select name="status" class="form-control">
                            <option value="">جميع الحالات</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>نشط</option>
                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>غير نشط</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>الترتيب حسب</label>
                        <select name="sort_by" class="form-control">
                            <option value="full_name" <?php echo $sort_by === 'full_name' ? 'selected' : ''; ?>>الاسم</option>
                            <option value="documents_count DESC" <?php echo $sort_by === 'documents_count DESC' ? 'selected' : ''; ?>>عدد المستندات</option>
                            <option value="last_document_date DESC" <?php echo $sort_by === 'last_document_date DESC' ? 'selected' : ''; ?>>آخر مستند</option>
                        </select>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> بحث
                        </button>
                        <a href="section_employees.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> إعادة تعيين
                        </a>
                        <button type="button" onclick="exportEmployees()" class="btn btn-warning">
                            <i class="fas fa-file-export"></i> تصدير
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- جدول الموظفين -->
        <div class="employees-table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> قائمة الموظفين</h3>
                <span style="color: #6c757d; font-size: 0.9rem;">
                    <?php echo count($employees); ?> موظف
                </span>
            </div>
            
            <?php if (empty($employees)): ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <h3 style="color: #6c757d; margin-bottom: 10px;">لا يوجد موظفين</h3>
                <p style="color: #adb5bd;">لم يتم العثور على موظفين تطابق معايير البحث</p>
                <button type="button" class="btn btn-primary" onclick="openAddEmployeeModal()" style="margin-top: 20px;">
                    <i class="fas fa-user-plus"></i> إضافة أول موظف
                </button>
            </div>
            <?php else: ?>
            <table class="employees-table">
                <thead>
                    <tr>
                        <th>الموظف</th>
                        <th>الحالة</th>
                        <th>المستندات</th>
                        <th>المكتملة</th>
                        <th>قيد الانتظار</th>
                        <th>آخر مستند</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp): 
                        $initials = mb_substr($emp['full_name'], 0, 1, 'UTF-8');
                        $last_doc = $emp['last_document_date'] ? date('Y-m-d', strtotime($emp['last_document_date'])) : 'لا يوجد';
                        $completion_rate = $emp['documents_count'] > 0 ? 
                            round(($emp['completed_docs'] / $emp['documents_count']) * 100) : 0;
                    ?>
                    <tr>
                        <td>
                            <div class="employee-info">
                                <div class="employee-avatar">
                                    <?php echo $initials; ?>
                                </div>
                                <div class="employee-details">
                                    <div class="employee-name"><?php echo htmlspecialchars($emp['full_name']); ?></div>
                                    <div class="employee-email"><?php echo htmlspecialchars($emp['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        
                        <td>
                            <span class="status-badge status-<?php echo $emp['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $emp['is_active'] ? 'نشط' : 'غير نشط'; ?>
                            </span>
                        </td>
                        
                        <td class="stats-item">
                            <div class="stat-value"><?php echo $emp['documents_count']; ?></div>
                            <div class="stat-label-small">مستند</div>
                        </td>
                        
                        <td class="stats-item">
                            <div class="stat-value"><?php echo $emp['completed_docs']; ?></div>
                            <div class="stat-label-small">مكتمل (<?php echo $completion_rate; ?>%)</div>
                        </td>
                        
                        <td class="stats-item">
                            <div class="stat-value"><?php echo $emp['pending_docs']; ?></div>
                            <div class="stat-label-small">قيد الانتظار</div>
                        </td>
                        
                        <td>
                            <span style="color: #6c757d; font-size: 0.9rem;">
                                <?php echo $last_doc; ?>
                            </span>
                        </td>
                        
                        <td>
                            <div class="action-buttons">
                                <a href="view_employee.php?id=<?php echo $emp['id']; ?>" 
                                   class="action-btn view">
                                    <i class="fas fa-eye"></i>
                                </a>
                                
                                <button type="button" onclick="editEmployee(<?php echo $emp['id']; ?>)" 
                                        class="action-btn edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                
                                <?php if ($emp['is_active']): ?>
                                <button type="button" onclick="toggleEmployeeStatus(<?php echo $emp['id']; ?>, 0)" 
                                        class="action-btn deactivate">
                                    <i class="fas fa-user-slash"></i>
                                </button>
                                <?php else: ?>
                                <button type="button" onclick="toggleEmployeeStatus(<?php echo $emp['id']; ?>, 1)" 
                                        class="action-btn activate">
                                    <i class="fas fa-user-check"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- نافذة إضافة موظف -->
    <div id="addEmployeeModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> إضافة موظف جديد</h3>
                <button type="button" onclick="closeAddEmployeeModal()" 
                        style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">
                    &times;
                </button>
            </div>
            
            <form method="POST" id="addEmployeeForm">
                <div class="modal-body">
                    <input type="hidden" name="add_employee" value="1">
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">
                            اسم المستخدم <span style="color: #e74c3c;">*</span>
                        </label>
                        <input type="text" name="username" class="form-control" 
                               placeholder="أدخل اسم المستخدم" required>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">
                            البريد الإلكتروني <span style="color: #e74c3c;">*</span>
                        </label>
                        <input type="email" name="email" class="form-control" 
                               placeholder="example@company.com" required>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">
                            الاسم الكامل <span style="color: #e74c3c;">*</span>
                        </label>
                        <input type="text" name="full_name" class="form-control" 
                               placeholder="أدخل الاسم الكامل" required>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">
                            كلمة المرور <span style="color: #e74c3c;">*</span>
                        </label>
                        <input type="password" name="password" class="form-control" 
                               placeholder="أدخل كلمة المرور" required minlength="6">
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">
                            تأكيد كلمة المرور <span style="color: #e74c3c;">*</span>
                        </label>
                        <input type="password" name="confirm_password" class="form-control" 
                               placeholder="أعد إدخال كلمة المرور" required>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeAddEmployeeModal()" class="btn btn-secondary">
                        <i class="fas fa-times"></i> إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> إضافة الموظف
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openAddEmployeeModal() {
            document.getElementById('addEmployeeModal').style.display = 'flex';
        }
        
        function closeAddEmployeeModal() {
            document.getElementById('addEmployeeModal').style.display = 'none';
        }
        
        function editEmployee(employeeId) {
            window.location.href = 'edit_employee.php?id=' + employeeId;
        }
        
        function toggleEmployeeStatus(employeeId, newStatus) {
            const action = newStatus ? 'تفعيل' : 'تعطيل';
            if (confirm(`هل تريد ${action} هذا الموظف؟`)) {
                fetch('toggle_employee_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        employee_id: employeeId,
                        new_status: newStatus 
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`تم ${action} الموظف بنجاح`);
                        window.location.reload();
                    } else {
                        alert('حدث خطأ: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في الاتصال');
                });
            }
        }
        
        function exportEmployees() {
            const filters = new URLSearchParams(window.location.search);
            window.location.href = 'export_employees.php?' + filters.toString();
        }
        
        // إغلاق النافذة المنبثقة عند النقر خارجها
        window.onclick = function(event) {
            const modal = document.getElementById('addEmployeeModal');
            if (event.target === modal) {
                closeAddEmployeeModal();
            }
        };
        
        // التحقق من صحة نموذج إضافة الموظف
        document.getElementById('addEmployeeForm').addEventListener('submit', function(e) {
            const password = document.querySelector('input[name="password"]').value;
            const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('كلمة المرور غير متطابقة');
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('كلمة المرور يجب أن تكون 6 أحرف على الأقل');
                return false;
            }
        });
    </script>
</body>
</html>