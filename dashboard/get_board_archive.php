<?php
/**
 * جلب المستندات المؤرشفة للديوان
 */

require_once '../includes/session.php';
require_once '../includes/config.php';
require_once '../includes/database.php';

checkLogin();

if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'board') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

$db = getDB();

// جلب المستندات المؤرشفة فقط (بدون تكرار)
$stmt = $db->prepare("
    SELECT 
        d.*,
        u.full_name as creator_name,
        u2.full_name as current_holder_name,
        DATE_FORMAT(d.archived_at, '%Y-%m-%d %H:%i') as archived_at
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN users u2 ON d.current_holder_id = u2.id
    WHERE d.archived = 1
    GROUP BY d.id
    ORDER BY d.archived_at DESC
");
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'documents' => $documents,
    'count' => count($documents)
]);
?>