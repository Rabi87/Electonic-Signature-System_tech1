<?php
/**
 * process_upload.php
 * معالجة رفع مستند جديد مع الحقول التفاعلية
 * نسخة محسنة ومأمونة
 */

require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// التحقق من CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error_message'] = 'طلب غير صالح (CSRF)';
    header('Location: document_upload.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role_name'];
$full_name = $_SESSION['full_name'];
$db = getDB();

// دالة مساعدة لتحديد لوحة التحكم
function getDashboardPage($role_name) {
    $dashboards = [
        'employee' => 'dashboard/employee_dashboard.php',
        'section_head' => 'dashboard/section_manager_dashboard.php',
        'department_head' => 'dashboard/department_manager_dashboard.php',
        'admin' => 'dashboard/dashboard_admin.php',
        'board' => 'dashboard/board_dashboard.php',
        'private_board' => 'dashboard/pboard_dashboard.php',
        'sub_board' => 'dashboard/sboard_dashboard.php',
        'ceo' => 'dashboard/ceo_dashboard.php'
    ];
    return $dashboards[$role_name] ?? 'dashboard.php';
}

$dashboard_page = getDashboardPage($user_role);

// بدء المعالجة
try {
    // التحقق من الطلب
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('طريقة الطلب غير صحيحة');
    }

    // التحقق من رفع الملف
    if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('خطأ في تحميل الملف، يرجى المحاولة مرة أخرى');
    }

    // قراءة بيانات النموذج
    $document_name = trim($_POST['document_name'] ?? '');
    $importance = trim($_POST['importance'] ?? 'normal');
    $fields_data_json = $_POST['fields_data'] ?? '[]';

    if (empty($document_name)) {
        throw new Exception('اسم المستند مطلوب');
    }

    // فك تشفير JSON الحقول
    $fields_data = json_decode($fields_data_json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $fields_data = [];
    }
    if (!is_array($fields_data)) {
        $fields_data = [];
    }

    // التحقق من عدد الحقول (حد أقصى 50)
    if (count($fields_data) > 50) {
        throw new Exception('عدد الحقول كبير جداً (الحد الأقصى 50)');
    }

    // --- رفع الملف والتحقق من نوعه ---
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/electronic-signature-system/uploads/documents/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file = $_FILES['pdf_file'];
    
    // التحقق من نوع MIME الحقيقي
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($mime_type !== 'application/pdf') {
        throw new Exception('يجب أن يكون الملف من نوع PDF صحيح (تم الكشف عن: ' . $mime_type . ')');
    }

    // إنشاء اسم فريد للملف
    $file_name = 'doc_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $file_path = $upload_dir . $file_name;

    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('فشل في تحميل الملف إلى الخادم');
    }

    $relative_file_path = 'uploads/documents/' . $file_name;
    $file_size = filesize($file_path);

    // --- بدء المعاملة ---
    $db->beginTransaction();

    // التحقق من صحة priority
    $priority = in_array($importance, ['normal', 'high', 'urgent']) ? $importance : 'normal';

    // --- جمع جميع معرفات المستخدمين المعينين للتحقق من وجودهم مرة واحدة ---
    $all_assignee_ids = [$user_id]; // نضمن وجود الرافع
    foreach ($fields_data as $field) {
        if (isset($field['assignedTo']) && is_numeric($field['assignedTo']) && $field['assignedTo'] > 0) {
            $all_assignee_ids[] = (int)$field['assignedTo'];
        }
    }
    $all_assignee_ids = array_unique($all_assignee_ids);

    // جلب أسماء المستخدمين
    $placeholders = implode(',', array_fill(0, count($all_assignee_ids), '?'));
    $stmt_users = $db->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders) AND is_active = 1");
    $stmt_users->execute($all_assignee_ids);
    $users_map = [];
    while ($row = $stmt_users->fetch(PDO::FETCH_ASSOC)) {
        $users_map[$row['id']] = $row['full_name'];
    }

    // التأكد من وجود جميع المستخدمين المعينين (بما فيهم الرافع)
    foreach ($all_assignee_ids as $uid) {
        if (!isset($users_map[$uid])) {
            throw new Exception('المستخدم ذو الرقم ' . $uid . ' غير موجود أو غير نشط');
        }
    }

    // --- تحديد المستلم الأول (أول مستخدم غير الرافع) ---
    $first_assignee_id = null;
    foreach ($fields_data as $field) {
        $aid = isset($field['assignedTo']) && is_numeric($field['assignedTo']) && $field['assignedTo'] > 0 ? (int)$field['assignedTo'] : $user_id;
        if ($aid != $user_id) {
            $first_assignee_id = $aid;
            break;
        }
    }
    $current_holder_id = $first_assignee_id ?? $user_id;
    $is_sent_to_other = ($first_assignee_id !== null && $first_assignee_id != $user_id);

    // --- إدخال المستند الأساسي ---
    $initial_status = $is_sent_to_other ? 'under_review' : 'draft';
    $sql_document = "
        INSERT INTO documents 
        (title, description, file_path, file_type, file_size, 
         created_by, current_holder_id, current_status, priority, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";
    $stmt_document = $db->prepare($sql_document);
    $stmt_document->execute([
        $document_name,
        'مستند تم إنشاؤه بواسطة ' . $full_name,
        $relative_file_path,
        'application/pdf',
        $file_size,
        $user_id,
        $current_holder_id,
        $initial_status,
        $priority
    ]);
    $document_id = $db->lastInsertId();

    // --- تجهيز مصفوفة لتجميع المعينين لاستخدامها لاحقاً في document_user_status ---
    $assignees = []; // مفتاح = user_id، قيمة = ['is_owner' => bool, 'fields' => []]

    // --- إضافة الرافع كمستلم افتراضي (حتى لو لم يكن له حقول) ---
    $assignees[$user_id] = [
        'user_id' => $user_id,
        'is_owner' => true,
        'fields' => []
    ];

    // --- معالجة الحقول وإدخالها ---
    $field_counter = 0;
    $field_inserted_ids = []; // لتخزين field_id مقابل المفتاح المؤقت (اختياري)

    foreach ($fields_data as $field) {
        $field_counter++;

        // التحقق من وجود الخصائص الأساسية
        $required_keys = ['type', 'page', 'xPercent', 'yPercent', 'widthPercent', 'heightPercent'];
        foreach ($required_keys as $key) {
            if (!isset($field[$key])) {
                throw new Exception('بيانات الحقل غير مكتملة: الخاصية ' . $key . ' مفقودة');
            }
        }

        // قراءة القيم مع التحقق
        $field_type_label = $field['type']; // القيمة المرسلة: 'توقيع', 'تاريخ', إلخ
        $page = (int)$field['page'];
        if ($page < 1 || $page > 100) {
            throw new Exception('رقم الصفحة غير صالح');
        }

        $x_percent = (float)$field['xPercent'];
        $y_percent = (float)$field['yPercent'];
        $width_percent = (float)$field['widthPercent'];
        $height_percent = (float)$field['heightPercent'];

        if ($x_percent < 0 || $x_percent > 100 || $y_percent < 0 || $y_percent > 100 ||
            $width_percent <= 0 || $width_percent > 100 || $height_percent <= 0 || $height_percent > 100) {
            throw new Exception('قيم النسب المئوية للحقل غير صالحة');
        }

        // تحويل نوع الحقل إلى القيمة المخزنة في قاعدة البيانات
        $field_type = 'text'; // default
        if ($field_type_label === 'توقيع') {
            $field_type = 'signature';
        } elseif ($field_type_label === 'تاريخ') {
            $field_type = 'date';
        } elseif ($field_type_label === 'ملاحظة') {
            $field_type = 'note';
        } elseif ($field_type_label === 'صورة') {
            $field_type = 'image';
        }

        // معالجة assignedTo
        $assigned_to = $user_id; // افتراضي
        if (isset($field['assignedTo']) && is_numeric($field['assignedTo']) && $field['assignedTo'] > 0) {
            $assigned_to = (int)$field['assignedTo'];
        }
        // التأكد من وجود المستخدم (تم التحقق منه مسبقاً)
        $assigned_name = $users_map[$assigned_to] ?? $full_name; // لن يحدث missing لأننا تحققنا

        // حساب القيم البكسلية (افتراضياً A4 595x842)
        $pageWidth = 595;
        $pageHeight = 842;
        $x_position = ($x_percent * $pageWidth) / 100;
        $y_position = ($y_percent * $pageHeight) / 100;
        $width = ($width_percent * $pageWidth) / 100;
        $height = ($height_percent * $pageHeight) / 100;

        // تحديد حالة الحقل مبدئياً (pending)
        $field_status = 'pending';

        // إدخال الحقل في جدول document_fields
        $sql_field = "
            INSERT INTO document_fields 
            (document_id, field_type, label, page_number, 
             x_position, y_position, width, height,
             x_percent, y_percent, width_percent, height_percent,
             assigned_to, assigned_name, field_order, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt_field = $db->prepare($sql_field);
        $stmt_field->execute([
            $document_id,
            $field_type,
            $field_type_label,
            $page,
            round($x_position),
            round($y_position),
            round($width),
            round($height),
            $x_percent,
            $y_percent,
            $width_percent,
            $height_percent,
            $assigned_to,
            $assigned_name,
            $field_counter,
            $field_status
        ]);
        $field_id = $db->lastInsertId();

        // --- تجميع المعينين ---
        if (!isset($assignees[$assigned_to])) {
            $assignees[$assigned_to] = [
                'user_id' => $assigned_to,
                'is_owner' => ($assigned_to == $user_id),
                'fields' => []
            ];
        }
        $assignees[$assigned_to]['fields'][] = $field;

        // --- إذا كان الحقل معبأ مسبقاً (textValue, noteValue, signatureData, imageData) ---
        $value_to_save = null;
        $value_type = null;
        $is_completed = false;

        if ($field_type === 'text' && !empty($field['textValue'])) {
            $value_to_save = $field['textValue'];
            $value_type = 'text';
            $is_completed = true;
        } elseif ($field_type === 'note' && !empty($field['noteValue'])) {
            $value_to_save = $field['noteValue'];
            $value_type = 'note';
            $is_completed = true;
        } elseif ($field_type === 'date' && !empty($field['textValue'])) {
            $value_to_save = $field['textValue'];
            $value_type = 'date';
            $is_completed = true;
        } elseif ($field_type === 'signature' && !empty($field['signatureData'])) {
            // التحقق من حجم base64
            $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $field['signatureData']));
            if (strlen($decoded) > 2 * 1024 * 1024) {
                throw new Exception('حجم التوقيع كبير جداً (الحد الأقصى 2MB)');
            }
            $value_to_save = $field['signatureData'];
            $value_type = 'signature_image';
            $is_completed = true;
        } elseif ($field_type === 'image' && !empty($field['imageData'])) {
            $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $field['imageData']));
            if (strlen($decoded) > 2 * 1024 * 1024) {
                throw new Exception('حجم الصورة كبير جداً (الحد الأقصى 2MB)');
            }
            $value_to_save = $field['imageData'];
            $value_type = 'image';
            $is_completed = true;
        }

        // إدخال القيمة إذا وجدت
        if ($value_to_save !== null) {
            $signed_at = null;
            if (in_array($field_type, ['signature', 'image', 'date'])) {
                $signed_at = date('Y-m-d H:i:s');
            }

            $sql_value = "
                INSERT INTO field_values 
                (field_id, user_id, field_type, value_type, value_data, signed_at, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ";
            $stmt_value = $db->prepare($sql_value);
            $stmt_value->execute([
                $field_id,
                $assigned_to,
                $field_type,
                $value_type,
                $value_to_save,
                $signed_at
            ]);

            // تحديث حالة الحقل إلى completed
            $db->prepare("UPDATE document_fields SET status = 'completed' WHERE id = ?")->execute([$field_id]);

            // إذا كان توقيع، أضف إلى جدول signatures
            if ($field_type === 'signature') {
                $sql_signature = "
                    INSERT INTO signatures 
                    (document_id, field_id, user_id, signature_data, signature_order, signed_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ";
                $stmt_signature = $db->prepare($sql_signature);
                $stmt_signature->execute([
                    $document_id,
                    $field_id,
                    $assigned_to,
                    $field['signatureData'],
                    $field_counter
                ]);
            }
        }
    } // نهاية foreach fields_data

    // --- تحديث حالة المستند بناءً على وجود مستلمين آخرين ---
    $has_other = false;
    foreach ($assignees as $assignee) {
        if (!$assignee['is_owner']) {
            $has_other = true;
            break;
        }
    }

    $new_status = $has_other ? 'under_review' : 'draft';
    $final_holder = $has_other ? $first_assignee_id : $user_id;
    if (!$final_holder) $final_holder = $user_id;

    $update_doc = $db->prepare("UPDATE documents SET current_status = ?, current_holder_id = ? WHERE id = ?");
    $update_doc->execute([$new_status, $final_holder, $document_id]);

    // --- إدخال سجلات document_user_status لكل معين ---
    foreach ($assignees as $assignee) {
        if ($assignee['is_owner']) {
            $user_status = $has_other ? 'pending' : 'draft';
            $action_required = null;
        } else {
            $user_status = 'completion_required';
            $action_required = 'complete';
        }

        $sql_user_status = "
            INSERT INTO document_user_status 
            (document_id, user_id, status, action_required, is_owner_department, updated_at) 
            VALUES (?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                action_required = VALUES(action_required), 
                updated_at = NOW()
        ";
        $stmt_user = $db->prepare($sql_user_status);
        $stmt_user->execute([
            $document_id,
            $assignee['user_id'],
            $user_status,
            $action_required
        ]);
    }

    // --- إضافة سجل workflow ---
    $sql_workflow = "
        INSERT INTO document_workflow 
        (document_id, from_user_id, to_user_id, action_type, 
         notes, status_before, status_after, is_current_step, action_date) 
        VALUES (?, ?, ?, ?, ?, 'draft', ?, 1, NOW())
    ";
    if ($has_other) {
        $stmt_workflow = $db->prepare($sql_workflow);
        $stmt_workflow->execute([
            $document_id,
            $user_id,
            $final_holder,
            'submit',
            'تم إنشاء المستند وإرساله من قبل ' . $full_name,
            $new_status
        ]);
    } else {
        $stmt_workflow = $db->prepare($sql_workflow);
        $stmt_workflow->execute([
            $document_id,
            $user_id,
            $user_id,
            'create',
            'تم إنشاء المستند بواسطة ' . $full_name,
            $new_status
        ]);
    }

    // --- إرسال إشعار إذا تم الإرسال لشخص آخر ---
    if ($has_other) {
        $notification_title = 'مستند جديد موجه إليك';
        $notification_message = 'يوجد مستند جديد من ' . $full_name . ' يحتاج مراجعتك';
        $notification_link = '../documents/view_document.php?id=' . $document_id;

        $sql_notification = "
            INSERT INTO notifications 
            (user_id, title, message, link, is_read, created_at) 
            VALUES (?, ?, ?, ?, 0, NOW())
        ";
        $db->prepare($sql_notification)->execute([
            $final_holder,
            $notification_title,
            $notification_message,
            $notification_link
        ]);
    }

    // --- تسجيل النشاط ---
    logUserActivity($user_id, 'DOCUMENT_UPLOAD', 'رفع مستند: ' . $document_name, $document_id, ['fields_count' => count($fields_data)]);

    // --- إنهاء المعاملة بنجاح ---
    $db->commit();

    $_SESSION['success_message'] = 'تم رفع المستند بنجاح!';
    $redirect_url = '../' . $dashboard_page . '?upload_success=1&document_id=' . $document_id;
    header('Location: ' . $redirect_url);
    exit();

} catch (PDOException $e) {
    // التراجع عن المعاملة وحذف الملف المرفوع
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if (isset($file_path) && file_exists($file_path)) {
        @unlink($file_path);
    }

    // تسجيل الخطأ الحقيقي في سجل الأخطاء
    error_log('Database error in process_upload: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    // يمكن إضافة تتبع الاستعلام إذا كنت قد خزنته في متغير
    // error_log('Last query: ' . ($lastSql ?? 'unknown'));

    // رسالة مناسبة للمستخدم
    $_SESSION['error_message'] = 'حدث خطأ في قاعدة البيانات، الرجاء المحاولة لاحقاً أو الاتصال بالدعم الفني.';
    header('Location: document_upload.php');
    exit();

} catch (Exception $e) {
    // خطأ عام (غير PDO)
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if (isset($file_path) && file_exists($file_path)) {
        @unlink($file_path);
    }

    // تسجيل الخطأ
    error_log('General error in process_upload: ' . $e->getMessage());

    $_SESSION['error_message'] = 'خطأ: ' . $e->getMessage();
    header('Location: document_upload.php?error=' . urlencode($e->getMessage()));
    exit();
}