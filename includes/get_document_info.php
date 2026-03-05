<?php
/**
 * جلب معلومات المستند
 */
require_once 'session.php';
checkLogin();
require_once 'config.php';
require_once 'database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'رقم المستند مطلوب']);
    exit();
}

$document_id = intval($_GET['id']);
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

try {
    $db = getDB();
    
    // التحقق من صلاحية الوصول للمستند
    $query = $db->prepare("
        SELECT id, title, description, current_status, priority, 
               created_at, created_by, current_holder_id
        FROM documents 
        WHERE id = :doc_id 
        AND (created_by = :user_id OR current_holder_id = :user_id2)
        LIMIT 1
    ");
    
    $query->execute([
        ':doc_id' => $document_id,
        ':user_id' => $user_id,
        ':user_id2' => $user_id
    ]);
    
    $document = $query->fetch(PDO::FETCH_ASSOC);
    
    if ($document) {
        echo json_encode([
            'success' => true,
            'document' => $document
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'لا تملك صلاحية الوصول لهذا المستند'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ: ' . $e->getMessage()
    ]);
}
?>