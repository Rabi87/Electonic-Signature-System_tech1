<?php
// [file name]: includes/get_indicators.php
require_once 'session.php';
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_name'])) {
    echo json_encode(['success' => false, 'message' => 'غير مسموح']);
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$role_name = $_SESSION['role_name'];
$department_id = $_SESSION['department_id'] ?? null;

require_once 'dashboard_indicators.php';
$indicators = getDocumentIndicators($db, $user_id, $role_name, $department_id);

echo json_encode([
    'success' => true,
    'normal' => $indicators['normal']['count'],
    'urgent' => $indicators['urgent']['count'],
    'secret' => $indicators['secret']['count']
]);
?>