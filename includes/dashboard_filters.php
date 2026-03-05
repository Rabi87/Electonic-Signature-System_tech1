<?php
// [file name]: includes/dashboard_filters.php
/**
 * فلاتر البحث والتصفية للداشبورد
 */

function displayDashboardFilters($search = '', $status = '', $priority = '', $date_from = '', $date_to = '', $users = []) {
    ?>
    <div class="filters-card mb-4">
        <div class="filters-header">
            <h5><i class="fas fa-filter me-2"></i>تصفية المستندات</h5>
            <div class="view-toggle">
                <button type="button" class="btn-view-toggle active" data-view="table">
                    <i class="fas fa-table"></i> جدول
                </button>
                <button type="button" class="btn-view-toggle" data-view="cards">
                    <i class="fas fa-th-large"></i> بطاقات
                </button>
            </div>
        </div>
        
        <form method="GET" id="filterForm" class="filters-form">
            <div class="row g-3">
                <!-- البحث -->
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="filter-group">
                        <label><i class="fas fa-search me-1"></i> بحث</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="عنوان، وصف، مرسل..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                
                <!-- الحالة -->
                <div class="col-xl-2 col-lg-3 col-md-6">
                    <div class="filter-group">
                        <label><i class="fas fa-tasks me-1"></i> الحالة</label>
                        <select name="status" class="form-select">
                            <option value="">جميع الحالات</option>
                            <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>مسودة</option>
                            <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>قيد المراجعة</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>مكتملة</option>
                        </select>
                    </div>
                </div>
                
                <!-- الأولوية -->
                <div class="col-xl-2 col-lg-3 col-md-6">
                    <div class="filter-group">
                        <label><i class="fas fa-flag me-1"></i> الأولوية</label>
                        <select name="priority" class="form-select">
                            <option value="">جميع الأولويات</option>
                            <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>منخفضة</option>
                            <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>متوسطة</option>
                            <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>عالية</option>
                            <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>عاجلة</option>
                        </select>
                    </div>
                </div>
                
                <!-- التاريخ من -->
                <div class="col-xl-2 col-lg-3 col-md-6">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt me-1"></i> من تاريخ</label>
                        <input type="date" name="date_from" class="form-control" 
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                </div>
                
                <!-- التاريخ إلى -->
                <div class="col-xl-2 col-lg-3 col-md-6">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt me-1"></i> إلى تاريخ</label>
                        <input type="date" name="date_to" class="form-control" 
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                </div>
                
                <!-- أزرار التحكم -->
                <div class="col-xl-1 col-lg-3 col-md-6">
                    <div class="filter-buttons">
                        <button type="submit" class="btn btn-primary btn-filter" title="تطبيق الفلاتر">
                            <i class="fas fa-check"></i>
                            <span class="d-none d-md-inline">تطبيق</span>
                        </button>
                        <button type="button" onclick="resetFilters()" class="btn btn-secondary btn-filter" title="إعادة تعيين">
                            <i class="fas fa-redo"></i>
                        </button>
                        <button type="button" onclick="exportResults()" class="btn btn-success btn-filter" title="تصدير">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- فلتر متقدم (قابل للطي) -->
            <div class="advanced-filters mt-3">
                <a href="javascript:void(0)" class="advanced-toggle" onclick="toggleAdvancedFilters()">
                    <i class="fas fa-sliders-h me-1"></i> فلاتر متقدمة
                    <i class="fas fa-chevron-down toggle-icon"></i>
                </a>
                
                <div class="advanced-content" style="display: none;">
                    <div class="row g-3 mt-2">
                        <?php if (!empty($users)): ?>
                        <div class="col-md-6">
                            <div class="filter-group">
                                <label><i class="fas fa-user me-1"></i> الموظف</label>
                                <select name="employee_id" class="form-select">
                                    <option value="">جميع الموظفين</option>
                                    <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3">
                            <div class="filter-group">
                                <label><i class="fas fa-sort me-1"></i> ترتيب حسب</label>
                                <select name="sort_by" class="form-select">
                                    <option value="created_at">تاريخ الإنشاء</option>
                                    <option value="title">العنوان</option>
                                    <option value="priority">الأولوية</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="filter-group">
                                <label><i class="fas fa-sort-amount-down me-1"></i> اتجاه الترتيب</label>
                                <select name="sort_order" class="form-select">
                                    <option value="DESC">تنازلي (الأحدث أولاً)</option>
                                    <option value="ASC">تصاعدي (الأقدم أولاً)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <style>
        .filters-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        
        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
        }
        
        .filters-header h5 {
            margin: 0;
            color: #2c3e50;
            font-weight: 700;
            display: flex;
            align-items: center;
        }
        
        .view-toggle {
            display: flex;
            gap: 5px;
            background: #f8f9fa;
            padding: 5px;
            border-radius: 10px;
        }
        
        .btn-view-toggle {
            padding: 8px 15px;
            border: none;
            background: transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-view-toggle:hover {
            background: rgba(0,0,0,0.05);
        }
        
        .btn-view-toggle.active {
            background: #3498db;
            color: white;
            box-shadow: 0 3px 10px rgba(52, 152, 219, 0.3);
        }
        
        .filter-group {
            margin-bottom: 15px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            height: 100%;
            align-items: flex-end;
        }
        
        .btn-filter {
            padding: 10px 15px;
            border-radius: 10px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            min-width: 45px;
            height: 45px;
        }
        
        .advanced-filters {
            border-top: 1px dashed #dee2e6;
            padding-top: 15px;
        }
        
        .advanced-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #6c757d;
            text-decoration: none;
            font-weight: 600;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .advanced-toggle:hover {
            background: #e9ecef;
            color: #495057;
        }
        
        .advanced-content {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-top: 10px;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .filters-card {
                padding: 20px;
            }
            
            .btn-filter {
                min-width: 40px;
                height: 40px;
                padding: 8px 12px;
            }
        }
        
        @media (max-width: 768px) {
            .filters-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .view-toggle {
                align-self: flex-start;
            }
            
            .filter-buttons {
                justify-content: flex-start;
            }
            
            .btn-view-toggle {
                padding: 6px 12px;
                font-size: 0.85rem;
            }
        }
        
        @media (max-width: 576px) {
            .filters-card {
                padding: 15px;
            }
            
            .btn-filter {
                min-width: 35px;
                height: 35px;
                padding: 5px 8px;
                font-size: 0.85rem;
            }
            
            .btn-view-toggle span {
                display: none;
            }
            
            .btn-view-toggle {
                padding: 8px 10px;
            }
        }
    </style>
    
    <script>
        // تبديل عرض الجدول/البطاقات
        document.querySelectorAll('.btn-view-toggle').forEach(button => {
            button.addEventListener('click', function() {
                // إزالة النشط من جميع الأزرار
                document.querySelectorAll('.btn-view-toggle').forEach(btn => {
                    btn.classList.remove('active');
                });
                
                // إضافة النشط للزر المحدد
                this.classList.add('active');
                
                const viewType = this.getAttribute('data-view');
                localStorage.setItem('preferredView', viewType);
                
                // تبديل العرض
                toggleView(viewType);
            });
        });
        
        function toggleView(viewType) {
            const tableView = document.getElementById('documents-table');
            const cardsView = document.getElementById('documents-cards');
            
            if (viewType === 'table') {
                if (tableView) tableView.style.display = 'block';
                if (cardsView) cardsView.style.display = 'none';
            } else {
                if (tableView) tableView.style.display = 'none';
                if (cardsView) cardsView.style.display = 'block';
            }
        }
        
        // تبديل الفلاتر المتقدمة
        function toggleAdvancedFilters() {
            const content = document.querySelector('.advanced-content');
            const icon = document.querySelector('.toggle-icon');
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                content.style.display = 'none';
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }
        
        // إعادة تعيين الفلاتر
        function resetFilters() {
            window.location.href = window.location.pathname;
        }
        
        // تصدير النتائج
        function exportResults() {
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            
            window.location.href = 'export_documents.php?' + params.toString();
        }
        
        // تهيئة العرض المفضل
        document.addEventListener('DOMContentLoaded', function() {
            const preferredView = localStorage.getItem('preferredView') || 'table';
            const activeButton = document.querySelector(`.btn-view-toggle[data-view="${preferredView}"]`);
            
            if (activeButton) {
                activeButton.classList.add('active');
                toggleView(preferredView);
            }
        });
    </script>
    <?php
}
?>