<?php
// تمكين عرض الأخطاء
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// تسجيل معلومات الجلسة للتصحيح
error_log("جلسة الأرشيف: " . print_r($_SESSION, true));

// التحقق من أن المستخدم مسجل دخول وله دور board
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'board') {
    $error = 'غير مسموح الوصول. الجلسة: ' . print_r($_SESSION, true);
    error_log($error);
    echo json_encode(['success' => false, 'message' => $error]);
    exit();
}

require_once '../includes/database.php';

$db = getDB();
$user_id = $_SESSION['user_id'];

// تسجيل بيانات POST للتصحيح
error_log("بيانات POST الواردة: " . print_r($_POST, true));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
    $importance = $_POST['importance'] ?? 'عادي';
    
    error_log("طلب أرشفة: document_id=$document_id, importance=$importance, user_id=$user_id");
    
    if ($document_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرف المستند غير صالح']);
        exit();
    }
    
    try {
        // التحقق من أن المستخدم لديه صلاحية لأرشفة هذا المستند
        $check_query = "SELECT id, title, current_status FROM documents 
                       WHERE id = :doc_id 
                       AND (created_by = :user_id OR current_holder_id = :user_id2)";
        
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([
            ':doc_id' => $document_id,
            ':user_id' => $user_id,
            ':user_id2' => $user_id
        ]);
        
        $document = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$document) {
            error_log("المستند غير موجود أو ليس للمستخدم. document_id: $document_id, user_id: $user_id");
            echo json_encode(['success' => false, 'message' => 'المستند غير موجود أو ليس لديك صلاحية لأرشفته']);
            exit();
        }
        
        error_log("المستند موجود: " . print_r($document, true));
        
        // تحديث حالة الأرشيف
        $update_query = "UPDATE documents SET archived = 1, archived_at = NOW() WHERE id = :doc_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->execute([':doc_id' => $document_id]);
        
        if ($update_stmt->rowCount() > 0) {
            error_log("تمت أرشفة المستند بنجاح. document_id: $document_id");
            echo json_encode(['success' => true, 'message' => 'تمت أرشفة المستند بنجاح']);
        } else {
            error_log("لم يتم تحديث أي سطر. document_id: $document_id");
            echo json_encode(['success' => false, 'message' => 'فشل في أرشفة المستند (لم يتم تحديث أي سجل)']);
        }
        
    } catch (PDOException $e) {
        error_log("خطأ في قاعدة البيانات: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()]);
    }
} else {
    error_log("طريقة غير صالحة للوصول. طريقة الطلب: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['success' => false, 'message' => 'طريقة غير صالحة']);
}
?>