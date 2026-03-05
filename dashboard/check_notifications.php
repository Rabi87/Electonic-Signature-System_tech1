<?php
session_start();
require_once 'config.php';
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['unread_count' => 0, 'success' => false]);
    exit();
}

try {
    $db = getDB();
    $user_id = $_SESSION['user_id'];
    
    // جلب عدد الإشعارات غير المقروءة
    $stmt = $db->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'unread_count' => $result['unread_count'] ?? 0,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'unread_count' => 0, 'error' => $e->getMessage()]);
}
?>