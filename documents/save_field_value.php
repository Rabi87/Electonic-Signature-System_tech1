<?php
require_once '../includes/session.php';
require_once '../includes/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['success' => false, 'message' => 'طريقة غير مسموحة']));
}

$field_id = $_POST['field_id'] ?? 0;
$document_id = $_POST['document_id'] ?? 0;
$user_id = $_POST['user_id'] ?? 0;
$value_data = $_POST['value_data'] ?? '';
$value_type = $_POST['value_type'] ?? '';
$field_type = $_POST['field_type'] ?? '';

if (!$field_id || !$document_id || !$user_id || !$value_data) {
    die(json_encode(['success' => false, 'message' => 'بيانات ناقصة']));
}

try {
    $db = getDB();
    $db->beginTransaction();
    
    // === 1. الحصول على دور المستخدم ===
    $get_user_role = $db->prepare("
        SELECT r.role_name 
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = ?
    ");
    $get_user_role->execute([$user_id]);
    $user_role = $get_user_role->fetchColumn();
    $is_ceo = ($user_role == 'ceo');
    
    // === 2. حفظ قيمة الحقل (نفس الكود الأصلي لجميع الأدوار) ===
    if (strpos($value_data, 'data:image') === 0 && in_array($value_type, ['image', 'signature'])) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/electronic-signature-system/uploads/field_images/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        list($type, $data) = explode(';', $value_data);
        list(, $data) = explode(',', $data);
        $data = base64_decode($data);
        
        $extension = 'png';
        if (strpos($type, 'jpeg') !== false) $extension = 'jpg';
        if (strpos($type, 'gif') !== false) $extension = 'gif';
        
        $image_name = 'img_' . $document_id . '_' . $field_id . '_' . time() . '.' . $extension;
        $file_path = $upload_dir . $image_name;
        
        if (file_put_contents($file_path, $data)) {
            $relative_path = 'uploads/field_images/' . $image_name;
            $value_data = $relative_path;
        } else {
            throw new Exception('فشل في حفظ الصورة');
        }
    }
    
    // حفظ في قاعدة البيانات (لجميع الأدوار)
    $sql = "INSERT INTO field_values (field_id, user_id, value_type, value_data, signed_at) 
            VALUES (?, ?, ?, ?, NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$field_id, $user_id, $value_type, $value_data]);
    
    // === 3. فقط إذا لم يكن حقل ملاحظة ===
    if ($field_type !== 'note') {
        // حساب عدد الحقول المتبقية للمستخدم في هذا المستند
        $stmt = $db->prepare("
            SELECT COUNT(*) as remaining_fields
            FROM document_fields df
            WHERE df.document_id = ?
              AND df.assigned_to = ?
              AND df.field_type != 'note'
              AND df.id NOT IN (
                  SELECT fv.field_id 
                  FROM field_values fv 
                  WHERE fv.field_id = df.id
              )
        ");
        $stmt->execute([$document_id, $user_id]);
        $remaining = $stmt->fetch();
        $remaining_fields = $remaining['remaining_fields'];
        
        // إذا لم يتبق أي حقل للمستخدم
        if ($remaining_fields == 0) {
            // تحديث حالة المستخدم في document_user_status
            $user_status = $is_ceo ? 'completed' : 'partially_signed';
            
            $update_user_status = $db->prepare("
                INSERT INTO document_user_status (document_id, user_id, status, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                updated_at = VALUES(updated_at)
            ");
            $update_user_status->execute([$document_id, $user_id, $user_status]);
            
            // === 4. تحديث حالة المستند العامة وتحويله إلى الديوان ===
            if ($is_ceo) {
                // ======= التعديل هنا: تحويل المستند إلى الديوان (board) =======
                
                // 1. الحصول على مستخدم الديوان (board)
                $get_board_user = $db->prepare("
                    SELECT u.id 
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    WHERE r.role_name = 'board'
                    AND u.is_active = 1
                    LIMIT 1
                ");
                $get_board_user->execute();
                $board_user = $get_board_user->fetch();
                
                if ($board_user) {
                    $board_user_id = $board_user['id'];
                    
                    // 2. تحديث حالة المستند إلى 'completion_required'
                    // وتحديث حامل المستند إلى مستخدم الديوان
                    $document_status = 'completion_required';
                    
                    $update_doc = $db->prepare("
                        UPDATE documents 
                        SET current_status = ?, 
                            current_holder_id = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $update_doc->execute([$document_status, $board_user_id, $document_id]);
                    
                    // 3. تحديث حالة المستخدم الديوان إلى 'completion_required'
                    $update_board_status = $db->prepare("
                        INSERT INTO document_user_status (document_id, user_id, status, updated_at)
                        VALUES (?, ?, 'completion_required', NOW())
                        ON DUPLICATE KEY UPDATE 
                        status = VALUES(status), 
                        updated_at = VALUES(updated_at)
                    ");
                    $update_board_status->execute([$document_id, $board_user_id]);
                    
                    // 4. إضافة سجل في workflow لنقل المستند إلى الديوان
                    $add_workflow = $db->prepare("
                        INSERT INTO document_workflow 
                        (document_id, from_user_id, to_user_id, action_type, action_date, 
                         status_before, status_after, notes, is_current_step)
                        VALUES (?, ?, ?, 'forward', NOW(), 
                                'partially_signed', ?, 'تم تحويل المستند إلى الديوان للمتابعة', 1)
                    ");
                    $add_workflow->execute([$document_id, $user_id, $board_user_id, $document_status]);
                    
                    // 5. تحديث سجلات workflow القديمة لعدم كونها current
                    $update_old_workflow = $db->prepare("
                        UPDATE document_workflow 
                        SET is_current_step = 0 
                        WHERE document_id = ? 
                        AND id != LAST_INSERT_ID()
                    ");
                    $update_old_workflow->execute([$document_id]);
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'تم توقيع الـ CEO بنجاح. تم تحويل المستند إلى الديوان للمتابعة',
                        'user_status' => $user_status,
                        'document_status' => $document_status,
                        'is_ceo' => $is_ceo,
                        'user_role' => $user_role,
                        'board_user_id' => $board_user_id,
                        'transferred_to_board' => true
                    ]);
                    
                } else {
                    // إذا لم يتم العثور على مستخدم الديوان
                    $document_status = 'completion_required';
                    
                    $update_doc_status = $db->prepare("
                        UPDATE documents 
                        SET current_status = ?, 
                            updated_at = NOW()
                        WHERE id = ? AND current_status != 'completed'
                    ");
                    $update_doc_status->execute([$document_status, $document_id]);
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'تم توقيع الـ CEO بنجاح، لكن لم يتم العثور على مستخدم الديوان',
                        'user_status' => $user_status,
                        'document_status' => $document_status,
                        'is_ceo' => $is_ceo,
                        'user_role' => $user_role
                    ]);
                }
                
            } else {
                // إذا كان المستخدم ليس CEO:
                // - حالة المستخدم: 'partially_signed'
                // - حالة المستند: 'partially_signed' (إذا أكمل جميع المستخدمين)
                
                // نحتاج للتحقق من حالة جميع المستخدمين
                $stmt = $db->prepare("
                    SELECT COUNT(DISTINCT df.assigned_to) as total_users
                    FROM document_fields df
                    WHERE df.document_id = ? 
                      AND df.field_type != 'note'
                      AND df.assigned_to IS NOT NULL
                ");
                $stmt->execute([$document_id]);
                $total_users = $stmt->fetchColumn();
                
                $stmt = $db->prepare("
                    SELECT COUNT(DISTINCT df.assigned_to) as completed_users
                    FROM document_fields df
                    WHERE df.document_id = ? 
                      AND df.field_type != 'note'
                      AND df.assigned_to IS NOT NULL
                      AND NOT EXISTS (
                          SELECT 1 
                          FROM document_fields df2
                          LEFT JOIN field_values fv ON fv.field_id = df2.id
                          WHERE df2.document_id = df.document_id
                            AND df2.assigned_to = df.assigned_to
                            AND df2.field_type != 'note'
                            AND fv.id IS NULL
                      )
                ");
                $stmt->execute([$document_id]);
                $completed_users = $stmt->fetchColumn();
                
                if ($total_users > 0 && $completed_users == $total_users) {
                    // جميع المستخدمين أكملوا حقولهم
                    $document_status = 'partially_signed';
                    
                    $update_doc_status = $db->prepare("
                        UPDATE documents 
                        SET current_status = ?, 
                            updated_at = NOW()
                        WHERE id = ? AND current_status != 'completed'
                    ");
                    $update_doc_status->execute([$document_status, $document_id]);
                } else {
                    // لم يكمل جميع المستخدمين بعد
                    $document_status = 'pending';
                    // لا نقوم بتحديث حالة المستند
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'تم إكمال جميع الحقول بنجاح',
                    'user_status' => $user_status,
                    'document_status' => $document_status,
                    'is_ceo' => $is_ceo,
                    'total_users' => $total_users,
                    'completed_users' => $completed_users,
                    'user_role' => $user_role
                ]);
            }
            
        } else {
            // لا يزال هناك حقول متبقية للمستخدم
            echo json_encode([
                'success' => true, 
                'message' => 'تم حفظ الحقل، لكن هناك ' . $remaining_fields . ' حقول متبقية',
                'remaining_fields' => $remaining_fields,
                'document_status' => 'pending',
                'is_ceo' => $is_ceo,
                'user_role' => $user_role
            ]);
        }
    } else {
        // حقل ملاحظة - لا يؤثر على الحالة
        echo json_encode([
            'success' => true, 
            'message' => 'تم حفظ الملاحظة بنجاح',
            'field_type' => 'note'
        ]);
    }
    
    $db->commit();
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'خطأ: ' . $e->getMessage()]);
}