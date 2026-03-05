<?php
/**
 * معالجة إجراءات الموظف
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_id = intval($_POST['document_id']);
    $action = $_POST['action'];
    
    try {
        $db->beginTransaction();
        
        if ($action === 'return_to_manager') {
            $supervisor_id = intval($_POST['supervisor_id']);
            $note = $_POST['note'] ?? '';
            
            // التحقق من أن الموظف هو حامل المستند
            $doc_query = $db->prepare("
                SELECT current_holder_id, current_status 
                FROM documents 
                WHERE id = :doc_id
            ");
            $doc_query->execute([':doc_id' => $document_id]);
            $document = $doc_query->fetch();
            
            if (!$document || $document['current_holder_id'] != $user_id) {
                throw new Exception("ليس لديك صلاحية لهذا الإجراء");
            }
            
            // تحديث حالة الموظف إلى 'pending'
            $update_employee = $db->prepare("
                UPDATE document_user_status 
                SET status = 'pending',
                    action_required = NULL,
                    updated_at = NOW()
                WHERE document_id = :doc_id AND user_id = :user_id
            ");
            $update_employee->execute([
                ':doc_id' => $document_id,
                ':user_id' => $user_id
            ]);
            
            // تحديث حالة رئيس القسم إلى 'completion_required'
            $update_supervisor = $db->prepare("
                UPDATE document_user_status 
                SET status = 'completion_required',
                    action_required = 'review',
                    updated_at = NOW()
                WHERE document_id = :doc_id AND user_id = :supervisor_id
            ");
            $update_supervisor->execute([
                ':doc_id' => $document_id,
                ':supervisor_id' => $supervisor_id
            ]);
            
            // تحديث حامل المستند
            $update_doc = $db->prepare("
                UPDATE documents 
                SET current_holder_id = :supervisor_id,
                    current_status = 'completion_required',
                    updated_at = NOW()
                WHERE id = :doc_id
            ");
            $update_doc->execute([
                ':doc_id' => $document_id,
                ':supervisor_id' => $supervisor_id
            ]);
            
            // إضافة سجل في workflow
            $workflow = $db->prepare("
                INSERT INTO document_workflow 
                (document_id, from_user_id, to_user_id, action_type, 
                 notes, status_before, status_after, is_current_step, action_date)
                VALUES 
                (:doc_id, :from_user, :to_user, 'return', 
                 :notes, :old_status, 'completion_required', 1, NOW())
            ");
            $workflow->execute([
                ':doc_id' => $document_id,
                ':from_user' => $user_id,
                ':to_user' => $supervisor_id,
                ':notes' => $note,
                ':old_status' => $document['current_status']
            ]);
            
            // إضافة إشعار لرئيس القسم
            $notification = $db->prepare("
                INSERT INTO notifications 
                (user_id, title, message, link, created_at)
                VALUES 
                (:supervisor_id, 'مستند جديد', :message, :link, NOW())
            ");
            $notification->execute([
                ':supervisor_id' => $supervisor_id,
                ':message' => 'قام الموظف بإرجاع مستند إليك للمراجعة',
                ':link' => '../documents/view_document.php?id=' . $document_id
            ]);
            
            $message = "تم إرجاع المستند لرئيس القسم بنجاح";
        }
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => $message]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'طريقة غير صالحة']);
}
?>