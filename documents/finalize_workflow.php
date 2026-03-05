<?php
/**
 * إكمال إرسال المستند بعد المعاينة
 */
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_id = intval($_POST['document_id']);
    
    // التحقق من وجود بيانات المعاينة في الجلسة
    if (!isset($_SESSION['preview_workflow'][$document_id])) {
        echo json_encode(['success' => false, 'message' => 'لا توجد بيانات معاينة لهذا المستند']);
        exit();
    }
    
    $preview_data = $_SESSION['preview_workflow'][$document_id];
    
    try {
        $db->beginTransaction();
        
        // === تحديث حالات المستخدمين ===
        $user_status_query = $db->prepare("
            SELECT status FROM document_user_status 
            WHERE document_id = :doc_id AND user_id = :user_id
        ");
        $user_status_query->execute([':doc_id' => $document_id, ':user_id' => $user_id]);
        $current_user_status = $user_status_query->fetchColumn();
        
        // === إذا كانت حالة المستخدم الحالي هي 'completion_required' أو 'partially_signed' ===
        if ($current_user_status === 'completion_required' || $current_user_status === 'partially_signed') {
            // تحديث حالة المستخدم الحالي إلى 'pending'
            $update_current_user = $db->prepare("
                UPDATE document_user_status 
                SET status = 'pending', 
                    action_required = NULL,
                    updated_at = NOW()
                WHERE document_id = :doc_id AND user_id = :user_id
            ");
            $update_current_user->execute([
                ':doc_id' => $document_id,
                ':user_id' => $user_id
            ]);
            
            // تحديث أو إضافة حالة المستخدم المستهدف
            $check_target_user = $db->prepare("
                SELECT id FROM document_user_status 
                WHERE document_id = :doc_id AND user_id = :target_id
            ");
            $check_target_user->execute([
                ':doc_id' => $document_id,
                ':target_id' => $preview_data['assigned_to']
            ]);
            
            if ($check_target_user->fetch()) {
                $update_target_user = $db->prepare("
                    UPDATE document_user_status 
                    SET status = 'completion_required',
                        action_required = 'complete',
                        updated_at = NOW()
                    WHERE document_id = :doc_id AND user_id = :target_id
                ");
                $update_target_user->execute([
                    ':doc_id' => $document_id,
                    ':target_id' => $preview_data['assigned_to']
                ]);
            } else {
                $insert_target_user = $db->prepare("
                    INSERT INTO document_user_status 
                    (document_id, user_id, status, action_required, department_id, is_owner_department, updated_at)
                    VALUES 
                    (:doc_id, :target_id, 'completion_required', 'complete', 
                     (SELECT department_id FROM users WHERE id = :target_id2), 1, NOW())
                ");
                $insert_target_user->execute([
                    ':doc_id' => $document_id,
                    ':target_id' => $preview_data['assigned_to'],
                    ':target_id2' => $preview_data['assigned_to']
                ]);
            }
            
            // تحديث حالة المستند العامة
            if ($current_user_status === 'completion_required') {
                $update_doc_status = $db->prepare("
                    UPDATE documents 
                    SET current_status = 'completion_required',
                        updated_at = NOW()
                    WHERE id = :doc_id
                ");
                $update_doc_status->execute([':doc_id' => $document_id]);
            }
        }
        
        // === تحديث خطوات سير العمل ===
        $update_old_steps = $db->prepare("
            UPDATE document_workflow 
            SET is_current_step = 0 
            WHERE document_id = :doc_id
        ");
        $update_old_steps->execute([':doc_id' => $document_id]);
        
        // إضافة خطوة سير عمل جديدة
        $step_notes = "تم توجيه المستند للتوقيع";
        if (!empty($preview_data['creator_note'])) {
            $step_notes = "ملاحظة من " . $_SESSION['full_name'] . ": " . substr($preview_data['creator_note'], 0, 200);
        }
        
        $workflow_step = $db->prepare("
            INSERT INTO document_workflow 
            (document_id, from_user_id, to_user_id, action_type, 
             notes, status_before, status_after, is_current_step, action_date) 
            VALUES 
            (:doc_id, :from_user, :to_user, 'review', 
             :notes, :current_status, 'pending', 1, NOW())
        ");
        
        // جلب الحالة الحالية للمستند
        $doc_query = $db->prepare("SELECT current_status FROM documents WHERE id = :doc_id");
        $doc_query->execute([':doc_id' => $document_id]);
        $current_status = $doc_query->fetchColumn();
        
        $workflow_step->execute([
            ':doc_id' => $document_id,
            ':from_user' => $user_id,
            ':to_user' => $preview_data['assigned_to'],
            ':notes' => $step_notes,
            ':current_status' => $current_status
        ]);
        
        // === تحديث حامل المستند ===
        $update_doc = $db->prepare("
            UPDATE documents 
            SET current_holder_id = :holder_id, 
                updated_at = NOW()
            WHERE id = :doc_id
        ");
        $update_doc->execute([
            ':doc_id' => $document_id,
            ':holder_id' => $preview_data['assigned_to']
        ]);
        
        // === إرسال إشعار للمستخدم الجديد ===
        $notification_message = "يوجد مستند جديد يتطلب إكمال الحقول من " . $_SESSION['full_name'];
        if ($preview_data['step_type'] === 'note') {
            $notification_message = "تم إضافة ملاحظة جديدة من " . $_SESSION['full_name'];
            if (!empty($preview_data['creator_note'])) {
                $notification_message .= ": " . substr($preview_data['creator_note'], 0, 100);
            }
        }
        
        $notification = $db->prepare("
            INSERT INTO notifications 
            (user_id, title, message, link, created_at)
            VALUES 
            (:to_user, 'مستند جديد', :message, :link, NOW())
        ");
        $notification->execute([
            ':to_user' => $preview_data['assigned_to'],
            ':message' => $notification_message,
            ':link' => '../documents/view_document.php?id=' . $document_id
        ]);
        
        // === حذف بيانات المعاينة من الجلسة ===
        unset($_SESSION['preview_workflow'][$document_id]);
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'تم إرسال المستند بنجاح'
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'طريقة طلب غير صالحة'
    ]);
}
?>