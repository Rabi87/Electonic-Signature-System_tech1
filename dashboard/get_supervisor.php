<?php
/**
 * جلب رئيس القسم للموظف
 */
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

try {
    // جلب رئيس القسم
    $supervisor_query = $db->prepare("
        SELECT supervisor_id 
        FROM users 
        WHERE id = :user_id
    ");
    $supervisor_query->execute([':user_id' => $user_id]);
    $supervisor_id = $supervisor_query->fetchColumn();
    
    if ($supervisor_id) {
        echo json_encode(['success' => true, 'supervisor_id' => $supervisor_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'لا يوجد رئيس قسم']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>