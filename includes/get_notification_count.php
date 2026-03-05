<?php
// ملف: includes/get_notification_count.php
session_start();
require_once 'database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['unread_count' => 0]);
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

$unread_count_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = 0";
$unread_count_stmt = $db->prepare($unread_count_query);
$unread_count_stmt->execute([':user_id' => $user_id]);
$result = $unread_count_stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['unread_count' => $result['count'] ?? 0]);