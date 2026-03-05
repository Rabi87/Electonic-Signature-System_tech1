<?php
// notifications.php
require_once '../includes/session.php';
require_once '../includes/helpers.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';
$db = getDB();
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$user_role = $_SESSION['role_name'] ?? 'employee';

$dashboard_url = getDashboardUrl();

// تحديث الإشعارات كمقروءة
if (isset($_GET['mark_as_read']) && $_GET['mark_as_read'] == 'all') {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    header('Location: notifications.php');
    exit();
}

// تحديث إشعار معين كمقروء
if (isset($_GET['mark_as_read_id'])) {
    $notification_id = intval($_GET['mark_as_read_id']);
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notification_id, $user_id]);
    header('Location: notifications.php');
    exit();
}

// حذف إشعار معين
if (isset($_GET['delete_id'])) {
    $notification_id = intval($_GET['delete_id']);
    $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$notification_id, $user_id]);
    header('Location: notifications.php');
    exit();
}

// جلب الإشعارات
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY is_read ASC, created_at DESC 
    LIMIT 100
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب عدد الإشعارات غير المقروءة
$stmt = $db->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread = $stmt->fetch(PDO::FETCH_ASSOC)['unread'];

$user_initials = mb_substr($full_name, 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>الإشعارات - نظام التوقيع الإلكتروني</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Cairo', sans-serif;
        }

        body {
              background: linear-gradient(
    135deg,
    #164a40 0%,
    rgba(140, 119, 79, 1) 50%,
    rgba(140, 119, 79, 1) 100%
  );
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* خلفية متحركة مثل upload */
        .background-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .floating-element {
            position: absolute;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 20s infinite;
        }

        .floating-element:nth-child(1) {
            top: 10%;
            left: 10%;
            width: 300px;
            height: 300px;
            animation-delay: 0s;
        }

        .floating-element:nth-child(2) {
            bottom: 10%;
            right: 10%;
            width: 400px;
            height: 400px;
            animation-delay: 5s;
        }

        .floating-element:nth-child(3) {
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 500px;
            height: 500px;
            animation-delay: 10s;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(30px, -30px) rotate(120deg); }
            66% { transform: translate(-30px, 30px) rotate(240deg); }
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        /* رأس الصفحة */
        .header {
            background: white;
            border-radius: 15px;
            padding: 20px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(20, 184, 5, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 {
            font-size: 1.8rem;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header h1 i {
            color: #3498db;
        }

        .header .badge {
            background: #e74c3c;
            color: white;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            box-shadow: 0 4px 10px rgba(52, 152, 219, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(52, 152, 219, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            color: white;
            box-shadow: 0 4px 10px rgba(46, 204, 113, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(46, 204, 113, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
            color: white;
            box-shadow: 0 4px 10px rgba(149, 165, 166, 0.3);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(149, 165, 166, 0.4);
        }

        .alert {
            background: rgba(219, 108, 52, 0.75);
            border-right: 4px solid #0d9eff;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            color: #ffffff;
        }

        .alert i {
            font-size: 1.5rem;
            color: #060baa;
        }

        .alert a {
            color: #120381;
            font-weight: 600;
            text-decoration: none;
        }

        .alert a:hover {
            text-decoration: underline;
        }

        /* شبكة الإشعارات */
        .notifications-grid {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        /* بطاقة الإشعار */
        .notification-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border-right: 4px solid transparent;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            position: relative;
        }

        .notification-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .notification-card.unread {
            background: #e8f4fd;
            border-right-color: #3498db;
        }

        .notification-card.read {
            background: white;
            border-right-color: #bdc3c7;
            opacity: 0.9;
        }

        /* أيقونة الإشعار */
        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            flex-shrink: 0;
        }

        .notification-icon.info { background: linear-gradient(135deg, #3498db, #2980b9); }
        .notification-icon.success { background: linear-gradient(135deg, #2ecc71, #27ae60); }
        .notification-icon.warning { background: linear-gradient(135deg, #f39c12, #e67e22); }
        .notification-icon.danger { background: linear-gradient(135deg, #e74c3c, #c0392b); }

        /* محتوى الإشعار */
        .notification-content {
            flex: 1;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .notification-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .badge-new {
            background: #e74c3c;
            color: white;
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 50px;
        }

        .notification-message {
            color: #555;
            line-height: 1.6;
            margin-bottom: 10px;
            word-break: break-word;
        }

        .notification-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #7f8c8d;
            font-size: 0.85rem;
        }

        .notification-meta i {
            font-size: 0.8rem;
        }

        /* أزرار الإجراءات */
        .notification-actions {
            display: flex;
            gap: 8px;
            opacity: 0.3;
            transition: opacity 0.3s ease;
        }

        .notification-card:hover .notification-actions {
            opacity: 1;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 8px;
            background: #f8f9fa;
            color: #2c3e50;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 1rem;
        }

        .action-btn:hover {
            transform: translateY(-2px);
        }

        .action-btn.mark-read {
            background: #2ecc71;
            color: white;
        }

        .action-btn.view-link {
            background: #3498db;
            color: white;
        }

        .action-btn.delete {
            background: #e74c3c;
            color: white;
        }

        /* حالة عدم وجود إشعارات */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .empty-state i {
            font-size: 5rem;
            color: #bdc3c7;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #7f8c8d;
            margin-bottom: 25px;
        }

        /* ترقيم الصفحات */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 30px;
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 8px;
            color: #2c3e50;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .page-link:hover {
            background: #3498db;
            color: white;
            transform: translateY(-2px);
        }

        .page-item.active .page-link {
            background: #3498db;
            color: white;
            font-weight: 600;
        }

        .page-item.disabled .page-link {
            opacity: 0.5;
            pointer-events: none;
        }

        /* تحسينات للجوال */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .header-actions {
                justify-content: center;
            }

            .notification-card {
                flex-direction: column;
                align-items: stretch;
            }

            .notification-icon {
                align-self: center;
            }

            .notification-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .notification-actions {
                opacity: 1;
                justify-content: flex-end;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="background-animation">
        <div class="floating-element"></div>
        <div class="floating-element"></div>
        <div class="floating-element"></div>
    </div>

    <div class="container">
        <!-- رأس الصفحة -->
        <div class="header">
            <h1>
                <i class="fas fa-bell"></i>
                الإشعارات
                <?php if ($unread > 0): ?>
                    <span class="badge"><?php echo $unread; ?> جديد</span>
                <?php endif; ?>
            </h1>
            <div class="header-actions">
                <a href="?mark_as_read=all" class="btn btn-success">
                    <i class="fas fa-check-double"></i> تعيين الكل كمقروء
                </a>
                <a href="<?php echo $dashboard_url; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-right"></i> العودة
                </a>
            </div>
        </div>

        <!-- تنبيه بعدد غير المقروء -->
        <?php if ($unread > 0): ?>
            <div class="alert">
                <i class="fas fa-info-circle"></i>
                <div>
                    لديك <strong><?php echo $unread; ?></strong> إشعار غير مقروء. 
                    <a href="?mark_as_read=all">اضغط هنا لتعيين الكل كمقروء</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- قائمة الإشعارات -->
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <h3>لا توجد إشعارات</h3>
                <p>ستظهر هنا جميع إشعارات النظام عند توفرها</p>
                <a href="<?php echo $dashboard_url; ?>" class="btn btn-primary">
                    <i class="fas fa-tachometer-alt"></i> العودة إلى لوحة التحكم
                </a>
            </div>
        <?php else: ?>
            <div class="notifications-grid">
                <?php foreach ($notifications as $notification): 
                    $is_unread = !$notification['is_read'];
                    $icon_type = 'info';
                    $icon_class = 'fas fa-bell';
                    
                    // تحديد نوع الإشعار بناءً على المحتوى
                    if (strpos($notification['title'], 'مستند جديد') !== false) {
                        $icon_type = 'info';
                        $icon_class = 'fas fa-file-alt';
                    } elseif (strpos($notification['title'], 'موافقة') !== false) {
                        $icon_type = 'success';
                        $icon_class = 'fas fa-thumbs-up';
                    } elseif (strpos($notification['title'], 'رفض') !== false) {
                        $icon_type = 'danger';
                        $icon_class = 'fas fa-thumbs-down';
                    } elseif (strpos($notification['title'], 'استكمال') !== false) {
                        $icon_type = 'warning';
                        $icon_class = 'fas fa-check-circle';
                    } elseif (strpos($notification['title'], 'إعادة') !== false) {
                        $icon_type = 'warning';
                        $icon_class = 'fas fa-undo';
                    }
                ?>
                    <div class="notification-card <?php echo $is_unread ? 'unread' : 'read'; ?>">
                        <div class="notification-icon <?php echo $icon_type; ?>">
                            <i class="<?php echo $icon_class; ?>"></i>
                        </div>

                        <div class="notification-content">
                            <div class="notification-header">
                                <div class="notification-title">
                                    <?php if ($is_unread): ?>
                                        <span class="badge-new">جديد</span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($notification['title']); ?>
                                </div>
                                <div class="notification-actions">
                                    <?php if ($is_unread): ?>
                                        <a href="?mark_as_read_id=<?php echo $notification['id']; ?>" class="action-btn mark-read" title="تعيين كمقروء">
                                            <i class="fas fa-check"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($notification['link']): ?>
                                        <a href="<?php echo $notification['link']; ?>" class="action-btn view-link" title="الانتقال">
                                            <i class="fas fa-external-link-alt"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="?delete_id=<?php echo $notification['id']; ?>" class="action-btn delete" title="حذف"
                                       onclick="return confirm('هل تريد حذف هذا الإشعار؟')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>

                            <div class="notification-message">
                                <?php echo nl2br(htmlspecialchars($notification['message'])); ?>
                            </div>

                            <div class="notification-meta">
                                <i class="fas fa-clock"></i>
                                <span><?php echo date('Y-m-d H:i', strtotime($notification['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ترقيم الصفحات (توضيحي فقط، يمكن تطويرها لاحقاً) -->
            <nav aria-label="تصفح الإشعارات">
                <ul class="pagination">
                    <li class="page-item disabled">
                        <a class="page-link" href="#" tabindex="-1">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item">
                        <a class="page-link" href="#">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <script>
        // تحديث الصفحة كل دقيقة للتحقق من الإشعارات الجديدة
        setTimeout(function() {
            window.location.reload();
        }, 60000);
    </script>
</body>
</html>