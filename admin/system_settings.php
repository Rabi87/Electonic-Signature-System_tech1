<?php
/**
 * إعدادات النظام - نظام التوقيع الإلكتروني
 * 
 * هذا الملف يمكن المسؤول من إدارة إعدادات النظام العامة
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

// إنشاء جدول system_settings إذا لم يكن موجوداً
$db->exec("
    CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        setting_group VARCHAR(50) DEFAULT 'general',
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
");

// جلب الإعدادات الحالية من قاعدة البيانات
$settings = $db->query("SELECT * FROM system_settings ORDER BY setting_group, setting_key")->fetchAll(PDO::FETCH_ASSOC);

// تحويل الإعدادات إلى مصفوفة مفيدة للوصول السريع
$settings_map = [];
foreach ($settings as $setting) {
    $settings_map[$setting['setting_key']] = $setting['setting_value'];
}

// الإعدادات الافتراضية إذا لم تكن موجودة في قاعدة البيانات
$default_settings = [
    'general' => [
        ['key' => 'site_name', 'value' => 'نظام التوقيع الإلكتروني', 'description' => 'اسم النظام'],
        ['key' => 'site_description', 'value' => 'نظام إدارة وتوقيع المستندات الإلكترونية', 'description' => 'وصف النظام'],
        ['key' => 'site_url', 'value' => 'http://localhost/signature_system', 'description' => 'رابط النظام'],
        ['key' => 'contact_email', 'value' => 'admin@example.com', 'description' => 'البريد الإلكتروني للاتصال'],
        ['key' => 'timezone', 'value' => 'Asia/Riyadh', 'description' => 'المنطقة الزمنية'],
    ],
    'email' => [
        ['key' => 'smtp_host', 'value' => 'smtp.gmail.com', 'description' => 'خادم SMTP'],
        ['key' => 'smtp_port', 'value' => '587', 'description' => 'منفذ SMTP'],
        ['key' => 'smtp_username', 'value' => '', 'description' => 'اسم مستخدم SMTP'],
        ['key' => 'smtp_password', 'value' => '', 'description' => 'كلمة مرور SMTP'],
        ['key' => 'smtp_encryption', 'value' => 'tls', 'description' => 'نوع التشفير'],
        ['key' => 'from_email', 'value' => 'noreply@example.com', 'description' => 'البريد المرسل منه'],
        ['key' => 'from_name', 'value' => 'نظام التوقيع الإلكتروني', 'description' => 'اسم المرسل'],
    ],
    'security' => [
        ['key' => 'password_min_length', 'value' => '6', 'description' => 'الحد الأدنى لطول كلمة المرور'],
        ['key' => 'password_require_numbers', 'value' => '1', 'description' => 'طلب أرقام في كلمة المرور'],
        ['key' => 'login_attempts', 'value' => '5', 'description' => 'عدد محاولات تسجيل الدخول المسموحة'],
        ['key' => 'session_timeout', 'value' => '30', 'description' => 'مدة انتهاء الجلسة (بالدقائق)'],
        ['key' => 'require_2fa', 'value' => '0', 'description' => 'تطلب المصادقة الثنائية'],
        ['key' => 'password_require_uppercase', 'value' => '0', 'description' => 'طلب أحرف كبيرة في كلمة المرور'],
        ['key' => 'password_require_special', 'value' => '0', 'description' => 'طلب رموز خاصة في كلمة المرور'],
    ],
    'document' => [
        ['key' => 'max_file_size', 'value' => '10485760', 'description' => 'الحد الأقصى لحجم الملف (بايت)'],
        ['key' => 'allowed_file_types', 'value' => 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'description' => 'صيغ الملفات المسموحة'],
        ['key' => 'default_priority', 'value' => 'medium', 'description' => 'الأولية الافتراضية'],
        ['key' => 'auto_archive_days', 'value' => '30', 'description' => 'الأيام التلقائية للأرشفة'],
        ['key' => 'enable_comments', 'value' => '1', 'description' => 'تفعيل التعليقات'],
        ['key' => 'enable_reminders', 'value' => '0', 'description' => 'تفعيل التذكيرات التلقائية'],
        ['key' => 'enable_versioning', 'value' => '0', 'description' => 'تفعيل إصدارات المستندات'],
    ],
    'appearance' => [
        ['key' => 'theme_color', 'value' => '#3498db', 'description' => 'لون السمة الرئيسي'],
        ['key' => 'background_color', 'value' => '#f5f7fa', 'description' => 'لون الخلفية'],
        ['key' => 'logo_url', 'value' => '../assets/images/logo.png', 'description' => 'رابط الشعار'],
        ['key' => 'favicon_url', 'value' => '../assets/images/favicon.ico', 'description' => 'رابط الأيقونة'],
        ['key' => 'rtl_enabled', 'value' => '1', 'description' => 'تفعيل اتجاه اليمين لليسار'],
        ['key' => 'heading_font', 'value' => 'Cairo', 'description' => 'خط العنوان'],
        ['key' => 'body_font', 'value' => 'Cairo', 'description' => 'خط المحتوى'],
    ],
    'maintenance' => [
        ['key' => 'maintenance_mode', 'value' => '0', 'description' => 'تفعيل وضع الصيانة'],
        ['key' => 'maintenance_message', 'value' => 'النظام قيد الصيانة حالياً. سنعود قريباً.', 'description' => 'رسالة وضع الصيانة'],
        ['key' => 'error_logging', 'value' => '1', 'description' => 'تفعيل تسجيل الأخطاء'],
        ['key' => 'auto_backup', 'value' => '0', 'description' => 'تفعيل النسخ الاحتياطي التلقائي'],
        ['key' => 'log_retention_days', 'value' => '90', 'description' => 'فترة احتفاظ السجلات (أيام)'],
    ]
];

// التأكد من وجود جميع الإعدادات الافتراضية في قاعدة البيانات
foreach ($default_settings as $group => $group_settings) {
    foreach ($group_settings as $setting) {
        $key = $setting['key'];
        
        if (!isset($settings_map[$key])) {
            // إدخال الإعداد الافتراضي في قاعدة البيانات
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value, setting_group, description) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $key, 
                $setting['value'], 
                $group, 
                $setting['description']
            ]);
            
            // إضافة إلى الخريطة للاستخدام في الجلسة الحالية
            $settings_map[$key] = $setting['value'];
        }
    }
}

// دالة مساعدة للحصول على قيمة الإعداد
function getSetting($key, $default = '') {
    global $settings_map;
    return isset($settings_map[$key]) ? $settings_map[$key] : $default;
}

// معالجة طلبات AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];
    
    header('Content-Type: application/json');
    
    try {
        switch ($action) {
            case 'save_settings':
                $settings_data = $_POST['settings'] ?? [];
                
                foreach ($settings_data as $key => $value) {
                    // تنظيف القيمة
                    $value = trim($value);
                    
                    // البحث عن المجموعة والوصف للإعداد
                    $group = 'general';
                    $description = '';
                    
                    foreach ($default_settings as $g => $group_settings) {
                        foreach ($group_settings as $setting) {
                            if ($setting['key'] == $key) {
                                $group = $g;
                                $description = $setting['description'];
                                break 2;
                            }
                        }
                    }
                    
                    // التحقق من وجود الإعداد
                    $check_stmt = $db->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
                    $check_stmt->execute([$key]);
                    
                    if ($check_stmt->fetch()) {
                        // تحديث الإعداد الحالي
                        $stmt = $db->prepare("
                            UPDATE system_settings 
                            SET setting_value = ?, setting_group = ?, description = ?, updated_at = NOW() 
                            WHERE setting_key = ?
                        ");
                        $stmt->execute([$value, $group, $description, $key]);
                    } else {
                        // إضافة إعداد جديد
                        $stmt = $db->prepare("
                            INSERT INTO system_settings (setting_key, setting_value, setting_group, description) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([$key, $value, $group, $description]);
                    }
                    
                    // تحديث الخريطة المحلية
                    $settings_map[$key] = $value;
                }
                
                $response['success'] = true;
                $response['message'] = 'تم حفظ الإعدادات بنجاح';
                break;
                
            case 'reset_to_default':
                // حذف جميع الإعدادات الحالية
                $db->exec("DELETE FROM system_settings");
                
                // إضافة الإعدادات الافتراضية
                foreach ($default_settings as $group => $group_settings) {
                    foreach ($group_settings as $setting) {
                        $stmt = $db->prepare("
                            INSERT INTO system_settings (setting_key, setting_value, setting_group, description) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $setting['key'], 
                            $setting['value'], 
                            $group, 
                            $setting['description']
                        ]);
                    }
                }
                
                $response['success'] = true;
                $response['message'] = 'تم إعادة تعيين الإعدادات إلى الافتراضية';
                break;
                
            case 'clear_cache':
                // مسح الكاش - هنا يمكن إضافة منطق مسح الكاش الخاص بالنظام
                $cache_dir = '../cache/';
                if (is_dir($cache_dir)) {
                    array_map('unlink', glob($cache_dir . '*.*'));
                }
                
                $response['success'] = true;
                $response['message'] = 'تم مسح الكاش بنجاح';
                break;
                
            case 'test_email':
                $to_email = $_POST['test_email'] ?? '';
                
                if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
                    $response['message'] = 'البريد الإلكتروني غير صالح';
                    break;
                }
                
                // محاولة إرسال بريد تجريبي
                $subject = "اختبار إرسال البريد الإلكتروني من نظام التوقيع";
                $message = "هذا بريد تجريبي لاختبار إعدادات البريد الإلكتروني.\n\n";
                $message .= "تم الإرسال في: " . date('Y-m-d H:i:s') . "\n";
                $message .= "إعدادات النظام:\n";
                $message .= "- اسم النظام: " . getSetting('site_name') . "\n";
                $message .= "- رابط النظام: " . getSetting('site_url') . "\n";
                
                $headers = "From: " . getSetting('from_email', 'noreply@signature-system.com') . "\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                
                if (mail($to_email, $subject, $message, $headers)) {
                    $response['success'] = true;
                    $response['message'] = 'تم إرسال البريد التجريبي بنجاح إلى ' . $to_email;
                } else {
                    $response['message'] = 'فشل إرسال البريد التجريبي. تحقق من إعدادات SMTP';
                }
                break;
                
            case 'optimize_database':
                // تحسين قاعدة البيانات
                $tables = ['users', 'documents', 'document_fields', 'document_user_status', 'notifications'];
                $optimized_tables = [];
                
                foreach ($tables as $table) {
                    try {
                        $db->exec("OPTIMIZE TABLE `$table`");
                        $optimized_tables[] = $table;
                    } catch (PDOException $e) {
                        // تجاهل الأخطاء للجداول غير الموجودة
                    }
                }
                
                $response['success'] = true;
                $response['message'] = 'تم تحسين الجداول التالية: ' . implode(', ', $optimized_tables);
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

// API للحصول على إعداد معين
if (isset($_GET['action']) && $_GET['action'] == 'get_setting') {
    $key = $_GET['key'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'setting' => $setting
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادات النظام - نظام التوقيع الإلكتروني</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        .settings-management {
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
        
        .settings-tabs {
            display: flex;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .tab-btn {
            padding: 12px 25px;
            border: none;
            background: none;
            cursor: pointer;
            font-family: 'Cairo', sans-serif;
            font-size: 1rem;
            color: #6c757d;
            border-radius: 5px;
            transition: all 0.3s;
            margin: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .tab-btn:hover {
            background: #e9ecef;
            color: #495057;
        }
        
        .tab-btn.active {
            background: #3498db;
            color: white;
        }
        
        .settings-content {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .tab-content {
            padding: 30px;
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .settings-group {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .settings-group:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .settings-group h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f8f9fa;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .setting-item {
            margin-bottom: 20px;
        }
        
        .setting-label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 500;
        }
        
        .setting-description {
            display: block;
            color: #6c757d;
            font-size: 0.85rem;
            margin-bottom: 5px;
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
        
        select.form-control {
            height: 42px;
        }
        
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        
        .actions-bar {
            position: sticky;
            bottom: 0;
            background: white;
            padding: 20px 30px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .test-email-form {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .test-email-form input {
            flex: 1;
        }
        
        .info-card {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .info-card h4 {
            color: #0066cc;
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        @media (max-width: 768px) {
            .settings-tabs {
                flex-direction: column;
            }
            
            .tab-btn {
                width: 100%;
                justify-content: center;
            }
            
            .actions-bar {
                flex-direction: column;
                gap: 15px;
            }
            
            .test-email-form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/admin_header.php'; ?>
    
    <div class="settings-management">
        <div class="page-header">
            <h1><i class="fas fa-cogs"></i> إعدادات النظام</h1>
            <div class="action-buttons">
                <button class="btn btn-danger" onclick="resetToDefault()">
                    <i class="fas fa-undo"></i> إعادة تعيين
                </button>
                <button class="btn btn-warning" onclick="clearCache()">
                    <i class="fas fa-trash"></i> مسح الكاش
                </button>
            </div>
        </div>
        
        <!-- رسائل التبليغ -->
        <div id="messageAlert" class="alert" style="display: none;"></div>
        
        <!-- تبويبات الإعدادات -->
        <div class="settings-tabs">
            <button class="tab-btn active" onclick="showTab('general')">
                <i class="fas fa-home"></i> عام
            </button>
            <button class="tab-btn" onclick="showTab('email')">
                <i class="fas fa-envelope"></i> البريد الإلكتروني
            </button>
            <button class="tab-btn" onclick="showTab('security')">
                <i class="fas fa-shield-alt"></i> الأمان
            </button>
            <button class="tab-btn" onclick="showTab('document')">
                <i class="fas fa-file-alt"></i> المستندات
            </button>
            <button class="tab-btn" onclick="showTab('appearance')">
                <i class="fas fa-paint-brush"></i> المظهر
            </button>
            <button class="tab-btn" onclick="showTab('maintenance')">
                <i class="fas fa-tools"></i> الصيانة
            </button>
        </div>
        
        <!-- محتوى الإعدادات -->
        <div class="settings-content">
            <form id="settingsForm">
                
                <!-- تبويب الإعدادات العامة -->
                <div id="general-tab" class="tab-content active">
                    <div class="info-card">
                        <h4><i class="fas fa-info-circle"></i> معلومات هامة</h4>
                        <p>الإعدادات العامة تحدد سلوك النظام الأساسي والمعلومات الظاهرة للمستخدمين.</p>
                    </div>
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-globe"></i> معلومات النظام</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">اسم النظام</label>
                                <span class="setting-description">الاسم الذي يظهر في أعلى الصفحة والبريد الإلكتروني</span>
                                <input type="text" name="settings[site_name]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('site_name')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">وصف النظام</label>
                                <span class="setting-description">وصف مختصر للنظام يظهر في بعض الصفحات</span>
                                <input type="text" name="settings[site_description]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('site_description')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">رابط النظام</label>
                                <span class="setting-description">الرابط الأساسي للنظام (يستخدم في الروابط)</span>
                                <input type="url" name="settings[site_url]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('site_url')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">البريد الإلكتروني للاتصال</label>
                                <span class="setting-description">يظهر للمستخدمين للتواصل مع الإدارة</span>
                                <input type="email" name="settings[contact_email]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('contact_email')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">المنطقة الزمنية</label>
                                <span class="setting-description">تحديد المنطقة الزمنية للنظام</span>
                                <select name="settings[timezone]" class="form-control">
                                    <?php
                                    $timezones = [
                                        'Asia/Riyadh' => 'الرياض (+3)',
                                        'Asia/Damascus' => 'دمشق (+2)',
                                        'Asia/Dubai' => 'دبي (+4)',
                                        'Asia/Beirut' => 'بيروت (+2)',
                                        'Asia/Amman' => 'عمان (+2)',
                                        'Europe/London' => 'لندن (+0)',
                                    ];
                                    
                                    $current_tz = getSetting('timezone');
                                    foreach ($timezones as $tz => $label) {
                                        $selected = ($tz == $current_tz) ? 'selected' : '';
                                        echo "<option value='$tz' $selected>$label</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- تبويب إعدادات البريد -->
                <div id="email-tab" class="tab-content">
                    <div class="info-card">
                        <h4><i class="fas fa-envelope"></i> إعدادات البريد الإلكتروني</h4>
                        <p>تستخدم لإرسال الإشعارات والرسائل التلقائية للمستخدمين.</p>
                    </div>
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-server"></i> إعدادات SMTP</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">خادم SMTP</label>
                                <input type="text" name="settings[smtp_host]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('smtp_host')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">منفذ SMTP</label>
                                <input type="number" name="settings[smtp_port]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('smtp_port')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">اسم مستخدم SMTP</label>
                                <input type="text" name="settings[smtp_username]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('smtp_username')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">كلمة مرور SMTP</label>
                                <input type="password" name="settings[smtp_password]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('smtp_password')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">نوع التشفير</label>
                                <select name="settings[smtp_encryption]" class="form-control">
                                    <?php
                                    $encryptions = ['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'بدون تشفير'];
                                    $current_enc = getSetting('smtp_encryption');
                                    foreach ($encryptions as $key => $label) {
                                        $selected = ($key == $current_enc) ? 'selected' : '';
                                        echo "<option value='$key' $selected>$label</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-cog"></i> إعدادات الإرسال</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">البريد المرسل منه</label>
                                <input type="email" name="settings[from_email]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('from_email')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">اسم المرسل</label>
                                <input type="text" name="settings[from_name]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('from_name')); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-vial"></i> اختبار الإرسال</h3>
                        <p>اختبر إعدادات البريد الإلكتروني بإرسال بريد تجريبي</p>
                        
                        <div class="test-email-form">
                            <input type="email" id="testEmail" class="form-control" placeholder="أدخل بريدك الإلكتروني">
                            <button type="button" class="btn btn-primary" onclick="testEmail()">
                                <i class="fas fa-paper-plane"></i> إرسال تجريبي
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- تبويب الأمان -->
                <div id="security-tab" class="tab-content">
                    <div class="settings-group">
                        <h3><i class="fas fa-lock"></i> أمان كلمات المرور</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">الحد الأدنى لطول كلمة المرور</label>
                                <input type="number" name="settings[password_min_length]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('password_min_length', 6)); ?>" min="4" max="20">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">متطلبات قوة كلمة المرور</label>
                                <div class="checkbox-container">
                                    <input type="checkbox" name="settings[password_require_numbers]" value="1" 
                                           <?php echo getSetting('password_require_numbers') == '1' ? 'checked' : ''; ?>>
                                    <label>طلب أرقام في كلمة المرور</label>
                                </div>
                                
                                <div class="checkbox-container">
                                    <input type="checkbox" name="settings[password_require_uppercase]" value="1" 
                                           <?php echo getSetting('password_require_uppercase') == '1' ? 'checked' : ''; ?>>
                                    <label>طلب أحرف كبيرة في كلمة المرور</label>
                                </div>
                                
                                <div class="checkbox-container">
                                    <input type="checkbox" name="settings[password_require_special]" value="1" 
                                           <?php echo getSetting('password_require_special') == '1' ? 'checked' : ''; ?>>
                                    <label>طلب رموز خاصة في كلمة المرور</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-user-shield"></i> أمان الجلسات</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">عدد محاولات تسجيل الدخول المسموحة</label>
                                <input type="number" name="settings[login_attempts]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('login_attempts', 5)); ?>" min="1" max="10">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">مدة انتهاء الجلسة (بالدقائق)</label>
                                <input type="number" name="settings[session_timeout]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('session_timeout', 30)); ?>" min="5" max="1440">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">تفعيل المصادقة الثنائية</label>
                                <div class="checkbox-container">
                                    <input type="checkbox" name="settings[require_2fa]" value="1" 
                                           <?php echo getSetting('require_2fa') == '1' ? 'checked' : ''; ?>>
                                    <label>تطلب المصادقة الثنائية للمسؤولين</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- تبويب المستندات -->
                <div id="document-tab" class="tab-content">
                    <div class="settings-group">
                        <h3><i class="fas fa-file-upload"></i> إعدادات رفع الملفات</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">الحد الأقصى لحجم الملف (ميجابايت)</label>
                                <?php 
                                $max_file_size = getSetting('max_file_size', 10485760);
                                $max_file_size_mb = floor($max_file_size / 1024 / 1024);
                                ?>
                                <input type="number" name="settings[max_file_size]" class="form-control" 
                                       value="<?php echo $max_file_size_mb; ?>" min="1" max="100">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">صيغ الملفات المسموحة</label>
                                <input type="text" name="settings[allowed_file_types]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('allowed_file_types')); ?>">
                                <small class="setting-description">أدخل الصيغ مفصولة بفاصلة: pdf,doc,docx,jpg,png</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-cog"></i> إعدادات تدفق العمل</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">الأولية الافتراضية للمستندات</label>
                                <select name="settings[default_priority]" class="form-control">
                                    <?php
                                    $priorities = ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', 'urgent' => 'عاجلة'];
                                    $current_priority = getSetting('default_priority', 'medium');
                                    foreach ($priorities as $key => $label) {
                                        $selected = ($key == $current_priority) ? 'selected' : '';
                                        echo "<option value='$key' $selected>$label</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">الأيام التلقائية للأرشفة</label>
                                <input type="number" name="settings[auto_archive_days]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('auto_archive_days', 30)); ?>" min="1" max="365">
                                <small class="setting-description">عدد الأيام بعدها يتم أرشفة المستندات المكتملة تلقائياً</small>
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">تفعيل الميزات المتقدمة</label>
                                <div class="checkbox-container">
                                    <input type="checkbox" name="settings[enable_comments]" value="1" 
                                           <?php echo getSetting('enable_comments', '1') == '1' ? 'checked' : ''; ?>>
                                    <label>تفعيل نظام التعليقات على المستندات</label>
                                </div>
                                
                                <div class="checkbox-container">
                                    <input type="checkbox" name="settings[enable_reminders]" value="1" 
                                           <?php echo getSetting('enable_reminders') == '1' ? 'checked' : ''; ?>>
                                    <label>تفعيل التذكيرات التلقائية</label>
                                </div>
                                
                                <div class="checkbox-container">
                                    <input type="checkbox" name="settings[enable_versioning]" value="1" 
                                           <?php echo getSetting('enable_versioning') == '1' ? 'checked' : ''; ?>>
                                    <label>تفعيل إصدارات المستندات</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- تبويب المظهر -->
                <div id="appearance-tab" class="tab-content">
                    <div class="settings-group">
                        <h3><i class="fas fa-palette"></i> الألوان والمظهر</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">لون السمة الرئيسي</label>
                                <input type="color" name="settings[theme_color]" class="form-control" style="height: 40px; padding: 0;" 
                                       value="<?php echo htmlspecialchars(getSetting('theme_color', '#3498db')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">لون الخلفية</label>
                                <input type="color" name="settings[background_color]" class="form-control" style="height: 40px; padding: 0;" 
                                       value="<?php echo htmlspecialchars(getSetting('background_color', '#f5f7fa')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">رابط الشعار</label>
                                <input type="text" name="settings[logo_url]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('logo_url')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">رابط الأيقونة (Favicon)</label>
                                <input type="text" name="settings[favicon_url]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('favicon_url')); ?>">
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">اتجاه العرض</label>
                                <select name="settings[rtl_enabled]" class="form-control">
                                    <option value="1" <?php echo getSetting('rtl_enabled', '1') == '1' ? 'selected' : ''; ?>>اليمين لليسار (RTL)</option>
                                    <option value="0" <?php echo getSetting('rtl_enabled') == '0' ? 'selected' : ''; ?>>اليسار لليمين (LTR)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-font"></i> الخطوط</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">خط العنوان</label>
                                <select name="settings[heading_font]" class="form-control">
                                    <option value="Cairo" <?php echo getSetting('heading_font', 'Cairo') == 'Cairo' ? 'selected' : ''; ?>>Cairo</option>
                                    <option value="Tajawal" <?php echo getSetting('heading_font') == 'Tajawal' ? 'selected' : ''; ?>>Tajawal</option>
                                    <option value="Arial" <?php echo getSetting('heading_font') == 'Arial' ? 'selected' : ''; ?>>Arial</option>
                                </select>
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">خط المحتوى</label>
                                <select name="settings[body_font]" class="form-control">
                                    <option value="Cairo" <?php echo getSetting('body_font', 'Cairo') == 'Cairo' ? 'selected' : ''; ?>>Cairo</option>
                                    <option value="Tajawal" <?php echo getSetting('body_font') == 'Tajawal' ? 'selected' : ''; ?>>Tajawal</option>
                                    <option value="Arial" <?php echo getSetting('body_font') == 'Arial' ? 'selected' : ''; ?>>Arial</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- تبويب الصيانة -->
                <div id="maintenance-tab" class="tab-content">
                    <div class="info-card">
                        <h4><i class="fas fa-exclamation-triangle"></i> تحذير</h4>
                        <p>هذه الإعدادات حساسة وقد تؤثر على أداء النظام. يرجى الحذر عند التعديل.</p>
                    </div>
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-tools"></i> أدوات الصيانة</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">تفعيل وضع الصيانة</label>
                                <div class="checkbox-container">
                                    <input type="checkbox" name="settings[maintenance_mode]" value="1" 
                                           <?php echo getSetting('maintenance_mode') == '1' ? 'checked' : ''; ?>>
                                    <label>إيقاف النظام للصيانة (للمستخدمين العاديين فقط)</label>
                                </div>
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">رسالة وضع الصيانة</label>
                                <textarea name="settings[maintenance_message]" class="form-control" rows="3"><?php echo htmlspecialchars(getSetting('maintenance_message')); ?></textarea>
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">تفعيل تسجيل الأخطاء</label>
                                <div class="checkbox-container">
                                    <input type="checkbox" name="settings[error_logging]" value="1" 
                                           <?php echo getSetting('error_logging', '1') == '1' ? 'checked' : ''; ?>>
                                    <label>تسجيل أخطاء النظام في ملفات السجلات</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-database"></i> إدارة قاعدة البيانات</h3>
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">تفعيل النسخ الاحتياطي التلقائي</label>
                                <div class="checkbox-container">
                                    <input type="checkbox" name="settings[auto_backup]" value="1" 
                                           <?php echo getSetting('auto_backup') == '1' ? 'checked' : ''; ?>>
                                    <label>إنشاء نسخة احتياطية تلقائية يومياً</label>
                                </div>
                            </div>
                            
                            <div class="setting-item">
                                <label class="setting-label">فترة احتفاظ السجلات (أيام)</label>
                                <input type="number" name="settings[log_retention_days]" class="form-control" 
                                       value="<?php echo htmlspecialchars(getSetting('log_retention_days', 90)); ?>" min="1" max="365">
                                <small class="setting-description">عدد الأيام التي تحتفظ فيها بسجلات النظام قبل حذفها</small>
                            </div>
                            
                            <div class="setting-item">
                                <button type="button" class="btn btn-primary" onclick="optimizeDatabase()">
                                    <i class="fas fa-magic"></i> تحسين قاعدة البيانات
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- شريط الإجراءات -->
                <div class="actions-bar">
                    <button type="submit" class="btn btn-primary" style="padding: 12px 30px;">
                        <i class="fas fa-save"></i> حفظ جميع الإعدادات
                    </button>
                    <span id="lastSaved" style="color: #6c757d;">
                        آخر حفظ: <?php echo date('Y-m-d H:i:s'); ?>
                    </span>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // دالة لعرض التبويب المحدد
        function showTab(tabId) {
            // إخفاء جميع التبويبات
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // إزالة النشاط من جميع الأزرار
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // إظهار التبويب المحدد
            document.getElementById(tabId + '-tab').classList.add('active');
            
            // إضافة النشاط للزر المحدد
            event.target.classList.add('active');
        }
        
        // دالة لعرض رسالة
        function showMessage(message, type = 'success') {
            const alert = document.getElementById('messageAlert');
            alert.textContent = message;
            alert.className = 'alert alert-' + (type === 'success' ? 'success' : 'error');
            alert.style.display = 'block';
            
            setTimeout(() => {
                alert.style.display = 'none';
            }, 5000);
        }
        
        // حفظ الإعدادات
        document.getElementById('settingsForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'save_settings');
            
            fetch('system_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                    document.getElementById('lastSaved').textContent = 'آخر حفظ: ' + new Date().toLocaleString('ar-SA');
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('حدث خطأ في الاتصال بالخادم', 'error');
            });
        });
        
        // إعادة تعيين الإعدادات إلى الافتراضية
        function resetToDefault() {
            if (!confirm('هل أنت متأكد من إعادة تعيين جميع الإعدادات إلى الافتراضية؟\n\nهذا الإجراء لا يمكن التراجع عنه.')) return;
            
            const formData = new FormData();
            formData.append('action', 'reset_to_default');
            
            fetch('system_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('حدث خطأ في الاتصال بالخادم', 'error');
            });
        }
        
        // مسح الكاش
        function clearCache() {
            if (!confirm('هل تريد مسح ذاكرة التخزين المؤقت للنظام؟')) return;
            
            const formData = new FormData();
            formData.append('action', 'clear_cache');
            
            fetch('system_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('حدث خطأ في الاتصال بالخادم', 'error');
            });
        }
        
        // اختبار البريد الإلكتروني
        function testEmail() {
            const email = document.getElementById('testEmail').value;
            
            if (!email || !email.includes('@')) {
                showMessage('الرجاء إدخال بريد إلكتروني صالح', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'test_email');
            formData.append('test_email', email);
            
            fetch('system_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('حدث خطأ في الاتصال بالخادم', 'error');
            });
        }
        
        // تحسين قاعدة البيانات
        function optimizeDatabase() {
            if (!confirm('هل تريد تحسين قاعدة البيانات؟\n\nهذا الإجراء قد يستغرق بضع دقائق.')) return;
            
            showMessage('جاري تحسين قاعدة البيانات...', 'success');
            
            const formData = new FormData();
            formData.append('action', 'optimize_database');
            
            fetch('system_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('حدث خطأ في الاتصال بالخادم', 'error');
            });
        }
        
        // رسالة ترحيب
        console.log('%cإعدادات النظام', 'color: #3498db; font-size: 16px; font-weight: bold;');
        console.log('عدد الإعدادات: <?php echo count($settings_map); ?>');
    </script>
</body>
</html>