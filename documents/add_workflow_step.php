<?php
/**
 * إضافة خطوة توقيع جديدة لمسار العمل
 * تدعم: استكمال (signature)، موافقة (approve)، رفض (reject)، ملاحظة (note)، إعادة (complete)
 * مع منطق مركزي عبر الديوان الخاص والعام
 */
require_once '../includes/session.php';
require_once '../includes/database.php';
checkLogin();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? '';
$user_role = $_SESSION['role_name'] ?? 'employee'; // الأدوار: p.board, board, employee, section_manager, department_manager, ceo, deputy_ceo

// تحديد صفحة العودة حسب نوع المستخدم
$dashboard_map = [
    'private_poard' => '../dashboard/pboard_dashboard.php',
    'board' => '../dashboard/board_dashboard.php',
    'employee' => '../dashboard/employee_dashboard.php',
    'section_manager' => '../dashboard/section_manager_dashboard.php',
    'department_manager' => '../dashboard/department_manager_dashboard.php',
    'ceo' => '../dashboard/ceo_dashboard.php',
    'deputy_ceo' => '../dashboard/deputy_ceo_dashboard.php',
];
$dashboard_page = $dashboard_map[$user_role] ?? '../dashboard/employee_dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_id = intval($_POST['document_id']);
    $step_type = $_POST['step_type']; // signature, approve, reject, note, complete
    $fields = isset($_POST['fields']) ? (is_array($_POST['fields']) ? $_POST['fields'] : explode(',', $_POST['fields'])) : [];
    $creator_note = trim($_POST['creator_note'] ?? '');
    $update_priority = isset($_POST['update_priority']) ? intval($_POST['update_priority']) : 0;
    $new_priority = $_POST['priority'] ?? null;

    try {
        $db->beginTransaction();

        // التحقق من أن المستند مع المستخدم الحالي
        $check_doc = $db->prepare("
            SELECT id, current_holder_id, current_status, title, created_by
            FROM documents 
            WHERE id = :doc_id AND current_holder_id = :user_id
        ");
        $check_doc->execute([':doc_id' => $document_id, ':user_id' => $user_id]);
        $document = $check_doc->fetch();

        if (!$document) {
            throw new Exception("ليس لديك صلاحية لإضافة خطوات لهذا المستند");
        }

        $creator_id = $document['created_by'];

        // جلب آخر خطوة workflow لتحديد المرسل الأصلي (الديوان)
        $last_workflow = $db->prepare("
            SELECT from_user_id FROM document_workflow 
            WHERE document_id = :doc_id AND is_current_step = 1
        ");
        $last_workflow->execute([':doc_id' => $document_id]);
        $last_step = $last_workflow->fetch();
        $from_user_id = $last_step ? $last_step['from_user_id'] : null;

        // تحديد ما إذا كان المستخدم الحالي ديواناً
        $is_diwan = in_array($user_role, ['private_board', 'board', 'sub_board','office_manager']);

        // إذا لم يكن المستخدم ديواناً، فإن المستلم يجب أن يكون من أرسل له (الديوان)
        $assigned_to = isset($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
        if (!$is_diwan) {
            if (!$from_user_id) {
                throw new Exception("لا يمكن تحديد المستلم - لم يتم العثور على مرسل سابق");
            }
            $assigned_to = $from_user_id; // إجبار المستلم ليكون الديوان
        } else {
            // إذا كان ديواناً ولم يحدد مستلم، خطأ
            if (!$assigned_to && $step_type !== 'reject' && $step_type !== 'approve' && $step_type !== 'complete') {
                // في حالات الرفض والموافقة والإعادة قد يكون المستلم محدداً أو لا، لكن للديوان يجب تحديده
                throw new Exception("يجب تحديد المستخدم المستهدف");
            }
        }

        // تحديث الأولوية إذا طُلب ذلك
        if ($update_priority && $new_priority && in_array($new_priority, ['normal', 'high', 'urgent'])) {
            $update_prio = $db->prepare("UPDATE documents SET priority = :prio WHERE id = :id");
            $update_prio->execute([':prio' => $new_priority, ':id' => $document_id]);
        }

        // جلب معلومات المستخدم المستهدف
        $assigned_user_name = null;
        $assigned_dept = null;
        if ($assigned_to) {
            $get_user = $db->prepare("SELECT full_name, department_id FROM users WHERE id = :id");
            $get_user->execute([':id' => $assigned_to]);
            $user_data = $get_user->fetch();
            $assigned_user_name = $user_data['full_name'] ?? 'مستخدم غير معروف';
            $assigned_dept = $user_data['department_id'] ?? null;
        }

        /**
         * دالة مساعدة لتحديث حالة مستخدم في document_user_status
         */
        function updateUserStatus($db, $doc_id, $user_id, $status, $action_required = null, $dept_id = null)
        {
            $sql = "
                INSERT INTO document_user_status (document_id, user_id, status, action_required, department_id, updated_at)
                VALUES (:doc_id, :user_id, :status, :action, :dept, NOW())
                ON DUPLICATE KEY UPDATE
                    status = :status,
                    action_required = :action,
                    department_id = :dept,
                    updated_at = NOW()
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':doc_id' => $doc_id,
                ':user_id' => $user_id,
                ':status' => $status,
                ':action' => $action_required,
                ':dept' => $dept_id
            ]);
        }

        /**
         * دالة مساعدة لإضافة إشعار
         */
        function addNotification($db, $to_user, $title, $message, $link)
        {
            $stmt = $db->prepare("
                INSERT INTO notifications (user_id, title, message, link, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$to_user, $title, $message, $link]);
        }

        /**
         * دالة مساعدة لإضافة سجل workflow
         */
        function addWorkflowStep($db, $doc_id, $from_user, $to_user, $action_type, $notes, $status_before, $status_after, $is_current = 1)
        {
            $stmt = $db->prepare("
                INSERT INTO document_workflow 
                (document_id, from_user_id, to_user_id, action_type, notes, status_before, status_after, is_current_step, action_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$doc_id, $from_user, $to_user, $action_type, $notes, $status_before, $status_after, $is_current]);
        }

        /**
         * دالة مساعدة لإضافة حقل مستند
         */
        function addDocumentField($db, $doc_id, $type, $label, $assigned_to, $assigned_name, $order, $required, $x, $y, $w, $h, $return_id = false)
        {
            $default_width = 1100;
            $default_height = 1550;

            $x_percent = ($x / $default_width) * 100;
            $y_percent = ($y / $default_height) * 100;
            $width_percent = ($w / $default_width) * 100;
            $height_percent = ($h / $default_height) * 100;

            $stmt = $db->prepare("
                INSERT INTO document_fields 
                (document_id, field_type, label, assigned_to, assigned_name, field_order, required, page_number, 
                 x_position, y_position, width, height, 
                 x_percent, y_percent, width_percent, height_percent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $doc_id,
                $type,
                $label,
                $assigned_to,
                $assigned_name,
                $order,
                $required,
                $x,
                $y,
                $w,
                $h,
                $x_percent,
                $y_percent,
                $width_percent,
                $height_percent
            ]);
            return $return_id ? $db->lastInsertId() : true;
        }

        // ===================================================
        // معالجة الإجراءات حسب النوع
        // ===================================================

        // ---------------------------------------------------
        // 1. الرفض (reject)
        // ---------------------------------------------------
        if ($step_type === 'reject') {
            // تحديث حالة المستخدم الحالي (الرافض) إلى rejected
            updateUserStatus($db, $document_id, $user_id, 'rejected');

            // تحديد ما إذا كان المستلم هو المنشئ
            $is_target_creator = ($assigned_to && $assigned_to == $creator_id);

            // إلغاء الخطوات القديمة
            $db->prepare("UPDATE document_workflow SET is_current_step = 0 WHERE document_id = ?")->execute([$document_id]);

            if ($is_target_creator) {
                // الرفض النهائي: المستند يُعاد للمنشئ ويصبح مكتملاً (مرفوض)
                $update_doc = $db->prepare("
                    UPDATE documents 
                    SET current_status = 'rejected', current_holder_id = :creator_id, updated_at = NOW()
                    WHERE id = :doc_id
                ");
                $update_doc->execute([':doc_id' => $document_id, ':creator_id' => $creator_id]);

                // تحديث حالة المنشئ إلى rejected
                updateUserStatus($db, $document_id, $creator_id, 'rejected');

                // إضافة سجل workflow (لا خطوة حالية تالية)
                addWorkflowStep(
                    $db,
                    $document_id,
                    $user_id,
                    $creator_id,
                    'reject',
                    !empty($creator_note) ? "سبب الرفض: " . $creator_note : "تم رفض المستند",
                    $document['current_status'],
                    'rejected',
                    0
                );

                // إشعار للمنشئ
                addNotification(
                    $db,
                    $creator_id,
                    'تم رفض مستندك',
                    "تم رفض المستند \"" . $document['title'] . "\" من قبل " . $full_name . (!empty($creator_note) ? " - ملاحظة: " . $creator_note : ""),
                    '../documents/view_document.php?id=' . $document_id
                );
            } else {
                // الرفض يذهب إلى المستلم (الديوان عادة)
                $update_doc = $db->prepare("
                    UPDATE documents 
                    SET current_status = 'partially_completed', current_holder_id = :holder, updated_at = NOW()
                    WHERE id = :doc_id
                ");
                $update_doc->execute([':doc_id' => $document_id, ':holder' => $assigned_to]);

                // تحديث حالة المستلم إلى completion_required (لأنه أصبح الحامل وعليه التصرف)
                updateUserStatus($db, $document_id, $assigned_to, 'completion_required', null, $assigned_dept);

                // إضافة سجل workflow (الخطوة الحالية للمستلم)
                addWorkflowStep(
                    $db,
                    $document_id,
                    $user_id,
                    $assigned_to,
                    'reject',
                    !empty($creator_note) ? "سبب الرفض: " . $creator_note : "تم رفض المستند",
                    $document['current_status'],
                    'partially_completed',
                    1
                );

                // إشعار للمستلم
                addNotification(
                    $db,
                    $assigned_to,
                    'مستند بانتظارك بعد رفض',
                    "تم رفض المستند \"" . $document['title'] . "\" وأُحيل إليك من قبل " . $full_name . (!empty($creator_note) ? " - ملاحظة: " . $creator_note : ""),
                    '../documents/view_document.php?id=' . $document_id
                );
            }
        }

        // ---------------------------------------------------
        // 2. الموافقة (approve)
        // ---------------------------------------------------
        elseif ($step_type === 'approve') {
            // تحديث حالة المستخدم الحالي (الموافق) إلى approved
            updateUserStatus($db, $document_id, $user_id, 'approved');

            // تحديد ما إذا كان المستلم هو المنشئ
            $is_target_creator = ($assigned_to && $assigned_to == $creator_id);

            // إلغاء الخطوات القديمة
            $db->prepare("UPDATE document_workflow SET is_current_step = 0 WHERE document_id = ?")->execute([$document_id]);

            if ($is_target_creator) {
                // الموافقة النهائية: المستند يكتمل
                $update_doc = $db->prepare("
                    UPDATE documents 
                    SET current_status = 'partially_completed', current_holder_id = :creator_id, updated_at = NOW()
                    WHERE id = :doc_id
                ");
                $update_doc->execute([':doc_id' => $document_id, ':creator_id' => $creator_id]);

                // تحديث حالة المنشئ إلى approved
                updateUserStatus($db, $document_id, $creator_id, 'pending');

                addWorkflowStep(
                    $db,
                    $document_id,
                    $user_id,
                    $creator_id,
                    'approve',
                    !empty($creator_note) ? "ملاحظة الموافقة: " . $creator_note : "تمت الموافقة",
                    $document['current_status'],
                    'completed',
                    0
                );

                addNotification(
                    $db,
                    $creator_id,
                    'اكتمل مستندك',
                    "تمت الموافقة على مستندك \"" . $document['title'] . "\" وأصبح مكتملاً.",
                    '../documents/view_document.php?id=' . $document_id
                );
            } else {
                // الموافقة تذهب لمستخدم آخر (ديوان عادة)
                $update_doc = $db->prepare("
                    UPDATE documents 
                    SET current_status = 'partially_completed', current_holder_id = :holder, updated_at = NOW()
                    WHERE id = :doc_id
                ");
                $update_doc->execute([':doc_id' => $document_id, ':holder' => $assigned_to]);

                updateUserStatus($db, $document_id, $assigned_to, 'completion_required', null, $assigned_dept);

                addWorkflowStep(
                    $db,
                    $document_id,
                    $user_id,
                    $assigned_to,
                    'approve',
                    !empty($creator_note) ? "ملاحظة الموافقة: " . $creator_note : "تمت الموافقة",
                    $document['current_status'],
                    'partially_completed',
                    1
                );

                addNotification(
                    $db,
                    $assigned_to,
                    'مستند بانتظارك بعد موافقة',
                    "تمت الموافقة على المستند \"" . $document['title'] . "\" وأُحيل إليك من قبل " . $full_name . (!empty($creator_note) ? " - ملاحظة: " . $creator_note : ""),
                    '../documents/view_document.php?id=' . $document_id
                );
            }
        }
        // ---------------------------------------------------
        // 3. الاستكمال (signature) - متاح للجميع
        // ---------------------------------------------------
        elseif ($step_type === 'signature') {
            if (!$assigned_to) {
                throw new Exception("يجب تحديد المستخدم المستهدف للاستكمال");
            }

            $base_y = 1000; // موضع ثابت في الأسفل

            $field_config = [
                'signature' => ['x' => 100, 'w' => 150, 'h' => 50, 'label' => 'توقيع'],
                'date' => ['x' => 300, 'w' => 90, 'h' => 30, 'label' => 'تاريخ'],
                'note' => ['x' => 500, 'w' => 200, 'h' => 80, 'label' => 'ملاحظة'],
                'image' => ['x' => 750, 'w' => 70, 'h' => 70, 'label' => 'ختم']
            ];

            $field_order = 1;
            foreach ($fields as $field) {
                if (!isset($field_config[$field]))
                    continue;
                $cfg = $field_config[$field];

                $field_id = addDocumentField(
                    $db,
                    $document_id,
                    $field,
                    $cfg['label'],
                    $assigned_to,
                    $assigned_user_name,
                    $field_order++,
                    1,
                    $cfg['x'],
                    $base_y,
                    $cfg['w'],
                    $cfg['h'],
                    ($field === 'note') ? true : false
                );

                if ($field === 'note' && $field_id) {
                    $note_content = !empty($creator_note) ? $creator_note : 'السيد ' . $assigned_user_name . '، يرجى استكمال الطلب';
                    $save_note = $db->prepare("
                INSERT INTO field_values (field_id, user_id, value_type, value_data, signed_at, created_at)
                VALUES (?, ?, 'note', ?, NULL, NOW())
            ");
                    $save_note->execute([$field_id, $user_id, $note_content]);
                }
            }

            // إلغاء الخطوات القديمة
            $db->prepare("UPDATE document_workflow SET is_current_step = 0 WHERE document_id = ?")->execute([$document_id]);

            // إضافة خطوة workflow جديدة
            $step_notes = !empty($creator_note) ? "ملاحظة من " . $full_name . ": " . $creator_note : "تم توجيه المستند للاستكمال";
            addWorkflowStep(
                $db,
                $document_id,
                $user_id,
                $assigned_to,
                'review',
                $step_notes,
                $document['current_status'],
                'completion_required',   // تعديل هنا
                1
            );

            // تحديث المستند: حالة عامة completion_required، الحامل هو المستلم
            $update_doc = $db->prepare("
                UPDATE documents 
                SET current_holder_id = ?, current_status = 'completion_required', updated_at = NOW()
                WHERE id = ?
            ");
            $update_doc->execute([$assigned_to, $document_id]);

            // تحديث حالة المرسل إلى pending
            updateUserStatus($db, $document_id, $user_id, 'pending');

            // تحديث حالة المستلم إلى completion_required مع action_required = 'complete'
            updateUserStatus($db, $document_id, $assigned_to, 'completion_required', 'complete', $assigned_dept);

            // إشعار للمستخدم المستهدف
            $notify_msg = "تم توجيه مستند \"" . $document['title'] . "\" إليك لاستكمال الحقول.";
            if (!empty($creator_note)) {
                $notify_msg .= " ملاحظة: " . $creator_note;
            }
            addNotification(
                $db,
                $assigned_to,
                'مستند يتطلب استكمال',
                $notify_msg,
                '../documents/view_document.php?id=' . $document_id
            );
        }


        // ---------------------------------------------------
// 4. ملاحظة فقط (note) - متاح للجميع
// ---------------------------------------------------
        elseif ($step_type === 'note') {
            if (!$assigned_to) {
                throw new Exception("يجب تحديد المستخدم المستهدف للملاحظة");
            }

            // إضافة حقل ملاحظة للمستخدم المستهدف
            $note_field_id = addDocumentField(
                $db,
                $document_id,
                'note',
                'ملاحظة',
                $assigned_to,
                $assigned_user_name,
                1,
                0,
                100,
                1400,
                300,
                80,
                true
            );

            $note_content = !empty($creator_note) ? $creator_note : 'السيد ' . $assigned_user_name . '، يرجى الاطلاع';
            $save_note = $db->prepare("
        INSERT INTO field_values (field_id, user_id, value_type, value_data, signed_at, created_at)
        VALUES (?, ?, 'note', ?, NULL, NOW())
    ");
            $save_note->execute([$note_field_id, $user_id, $note_content]);

            // إلغاء الخطوات القديمة
            $db->prepare("UPDATE document_workflow SET is_current_step = 0 WHERE document_id = ?")->execute([$document_id]);

            addWorkflowStep(
                $db,
                $document_id,
                $user_id,
                $assigned_to,
                'note_assigned',
                $note_content,
                $document['current_status'],
                'completion_required',   // تعديل هنا
                1
            );

            // تحديث المستند: حالة عامة completion_required، الحامل هو المستلم
            $update_doc = $db->prepare("
        UPDATE documents 
        SET current_holder_id = ?, current_status = 'completion_required', updated_at = NOW()
        WHERE id = ?
    ");
            $update_doc->execute([$assigned_to, $document_id]);

            updateUserStatus($db, $document_id, $user_id, 'pending');
            updateUserStatus($db, $document_id, $assigned_to, 'completion_required', 'complete', $assigned_dept);

            addNotification(
                $db,
                $assigned_to,
                'ملاحظة جديدة',
                "تم إضافة ملاحظة من " . $full_name . " على المستند \"" . $document['title'] . "\"",
                '../documents/view_document.php?id=' . $document_id
            );
        }

        // ---------------------------------------------------
        // 5. الإعادة (complete) - للمستخدم العادي بعد تعبئة الحقول
        // ---------------------------------------------------
        elseif ($step_type === 'complete') {
            if ($is_diwan) {
                throw new Exception("الديوان لا يستخدم هذا الإجراء، استخدم signature أو note أو approve/reject");
            }
            if (!$assigned_to) {
                throw new Exception("لم يتم تحديد المستلم (يجب أن يكون الديوان)");
            }

            // تحديث حالة المستخدم الحالي (المرسل) إلى pending (بعد الإرسال)
            updateUserStatus($db, $document_id, $user_id, 'pending');

            // إلغاء الخطوات القديمة
            $db->prepare("UPDATE document_workflow SET is_current_step = 0 WHERE document_id = ?")->execute([$document_id]);

            // إضافة خطوة workflow
            addWorkflowStep(
                $db,
                $document_id,
                $user_id,
                $assigned_to,
                'complete',
                !empty($creator_note) ? "ملاحظة: " . $creator_note : "تم إعادة المستند بعد الاستكمال",
                $document['current_status'],
                'partially_completed',
                1
            );

            // تحديث المستند: حالة عامة partially_completed، الحامل هو المستلم (الديوان)
            $update_doc = $db->prepare("
                UPDATE documents 
                SET current_holder_id = ?, current_status = 'partially_completed', updated_at = NOW()
                WHERE id = ?
            ");
            $update_doc->execute([$assigned_to, $document_id]);

            // تحديث حالة المستلم (الديوان) إلى completion_required (لأنه أصبح الحامل وعليه التصرف)
            updateUserStatus($db, $document_id, $assigned_to, 'completion_required', null, $assigned_dept);

            // إشعار للمستلم (الديوان)
            addNotification(
                $db,
                $assigned_to,
                'مستند معاد من مستخدم',
                "تم إعادة المستند \"" . $document['title'] . "\" من قبل " . $full_name . (!empty($creator_note) ? " مع ملاحظة: " . $creator_note : ""),
                '../documents/view_document.php?id=' . $document_id
            );
        }

        $db->commit();

        header("Location: $dashboard_page?success=1");
        exit();

    } catch (Exception $e) {
        $db->rollBack();
        header("Location: $dashboard_page?error=" . urlencode($e->getMessage()));
        exit();
    }
} else {
    header("Location: $dashboard_page");
    exit();
}
?>