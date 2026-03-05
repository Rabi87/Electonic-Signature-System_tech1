<?php
/**
 * ملف لجلب إحصائيات المؤشرات للرئيس التنفيذي (AJAX)
 */

require_once '../includes/session.php';
require_once '../includes/config.php';
require_once '../includes/database.php';

checkLogin();

// التحقق من أن المستخدم مسجل دخول وله دور ceo
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'ceo') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// حساب المستندات العادية (ليست عاجلة وليست سرية)
$normal_docs_query = "
    SELECT 
        COUNT(DISTINCT d.id) as total,
        SUM(CASE WHEN d.current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as pending
    FROM documents d
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    WHERE (d.current_holder_id = :user_id OR dw.to_user_id = :user_id2)
    AND d.priority NOT IN ('urgent', 'high')
    AND NOT EXISTS (
        SELECT 1 FROM documents d2
        WHERE d2.id = d.id
        AND (
            d2.title LIKE '%سري%' 
            OR d2.title LIKE '%سري للغاية%'
            OR d2.title LIKE '%مصنف%'
            OR d2.description LIKE '%سري%'
            OR d2.description LIKE '%سري للغاية%'
            OR d2.description LIKE '%مصنف%'
        )
    )
";

$normal_params = [':user_id' => $user_id, ':user_id2' => $user_id];
$normal_docs_stmt = $db->prepare($normal_docs_query);
$normal_docs_stmt->execute($normal_params);
$normal_docs = $normal_docs_stmt->fetch(PDO::FETCH_ASSOC);

$normal_stats = [
    'total' => $normal_docs['total'] ?? 0,
    'pending' => $normal_docs['pending'] ?? 0
];

// حساب المستندات العاجلة
$urgent_stats_query = "
    SELECT 
        COUNT(DISTINCT d.id) as total,
        SUM(CASE WHEN d.current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as pending
    FROM documents d
    LEFT JOIN document_workflow dw ON d.id = dw.document_id
    WHERE (d.current_holder_id = :user_id OR dw.to_user_id = :user_id2)
    AND d.priority = 'urgent'
";

$urgent_stats_stmt = $db->prepare($urgent_stats_query);
$urgent_stats_stmt->execute($normal_params);
$urgent_stats = $urgent_stats_stmt->fetch(PDO::FETCH_ASSOC);

// حساب المستندات السرية
$secret_keywords = ['سري', 'سري للغاية', 'مصنف', 'confidential', 'secret', 'classified'];
$secret_conditions = [];
$secret_params = [
    ':user_id' => $user_id,
    ':user_id2' => $user_id
];

foreach ($secret_keywords as $index => $keyword) {
    $secret_conditions[] = "(d.title LIKE :keyword{$index} OR d.description LIKE :keyword{$index})";
    $secret_params[":keyword{$index}"] = "%{$keyword}%";
}

$secret_stats = ['total' => 0, 'pending' => 0];
if (!empty($secret_conditions)) {
    $secret_condition_sql = implode(' OR ', $secret_conditions);
    $secret_docs_query = "
        SELECT 
            COUNT(DISTINCT d.id) as total,
            SUM(CASE WHEN d.current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as pending
        FROM documents d
        LEFT JOIN document_workflow dw ON d.id = dw.document_id
        WHERE (d.current_holder_id = :user_id OR dw.to_user_id = :user_id2)
        AND ({$secret_condition_sql})
    ";
    
    $secret_docs_stmt = $db->prepare($secret_docs_query);
    $secret_docs_stmt->execute($secret_params);
    $secret_docs = $secret_docs_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($secret_docs) {
        $secret_stats['total'] = $secret_docs['total'];
        $secret_stats['pending'] = $secret_docs['pending'];
    }
}

// حساب النسبة المئوية للإكمال
$total_pending = $normal_stats['pending'] + ($urgent_stats['pending'] ?? 0) + $secret_stats['pending'];
$total_documents = $normal_stats['total'] + ($urgent_stats['total'] ?? 0) + $secret_stats['total'];

$completion_percentage = 0;
if ($total_documents > 0) {
    $completed_documents = $total_documents - $total_pending;
    $completion_percentage = round(($completed_documents / $total_documents) * 100, 1);
}

// إرجاع البيانات كـ JSON
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'normal_pending' => $normal_stats['pending'],
    'urgent_pending' => $urgent_stats['pending'] ?? 0,
    'secret_pending' => $secret_stats['pending'],
    'total_pending' => $total_pending,
    'total_documents' => $total_documents,
    'completion_percentage' => $completion_percentage
]);
exit();