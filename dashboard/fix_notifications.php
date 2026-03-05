<?php
session_start();
require_once '../includes/database.php';

// للاستخدام الداخلي فقط

$db = getDB();

echo "<h2>فحص وإصلاح جدول notifications</h2>";

// 1. التحقق من وجود الجدول
$tables = $db->query("SHOW TABLES LIKE 'notifications'")->rowCount();
if ($tables == 0) {
    echo "<p style='color: red'>❌ الجدول غير موجود</p>";
    // إنشاء الجدول
    $db->exec("
        CREATE TABLE notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            sender_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(500) NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "<p style='color: green'>✅ تم إنشاء الجدول</p>";
} else {
    echo "<p style='color: green'>✅ الجدول موجود</p>";
}

// 2. التحقق من FOREIGN KEYS
echo "<h3>التحقق من FOREIGN KEYS:</h3>";
$foreign_keys = $db->query("
    SELECT 
        CONSTRAINT_NAME,
        TABLE_NAME,
        COLUMN_NAME,
        REFERENCED_TABLE_NAME,
        REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications'
    AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($foreign_keys)) {
    echo "<p style='color: orange'>⚠️ لا توجد FOREIGN KEYS</p>";
    
    // محاولة إضافة FOREIGN KEYS
    try {
        $db->exec("
            ALTER TABLE notifications 
            ADD CONSTRAINT fk_notifications_user 
            FOREIGN KEY (user_id) REFERENCES users(id) 
            ON DELETE CASCADE
        ");
        echo "<p style='color: green'>✅ تم إضافة FOREIGN KEY للمستخدم</p>";
    } catch (Exception $e) {
        echo "<p style='color: red'>❌ فشل إضافة FOREIGN KEY للمستخدم: " . $e->getMessage() . "</p>";
    }
    
    try {
        $db->exec("
            ALTER TABLE notifications 
            ADD CONSTRAINT fk_notifications_sender 
            FOREIGN KEY (sender_id) REFERENCES users(id) 
            ON DELETE SET NULL
        ");
        echo "<p style='color: green'>✅ تم إضافة FOREIGN KEY للمرسل</p>";
    } catch (Exception $e) {
        echo "<p style='color: red'>❌ فشل إضافة FOREIGN KEY للمرسل: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<ul>";
    foreach ($foreign_keys as $fk) {
        echo "<li>✅ {$fk['CONSTRAINT_NAME']}: {$fk['COLUMN_NAME']} → {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}</li>";
    }
    echo "</ul>";
}

// 3. التحقق من البيانات
echo "<h3>البيانات الحالية:</h3>";
$notifications = $db->query("SELECT COUNT(*) as count FROM notifications")->fetch();
echo "<p>إجمالي الإشعارات: {$notifications['count']}</p>";

$sample_data = $db->query("
    SELECT 
        n.id,
        n.title,
        n.is_read,
        n.created_at,
        u1.full_name as recipient,
        u2.full_name as sender
    FROM notifications n
    LEFT JOIN users u1 ON n.user_id = u1.id
    LEFT JOIN users u2 ON n.sender_id = u2.id
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' cellspacing='0'>
    <tr>
        <th>ID</th>
        <th>العنوان</th>
        <th>الحالة</th>
        <th>المستلم</th>
        <th>المرسل</th>
        <th>التاريخ</th>
    </tr>";

foreach ($sample_data as $row) {
    echo "<tr>
        <td>{$row['id']}</td>
        <td>{$row['title']}</td>
        <td>" . ($row['is_read'] ? 'مقروء' : 'غير مقروء') . "</td>
        <td>{$row['recipient']}</td>
        <td>" . ($row['sender'] ?: 'النظام') . "</td>
        <td>{$row['created_at']}</td>
    </tr>";
}
echo "</table>";

// 4. إضافة بيانات اختبارية
echo "<h3>إضافة بيانات اختبارية:</h3>";
$test_users = $db->query("SELECT id, full_name FROM users LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

foreach ($test_users as $user) {
    $test_notifications = [
        [
            'user_id' => $user['id'],
            'title' => 'إشعار ترحيب',
            'message' => "مرحباً بك {$user['full_name']} في النظام",
            'link' => 'dashboard.php'
        ],
        [
            'user_id' => $user['id'],
            'sender_id' => 1, // استخدام أول مستخدم كمرسل
            'title' => 'مهمة جديدة',
            'message' => 'لديك مهمة جديدة تحتاج إلى إنجاز',
            'link' => 'tasks.php'
        ]
    ];
    
    foreach ($test_notifications as $notification) {
        $stmt = $db->prepare("
            INSERT IGNORE INTO notifications (user_id, sender_id, title, message, link, is_read, created_at)
            VALUES (:user_id, :sender_id, :title, :message, :link, 0, NOW())
        ");
        $stmt->execute($notification);
    }
    
    echo "<p>✅ تم إضافة إشعارات تجريبية للمستخدم: {$user['full_name']}</p>";
}

echo "<hr><p style='color: green'><strong>✅ تم الانتهاء من الفحص والإصلاح</strong></p>";
echo "<p><a href='get_notifications.php'>اختبار جلب الإشعارات</a></p>";