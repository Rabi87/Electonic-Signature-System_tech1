<?php

require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// التأكد من وجود رمز CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// جلب دور المستخدم
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_name'] ?? 'employee';

// التوجيهات
$redirects = [
    'admin' => '../dashboard/dashboard_admin.php',
    'deputy_ceo' => '../dashboard/deputy_ceo_dashboard.php',
    'board' => '../dashboard/board_dashboard.php',
    'private_board' => '../dashboard/pboard_dashboard.php',
    'sub_board' => '../dashboard/sboard_dashboard.php',
    'ceo' => '../dashboard/ceo_dashboard.php',
    'office_manager' => '../dashboard/office_mgr_dashboard.php',
    'department_manager' => '../dashboard/department_manager_dashboard.php',
    'section_manager' => '../dashboard/section_manager_dashboard.php',
    'employee' => '../dashboard/employee_dashboard.php'
];

$redirect_url = $redirects[$user_role] ?? '../dashboard/employee_dashboard.php';

// التحقق من معرف المستند
if (!isset($_GET['id'])) {
    die("المعرف غير محدد");
}

$document_id = intval($_GET['id']);
$is_modal = isset($_GET['modal']) && $_GET['modal'] == 1;

try {
    $pdo = getDb();

    // === جلب بيانات المستند ===
    $stmt = $pdo->prepare("
        SELECT d.*, 
               u.full_name as creator_name,
               u2.full_name as current_holder_name
        FROM documents d 
        LEFT JOIN users u ON d.created_by = u.id
        LEFT JOIN users u2 ON d.current_holder_id = u2.id
        WHERE d.id = ?
    ");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();

    if (!$document) {
        die("المستند غير موجود");
    }

    // === التحقق من صلاحيات الوصول ===
    $can_view = false;
    $can_sign = false;
    $can_attach = false;
    $can_manage = false;
    $is_creator = ($document['created_by'] == $user_id);
    $is_current_holder = ($document['current_holder_id'] == $user_id);

    // صلاحيات حسب الدور
    switch ($user_role) {
        case 'admin':
            $can_view = true;
            $can_sign = true;
            $can_attach = true;
            $can_manage = true;
            break;
        case 'ceo':
        case 'board':
        case 'private_board':
        case 'sub_board':
        case 'office_manager':
        case 'department_manager':
        case 'section_manager':
            $can_view = true;
            $can_sign = true;
            $can_attach = true;
            $can_manage = true;
            break;
        case 'employee':
            $can_view = ($is_creator || $is_current_holder);
            $can_sign = ($is_current_holder);
            $can_attach = ($is_creator);
            $can_manage = ($is_creator || $is_current_holder);
            break;
        default:
            $can_view = false;
            $can_attach = false;
    }

    // تحقق إضافي: إذا كان المستخدم معيناً في أي حقل
    if (!$can_view) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM document_fields 
            WHERE document_id = ? AND assigned_to = ?
        ");
        $stmt->execute([$document_id, $user_id]);
        $assigned_field = $stmt->fetch();
        if ($assigned_field['count'] > 0) {
            $can_view = true;
            $can_sign = true;
        }
    }

    if (!$can_view) {
        die("ليس لديك صلاحية للوصول إلى هذا المستند");
    }

    // === جلب الحقول مع النسب المئوية ===
    $stmt = $pdo->prepare("
        SELECT f.*, 
               COALESCE(f.x_percent, (f.x_position / 1100) * 100) as x_percent,
               COALESCE(f.y_percent, (f.y_position / 1550) * 100) as y_percent,
               COALESCE(f.width_percent, (f.width / 1100) * 100) as width_percent,
               COALESCE(f.height_percent, (f.height / 1550) * 100) as height_percent,
               u.full_name as assigned_name
        FROM document_fields f
        LEFT JOIN users u ON f.assigned_to = u.id
        WHERE f.document_id = ?
        ORDER BY f.field_order ASC, f.created_at ASC
    ");
    $stmt->execute([$document_id]);
    $fields = $stmt->fetchAll();

    // === جلب قيم الحقول الموقعة ===
    $stmt = $pdo->prepare("
        SELECT v.*, u.full_name as signer_name
        FROM field_values v
        LEFT JOIN users u ON v.user_id = u.id
        WHERE v.field_id IN (
            SELECT id FROM document_fields WHERE document_id = ?
        )
        ORDER BY v.signed_at ASC
    ");
    $stmt->execute([$document_id]);
    $field_values = $stmt->fetchAll();

    // === جلب المرفقات ===
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as uploader_name 
        FROM document_attachments a
        LEFT JOIN users u ON a.uploaded_by = u.id
        WHERE a.document_id = ?
        ORDER BY a.uploaded_at DESC
    ");
    $stmt->execute([$document_id]);
    $attachments = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("خطأ في جلب البيانات: " . $e->getMessage());
    $attachments = [];
}

// معلومات الملف
$file_path = $document['file_path'] ?? '';
$file_exists = false;

// محاولة إيجاد الملف بمسارات مختلفة
$file_paths_to_try = [
    $file_path,
    '../' . $file_path,
    '../../' . $file_path,
    '../../../' . $file_path,
    basename($file_path),
    'uploads/' . basename($file_path),
    '../uploads/' . basename($file_path),
    '../../uploads/' . basename($file_path),
];

foreach ($file_paths_to_try as $path) {
    if ($path && file_exists($path)) {
        $file_path = $path;
        $file_exists = true;
        break;
    }
}

$file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

// دالة لتنسيق حجم الملف
function formatFileSize($bytes)
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return $bytes . ' byte';
    } else {
        return '0 bytes';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>عرض المستند - <?= htmlspecialchars($document['title']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/print.css">
    <style>
        /* المتغيرات كما هي */
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
            overflow-x: hidden;
            /* منع التمرير الأفقي */
            padding: 1rem;
            /* مسافة حول البطاقة على الشاشات الصغيرة */
        }

        /* خلفية متحركة (بدون تعديل) */
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

        /* حاوية البطاقة - أصبحت مرنة */
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
            min-height: 500px;
            /* متناسق مع الحاوية */
            transition: transform 0.8s cubic-bezier(0.4, 0.2, 0.2, 1);
            transform-style: preserve-3d;
            border-radius: 30px;
            box-shadow: 0 30px 40px rgba(0, 0, 0, 0.4);
            cursor: pointer;
            overflow: visible;
        }

        /* اللمعة - لم تتغير */
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
            pointer-events: none;
        }

        .flip-card.flipped {
            transform: rotateY(180deg);
        }

        .flip-card:hover .front::before,
        .flip-card:hover .back::before {
            left: 150%;
        }

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
            /* padding متجاوب */
            backdrop-filter: blur(10px);
        }

        .front {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border: 5px solid var(--accent-color);
            /* تقليل سمك الإطار قليلاً للموبايل */
        }

        .back {
            background: linear-gradient(135deg, var(--accent-color) 0%, var(--accent-color) 100%);
            border: 5px solid var(--primary-color);
            transform: rotateY(180deg);
        }

        /* محتوى QR - تجاوب */
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
            /* على الشاشات الصغيرة لا يتجاوز 40% من عرض الشاشة */
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

        /* نموذج الدخول - تجاوب */
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
            /* تقليل المسافة للموبايل */
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
            /* تقليل المسافة */
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

        /* تعديل autofill */
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

        /* === MEDIA QUERIES لتحسينات إضافية === */

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

            .front,
            .back {
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

            .front,
            .back {
                padding: 20px;
            }
        }

        /* شاشات كبيرة (ديسكتوب، فوق 1024) */
        @media (min-width: 1024px) {
            .card-container {
                max-width: 500px;
                /* أوسع قليلاً */
            }

            .front,
            .back {
                border-width: 10px;
                /* إعادة الإطار السميك */
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

        .signature-field[data-field-type="note"] {
            z-index: 200 !important;
        }

        .btn-danger {
            background-color: var(--danger-color, #e74c3c);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c0392b;
        }
    </style>
</head>

<body>
    <!-- حاوية المستند -->
    <div class="document-container">
        <div class="document-wrapper">
            <div id="pdfViewer">
                <?php if (!$file_exists): ?>
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>الملف غير موجود</h3>
                    </div>
                <?php else: ?>
                    <div id="documentContainer"></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- =========== قسم عرض المرفقات =========== -->
        <?php if (!empty($attachments)): ?>
            <div style="margin-top: 40px;">
                <h3 class="attachments-title" style="text-align: center; color: #ffffff; margin-bottom: 20px;">
                    <i class="fas fa-paperclip"></i> المرفقات
                </h3>
                <?php foreach ($attachments as $attach):
                    $isImage = strpos($attach['file_type'], 'image') !== false;
                    $isPDF = strpos($attach['file_type'], 'pdf') !== false;
                    $canDisplay = $isImage || $isPDF;

                    $can_delete_attachment = (
                        $user_role === 'admin' ||
                        $user_role === 'board' ||
                        $document['created_by'] == $user_id ||
                        $attach['uploaded_by'] == $user_id
                    );

                ?>
                    <div class="attachment-wrapper" data-attachment-id="<?= $attach['id'] ?>"
                        data-attachment-type="<?= $isPDF ? 'pdf' : ($isImage ? 'image' : 'other') ?>">
                        <!-- رأس المرفق مع اسم الملف وأزرار التحكم واسم الرافع وزر الحذف -->
                        <div class="attachment-header">


                            <div class="attachment-display-header">
                                <div style="flex: 1;">
                                    <h4 style="margin: 0; color: #fbfcfd;">
                                        <?= htmlspecialchars($attach['file_name']) ?>
                                    </h4>
                                    <p style="margin: 5px 0 0; color: #ffffff; font-size: 13px;">
                                        <i class="fas fa-user"></i> <?= htmlspecialchars($attach['uploader_name'] ?? 'مجهول') ?>
                                        | <i class="fas fa-calendar"></i>
                                        <?= date('Y-m-d H:i', strtotime($attach['uploaded_at'])) ?>
                                        | <i class="fas fa-hdd"></i> <?= formatFileSize($attach['file_size'] ?? 0) ?>
                                    </p>
                                </div>

                            </div>

                            <!-- <div class="attachment-controls" id="controls-<?= $attach['id'] ?>">
                                <?php if ($canDisplay): ?>
                                    <button class="control-btn" onclick="attachmentZoomOut(<?= $attach['id'] ?>)">
                                        <i class="fas fa-search-minus"></i>
                                    </button>
                                    <span class="zoom-level" id="zoom-level-<?= $attach['id'] ?>">100%</span>
                                    <button class="control-btn" onclick="attachmentZoomIn(<?= $attach['id'] ?>)">
                                        <i class="fas fa-search-plus"></i>
                                    </button>
                                    <button class="control-btn" onclick="attachmentReset(<?= $attach['id'] ?>)">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                <?php endif; ?>

                            </div>-->
                            <div class="attachment-controls">
                                <button class="control-btn"
                                    onclick="downloadAttachment(<?= $attach['id'] ?>, '<?= htmlspecialchars($attach['file_name']) ?>')">
                                    <i class="fas fa-download"></i>
                                </button>
                                <?php if ($can_delete_attachment): ?>
                                    <button class="control-btn" onclick="deleteAttachment(<?= $attach['id'] ?>)" title="حذف المرفق">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- محتوى المرفق -->
                        <div class="attachment-container" id="attachment-container-<?= $attach['id'] ?>">
                            <?php if ($isImage): ?>
                                <img src="<?= htmlspecialchars($attach['file_path']) ?>"
                                    alt="<?= htmlspecialchars($attach['file_name']) ?>"
                                    style="width: 100%; height: auto; transform-origin: top left; transition: transform 0.2s;">
                            <?php elseif ($isPDF): ?>
                                <!-- سيتم عرض PDF عبر JavaScript -->
                                <div class="pdf-placeholder">جاري تحميل ملف PDF...</div>
                            <?php else: ?>
                                <div class="other-file-placeholder">
                                    <i class="fas fa-file" style="font-size: 48px;"></i>
                                    <p>لا يمكن معاينة هذا النوع من الملفات</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <!-- =========== نهاية قسم عرض المرفقات =========== -->
    </div>

    <!-- نافذة معاينة الصور -->
    <div id="imagePreviewModal" class="modal-overlay" style="z-index: 3000;">
        <div class="modal-content" style="max-width: 90%; max-height: 90%; background: rgba(0,0,0,0.9);">
            <div class="modal-header" style="background: rgba(0,0,0,0.8);">
                <h3 style="margin: 0; color: white;" id="imagePreviewTitle"></h3>
                <span onclick="closeImagePreview()" style="color: white; cursor: pointer;">&times;</span>
            </div>
            <div class="modal-body" style="display: flex; align-items: center; justify-content: center; padding: 20px;">
                <img id="previewImage" src="" alt=""
                    style="max-width: 100%; max-height: 70vh; object-fit: contain; border-radius: 5px;">
            </div>
            <div class="modal-footer" style="background: rgba(0,0,0,0.8);">
                <button onclick="closeImagePreview()" class="btn btn-secondary">
                    <i class="fas fa-times"></i> إغلاق
                </button>
                <a id="downloadImageLink" href="#" class="btn btn-primary" download>
                    <i class="fas fa-download"></i> تحميل الصورة
                </a>
            </div>
        </div>
    </div>

    <!-- الأزرار العائمة -->
    <div class="floating-actions">
        <!--  <?php if ($can_sign && !in_array($document['current_status'], ['completed', 'archived'])): ?>
            <button onclick="openSignModal()" class="btn-circle btn-success" title="إكمال التوقيع">
                <i class="fas fa-check"></i>
            </button>
        <?php endif; ?> -->

        <?php if (in_array($user_role, ['board', 'sub_board', 'private_board']) && !in_array($document['current_status'], ['completed', 'archived'])): ?>
            <button onclick="editDocument()" class="btn-circle btn-success" title="تحرير المستند">
                <i class="fas fa-edit"></i>
            </button>
        <?php endif; ?>


        <button onclick="openAttachModal()" class="btn-circle btn-secondary" title="إضافة مرفق">
            <i class="fas fa-paperclip"></i>
        </button>


        <button onclick="printCompleteDocument()" class="btn-circle btn-warning" title="طباعة المستند مع المرفقات">
            <i class="fas fa-print"></i>
        </button>

        <!--  <button onclick="downloadDocument()" class="btn-circle btn-info" title="تحميل">
            <i class="fas fa-download"></i>
        </button>

        <button onclick="refreshPage()" class="btn-circle" title="تحديث">
            <i class="fas fa-sync-alt"></i>
        </button> -->

        <?php if (!$is_modal): ?>
            <button onclick="goBack()" class="btn-circle" title="العودة للوحة الرئيسية"">
                <i class=" fas fa-arrow-right"></i>
            </button>
        <?php else: ?>
            <button onclick="closeModal()" class="btn-circle btn-danger" title="إغلاق">
                <i class="fas fa-times"></i>
            </button>
        <?php endif; ?>
    </div>

    <!-- أدوات الزووم -->
    <?php if ($file_exists): ?>
        <div class="zoom-controls">
            <button class="zoom-btn" onclick="zoomOut()" title="تصغير">
                <i class="fas fa-search-minus"></i>
            </button>
            <span id="zoomLevel">100%</span>
            <button class="zoom-btn" onclick="zoomIn()" title="تكبير">
                <i class="fas fa-search-plus"></i>
            </button>
            <button class="zoom-btn" onclick="resetZoom()" title="إعادة تعيين">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- نافذة تعبئة الحقل -->
    <div id="fieldModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-signature"></i> تعبئة الحقل</h3>
                <span onclick="closeFieldModal()">&times;</span>
            </div>
            <div class="modal-body" id="modalContent"></div>
            <div class="modal-footer">
                <button onclick="closeFieldModal()" class="btn btn-secondary">إلغاء</button>
                <button onclick="submitFieldValue()" class="btn btn-primary">حفظ</button>
            </div>
        </div>
    </div>

    <!-- نافذة إكمال التوقيع -->
    <div id="signModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> تأكيد إكمال التوقيع</h3>
                <span onclick="closeSignModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>هل أنت متأكد من إكمال توقيع جميع الحقول المطلوبة منك؟</p>
            </div>
            <div class="modal-footer">
                <button onclick="closeSignModal()" class="btn btn-secondary">إلغاء</button>
                <button onclick="completeDocument()" class="btn btn-success">تأكيد الإكمال</button>
            </div>
        </div>
    </div>

    <!-- نافذة إضافة مرفق -->
    <div id="attachModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-paperclip"></i> إضافة مرفق</h3>
                <span onclick="closeAttachModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div style="text-align:center; padding:15px;">
                    <!-- منطقة رفع الملفات -->
                    <div style="margin:15px 0; border:2px dashed #34db7c; padding:30px; border-radius:10px; background:#e3f2fd; cursor:pointer;"
                        id="attachUploadArea">
                        <i class="fas fa-cloud-upload-alt"
                            style="font-size:48px; color:#3498db; margin-bottom:15px;"></i>
                        <p>انقر لاختيار ملف</p>
                        <input type="file" id="attachmentFile" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                            style="display:none;">
                    </div>

                    <!-- منطقة المعاينة -->
                    <div id="attachPreview" style="margin:15px 0; display:none;">
                        <img id="attachPreviewImage"
                            style="max-width:100%; max-height:200px; border:1px solid #ddd; border-radius:5px; object-fit:contain; display:none;">
                        <div id="attachFileName"
                            style="font-size:16px; color:#333; background:#f5f5f5; padding:10px; border-radius:5px;">
                        </div>
                    </div>

                    <!-- أزرار التحكم -->
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button onclick="closeAttachModal()" class="btn btn-secondary" style="flex:1; min-width:100px;">
                            <i class="fas fa-times"></i> إلغاء
                        </button>
                        <button onclick="uploadAttachment()" class="btn btn-primary" style="flex:1; min-width:100px;">
                            <i class="fas fa-check"></i> رفع
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- نافذة تتبع المسار -->
    <div id="trackModal" class="modal-overlay" style="z-index: 4000;">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3><i class="fas fa-road"></i> تتبع مسار المستند</h3>
                <span onclick="closeTrackModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="trackTimeline"></div>
            </div>
            <div class="modal-footer">
                <button onclick="closeTrackModal()" class="btn btn-secondary">إغلاق</button>
            </div>
        </div>
    </div>

    <!-- نافذة تأكيد الحذف -->
    <div id="deleteConfirmModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3><i class="fas fa-trash"></i> تأكيد الحذف</h3>
                <span onclick="closeDeleteConfirmModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p id="deleteConfirmMessage">هل أنت متأكد من حذف هذه الملاحظة؟ هذا الإجراء لا يمكن التراجع عنه.</p>
            </div>
            <div class="modal-footer">
                <button onclick="closeDeleteConfirmModal()" class="btn btn-secondary">إلغاء</button>
                <button onclick="confirmDeleteNote()" class="btn btn-danger">حذف</button>
            </div>
        </div>
    </div>



    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

    <script>
        // تهيئة مكتبة PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // البيانات من PHP
        const docData = {
            id: <?= $document_id ?>,
            path: '<?= addslashes($file_path) ?>',
            exists: <?= $file_exists ? 'true' : 'false' ?>,
            ext: '<?= $file_ext ?>',
            fields: <?= json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            fieldValues: <?= json_encode($field_values, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            title: '<?= addslashes($document['title']) ?>',
            createdBy: <?= $document['created_by'] ?>
        };

        const user = {
            id: <?= $user_id ?>,
            role: '<?= addslashes($user_role) ?>'
        };

        const csrfToken = '<?= $_SESSION['csrf_token'] ?>';

        // متغيرات جافاسكريبت
        let pdfDoc = null;
        let currentScale = 1;
        let currentFieldId = null;
        let currentFieldType = null;
        let pageRects = {};
        let isDragging = false;
        let dragStartX = 0;
        let dragStartY = 0;
        let fieldStartX = 0;
        let fieldStartY = 0;
        let draggedField = null;

        // ============== دوال مساعدة ==============
        function getFieldIcon(type) {
            switch (type) {
                case 'signature':
                    return 'signature';
                case 'text':
                    return 'font';
                case 'date':
                    return 'calendar-alt';
                case 'note':
                    return 'sticky-note';
                case 'image':
                    return 'image';
                default:
                    return 'edit';
            }
        }

        function getFieldTypeName(type) {
            switch (type) {
                case 'signature':
                    return 'توقيع';
                case 'text':
                    return 'نص';
                case 'date':
                    return 'تاريخ';
                case 'note':
                    return 'ملاحظة';
                case 'image':
                    return 'صورة';
                default:
                    return 'حقل';
            }
        }

        function showNotification(message, type = 'info') {
            const oldNotif = document.querySelector('.notification');
            if (oldNotif) oldNotif.remove();

            const div = document.createElement('div');
            div.className = `notification ${type}`;
            div.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()" style="background:none; border:none; color:white; margin-right:auto; cursor:pointer;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            document.body.appendChild(div);

            setTimeout(() => {
                if (div.parentNode) {
                    div.style.animation = 'slideOut 0.3s';
                    setTimeout(() => {
                        if (div.parentNode) div.remove();
                    }, 300);
                }
            }, 5000);
        }

        // ============== تحميل وعرض PDF ==============
        async function loadPDF() {
            if (!docData.exists) return;

            try {
                pdfDoc = await pdfjsLib.getDocument(docData.path).promise;
                await renderPDF();
            } catch (e) {
                showNotification('خطأ في تحميل ملف PDF', 'error');
                console.error(e);
            }
        }

        async function renderPDF() {
            const container = document.getElementById('documentContainer');
            container.innerHTML = '';

            const targetWidth = 1100; // عرض ثابت للمستند

            for (let i = 1; i <= pdfDoc.numPages; i++) {
                const page = await pdfDoc.getPage(i);
                const viewport = page.getViewport({
                    scale: 1
                });

                // حساب مقياس التكبير بناءً على العرض الثابت
                const scale = (targetWidth / viewport.width) * currentScale;
                const scaledViewport = page.getViewport({
                    scale: scale
                });

                pageRects[i] = {
                    width: scaledViewport.width,
                    height: scaledViewport.height,
                    scale: scale,
                    originalWidth: viewport.width,
                    originalHeight: viewport.height
                };

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = scaledViewport.width;
                canvas.height = scaledViewport.height;

                await page.render({
                    canvasContext: ctx,
                    viewport: scaledViewport
                }).promise;

                const pageDiv = document.createElement('div');
                pageDiv.className = 'page';
                pageDiv.dataset.page = i;
                pageDiv.dataset.scale = scale;
                pageDiv.style.width = scaledViewport.width + 'px';
                pageDiv.style.height = scaledViewport.height + 'px';
                pageDiv.style.margin = '0 auto 20px';
                pageDiv.style.position = 'relative';
                pageDiv.appendChild(canvas);

                container.appendChild(pageDiv);
            }

            renderFields();
        }
        // ============== حساب موقع الحقل ==============
        function calculateFieldPosition(field, pageRect, isSigned = false) {
            // استخدام النسب المئوية
            const xPercent = field.x_percent || (field.x_position / 1100) * 100;
            const yPercent = field.y_percent || (field.y_position / 1550) * 100;
            const widthPercent = field.width_percent || (field.width / 1100) * 100;
            const heightPercent = field.height_percent || (field.height / 1550) * 100;

            // حساب القيم الحالية
            const currentX = (xPercent / 100) * pageRect.width;
            const currentY = (yPercent / 100) * pageRect.height;
            const currentWidth = (widthPercent / 100) * pageRect.width;
            const currentHeight = (heightPercent / 100) * pageRect.height;

            // إذا كان الحقل موقعاً، قم بتكبيرة 20%
            if (isSigned) {
                return {
                    x: currentX - (currentWidth * 0.1),
                    y: currentY - (currentHeight * 0.1),
                    width: currentWidth * 1.2,
                    height: currentHeight * 1.2
                };
            }

            return {
                x: currentX,
                y: currentY,
                width: currentWidth,
                height: currentHeight
            };
        }


        // ============== عرض الحقول ==============
        function renderFields() {
            document.querySelectorAll('.signature-field').forEach(f => f.remove());

            docData.fields.forEach(field => {
                const pageNum = field.page_number || 1;
                const pageRect = pageRects[pageNum];
                if (!pageRect) return;

                const fieldValue = docData.fieldValues.find(v => v.field_id == field.id);
                const isSigned = fieldValue !== undefined;
                const fieldType = field.field_type || 'signature';
                const canSign = field.assigned_to == user.id && !isSigned;

                // حساب الموقع باستخدام النسب المئوية
                const position = calculateFieldPosition(field, pageRect, isSigned);

                const pageElement = document.querySelector(`[data-page="${pageNum}"]`);
                if (!pageElement) return;

                const fieldDiv = document.createElement('div');
                fieldDiv.className = 'signature-field';

                if (isSigned) {
                    fieldDiv.classList.add('signed');
                } else if (!canSign) {
                    fieldDiv.classList.add('unauthorized');
                }

                fieldDiv.dataset.fieldId = field.id;
                fieldDiv.dataset.fieldType = fieldType;
                fieldDiv.dataset.page = pageNum;
                fieldDiv.dataset.xPercent = field.x_percent || (field.x_position / 1100) * 100;
                fieldDiv.dataset.yPercent = field.y_percent || (field.y_position / 1550) * 100;
                fieldDiv.dataset.widthPercent = field.width_percent || (field.width / 1100) * 100;
                fieldDiv.dataset.heightPercent = field.height_percent || (field.height / 1550) * 100;
                fieldDiv.dataset.originalWidth = field.width;
                fieldDiv.dataset.originalHeight = field.height;

                // تعيين الموضع والأبعاد
                fieldDiv.style.left = position.x + 'px';
                fieldDiv.style.top = position.y + 'px';
                fieldDiv.style.width = position.width + 'px';
                fieldDiv.style.height = position.height + 'px';

                // إنشاء أداة معلومات الحقل
                const fieldInfo = document.createElement('div');
                fieldInfo.className = 'field-info';

                let infoContent = '';
                if (isSigned && fieldValue) {
                    const signedDate = fieldValue.signed_at ?
                        new Date(fieldValue.signed_at).toLocaleDateString('ar-EG', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        }) : 'غير محدد';

                    infoContent = `
                <div class="info-line"><i class="fas fa-user-check"></i> ${fieldValue.signer_name || 'غير محدد'}</div>
                <div class="info-line"><i class="fas fa-calendar"></i> ${signedDate}</div>
            `;
                } else {
                    infoContent = `
                <div class="info-line"><i class="fas fa-user-tag"></i> ${field.assigned_name || 'غير محدد'}</div>
                <div class="info-line"><i class="fas fa-${getFieldIcon(fieldType)}"></i> ${getFieldTypeName(fieldType)}</div>
            `;
                }
                fieldInfo.innerHTML = infoContent;

                // محتوى الحقل
                let contentHtml = '';
                let showControls = false;

                if (isSigned && fieldValue) {
                    if (fieldType === 'signature' && fieldValue.value_data && fieldValue.value_data.startsWith('data:image')) {
                        contentHtml = `<img src="${fieldValue.value_data}" class="signature-image">`;
                    } else if (fieldType === 'note' && fieldValue.value_data) {
                        contentHtml = `
                    <div class="note-content">
                        ${fieldValue.value_data}
                    </div>
                `;
                    } else if (fieldType === 'image' && fieldValue.value_data && fieldValue.value_data.startsWith('data:image')) {
                        contentHtml = `<img src="${fieldValue.value_data}" class="signature-image">`;
                    } else if (fieldType === 'date' && fieldValue.value_data) {
                        contentHtml = `
                    <div class="signature-text" style="font-size:${Math.min(position.width * 0.15, position.height * 0.5)}px">
                        ${fieldValue.value_data}
                    </div>
                `;
                    } else if (fieldValue.value_data) {
                        contentHtml = `<div class="signature-text" style="font-size:${Math.min(position.width * 0.15, position.height * 0.5)}px">
                    ${fieldValue.value_data}
                </div>`;
                    }

                    // عرض أزرار التحكم للحقول الموقعة إذا كان المستخدم مسؤولاً
                    showControls = (user.role === 'admin' || user.role === 'board' || user.id == docData.createdBy || (field && field.assigned_to && user.id == field.assigned_to));
                } else {
                    const icon = getFieldIcon(fieldType);
                    const label = getFieldTypeName(fieldType);

                    contentHtml = `
                <div class="field-content">
                    <i class="fas fa-${icon}" style="font-size:${position.height * 0.4}px"></i>
                    <small>${label}</small>
                    ${canSign ? '<small style="font-size:8px">انقر للتعبئة</small>' : ''}
                </div>
            `;

                    // عرض أزرار التحكم للحقول غير الموقعة إذا كان المستخدم مسؤولاً
                    showControls = (user.role === 'admin' || user.role === 'board' || user.id == docData.createdBy || (field && field.assigned_to && user.id == field.assigned_to));
                }

                // بناء HTML للحقل
                let fieldHtml = `
            <div class="field-content">
                ${contentHtml}
            </div>
        `;

                // إضافة أزرار التحكم إذا كان المستخدم مسؤولاً
                if (showControls) {
                    fieldHtml += createFieldControls(field.id, isSigned, fieldType, field);
                }

                // إضافة زر حذف الملاحظة الموقعة إذا كان المستخدم مسؤولاً

                fieldHtml += `
                <button class="note-delete-btn" onclick="deleteNoteField('${field.id}')" title="حذف الملاحظة">
                    <i class="fas fa-times"></i>
                </button>
            `;


                fieldDiv.innerHTML = fieldHtml;

                // إضافة أداة المعلومات إلى الحقل
                fieldDiv.appendChild(fieldInfo);

                if (canSign) {
                    fieldDiv.style.cursor = 'pointer';
                    fieldDiv.onclick = () => openFieldModal(field.id, fieldType, getFieldTypeName(fieldType));
                }

                pageElement.appendChild(fieldDiv);

                // جعل الحقل قابلاً للسحب وإضافة أحداث التحكم إذا كان المستخدم مسؤولاً
                if (showControls) {
                    makeFieldDraggable(fieldDiv);
                    addControlEvents(fieldDiv);
                }
            });
        }
        // ============== إنشاء أزرار التحكم ==============
        function createFieldControls(fieldId, isSigned, fieldType, field) {
            let controls = `
        <div class="field-controls">
            <button class="control-btn zoom-in" data-field="${fieldId}" data-action="zoom-in" title="تكبير">
                <i class="fas fa-search-plus"></i>
            </button>
            <button class="control-btn zoom-out" data-field="${fieldId}" data-action="zoom-out" title="تصغير">
                <i class="fas fa-search-minus"></i>
            </button>
            <button class="control-btn reset" data-field="${fieldId}" data-action="reset" title="إعادة تعيين">
                <i class="fas fa-sync-alt"></i>
            </button>
    `;



            controls += `</div>`;
            return controls;
        }

        // ============== إضافة أحداث التحكم ==============
        function addControlEvents(fieldDiv) {
            const fieldId = fieldDiv.dataset.fieldId;
            const controls = fieldDiv.querySelector('.field-controls');

            if (!controls) return;

            controls.querySelectorAll('.control-btn').forEach(btn => {
                btn.onclick = (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    const action = btn.dataset.action;

                    switch (action) {
                        case 'zoom-in':
                            resizeField(fieldId, 1.2);
                            break;
                        case 'zoom-out':
                            resizeField(fieldId, 0.8);
                            break;
                        case 'reset':
                            resetFieldSize(fieldId);
                            break;
                        case 'delete':
                            deleteNoteField(fieldId);
                            break;
                    }
                };
            });
        }

        // ============== جعل الحقول قابلة للسحب ==============
        function makeFieldDraggable(element) {
            element.addEventListener('mousedown', startDrag);
            element.addEventListener('touchstart', startDragTouch);

            function startDrag(e) {
                if (e.target.closest('.control-btn')) return;

                e.preventDefault();
                isDragging = true;
                draggedField = element;

                dragStartX = e.clientX;
                dragStartY = e.clientY;
                fieldStartX = parseFloat(element.style.left) || 0;
                fieldStartY = parseFloat(element.style.top) || 0;

                element.style.zIndex = '1000';
                element.style.opacity = '0.8';

                document.addEventListener('mousemove', drag);
                document.addEventListener('mouseup', stopDrag);
            }

            function startDragTouch(e) {
                if (e.target.closest('.control-btn')) return;

                e.preventDefault();
                isDragging = true;
                draggedField = element;

                const touch = e.touches[0];
                dragStartX = touch.clientX;
                dragStartY = touch.clientY;
                fieldStartX = parseFloat(element.style.left) || 0;
                fieldStartY = parseFloat(element.style.top) || 0;

                element.style.zIndex = '1000';
                element.style.opacity = '0.8';

                document.addEventListener('touchmove', dragTouch);
                document.addEventListener('touchend', stopDrag);
            }

            function drag(e) {
                if (!isDragging || !draggedField) return;

                const dx = e.clientX - dragStartX;
                const dy = e.clientY - dragStartY;

                draggedField.style.left = (fieldStartX + dx) + 'px';
                draggedField.style.top = (fieldStartY + dy) + 'px';
            }

            function dragTouch(e) {
                if (!isDragging || !draggedField) return;
                e.preventDefault();

                const touch = e.touches[0];
                const dx = touch.clientX - dragStartX;
                const dy = touch.clientY - dragStartY;

                draggedField.style.left = (fieldStartX + dx) + 'px';
                draggedField.style.top = (fieldStartY + dy) + 'px';
            }

            async function stopDrag() {
                if (!isDragging || !draggedField) return;

                isDragging = false;
                draggedField.style.zIndex = '100';
                draggedField.style.opacity = '1';

                // حفظ الموقع الجديد
                const fieldId = draggedField.dataset.fieldId;
                const newX = parseFloat(draggedField.style.left);
                const newY = parseFloat(draggedField.style.top);

                await saveFieldPosition(fieldId, newX, newY);

                draggedField = null;
                document.removeEventListener('mousemove', drag);
                document.removeEventListener('mouseup', stopDrag);
                document.removeEventListener('touchmove', dragTouch);
                document.removeEventListener('touchend', stopDrag);
            }
        }

        // ============== تحجيم الحقل ==============
        async function resizeField(fieldId, scaleFactor) {
            const fieldElement = document.querySelector(`[data-field-id="${fieldId}"]`);
            if (!fieldElement) return;

            const currentWidth = parseFloat(fieldElement.style.width);
            const currentHeight = parseFloat(fieldElement.style.height);

            const newWidth = currentWidth * scaleFactor;
            const newHeight = currentHeight * scaleFactor;

            // حدود الحجم
            const minWidth = 30,
                minHeight = 20;
            const maxWidth = 300,
                maxHeight = 200;

            if (newWidth < minWidth || newHeight < minHeight ||
                newWidth > maxWidth || newHeight > maxHeight) {
                showNotification('الحجم خارج الحدود المسموحة', 'warning');
                return;
            }

            // حساب الإزاحة للحفاظ على المركز
            const centerX = parseFloat(fieldElement.style.left) + (currentWidth / 2);
            const centerY = parseFloat(fieldElement.style.top) + (currentHeight / 2);

            fieldElement.style.width = newWidth + 'px';
            fieldElement.style.height = newHeight + 'px';
            fieldElement.style.left = (centerX - (newWidth / 2)) + 'px';
            fieldElement.style.top = (centerY - (newHeight / 2)) + 'px';

            updateFieldAppearance(fieldElement);

            // حفظ الحجم الجديد
            await saveFieldSize(fieldId, newWidth, newHeight);
        }

        // ============== إعادة تعيين حجم الحقل ==============
        async function resetFieldSize(fieldId) {
            const fieldElement = document.querySelector(`[data-field-id="${fieldId}"]`);
            if (!fieldElement) return;

            const pageNum = fieldElement.dataset.page;
            const pageRect = pageRects[pageNum];
            if (!pageRect) return;

            const xPercent = parseFloat(fieldElement.dataset.xPercent);
            const yPercent = parseFloat(fieldElement.dataset.yPercent);
            const widthPercent = parseFloat(fieldElement.dataset.widthPercent);
            const heightPercent = parseFloat(fieldElement.dataset.heightPercent);

            // حساب القيم الأصلية
            const originalX = (xPercent / 100) * pageRect.width;
            const originalY = (yPercent / 100) * pageRect.height;
            const originalWidth = (widthPercent / 100) * pageRect.width;
            const originalHeight = (heightPercent / 100) * pageRect.height;

            // إذا كان الحقل موقعاً، قم بتكبيرة 20%
            if (fieldElement.classList.contains('signed')) {
                fieldElement.style.left = (originalX - (originalWidth * 0.1)) + 'px';
                fieldElement.style.top = (originalY - (originalHeight * 0.1)) + 'px';
                fieldElement.style.width = (originalWidth * 1.2) + 'px';
                fieldElement.style.height = (originalHeight * 1.2) + 'px';
            } else {
                fieldElement.style.left = originalX + 'px';
                fieldElement.style.top = originalY + 'px';
                fieldElement.style.width = originalWidth + 'px';
                fieldElement.style.height = originalHeight + 'px';
            }
            updateFieldAppearance(fieldElement);

            // حفظ الحجم الأصلي
            await saveFieldSize(fieldId, originalWidth, originalHeight);
        }

        // ============== حفظ موقع الحقل ==============
        async function saveFieldPosition(fieldId, x, y) {
            console.log('محاولة حفظ موقع الحقل:', fieldId, x, y);

            try {
                const fieldElement = document.querySelector(`[data-field-id="${fieldId}"]`);
                if (!fieldElement) {
                    console.error('الحقل غير موجود في DOM');
                    return;
                }

                const pageNum = fieldElement.dataset.page;
                const pageRect = pageRects[pageNum];

                if (!pageRect) {
                    console.error('صفحة غير موجودة:', pageNum);
                    return;
                }

                // حساب النسب المئوية الجديدة
                const newXPercent = (x / pageRect.width) * 100;
                const newYPercent = (y / pageRect.height) * 100;

                console.log('النسب المئوية الجديدة:', newXPercent, newYPercent);

                // تحديث dataset
                fieldElement.dataset.xPercent = newXPercent;
                fieldElement.dataset.yPercent = newYPercent;

                // حساب القيم الأصلية
                const originalX = (newXPercent / 100) * 1100;
                const originalY = (newYPercent / 100) * 1550;

                const formData = new FormData();
                formData.append('field_id', fieldId);
                formData.append('x_percent', newXPercent);
                formData.append('y_percent', newYPercent);
                formData.append('x_position', originalX);
                formData.append('y_position', originalY);
                formData.append('document_id', docData.id);

                console.log('إرسال البيانات إلى الخادم...');

                // إرسال الطلب
                const response = await fetch('../documents/update_field_position.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                console.log('استلام الاستجابة، الحالة:', response.status);

                // الحصول على النص الخام أولاً
                const responseText = await response.text();
                console.log('استجابة الخادم (نص):', responseText);

                // محاولة تحليل JSON
                try {
                    const result = JSON.parse(responseText);
                    console.log('استجابة الخادم (JSON):', result);

                    if (result.success) {

                        return true;
                    } else {
                        showNotification('خطأ: ' + (result.message || 'غير معروف'), 'error');
                        return false;
                    }
                } catch (parseError) {
                    console.error('فشل في تحليل JSON:', parseError);
                    console.error('النص الذي فشل تحليله:', responseText.substring(0, 200));
                    showNotification('استجابة غير صحيحة من الخادم', 'error');
                    return false;
                }

            } catch (networkError) {
                console.error('خطأ في الشبكة:', networkError);
                showNotification('تعذر الاتصال بالخادم', 'error');
                return false;
            }
        }
        // ============== حفظ حجم الحقل ==============
        async function saveFieldSize(fieldId, width, height) {
            try {
                const fieldElement = document.querySelector(`[data-field-id="${fieldId}"]`);
                const pageNum = fieldElement.dataset.page;
                const pageRect = pageRects[pageNum];

                if (!pageRect) return;

                // حساب النسب المئوية الجديدة
                const newWidthPercent = (width / pageRect.width) * 100;
                const newHeightPercent = (height / pageRect.height) * 100;

                // تحديث dataset
                fieldElement.dataset.widthPercent = newWidthPercent;
                fieldElement.dataset.heightPercent = newHeightPercent;

                // حساب القيم الأصلية
                const originalWidth = (newWidthPercent / 100) * 1100;
                const originalHeight = (newHeightPercent / 100) * 1550;

                const formData = new FormData();
                formData.append('field_id', fieldId);
                formData.append('width_percent', newWidthPercent);
                formData.append('height_percent', newHeightPercent);
                formData.append('width', originalWidth);
                formData.append('height', originalHeight);
                formData.append('document_id', docData.id);

                const response = await fetch('../documents/update_field_size.php', {
                    method: 'POST',
                    body: formData
                });

                // تحقق من أن الاستجابة JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('استجابة غير صحيحة من الخادم');
                }

                const result = await response.json();
                if (result.success) {
                    console.log('تم حفظ الحجم:', result.message);

                } else {
                    console.error('خطأ في حفظ الحجم:', result.message);
                    showNotification(result.message, 'error');
                }
            } catch (e) {
                console.error('خطأ في الاتصال:', e);
                showNotification('حدث خطأ في الاتصال بالخادم', 'error');
            }
        }
        // ============== دوال النوافذ المنبثقة ==============
        function openFieldModal(fieldId, fieldType, fieldLabel) {
            currentFieldId = fieldId;
            currentFieldType = fieldType;

            const modal = document.getElementById('fieldModal');
            const title = document.getElementById('modalTitle');
            const content = document.getElementById('modalContent');

            title.innerHTML = `<i class="fas fa-${getFieldIcon(fieldType)}"></i> ${fieldLabel}`;

            let modalContent = '';
            switch (fieldType) {
                case 'signature':
                    modalContent = `
                        <p>ارسم توقيعك في المساحة أدناه:</p>
                        <canvas id="signCanvas" class="signature-canvas"></canvas>
                        <div style="margin-top: 15px;">
                            <button onclick="clearCanvas()" class="btn" style="background: #e74c3c; color: white;">
                                <i class="fas fa-trash"></i> مسح
                            </button>
                        </div>
                    `;
                    break;
                case 'text':
                    modalContent = `
                        <p>أدخل النص:</p>
                        <input type="text" id="textInput" style="width: 100%; padding: 10px;" placeholder="أدخل النص هنا...">
                    `;
                    break;
                case 'date':
                    const today = new Date().toISOString().split('T')[0];
                    modalContent = `
                        <p>اختر التاريخ:</p>
                        <input type="date" id="dateInput" value="${today}" style="width: 100%; padding: 10px;">
                    `;
                    break;
                case 'image':
                    modalContent = `
                        <p>رفع صورة/ختم:</p>
                        <input type="file" id="imageInput" accept="image/*" style="width: 100%; padding: 10px; margin: 10px 0;">
                        <small style="color: #666; display: block; margin-bottom: 15px;">الحد الأقصى: 2MB</small>
                        <div id="imagePreview" style="margin: 10px 0; text-align: center;"></div>
                    `;

                    // إضافة حدث لمعاينة الصورة
                    setTimeout(() => {
                        document.getElementById('imageInput').addEventListener('change', function(e) {
                            const file = e.target.files[0];
                            if (file) {
                                if (file.size > 2 * 1024 * 1024) {
                                    alert('حجم الصورة كبير جداً. الحد الأقصى 2MB');
                                    this.value = '';
                                    return;
                                }

                                const reader = new FileReader();
                                reader.onload = function(e) {
                                    document.getElementById('imagePreview').innerHTML =
                                        '<img src="' + e.target.result + '" style="max-width: 200px; max-height: 150px; border: 1px solid #ddd; border-radius: 5px;">';
                                };
                                reader.readAsDataURL(file);
                            }
                        });
                    }, 100);
                    break;
                case 'note':
                    modalContent = `
                        <p>أدخل الملاحظة:</p>
                        <textarea id="noteInput" rows="4" style="width: 100%; padding: 10px;" placeholder="أدخل الملاحظة هنا..."></textarea>
                    `;
                    break;
            }

            content.innerHTML = modalContent;
            modal.style.display = 'flex';

            if (fieldType === 'signature') {
                setTimeout(() => initSignatureCanvas(), 100);
            }
        }

        function initSignatureCanvas() {
            const canvas = document.getElementById('signCanvas');
            const ctx = canvas.getContext('2d');

            canvas.width = canvas.offsetWidth;
            canvas.height = canvas.offsetHeight;

            ctx.strokeStyle = '#001496';
            ctx.lineWidth = 5;
            ctx.lineCap = 'round';

            let drawing = false;
            let lastX = 0;
            let lastY = 0;

            canvas.addEventListener('mousedown', (e) => {
                drawing = true;
                [lastX, lastY] = [e.offsetX, e.offsetY];
            });

            canvas.addEventListener('mousemove', (e) => {
                if (!drawing) return;
                ctx.beginPath();
                ctx.moveTo(lastX, lastY);
                ctx.lineTo(e.offsetX, e.offsetY);
                ctx.stroke();
                [lastX, lastY] = [e.offsetX, e.offsetY];
            });

            canvas.addEventListener('mouseup', () => drawing = false);
            canvas.addEventListener('mouseout', () => drawing = false);

            // لدعم اللمس
            canvas.addEventListener('touchstart', (e) => {
                e.preventDefault();
                drawing = true;
                const rect = canvas.getBoundingClientRect();
                lastX = e.touches[0].clientX - rect.left;
                lastY = e.touches[0].clientY - rect.top;
            });

            canvas.addEventListener('touchmove', (e) => {
                if (!drawing) return;
                e.preventDefault();
                const rect = canvas.getBoundingClientRect();
                const x = e.touches[0].clientX - rect.left;
                const y = e.touches[0].clientY - rect.top;
                ctx.beginPath();
                ctx.moveTo(lastX, lastY);
                ctx.lineTo(x, y);
                ctx.stroke();
                [lastX, lastY] = [x, y];
            });

            canvas.addEventListener('touchend', () => drawing = false);
        }

        function clearCanvas() {
            const canvas = document.getElementById('signCanvas');
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        async function submitFieldValue() {
            let value = '';

            switch (currentFieldType) {
                case 'signature':
                    const canvas = document.getElementById('signCanvas');
                    value = canvas.toDataURL();
                    break;
                case 'text':
                    value = document.getElementById('textInput').value.trim();
                    if (!value) {
                        showNotification('يرجى إدخال النص', 'warning');
                        return;
                    }
                    break;
                case 'date':
                    value = document.getElementById('dateInput').value;
                    if (!value) {
                        showNotification('يرجى اختيار التاريخ', 'warning');
                        return;
                    }
                    break;
                case 'image':
                    const imageInput = document.getElementById('imageInput');
                    if (!imageInput.files[0]) {
                        showNotification('يرجى اختيار صورة أولاً', 'warning');
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = async function(e) {
                        value = e.target.result;
                        await saveFieldValue(value);
                    };
                    reader.readAsDataURL(imageInput.files[0]);
                    return;
                case 'note':
                    value = document.getElementById('noteInput').value.trim();
                    if (!value) {
                        showNotification('يرجى إدخال الملاحظة', 'warning');
                        return;
                    }
                    break;
            }

            await saveFieldValue(value);
        }

        async function saveFieldValue(value) {
            try {
                const formData = new FormData();
                formData.append('field_id', currentFieldId);
                formData.append('document_id', docData.id);
                formData.append('user_id', user.id);
                formData.append('value_data', value);
                formData.append('field_type', currentFieldType);

                const response = await fetch('save_field_value.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.success) {

                    closeFieldModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(result.message || 'حدث خطأ', 'error');
                }
            } catch (e) {
                showNotification('حدث خطأ في الاتصال', 'error');
            }
        }

        function closeFieldModal() {
            document.getElementById('fieldModal').style.display = 'none';
        }

        function openSignModal() {
            document.getElementById('signModal').style.display = 'flex';
        }

        function closeSignModal() {
            document.getElementById('signModal').style.display = 'none';
        }

        function openAttachModal() {
            document.getElementById('attachModal').style.display = 'flex';
            document.getElementById('fileName').textContent = '';
            document.getElementById('attachmentFile').value = '';
        }

        function closeAttachModal() {
            document.getElementById('attachModal').style.display = 'none';
        }

        // ============== دوال المرفقات ==============
        document.getElementById('attachmentFile').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                document.getElementById('fileName').textContent = file.name;
            }
        });

        async function uploadAttachment() {
            const fileInput = document.getElementById('attachmentFile');
            const file = fileInput.files[0];

            if (!file) {
                showNotification('يرجى اختيار ملف أولاً', 'warning');
                return;
            }

            if (file.size > 10 * 1024 * 1024) {
                showNotification('حجم الملف كبير جداً (الحد الأقصى 10MB)', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('attachment', file);
            formData.append('document_id', docData.id);

            try {
                const response = await fetch('attach_file.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.success) {
                    showNotification('تم رفع المرفق بنجاح', 'success');
                    closeAttachModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(result.message || 'حدث خطأ', 'error');
                }
            } catch (e) {
                showNotification('حدث خطأ في الاتصال', 'error');
            }
        }

        function downloadAttachment(attachId, fileName) {
            window.open('download_attachment.php?id=' + attachId, '_blank');
        }


        async function deleteAttachment(attachId) {
            if (!confirm('هل أنت متأكد من حذف هذا المرفق؟ هذا الإجراء لا يمكن التراجع عنه.')) {
                return;
            }

            try {


                const response = await fetch('../documents/delete_attachment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `attachment_id=${attachId}&document_id=${docData.id}&csrf_token=${encodeURIComponent(csrfToken)}`
                });

                const result = await response.json();
                if (result.success) {

                    // إزالة عنصر المرفق من الصفحة
                    const attachmentElement = document.querySelector(`.attachment-wrapper[data-attachment-id="${attachId}"]`);
                    if (attachmentElement) {
                        attachmentElement.remove();
                    }
                } else {
                    showNotification(result.message || 'حدث خطأ', 'error');
                }
            } catch (e) {
                showNotification('حدث خطأ في الاتصال', 'error');
            }
        }

        // ============== معاينة الصور ==============
        function openImagePreview(imageSrc, imageName) {
            const modal = document.getElementById('imagePreviewModal');
            const image = document.getElementById('previewImage');
            const title = document.getElementById('imagePreviewTitle');
            const downloadLink = document.getElementById('downloadImageLink');

            image.src = imageSrc;
            title.textContent = imageName;
            downloadLink.href = imageSrc;
            downloadLink.download = imageName;

            modal.style.display = 'flex';
        }

        function closeImagePreview() {
            document.getElementById('imagePreviewModal').style.display = 'none';
        }

        // ============== دوال الزووم ==============
        function zoomIn() {
            if (!pdfDoc) return;
            currentScale = Math.min(3, currentScale + 0.1);
            updateZoom();
        }

        function zoomOut() {
            if (!pdfDoc) return;
            currentScale = Math.max(0.5, currentScale - 0.1);
            updateZoom();
        }

        function resetZoom() {
            if (!pdfDoc) return;
            currentScale = 1;
            updateZoom();
        }

        function updateZoom() {
            document.getElementById('zoomLevel').textContent = Math.round(currentScale * 100) + '%';
            if (pdfDoc) {
                renderPDF();
            }
        }

        // ============== دوال أخرى ==============
        function editDocument() {
            window.location.href = 'edit_document.php?id=' + docData.id;
        }

        function printDocument() {
            window.print();
        }

        function downloadDocument() {
            if (docData.exists) {
                const link = document.createElement('a');
                link.href = docData.path;
                link.download = docData.title + '.' + docData.ext;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        function refreshPage() {
            location.reload();
        }

        function goBack() {
            window.location.href = '<?= htmlspecialchars($redirect_url) ?>';
        }

        function closeModal() {
            if (window.opener) {
                window.close();
            } else {
                history.back();
            }
        }

        async function completeDocument() {
            try {
                showNotification('جاري معالجة طلب الإكمال...', 'info');

                const response = await fetch('complete_document.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `document_id=${docData.id}`
                });

                const result = await response.json();
                if (result.success) {
                    showNotification(result.message, 'success');
                    closeSignModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(result.message || 'حدث خطأ', 'error');
                }
            } catch (e) {
                showNotification('حدث خطأ في الاتصال', 'error');
            }
        }

        // ============== تحميل المرفقات PDF ==============
        async function loadAttachmentPDFs() {
            const pdfContainers = document.querySelectorAll('[id^="pdf-attachment-"]');

            for (const container of pdfContainers) {
                const attachId = container.id.replace('pdf-attachment-', '');
                const filePath = getAttachmentPath(attachId);

                if (filePath) {
                    await renderAttachmentPDF(filePath, container, attachId);
                }
            }
        }

        function getAttachmentPath(attachId) {
            <?php
            foreach ($attachments as $attach) {
                if (strpos($attach['file_type'], 'pdf') !== false) {
                    echo "if (attachId == '{$attach['id']}') return '{$attach['file_path']}';\n";
                }
            }
            ?>
            return null;
        }

        async function renderAttachmentPDF(filePath, container, attachId) {
            try {
                const pdfDoc = await pdfjsLib.getDocument(filePath).promise;
                container.innerHTML = '';

                const availableWidth = container.clientWidth - 40;

                for (let i = 1; i <= pdfDoc.numPages; i++) {
                    const page = await pdfDoc.getPage(i);
                    const viewport = page.getViewport({
                        scale: 1
                    });

                    const scale = Math.min(availableWidth / viewport.width, 1.2);
                    const scaledViewport = page.getViewport({
                        scale: scale
                    });

                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    canvas.width = scaledViewport.width;
                    canvas.height = scaledViewport.height;

                    await page.render({
                        canvasContext: ctx,
                        viewport: scaledViewport
                    }).promise;

                    const pageDiv = document.createElement('div');
                    pageDiv.className = 'page';
                    pageDiv.style.width = scaledViewport.width + 'px';
                    pageDiv.style.height = scaledViewport.height + 'px';
                    pageDiv.style.margin = '0 auto 15px';
                    pageDiv.style.border = '1px solid #ddd';
                    pageDiv.style.boxShadow = '0 2px 5px rgba(0,0,0,0.1)';
                    pageDiv.appendChild(canvas);

                    container.appendChild(pageDiv);
                }
            } catch (e) {
                console.error('خطأ في تحميل مرفق PDF:', e);
                container.innerHTML = '<p style="color: red; padding: 20px;">خطأ في تحميل ملف PDF</p>';
            }
        }

        // ============== بدء التحميل ==============
        document.addEventListener('DOMContentLoaded', function() {
            if (docData.exists && docData.ext === 'pdf') {
                loadPDF();
            } else if (docData.exists && ['jpg', 'jpeg', 'png', 'gif'].includes(docData.ext)) {
                // لملفات الصور
                const container = document.getElementById('documentContainer');
                const img = document.createElement('img');
                img.src = docData.path;
                img.style.maxWidth = '100%';
                img.style.height = 'auto';
                img.style.display = 'block';
                img.style.margin = '0 auto';
                container.appendChild(img);

                img.onload = function() {
                    const imgRect = img.getBoundingClientRect();
                    pageRects[1] = {
                        width: imgRect.width,
                        height: imgRect.height,
                        scale: 1,
                        originalWidth: img.naturalWidth,
                        originalHeight: img.naturalHeight
                    };
                    renderFields();
                };
            }

            setTimeout(() => {
                loadAttachmentPDFs();
            }, 1500);
        });

        // ============== أحداث النوافذ ==============
        window.onclick = function(event) {
            const fieldModal = document.getElementById('fieldModal');
            const signModal = document.getElementById('signModal');
            const attachModal = document.getElementById('attachModal');
            const imagePreviewModal = document.getElementById('imagePreviewModal');
            const trackModal = document.getElementById('trackModal');
            

            if (event.target === fieldModal) closeFieldModal();
            if (event.target === signModal) closeSignModal();
            if (event.target === attachModal) closeAttachModal();
            if (event.target === imagePreviewModal) closeImagePreview();
            if (event.target === trackModal) closeTrackModal();
            if (event.target === deleteConfirmModal) closeDeleteConfirmModal();
        };

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeFieldModal();
                closeSignModal();
                closeAttachModal();
                closeImagePreview();
                closeTrackModal();
            }
        });

        // دالة فتح تتبع المسار
        function openTrackModal() {
            fetch(`get_document_journey.php?document_id=<?= $document_id ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderTimeline(data.journey);
                        document.getElementById('trackModal').style.display = 'flex';
                    } else {
                        alert('خطأ في تحميل مسار المستند');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ أثناء تحميل المسار');
                });
        }

        // دالة إغلاق تتبع المسار
        function closeTrackModal() {
            document.getElementById('trackModal').style.display = 'none';
        }

        // دالة عرض المسار الزمني
        function renderTimeline(journey) {
            const container = document.getElementById('trackTimeline');
            let html = '<div class="timeline">';

            journey.forEach((step, index) => {
                const date = new Date(step.action_date).toLocaleString('ar-EG');
                const statusIcon = getStatusIcon(step.status_after);

                html += `
                    <div class="timeline-item ${index % 2 === 0 ? 'right' : 'left'}">
                        <div class="timeline-content">
                            <div class="timeline-header">
                                <span class="timeline-icon">${statusIcon}</span>
                                <span class="timeline-date">${date}</span>
                            </div>
                            <div class="timeline-body">
                                <p><strong>الإجراء:</strong> ${getActionLabel(step.action_type)}</p>
                                <p><strong>من:</strong> ${step.from_user_name || 'النظام'}</p>
                                <p><strong>إلى:</strong> ${step.to_user_name}</p>
                                ${step.notes ? `<p><strong>ملاحظة:</strong> ${step.notes}</p>` : ''}
                                <p><strong>الحالة:</strong> ${getStatusLabel(step.status_after)}</p>
                            </div>
                        </div>
                    </div>
                `;
            });

            html += '</div>';
            container.innerHTML = html;
        }

        function getActionLabel(action) {
            const labels = {
                'submit': 'إرسال',
                'review': 'مراجعة',
                'approve': 'موافقة',
                'reject': 'رفض',
                'return': 'إعادة',
                'complete': 'استكمال',
                'forward': 'إرسال'
            };
            return labels[action] || action;
        }

        function getStatusLabel(status) {
            const labels = {
                'pending': 'انتظار',
                'completion_required': 'مطلوب استكمال',
                'partially_signed': 'موقع جزئياً',
                'partially_completed': 'مكتمل جزئياً ⭐',
                'completed': 'مكتمل',
                'responded': 'تم الرد',
                'approved': 'موافق',
                'rejected': 'مرفوض'
            };
            return labels[status] || status;
        }

        function getStatusIcon(status) {
            const icons = {
                'pending': '⏳',
                'completion_required': '📝',
                'partially_signed': '🟡',
                'partially_completed': '⭐',
                'completed': '🟢',
                'responded': '🔵',
                'approved': '✅',
                'rejected': '❌'
            };
            return icons[status] || '📄';
        }

        // فتح تتبع المسار إذا كان معلمة track في URL
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('track') === '1') {
                setTimeout(() => {
                    openTrackModal();
                }, 1000);
            }
        });
        // ============== حذف ملاحظة ==============
        function deleteNoteField(fieldId) {
            openDeleteConfirmModal(fieldId);
        }

        // كائن لتخزين بيانات المرفقات (PDF فقط)
        const attachments = {};

        // تهيئة جميع المرفقات بعد تحميل الصفحة
        function initAttachments() {
            <?php foreach ($attachments as $attach): ?>
                <?php if (strpos($attach['file_type'], 'pdf') !== false): ?>
                    initializeAttachment(<?= $attach['id'] ?>, '<?= addslashes($attach['file_path']) ?>');
                <?php elseif (strpos($attach['file_type'], 'image') !== false): ?>
                    initializeImageAttachment(<?= $attach['id'] ?>);
                <?php endif; ?>
            <?php endforeach; ?>
        }

        // تهيئة مرفق PDF
        async function initializeAttachment(attachId, filePath) {
            if (attachments[attachId]) return;
            attachments[attachId] = {
                pdfDoc: null,
                scale: 1,
                container: document.getElementById(`attachment-container-${attachId}`),
                filePath: filePath
            };
            await loadAttachmentPDF(attachId);
        }

        async function loadAttachmentPDF(attachId) {
            const attach = attachments[attachId];
            if (!attach) return;
            try {
                attach.pdfDoc = await pdfjsLib.getDocument(attach.filePath).promise;
                renderAttachment(attachId);
            } catch (e) {
                console.error(e);
                attach.container.innerHTML = '<p style="color: red;">خطأ في تحميل ملف PDF</p>';
            }
        }

        async function renderAttachment(attachId) {
            const attach = attachments[attachId];
            if (!attach || !attach.pdfDoc) return;
            const container = attach.container;
            container.innerHTML = '';
            const scale = attach.scale;
            const containerWidth = container.clientWidth;

            for (let i = 1; i <= attach.pdfDoc.numPages; i++) {
                const page = await attach.pdfDoc.getPage(i);
                const viewport = page.getViewport({
                    scale: 1
                });
                // حساب scale المناسب لملء العرض مع تطبيق scale المطلوب
                const fitScale = (containerWidth / viewport.width) * scale;
                const scaledViewport = page.getViewport({
                    scale: fitScale
                });

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = scaledViewport.width;
                canvas.height = scaledViewport.height;

                await page.render({
                    canvasContext: ctx,
                    viewport: scaledViewport
                }).promise;

                const pageDiv = document.createElement('div');
                pageDiv.className = 'attachment-page';
                pageDiv.style.width = canvas.width + 'px';
                pageDiv.style.height = canvas.height + 'px';
                pageDiv.style.margin = '0 auto 15px';
                pageDiv.appendChild(canvas);
                container.appendChild(pageDiv);
            }
        }

        // دوال التحكم للمرفقات
        function attachmentZoomIn(attachId) {
            const attach = attachments[attachId];
            if (attach) {
                attach.scale = Math.min(3, attach.scale + 0.1);
                updateAttachmentZoom(attachId);
            } else {
                // إذا كان مرفق صورة نستخدم معالجة مختلفة
                zoomImage(attachId, 0.1);
            }
        }

        function attachmentZoomOut(attachId) {
            const attach = attachments[attachId];
            if (attach) {
                attach.scale = Math.max(0.5, attach.scale - 0.1);
                updateAttachmentZoom(attachId);
            } else {
                zoomImage(attachId, -0.1);
            }
        }

        function attachmentReset(attachId) {
            const attach = attachments[attachId];
            if (attach) {
                attach.scale = 1;
                updateAttachmentZoom(attachId);
            } else {
                resetImageZoom(attachId);
            }
        }

        function updateAttachmentZoom(attachId) {
            document.getElementById(`zoom-level-${attachId}`).textContent = Math.round(attachments[attachId].scale * 100) + '%';
            renderAttachment(attachId);
        }

        // دوال مساعدة للصور
        function initializeImageAttachment(attachId) {
            const container = document.getElementById(`attachment-container-${attachId}`);
            const img = container.querySelector('img');
            if (img) {
                img.dataset.scale = 1;
            }
        }

        function zoomImage(attachId, delta) {
            const container = document.getElementById(`attachment-container-${attachId}`);
            const img = container.querySelector('img');
            if (!img) return;
            let scale = parseFloat(img.dataset.scale || 1);
            scale = Math.min(3, Math.max(0.5, scale + delta));
            img.dataset.scale = scale;
            img.style.transform = `scale(${scale})`;
            document.getElementById(`zoom-level-${attachId}`).textContent = Math.round(scale * 100) + '%';
        }

        function resetImageZoom(attachId) {
            const container = document.getElementById(`attachment-container-${attachId}`);
            const img = container.querySelector('img');
            if (!img) return;
            img.dataset.scale = 1;
            img.style.transform = 'scale(1)';
            document.getElementById(`zoom-level-${attachId}`).textContent = '100%';
        }

        // تحميل المرفق
        function downloadAttachment(attachId, fileName) {
            window.open('download_attachment.php?id=' + attachId, '_blank');
        }

        // استدعاء التهيئة بعد تحميل المستند الأساسي
        document.addEventListener('DOMContentLoaded', function() {
            // ... الكود السابق ...
            setTimeout(() => {
                initAttachments();
            }, 1500);
        });

        function openPrintOrientationModal() {
            document.getElementById('printOrientationModal').style.display = 'flex';
        }

        function startPrint(orientation) {
            closePrintOrientationModal();
            showNotification('جاري تجهيز المستند للطباعة...', 'info');

            const iframe = document.createElement('iframe');
            iframe.style.position = 'absolute';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = 'none';
            iframe.style.visibility = 'hidden';
            document.body.appendChild(iframe);
            iframe.src = `print_document.php?id=<?= $document_id ?>&orientation=${orientation}`;

            let printTimeout = setTimeout(() => {
                showNotification('تأخر تحميل المستند، يرجى المحاولة مرة أخرى.', 'error');
                document.body.removeChild(iframe);
            }, 10000);

            window.addEventListener('message', function onMessage(event) {
                if (event.source !== iframe.contentWindow) return;
                if (event.data.type === 'printDocumentReady') {
                    clearTimeout(printTimeout);
                    try {
                        iframe.contentWindow.print();
                    } catch (e) {
                        showNotification('حدث خطأ أثناء الطباعة', 'error');
                    }
                    setTimeout(() => {
                        if (iframe.parentNode) document.body.removeChild(iframe);
                    }, 2000);
                } else if (event.data.type === 'printDocumentError') {
                    clearTimeout(printTimeout);
                    showNotification('حدث خطأ في تحميل المستند: ' + event.data.error, 'error');
                    document.body.removeChild(iframe);
                }
            });

            iframe.onerror = function() {
                clearTimeout(printTimeout);
                showNotification('فشل تحميل صفحة الطباعة', 'error');
                document.body.removeChild(iframe);
            };
        }

        // ============== طباعة المستند مع المرفقات والتواقيع ==============
        async function printCompleteDocument() {
            // إخفاء الأزرار العائمة وأدوات التحكم مؤقتاً
            const floatingActions = document.querySelector('.floating-actions');
            const zoomControls = document.querySelector('.zoom-controls');
            if (floatingActions) floatingActions.style.visibility = 'hidden';
            if (zoomControls) zoomControls.style.visibility = 'hidden';

            // إنشاء نافذة طباعة جديدة
            const printWindow = window.open('', '_blank');

            // كتابة رأس الصفحة والأنماط الأساسية
            printWindow.document.write(`
        <!DOCTYPE html>
        <html dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>طباعة المستند - ${docData.title}</title>
            <style>
                @page {
                    margin: 0.5cm;
                }
                body {
                    margin: 0;
                    padding: 0;
                    background: white;
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                }
               .print-page {
                    position: relative;
                    margin: 0 auto 20px;
                    page-break-after: always;
                    box-shadow: none;
                    background: white;
                    width: 100%; /* يجعل الصفحة تأخذ عرض الورقة بالكامل */
                    height: auto; /* الارتفاع يتناسب مع العرض */
                }
                .print-page:last-child {
                    page-break-after: avoid;
                }
                .page-content {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                }
                .document-field {
                    position: absolute;
                    border: none !important;
                    box-sizing: border-box;
                    overflow: hidden;
                }
                .field-text {
                    width: 100%;
                    height: 100%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    text-align: center;
                    padding: 2px;
                    font-size: 14px;
                    color: #000;
                }
                .field-image {
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                }
                /* إذا كان الحقل من نوع ملاحظة نحافظ على الخلفية الصفراء */
                .document-field[data-field-type="note"] .field-text {
                    background: #fff9c4;
                    border: 1px solid #f39c12;
                }
                /* عناوين المرفقات */
                .attachments-title {
                    text-align: center;
                    font-size: 18px;
                    margin: 30px 0 10px;
                    color: #2c3e50;
                }
            </style>
        </head>
        <body>
    `);

            // ===== طباعة صفحات المستند الرئيسي =====
            const pages = document.querySelectorAll('.page');

            for (let i = 0; i < pages.length; i++) {
                const page = pages[i];
                const pageNum = i + 1;
                const pageRect = pageRects[pageNum];

                if (!pageRect) continue;

                const pageWidth = pageRect.width;
                const pageHeight = pageRect.height;

                // الحصول على صورة الصفحة (canvas)
                const canvas = page.querySelector('canvas');
                let imageData = '';
                if (canvas) {
                    imageData = canvas.toDataURL('image/png');
                } else {
                    // إذا لم يكن هناك canvas (مثلاً في حالة الصور)
                    const img = page.querySelector('img');
                    if (img) imageData = img.src;
                }

                // بدء الصفحة في نافذة الطباعة
                printWindow.document.write(`
            <div class="print-page" style="width:${pageWidth}px; height:${pageHeight}px;">
                <img src="${imageData}" style="width:${pageWidth}px; height:${pageHeight}px; display:block;">
                <div class="page-content">
        `);

                // جلب جميع الحقول الموجودة داخل هذه الصفحة
                const fields = page.querySelectorAll('.signature-field');

                fields.forEach(field => {
                    // تجاهل حقول الاطلاع
                    const fieldType = field.dataset.fieldType;
                    if (fieldType === 'اطلاع') return;

                    // حساب الموقع الحقيقي بالنسبة للصفحة
                    const fieldRect = field.getBoundingClientRect();
                    const pageRectDom = page.getBoundingClientRect();

                    const x = fieldRect.left - pageRectDom.left;
                    const y = fieldRect.top - pageRectDom.top;
                    const width = fieldRect.width;
                    const height = fieldRect.height;

                    // استخراج المحتوى المعروض حالياً
                    let fieldContent = '';
                    const imgElement = field.querySelector('img');
                    const textElement = field.querySelector('.signature-text, .field-text, .note-content');
                    const iconElement = field.querySelector('i.fas');

                    if (imgElement) {
                        // صورة (توقيع، ختم، صورة)
                        fieldContent = `<img src="${imgElement.src}" class="field-image">`;
                    } else if (textElement) {
                        // نص أو تاريخ أو ملاحظة
                        fieldContent = `<div class="field-text">${textElement.textContent}</div>`;
                    } else if (iconElement) {
                        // حقل غير موقع (يظهر أيقونة ونوع الحقل)
                        const smallText = field.querySelector('small')?.textContent || fieldType || 'حقل';
                        fieldContent = `<div class="field-text">${smallText}</div>`;
                    } else {
                        fieldContent = `<div class="field-text">${fieldType || 'حقل'}</div>`;
                    }

                    // كتابة الحقل في نافذة الطباعة
                    printWindow.document.write(`
                <div class="document-field" data-field-type="${fieldType || ''}"
                     style="left:${x}px; top:${y}px; width:${width}px; height:${height}px;">
                    ${fieldContent}
                </div>
            `);
                });

                // إغلاق div الصفحة الحالية
                printWindow.document.write(`
                </div>
            </div>
        `);
            }

            // ===== طباعة المرفقات =====
            const attachments = document.querySelectorAll('.attachment-wrapper');

            if (attachments.length > 0) {


                for (const attach of attachments) {
                    const attachId = attach.dataset.attachmentId;
                    const container = attach.querySelector('.attachment-container');
                    if (!container) continue;

                    // إذا كان المرفق صورة
                    const img = container.querySelector('img');
                    if (img) {
                        const imgRect = img.getBoundingClientRect();
                        printWindow.document.write(`
                    <div class="print-page" style="width:${imgRect.width}px; height:${imgRect.height}px; margin:20px auto;">
                        <img src="${img.src}" style="width:100%; height:auto; display:block;">
                    </div>
                `);
                    }

                    // إذا كان المرفق PDF (يحتوي على canvases)
                    const canvases = container.querySelectorAll('canvas');
                    if (canvases.length > 0) {
                        for (const canvas of canvases) {
                            const canvasData = canvas.toDataURL('image/png');
                            const canvasWidth = canvas.width;
                            const canvasHeight = canvas.height;
                            printWindow.document.write(`
                        <div class="print-page" style="width:${canvasWidth}px; height:${canvasHeight}px; margin:20px auto;">
                            <img src="${canvasData}" style="width:100%; height:auto; display:block;">
                        </div>
                    `);
                        }
                    }
                }
            }

            // إغلاق الـ body و html
            printWindow.document.write(`
        </body>
        </html>
    `);

            printWindow.document.close();

            // بعد تحميل المحتوى، نقوم بالطباعة ثم إغلاق النافذة
            printWindow.onload = function() {
                setTimeout(() => {
                    printWindow.print();
                    setTimeout(() => {
                        printWindow.close();
                        // إعادة إظهار الأزرار بعد الطباعة
                        if (floatingActions) floatingActions.style.visibility = 'visible';
                        if (zoomControls) zoomControls.style.visibility = 'visible';
                    }, 1000);
                }, 1000);
            };
        }


        // ============== دوال نافذة إضافة المرفق (بالتصميم الجديد) ==============
        document.addEventListener('DOMContentLoaded', function() {
            const uploadArea = document.getElementById('attachUploadArea');
            const fileInput = document.getElementById('attachmentFile');
            const previewDiv = document.getElementById('attachPreview');
            const previewImg = document.getElementById('attachPreviewImage');
            const fileNameDiv = document.getElementById('attachFileName');

            if (uploadArea && fileInput) {
                // فتح نافذة اختيار الملف عند النقر على المنطقة
                uploadArea.addEventListener('click', () => fileInput.click());

                // معالجة اختيار الملف
                fileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        // التحقق من الحجم (اختياري)
                        if (file.size > 10 * 1024 * 1024) {
                            showNotification('حجم الملف كبير جداً (الحد الأقصى 10MB)', 'error');
                            fileInput.value = '';
                            return;
                        }

                        // عرض اسم الملف
                        fileNameDiv.textContent = file.name;
                        previewDiv.style.display = 'block';

                        // إذا كان الملف صورة، اعرض معاينة
                        if (file.type.startsWith('image/')) {
                            const reader = new FileReader();
                            reader.onload = function(ev) {
                                previewImg.src = ev.target.result;
                                previewImg.style.display = 'block';
                                fileNameDiv.style.display = 'none'; // إخفاء اسم الملف عند عرض الصورة
                            };
                            reader.readAsDataURL(file);
                        } else {
                            previewImg.style.display = 'none';
                            fileNameDiv.style.display = 'block';
                        }
                    } else {
                        previewDiv.style.display = 'none';
                        previewImg.src = '';
                        fileNameDiv.textContent = '';
                    }
                });
            }
        });

        // تحديث دالة فتح النافذة لإعادة التعيين
        function openAttachModal() {
            const modal = document.getElementById('attachModal');
            const fileInput = document.getElementById('attachmentFile');
            const previewDiv = document.getElementById('attachPreview');
            const previewImg = document.getElementById('attachPreviewImage');
            const fileNameDiv = document.getElementById('attachFileName');

            if (fileInput) fileInput.value = '';
            if (previewDiv) previewDiv.style.display = 'none';
            if (previewImg) previewImg.src = '';
            if (fileNameDiv) fileNameDiv.textContent = '';

            modal.style.display = 'flex';
        }

        // دالة الإغلاق (موجودة أصلاً)
        function closeAttachModal() {
            document.getElementById('attachModal').style.display = 'none';
        }


        // دالة لتحديث مظهر المحتوى الداخلي للحقل بعد تغيير الحجم
        function updateFieldAppearance(fieldElement) {
            if (!fieldElement) return;

            const fieldType = fieldElement.dataset.fieldType;
            const isSigned = fieldElement.classList.contains('signed');
            const currentWidth = parseFloat(fieldElement.style.width);
            const currentHeight = parseFloat(fieldElement.style.height);

            // تحديث النصوص (سواء كانت موقعة أو غير موقعة)
            const textElements = fieldElement.querySelectorAll('.signature-text, .note-content, .field-text');
            textElements.forEach(el => {
                let newFontSize;
                if (fieldType === 'note') {
                    newFontSize = Math.min(16, currentHeight * 0.3); // حجم مناسب للملاحظات
                } else {
                    newFontSize = Math.min(currentWidth * 0.15, currentHeight * 0.5);
                }
                el.style.fontSize = newFontSize + 'px';
            });

            // تحديث الأيقونات والنصوص الصغيرة للحقول غير الموقعة
            if (!isSigned) {
                const icon = fieldElement.querySelector('.field-content i.fas');
                if (icon) {
                    icon.style.fontSize = (currentHeight * 0.4) + 'px';
                }
                const smalls = fieldElement.querySelectorAll('.field-content small');
                smalls.forEach(small => {
                    small.style.fontSize = Math.max(8, currentHeight * 0.2) + 'px';
                });
            }
        }
        // متغير لتخزين معرف الملاحظة المراد حذفها
        let pendingDeleteFieldId = null;

        // فتح نافذة تأكيد الحذف
        function openDeleteConfirmModal(fieldId) {
            pendingDeleteFieldId = fieldId;
            document.getElementById('deleteConfirmModal').style.display = 'flex';
        }

        // إغلاق نافذة تأكيد الحذف
        function closeDeleteConfirmModal() {
            document.getElementById('deleteConfirmModal').style.display = 'none';
            pendingDeleteFieldId = null;
        }

        // تنفيذ الحذف بعد التأكيد
        async function confirmDeleteNote() {
            if (!pendingDeleteFieldId) return;

            const fieldId = pendingDeleteFieldId;
            closeDeleteConfirmModal(); // إغلاق النافذة أولاً

            // إظهار رسالة "جاري الحذف..."
            showNotification('جاري حذف الملاحظة...', 'info');

            try {
                const formData = new FormData();
                formData.append('field_id', fieldId);
                formData.append('document_id', docData.id);
                formData.append('csrf_token', csrfToken);

                const response = await fetch('delete_note.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showNotification('تم حذف الملاحظة بنجاح', 'success');

                    // إزالة الحقل من العرض
                    const fieldElement = document.querySelector(`[data-field-id="${fieldId}"]`);
                    if (fieldElement) {
                        fieldElement.remove();
                    }

                    // تحديث قائمة الحقول في البيانات
                    docData.fields = docData.fields.filter(f => f.id != fieldId);
                } else {
                    showNotification(result.message || 'حدث خطأ أثناء حذف الملاحظة', 'error');
                }
            } catch (e) {
                console.error('خطأ في حذف الملاحظة:', e);
                showNotification('حدث خطأ في الاتصال بالخادم', 'error');
            } finally {
                pendingDeleteFieldId = null;
            }
        }
    </script>
</body>

</html>