<?php
session_start();
require_once 'config.php';
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// جلب عدد الإشعارات غير المقروءة
$stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode(['count' => $result['count'] ?? 0]);
?>