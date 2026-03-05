<?php
// log.php - نظام تسجيل الإجراءات
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من الصلاحيات (للمسؤولين فقط)
if ($_SESSION['role_name'] !== 'admin') {
    header('Location: ../dashboard/employee_dashboard.php');
    exit();
}

$db = getDB();
$full_name = $_SESSION['full_name'];

// معالجة البحث
$search = $_GET['search'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$action = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// بناء شروط البحث
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(u.full_name LIKE :search OR ul.action LIKE :search2 OR ul.details LIKE :search3)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
    $params[':search3'] = "%{$search}%";
}

if (!empty($user_id)) {
    $where_conditions[] = "ul.user_id = :user_id";
    $params[':user_id'] = $user_id;
}

if (!empty($action)) {
    $where_conditions[] = "ul.action = :action";
    $params[':action'] = $action;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(ul.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(ul.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// جلب السجلات
$query = "
    SELECT ul.*, u.full_name, u.username, r.role_name
    FROM user_logs ul
    LEFT JOIN users u ON ul.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    {$where_sql}
    ORDER BY ul.created_at DESC
    LIMIT 500
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب جميع المستخدمين للفلتر
$users_stmt = $db->query("SELECT id, full_name FROM users ORDER BY full_name");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب جميع الإجراءات للفلتر
$actions_stmt = $db->query("SELECT DISTINCT action FROM user_logs ORDER BY action");
$actions = $actions_stmt->fetchAll(PDO::FETCH_ASSOC);

$user_initials = mb_substr($full_name, 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجلات النظام - نظام التوقيع الإلكتروني</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background-color: #f5f7fa;
        }
        
        .log-card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .log-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .log-badge.success { background-color: #d4edda; color: #155724; }
        .log-badge.error { background-color: #f8d7da; color: #721c24; }
        .log-badge.info { background-color: #d1ecf1; color: #0c5460; }
        .log-badge.warning { background-color: #fff3cd; color: #856404; }
        
        .log-details {
            max-height: 100px;
            overflow-y: auto;
            background-color: #f8f9fa;
            border-radius: 6px;
            padding: 10px;
            font-family: monospace;
            font-size: 0.85rem;
        }
        
        .table th {
            background-color: #2c3e50;
            color: white;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .filter-card {
            background-color: white;
            border-radius: 12px;
            border: 2px solid #2c3e50;
        }
    </style>
</head>
<body>
    <!-- الشريط العلوي -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #2c3e50, #34495e);">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="fas fa-history me-2"></i>
                <span>سجلات النظام</span>
                <small class="ms-2" style="font-size: 0.8rem; opacity: 0.8;">لوحة المسؤول</small>
            </a>
            
            <div class="d-flex align-items-center">
                <a href="../dashboard/dashboard_admin.php" class="btn btn-outline-light btn-sm me-2">
                    <i class="fas fa-arrow-right me-1"></i> العودة
                </a>
                <span class="badge bg-light text-dark me-3"><?php echo count($logs); ?> سجل</span>
            </div>
        </div>
    </nav>

    <!-- المحتوى الرئيسي -->
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="fw-bold text-dark">
                            <i class="fas fa-clipboard-list me-2"></i>سجلات إجراءات النظام
                        </h2>
                        <p class="text-muted mb-0">مراقبة وتتبع جميع إجراءات المستخدمين في النظام</p>
                    </div>
                    
                    <div class="btn-group">
                        <a href="log.php" class="btn btn-secondary">
                            <i class="fas fa-redo me-2"></i> تحديث
                        </a>
                        <button class="btn btn-primary" onclick="exportLogs()">
                            <i class="fas fa-download me-2"></i> تصدير
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- فلاتر البحث -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card filter-card">
                    <div class="card-body">
                        <form method="GET" id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-6 col-lg-3">
                                    <label for="search" class="form-label fw-bold">بحث عام</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                        <input type="text" class="form-control" id="search" name="search" 
                                               placeholder="ابحث في السجلات..." value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-6 col-lg-2">
                                    <label for="user_id" class="form-label fw-bold">المستخدم</label>
                                    <select class="form-select" id="user_id" name="user_id">
                                        <option value="">الكل</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user['id']; ?>" <?php echo $user_id == $user['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($user['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 col-lg-2">
                                    <label for="action" class="form-label fw-bold">الإجراء</label>
                                    <select class="form-select" id="action" name="action">
                                        <option value="">الكل</option>
                                        <?php foreach ($actions as $action_item): ?>
                                            <option value="<?php echo $action_item['action']; ?>" <?php echo $action == $action_item['action'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($action_item['action']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 col-lg-2">
                                    <label for="date_from" class="form-label fw-bold">من تاريخ</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" 
                                           value="<?php echo htmlspecialchars($date_from); ?>">
                                </div>
                                
                                <div class="col-md-6 col-lg-2">
                                    <label for="date_to" class="form-label fw-bold">إلى تاريخ</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" 
                                           value="<?php echo htmlspecialchars($date_to); ?>">
                                </div>
                                
                                <div class="col-md-6 col-lg-1 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-search me-1"></i> بحث
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- جدول السجلات -->
        <div class="row">
            <div class="col-12">
                <div class="card log-card">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>
                            سجلات الإجراءات
                            <span class="badge bg-light text-dark ms-2"><?php echo count($logs); ?></span>
                        </h5>
                        <div class="text-muted small">
                            آخر تحديث: <?php echo date('Y-m-d H:i:s'); ?>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if (empty($logs)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-search fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">لا توجد سجلات مطابقة للبحث</h5>
                            <p class="text-muted mb-4">جرب تغيير معايير البحث</p>
                            <a href="log.php" class="btn btn-primary">
                                <i class="fas fa-redo me-2"></i> عرض الكل
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="50">#</th>
                                        <th width="150">التاريخ والوقت</th>
                                        <th>المستخدم</th>
                                        <th width="120">الدور</th>
                                        <th width="150">الإجراء</th>
                                        <th width="120">عنوان IP</th>
                                        <th>التفاصيل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $index => $log): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo $index + 1; ?></td>
                                        <td>
                                            <div class="fw-bold"><?php echo date('Y-m-d', strtotime($log['created_at'])); ?></div>
                                            <div class="text-muted small"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($log['full_name']); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($log['username']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($log['role_name']); ?></span>
                                        </td>
                                        <td>
                                            <span class="log-badge <?php echo getActionType($log['action']); ?>">
                                                <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></code>
                                        </td>
                                        <td>
                                            <?php if ($log['details']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-info" 
                                                    data-bs-toggle="collapse" data-bs-target="#details-<?php echo $index; ?>">
                                                <i class="fas fa-eye me-1"></i> عرض التفاصيل
                                            </button>
                                            <div class="collapse mt-2" id="details-<?php echo $index; ?>">
                                                <div class="log-details">
                                                    <?php 
                                                    $details = json_decode($log['details'], true);
                                                    if ($details) {
                                                        echo '<pre class="mb-0">' . htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                                                    } else {
                                                        echo htmlspecialchars($log['details']);
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <span class="text-muted">لا توجد تفاصيل</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($logs)): ?>
                    <div class="card-footer text-muted">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                عرض <?php echo count($logs); ?> سجل
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-sm btn-outline-primary" onclick="exportLogs()">
                                    <i class="fas fa-download me-1"></i> تصدير كـ CSV
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="clearOldLogs()">
                                    <i class="fas fa-trash me-1"></i> مسح السجلات القديمة
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // دالة تصدير السجلات
        function exportLogs() {
            // جمع بيانات الفلاتر
            const search = document.getElementById('search').value;
            const userId = document.getElementById('user_id').value;
            const action = document.getElementById('action').value;
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            
            // إنشاء رابط التصدير
            let exportUrl = 'export_logs.php?export=csv';
            if (search) exportUrl += '&search=' + encodeURIComponent(search);
            if (userId) exportUrl += '&user_id=' + userId;
            if (action) exportUrl += '&action=' + encodeURIComponent(action);
            if (dateFrom) exportUrl += '&date_from=' + dateFrom;
            if (dateTo) exportUrl += '&date_to=' + dateTo;
            
            // فتح رابط التصدير
            window.open(exportUrl, '_blank');
        }
        
        // دالة مسح السجلات القديمة
        function clearOldLogs() {
            if (confirm('هل تريد مسح السجلات الأقدم من 30 يوم؟\nهذا الإجراء لا يمكن التراجع عنه.')) {
                fetch('clear_logs.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('تم مسح ' + data.deleted + ' سجل بنجاح');
                            window.location.reload();
                        } else {
                            alert('خطأ: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('حدث خطأ أثناء مسح السجلات');
                    });
            }
        }
        
        // تحديث الصفحة كل 5 دقائق
        setTimeout(function() {
            window.location.reload();
        }, 300000);
        
        // تحسين تجربة المستخدم: تفعيل tooltips
        document.addEventListener('DOMContentLoaded', function() {
            // تفعيل tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // حفظ حالة الفلاتر في localStorage
            const filterForm = document.getElementById('filterForm');
            const formInputs = filterForm.querySelectorAll('input, select');
            
            // تحميل الحالة المحفوظة
            formInputs.forEach(input => {
                const savedValue = localStorage.getItem('log_filter_' + input.name);
                if (savedValue !== null) {
                    input.value = savedValue;
                }
            });
            
            // حفظ الحالة عند التغيير
            formInputs.forEach(input => {
                input.addEventListener('change', function() {
                    localStorage.setItem('log_filter_' + this.name, this.value);
                });
            });
            
            // زر لمسح الحالة المحفوظة
            const clearFiltersBtn = document.createElement('button');
            clearFiltersBtn.type = 'button';
            clearFiltersBtn.className = 'btn btn-sm btn-outline-secondary mt-2';
            clearFiltersBtn.innerHTML = '<i class="fas fa-trash me-1"></i> مسح الفلاتر المحفوظة';
            clearFiltersBtn.onclick = function() {
                formInputs.forEach(input => {
                    localStorage.removeItem('log_filter_' + input.name);
                });
                window.location.href = 'log.php';
            };
            
            filterForm.appendChild(clearFiltersBtn);
        });
    </script>
</body>
</html>

<?php
function getActionType($action) {
    if (strpos($action, 'login') !== false) return 'success';
    if (strpos($action, 'logout') !== false) return 'info';
    if (strpos($action, 'error') !== false) return 'error';
    if (strpos($action, 'create') !== false) return 'success';
    if (strpos($action, 'delete') !== false) return 'danger';
    if (strpos($action, 'update') !== false) return 'warning';
    if (strpos($action, 'approve') !== false) return 'success';
    if (strpos($action, 'reject') !== false) return 'error';
    return 'info';
}
?>