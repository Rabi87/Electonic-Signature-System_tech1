<?php
session_start();
require_once '../includes/database.php';

// التحقق من أن المستخدم مسجل دخول وله دور board
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'board') {
    echo json_encode(['success' => false, 'message' => 'غير مسموح الوصول']);
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

try {
    // جلب المستندات المؤرشفة التي تخص الديوان
    $query = "SELECT d.*, u.full_name as creator_name 
              FROM documents d
              LEFT JOIN users u ON d.created_by = u.id
              WHERE d.archived = 1 
              AND (d.created_by = :user_id OR d.current_holder_id = :user_id2)
              ORDER BY d.archived_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':user_id' => $user_id,
        ':user_id2' => $user_id
    ]);
    
    $archived_docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تجميع المستندات حسب الأهمية
    $folders = [
        'سري' => [],
        'عاجل' => [],
        'عادي' => []
    ];
    
    foreach ($archived_docs as $doc) {
        // تحويل الأولوية من الإنجليزية إلى العربية
        $importance = 'عادي';
        if ($doc['priority'] === 'urgent') {
            $importance = 'سري';
        } elseif ($doc['priority'] === 'high') {
            $importance = 'عاجل';
        }
        
        $folders[$importance][] = [
            'id' => $doc['id'],
            'title' => $doc['title'],
            'created_at' => date('Y-m-d', strtotime($doc['created_at'])),
            'archived_at' => $doc['archived_at'],
            'creator_name' => $doc['creator_name']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'folders' => $folders
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
    ]);
}
?>