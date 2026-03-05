<?php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

$db = getDB();

// جلب جميع المستندات
$documents_stmt = $db->query("SELECT id FROM documents ORDER BY created_at DESC");
$documents = $documents_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>إعادة بناء الإشعارات</h3>";
echo "<p>عدد المستندات: " . count($documents) . "</p>";

foreach ($documents as $doc) {
    rebuildNotificationsForDocument($doc['id']);
    echo "<p>تمت معالجة المستند #" . $doc['id'] . "</p>";
}

echo "<h3>✅ تم الانتهاء من إعادة بناء جميع الإشعارات</h3>";
?>