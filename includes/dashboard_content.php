<?php
// [file name]: includes/dashboard_content.php
/**
 * محتوى الداشبورد - جدول وبطاقات المستندات
 */

function displayDocumentsContent($documents, $view_type = 'table') {
    ?>
    <div class="documents-content">
        <!-- عرض الجدول -->
        <div id="documents-table" class="documents-table-view" style="<?php echo $view_type === 'table' ? 'display: block;' : 'display: none;'; ?>">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th width="50">#</th>
                            <th>المستند</th>
                            <th>المرسل</th>
                            <th>القسم</th>
                            <th>الحالة</th>
                            <th>الأولوية</th>
                            <th>التوقيعات</th>
                            <th>التعليقات</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($documents)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <h5>لا توجد مستندات</h5>
                                    <p class="text-muted">لم يتم العثور على مستندات تطابق معايير البحث</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($documents as $index => $doc): ?>
                            <tr class="document-row" data-id="<?php echo $doc['id']; ?>">
                                <td class="text-center fw-bold"><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="document-title">
                                        <a href="view_document.php?id=<?php echo $doc['id']; ?>" class="text-decoration-none">
                                            <strong><?php echo htmlspecialchars($doc['title']); ?></strong>
                                        </a>
                                    </div>
                                    <div class="document-desc text-muted">
                                        <?php echo htmlspecialchars(mb_substr($doc['description'] ?: 'لا يوجد وصف', 0, 60, 'UTF-8')); ?>...
                                    </div>
                                </td>
                                <td>
                                    <div class="creator-info">
                                        <div class="fw-bold"><?php echo htmlspecialchars($doc['creator_name']); ?></div>
                                        <small class="text-muted"><?php echo date('Y-m-d', strtotime($doc['created_at'])); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <span class="department-badge"><?php echo htmlspecialchars($doc['department_name']); ?></span>
                                </td>
                                <td>
                                    <?php displayStatusBadge($doc['current_status']); ?>
                                </td>
                                <td>
                                    <?php displayPriorityBadge($doc['priority']); ?>
                                </td>
                                <td class="text-center">
                                    <span class="signature-count"><?php echo $doc['signatures_count']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="comments-count"><?php echo $doc['comments_count'] ?? 0; ?></span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view_document.php?id=<?php echo $doc['id']; ?>" class="btn-action btn-view" title="عرض">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($doc['current_status'] === 'pending'): ?>
                                            <a href="sign_document.php?id=<?php echo $doc['id']; ?>" class="btn-action btn-sign" title="توقيع">
                                                <i class="fas fa-signature"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="track_document.php?id=<?php echo $doc['id']; ?>" class="btn-action btn-track" title="تتبع">
                                            <i class="fas fa-project-diagram"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- ترقيم الصفحات -->
            <?php if (count($documents) > 0): ?>
            <div class="table-footer mt-3">
                <div class="row">
                    <div class="col-md-6">
                        <div class="pagination-info">
                            عرض <strong>1-<?php echo count($documents); ?></strong> من <strong><?php echo count($documents); ?></strong> مستند
                        </div>
                    </div>
                    <div class="col-md-6">
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-end">
                                <li class="page-item disabled">
                                    <a class="page-link" href="#" tabindex="-1">السابق</a>
                                </li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item"><a class="page-link" href="#">2</a></li>
                                <li class="page-item"><a class="page-link" href="#">3</a></li>
                                <li class="page-item">
                                    <a class="page-link" href="#">التالي</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- عرض البطاقات -->
        <div id="documents-cards" class="documents-cards-view" style="<?php echo $view_type === 'cards' ? 'display: block;' : 'display: none;'; ?>">
            <?php if (empty($documents)): ?>
            <div class="empty-state text-center py-5">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <h5>لا توجد مستندات</h5>
                <p class="text-muted">لم يتم العثور على مستندات تطابق معايير البحث</p>
            </div>
            <?php else: ?>
            <div class="row">
                <?php foreach ($documents as $doc): ?>
                <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
                    <div class="document-card">
                        <div class="card-header">
                            <div class="document-title">
                                <h6><?php echo htmlspecialchars($doc['title']); ?></h6>
                            </div>
                            <div class="document-actions">
                                <?php displayPriorityBadge($doc['priority'], true); ?>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <div class="document-desc">
                                <?php echo htmlspecialchars(mb_substr($doc['description'] ?: 'لا يوجد وصف', 0, 100, 'UTF-8')); ?>
                            </div>
                            
                            <div class="document-meta">
                                <div class="meta-item">
                                    <i class="fas fa-user"></i>
                                    <span><?php echo htmlspecialchars($doc['creator_name']); ?></span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-building"></i>
                                    <span><?php echo htmlspecialchars($doc['department_name']); ?></span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-calendar"></i>
                                    <span><?php echo date('Y-m-d', strtotime($doc['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-footer">
                            <div class="document-status">
                                <?php displayStatusBadge($doc['current_status']); ?>
                            </div>
                            
                            <div class="document-stats">
                                <div class="stat">
                                    <i class="fas fa-signature"></i>
                                    <span><?php echo $doc['signatures_count']; ?></span>
                                </div>
                                <div class="stat">
                                    <i class="fas fa-comment"></i>
                                    <span><?php echo $doc['comments_count'] ?? 0; ?></span>
                                </div>
                            </div>
                            
                            <div class="document-actions">
                                <a href="view_document.php?id=<?php echo $doc['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($doc['current_status'] === 'pending'): ?>
                                    <a href="sign_document.php?id=<?php echo $doc['id']; ?>" class="btn btn-sm btn-success">
                                        <i class="fas fa-signature"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <style>
        /* أنماط الجدول */
        .documents-table-view table {
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .documents-table-view thead {
            background: linear-gradient(135deg, #2c3e50, #34495e);
        }
        
        .documents-table-view th {
            color: white;
            font-weight: 600;
            border: none;
            padding: 15px;
        }
        
        .documents-table-view td {
            padding: 12px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
        }
        
        .documents-table-view tr:hover {
            background-color: #f8f9fa;
        }
        
        .document-title a {
            color: #2c3e50;
            font-weight: 600;
        }
        
        .document-title a:hover {
            color: #3498db;
        }
        
        .document-desc {
            font-size: 0.85rem;
            margin-top: 3px;
        }
        
        .department-badge {
            background: #e9ecef;
            color: #495057;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .signature-count, .comments-count {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #f8f9fa;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-action {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-action:hover {
            transform: scale(1.1);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .btn-view { background: #3498db; }
        .btn-sign { background: #2ecc71; }
        .btn-track { background: #9b59b6; }
        
        .table-footer {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
        }
        
        .pagination-info {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        /* أنماط البطاقات */
        .documents-cards-view .document-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            height: 100%;
            transition: all 0.3s ease;
        }
        
        .documents-cards-view .document-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .document-card .card-header {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .document-card .card-body {
            padding: 20px;
        }
        
        .document-card .card-footer {
            padding: 15px;
            border-top: 1px solid #e9ecef;
            border-radius: 0 0 15px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .document-meta {
            margin-top: 15px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .document-stats {
            display: flex;
            gap: 15px;
        }
        
        .document-stats .stat {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #6c757d;
        }
        
        /* أنماط الحالة والأولوية المشتركة */
        .status-badge, .priority-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-review { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-draft { background: #e9ecef; color: #6c757d; }
        
        .priority-low { background: #d4edda; color: #155724; }
        .priority-medium { background: #fff3cd; color: #856404; }
        .priority-high { background: #f8d7da; color: #721c24; }
        .priority-urgent { background: #e74c3c; color: white; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .documents-table-view th, 
            .documents-table-view td {
                padding: 10px 8px;
                font-size: 0.85rem;
            }
            
            .action-buttons .btn-action {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
            }
            
            .table-footer {
                padding: 10px;
            }
            
            .document-card .card-header,
            .document-card .card-body,
            .document-card .card-footer {
                padding: 12px;
            }
        }
        
        @media (max-width: 576px) {
            .documents-table-view {
                font-size: 0.8rem;
            }
            
            .btn-action {
                width: 28px;
                height: 28px;
            }
            
            .document-stats {
                gap: 10px;
            }
        }
    </style>
    
    <script>
        // تحديث تلقائي للجدول كل دقيقة
        function refreshDocuments() {
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newTable = doc.querySelector('.documents-table-view');
                    const newCards = doc.querySelector('.documents-cards-view');
                    
                    if (newTable) {
                        document.querySelector('.documents-table-view').innerHTML = newTable.innerHTML;
                    }
                    
                    if (newCards) {
                        document.querySelector('.documents-cards-view').innerHTML = newCards.innerHTML;
                    }
                    
                    // إعادة إرفاق الأحداث
                    reattachEvents();
                })
                .catch(error => console.error('Error refreshing documents:', error));
        }
        
        // تحديث كل 60 ثانية
        setInterval(refreshDocuments, 60000);
        
        function reattachEvents() {
            // إعادة إرفاق الأحداث إذا لزم الأمر
        }
    </script>
    <?php
}

// دالة لعرض حالة المستند
function displayStatusBadge($status, $is_card = false) {
    $statuses = [
        'draft' => ['label' => 'مسودة', 'class' => 'status-draft'],
        'under_review' => ['label' => 'قيد المراجعة', 'class' => 'status-review'],
        'pending' => ['label' => 'قيد الانتظار', 'class' => 'status-pending'],
        'completed' => ['label' => 'مكتملة', 'class' => 'status-completed']
    ];
    
    $config = $statuses[$status] ?? ['label' => $status, 'class' => 'status-draft'];
    
    echo '<span class="status-badge ' . $config['class'] . '">' . $config['label'] . '</span>';
}

// دالة لعرض أولوية المستند
function displayPriorityBadge($priority, $is_card = false) {
    $priorities = [
        'low' => ['label' => 'منخفضة', 'class' => 'priority-low'],
        'medium' => ['label' => 'متوسطة', 'class' => 'priority-medium'],
        'high' => ['label' => 'عالية', 'class' => 'priority-high'],
        'urgent' => ['label' => 'عاجلة', 'class' => 'priority-urgent']
    ];
    
    $config = $priorities[$priority] ?? ['label' => $priority, 'class' => 'priority-medium'];
    
    if ($is_card) {
        echo '<span class="priority-badge ' . $config['class'] . '" title="' . $config['label'] . '">
                <i class="fas fa-flag"></i>
              </span>';
    } else {
        echo '<span class="priority-badge ' . $config['class'] . '">' . $config['label'] . '</span>';
    }
}
?>