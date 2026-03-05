<?php
// [file name]: includes/dashboard_indicators.php
/**
 * مؤشرات دائرية للمستندات غير المعالجة
 */

// جلب إحصائيات المستندات حسب الدور
function getDocumentIndicators($db, $user_id, $role_name, $department_id = null) {
    $indicators = [
        'normal' => ['count' => 0, 'label' => 'عادي', 'icon' => 'fa-file-alt', 'color' => '#3498db'],
        'urgent' => ['count' => 0, 'label' => 'عاجل', 'icon' => 'fa-exclamation-triangle', 'color' => '#e74c3c'],
        'secret' => ['count' => 0, 'label' => 'سري', 'icon' => 'fa-lock', 'color' => '#9b59b6']
    ];
    
    // استعلامات مختلفة حسب الدور
    if ($role_name === 'employee') {
        // موظف: المستندات الموجهة إليه فقط
        $query = "SELECT 
            SUM(CASE WHEN priority NOT IN ('urgent', 'high') AND 
                      (title NOT LIKE '%سري%' AND description NOT LIKE '%سري%') 
                      AND current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as normal,
            SUM(CASE WHEN priority IN ('urgent', 'high') AND 
                      current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as urgent,
            SUM(CASE WHEN (title LIKE '%سري%' OR description LIKE '%سري%') AND 
                      current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as secret
            FROM documents 
            WHERE current_holder_id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->execute([':user_id' => $user_id]);
        
    } elseif ($role_name === 'section_manager') {
        // رئيس قسم: المستندات في قسمه + الموجهة إليه
        $query = "SELECT 
            SUM(CASE WHEN d.priority NOT IN ('urgent', 'high') AND 
                      (d.title NOT LIKE '%سري%' AND d.description NOT LIKE '%سري%') 
                      AND d.current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as normal,
            SUM(CASE WHEN d.priority IN ('urgent', 'high') AND 
                      d.current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as urgent,
            SUM(CASE WHEN (d.title LIKE '%سري%' OR d.description LIKE '%سري%') AND 
                      d.current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as secret
            FROM documents d
            LEFT JOIN users u ON d.created_by = u.id
            WHERE (d.current_holder_id = :user_id 
                   OR d.created_by IN (SELECT id FROM users WHERE supervisor_id = :supervisor_id))
            AND d.current_status IN ('pending', 'under_review')";
        $stmt = $db->prepare($query);
        $stmt->execute([':user_id' => $user_id, ':supervisor_id' => $user_id]);
        
    } elseif ($role_name === 'department_manager') {
        // رئيس دائرة: جميع مستندات الأقسام في الدائرة
        $query = "SELECT 
            SUM(CASE WHEN d.priority NOT IN ('urgent', 'high') AND 
                      (d.title NOT LIKE '%سري%' AND d.description NOT LIKE '%سري%') 
                      AND d.current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as normal,
            SUM(CASE WHEN d.priority IN ('urgent', 'high') AND 
                      d.current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as urgent,
            SUM(CASE WHEN (d.title LIKE '%سري%' OR d.description LIKE '%سري%') AND 
                      d.current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as secret
            FROM documents d
            LEFT JOIN users u ON d.created_by = u.id
            WHERE u.department_id IN (SELECT id FROM departments WHERE manager_id = :manager_id OR id = :dept_id)
            AND d.current_status IN ('pending', 'under_review')";
        $stmt = $db->prepare($query);
        $stmt->execute([':manager_id' => $user_id, ':dept_id' => $department_id]);
    }
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $indicators['normal']['count'] = (int)$result['normal'];
        $indicators['urgent']['count'] = (int)$result['urgent'];
        $indicators['secret']['count'] = (int)$result['secret'];
    }
    
    return $indicators;
}

// عرض المؤشرات
function displayIndicators($indicators) {
    ?>
    <div class="row mb-4 indicators-container">
        <?php foreach ($indicators as $key => $indicator): ?>
        <div class="col-lg-4 col-md-4 col-sm-6 mb-3">
            <div class="indicator-card indicator-<?php echo $key; ?> <?php echo $indicator['count'] > 0 ? 'has-pending pulse' : ''; ?>" 
                 data-type="<?php echo $key; ?>"
                 onclick="filterByIndicator('<?php echo $key; ?>')">
                <div class="indicator-content">
                    <div class="indicator-icon">
                        <i class="fas <?php echo $indicator['icon']; ?>"></i>
                    </div>
                    <div class="indicator-details">
                        <div class="indicator-count" id="<?php echo $key; ?>-count">
                            <?php echo $indicator['count']; ?>
                        </div>
                        <div class="indicator-label"><?php echo $indicator['label']; ?></div>
                        <div class="indicator-subtitle">مستند غير معالج</div>
                    </div>
                    <?php if ($indicator['count'] > 0): ?>
                    <div class="indicator-alert">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="indicator-progress">
                    <div class="progress-bar" style="width: <?php echo min($indicator['count'] * 10, 100); ?>%"></div>
                </div>
                <div class="indicator-hover">
                    <span>انقر للتصفية حسب <?php echo $indicator['label']; ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <style>
        .indicators-container {
            margin-bottom: 30px;
        }
        
        .indicator-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 3px solid transparent;
            height: 180px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .indicator-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        .indicator-card.has-pending {
            animation: borderGlow 2s infinite;
        }
        
        .indicator-card.indicator-normal {
            border-color: #3498db;
            background: linear-gradient(135deg, #f8fafd, #e8f4fc);
        }
        
        .indicator-card.indicator-urgent {
            border-color: #e74c3c;
            background: linear-gradient(135deg, #fff8f7, #ffeaea);
        }
        
        .indicator-card.indicator-secret {
            border-color: #9b59b6;
            background: linear-gradient(135deg, #f9f5ff, #f3eaff);
        }
        
        .indicator-content {
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            z-index: 2;
        }
        
        .indicator-icon {
            width: 70px;
            height: 70px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            color: white;
            flex-shrink: 0;
        }
        
        .indicator-normal .indicator-icon {
            background: linear-gradient(135deg, #3498db, #2980b9);
            box-shadow: 0 10px 20px rgba(52, 152, 219, 0.3);
        }
        
        .indicator-urgent .indicator-icon {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            box-shadow: 0 10px 20px rgba(231, 76, 60, 0.3);
        }
        
        .indicator-secret .indicator-icon {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
            box-shadow: 0 10px 20px rgba(155, 89, 182, 0.3);
        }
        
        .indicator-details {
            flex: 1;
        }
        
        .indicator-count {
            font-size: 3rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 5px;
        }
        
        .indicator-normal .indicator-count {
            color: #3498db;
            text-shadow: 2px 2px 5px rgba(52, 152, 219, 0.2);
        }
        
        .indicator-urgent .indicator-count {
            color: #e74c3c;
            text-shadow: 2px 2px 5px rgba(231, 76, 60, 0.2);
        }
        
        .indicator-secret .indicator-count {
            color: #9b59b6;
            text-shadow: 2px 2px 5px rgba(155, 89, 182, 0.2);
        }
        
        .indicator-label {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 3px;
            color: #2c3e50;
        }
        
        .indicator-subtitle {
            font-size: 0.85rem;
            color: #7f8c8d;
        }
        
        .indicator-alert {
            position: absolute;
            top: 15px;
            left: 15px;
            color: #e74c3c;
            font-size: 1.2rem;
            animation: pulse 1.5s infinite;
        }
        
        .indicator-progress {
            height: 8px;
            background: rgba(0,0,0,0.05);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 15px;
        }
        
        .indicator-progress .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .indicator-normal .progress-bar {
            background: linear-gradient(90deg, #3498db, #2980b9);
        }
        
        .indicator-urgent .progress-bar {
            background: linear-gradient(90deg, #e74c3c, #c0392b);
        }
        
        .indicator-secret .progress-bar {
            background: linear-gradient(90deg, #9b59b6, #8e44ad);
        }
        
        .indicator-hover {
            position: absolute;
            bottom: 0;
            right: 0;
            left: 0;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 10px;
            text-align: center;
            font-size: 0.85rem;
            transform: translateY(100%);
            transition: transform 0.3s ease;
            border-radius: 0 0 20px 20px;
        }
        
        .indicator-card:hover .indicator-hover {
            transform: translateY(0);
        }
        
        /* Animations */
        @keyframes borderGlow {
            0% {
                box-shadow: 0 0 0 0 rgba(52, 152, 219, 0.4);
            }
            70% {
                box-shadow: 0 0 0 15px rgba(52, 152, 219, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(52, 152, 219, 0);
            }
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.1);
                opacity: 0.8;
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .indicator-urgent.has-pending {
            animation: borderGlowUrgent 1.5s infinite, pulse 2s infinite;
        }
        
        @keyframes borderGlowUrgent {
            0% {
                box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.6);
            }
            70% {
                box-shadow: 0 0 0 15px rgba(231, 76, 60, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(231, 76, 60, 0);
            }
        }
        
        .indicator-secret.has-pending {
            animation: borderGlowSecret 2s infinite, pulse 2.5s infinite;
        }
        
        @keyframes borderGlowSecret {
            0% {
                box-shadow: 0 0 0 0 rgba(155, 89, 182, 0.5);
            }
            70% {
                box-shadow: 0 0 0 15px rgba(155, 89, 182, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(155, 89, 182, 0);
            }
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .indicator-icon {
                width: 60px;
                height: 60px;
                font-size: 25px;
            }
            
            .indicator-count {
                font-size: 2.5rem;
            }
        }
        
        @media (max-width: 992px) {
            .indicator-card {
                height: 160px;
                padding: 20px;
            }
            
            .indicator-icon {
                width: 55px;
                height: 55px;
                font-size: 22px;
            }
            
            .indicator-count {
                font-size: 2.2rem;
            }
            
            .indicator-label {
                font-size: 1.1rem;
            }
        }
        
        @media (max-width: 768px) {
            .indicator-card {
                height: 150px;
            }
            
            .indicator-content {
                gap: 15px;
            }
            
            .indicator-icon {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }
            
            .indicator-count {
                font-size: 2rem;
            }
        }
        
        @media (max-width: 576px) {
            .indicator-card {
                height: 140px;
                padding: 15px;
            }
            
            .indicator-icon {
                width: 45px;
                height: 45px;
                font-size: 18px;
            }
            
            .indicator-count {
                font-size: 1.8rem;
            }
            
            .indicator-label {
                font-size: 1rem;
            }
        }
        
        @media (max-width: 400px) {
            .indicator-card {
                height: 130px;
                padding: 12px;
            }
            
            .indicator-icon {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }
            
            .indicator-count {
                font-size: 1.6rem;
            }
        }
    </style>
    
    <script>
        function filterByIndicator(type) {
            // تطبيق الفلاتر حسب نوع المؤشر
            const form = document.getElementById('filterForm');
            const prioritySelect = form.querySelector('select[name="priority"]');
            const searchInput = form.querySelector('input[name="search"]');
            
            switch(type) {
                case 'urgent':
                    prioritySelect.value = 'urgent';
                    searchInput.value = '';
                    break;
                case 'secret':
                    prioritySelect.value = 'medium';
                    searchInput.value = 'سري';
                    break;
                case 'normal':
                    prioritySelect.value = '';
                    searchInput.value = '';
                    break;
            }
            
            form.submit();
        }
        
        // تحديث المؤشرات كل 30 ثانية
        function updateIndicators() {
            fetch('../includes/get_indicators.php?role=<?php echo $role_name; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // تحديث الأرقام
                        document.getElementById('normal-count').textContent = data.normal;
                        document.getElementById('urgent-count').textContent = data.urgent;
                        document.getElementById('secret-count').textContent = data.secret;
                        
                        // تحديث حالة الوميض
                        const normalCard = document.querySelector('.indicator-normal');
                        const urgentCard = document.querySelector('.indicator-urgent');
                        const secretCard = document.querySelector('.indicator-secret');
                        
                        // تحديث فئة has-pending بناءً على وجود مستندات غير معالجة
                        if (data.normal > 0) {
                            normalCard.classList.add('has-pending', 'pulse');
                        } else {
                            normalCard.classList.remove('has-pending', 'pulse');
                        }
                        
                        if (data.urgent > 0) {
                            urgentCard.classList.add('has-pending', 'pulse');
                        } else {
                            urgentCard.classList.remove('has-pending', 'pulse');
                        }
                        
                        if (data.secret > 0) {
                            secretCard.classList.add('has-pending', 'pulse');
                        } else {
                            secretCard.classList.remove('has-pending', 'pulse');
                        }
                        
                        // تحديث أشرطة التقدم
                        const normalProgress = normalCard.querySelector('.progress-bar');
                        const urgentProgress = urgentCard.querySelector('.progress-bar');
                        const secretProgress = secretCard.querySelector('.progress-bar');
                        
                        normalProgress.style.width = Math.min(data.normal * 10, 100) + '%';
                        urgentProgress.style.width = Math.min(data.urgent * 10, 100) + '%';
                        secretProgress.style.width = Math.min(data.secret * 10, 100) + '%';
                        
                        // إشعار إذا كانت هناك مستندات عاجلة جديدة
                        if (data.urgent > 0 && data.urgent > localStorage.getItem('lastUrgentCount')) {
                            showUrgentNotification(data.urgent);
                            localStorage.setItem('lastUrgentCount', data.urgent);
                        }
                    }
                })
                .catch(error => {
                    console.error('Error updating indicators:', error);
                });
        }
        
        // تحديث كل 30 ثانية
        setInterval(updateIndicators, 30000);
        
        // تحديث عند عودة التركيز للصفحة
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                updateIndicators();
            }
        });
        
        // تهيئة أولية
        document.addEventListener('DOMContentLoaded', function() {
            // حفظ العد الحالي للعاجلة
            const urgentCount = document.getElementById('urgent-count').textContent;
            localStorage.setItem('lastUrgentCount', urgentCount);
            
            // تحديث بعد 5 ثواني للتأكد من تحديث البيانات
            setTimeout(updateIndicators, 5000);
        });
    </script>
    <?php
}
?>