<?php
/**
 * send_reminder.php — ضع في نفس مجلد track_document.php (/dashboard/)
 */

// session_start() مباشرة بدون session.php لتجنب أي redirect
session_start();
require_once '../includes/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صحيحة']);
    exit;
}

// sender_id: أولوية للجلسة، fallback للقيمة المُمررة من PHP
$sender_id      = (int)($_SESSION['user_id'] ?? $_POST['sender_id'] ?? 0);
$target_user_id = (int)($_POST['user_id']     ?? 0);
$document_id    = (int)($_POST['document_id'] ?? 0);
$message        = trim($_POST['message']      ?? '');

if (!$sender_id) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}
if (!$target_user_id || !$document_id) {
    echo json_encode(['success' => false, 'message' => 'بيانات ناقصة']);
    exit;
}

try {
    $db = getDB();

    // التحقق من وجود المستند وصلاحية المرسل
    $check = $db->prepare("
        SELECT d.id, d.title
        FROM documents d
        WHERE d.id = :doc_id
          AND (
              d.created_by = :sid
              OR EXISTS (
                  SELECT 1 FROM document_workflow dw
                  WHERE dw.document_id = d.id
                    AND (dw.from_user_id = :sid2 OR dw.to_user_id = :sid3)
              )
          )
        LIMIT 1
    ");
    $check->execute([':doc_id' => $document_id, ':sid' => $sender_id,
                     ':sid2' => $sender_id, ':sid3' => $sender_id]);
    $doc = $check->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'لا توجد صلاحية على هذا المستند']);
        exit;
    }

    // التحقق من المستخدم المستهدف
    $userStmt = $db->prepare("SELECT id, full_name FROM users WHERE id = ? LIMIT 1");
    $userStmt->execute([$target_user_id]);
    $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        echo json_encode(['success' => false, 'message' => 'المستخدم غير موجود']);
        exit;
    }

    // بناء الرسالة
    $docTitle = $doc['title'] ?? "مستند #$document_id";
    $finalMsg = $message
        ? "\"$message\" — بخصوص: $docTitle"
        : "لديك مستند ينتظر إجراءك: \"$docTitle\"";

    // رابط الداشبورد المناسب
    $roleStmt = $db->prepare("
        SELECT r.role_name FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = ? LIMIT 1
    ");
    $roleStmt->execute([$target_user_id]);
    $targetRole = $roleStmt->fetchColumn() ?: 'employee';

    $dashMap = [
        'private_board'      => 'pboard_dashboard.php',
        'sub_board'          => 'sboard_dashboard.php',
        'board'              => 'board_dashboard.php',
        'employee'           => 'employee_dashboard.php',
        'section_manager'    => 'section_manager_dashboard.php',
        'department_manager' => 'department_manager_dashboard.php',
        'deputy_ceo'         => 'deputy_ceo_dashboard.php',
        'ceo'                => 'ceo_dashboard.php',
        'office_manager'     => 'board_dashboard.php',
    ];
    $link = "../dashboard/" . ($dashMap[$targetRole] ?? 'employee_dashboard.php');

    // إدراج الإشعار
    $notif = $db->prepare("
        INSERT INTO notifications (user_id, title, message, link, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $notif->execute([
        $target_user_id,
        'تذكير: مستند ينتظر إجراءك',
        $finalMsg,
        $link,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'تم إرسال التذكير بنجاح إلى ' . $targetUser['full_name'],
    ]);

} catch (Exception $e) {
    error_log('send_reminder error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}