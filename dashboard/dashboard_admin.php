<?php
/**
 * لوحة تحكم المسؤول (Admin Dashboard)
 * 
 * هذا الملف هو لوحة التحكم الرئيسية للمسؤول
 * تتيح للمسؤول إدارة المستخدمين، الأدوار، الأقسام، والمستندات
 */
require_once '../includes/session.php';
checkLogin();

// تحميل ملفات الإعدادات
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من أن المستخدم مسجل دخول وهو مسؤول
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// جلب إحصائيات النظام
$db = getDB();

// عدد المستخدمين
$stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
$stmt->execute();
$total_users = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// عدد المستندات
$stmt = $db->prepare("SELECT COUNT(*) as count FROM documents");
$stmt->execute();
$total_documents = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// المستندات المعلقة
$stmt = $db->prepare("SELECT COUNT(*) as count FROM documents WHERE current_status IN ('pending', 'under_review')");
$stmt->execute();
$pending_documents = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// المستندات المكتملة
$stmt = $db->prepare("SELECT COUNT(*) as count FROM documents WHERE current_status = 'completed'");
$stmt->execute();
$completed_documents = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// عدد الأدوار
$stmt = $db->prepare("SELECT COUNT(*) as count FROM roles");
$stmt->execute();
$total_roles = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// عدد الأقسام
$stmt = $db->prepare("SELECT COUNT(*) as count FROM departments");
$stmt->execute();
$total_departments = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// جلب آخر المستخدمين المسجلين
$stmt = $db->prepare("
    SELECT u.*, r.role_name, d.name as department_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    LEFT JOIN departments d ON u.department_id = d.id 
    ORDER BY u.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب آخر المستندات
$stmt = $db->prepare("
    SELECT d.*, u.full_name as creator_name 
    FROM documents d 
    JOIN users u ON d.created_by = u.id 
    ORDER BY d.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب المستندات التي تحتاج اهتمام (معلقة منذ أكثر من 3 أيام)
$stmt = $db->prepare("
    SELECT d.*, u.full_name as creator_name, 
           DATEDIFF(NOW(), d.created_at) as days_pending 
    FROM documents d 
    JOIN users u ON d.created_by = u.id 
    WHERE d.current_status IN ('pending', 'under_review') 
    AND DATEDIFF(NOW(), d.created_at) >= 3 
    ORDER BY d.created_at ASC 
    LIMIT 5
");
$stmt->execute();
$attention_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - المسؤول</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    
    <style>
        /* تنسيقات خاصة بلوحة المسؤول */
        .admin-dashboard {
            display: grid;
            grid-template-columns: 250px 1fr;
            grid-template-rows: auto 1fr;
            min-height: 100vh;
            background: #f5f7fa;
        }
        
        .admin-sidebar {
            grid-column: 1;
            grid-row: 1 / -1;
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            padding: 20px 0;
            box-shadow: 5px 0 15px rgba(0,0,0,0.1);
            z-index: 100;
            overflow-y: auto;
        }
        
        .admin-header {
            grid-column: 2;
            grid-row: 1;
            background: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-bottom: 1px solid #eaeaea;
        }
        
        .admin-main {
            grid-column: 2;
            grid-row: 2;
            padding: 30px;
            overflow-y: auto;
        }
        
        /* الشعار في الشريط الجانبي */
        .sidebar-logo {
            padding: 0 20px 30px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .sidebar-logo h2 {
            color: white;
            font-size: 1.3rem;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sidebar-logo p {
            color: rgba(255,255,255,0.7);
            font-size: 0.8rem;
        }
        
        /* قائمة التنقل الجانبية */
        .sidebar-nav ul {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-nav li {
            margin-bottom: 5px;
        }
        
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.3s;
            border-right: 3px solid transparent;
        }
        
        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255,255,255,0.1);
            color: white;
            border-right-color: #3498db;
        }
        
        .sidebar-nav i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        /* رأس لوحة التحكم */
        .header-left h1 {
            color: #2c3e50;
            font-size: 1.5rem;
            margin: 0;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .user-info h4 {
            margin: 0;
            color: #2c3e50;
            font-size: 0.95rem;
        }
        
        .user-info p {
            margin: 0;
            color: #7f8c8d;
            font-size: 0.8rem;
        }
        
        /* البطاقات الإحصائية */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            border-top: 4px solid #3498db;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card.users {
            border-top-color: #9b59b6;
        }
        
        .stat-card.documents {
            border-top-color: #2ecc71;
        }
        
        .stat-card.pending {
            border-top-color: #f39c12;
        }
        
        .stat-card.completed {
            border-top-color: #e74c3c;
        }
        
        .stat-card.roles {
            border-top-color: #1abc9c;
        }
        
        .stat-card.departments {
            border-top-color: #34495e;
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .stat-icon {
            font-size: 1.8rem;
            opacity: 0.8;
        }
        
        .stat-content h3 {
            font-size: 2rem;
            margin: 0 0 5px;
            color: #2c3e50;
        }
        
        .stat-content p {
            margin: 0;
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        /* الأقسام الرئيسية */
        .dashboard-sections {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }
        
        .dashboard-section {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .section-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #eaeaea;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-header h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.1rem;
        }
        
        .section-content {
            padding: 20px;
        }
        
        /* الجداول */
        .simple-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .simple-table th {
            text-align: right;
            padding: 12px 10px;
            border-bottom: 2px solid #eee;
            color: #7f8c8d;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .simple-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .simple-table tr:hover {
            background: #f8f9fa;
        }
        
        .user-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .document-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        /* التحذيرات */
        .attention-alert {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .attention-alert h4 {
            color: #856404;
            margin: 0 0 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* الأزرار السريعة */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .quick-btn {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            color: #6c757d;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .quick-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
            color: #495057;
        }
        
        .quick-btn i {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 8px;
            color: #3498db;
        }
        
        /* التوافق مع الأجهزة المحمولة */
        @media (max-width: 768px) {
            .admin-dashboard {
                grid-template-columns: 1fr;
            }
            
            .admin-sidebar {
                display: none;
                position: fixed;
                top: 0;
                right: 0;
                bottom: 0;
                width: 250px;
            }
            
            .admin-sidebar.active {
                display: block;
            }
            
            .admin-header {
                grid-column: 1;
            }
            
            .admin-main {
                grid-column: 1;
            }
            
            .menu-toggle {
                display: block;
                background: none;
                border: none;
                font-size: 1.5rem;
                color: #2c3e50;
                cursor: pointer;
            }
        }
        
        @media (min-width: 769px) {
            .menu-toggle {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="admin-dashboard">
        <!-- الشريط الجانبي -->
        <aside class="admin-sidebar" id="sidebar">
            <div class="sidebar-logo">
                <h2><i class="fas fa-crown"></i> لوحة المسؤول</h2>
                <p>إدارة النظام بالكامل</p>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="#" class="active"><i class="fas fa-tachometer-alt"></i> الرئيسية</a></li>
                    <li><a href="../admin/manage_users.php"><i class="fas fa-users"></i> إدارة المستخدمين</a></li>
                    <li><a href="../admin/manage_roles.php"><i class="fas fa-user-tag"></i> إدارة الأدوار</a></li>
                    <li><a href="../admin/manage_departments.php"><i class="fas fa-building"></i> إدارة الأقسام</a></li>
                    <li><a href="../documents/list_documents.php"><i class="fas fa-file-alt"></i> المستندات</a></li>
                    <li><a href="../admin/system_settings.php"><i class="fas fa-cogs"></i> إعدادات النظام</a></li>
                    <li><a href="../documents/create_document.php"><i class="fas fa-plus-circle"></i> مستند جديد</a></li>
                </ul>
                
                <div style="margin-top: 30px; padding: 0 20px;">
                    <h4 style="color: rgba(255,255,255,0.6); font-size: 0.8rem; margin-bottom: 10px;">روابط سريعة</h4>
                    <ul>
                        <li><a href="../dashboard/employee_dashboard.php"><i class="fas fa-user"></i> لوحة الموظف</a></li>
                        <li><a href="../dashboard/ceo_dashboard.php"><i class="fas fa-user-tie"></i> لوحة الرئيس التنفيذي</a></li>
                        <li><a href="../index.php"><i class="fas fa-home"></i> الصفحة الرئيسية</a></li>
                    </ul>
                </div>
            </nav>
        </aside>
        
        <!-- رأس الصفحة -->
        <header class="admin-header">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h1>مرحباً، <?php echo htmlspecialchars($_SESSION['full_name']); ?></h1>
            </div>
            
            <div class="header-right">
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php 
                        // عرض الحرف الأول من اسم المستخدم
                        echo mb_substr($_SESSION['full_name'], 0, 1, 'UTF-8'); 
                        ?>
                    </div>
                    <div class="user-info">
                        <h4><?php echo htmlspecialchars($_SESSION['full_name']); ?></h4>
                        <p>مسؤول النظام</p>
                    </div>
                </div>
                <a href="../logout.php" class="btn btn-danger" style="padding: 8px 20px;">
                    <i class="fas fa-sign-out-alt"></i> خروج
                </a>
            </div>
        </header>
        
        <!-- المحتوى الرئيسي -->
        <main class="admin-main">
            <!-- الإحصائيات -->
            <div class="stats-grid">
                <div class="stat-card users">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $total_users; ?></h3>
                        <p>المستخدمين النشطين</p>
                    </div>
                </div>
                
                <div class="stat-card documents">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $total_documents; ?></h3>
                        <p>إجمالي المستندات</p>
                    </div>
                </div>
                
                <div class="stat-card pending">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $pending_documents; ?></h3>
                        <p>مستندات قيد المراجعة</p>
                    </div>
                </div>
                
                <div class="stat-card completed">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $completed_documents; ?></h3>
                        <p>مستندات مكتملة</p>
                    </div>
                </div>
                
                <div class="stat-card roles">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-user-tag"></i>
                        </div>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $total_roles; ?></h3>
                        <p>أدوار في النظام</p>
                    </div>
                </div>
                
                <div class="stat-card departments">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $total_departments; ?></h3>
                        <p>أقسام في النظام</p>
                    </div>
                </div>
            </div>
            
            <!-- الأقسام الرئيسية -->
            <div class="dashboard-sections">
                <!-- المستخدمين الجدد -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h3><i class="fas fa-user-plus"></i> آخر المستخدمين</h3>
                        <a href="../admin/manage_users.php" class="btn btn-sm btn-primary">عرض الكل</a>
                    </div>
                    <div class="section-content">
                        <?php if (count($recent_users) > 0): ?>
                        <table class="simple-table">
                            <thead>
                                <tr>
                                    <th>اسم المستخدم</th>
                                    <th>البريد الإلكتروني</th>
                                    <th>الدور</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['role_name']); ?></td>
                                    <td>
                                        <span class="user-status <?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $user['is_active'] ? 'نشط' : 'غير نشط'; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p style="text-align: center; color: #7f8c8d;">لا توجد مستخدمين</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- آخر المستندات -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h3><i class="fas fa-file"></i> آخر المستندات</h3>
                        <a href="../documents/list_documents.php" class="btn btn-sm btn-primary">عرض الكل</a>
                    </div>
                    <div class="section-content">
                        <?php if (count($recent_documents) > 0): ?>
                        <table class="simple-table">
                            <thead>
                                <tr>
                                    <th>المستند</th>
                                    <th>المنشئ</th>
                                    <th>التاريخ</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_documents as $doc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(substr($doc['title'], 0, 20)); ?>...</td>
                                    <td><?php echo htmlspecialchars($doc['creator_name']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($doc['created_at'])); ?></td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        switch ($doc['current_status']) {
                                            case 'draft': $status_text = 'مسودة'; $status_class = 'status-draft'; break;
                                            case 'under_review': $status_text = 'قيد المراجعة'; $status_class = 'status-under_review'; break;
                                            case 'pending': $status_text = 'معلق'; $status_class = 'status-pending'; break;
                                            case 'completed': $status_text = 'مكتمل'; $status_class = 'status-completed'; break;
                                            case 'rejected': $status_text = 'مرفوض'; $status_class = 'status-rejected'; break;
                                            default: $status_text = $doc['current_status']; $status_class = 'status-draft';
                                        }
                                        ?>
                                        <span class="document-status <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p style="text-align: center; color: #7f8c8d;">لا توجد مستندات</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- المستندات التي تحتاج اهتمام -->
            <?php if (count($attention_documents) > 0): ?>
            <div class="dashboard-section" style="margin-top: 30px;">
                <div class="section-header" style="background: #fff3cd; border-bottom: 1px solid #ffeaa7;">
                    <h3 style="color: #856404;">
                        <i class="fas fa-exclamation-triangle"></i> مستندات تحتاج اهتمام
                    </h3>
                </div>
                <div class="section-content">
                    <div class="attention-alert">
                        <h4><i class="fas fa-clock"></i> مستندات معلقة منذ أكثر من 3 أيام</h4>
                        <p>يوجد <?php echo count($attention_documents); ?> مستندات تحتاج إلى متابعة.</p>
                    </div>
                    
                    <table class="simple-table">
                        <thead>
                            <tr>
                                <th>المستند</th>
                                <th>المنشئ</th>
                                <th>الأيام المعلقة</th>
                                <th>الإجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attention_documents as $doc): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($doc['title'], 0, 25)); ?>...</td>
                                <td><?php echo htmlspecialchars($doc['creator_name']); ?></td>
                                <td><span class="badge badge-warning"><?php echo $doc['days_pending']; ?> يوم</span></td>
                                <td>
                                    <a href="../documents/view_document.php?id=<?php echo $doc['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> عرض
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- أزرار سريعة -->
            <div class="quick-actions" style="margin-top: 30px;">
                <a href="../admin/manage_users.php" class="quick-btn">
                    <i class="fas fa-user-plus"></i>
                    إضافة مستخدم
                </a>
                <a href="../admin/manage_roles.php" class="quick-btn">
                    <i class="fas fa-user-tag"></i>
                    إضافة دور جديد
                </a>
                <a href="../admin/manage_departments.php" class="quick-btn">
                    <i class="fas fa-building"></i>
                    إضافة قسم
                </a>
                <a href="../documents/create_document.php" class="quick-btn">
                    <i class="fas fa-file-import"></i>
                    رفع مستند
                </a>
                <a href="../admin/system_settings.php" class="quick-btn">
                    <i class="fas fa-cog"></i>
                    الإعدادات
                </a>
                <a href="javascript:void(0)" onclick="generateReport()" class="quick-btn">
                    <i class="fas fa-chart-bar"></i>
                    تقرير شهري
                </a>
            </div>
            
            <!-- ملخص النظام -->
            <div class="dashboard-section" style="margin-top: 30px; background: #f8f9fa;">
                <div class="section-header">
                    <h3><i class="fas fa-info-circle"></i> معلومات النظام</h3>
                </div>
                <div class="section-content">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div>
                            <h4>إصدار النظام</h4>
                            <p>1.0.0</p>
                        </div>
                        <div>
                            <h4>آخر تحديث</h4>
                            <p><?php echo date('Y-m-d'); ?></p>
                        </div>
                        <div>
                            <h4>إجمالي السعة المستخدمة</h4>
                            <p>
                                <?php
                                // حساب حجم الملفات في مجلد المستندات
                                $upload_path = '../assets/uploads/documents/';
                                $total_size = 0;
                                if (is_dir($upload_path)) {
                                    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upload_path)) as $file) {
                                        if ($file->isFile()) {
                                            $total_size += $file->getSize();
                                        }
                                    }
                                }
                                echo round($total_size / (1024 * 1024), 2) . ' MB';
                                ?>
                            </p>
                        </div>
                        <div>
                            <h4>حالة النظام</h4>
                            <p><span class="badge badge-success" style="background: #27ae60; color: white; padding: 3px 10px; border-radius: 20px;">جيد</span></p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // التحكم في عرض/إخفاء الشريط الجانبي على الأجهزة المحمولة
        document.getElementById('menuToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        });
        
        // إغلاق الشريط الجانبي عند النقر خارجها
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
        
        // تغيير حجم النافذة
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
            }
        });
        
        // دالة لتوليد التقرير
        function generateReport() {
            if (confirm('هل تريد توليد تقرير شهري؟')) {
                // في النسخة الحقيقية، هنا سيتم إرسال طلب AJAX لتوليد التقرير
                alert('جاري توليد التقرير...\nفي النسخة الكاملة، سيتم إنشاء ملف PDF وتنزيله تلقائياً.');
                
                // محاكاة عملية التوليد
                setTimeout(function() {
                    alert('تم توليد التقرير بنجاح!');
                }, 1500);
            }
        }
        
        // تحديث الإحصائيات تلقائياً كل دقيقة
        setInterval(function() {
            // في النسخة الكاملة، هنا سيتم جلب البيانات الجديدة عبر AJAX
            console.log('تحديث الإحصائيات...');
        }, 60000);
        
        // رسالة ترحيب
        console.log('مرحباً بك في لوحة تحكم المسؤول');
    </script>
</body>
</html>