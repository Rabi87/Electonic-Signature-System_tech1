<?php
// ملف لتعديل إنشاء الإشعارات في النظام
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

$db = getDB();

// دالة لإنشاء إشعارات متوافقة مع النظام الجديد
function createNotification($user_id, $title, $message, $document_id, $type = 'document') {
    global $db;
    
    $link = "../documents/view_document.php?id=" . $document_id;
    
    $stmt = $db->prepare("
        INSERT INTO notifications (user_id, title, message, link, created_at)
        VALUES (:user_id, :title, :message, :link, NOW())
    ");
    
    return $stmt->execute([
        ':user_id' => $user_id,
        ':title' => $title,
        ':message' => $message,
        ':link' => $link
    ]);
}

// إعادة بناء الإشعارات للعلاقات الصحيحة
function rebuildNotificationsForDocument($document_id) {
    global $db;
    
    // حذف جميع الإشعارات القديمة لهذا المستند
    $delete_stmt = $db->prepare("
        DELETE FROM notifications 
        WHERE link LIKE :link_pattern
    ");
    $delete_stmt->execute([':link_pattern' => "%view_document.php?id=" . $document_id . "%"]);
    
    // جلب معلومات المستند
    $doc_stmt = $db->prepare("
        SELECT d.*, u.full_name as creator_name
        FROM documents d
        JOIN users u ON d.created_by = u.id
        WHERE d.id = :doc_id
    ");
    $doc_stmt->execute([':doc_id' => $document_id]);
    $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) return;
    
    // جلب سير العمل
    $workflow_stmt = $db->prepare("
        SELECT dw.*, u.full_name as sender_name, u2.full_name as receiver_name
        FROM document_workflow dw
        JOIN users u ON dw.from_user_id = u.id
        JOIN users u2 ON dw.to_user_id = u2.id
        WHERE dw.document_id = :doc_id
        ORDER BY dw.action_date DESC
    ");
    $workflow_stmt->execute([':doc_id' => $document_id]);
    $workflow_steps = $workflow_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إنشاء إشعار لكل خطوة في سير العمل ذات معنى
    foreach ($workflow_steps as $step) {
        $title = "";
        $message = "";
        
        switch ($step['action_type']) {
            case 'submit':
                $title = "مستند جديد بانتظارك";
                $message = "قام {$step['sender_name']} بإرسال مستند إليك: {$document['title']}";
                createNotification($step['to_user_id'], $title, $message, $document_id);
                break;
                
            case 'forward':
                $title = "مستند مُحال إليك";
                $message = "قام {$step['sender_name']} بإحالة مستند إليك: {$document['title']}";
                createNotification($step['to_user_id'], $title, $message, $document_id);
                break;
                
            case 'approve':
                $title = "تمت الموافقة على المستند";
                $message = "قام {$step['sender_name']} بالموافقة على المستند: {$document['title']}";
                // إرسال للمنشئ
                createNotification($document['created_by'], $title, $message, $document_id);
                break;
                
            case 'reject':
                $title = "تم رفض المستند";
                $message = "قام {$step['sender_name']} برفض المستند: {$document['title']}";
                // إرسال للمنشئ
                createNotification($document['created_by'], $title, $message, $document_id);
                break;
        }
    }
}

// دالة لتحديث حالة الإشعار كمقروء عند النقر عليه
function markNotificationAsRead($notification_id, $user_id) {
    global $db;
    
    $stmt = $db->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE id = :id AND user_id = :user_id
    ");
    
    return $stmt->execute([
        ':id' => $notification_id,
        ':user_id' => $user_id
    ]);
}

// دالة لإنشاء إشعار تذكير من صفحة التتبع
function createReminderNotification($document_id, $reminder_user_id, $target_user_id) {
    global $db;
    
    // جلب اسم من أرسل التذكير
    $sender_stmt = $db->prepare("SELECT full_name FROM users WHERE id = :user_id");
    $sender_stmt->execute([':user_id' => $reminder_user_id]);
    $sender = $sender_stmt->fetch(PDO::FETCH_ASSOC);
    
    // جلب معلومات المستند
    $doc_stmt = $db->prepare("SELECT title FROM documents WHERE id = :doc_id");
    $doc_stmt->execute([':doc_id' => $document_id]);
    $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sender && $document) {
        $title = "تذكير بالمستند";
        $message = "يريد {$sender['full_name']} منك تفقد المستند: {$document['title']}";
        return createNotification($target_user_id, $title, $message, $document_id, 'reminder');
    }
    
    return false;
}

// API endpoint لمعالجة النقر على الإشعارات
if (isset($_GET['action']) && $_GET['action'] == 'mark_read') {
    if (isset($_GET['notification_id'])) {
        $notification_id = $_GET['notification_id'];
        $user_id = $_SESSION['user_id'];
        
        if (markNotificationAsRead($notification_id, $user_id)) {
            // جلب رابط الإشعار للانتقال إليه
            $link_stmt = $db->prepare("SELECT link FROM notifications WHERE id = :id");
            $link_stmt->execute([':id' => $notification_id]);
            $notification = $link_stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'redirect_url' => $notification['link'] ?? '../documents/view_document.php'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'فشل تحديث حالة الإشعار']);
        }
    }
    exit();
}

// API endpoint لإنشاء إشعار تذكير
if (isset($_GET['action']) && $_GET['action'] == 'create_reminder') {
    if (isset($_POST['document_id']) && isset($_POST['target_user_id'])) {
        $document_id = $_POST['document_id'];
        $target_user_id = $_POST['target_user_id'];
        $reminder_user_id = $_SESSION['user_id'];
        
        if (createReminderNotification($document_id, $reminder_user_id, $target_user_id)) {
            echo json_encode(['success' => true, 'message' => 'تم إرسال التذكير بنجاح']);
        } else {
            echo json_encode(['success' => false, 'message' => 'فشل إرسال التذكير']);
        }
    }
    exit();
}
?>