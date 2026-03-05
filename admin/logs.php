<?php
// admin/logs.php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';

// التحقق من أن المستخدم مسؤول
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$pageTitle = 'سجل النشاطات';
$db = getDB();
$user_id = $_SESSION['user_id'];

// معالجة الفلاتر
$search_user = $_GET['user'] ?? '';
$search_action = $_GET['action'] ?? '';
$search_document = $_GET['document_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// إعداد الاستعلام
$where_conditions = [];
$params = [];

if (!empty($search_user)) {
    $where_conditions[] = '(username LIKE :user OR full_name LIKE :user)';
    $params[':user'] = "%$search_user%";
}
if (!empty($search_action)) {
    $where_conditions[] = 'action LIKE :action';
    $params[':action'] = "%$search_action%";
}
if (!empty($search_document)) {
    $where_conditions[] = 'document_id = :doc_id';
    $params[':doc_id'] = $search_document;
}
if (!empty($date_from)) {
    $where_conditions[] = 'DATE(created_at) >= :date_from';
    $params[':date_from'] = $date_from;
}
if (!empty($date_to)) {
    $where_conditions[] = 'DATE(created_at) <= :date_to';
    $params[':date_to'] = $date_to;
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// الترقيم
$records_per_page = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

// عدد السجلات الكلي
$count_query = "SELECT COUNT(*) as total FROM user_activity_logs $where_sql";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// جلب السجلات
$query = "
    SELECT * FROM user_activity_logs
    $where_sql
    ORDER BY created_at DESC
    LIMIT $records_per_page OFFSET $offset
";
$stmt = $db->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        /* تنسيقات إضافية للصفحة */
        .logs-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .logs-table th {
            background: #2c3e50;
            color: white;
            padding: 12px;
            font-weight: 500;
        }
        .logs-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .logs-table tr:hover {
            background: #f8f9fa;
        }
        .badge-action {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
            background: #e9ecef;
            color: #2c3e50;
        }
        .badge-login { background: #27ae60; color: white; }
        .badge-logout { background: #95a5a6; color: white; }
        .badge-upload { background: #3498db; color: white; }
        .badge-delete { background: #e74c3c; color: white; }
        .badge-update { background: #f39c12; color: white; }
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 15px;
        }
        .details-btn {
            background: none;
            border: none;
            color: #3498db;
            cursor: pointer;
            font-size: 1.2rem;
        }
        .modal-content {
            max-width: 600px;
        }
        .json-view {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            direction: ltr;
            text-align: left;
            font-family: monospace;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 300px;
            overflow-y: auto;
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
        <?php include '../includes/header.php'; ?>

        <div style="margin: 20px 0;">
            <h1 style="color: #2c3e50;"><i class="fas fa-history"></i> سجل النشاطات</h1>
        </div>

        <!-- قسم التصفية -->
        <div class="filter-section">
            <form method="GET">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-user"></i> المستخدم</label>
                        <input type="text" name="user" class="form-control" value="<?php echo htmlspecialchars($search_user); ?>" placeholder="اسم المستخدم أو الاسم الكامل">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-tag"></i> الإجراء</label>
                        <input type="text" name="action" class="form-control" value="<?php echo htmlspecialchars($search_action); ?>" placeholder="مثل LOGIN, UPLOAD">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-file"></i> رقم المستند</label>
                        <input type="number" name="document_id" class="form-control" value="<?php echo htmlspecialchars($search_document); ?>" placeholder="معرف المستند">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> من تاريخ</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> إلى تاريخ</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> بحث
                    </button>
                    <a href="logs.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> إعادة تعيين
                    </a>
                </div>
            </form>
        </div>

        <!-- عرض السجلات -->
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <h4>لا توجد سجلات</h4>
                <p>لم يتم العثور على أي نشاط مطابق لمعايير البحث.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المستخدم</th>
                            <th>الإجراء</th>
                            <th>الوصف</th>
                            <th>رقم المستند</th>
                            <th>IP</th>
                            <th>التاريخ</th>
                            <th>تفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $index => $log): 
                            $action_class = '';
                            if (strpos($log['action'], 'LOGIN') !== false) $action_class = 'badge-login';
                            elseif (strpos($log['action'], 'LOGOUT') !== false) $action_class = 'badge-logout';
                            elseif (strpos($log['action'], 'UPLOAD') !== false) $action_class = 'badge-upload';
                            elseif (strpos($log['action'], 'DELETE') !== false) $action_class = 'badge-delete';
                            elseif (strpos($log['action'], 'UPDATE') !== false || strpos($log['action'], 'CHANGE') !== false) $action_class = 'badge-update';
                        ?>
                        <tr>
                            <td><?php echo $offset + $index + 1; ?></td>
                            <td>
                                <div><strong><?php echo htmlspecialchars($log['full_name'] ?: $log['username']); ?></strong></div>
                                <small style="color: #7f8c8d;"><?php echo htmlspecialchars($log['role_name']); ?></small>
                            </td>
                            <td>
                                <span class="badge-action <?php echo $action_class; ?>">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($log['description']); ?></td>
                            <td>
                                <?php if ($log['document_id']): ?>
                                    <a href="../documents/view_document.php?id=<?php echo $log['document_id']; ?>" target="_blank">
                                        <?php echo $log['document_id']; ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($log['created_at'])); ?></td>
                            <td>
                                <?php if (!empty($log['additional_data']) && $log['additional_data'] !== 'null'): ?>
                                    <button class="details-btn" onclick="showDetails(<?php echo htmlspecialchars(json_encode($log['additional_data']), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- الترقيم -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        عرض <?php echo count($logs); ?> من أصل <?php echo $total_records; ?> سجل | الصفحة <?php echo $page; ?> من <?php echo $total_pages; ?>
                    </div>
                    <ul class="pagination">
                        <?php
                        function buildPaginationUrl($page_num) {
                            $params = $_GET;
                            $params['page'] = $page_num;
                            return '?' . http_build_query($params);
                        }
                        ?>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo buildPaginationUrl($page - 1); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        if ($start_page > 1) {
                            echo '<li class="page-item"><a class="page-link" href="' . buildPaginationUrl(1) . '">1</a></li>';
                            if ($start_page > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        for ($i = $start_page; $i <= $end_page; $i++) {
                            $active_class = ($i == $page) ? 'active' : '';
                            echo '<li class="page-item ' . $active_class . '">';
                            echo '<a class="page-link" href="' . buildPaginationUrl($i) . '">' . $i . '</a>';
                            echo '</li>';
                        }
                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            echo '<li class="page-item"><a class="page-link" href="' . buildPaginationUrl($total_pages) . '">' . $total_pages . '</a></li>';
                        }
                        ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo buildPaginationUrl($page + 1); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- مودال عرض التفاصيل الإضافية -->
    <div id="detailsModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle"></i> تفاصيل إضافية</h3>
                <button onclick="closeDetailsModal()" style="background: none; border: none; color: white; font-size: 1.5rem;">&times;</button>
            </div>
            <div class="modal-body">
                <pre id="detailsContent" class="json-view"></pre>
            </div>
            <div class="modal-footer">
                <button onclick="closeDetailsModal()" class="btn btn-secondary">إغلاق</button>
            </div>
        </div>
    </div>

    <script>
        function showDetails(data) {
            let parsed;
            if (typeof data === 'string') {
                try {
                    parsed = JSON.parse(data);
                } catch (e) {
                    parsed = data;
                }
            } else {
                parsed = data;
            }
            document.getElementById('detailsContent').textContent = JSON.stringify(parsed, null, 2);
            document.getElementById('detailsModal').style.display = 'flex';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // إغلاق المودال عند النقر خارج المحتوى
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('detailsModal');
            if (e.target === modal) {
                closeDetailsModal();
            }
        });
    </script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html>