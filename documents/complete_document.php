<?php
require_once '../includes/config.php';
require_once '../includes/database.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'message' => 'غير مصرح']));
}

$response = ['success' => false, 'message' => ''];

try {
    $user_id = $_SESSION['user_id'];
    $document_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;

    if (!$document_id) {
        throw new Exception('معرف المستند غير صحيح');
    }

    $pdo = getDB();

    // جلب بيانات المستند
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();

    if (!$document) {
        throw new Exception('المستند غير موجود');
    }

    // التحقق من أن المستخدم هو صاحب المستند الحالي أو معين في حقل
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as can_complete 
        FROM document_fields 
        WHERE document_id = ? 
        AND assigned_to = ?
        AND id NOT IN (SELECT field_id FROM field_values WHERE document_id = ?)
    ");
    $stmt->execute([$document_id, $user_id, $document_id]);
    $can_complete = $stmt->fetch();

    if ($can_complete['can_complete'] == 0) {
        throw new Exception('ليس لديك حقول مطلوبة لإكمالها');
    }

    // التحقق من أن جميع الحقول المطلوبة للمستخدم قد تم تعبئتها
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as missing 
        FROM document_fields 
        WHERE document_id = ? 
        AND assigned_to = ? 
        AND id NOT IN (SELECT field_id FROM field_values WHERE document_id = ?)
    ");
    $stmt->execute([$document_id, $user_id, $document_id]);
    $missing = $stmt->fetch();

    if ($missing['missing'] > 0) {
        throw new Exception('يوجد حقول مطلوبة لم تكتمل بعد');
    }

    // تحديث حالة الحقول الخاصة بالمستخدم إلى مكتملة
    $stmt = $pdo->prepare("
        UPDATE document_fields 
        SET status = 'completed' 
        WHERE document_id = ? 
        AND assigned_to = ?
    ");
    $stmt->execute([$document_id, $user_id]);

    // التحقق من حالة جميع الحقول في المستند
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_fields,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_fields,
            SUM(CASE WHEN status = 'pending' AND assigned_to IS NOT NULL THEN 1 ELSE 0 END) as pending_assigned_fields
        FROM document_fields 
        WHERE document_id = ?
    ");
    $stmt->execute([$document_id]);
    $field_stats = $stmt->fetch();

    $new_status = $document['current_status'];
    $is_completed = false;

    // تحديد الحالة الجديدة بناءً على إحصاءات الحقول
    if ($field_stats['completed_fields'] == $field_stats['total_fields']) {
        // جميع الحقول مكتملة
        $new_status = 'completed';
        $is_completed = true;
    } elseif ($field_stats['completed_fields'] > 0 && $field_stats['pending_assigned_fields'] > 0) {
        // بعض الحقول مكتملة وبعضها معلق (لمستخدمين آخرين)
        $new_status = 'partially_signed';
    } elseif ($field_stats['completed_fields'] > 0) {
        // بعض الحقول مكتملة ولكن لا توجد حقول معلقة لآخرين
        $new_status = 'partially_completed';
    }

    // تحديث حالة المستند
    $stmt = $pdo->prepare("UPDATE documents SET current_status = ? WHERE id = ?");
    $stmt->execute([$new_status, $document_id]);

    // تحديث حالة المستخدم في document_user_status
    $user_status = $is_completed ? 'completed' : 'partially_signed';
    
    $stmt = $pdo->prepare("
        INSERT INTO document_user_status 
        (document_id, user_id, status, updated_at) 
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
        status = VALUES(status), 
        updated_at = VALUES(updated_at)
    ");
    $stmt->execute([$document_id, $user_id, $user_status]);

    // تحديث حالة المستخدمين الآخرين إذا لزم الأمر
    if (!$is_completed) {
        // جلب جميع المستخدمين المعنيين بالمستند
        $stmt = $pdo->prepare("
            SELECT DISTINCT assigned_to 
            FROM document_fields 
            WHERE document_id = ? 
            AND assigned_to IS NOT NULL
            AND assigned_to != ?
            AND status = 'pending'
        ");
        $stmt->execute([$document_id, $user_id]);
        $other_users = $stmt->fetchAll();

        foreach ($other_users as $other_user) {
            // تحديث حالة المستخدمين الآخرين إلى pending (انتظار دورهم)
            $stmt = $pdo->prepare("
                INSERT INTO document_user_status 
                (document_id, user_id, status, updated_at) 
                VALUES (?, ?, 'pending', NOW())
                ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                updated_at = VALUES(updated_at)
            ");
            $stmt->execute([$document_id, $other_user['assigned_to'], 'pending']);
        }
    }

    // إضافة سجل في workflow
    $stmt = $pdo->prepare("
        INSERT INTO document_workflow 
        (document_id, from_user_id, to_user_id, action_type, notes, status_before, status_after, action_date) 
        VALUES (?, ?, NULL, 'partial_sign', 'تم إكمال الحقول المطلوبة من المستخدم', ?, ?, NOW())
    ");
    $stmt->execute([$document_id, $user_id, $document['current_status'], $new_status]);

    // إذا كان المستند مكتملاً بالكامل، تحديث الحامل الحالي إلى NULL
    if ($is_completed) {
        $stmt = $pdo->prepare("UPDATE documents SET current_holder_id = NULL WHERE id = ?");
        $stmt->execute([$document_id]);
    }

    $response['success'] = true;
    $response['message'] = 'تم إكمال الحقول المطلوبة بنجاح';
    $response['new_status'] = $new_status;
    $response['user_status'] = $user_status;
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit();