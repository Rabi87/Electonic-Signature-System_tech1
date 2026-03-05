<?php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من الصلاحيات
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$db = getDB();

// جلب جميع المستخدمين مع علاقاتهم
$users = $db->query("
    SELECT u.id, u.full_name, u.username, u.supervisor_id, 
           r.role_name, d.name as department_name
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    LEFT JOIN departments d ON u.department_id = d.id 
    WHERE u.is_active = 1 
    ORDER BY 
        CASE r.role_name 
            WHEN 'admin' THEN 1
            WHEN 'ceo' THEN 2
            WHEN 'department_manager' THEN 3
            WHEN 'section_manager' THEN 4
            ELSE 5
        END,
        u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

// بناء الشجرة الهيكلية
function buildOrgChart($users, $supervisor_id = null) {
    $html = '<ul class="org-chart">';
    
    foreach ($users as $user) {
        if ($user['supervisor_id'] == $supervisor_id) {
            $html .= '<li>';
            $html .= '<div class="employee-card">';
            $html .= '<h4>' . htmlspecialchars($user['full_name']) . '</h4>';
            $html .= '<p>' . htmlspecialchars($user['role_name']) . '</p>';
            if ($user['department_name']) {
                $html .= '<p><small>' . htmlspecialchars($user['department_name']) . '</small></p>';
            }
            $html .= '</div>';
            
            // البحث عن المرؤوسين
            $subordinates = array_filter($users, function($u) use ($user) {
                return $u['supervisor_id'] == $user['id'];
            });
            
            if (count($subordinates) > 0) {
                $html .= buildOrgChart($users, $user['id']);
            }
            
            $html .= '</li>';
        }
    }
    
    $html .= '</ul>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>هيكل المؤسسة</title>
    <style>
        .org-chart {
            list-style: none;
            padding: 0;
        }
        
        .org-chart ul {
            margin-right: 40px;
            border-right: 2px solid #3498db;
        }
        
        .org-chart li {
            margin: 20px 0;
            position: relative;
        }
        
        .employee-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            width: 200px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .admin { border-top: 4px solid #e74c3c; }
        .ceo { border-top: 4px solid #3498db; }
        .department_manager { border-top: 4px solid #2ecc71; }
        .section_manager { border-top: 4px solid #f39c12; }
        .employee { border-top: 4px solid #95a5a6; }
    </style>
</head>
<body>
    <h1>الهيكل التنظيمي للمؤسسة</h1>
    <?php echo buildOrgChart($users); ?>
</body>
</html>