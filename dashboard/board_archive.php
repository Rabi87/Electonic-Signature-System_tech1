<?php
/**
 * صفحة عرض الأرشيف الشخصي - تعرض النسخ المؤرشفة (snapshots)
 */

require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

$pageTitle = 'أرشيفي الشخصي';
$db = getDB();
$user_id = $_SESSION['user_id'];
$role_name = $_SESSION['role_name'] ?? '';

$allowed_roles = ['board', 'sub_board', 'private_board'];
if (!in_array($role_name, $allowed_roles)) {
    header("Location: ../login.php");
    exit();
}

// معالجة معاملات GET
$search = $_GET['search'] ?? '';
$document_type = $_GET['document_type'] ?? '';
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'archived_at';
$sort_order = $_GET['sort_order'] ?? 'DESC';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// استعلام أساسي مع archived_file_path
$query = "
    SELECT 
        ua.id as archive_id,
        d.*, 
        ua.archived_at as user_archived_at, 
        ua.priority as archive_priority,
        ua.archived_file_path,
        creator.full_name as creator_name,
        holder.full_name as current_holder_name,
        (SELECT fv.value_data 
         FROM document_fields df 
         LEFT JOIN field_values fv ON df.id = fv.field_id 
         WHERE df.document_id = d.id 
           AND df.field_type = 'text'
           AND fv.value_data IS NOT NULL
           AND TRIM(fv.value_data) != ''
         ORDER BY fv.created_at DESC 
         LIMIT 1) as public_number,
        (SELECT fv.value_data 
         FROM document_fields df 
         LEFT JOIN field_values fv ON df.id = fv.field_id 
         WHERE df.document_id = d.id 
           AND df.field_type = 'text'
           AND fv.value_data IS NOT NULL
           AND TRIM(fv.value_data) != ''
         ORDER BY fv.created_at ASC 
         LIMIT 1) as private_number
    FROM user_archives ua
    INNER JOIN documents d ON ua.document_id = d.id
    LEFT JOIN users creator ON d.created_by = creator.id
    LEFT JOIN users holder ON d.current_holder_id = holder.id
    WHERE ua.user_id = :user_id
";

$params = [':user_id' => $user_id];

// تطبيق الفلاتر
if (!empty($search)) {
    $query .= " AND (d.title LIKE :search OR d.description LIKE :search OR creator.full_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}
// ... باقي الفلاتر كما هي (يمكنك إضافتها حسب رغبتك)

$query .= " ORDER BY ua.archived_at $sort_order LIMIT :limit OFFSET :offset";
$params[':limit'] = $records_per_page;
$params[':offset'] = $offset;

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    if ($key === ':limit' || $key === ':offset') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// باقي الكود للإحصائيات والترقيم (مثل الملف الأصلي)
$countQuery = "SELECT COUNT(*) as total FROM user_archives WHERE user_id = :user_id";
$countStmt = $db->prepare($countQuery);
$countStmt->execute([':user_id' => $user_id]);
$total_documents = $countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$total_pages = ceil($total_documents / $records_per_page);

// إحصائيات سريعة
$statsQuery = "
    SELECT 
        COUNT(*) as total_archived,
        SUM(CASE WHEN d.created_by = :user_id THEN 1 ELSE 0 END) as outgoing_archived,
        SUM(CASE WHEN d.created_by != :user_id THEN 1 ELSE 0 END) as incoming_archived
    FROM user_archives ua
    JOIN documents d ON ua.document_id = d.id
    WHERE ua.user_id = :user_id
";
$statsStmt = $db->prepare($statsQuery);
$statsStmt->execute([':user_id' => $user_id]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$viewMode = $_GET['view'] ?? 'list';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .restore-btn {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: 0.3s;
        }
        .restore-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 204, 113, 0.3);
        }
        .archived-badge {
            background: #9b59b6;
            color: white;
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 12px;
            margin-right: 5px;
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

        <!-- شريط العودة والإحصائيات -->
        <div class="archive-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap;">
            <div>
                <a href="<?php echo $role_name; ?>_dashboard.php" class="btn" style="background: #441088; color: white; padding: 8px 20px; border-radius: 8px; text-decoration: none;">
                    <i class="fas fa-arrow-right"></i> العودة للوحة التحكم
                </a>
            </div>
            <div style="display: flex; gap: 20px;">
                <div class="stat-badge" style="background: #f39c12; color: white; padding: 5px 15px; border-radius: 20px;">
                    <i class="fas fa-archive"></i> إجمالي المؤرشف: <?php echo $stats['total_archived'] ?? 0; ?>
                </div>
                <div class="stat-badge" style="background: #3498db; color: white; padding: 5px 15px; border-radius: 20px;">
                    <i class="fas fa-paper-plane"></i> صادر: <?php echo $stats['outgoing_archived'] ?? 0; ?>
                </div>
                <div class="stat-badge" style="background: #2ecc71; color: white; padding: 5px 15px; border-radius: 20px;">
                    <i class="fas fa-inbox"></i> وارد: <?php echo $stats['incoming_archived'] ?? 0; ?>
                </div>
            </div>
        </div>

        <!-- قسم الفلاتر (اختصاراً) -->
        <div class="advanced-filters-section" style="margin-bottom: 20px;">
            <div class="filters-header">
                <h3><i class="fas fa-filter"></i> بحث متقدم في الأرشيف</h3>
            </div>
            <div class="filters-content">
                <form method="GET" id="filterForm">
                    <!-- حقول البحث (كما كانت) -->
                </form>
            </div>
        </div>

        <!-- حقل البحث الفوري -->
        <div class="instant-search-container">
            <input type="text" id="instantSearchInput" class="form-control instant-search-input"
                placeholder="ابحث فوراً في الأرشيف (عنوان، وصف، مرسل، رقم...)">
            <div class="searching-indicator"><i class="fas fa-search"></i></div>
            <div class="searching-indicator" id="searchSpinner" style="display: none;"><i class="fas fa-spinner fa-spin"></i></div>
        </div>
        <div id="instantSearchInfo"><span id="resultsCount"><?php echo count($documents); ?></span> نتيجة</div>

        <!-- أزرار تبديل طريقة العرض -->
        <div class="view-toggle" style="text-align: center; margin: 20px 0;">
            <button class="view-toggle-btn <?php echo $viewMode == 'list' ? 'active' : ''; ?>" onclick="changeViewMode('list')">
                <i class="fas fa-list"></i> قائمة
            </button>
            <button class="view-toggle-btn <?php echo $viewMode == 'cards' ? 'active' : ''; ?>" onclick="changeViewMode('cards')">
                <i class="fas fa-th-large"></i> بطاقات
            </button>
        </div>

        <?php if (empty($documents)): ?>
            <div class="empty-state">
                <i class="fas fa-archive" style="font-size: 4rem; color: #95a5a6;"></i>
                <h4>لا توجد مستندات مؤرشفة</h4>
                <p>لم تقم بأرشفة أي مستند بعد.</p>
                <a href="<?php echo $role_name; ?>_dashboard.php" class="btn" style="background: #441088; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
                    <i class="fas fa-arrow-right"></i> العودة للوحة التحكم
                </a>
            </div>
        <?php else: ?>

            <!-- عرض البطاقات -->
            <?php if ($viewMode == 'cards'): ?>
                <div class="documents-grid">
                    <?php foreach ($documents as $doc):
                        $doc_type = ($doc['created_by'] == $user_id) ? 'صادر' : 'وارد';
                        $archiveFile = $doc['archived_file_path'] ?? '';
                    ?>
                        <div class="document-card" style="border-right: 4px solid #9b59b6;">
                            <div class="type-ribbon" style="background: #9b59b6;">
                                <i class="fas fa-archive"></i> نسخة مؤرشفة
                            </div>
                            <div class="document-header">
                                <div class="document-icon">
                                    <?php
                                    if ($doc['priority'] == 'urgent') echo '🔒';
                                    elseif ($doc['priority'] == 'high') echo '🔥';
                                    else echo '📄';
                                    ?>
                                </div>
                                <h3><?php echo htmlspecialchars($doc['title']); ?></h3>
                            </div>
                            <div class="document-body">
                                <div class="document-info">
                                    <div class="info-item"><span class="info-label">المرسل:</span> <?php echo htmlspecialchars($doc['creator_name']); ?></div>
                                    <div class="info-item"><span class="info-label">تاريخ الأرشفة:</span> <?php echo date('Y-m-d', strtotime($doc['user_archived_at'] ?? $doc['created_at'])); ?></div>
                                    <div class="info-item"><span class="info-label">الأولوية:</span>
                                        <?php
                                        $priority_class = $doc['priority'] == 'urgent' ? 'urgent' : ($doc['priority'] == 'high' ? 'high' : 'normal');
                                        ?>
                                        <span class="priority-badge priority-<?php echo $priority_class; ?>">
                                            <?php echo $doc['priority'] == 'urgent' ? 'سري' : ($doc['priority'] == 'high' ? 'عاجل' : 'عادي'); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="document-actions" style="justify-content: center;">
                                    <?php if (!empty($archiveFile)): ?>
                                        <button class="card-btn view" onclick="window.open('view_archived.php?id=<?php echo $doc['archive_id']; ?>', '_blank')" title="عرض النسخة المؤرشفة">
                                            <i class="fas fa-eye"></i> عرض
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">(لا يوجد ملف)</span>
                                    <?php endif; ?>
                                    <button class="restore-btn" onclick="restoreDocument(<?php echo $doc['id']; ?>, '<?php echo addslashes($doc['title']); ?>')" title="استعادة من الأرشيف">
                                        <i class="fas fa-undo-alt"></i> استعادة
                                    </button>
                                </div>
                                <div style="margin-top: 10px; font-size: 0.8rem; color: #9b59b6; text-align: center;">
                                    <i class="fas fa-camera"></i> لقطة ثابتة وقت الأرشفة
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <!-- عرض الجدول -->
            <?php else: ?>
                <div class="documents-table-container">
                    <div class="documents-header">
                        <h3><i class="fas fa-archive"></i> قائمة المستندات المؤرشفة</h3>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th>الرقم</th>
                                    <th>المستند</th>
                                    <th>المرسل</th>
                                    <th>الحالة</th>
                                    <th>الأولوية</th>
                                    <th>تاريخ الأرشفة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc):
                                    $doc_type = ($doc['created_by'] == $user_id) ? 'صادر' : 'وارد';
                                    $archiveFile = $doc['archived_file_path'] ?? '';
                                ?>
                                    <tr>
                                        <td><?php echo $doc['archive_id']; ?></td>
                                        <td>
                                            <a href="view_archived.php?id=<?php echo $doc['archive_id']; ?>" target="_blank">
                                                <span class="document-title"><?php echo htmlspecialchars($doc['title']); ?></span>
                                                <span class="document-type" style="background: #9b59b6;">
                                                    <i class="fas fa-archive"></i> نسخة مؤرشفة
                                                </span>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($doc['creator_name']); ?></td>
                                        <td>
                                            <?php
                                            $status_labels = [
                                                'draft' => 'مسودة',
                                                'under_review' => 'قيد المراجعة',
                                                'pending' => 'قيد الانتظار',
                                                'completed' => 'مكتملة',
                                                'rejected' => 'مرفوض',
                                                'approved' => 'موافق عليها'
                                            ];
                                            $status_text = $status_labels[$doc['current_status']] ?? $doc['current_status'];
                                            ?>
                                            <span class="status-badge status-<?php echo $doc['current_status']; ?>"><?php echo $status_text; ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $priority_labels = ['normal' => 'عادي', 'high' => 'عاجل', 'urgent' => 'سري'];
                                            $priority_class = $doc['priority'] == 'urgent' ? 'urgent' : ($doc['priority'] == 'high' ? 'high' : 'normal');
                                            ?>
                                            <span class="priority-badge priority-<?php echo $priority_class; ?>">
                                                <?php echo $priority_labels[$doc['priority']] ?? $doc['priority']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('Y-m-d', strtotime($doc['user_archived_at'] ?? $doc['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if (!empty($archiveFile)): ?>
                                                    <a href="view_archived.php?id=<?php echo $doc['archive_id']; ?>" target="_blank" class="employee-btn view" title="عرض النسخة المؤرشفة">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <button onclick="restoreDocument(<?php echo $doc['id']; ?>, '<?php echo addslashes($doc['title']); ?>')" class="employee-btn" style="background: #2ecc71; color: white;" title="استعادة">
                                                    <i class="fas fa-undo-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- الترقيم -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-container">
                            <ul class="pagination">
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- مودال تأكيد الاستعادة -->
    <div id="restoreConfirmModal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #2ecc71, #27ae60);">
                <h3><i class="fas fa-undo-alt"></i> تأكيد الاستعادة</h3>
                <button type="button" onclick="closeRestoreModal()" style="background: none; border: none; color: white; font-size: 1.5rem;">&times;</button>
            </div>
            <div class="modal-body" style="padding: 25px; text-align: center;">
                <i class="fas fa-question-circle" style="font-size: 4rem; color: #2ecc71; margin-bottom: 15px;"></i>
                <p style="font-size: 1.1rem;">هل أنت متأكد من استعادة هذا المستند من الأرشيف؟</p>
                <p style="color: #7f8c8d;" id="restoreDocumentTitle"></p>
                <p style="font-size: 0.9rem; color: #e74c3c;">ملاحظة: استعادة المستند تعني إزالة النسخة المؤرشفة من قائمتك. قد تبقى النسخة على الخادم إذا كان مستخدمون آخرون قد أرشفوه.</p>
                <div style="display: flex; gap: 15px; justify-content: center; margin-top: 20px;">
                    <button onclick="proceedRestore()" class="btnx" style="background: #2ecc71; color: white; padding: 10px 30px;">تأكيد</button>
                    <button onclick="closeRestoreModal()" class="btnx btn-secondary" style="background: #95a5a6;">إلغاء</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notifications Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        let currentRestoreId = null;

        function changeViewMode(mode) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', mode);
            window.location.href = url.toString();
        }

        function restoreDocument(id, title) {
            currentRestoreId = id;
            document.getElementById('restoreDocumentTitle').innerText = title || 'المستند رقم ' + id;
            document.getElementById('restoreConfirmModal').style.display = 'flex';
        }

        function closeRestoreModal() {
            document.getElementById('restoreConfirmModal').style.display = 'none';
            currentRestoreId = null;
        }

        function proceedRestore() {
            if (!currentRestoreId) return;
            fetch('restore_board_document.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'document_id=' + currentRestoreId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('success', data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('error', data.message);
                }
                closeRestoreModal();
            })
            .catch(error => {
                showToast('error', 'حدث خطأ في الاتصال');
                closeRestoreModal();
            });
        }

        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast toast-' + type;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        // البحث الفوري
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('instantSearchInput');
            const resultsSpan = document.getElementById('resultsCount');
            const searchSpinner = document.getElementById('searchSpinner');
            const viewMode = '<?php echo $viewMode; ?>';

            if (!searchInput) return;

            const getItems = () => {
                if (viewMode === 'cards') {
                    return document.querySelectorAll('.document-card');
                } else {
                    return document.querySelectorAll('.documents-table tbody tr');
                }
            };

            const performSearch = () => {
                const searchTerm = searchInput.value.trim().toLowerCase();
                const items = getItems();
                let visibleCount = 0;

                if (searchSpinner) searchSpinner.style.display = 'block';

                items.forEach(item => {
                    const searchableData = item.getAttribute('data-searchable');
                    if (!searchableData) {
                        item.style.display = '';
                        visibleCount++;
                        return;
                    }
                    try {
                        const data = JSON.parse(searchableData);
                        const searchableText = Object.values(data).join(' ').toLowerCase();
                        const matches = searchTerm === '' || searchableText.includes(searchTerm);
                        if (matches) {
                            item.style.display = '';
                            visibleCount++;
                        } else {
                            item.style.display = 'none';
                        }
                    } catch (e) {
                        console.error('خطأ في تحليل data-searchable', e);
                        item.style.display = '';
                        visibleCount++;
                    }
                });

                if (resultsSpan) resultsSpan.textContent = visibleCount;
                if (searchSpinner) searchSpinner.style.display = 'none';
            };

            searchInput.addEventListener('input', performSearch);
            performSearch();
        });
    </script>
</body>
</html>