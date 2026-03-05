<?php
// process_edit.php - معالجة تحرير المستند وإضافة حقول جديدة
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من أن المستخدم مسجل دخول وله صلاحية board
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], ['board', 'sub_board', 'private_board'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$db = getDB();

$response = ['success' => false, 'message' => ''];

try {
    // التحقق من الطريقة POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('طريقة الطلب غير صحيحة');
    }

    // التحقق من وجود معرف المستند
    $document_id = intval($_POST['document_id'] ?? 0);
    if ($document_id <= 0) {
        throw new Exception('معرف المستند غير صالح');
    }

    // جلب المستند الحالي
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        throw new Exception('المستند غير موجود');
    }

    // التحقق من أن المستند ليس مكتملاً أو مؤرشفاً
    if (in_array($document['current_status'], ['completed', 'archived'])) {
        throw new Exception('لا يمكن تحرير مستند مكتمل أو مؤرشف');
    }

    // جلب الحقول الجديدة
    $new_fields_data_json = $_POST['new_fields_data'] ?? '[]';
    $new_fields_data = json_decode($new_fields_data_json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $new_fields_data = [];
    }

    // جلب تحديثات الحقول الموجودة
    $updated_existing_json = $_POST['updated_existing_fields'] ?? '[]';
    $updated_existing = json_decode($updated_existing_json, true);

    $db->beginTransaction();

    try {
        // 1. تحديث مواقع وأحجام الحقول الموجودة إذا وجدت
        if (!empty($updated_existing)) {
            foreach ($updated_existing as $update) {
                $sql_update = "
                    UPDATE document_fields 
                    SET x_position = ?, y_position = ?, width = ?, height = ?,
                        x_percent = ?, y_percent = ?, width_percent = ?, height_percent = ?
                    WHERE id = ? AND document_id = ?
                ";
                $stmt_update = $db->prepare($sql_update);
                $stmt_update->execute([
                    $update['x_position'] ?? 0,
                    $update['y_position'] ?? 0,
                    $update['width'] ?? 150,
                    $update['height'] ?? 50,
                    $update['x_percent'] ?? 0,
                    $update['y_percent'] ?? 0,
                    $update['width_percent'] ?? 15,
                    $update['height_percent'] ?? 5,
                    $update['id'],
                    $document_id
                ]);
            }
        }

        // 2. إضافة الحقول الجديدة
        if (!empty($new_fields_data)) {
            // جلب عدد الحقول الحالية لتحديد الترتيب
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM document_fields WHERE document_id = ?");
            $stmt->execute([$document_id]);
            $field_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            $field_order = $field_count + 1;

            foreach ($new_fields_data as $field) {
                // تخطيط نوع الحقل
                $field_type = 'text'; // الافتراضي
                if ($field['type'] === 'توقيع')
                    $field_type = 'signature';
                elseif ($field['type'] === 'تاريخ')
                    $field_type = 'date';
                elseif ($field['type'] === 'صورة')
                    $field_type = 'image';
                elseif ($field['type'] === 'ملاحظة')
                    $field_type = 'note';
                else
                    $field_type = 'text';

                // إدخال الحقل الجديد مع النسب المئوية
                $sql_field = "
                    INSERT INTO document_fields 
                    (document_id, field_type, label, page_number, x_position, y_position, 
                     width, height, x_percent, y_percent, width_percent, height_percent,
                     assigned_to, assigned_name, field_order, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ";

                $stmt_field = $db->prepare($sql_field);
                $stmt_field->execute([
                    $document_id,
                    $field_type,
                    $field['type'],
                    $field['page'] ?? 1,
                    (int)($field['x'] ?? 0),
                    (int)($field['y'] ?? 0),
                    (int)($field['width'] ?? 150),
                    (int)($field['height'] ?? 50),
                    $field['xPercent'] ?? 0,
                    $field['yPercent'] ?? 0,
                    $field['widthPercent'] ?? 15,
                    $field['heightPercent'] ?? 5,
                    $field['assignedTo'],
                    $field['assignedName'],
                    $field_order,
                    'pending'
                ]);

                $field_id = $db->lastInsertId();
                $field_order++;

                // 3. إذا كان الحقل يحتوي على قيمة مسبقة (نص، ملاحظة، إلخ)
                if (isset($field['textValue']) && !empty($field['textValue'])) {
                    $sql_value = "
                        INSERT INTO field_values 
                        (field_id, user_id, field_type, value_type, value_data, signed_at, created_at) 
                        VALUES (?, ?, ?, 'text', ?, NULL, NOW())
                    ";
                    
                    $stmt_value = $db->prepare($sql_value);
                    $stmt_value->execute([
                        $field_id,
                        $field['assignedTo'],
                        $field_type,
                        $field['textValue']
                    ]);

                    // تحديث حالة الحقل إلى completed
                    $db->prepare("UPDATE document_fields SET status = 'completed' WHERE id = ?")
                       ->execute([$field_id]);
                }
                
                elseif (isset($field['noteValue']) && !empty($field['noteValue'])) {
                    $sql_value = "
                        INSERT INTO field_values 
                        (field_id, user_id, field_type, value_type, value_data, signed_at, created_at) 
                        VALUES (?, ?, ?, 'note', ?, NULL, NOW())
                    ";
                    
                    $stmt_value = $db->prepare($sql_value);
                    $stmt_value->execute([
                        $field_id,
                        $field['assignedTo'],
                        $field_type,
                        $field['noteValue']
                    ]);

                    $db->prepare("UPDATE document_fields SET status = 'completed' WHERE id = ?")
                       ->execute([$field_id]);
                }
                
                elseif (isset($field['signatureData']) && !empty($field['signatureData'])) {
                    $sql_value = "
                        INSERT INTO field_values 
                        (field_id, user_id, field_type, value_type, value_data, signed_at, created_at) 
                        VALUES (?, ?, ?, 'signature_image', ?, NOW(), NOW())
                    ";
                    
                    $stmt_value = $db->prepare($sql_value);
                    $stmt_value->execute([
                        $field_id,
                        $field['assignedTo'],
                        $field_type,
                        $field['signatureData']
                    ]);

                    // إضافة إلى جدول signatures
                    $sql_signature = "
                        INSERT INTO signatures 
                        (document_id, field_id, user_id, signature_data, signature_order, signed_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ";

                    $stmt_signature = $db->prepare($sql_signature);
                    $stmt_signature->execute([
                        $document_id,
                        $field_id,
                        $field['assignedTo'],
                        $field['signatureData'],
                        $field_order - 1
                    ]);

                    $db->prepare("UPDATE document_fields SET status = 'completed' WHERE id = ?")
                       ->execute([$field_id]);
                }
                
                elseif (isset($field['imageData']) && !empty($field['imageData'])) {
                    $sql_value = "
                        INSERT INTO field_values 
                        (field_id, user_id, field_type, value_type, value_data, signed_at, created_at) 
                        VALUES (?, ?, ?, 'image', ?, NULL, NOW())
                    ";
                    
                    $stmt_value = $db->prepare($sql_value);
                    $stmt_value->execute([
                        $field_id,
                        $field['assignedTo'],
                        $field_type,
                        $field['imageData']
                    ]);

                    $db->prepare("UPDATE document_fields SET status = 'completed' WHERE id = ?")
                       ->execute([$field_id]);
                }
            }
        }

        // 4. تحديث حالة المستند إذا تمت إضافة حقول جديدة فقط (اختياري)
        if (!empty($new_fields_data)) {
            $db->prepare("UPDATE documents SET updated_at = NOW() WHERE id = ?")
               ->execute([$document_id]);
        }

        // 5. تسجيل workflow
        $action_notes = '';
        $action_type = 'edit';
        if (!empty($new_fields_data) && empty($updated_existing)) {
            $action_notes = 'تم إضافة ' . count($new_fields_data) . ' حقول جديدة بواسطة ' . $full_name;
        } elseif (empty($new_fields_data) && !empty($updated_existing)) {
            $action_notes = 'تم تعديل مواقع/أحجام ' . count($updated_existing) . ' حقول موجودة بواسطة ' . $full_name;
        } elseif (!empty($new_fields_data) && !empty($updated_existing)) {
            $action_notes = 'تم إضافة ' . count($new_fields_data) . ' حقول جديدة وتعديل ' . count($updated_existing) . ' حقول موجودة بواسطة ' . $full_name;
        } else {
            $action_notes = 'تم تعديل المستند بواسطة ' . $full_name;
        }

        $sql_workflow = "
            INSERT INTO document_workflow 
            (document_id, from_user_id, to_user_id, action_type, 
             notes, status_before, status_after, is_current_step, action_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ";

        $stmt_workflow = $db->prepare($sql_workflow);
        $stmt_workflow->execute([
            $document_id,
            $user_id,
            $user_id,
            $action_type,
            $action_notes,
            $document['current_status'],
            $document['current_status']
        ]);

        $db->commit();

        // رسالة نجاح
        $message = '';
        if (!empty($new_fields_data)) {
            $message .= 'تم إضافة ' . count($new_fields_data) . ' حقول جديدة بنجاح. ';
        }
        if (!empty($updated_existing)) {
            $message .= 'تم تحديث ' . count($updated_existing) . ' حقول موجودة بنجاح.';
        }
        $_SESSION['success_message'] = $message ?: 'تم حفظ التغييرات بنجاح';
        
        header('Location: view_document.php?id=' . $document_id);
        exit();

    } catch (PDOException $e) {
        $db->rollBack();
        throw new Exception('خطأ في قاعدة البيانات: ' . $e->getMessage());
    }

} catch (Exception $e) {
    $_SESSION['error_message'] = 'خطأ: ' . $e->getMessage();
    header('Location: edit_document.php?id=' . $document_id);
    exit();
}