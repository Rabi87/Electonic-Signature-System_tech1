<?php
/**
 * صفحة إشعارات الديوان
 */

require_once '../includes/session.php';
checkLogin();

// التحقق من أن المستخدم مسجل دخول وله دور board
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'board') {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/config.php';
require_once '../includes/database.php';

$db = getDB();
$user_id = $_SESSION['user_id'];

// جلب جميع إشعارات الديوان
$notifications_query = "
    SELECT n.*, u.full_name as sender_name
    FROM notifications n
    LEFT JOIN users u ON n.link LIKE CONCAT('%id=', u.id) OR n.message LIKE CONCAT('%', u.full_name, '%')
    WHERE n.user_id = :user_id 
    ORDER BY n.created_at DESC
    LIMIT 100
";

$notifications_stmt = $db->prepare($notifications_query);
$notifications_stmt->execute([':user_id' => $user_id]);
$all_notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب عدد الإشعارات غير المقروءة
$unread_count_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = 0";
$unread_count_stmt = $db->prepare($unread_count_query);
$unread_count_stmt->execute([':user_id' => $user_id]);
$unread_count = $unread_count_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إشعارات الديوان - نظام التوقيع الإلكتروني</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/board_style.css">
</head>

<body>
    <?php include '../includes/headernew.php'; ?>

    <div class="container" style="padding: 20px;">
        <div class="page-header">
            <h1><i class="fas fa-bell"></i> إشعارات الديوان</h1>
            <div style="display: flex; gap: 10px;">
                <button onclick="markAllAsRead()" class="btn" style="background: #2ecc71; color: white;">
                    <i class="fas fa-check-double"></i> تحديد الكل كمقروء
                </button>
                <button onclick="window.history.back()" class="btn" style="background: #95a5a6; color: white;">
                    <i class="fas fa-arrow-left"></i> رجوع
                </button>
            </div>
        </div>

        <div style="background: white; border-radius: 10px; box-shadow: 0 3px 10px rgba(0,0,0,0.05); padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #2c3e50;">
                    <i class="fas fa-list"></i> قائمة الإشعارات
                    <span style="font-size: 0.9rem; color: #6c757d;">(<?php echo count($all_notifications); ?>)</span>
                </h3>
                <span
                    style="background: <?php echo $unread_count > 0 ? '#e74c3c' : '#2ecc71'; ?>; color: white; padding: 5px 15px; border-radius: 20px;">
                    <i class="fas fa-envelope"></i> غير مقروء: <?php echo $unread_count; ?>
                </span>
            </div>

            <?php if (empty($all_notifications)): ?>
                <div style="text-align: center; padding: 50px; color: #6c757d;">
                    <i class="fas fa-bell-slash fa-3x" style="margin-bottom: 20px;"></i>
                    <h4>لا توجد إشعارات</h4>
                    <p>ستظهر الإشعارات هنا عند توفرها</p>
                </div>
            <?php else: ?>
                <div style="max-height: 500px; overflow-y: auto;">
                    <?php foreach ($all_notifications as $notification): ?>
                        <div class="notification-item" onclick="handleNotificationClick(<?php echo $notification['id']; ?>, 
             <?php
             // استخراج معرف المستند من الرابط
             $documentId = null;
             if (!empty($notification['link'])) {
                 preg_match('/id=(\d+)/', $notification['link'], $matches);
                 if ($matches) {
                     $documentId = $matches[1];
                     echo "'" . $documentId . "'";
                 } else {
                     echo 'null';
                 }
             } else {
                 echo 'null';
             }
             ?>, 
             '<?php echo !empty($notification['link']) ? htmlspecialchars($notification['link']) : ''; ?>')" style="padding: 15px; border-bottom: 1px solid #eee; 
            background: <?php echo $notification['is_read'] == 0 ? '#f8f9fa' : 'white'; ?>; 
            cursor: pointer;
            border-right: <?php echo $notification['is_read'] == 0 ? '4px solid #3498db' : 'none'; ?>;
            transition: all 0.3s ease;" onmouseover="this.style.background='#f1f8ff'"
                            onmouseout="this.style.background='<?php echo $notification['is_read'] == 0 ? '#f8f9fa' : 'white'; ?>'">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <div style="flex: 1;">
                                    <h4 style="margin: 0 0 5px 0; color: #2c3e50;">
                                        <?php echo htmlspecialchars($notification['title']); ?>
                                        <?php if ($notification['is_read'] == 0): ?>
                                            <span
                                                style="background: #e74c3c; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem;">جديد</span>
                                        <?php endif; ?>
                                    </h4>
                                    <p style="margin: 0 0 10px 0; color: #495057; font-size: 14px; line-height: 1.4;">
                                        <?php echo htmlspecialchars($notification['message']); ?>
                                    </p>
                                    <div style="display: flex; gap: 15px; font-size: 0.85rem; color: #6c757d;">
                                        <span><i class="far fa-clock"></i>
                                            <?php echo date('Y-m-d H:i', strtotime($notification['created_at'])); ?></span>
                                        <?php if ($notification['sender_name']): ?>
                                            <span><i class="fas fa-user"></i>
                                                <?php echo htmlspecialchars($notification['sender_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="notification-actions" style="display: flex; gap: 5px; align-items: flex-start;">
                                    <?php if (!empty($notification['link'])): ?>
                                        <span style="color: #3498db; font-size: 12px;">
                                            <i class="fas fa-external-link-alt"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function markAllAsRead() {
            if (confirm('هل تريد تحديد جميع الإشعارات كمقروءة؟')) {
                fetch('mark_all_notifications_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    }
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            location.reload();
                        } else {
                            alert('حدث خطأ: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('حدث خطأ أثناء تحديث الإشعارات');
                    });
            }
        }

        function markSingleAsRead(notificationId) {
            fetch('mark_board_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'notification_id=' + notificationId
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('حدث خطأ: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ أثناء تحديث الإشعار');
                });
        }

        // دالة التعامل مع نقر الإشعار في صفحة الإشعارات الكاملة
        // دالة التعامل مع نقر الإشعار في صفحة الإشعارات الكاملة
        function handleNotificationClick(notificationId, documentId, link) {
            // 1. تحديث الإشعار كمقروء
            markSingleAsRead(notificationId);

            // 2. فتح المستند في نفس النافذة (عودة للداشبورد وفتح المستند)
            if (documentId && documentId !== 'null') {
                // الرجوع للداشبورد وفتح المستند
                window.location.href = `board_dashboard.php?open_document=${documentId}`;
            } else if (link) {
                // فتح الرابط في نفس الصفحة
                window.location.href = link;
            }
        }

        // دالة تحديث الإشعار كمقروء
        function markSingleAsRead(notificationId) {
            fetch('mark_board_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'notification_id=' + notificationId
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // تحديث الواجهة
                        updateNotificationItem(notificationId);
                        updateUnreadCount();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }

        // تحديث واجهة عنصر الإشعار
        function updateNotificationItem(notificationId) {
            const notificationDiv = document.querySelector(`[onclick*="${notificationId}"]`);
            if (notificationDiv) {
                // تغيير الخلفية
                notificationDiv.style.background = '#ffffff';
                notificationDiv.style.borderRight = 'none';

                // إزالة علامة "جديد"
                const newBadge = notificationDiv.querySelector('span[style*="background: #e74c3c"]');
                if (newBadge) {
                    newBadge.remove();
                }

                // إزالة زر "تمت" إذا كان موجوداً
                const actionsDiv = notificationDiv.querySelector('.notification-actions');
                if (actionsDiv) {
                    actionsDiv.remove();
                }
            }
        }

        // تحديث العداد في صفحة الإشعارات
        function updateUnreadCount() {
            const unreadSpan = document.querySelector('span[style*="background"]');
            if (unreadSpan) {
                let currentCount = parseInt(unreadSpan.textContent.match(/\d+/)[0]);
                currentCount--;

                if (currentCount > 0) {
                    unreadSpan.innerHTML = `<i class="fas fa-envelope"></i> غير مقروء: ${currentCount}`;
                } else {
                    unreadSpan.innerHTML = `<i class="fas fa-envelope"></i> غير مقروء: 0`;
                    unreadSpan.style.background = '#2ecc71';
                }
            }
        }
    </script>
</body>

</html>