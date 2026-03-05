<?php
/**
 * معالجة إجراءات المستندات (موافقة، رفض، إرسال)
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
$full_name = $_SESSION['full_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_id = intval($_POST['document_id']);
    $action = $_POST['action'];
    
    try {
        $db->beginTransaction();
        
        // جلب معلومات المستند
        $doc_query = $db->prepare("
            SELECT d.*, u.full_name as creator_name
            FROM documents d
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.id = :doc_id
        ");
        $doc_query->execute([':doc_id' => $document_id]);
        $document = $doc_query->fetch();
        
        if (!$document) {
            throw new Exception("المستند غير موجود");
        }
        
        // التحقق من أن المستخدم الحالي هو حامل المستند
        if ($document['current_holder_id'] != $user_id) {
            throw new Exception("ليس لديك صلاحية لهذا الإجراء");
        }
        
        switch ($action) {
            case 'approve':
                // تحديث حالة المستخدم الحالي إلى 'completed'
                $update_current = $db->prepare("
                    UPDATE document_user_status 
                    SET status = 'completed',
                        action_required = NULL,
                        updated_at = NOW()
                    WHERE document_id = :doc_id AND user_id = :user_id
                ");
                $update_current->execute([
                    ':doc_id' => $document_id,
                    ':user_id' => $user_id
                ]);
                
                // تحديث حالة منشئ المستند إلى 'completed'
                $update_creator = $db->prepare("
                    UPDATE document_user_status 
                    SET status = 'completed',
                        action_required = NULL,
                        updated_at = NOW()
                    WHERE document_id = :doc_id AND user_id = :creator_id
                ");
                $update_creator->execute([
                    ':doc_id' => $document_id,
                    ':creator_id' => $document['created_by']
                ]);
                
                // تحديث حالة المستند العامة
                $update_doc = $db->prepare("
                    UPDATE documents 
                    SET current_status = 'completed',
                        updated_at = NOW()
                    WHERE id = :doc_id
                ");
                $update_doc->execute([':doc_id' => $document_id]);
                
                $message = "تمت الموافقة على المستند بنجاح";
                break;
                
            case 'reject':
                $reason = $_POST['reason'] ?? '';
                
                // تحديث حالة المستخدم الحالي إلى 'completed' مع الرفض
                $update_current = $db->prepare("
                    UPDATE document_user_status 
                    SET status = 'rejected',
                        action_required = NULL,
                        notes = :reason,
                        updated_at = NOW()
                    WHERE document_id = :doc_id AND user_id = :user_id
                ");
                $update_current->execute([
                    ':doc_id' => $document_id,
                    ':user_id' => $user_id,
                    ':reason' => $reason
                ]);
                
                // تحديث حالة منشئ المستند إلى 'rejected'
                $update_creator = $db->prepare("
                    UPDATE document_user_status 
                    SET status = 'rejected',
                        action_required = NULL,
                        updated_at = NOW()
                    WHERE document_id = :doc_id AND user_id = :creator_id
                ");
                $update_creator->execute([
                    ':doc_id' => $document_id,
                    ':creator_id' => $document['created_by']
                ]);
                
                // تحديث حالة المستند العامة
                $update_doc = $db->prepare("
                    UPDATE documents 
                    SET current_status = 'rejected',
                        updated_at = NOW()
                    WHERE id = :doc_id
                ");
                $update_doc->execute([':doc_id' => $document_id]);
                
                $message = "تم رفض المستند بنجاح";
                break;
                
            case 'forward':
                $target_user_id = intval($_POST['target_user_id']);
                $note = $_POST['note'] ?? '';
                
                // التحقق من وجود المستخدم الهدف
                $target_user_query = $db->prepare("SELECT full_name FROM users WHERE id = :id");
                $target_user_query->execute([':id' => $target_user_id]);
                $target_user = $target_user_query->fetch();
                
                if (!$target_user) {
                    throw new Exception("المستخدم الهدف غير موجود");
                }
                
                // تحديث حالة المستخدم الحالي إلى 'pending'
                $update_current = $db->prepare("
                    UPDATE document_user_status 
                    SET status = 'pending',
                        action_required = NULL,
                        updated_at = NOW()
                    WHERE document_id = :doc_id AND user_id = :user_id
                ");
                $update_current->execute([
                    ':doc_id' => $document_id,
                    ':user_id' => $user_id
                ]);
                
                // تحديث أو إضافة حالة المستخدم الهدف
                $check_target = $db->prepare("
                    SELECT id FROM document_user_status 
                    WHERE document_id = :doc_id AND user_id = :target_id
                ");
                $check_target->execute([
                    ':doc_id' => $document_id,
                    ':target_id' => $target_user_id
                ]);
                
                if ($check_target->fetch()) {
                    $update_target = $db->prepare("
                        UPDATE document_user_status 
                        SET status = 'completion_required',
                            action_required = 'review',
                            updated_at = NOW()
                        WHERE document_id = :doc_id AND user_id = :target_id
                    ");
                    $update_target->execute([
                        ':doc_id' => $document_id,
                        ':target_id' => $target_user_id
                    ]);
                } else {
                    $insert_target = $db->prepare("
                        INSERT INTO document_user_status 
                        (document_id, user_id, status, action_required, department_id, is_owner_department, updated_at)
                        VALUES 
                        (:doc_id, :target_id, 'completion_required', 'review', 
                         (SELECT department_id FROM users WHERE id = :target_id2), 0, NOW())
                    ");
                    $insert_target->execute([
                        ':doc_id' => $document_id,
                        ':target_id' => $target_user_id,
                        ':target_id2' => $target_user_id
                    ]);
                }
                
                // تحديث حامل المستند
                $update_doc = $db->prepare("
                    UPDATE documents 
                    SET current_holder_id = :target_id,
                        current_status = 'completion_required',
                        updated_at = NOW()
                    WHERE id = :doc_id
                ");
                $update_doc->execute([
                    ':doc_id' => $document_id,
                    ':target_id' => $target_user_id
                ]);
                
                // إضافة سجل في workflow
                $workflow = $db->prepare("
                    INSERT INTO document_workflow 
                    (document_id, from_user_id, to_user_id, action_type, 
                     notes, status_before, status_after, is_current_step, action_date)
                    VALUES 
                    (:doc_id, :from_user, :to_user, 'forward', 
                     :notes, :old_status, 'completion_required', 1, NOW())
                ");
                $workflow->execute([
                    ':doc_id' => $document_id,
                    ':from_user' => $user_id,
                    ':to_user' => $target_user_id,
                    ':notes' => $note,
                    ':old_status' => $document['current_status']
                ]);
                
                // إضافة إشعار للمستخدم الهدف
                $notification = $db->prepare("
                    INSERT INTO notifications 
                    (user_id, title, message, link, created_at)
                    VALUES 
                    (:target_id, 'مستند جديد', :message, :link, NOW())
                ");
                $notification->execute([
                    ':target_id' => $target_user_id,
                    ':message' => 'تم إرسال مستند إليك من ' . $full_name . ' للمراجعة',
                    ':link' => '../documents/view_document.php?id=' . $document_id
                ]);
                
                $message = "تم إرسال المستند بنجاح";
                break;
                
            default:
                throw new Exception("الإجراء غير معروف");
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