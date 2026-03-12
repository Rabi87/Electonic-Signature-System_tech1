<?php
session_start();
require_once '../includes/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'board') {
    echo 'غير مسموح';
    exit();
}

$db = getDB();
$document_id = $_POST['document_id'] ?? 0;

if ($document_id > 0) {
    $stmt = $db->prepare("UPDATE documents SET archived = 0 WHERE id = ?");
    $stmt->execute([$document_id]);
}

echo 'تمت الاستعادة';
?>