<?php
// تهيئة الجلسة مع إعدادات أكثر أماناً لملفات تعريف الارتباط
if (session_status() === PHP_SESSION_NONE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/functions.php';

// إذا كان المستخدم مسجل دخول بالفعل، توجيهه
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// توليد CSRF token لصفحة تسجيل الدخول
if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

// معالجة إرسال النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_username = $_POST['username'] ?? '';
    $input_password = $_POST['password'] ?? '';
    // remember-me feature disabled; no longer processing checkbox or cookie
    $posted_token = $_POST['login_csrf_token'] ?? '';

    // التحقق من CSRF
    if (empty($posted_token) || !hash_equals($_SESSION['login_csrf_token'], $posted_token)) {
        $error = 'طلب غير صالح، الرجاء إعادة المحاولة.';
    } elseif (empty($input_username) || empty($input_password)) {
        $error = 'يرجى إدخال جميع الحقول';
    } else {
        try {
            // الاتصال بقاعدة البيانات عبر طبقة Database الموحدة
            $pdo = getDB();

            // البحث عن المستخدم
            $stmt = $pdo->prepare("
                SELECT u.*, r.role_name
                FROM users u 
                INNER JOIN roles r ON u.role_id = r.id 
                WHERE (u.username = :username OR u.email = :username) 
                AND u.is_active = 1
            ");

            $stmt->execute([':username' => $input_username]);
            $user = $stmt->fetch();

            if ($user && password_verify($input_password, $user['password'])) {
                // تجديد معرف الجلسة لمنع تثبيت الجلسة
                session_regenerate_id(true);

                // حفظ بيانات المستخدم في الجلسة
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['site'] = $user['site'];
                $_SESSION['role_id'] = (int)$user['role_id'];
                $_SESSION['role_name'] = $user['role_name'];
                $_SESSION['department_id'] = $user['department_id'];
                $_SESSION['user_type'] = $user['role_name'];
                $_SESSION['site_name'] = $user['site'];
                $_SESSION['title'] = $user['title'];
                $_SESSION['login_time'] = time();


                // تحديث وقت آخر دخول
                $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
                $updateStmt->execute([':id' => $user['id']]);

                // تسجيل النشاط
                logUserActivity($user['id'], 'LOGIN', 'تسجيل دخول ناجح', null, ['username' => $user['username']]);

                // توجيه حسب الدور
                $role = strtolower(trim($user['role_name']));

                $redirects = [
                    'admin' => 'dashboard/dashboard_admin.php',
                    'board' => 'dashboard/board_dashboard.php',
                    'deputy_ceo' => 'dashboard/deputy_ceo_dashboard.php',
                    'sub_board' => 'dashboard/sboard_dashboard.php',
                    'private_board' => 'dashboard/pboard_dashboard.php',
                    'office_manager' => 'dashboard/office_mgr_dashboard.php',
                    'ceo' => 'dashboard/ceo_dashboard.php',
                    'department_manager' => 'dashboard/department_manager_dashboard.php',
                    'section_manager' => 'dashboard/section_manager_dashboard.php',
                    'employee' => 'dashboard/employee_dashboard.php'
                ];

                $redirect_url = $redirects[$role] ?? 'login.php';

                header("Location: $redirect_url");
                exit();
            } else {
                $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
            }

        } catch (PDOException $e) {
            // تسجيل الخطأ داخلياً بدون كشف تفاصيل للمستخدم
            error_log('Login error: ' . $e->getMessage());
            $error = 'خطأ في النظام، الرجاء المحاولة لاحقاً.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ديوان الشركة السورية للبترول SPC</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2b4438;
            --secondary-color: #2b4438;
            --accent-color: #8c774f;
            --danger-color: #e74c3c;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --info-color: #3498db;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Cairo', sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg,
                    #164a40 0%,
                    rgba(140, 119, 79, 1) 50%,
                    rgba(140, 119, 79, 1) 100%);
            position: relative;
            overflow: hidden;
            padding: 1rem;
        }

        /* ---------- 2. BACKGROUND ANIMATION ---------- */
        .background-animation {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 50%,
                    rgba(22, 74, 64, 0.4) 0%,
                    transparent 50%),
                radial-gradient(circle at 80% 20%,
                    rgba(140, 119, 79, 0.3) 0%,
                    transparent 50%),
                radial-gradient(circle at 40% 40%,
                    rgba(45, 106, 90, 0.5) 0%,
                    transparent 50%);
            animation: gradientShift 15s ease infinite;
            z-index: -1;
        }

        @keyframes gradientShift {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        .floating-element {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 20s infinite linear;
        }

        .floating-element:nth-child(1) {
            width: 100px;
            height: 100px;
            top: 10%;
            left: 10%;
            animation-delay: 0s;
        }

        .floating-element:nth-child(2) {
            width: 150px;
            height: 150px;
            bottom: 15%;
            right: 10%;
            animation-delay: -5s;
        }

        .floating-element:nth-child(3) {
            width: 80px;
            height: 80px;
            top: 60%;
            left: 90%;
            animation-delay: -10s;
        }

        @keyframes float {
            0% {
                transform: translate(0, 0) rotate(0deg);
            }

            33% {
                transform: translate(30px, -50px) rotate(120deg);
            }

            66% {
                transform: translate(-20px, 20px) rotate(240deg);
            }

            100% {
                transform: translate(0, 0) rotate(360deg);
            }
        }

        /* حاوية البطاقة */
        .card-container {
            width: 100%;
            max-width: 450px;
            /* حد أقصى على الشاشات الكبيرة */
            height: auto;
            /* ارتفاع ديناميكي */
            min-height: 500px;
            /* حد أدنى للارتفاع */
            perspective: 2500px;
            z-index: 10;
            margin: 0 auto;
            /* توسيط */
        }

        .flip-card {
            position: relative;
            width: 100%;
            height: 100%;
            min-height: 575px;
            transition: transform 0.8s cubic-bezier(0.4, 0.2, 0.2, 1);
            transform-style: preserve-3d;
            border-radius: 30px;
            box-shadow: 0 30px 40px rgba(0, 0, 0, 0.4);
            cursor: pointer;
            overflow: visible;
        }


        /* إضافة اللمعة لكل من الوجهين */
        .front::before,
        .back::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 50%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
            transform: skewX(-25deg);
            transition: left 0.9s ease;
            z-index: 5;
            /* يظهر فوق الخلفية وتحت النصوص إذا أردت، أو عدل حسب الرغبة */
            pointer-events: none;
            /* لا يعيق النقر */
        }


        /* عند إضافة class flipped يتم القلب */
        .flip-card.flipped {
            transform: rotateY(180deg);
        }

        /* تحريك اللمعة عند تمرير المؤشر على البطاقة */
        .flip-card:hover .front::before,
        .flip-card:hover .back::before {
            left: 150%;
        }


        /* الوجهان */
        .front,
        .back {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            border-radius: 30px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: clamp(15px, 5vw, 30px);
            backdrop-filter: blur(10px);
        }

        /* الوجه الأمامي (QR - الافتراضي) */
        .front {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border: 5px solid var(--accent-color);

        }

        /* الوجه الخلفي (نموذج الدخول) */
        .back {
            background: linear-gradient(135deg, var(--accent-color) 0%, var(--accent-color) 100%);
            border: 5px solid var(--primary-color);
            transform: rotateY(180deg);
        }

        /* محتوى QR */
        .qr-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }

        .logo-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 1rem;
        }

        .logo-img {
            width: min(150px, 40vw);
            height: auto;
            filter: drop-shadow(0 5px 10px rgba(0, 0, 0, 0.1));
        }

        .company-name {
            font-size: clamp(16px, 5vw, 22px);
            /* خط متجاوب */
            font-weight: 700;
            color: #feffff;
            margin-bottom: 0.2rem;
            text-align: center;
        }

        .system-name {
            font-size: clamp(12px, 4vw, 16px);
            color: #b5b7bb;
            margin-bottom: 1rem;
            text-align: center;
        }

        .qr-code {
            width: min(200px, 60vw);
            /* QR code يتناسب */
            height: min(200px, 60vw);
            background: #ffffff;
            border-radius: 20px;
            padding: 10px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
            margin: 0.5rem 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }


        .qr-code img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .qr-hint {
            font-size: clamp(12px, 4vw, 14px);
            color: #718096;
            background: #edf2f7;
            padding: 0.6rem 1.2rem;
            border-radius: 50px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-align: center;
        }

        .qr-hint i {
            color: #2a5298;
        }

        /* نموذج الدخول */
        .login-form {
            width: 100%;
        }

        .form-title {
            font-size: clamp(22px, 6vw, 28px);
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 1.2rem;
            text-align: center;
        }

        .error-message {
            background: #fee2e2;
            color: #b91c1c;
            padding: 0.8rem 1rem;
            border-radius: 50px;
            margin-bottom: 1rem;
            font-size: clamp(12px, 4vw, 14px);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: 100%;
        }

        .form-group {
            width: 100%;
            margin-bottom: 1.2rem;
        }

        .input-with-icon {
            position: relative;
            width: 100%;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            right: 15px;
            color: rgba(255, 255, 255, 0.8);
            z-index: 2;
            transition: color 0.3s;
        }

        .form-control {
            width: 100%;
            padding: 14px 45px 14px 45px;
            /* متناسق مع الأيقونات */
            font-size: clamp(14px, 4vw, 16px);
            background: transparent;
            border: none;
            border-bottom: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 0;
            color: #fff;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-bottom-color: #fff;
            background: transparent;
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.7);
            opacity: 1;
            font-size: clamp(12px, 4vw, 14px);
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            left: 15px;
            color: rgba(255, 255, 255, 0.8);
            cursor: pointer;
            z-index: 2;
            transition: color 0.3s;
        }

        .password-toggle:hover {
            color: #ffffff;
        }

        .login-btn {
            width: 100%;
            padding: 14px 16px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-color) 100%);
            color: white;
            border: none;
            border-radius: 50px;
            font-size: clamp(16px, 5vw, 18px);
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        /* تلميح بجانب الزر */
        .flip-hint {
            margin-top: 1.2rem;
            font-size: clamp(12px, 4vw, 13px);
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            cursor: pointer;
            /* للإشارة إلى أنه قابل للنقر */
            transition: background-color 0.3s;
        }


        .flip-hint:hover {
            background-color: rgba(0, 0, 0, 0.1);
        }

        .flip-hint i {
            font-size: clamp(12px, 4vw, 14px);
        }


        /* إخفاء خيارات إضافية مؤقتاً */
        .form-options {
            display: none;
        }

        /* إزالة تأثير autofill والحفاظ على الألوان */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px var(--accent-color) inset !important;
            -webkit-text-fill-color: #fff !important;
            caret-color: #fff;
            border-bottom: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 0;
        }

        /* تأكيد أن الحقل عند الكتابة يبقى بدون خلفية */
        .form-control {
            width: 100%;
            padding: 16px 50px 16px 50px;
            font-size: 16px;
            background: transparent;
            border: none;
            border-bottom: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 0;
            color: #fff;
            transition: border-color 0.3s;
            -webkit-text-fill-color: #fff;
            /* يضمن بقاء النص أبيض حتى أثناء autofill */
        }

        .form-control:focus {
            outline: none;
            border-bottom-color: #fff;
            background: transparent;
        }

        /* شاشات صغيرة جداً (أقل من 400px) */
        @media (max-width: 399px) {
            body {
                padding: 0.5rem;
            }
            .card-container {
                max-width: 100%;
        /* شاشات صغيرة جداً (أقل من 400px) */
        @media (max-width: 399px) {
            body {
                padding: 0.5rem;
            }
            .card-container {
                max-width: 100%;
                min-height: auto;
            }
            .flip-card {
                min-height: 450px;
            }
            .front, .back {
                padding: 15px 10px;
            }
            .qr-code {
                width: 150px;
                height: 150px;
            }
            .logo-img {
                width: 100px;
            }
            .form-control {
                padding: 12px 35px;
            }
            .input-icon {
                right: 10px;
            }
            .password-toggle {
                left: 10px;
            }
        }

        /* شاشات متوسطة (تاب، بين 400 و 768) */
        @media (min-width: 400px) and (max-width: 767px) {
            .card-container {
                max-width: 400px;
            }
            .front, .back {
                padding: 20px;
            }
        }

        /* شاشات كبيرة (ديسكتوب، فوق 1024) */
        @media (min-width: 1024px) {
            .card-container {
                max-width: 450px; /* أوسع قليلاً */
            }
            .front, .back {
                border-width: 10px; /* إعادة الإطار السميك */
            }
            .qr-code {
                width: 200px;
                height: 200px;
            }
            .logo-img {
                width: 170px;
            }
        }

        /* شاشات كبيرة جداً (1200+) */
        @media (min-width: 1200px) {
            .card-container {
                max-width: 550px;
            }
        }
                min-height: auto;
            }
            .flip-card {
                min-height: 500px;
            }
            .front, .back {
                padding: 15px 10px;
            }
            .qr-code {
                width: 100px;
                height: 100px;
            }
            .logo-img {
                width: 100px;
            }
            .form-control {
                padding: 12px 35px;
            }
            .input-icon {
                right: 10px;
            }
            .password-toggle {
                left: 10px;
            }
        }

        /* شاشات متوسطة (تاب، بين 400 و 768) */
        @media (min-width: 400px) and (max-width: 767px) {
            .card-container {
                max-width: 400px;
            }
            .front, .back {
                padding: 20px;
            }
        }

        /* شاشات كبيرة (ديسكتوب، فوق 1024) */
        @media (min-width: 1024px) {
            .card-container {
                max-width: 500px; /* أوسع قليلاً */
            }
            .front, .back {
                border-width: 10px; /* إعادة الإطار السميك */
            }
            .qr-code {
                width: 220px;
                height: 220px;
            }
            .logo-img {
                width: 170px;
            }
        }

        /* شاشات كبيرة جداً (1200+) */
        @media (min-width: 1200px) {
            .card-container {
                max-width: 550px;
            }
        }
    </style>
</head>

<body>
    <!-- خلفية متحركة -->
    <div class="background-animation">
        <div class="floating-element"></div>
        <div class="floating-element"></div>
        <div class="floating-element"></div>
    </div>

    <div class="card-container">
        <!-- تم إزالة hover وأضفنا click باستخدام JavaScript -->
        <div class="flip-card" id="flipCard">
            <!-- الوجه الأمامي: رمز QR (يظهر افتراضياً) -->
            <div class="front">
                <div class="qr-section">
                    <div class="logo-wrapper">
                        <img src="images/logo.png" alt="SPC Logo" class="logo-img">
                    </div>
                    <div class="company-name"> السورية للبترول</div>
                    <div class="company-name" style="color:var(--accent-color)">SYRIA PETROLEUM</div>
                    <div class="system-name">نظام التوقيع الإلكتروني</div>

                    <div class="qr-code">
                        <img src="images/qr.png" alt="QR Code">
                    </div>

                    <div class="flip-hint"
                        onclick="event.stopPropagation(); document.getElementById('flipCard').classList.toggle('flipped');">
                        <i class="fas fa-arrow-left"></i>
                        <span>اضغط هنا للدخول</span>
                    </div>
                </div>
            </div>

            <!-- الوجه الخلفي: نموذج تسجيل الدخول -->
            <div class="back">
                <div class="login-form">
                    <div class="logo-wrapper">
                        <img src="images/logo.png" alt="SPC Logo" class="logo-img">
                    </div>
                    <div class="form-title">تسجيل الدخول</div>
                    <?php if (isset($error)): ?>
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="" onclick="event.stopPropagation();">
                        <input type="hidden" name="login_csrf_token"
                               value="<?php echo htmlspecialchars($_SESSION['login_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <!-- منع القلب عند النقر داخل النموذج -->
                        <div class="form-group">
                            <div class="input-with-icon">
                                <div class="input-icon">
                                    <i class="fas fa-user"></i>
                                </div>
                                <input type="text" name="username" class="form-control" placeholder="اسم المستخدم"
                                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                                    required>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="input-with-icon">
                                <div class="input-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <input type="password" name="password" class="form-control" id="passwordInput"
                                    placeholder="كلمة المرور" required>
                                <span class="password-toggle" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                        </div>

                        <button type="submit" class="login-btn">
                            دخول
                        </button>
                    </form>

                    <div class="flip-hint"
                        onclick="event.stopPropagation(); document.getElementById('flipCard').classList.toggle('flipped');"
                        style="justify-content: center;">
                        <i class="fas fa-arrow-right"></i>
                        <span>اضغط للعودة إلى رمز QR</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/login.js"></script>
</body>

</html>