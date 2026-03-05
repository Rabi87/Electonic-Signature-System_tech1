<?php
// ملف لاستخراج معرف المستخدم من الاسم
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

$db = getDB();

if (isset($_GET['name'])) {
    $user_name = trim($_GET['name']);
    
    // البحث عن المستخدم بالاسم
    $stmt = $db->prepare("
        SELECT id, full_name 
        FROM users 
        WHERE full_name LIKE :name 
        OR full_name LIKE CONCAT('%', :name2, '%')
        LIMIT 1
    ");
    
    $stmt->execute([
        ':name' => $user_name,
        ':name2' => $user_name
    ]);
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode([
            'success' => true,
            'user_id' => $user['id'],
            'full_name' => $user['full_name']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'المستخدم غير موجود']);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'لم يتم توفير اسم المستخدم']);
?>