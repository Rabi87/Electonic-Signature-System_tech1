<?php
/**
 * ترويسة المسؤول المشتركة
 * 
 * هذا الملف يوفر ترويسة موحدة لجميع صفحات المسؤول
 */
?>
<header class="admin-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
        </button>
        <a href="../dashboard/dashboard_admin.php" style="text-decoration: none; color: #2c3e50;">
            <h1 style="margin: 0; font-size: 1.2rem;">
                <i class="fas fa-crown"></i> لوحة تحكم المسؤول
            </h1>
        </a>
    </div>
    
    <div class="header-right">
        <div class="user-profile">
            <div class="user-avatar">
                <?php echo mb_substr($_SESSION['full_name'] ?? 'م', 0, 1, 'UTF-8'); ?>
            </div>
            <div class="user-info">
                <h4><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'المسؤول'); ?></h4>
                <p>مسؤول النظام</p>
            </div>
        </div>
        <a href="../logout.php" class="btn btn-danger" style="padding: 8px 20px;">
            <i class="fas fa-sign-out-alt"></i> خروج
        </a>
    </div>
</header>

<style>
    .admin-header {
        background: white;
        padding: 15px 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        border-bottom: 1px solid #eaeaea;
        margin-bottom: 30px;
    }
    
    .header-left {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .header-right {
        display: flex;
        align-items: center;
        gap: 20px;
    }
    
    .user-profile {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 1.2rem;
    }
    
    .user-info h4 {
        margin: 0;
        color: #2c3e50;
        font-size: 0.95rem;
    }
    
    .user-info p {
        margin: 0;
        color: #7f8c8d;
        font-size: 0.8rem;
    }
    
    .menu-toggle {
        display: none;
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #2c3e50;
        cursor: pointer;
    }
    
    @media (max-width: 768px) {
        .menu-toggle {
            display: block;
        }
    }
</style>

<script>
    // إضافة أي JavaScript ضروري للترويسة هنا
    document.getElementById('menuToggle')?.addEventListener('click', function() {
        alert('هنا سيتم فتح/إغلاق القائمة الجانبية في النسخة الكاملة');
    });
</script>