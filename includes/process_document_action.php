<?php
/**
 * معالجة إجراءات الموافقة والرفض على المستندات
 */

require_once 'session.php';
checkLogin();
require_once 'config.php';
require_once 'database.php';

// التحقق من أن الطريقة POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صحيحة']);
    exit();
}

// التحقق من وجود البيانات المطلوبة
$document_id = $_POST['document_id'] ?? null;
$action = $_POST['action'] ?? null; // 'approve' أو 'reject'
$user_id = $_SESSION['user_id'] ?? null;
$role_name = $_SESSION['role_name'] ?? null;
$full_name = $_SESSION['full_name'] ?? '';

if (!$document_id || !$action || !$user_id || !$role_name) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'بيانات ناقصة']);
    exit();
}

// التحقق من صحة الإجراء
if (!in_array($action, ['approve', 'reject'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'إجراء غير صحيح']);
    exit();
}

$db = getDB();

try {
    $db->beginTransaction();
    
    // الحصول على معلومات المستند
    $doc_query = $db->prepare("SELECT * FROM documents WHERE id = ?");
    $doc_query->execute([$document_id]);
    $document = $doc_query->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        throw new Exception("المستند غير موجود");
    }
    
    // تحديد حالة المستخدم الجديدة بناءً على الإجراء
    $new_user_status = ($action === 'approve') ? 'approved' : 'rejected';
    
    // تحديث حالة المستخدم في document_user_status
    $status_query = $db->prepare("
        SELECT id FROM document_user_status 
        WHERE document_id = ? AND user_id = ?
    ");
    $status_query->execute([$document_id, $user_id]);
    
    if ($status_query->rowCount() > 0) {
        // تحديث السجل الموجود
        $update_status = $db->prepare("
            UPDATE document_user_status 
            SET status = ?, updated_at = NOW() 
            WHERE document_id = ? AND user_id = ?
        ");
        $update_status->execute([$new_user_status, $document_id, $user_id]);
    } else {
        // إنشاء سجل جديد
        $insert_status = $db->prepare("
            INSERT INTO document_user_status 
            (document_id, user_id, status, created_at, updated_at) 
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $insert_status->execute([$document_id, $user_id, $new_user_status]);
    }
    
    // تحديد الحالة العامة الجديدة بناءً على الدور
    $new_document_status = $document['current_status']; // الاحتفاظ بالحالة الحالية افتراضياً
    
    if ($action === 'approve') {
        // حالة الموافقة
        switch ($role_name) {
            case 'board':
            case 'department_manager':
                // الديوان ورئيس الدائرة: الحالة تبقى "مكتمل جزئياً"
                $new_document_status = 'partially_completed';
                break;
            case 'section_manager':
                // رئيس القسم: الحالة تصبح "مكتمل"
                $new_document_status = 'completed';
                
                // تحديث حالة جميع المستخدمين المعنيين بالمستند إلى "موافق"
                $all_users_query = $db->prepare("
                    SELECT DISTINCT user_id FROM document_user_status 
                    WHERE document_id = ? AND status != 'approved'
                ");
                $all_users_query->execute([$document_id]);
                $all_users = $all_users_query->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($all_users as $uid) {
                    if ($uid != $user_id) {
                        $update_all = $db->prepare("
                            UPDATE document_user_status 
                            SET status = 'approved', updated_at = NOW() 
                            WHERE document_id = ? AND user_id = ?
                        ");
                        $update_all->execute([$document_id, $uid]);
                    }
                }
                break;
        }
    } elseif ($action === 'reject') {
        // حالة الرفض
        switch ($role_name) {
            case 'board':
            case 'department_manager':
                // الديوان ورئيس الدائرة: الحالة تبقى "مكتمل جزئياً"
                $new_document_status = 'partially_completed';
                break;
            case 'section_manager':
                // رئيس القسم: الحالة تصبح "مرفوض"
                $new_document_status = 'rejected';
                
                // تحديث حالة جميع المستخدمين المعنيين بالمستند إلى "مرفوض"
                $all_users_query = $db->prepare("
                    SELECT DISTINCT user_id FROM document_user_status 
                    WHERE document_id = ? AND status != 'rejected'
                ");
                $all_users_query->execute([$document_id]);
                $all_users = $all_users_query->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($all_users as $uid) {
                    if ($uid != $user_id) {
                        $update_all = $db->prepare("
                            UPDATE document_user_status 
                            SET status = 'rejected', updated_at = NOW() 
                            WHERE document_id = ? AND user_id = ?
                        ");
                        $update_all->execute([$document_id, $uid]);
                    }
                }
                break;
        }
    }
    
    // تحديث حالة المستند العامة
    $update_doc = $db->prepare("
        UPDATE documents 
        SET current_status = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $update_doc->execute([$new_document_status, $document_id]);
    
    // إذا كانت الحالة النهائية "مكتمل" أو "مرفوض"، ننهي المسار
    if ($new_document_status === 'completed' || $new_document_status === 'rejected') {
        // إزالة current_holder_id
        $remove_holder = $db->prepare("
            UPDATE documents 
            SET current_holder_id = NULL 
            WHERE id = ?
        ");
        $remove_holder->execute([$document_id]);
        
        // تحديث جميع خطوات الـ workflow لتكون غير نشطة
        $update_workflow = $db->prepare("
            UPDATE document_workflow 
            SET is_current_step = 0 
            WHERE document_id = ?
        ");
        $update_workflow->execute([$document_id]);
    }
    
    // إضافة سجل في الـ workflow - إصلاح العمود action_description
    $action_arabic = ($action === 'approve') ? 'موافقة' : 'رفض';
    
    // تحقق إذا كان عمود action_description موجوداً
    try {
        $check_column = $db->query("SHOW COLUMNS FROM document_workflow LIKE 'action_description'");
        if ($check_column->rowCount() > 0) {
            // العمود موجود، استخدمه
            $insert_workflow = $db->prepare("
                INSERT INTO document_workflow 
                (document_id, from_user_id, action_type, action_description, action_date) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $insert_workflow->execute([
                $document_id, 
                $user_id, 
                $action,
                "{$action_arabic} على المستند من قبل " . $full_name
            ]);
        } else {
            // العمود غير موجود، استخدم action_type فقط
            $insert_workflow = $db->prepare("
                INSERT INTO document_workflow 
                (document_id, from_user_id, action_type, action_date) 
                VALUES (?, ?, ?, NOW())
            ");
            $insert_workflow->execute([
                $document_id, 
                $user_id, 
                "{$action_arabic} على المستند من قبل " . $full_name
            ]);
        }
    } catch (Exception $e) {
        // في حالة خطأ، استخدم الاستعلام البسيط
        $insert_workflow = $db->prepare("
            INSERT INTO document_workflow 
            (document_id, from_user_id, action_type, action_date) 
            VALUES (?, ?, ?, NOW())
        ");
        $insert_workflow->execute([
            $document_id, 
            $user_id, 
            "{$action_arabic} على المستند من قبل " . $full_name
        ]);
    }
    
    // إرسال إشعارات للمستخدمين المعنيين
    if ($action === 'approve') {
        $notification_title = "تمت الموافقة على المستند";
        $notification_message = "قام " . $full_name . " بالموافقة على المستند: " . $document['title'];
    } else {
        $notification_title = "تم رفض المستند";
        $notification_message = "قام " . $full_name . " برفض المستند: " . $document['title'];
    }
    
    // جلب جميع المستخدمين المعنيين بالمستند (عدا المستخدم الحالي)
    $related_users = $db->prepare("
        SELECT DISTINCT u.id 
        FROM users u
        LEFT JOIN document_user_status dus ON u.id = dus.user_id
        WHERE dus.document_id = ? AND u.id != ?
        UNION
        SELECT created_by FROM documents WHERE id = ? AND created_by != ?
        UNION
        SELECT current_holder_id FROM documents WHERE id = ? AND current_holder_id IS NOT NULL AND current_holder_id != ?
    ");
    $related_users->execute([$document_id, $user_id, $document_id, $user_id, $document_id, $user_id]);
    $users = $related_users->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($users as $notify_user_id) {
        $insert_notification = $db->prepare("
            INSERT INTO notifications 
            (user_id, title, message, link, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $insert_notification->execute([
            $notify_user_id,
            $notification_title,
            $notification_message,
            "../documents/view_document.php?id=" . $document_id
        ]);
    }
    
    $db->commit();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => "تمت عملية " . ($action === 'approve' ? 'الموافقة' : 'الرفض') . " بنجاح"
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'حدث خطأ: ' . $e->getMessage()
    ]);
}