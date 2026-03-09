<?php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من أن المستخدم مسجل دخول وله صلاحية board
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], ['board', 'sub_board', 'private_board'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_name'];
$full_name = $_SESSION['full_name'];
$db = getDB();

// التحقق من معرف المستند
if (!isset($_GET['id'])) {
    die("معرف المستند غير محدد");
}
$document_id = intval($_GET['id']);

// جلب بيانات المستند
$stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
$stmt->execute([$document_id]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    die("المستند غير موجود");
}

// التحقق من أن المستند ليس مكتملاً أو مؤرشفاً
if (in_array($document['current_status'], ['completed', 'archived'])) {
    die("لا يمكن تحرير مستند مكتمل أو مؤرشف");
}

// جلب الحقول الحالية للمستند مع النسب المئوية
$stmt = $db->prepare("
    SELECT f.*, 
           COALESCE(f.x_percent, (f.x_position / 1100) * 100) as x_percent,
           COALESCE(f.y_percent, (f.y_position / 1550) * 100) as y_percent,
           COALESCE(f.width_percent, (f.width / 1100) * 100) as width_percent,
           COALESCE(f.height_percent, (f.height / 1550) * 100) as height_percent,
           u.full_name as assigned_name,
           v.value_data, v.signed_at, v2.full_name as signer_name
    FROM document_fields f
    LEFT JOIN users u ON f.assigned_to = u.id
    LEFT JOIN field_values v ON f.id = v.field_id AND v.id = (
        SELECT MAX(id) FROM field_values WHERE field_id = f.id
    )
    LEFT JOIN users v2 ON v.user_id = v2.id
    WHERE f.document_id = ?
    ORDER BY f.field_order ASC, f.created_at ASC
");
$stmt->execute([$document_id]);
$existing_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

// === جلب المرفقات ===
$stmt = $db->prepare("
    SELECT a.*, u.full_name as uploader_name 
    FROM document_attachments a
    LEFT JOIN users u ON a.uploaded_by = u.id
    WHERE a.document_id = ?
    ORDER BY a.uploaded_at DESC
");
$stmt->execute([$document_id]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دالة لتحديد نوع الملف
function getFileType($file_path)
{
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $document_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    if (in_array($extension, $image_extensions)) {
        return 'image';
    } elseif (in_array($extension, $document_extensions)) {
        return 'document';
    } else {
        return 'other';
    }
}

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



// جلب المدير المباشر والقسم الحالي للمستخدم (لخيار "مديري المباشر")
$manager_id = null;
$manager_name = "غير محدد";
$user_department_id = null;
$user_site = null;

$stmt = $db->prepare("SELECT supervisor_id, department_id, site FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$manager_id = $user['supervisor_id'] ?? null;
$user_department_id = $user['department_id'] ?? null;
$user_site = $user['site'] ?? null;

if ($manager_id) {
    $stmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$manager_id]);
    $manager = $stmt->fetch(PDO::FETCH_ASSOC);
    $manager_name = $manager['full_name'] ?? "غير محدد";
}
// جلب جميع المستخدمين (لاختيار الشخص المعين)
// جلب جميع المستخدمين من نفس القسم والموقع (زملاء العمل) - مثل صفحة الرفع
$all_users = [];
if ($user_department_id && $user_site) {
    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.title, r.role_name 
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE u.department_id = ? AND u.site = ? AND u.id != ? AND u.is_active = 1
        ORDER BY u.full_name
    ");
    $stmt->execute([$user_department_id, $user_site, $user_id]);
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// تحويل الحقول الحالية إلى تنسيق JSON لاستخدامها في JavaScript
$existing_fields_json = json_encode($existing_fields, JSON_UNESCAPED_UNICODE);
$all_users_json = json_encode($all_users, JSON_UNESCAPED_UNICODE);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحرير المستند - <?php echo htmlspecialchars($document['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Noto Kufi Arabic', sans-serif;
            font-weight: 300;

        }

        body {
            background: linear-gradient(135deg,
                    #164a40 0%,
                    rgba(140, 119, 79, 1) 50%,
                    rgba(140, 119, 79, 1) 100%);
            min-height: 100vh;
            padding: 20px;
        }



        .container {
            max-width: 1500px;
            margin: 0 auto;
            padding: 15px;
            position: relative;
            z-index: 1;
        }


        /* ===== الخلفية المتحركة (مأخوذة من dashboard.css) ===== */
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

        .header {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .edit-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }

        .edit-info i {
            color: #f39c12;
        }

        .upload-area {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            min-height: 800px;
        }

        .sidebar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .pdf-preview {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow: auto;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }

        .file-display {
            background: #e3f2fd;
            border: 2px solid #3498db;
            padding: 20px;
            text-align: center;
            border-radius: 8px;
        }

        .existing-fields-section {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
        }

        .existing-field-item {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .field-type-badge {
            background: #3498db;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-right: 5px;
        }

        .field-status {
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 12px;
        }

        .field-status.completed {
            background: #d4edda;
            color: #155724;
        }

        .field-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .tools-section {
            margin-top: 30px;
        }

        .tools-section h3 {
            margin-bottom: 15px;
            color: #2c3e50;
            border-bottom: 2px solid #eee;
            padding-bottom: 8px;
        }

        .tools-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .tool-item {
            background: #f8f9fa;
            border: 2px dashed #ddd;
            padding: 15px 10px;
            border-radius: 8px;
            cursor: move;
            text-align: center;
            transition: all 0.3s;
        }

        .tool-item:hover {
            border-color: #3498db;
            background: #e3f2fd;
        }

        .tool-item i {
            font-size: 24px;
            margin-bottom: 5px;
            color: #3498db;
        }

        .tool-item span {
            font-size: 14px;
            font-weight: 600;
        }

        .pdf-container {
            display: block;
        }

        .pdf-page-container {
            position: relative;
            margin: 20px auto;
            text-align: center;
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .pdf-canvas {
            width: 100%;
            height: auto;
            border: 1px solid #eee;
        }

        /* أنماط الحقول */
        .field-marker {
            position: absolute;
            z-index: 100;
            cursor: move;
            transition: transform 0.2s, opacity 0.2s;
        }

        .field-marker-inner {
            padding: 5px;
            min-width: 100px;
            min-height: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: all 0.3s;
            width: 100%;
            height: 100%;
            box-sizing: border-box;
        }

        /* الحقول الموجودة - مكتملة */
        .field-marker.existing.completed {
            border: none !important;
            background: none !important;
            box-shadow: none !important;
        }

        .field-marker.existing.completed .field-marker-inner {
            border: none;
            background: none;
        }

        /* الحقول الموجودة - غير مكتملة */
        .field-marker.existing.pending .field-marker-inner {
            background: rgba(241, 196, 15, 0.2);
            border: 2px dashed #090909;
        }

        /* الحقول الجديدة */
        .field-marker.new .field-marker-inner {
            background: rgba(7, 49, 188, 0.37);
            border: 2px dashed #060606;
        }

        /* أزرار التحكم في الحجم */
        .field-controls {
            position: absolute;
            top: -40px;
            right: 50%;
            transform: translateX(50%);
            display: none;
            gap: 5px;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            z-index: 1001;
            background: rgba(255, 255, 255, 0.95);
            padding: 5px;
            border-radius: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .field-marker:hover .field-controls {
            opacity: 1;
            pointer-events: auto;
        }

        .field-controls .control-btn {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #f5f5f5;
            color: #2c3e50;
            border: 2px solid #f5f5f5;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: all 0.3s;
            pointer-events: auto;
            opacity: 0.9;
        }

        .field-controls .control-btn:hover {
            transform: scale(1.1);
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        /* محتوى الحقول المعبأة */
        .field-content {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            word-break: break-word;
            overflow: hidden;
            padding: 2px;
            box-sizing: border-box;
        }

        .signature-image {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .note-content {
            width: 100%;
            height: 100%;
            padding: 5px;
            text-align: right;
            font-size: 11px;
            overflow: auto;
            background: #ffe600;
            border-radius: 3px;
        }

        .text-content {
            font-weight: bold;
            color: #001496;
            font-size: 14px;
            text-align: center;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            word-break: break-word;
        }

        /* زر الحذف للحقول الجديدة */
        .delete-field-btn {
            position: absolute;
            top: -12px;
            right: -12px;
            background: #e74c3c;
            color: white;
            border: 2px solid white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
            z-index: 1001;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        }

        .field-marker.new:hover .delete-field-btn {
            opacity: 1;
        }

        .delete-field-btn:hover {
            background: #c0392b;
            transform: scale(1.1);
        }

        /* أدوات الزووم العامة للصفحة */
        .zoom-controls {
            position: fixed;
            bottom: 50%;
            right: 30px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            z-index: 1000;
        }

        .zoom-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #417807;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all 0.3s;
        }

        .zoom-btn:hover {
            background: #3498db;
            color: white;
        }

        #zoomLevel {
            font-weight: 600;
            color: #2c3e50;
            min-width: 50px;
            text-align: center;
            font-size: 14px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #2ecc71;
            color: white;
        }

        .btn-primary:hover {
            background: #27ae60;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-warning:hover {
            background: #e67e22;
        }

        .actions {
            padding: 20px;
            text-align: center;
            border-top: 1px solid #eee;
            margin-top: 20px;
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        /* النوافذ المنبثقة */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 25px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        .close {
            color: #aaa;
            float: left;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute;
            left: 15px;
            top: 10px;
        }

        .close:hover {
            color: #000;
        }

        /* محسنات النوافذ */
        .select-with-search {
            position: relative;
            margin: 15px 0;
        }

        .select-with-search input {
            width: 100%;
            padding: 10px 40px 10px 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }

        .select-with-search i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }

        .user-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-top: 5px;
            display: none;
        }

        .user-list.visible {
            display: block;
        }

        .user-option {
            padding: 10px;
            cursor: pointer;
            transition: background 0.3s;
            border-bottom: 1px solid #eee;
        }

        .user-option:hover {
            background: #e3f2fd;
        }

        .user-option.selected {
            background: #d4edda;
        }

        .user-option i {
            margin-left: 8px;
            color: #3498db;
        }

        .selected-user-display {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            display: none;
        }

        .selected-user-display.visible {
            display: block;
        }

        .assign-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 15px 0;
        }

        .assign-option {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .assign-option:hover {
            background: #e3f2fd;
            border-color: #3498db;
        }

        .assign-option i {
            font-size: 24px;
            color: #3498db;
        }

        .assign-option-info {
            display: flex;
            flex-direction: column;
        }

        .assign-option-info span {
            font-weight: 600;
            color: #2c3e50;
        }

        .assign-option-info small {
            color: #666;
            font-size: 12px;
        }

        .text-input-area {
            margin: 15px 0;
        }

        .text-input-area textarea,
        .text-input-area input[type="text"],
        .text-input-area input[type="date"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            min-height: 40px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

         .floating-actions {
            position: fixed;
            bottom: 50%;
            left: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            z-index: 1000;
        }

        .floating-actions .btn1 {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #3498db;
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            transition: all 0.3s;
            font-size: 28px;
           
        }

        .floating-actions .btn1:hover {
        
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.65);
        }

    .floating-actions .btn1-secondary {
        background: linear-gradient(135deg, #de1405, #fc0c04);
        border: none;
        color: white;
    }

    .floating-actions .btn1-primary {
        background: linear-gradient(135deg, #2ecc71, #27ae60);
        border: none;
        color: white;
    }

        /* إضافة مسافة في أسفل المحتوى حتى لا يختفي خلف الشريط */
        .container {
            padding-bottom: 80px;
        }

        /* تحسين للجوال */
        @media (max-width: 768px) {
            .floating-actions {
                padding: 10px;
                gap: 10px;
            }

            .floating-actions .btn {
                min-width: 120px;
                padding: 10px 15px;
                font-size: 1rem;
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
        <div class="header">
            <h1><i class="fas fa-edit"></i> تحرير المستند - <?php echo htmlspecialchars($document['title']); ?></h1>
            <button type="button" class="btn btn-secondary"
                onclick="window.location.href='view_document.php?id=<?php echo $document_id; ?>'">
                <i class="fas fa-arrow-right"></i> العودة لعرض المستند
            </button>
        </div>

        <!--<div class="edit-info">
            <i class="fas fa-info-circle"></i>
            <strong>وضع التحرير:</strong> يمكنك تحريك وتكبير/تصغير جميع الحقول (الموجودة والجديدة).
            <span style="color: #2ecc71;">✓ الحقول المكتملة</span> |
            <span style="color: #f1c40f;">⏳ الحقول غير المكتملة</span> |
            <span style="color: #f39c12;">➕ الحقول الجديدة</span>
        </div> -->

        <form id="editForm" method="POST" action="process_edit.php" class="upload-area">
            <div class="sidebar">
                <input type="hidden" name="document_id" value="<?php echo $document_id; ?>">

                <!-- معلومات المستند -->
                <div class="form-group">
                    <label><i class="fas fa-file-alt"></i> معلومات المستند</label>
                    <div class="file-display">
                        <i class="fas fa-file-pdf" style="font-size: 48px; color: #3498db;"></i>
                        <p><strong><?php echo htmlspecialchars($document['title']); ?></strong></p>
                        <p style="font-size:14px;color:#666;margin-top:10px">
                            الحالة: <?php echo htmlspecialchars($document['current_status']); ?>
                        </p>
                        <p style="font-size:12px;color:#666;">
                            عدد الحقول: <?php echo count($existing_fields); ?>
                        </p>
                    </div>
                </div>

                <!-- الحقول الموجودة -->
                <div class="existing-fields-section">
                    <h4><i class="fas fa-list"></i> الحقول الموجودة (<?php echo count($existing_fields); ?>)</h4>
                    <?php if (empty($existing_fields)): ?>
                        <p style="text-align:center;color:#666;padding:20px">لا توجد حقول مضافة</p>
                    <?php else: ?>
                        <?php foreach ($existing_fields as $field): ?>
                            <div class="existing-field-item">
                                <div>
                                    <span class="field-type-badge">
                                        <?php
                                        echo $field['field_type'] === 'signature' ? 'توقيع' : ($field['field_type'] === 'text' ? 'نص' : ($field['field_type'] === 'date' ? 'تاريخ' : ($field['field_type'] === 'image' ? 'صورة' : 'ملاحظة')));
                                        ?>
                                    </span>
                                    <span><?php echo htmlspecialchars($field['label']); ?></span>
                                    <br>
                                    <small style="color:#666">
                                        <?php echo htmlspecialchars($field['assigned_name'] ?? 'غير معين'); ?>
                                    </small>
                                </div>
                                <div class="field-status <?php echo ($field['value_data'] ? 'completed' : 'pending'); ?>">
                                    <?php echo ($field['value_data'] ? 'مكتمل' : 'قيد الانتظار'); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- أدوات إضافة الحقول الجديدة -->
                <div class="tools-section">
                    <h3><i class="fas fa-plus-circle"></i> إضافة حقول جديدة</h3>
                    <div class="tools-list">
                        <div class="tool-item" draggable="true" data-type="توقيع">
                            <i class="fas fa-signature"></i>
                            <span>توقيع</span>
                        </div>
                        <div class="tool-item" draggable="true" data-type="تاريخ">
                            <i class="fas fa-calendar-alt"></i>
                            <span>تاريخ</span>
                        </div>
                        <div class="tool-item" draggable="true" data-type="نص">
                            <i class="fas fa-font"></i>
                            <span>رقم داخلي</span>
                        </div>
                        <div class="tool-item" draggable="true" data-type="ملاحظة">
                            <i class="fas fa-sticky-note"></i>
                            <span>ملاحظة</span>
                        </div>
                        <div class="tool-item" draggable="true" data-type="صورة">
                            <i class="fas fa-stamp"></i>
                            <span>صورة/ختم</span>
                        </div>
                    </div>
                </div>

                <!-- ملاحظة -->
                <div style="margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 8px;">
                    <i class="fas fa-lightbulb"></i>
                    <strong>ملاحظة:</strong> اسحب الحقل المطلوب وأفلته على المكان المناسب في المستند
                </div>
            </div>

            <!-- معاينة PDF -->
            <div class="pdf-preview">
                <div id="pdfContainer" class="pdf-container"></div>
            </div>

            <input type="hidden" name="new_fields_data" id="newFieldsData" value="[]">
            <input type="hidden" name="updated_existing_fields" id="updatedExistingFields" value="[]">
        </form>

        <div class="floating-actions">
             <button type="button" class="btn1 btn1-warning" onclick="saveEdit()">
                <i class="fas fa-save"></i>  
            </button>
            <button type="button" class="btn1 btn1-secondary" onclick="window.location.href='view_document.php?id=<?php echo $document_id; ?>'">
                <i class="fas fa-times"></i> 
            </button>
           
        </div>

       
    </div>

    <!-- أدوات الزووم العامة -->
    <!--  <div class="zoom-controls">
        <button class="zoom-btn" onclick="zoomInAll()" title="تكبير">
            <i class="fas fa-search-plus"></i>
        </button>
        <span id="zoomLevel">150%</span>
        <button class="zoom-btn" onclick="zoomOutAll()" title="تصغير">
            <i class="fas fa-search-minus"></i>
        </button>
        <button class="zoom-btn" onclick="resetZoomAll()" title="إعادة تعيين">
            <i class="fas fa-sync-alt"></i>
        </button>
    </div> -->

    <!-- نافذة التعيين (للتوقيع، التاريخ، الصورة) -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 style="margin-bottom:20px;color:#2c3e50" id="modalTitle">
                <i class="fas fa-user-plus"></i> تعيين حقل جديد
            </h3>
            <div id="modalBody">
                <!-- سيتم ملؤها بواسطة JavaScript -->
            </div>
        </div>
    </div>

    <!-- نافذة إدخال التاريخ -->
    <div id="dateModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeDateModal()">&times;</span>
            <h3 style="margin-bottom:20px;color:#2c3e50">
                <i class="fas fa-calendar-alt"></i> إدخال التاريخ
            </h3>
            <div class="text-input-area">
                <input type="date" id="dateInput" class="form-control" style="width:100%; padding:10px;">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeDateModal()">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button type="button" class="btn btn-primary" onclick="saveDate()">
                    <i class="fas fa-check"></i> حفظ
                </button>
            </div>
        </div>
    </div>

    <!-- نافذة إدخال النص -->
    <div id="textModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeTextModal()">&times;</span>
            <h3 style="margin-bottom:20px;color:#2c3e50">
                <i class="fas fa-font"></i> إدخال نص
            </h3>
            <div class="text-input-area">
                <input type="text" id="textInput" placeholder="أدخل النص هنا..." autofocus>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeTextModal()">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button type="button" class="btn btn-primary" onclick="saveText()">
                    <i class="fas fa-check"></i> حفظ
                </button>
            </div>
        </div>
    </div>

    <!-- نافذة إدخال الملاحظة -->
    <div id="noteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeNoteModal()">&times;</span>
            <h3 style="margin-bottom:20px;color:#2c3e50">
                <i class="fas fa-sticky-note"></i> إدخال ملاحظة
            </h3>

            <div class="select-with-search">
                <input type="text" id="userSearch" placeholder="ابحث عن مستخدم..." autocomplete="off">
                <i class="fas fa-search"></i>
                <div id="userList" class="user-list"></div>
            </div>

            <div id="selectedUserDisplay" class="selected-user-display">
                <i class="fas fa-user-check"></i>
                <span id="selectedUserName"></span>
                <button type="button" onclick="clearUserSelection()"
                    style="background:none;border:none;color:#e74c3c;cursor:pointer;margin-right:10px;">
                    <i class="fas fa-times"></i> إلغاء
                </button>
            </div>

            <div class="text-input-area">
                <textarea id="noteInput" placeholder="أدخل الملاحظة هنا... (اختياري)"></textarea>
                <small style="color:#666;display:block;margin-top:5px;">إذا تركتها فارغة سيتم استخدام "ملاحظة" كقيمة
                    افتراضية</small>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeNoteModal()">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button type="button" class="btn btn-primary" onclick="saveNote()">
                    <i class="fas fa-check"></i> حفظ
                </button>
            </div>
        </div>
    </div>

    <script>
        // تهيئة مكتبة PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // المتغيرات العامة
        let pdfDoc = null;
        let scale = 1.5;
        let newFields = [];
        let updatedExistingFields = [];
        let existingFields = <?php echo $existing_fields_json; ?>;
        let currentField = null;
        let pageViewports = {};

        const documentId = <?php echo $document_id; ?>;
       const allUsers = <?php echo json_encode($all_users, JSON_UNESCAPED_UNICODE); ?>;
        const currentUserId = <?php echo $user_id; ?>;
        const currentUserName = '<?php echo addslashes($full_name); ?>';
        const managerId = <?php echo json_encode($manager_id); ?>;
        const managerName = '<?php echo addslashes($manager_name); ?>';

        // دوال مساعدة
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

        // حساب موقع الحقل باستخدام النسب المئوية
        function calculateFieldPosition(field, pageRect, isSigned = false) {
            const xPercent = field.x_percent || (field.x_position / 1100) * 100;
            const yPercent = field.y_percent || (field.y_position / 1550) * 100;
            const widthPercent = field.width_percent || (field.width / 1100) * 100;
            const heightPercent = field.height_percent || (field.height / 1550) * 100;

            const currentX = (xPercent / 100) * pageRect.width;
            const currentY = (yPercent / 100) * pageRect.height;
            const currentWidth = (widthPercent / 100) * pageRect.width;
            const currentHeight = (heightPercent / 100) * pageRect.height;

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

        // عند تحميل الصفحة
        document.addEventListener('DOMContentLoaded', function() {
            initToolbarDrag();
            loadExistingPDF();
            initUserSearch();
        });

        // تحميل PDF الحالي
        async function loadExistingPDF() {
            try {
                const pdfUrl = 'get_pdf.php?id=' + documentId;
                const loadingTask = pdfjsLib.getDocument(pdfUrl);
                pdfDoc = await loadingTask.promise;

                await renderAllPages();
                renderExistingFields();

            } catch (error) {
                console.error('خطأ في تحميل PDF:', error);
                document.getElementById('pdfContainer').innerHTML = `
                    <div style="padding: 50px; text-align: center; color: #e74c3c;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 64px; margin-bottom: 20px;"></i>
                        <h3>تعذر تحميل ملف PDF</h3>
                        <p>${error.message}</p>
                        <button onclick="window.location.href='view_document.php?id=${documentId}'" class="btn btn-secondary" style="margin-top: 20px;">
                            <i class="fas fa-arrow-right"></i> العودة للعرض
                        </button>
                    </div>
                `;
            }
        }

        // عرض جميع صفحات PDF
        async function renderAllPages() {
            if (!pdfDoc) return;
            const pdfContainer = document.getElementById('pdfContainer');
            pdfContainer.innerHTML = '';

            for (let pageNum = 1; pageNum <= pdfDoc.numPages; pageNum++) {
                const page = await pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({
                    scale: scale
                });
                pageViewports[pageNum] = viewport;

                const pageContainer = document.createElement('div');
                pageContainer.className = 'pdf-page-container';
                pageContainer.dataset.pageNumber = pageNum;

                const canvas = document.createElement('canvas');
                canvas.className = 'pdf-canvas';
                canvas.width = viewport.width;
                canvas.height = viewport.height;

                const ctx = canvas.getContext('2d');
                await page.render({
                    canvasContext: ctx,
                    viewport: viewport
                }).promise;
                pageContainer.appendChild(canvas);
                pdfContainer.appendChild(pageContainer);

                initPageForDrop(pageContainer, pageNum, canvas, viewport);
            }
        }

        // تهيئة الصفحة لاستقبال الحقول المسقطة
        function initPageForDrop(pageContainer, pageNum, canvas, viewport) {
            pageContainer.addEventListener('dragover', function(e) {
                e.preventDefault();
            });

            pageContainer.addEventListener('drop', function(e) {
                e.preventDefault();
                const fieldType = e.dataTransfer.getData('text/plain');
                if (!fieldType) return;

                const rect = canvas.getBoundingClientRect();
                const scaleX = viewport.width / rect.width;
                const scaleY = viewport.height / rect.height;

                const x = (e.clientX - rect.left) * scaleX;
                const y = (e.clientY - rect.top) * scaleY;

                createNewField(x, y, fieldType, pageNum);
            });
        }

        // تهيئة شريط الأدوات للسحب
        function initToolbarDrag() {
            const toolItems = document.querySelectorAll('.tool-item[draggable="true"]');

            toolItems.forEach(item => {
                item.addEventListener('dragstart', function(e) {
                    e.dataTransfer.setData('text/plain', this.dataset.type);
                });
            });
        }

        // إنشاء حقل جديد
        function createNewField(x, y, type, pageNum) {
            const fieldId = 'new-field-' + Date.now();

            let width, height;
            switch (type) {
                case 'توقيع':
                    width = 100;
                    height = 40;
                    break;
                case 'تاريخ':
                    width = 80;
                    height = 30;
                    break;
                case 'نص':
                    width = 120;
                    height = 30;
                    break;
                case 'ملاحظة':
                    width = 150;
                    height = 60;
                    break;
                case 'صورة':
                    width = 60;
                    height = 60;
                    break;
                default:
                    width = 100;
                    height = 40;
            }

            const pageContainer = document.querySelector(`.pdf-page-container[data-page-number="${pageNum}"]`);
            const viewport = pageViewports[pageNum];
            const canvas = pageContainer.querySelector('canvas');
            const rect = canvas.getBoundingClientRect();
            const scaleX = viewport.width / rect.width;
            const scaleY = viewport.height / rect.height;

            const pdfX = x - (width / 2);
            const pdfY = y - (height / 2);

            const xPercent = (pdfX / viewport.width) * 100;
            const yPercent = (pdfY / viewport.height) * 100;
            const widthPercent = (width / viewport.width) * 100;
            const heightPercent = (height / viewport.height) * 100;

            const field = {
                id: fieldId,
                type: type,
                x: pdfX,
                y: pdfY,
                width: width,
                height: height,
                page: pageNum,
                assignedTo: null,
                assignedName: null,
                textValue: null,
                noteValue: null,
                signatureData: null,
                imageData: null,
                isNew: true,
                xPercent: xPercent,
                yPercent: yPercent,
                widthPercent: widthPercent,
                heightPercent: heightPercent
            };

            newFields.push(field);
            renderNewField(field);

            setTimeout(() => {
                if (field.type === 'نص') {
                    openTextModal(field);
                } else if (field.type === 'ملاحظة') {
                    openNoteModal(field);
                } else if (field.type === 'تاريخ') {
                    openAssignModal(field); // فتح نافذة اختيار المستخدم أولاً
                } else {
                    openAssignModal(field); // للتوقيع والصورة
                }
            }, 300);
        }

        // عرض الحقل الجديد
        function renderNewField(field) {
            const pageContainer = document.querySelector(`.pdf-page-container[data-page-number="${field.page}"]`);
            if (!pageContainer) return;

            const viewport = pageViewports[field.page];
            if (!viewport) return;

            const canvas = pageContainer.querySelector('canvas');
            if (!canvas) return;

            const rect = canvas.getBoundingClientRect();
            const scaleX = rect.width / viewport.width;
            const scaleY = rect.height / viewport.height;

            const displayX = field.x * scaleX;
            const displayY = field.y * scaleY;
            const displayWidth = field.width * scaleX;
            const displayHeight = field.height * scaleY;

            const existing = document.getElementById(field.id);
            if (existing) existing.remove();

            const fieldElement = document.createElement('div');
            fieldElement.id = field.id;
            fieldElement.className = 'field-marker new';
            fieldElement.style.left = displayX + 'px';
            fieldElement.style.top = displayY + 'px';
            fieldElement.style.width = displayWidth + 'px';
            fieldElement.style.height = displayHeight + 'px';

            let contentHtml = '';
            if (field.type === 'توقيع' && field.signatureData) {
                contentHtml = `<img src="${field.signatureData}" style="max-width:100%; max-height:100%; object-fit:contain;">`;
            } else if (field.type === 'تاريخ' && field.textValue) {
                contentHtml = `<div class="text-content">${field.textValue}</div>`;
            } else if (field.type === 'نص' && field.textValue) {
                contentHtml = `<div class="text-content">${field.textValue}</div>`;
            } else if (field.type === 'ملاحظة' && field.noteValue) {
                contentHtml = `<div class="note-content">${field.noteValue}</div>`;
            } else if (field.type === 'صورة' && field.imageData) {
                contentHtml = `<img src="${field.imageData}" style="max-width:100%; max-height:100%; object-fit:contain;">`;
            } else {
                const iconClass = field.type === 'توقيع' ? 'signature' :
                    field.type === 'تاريخ' ? 'calendar-alt' :
                    field.type === 'نص' ? 'font' :
                    field.type === 'ملاحظة' ? 'sticky-note' : 'stamp';
                contentHtml = `
                    <div style="width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                        <i class="fas fa-${iconClass}" style="font-size:${Math.min(displayHeight * 0.4, 24)}px"></i>
                        <span style="font-size:10px;">${field.type}</span>
                        ${field.assignedName ? `<small style="font-size:8px;">${field.assignedName}</small>` : ''}
                    </div>
                `;
            }

            fieldElement.innerHTML = `
                <div class="field-marker-inner">
                    ${contentHtml}
                    <button class="delete-field-btn" onclick="deleteNewField('${field.id}', event)"><i class="fas fa-times"></i></button>
                </div>
                <div class="field-controls">
                    <button class="control-btn zoom-in" onclick="resizeField('${field.id}', 1.2, event, false)"><i class="fas fa-search-plus"></i></button>
                    <button class="control-btn zoom-out" onclick="resizeField('${field.id}', 0.8, event, false)"><i class="fas fa-search-minus"></i></button>
                    <button class="control-btn reset" onclick="resetFieldSize('${field.id}', event, false)"><i class="fas fa-sync-alt"></i></button>
                </div>
            `;

            fieldElement.addEventListener('click', function(e) {
                if (fieldElement.dataset.dragging === 'true') return;
                if (!e.target.closest('.delete-field-btn') && !e.target.closest('.control-btn')) {
                    const clickedField = newFields.find(f => f.id === field.id);
                    if (clickedField) {
                        if (clickedField.type === 'نص') openTextModal(clickedField);
                        else if (clickedField.type === 'ملاحظة') openNoteModal(clickedField);
                        else if (clickedField.type === 'تاريخ') openAssignModal(clickedField);
                        else openAssignModal(clickedField);
                    }
                }
            });

            makeFieldDraggable(fieldElement, field, true);
            pageContainer.appendChild(fieldElement);
        }

        // عرض الحقول الموجودة (مع محتواها)
        function renderExistingFields() {
            existingFields.forEach(field => {
                const pageNum = field.page_number || 1;
                const pageContainer = document.querySelector(`.pdf-page-container[data-page-number="${pageNum}"]`);
                if (!pageContainer) return;

                const viewport = pageViewports[pageNum];
                if (!viewport) return;

                const canvas = pageContainer.querySelector('canvas');
                if (!canvas) return;

                const rect = canvas.getBoundingClientRect();

                const xPercent = parseFloat(field.x_percent) || (field.x_position / 1100) * 100;
                const yPercent = parseFloat(field.y_percent) || (field.y_position / 1550) * 100;
                const widthPercent = parseFloat(field.width_percent) || (field.width / 1100) * 100;
                const heightPercent = parseFloat(field.height_percent) || (field.height / 1550) * 100;

                const displayX = (xPercent / 100) * rect.width;
                const displayY = (yPercent / 100) * rect.height;
                const displayWidth = (widthPercent / 100) * rect.width;
                const displayHeight = (heightPercent / 100) * rect.height;

                const fieldElement = document.createElement('div');
                fieldElement.className = `field-marker existing ${field.value_data ? 'completed' : 'pending'}`;
                if (field.value_data && field.field_type === 'note') {
                    fieldElement.classList.add('completed-note');
                }
                fieldElement.dataset.fieldId = field.id;
                fieldElement.dataset.fieldType = field.field_type;
                fieldElement.dataset.xPercent = xPercent;
                fieldElement.dataset.yPercent = yPercent;
                fieldElement.dataset.widthPercent = widthPercent;
                fieldElement.dataset.heightPercent = heightPercent;
                fieldElement.style.left = displayX + 'px';
                fieldElement.style.top = displayY + 'px';
                fieldElement.style.width = displayWidth + 'px';
                fieldElement.style.height = displayHeight + 'px';

                let contentHtml = '';
                if (field.value_data) {
                    if (field.field_type === 'signature' && field.value_data.startsWith('data:image')) {
                        contentHtml = `<img src="${field.value_data}" class="signature-image">`;
                    } else if (field.field_type === 'text' || field.field_type === 'date') {
                        contentHtml = `<div class="text-content">${field.value_data}</div>`;
                    } else if (field.field_type === 'note') {
                        contentHtml = `<div class="note-content">${field.value_data}</div>`;
                    } else if (field.field_type === 'image' && field.value_data.startsWith('data:image')) {
                        contentHtml = `<img src="${field.value_data}" class="signature-image">`;
                    }
                } else {
                    const iconClass = field.field_type === 'signature' ? 'signature' :
                        field.field_type === 'date' ? 'calendar-alt' :
                        field.field_type === 'text' ? 'font' :
                        field.field_type === 'image' ? 'stamp' : 'sticky-note';
                    const fieldType = field.field_type === 'signature' ? 'توقيع' :
                        field.field_type === 'date' ? 'تاريخ' :
                        field.field_type === 'text' ? 'نص' :
                        field.field_type === 'image' ? 'صورة' : 'ملاحظة';
                    contentHtml = `
                        <div style="width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#666;">
                            <i class="fas fa-${iconClass}" style="font-size:${Math.min(displayHeight * 0.4, 24)}px"></i>
                            <span style="font-size:10px;">${fieldType}</span>
                            ${field.assigned_name ? `<small style="font-size:8px;">${field.assigned_name}</small>` : ''}
                        </div>
                    `;
                }

                fieldElement.innerHTML = `
                    <div class="field-marker-inner">
                        ${contentHtml}
                    </div>
                    <div class="field-controls">
                        <button class="control-btn zoom-in" onclick="resizeField('${field.id}', 1.2, event, true)" title="تكبير">
                            <i class="fas fa-search-plus"></i>
                        </button>
                        <button class="control-btn zoom-out" onclick="resizeField('${field.id}', 0.8, event, true)" title="تصغير">
                            <i class="fas fa-search-minus"></i>
                        </button>
                        <button class="control-btn reset" onclick="resetFieldSize('${field.id}', event, true)" title="إعادة تعيين">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                `;

                makeFieldDraggable(fieldElement, field, false);
                pageContainer.appendChild(fieldElement);
            });
        }

        // جعل الحقل قابلاً للسحب
        function makeFieldDraggable(element, fieldData, isNew) {
            let isDragging = false;
            let startX, startY;
            let initialLeft, initialTop;

            element.addEventListener('mousedown', function(e) {
                if (e.target.closest('.field-marker-inner') || e.target.closest('.field-content') ||
                    e.target.closest('.text-content') || e.target.closest('.note-content')) {

                    isDragging = true;
                    startX = e.clientX;
                    startY = e.clientY;

                    const rect = element.getBoundingClientRect();
                    const pageContainer = element.closest('.pdf-page-container');
                    const pageRect = pageContainer.getBoundingClientRect();

                    initialLeft = rect.left - pageRect.left;
                    initialTop = rect.top - pageRect.top;

                    element.style.opacity = '0.7';
                    element.dataset.dragging = 'true'; 

                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);

                    e.preventDefault();
                }
            });

            function onMouseMove(e) {
                if (!isDragging) return;

                const dx = e.clientX - startX;
                const dy = e.clientY - startY;

                let newLeft = initialLeft + dx;
                let newTop = initialTop + dy;

                const container = element.parentElement;
                const containerRect = container.getBoundingClientRect();

                const elementWidth = element.offsetWidth;
                const elementHeight = element.offsetHeight;
                const maxX = containerRect.width - elementWidth;
                const maxY = containerRect.height - elementHeight;

                newLeft = Math.max(0, Math.min(newLeft, maxX));
                newTop = Math.max(0, Math.min(newTop, maxY));

                element.style.left = newLeft + 'px';
                element.style.top = newTop + 'px';

                const viewport = pageViewports[fieldData.page || fieldData.page_number];
                const canvas = container.querySelector('canvas');
                const rect = canvas.getBoundingClientRect();
                const scaleX = viewport.width / rect.width;
                const scaleY = viewport.height / rect.height;

                if (isNew) {
                    const fieldIndex = newFields.findIndex(f => f.id === fieldData.id);
                    if (fieldIndex !== -1) {
                        newFields[fieldIndex].x = newLeft / scaleX;
                        newFields[fieldIndex].y = newTop / scaleY;
                        newFields[fieldIndex].xPercent = (newLeft / rect.width) * 100;
                        newFields[fieldIndex].yPercent = (newTop / rect.height) * 100;
                    }
                } else {
                    const originalX = newLeft / scaleX;
                    const originalY = newTop / scaleY;
                    const newXPercent = (newLeft / rect.width) * 100;
                    const newYPercent = (newTop / rect.height) * 100;

                    const fieldIndex = updatedExistingFields.findIndex(f => f.id === fieldData.id);
                    if (fieldIndex === -1) {
                        updatedExistingFields.push({
                            id: fieldData.id,
                            x_position: Math.round(originalX),
                            y_position: Math.round(originalY),
                            page_number: fieldData.page_number,
                            width: fieldData.width,
                            height: fieldData.height,
                            x_percent: newXPercent,
                            y_percent: newYPercent,
                            width_percent: fieldData.width_percent,
                            height_percent: fieldData.height_percent
                        });
                    } else {
                        updatedExistingFields[fieldIndex].x_position = Math.round(originalX);
                        updatedExistingFields[fieldIndex].y_position = Math.round(originalY);
                        updatedExistingFields[fieldIndex].x_percent = newXPercent;
                        updatedExistingFields[fieldIndex].y_percent = newYPercent;
                    }
                }
            }

            function onMouseUp() {
                if (isDragging) {
                    isDragging = false;
                    element.style.opacity = '1';
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    updateFieldsData();
                }
            }
        }

        // تغيير حجم الحقل
        function resizeField(fieldId, scaleFactor, e, isExisting = false) {
            if (e) e.stopPropagation();

            let element;
            if (isExisting) {
                element = document.querySelector(`[data-field-id="${fieldId}"]`);
            } else {
                element = document.getElementById(fieldId);
            }

            if (!element) return;

            const currentWidth = parseFloat(element.style.width);
            const currentHeight = parseFloat(element.style.height);

            const newWidth = currentWidth * scaleFactor;
            const newHeight = currentHeight * scaleFactor;

            const minWidth = 30,
                minHeight = 20;
            const maxWidth = 400,
                maxHeight = 200;

            if (newWidth < minWidth || newHeight < minHeight ||
                newWidth > maxWidth || newHeight > maxHeight) {
                alert('الحجم خارج الحدود المسموحة');
                return;
            }

            const centerX = parseFloat(element.style.left) + (currentWidth / 2);
            const centerY = parseFloat(element.style.top) + (currentHeight / 2);

            element.style.width = newWidth + 'px';
            element.style.height = newHeight + 'px';
            element.style.left = (centerX - (newWidth / 2)) + 'px';
            element.style.top = (centerY - (newHeight / 2)) + 'px';

            updateFieldSizeInData(element, newWidth, newHeight, isExisting ? fieldId : null);
        }

        function updateFieldSizeInData(element, newWidth, newHeight, fieldId = null) {
            const pageContainer = element.closest('.pdf-page-container');
            const pageNum = pageContainer.dataset.pageNumber;
            const viewport = pageViewports[pageNum];
            const canvas = pageContainer.querySelector('canvas');
            const rect = canvas.getBoundingClientRect();
            const scaleX = viewport.width / rect.width;
            const scaleY = viewport.height / rect.height;

            if (fieldId) {
                const fieldData = existingFields.find(f => f.id == fieldId);
                if (fieldData) {
                    const originalWidth = newWidth / scaleX;
                    const originalHeight = newHeight / scaleY;
                    const newWidthPercent = (newWidth / rect.width) * 100;
                    const newHeightPercent = (newHeight / rect.height) * 100;

                    const fieldIndex = updatedExistingFields.findIndex(f => f.id == fieldId);
                    if (fieldIndex === -1) {
                        updatedExistingFields.push({
                            id: fieldId,
                            x_position: fieldData.x_position,
                            y_position: fieldData.y_position,
                            page_number: fieldData.page_number,
                            width: Math.round(originalWidth),
                            height: Math.round(originalHeight),
                            x_percent: fieldData.x_percent,
                            y_percent: fieldData.y_percent,
                            width_percent: newWidthPercent,
                            height_percent: newHeightPercent
                        });
                    } else {
                        updatedExistingFields[fieldIndex].width = Math.round(originalWidth);
                        updatedExistingFields[fieldIndex].height = Math.round(originalHeight);
                        updatedExistingFields[fieldIndex].width_percent = newWidthPercent;
                        updatedExistingFields[fieldIndex].height_percent = newHeightPercent;
                    }
                }
            } else {
                const fieldId = element.id;
                const fieldIndex = newFields.findIndex(f => f.id === fieldId);
                if (fieldIndex !== -1) {
                    newFields[fieldIndex].width = newWidth / scaleX;
                    newFields[fieldIndex].height = newHeight / scaleY;
                    newFields[fieldIndex].widthPercent = (newWidth / rect.width) * 100;
                    newFields[fieldIndex].heightPercent = (newHeight / rect.height) * 100;
                }
            }
            updateFieldsData();
        }

        function resetFieldSize(fieldId, e, isExisting = false) {
            if (e) e.stopPropagation();

            let element;
            if (isExisting) {
                element = document.querySelector(`[data-field-id="${fieldId}"]`);
            } else {
                element = document.getElementById(fieldId);
            }

            if (!element) return;

            const pageContainer = element.closest('.pdf-page-container');
            const pageNum = pageContainer.dataset.pageNumber;
            const viewport = pageViewports[pageNum];
            const canvas = pageContainer.querySelector('canvas');
            const rect = canvas.getBoundingClientRect();

            if (isExisting) {
                const fieldData = existingFields.find(f => f.id == fieldId);
                if (fieldData) {
                    const xPercent = fieldData.x_percent;
                    const yPercent = fieldData.y_percent;
                    const widthPercent = fieldData.width_percent;
                    const heightPercent = fieldData.height_percent;

                    const displayX = (xPercent / 100) * rect.width;
                    const displayY = (yPercent / 100) * rect.height;
                    const displayWidth = (widthPercent / 100) * rect.width;
                    const displayHeight = (heightPercent / 100) * rect.height;

                    element.style.left = displayX + 'px';
                    element.style.top = displayY + 'px';
                    element.style.width = displayWidth + 'px';
                    element.style.height = displayHeight + 'px';

                    const fieldIndex = updatedExistingFields.findIndex(f => f.id == fieldId);
                    if (fieldIndex !== -1) {
                        updatedExistingFields.splice(fieldIndex, 1);
                    }
                }
            } else {
                const fieldIndex = newFields.findIndex(f => f.id === fieldId);
                if (fieldIndex !== -1) {
                    const field = newFields[fieldIndex];
                    const displayX = field.x * (rect.width / viewport.width);
                    const displayY = field.y * (rect.height / viewport.height);
                    const displayWidth = field.width * (rect.width / viewport.width);
                    const displayHeight = field.height * (rect.height / viewport.height);

                    element.style.left = displayX + 'px';
                    element.style.top = displayY + 'px';
                    element.style.width = displayWidth + 'px';
                    element.style.height = displayHeight + 'px';
                }
            }
            updateFieldsData();
        }

        function deleteNewField(fieldId, e) {
            if (e) e.stopPropagation();
            if (confirm('هل تريد حذف هذا الحقل الجديد؟')) {
                newFields = newFields.filter(f => f.id !== fieldId);
                const el = document.getElementById(fieldId);
                if (el) el.remove();
                updateFieldsData();
            }
        }

        // دوال الزووم العامة
        window.zoomInAll = function() {
            scale = Math.min(3, scale + 0.1);
            document.getElementById('zoomLevel').textContent = Math.round(scale * 100) + '%';
            renderAllPages();
            renderExistingFields();
            renderNewFields();
        };

        window.zoomOutAll = function() {
            scale = Math.max(0.5, scale - 0.1);
            document.getElementById('zoomLevel').textContent = Math.round(scale * 100) + '%';
            renderAllPages();
            renderExistingFields();
            renderNewFields();
        };

        window.resetZoomAll = function() {
            scale = 1.5;
            document.getElementById('zoomLevel').textContent = Math.round(scale * 100) + '%';
            renderAllPages();
            renderExistingFields();
            renderNewFields();
        };

        function renderNewFields() {
            newFields.forEach(field => renderNewField(field));
        }

        // ===== نوافذ التعيين المطورة (مثل document_upload.php) =====
        function openAssignModal(field) {
            currentField = field;
            let modalBody = '';

            // بناء خيارات المستخدمين الآخرين
            let otherUsersOptions = '';
            allUsers.forEach(user => {
                otherUsersOptions += `<option value="${user.id}">${user.full_name}</option>`;
            });

            if (field.type === 'نص') {
                openTextModal(field);
                return;
            } else if (field.type === 'ملاحظة') {
                modalBody = `
                    <div id="noteModalContent">
                        <p>اختر من سيتم تعيين حقل الملاحظة:</p>
                        <div class="assign-options">
                            <div class="assign-option" onclick="assignNoteToSelf()">
                                <i class="fas fa-user"></i>
                                <div class="assign-option-info">
                                    <span>نفسي (أنا)</span>
                                    <small>سأدخل ملاحظة لنفسي</small>
                                </div>
                            </div>
                            ${managerId ? `
                            <div class="assign-option" onclick="assignNoteToManager()">
                                <i class="fas fa-user-tie"></i>
                                <div class="assign-option-info">
                                    <span>مديري المباشر</span>
                                    <small>${managerName}</small>
                                </div>
                            </div>
                            ` : ''}
                            <div class="assign-option" onclick="showOtherUsersSelect('note')">
                                <i class="fas fa-users"></i>
                                <div class="assign-option-info">
                                    <span>استكمال من قبل</span>
                                    <small>اختر من القائمة</small>
                                </div>
                            </div>
                        </div>
                        <div id="otherUsersSelectContainer" style="margin-top:15px; display:none;">
                            <select id="otherUsersSelect" class="form-control" style="width:100%; padding:8px;">
                                <option value="">-- اختر مستخدم --</option>
                                ${otherUsersOptions}
                            </select>
                            <button class="btn btn-primary" style="margin-top:10px; width:100%;" onclick="assignNoteToOther()">تأكيد</button>
                        </div>
                    </div>
                `;
            } else if (field.type === 'تاريخ') {
                modalBody = `
                    <div id="dateAssignModal">
                        <p>اختر من سيتم تعيين حقل التاريخ:</p>
                        <div class="assign-options">
                            <div class="assign-option" onclick="assignDateToSelf()">
                                <i class="fas fa-user"></i>
                                <div class="assign-option-info">
                                    <span>ملئ من قبلي</span>
                                    <small>سأدخل التاريخ بنفسي</small>
                                </div>
                            </div>
                            ${managerId ? `
                            <div class="assign-option" onclick="assignDateToManager()">
                                <i class="fas fa-user-tie"></i>
                                <div class="assign-option-info">
                                    <span>مديري المباشر</span>
                                    <small>${managerName}</small>
                                </div>
                            </div>
                            ` : ''}
                            <div class="assign-option" onclick="showOtherUsersSelect('date')">
                                <i class="fas fa-users"></i>
                                <div class="assign-option-info">
                                    <span>استكمال من قبل</span>
                                    <small>اختر من القائمة</small>
                                </div>
                            </div>
                        </div>
                        <div id="otherUsersSelectContainer" style="margin-top:15px; display:none;">
                            <select id="otherUsersSelect" class="form-control" style="width:100%; padding:8px;">
                                <option value="">-- اختر مستخدم --</option>
                                ${otherUsersOptions}
                            </select>
                            <button class="btn btn-primary" style="margin-top:10px; width:100%;" onclick="assignDateToOther()">تأكيد</button>
                        </div>
                    </div>
                `;
            } else if (field.type === 'توقيع') {
                modalBody = `
                    <div id="signatureAssignModal">
                        <p>اختر من سيتم تعيين حقل التوقيع:</p>
                        <div class="assign-options">
                            <div class="assign-option" onclick="assignSignatureToSelf()">
                                <i class="fas fa-user"></i>
                                <div class="assign-option-info">
                                    <span>ملئ من قبلي</span>
                                    <small>سأوقع الآن</small>
                                </div>
                            </div>
                            ${managerId ? `
                            <div class="assign-option" onclick="assignSignatureToManager()">
                                <i class="fas fa-user-tie"></i>
                                <div class="assign-option-info">
                                    <span>مديري المباشر</span>
                                    <small>${managerName}</small>
                                </div>
                            </div>
                            ` : ''}
                            <div class="assign-option" onclick="showOtherUsersSelect('signature')">
                                <i class="fas fa-users"></i>
                                <div class="assign-option-info">
                                    <span>استكمال من قبل</span>
                                    <small>اختر من القائمة</small>
                                </div>
                            </div>
                        </div>
                        <div id="otherUsersSelectContainer" style="margin-top:15px; display:none;">
                            <select id="otherUsersSelect" class="form-control" style="width:100%; padding:8px;">
                                <option value="">-- اختر مستخدم --</option>
                                ${otherUsersOptions}
                            </select>
                            <button class="btn btn-primary" style="margin-top:10px; width:100%;" onclick="assignSignatureToOther()">تأكيد</button>
                        </div>
                    </div>
                `;
            } else if (field.type === 'صورة') {
                modalBody = `
                    <div id="imageAssignModal">
                        <p>اختر من سيتم تعيين حقل الصورة/الختم:</p>
                        <div class="assign-options">
                            <div class="assign-option" onclick="assignImageToSelf()">
                                <i class="fas fa-user"></i>
                                <div class="assign-option-info">
                                    <span>ملئ من قبلي</span>
                                    <small>سأضيف الصورة الآن</small>
                                </div>
                            </div>
                            ${managerId ? `
                            <div class="assign-option" onclick="assignImageToManager()">
                                <i class="fas fa-user-tie"></i>
                                <div class="assign-option-info">
                                    <span>مديري المباشر</span>
                                    <small>${managerName}</small>
                                </div>
                            </div>
                            ` : ''}
                            <div class="assign-option" onclick="showOtherUsersSelect('image')">
                                <i class="fas fa-users"></i>
                                <div class="assign-option-info">
                                    <span>استكمال من قبل</span>
                                    <small>اختر من القائمة</small>
                                </div>
                            </div>
                        </div>
                        <div id="otherUsersSelectContainer" style="margin-top:15px; display:none;">
                            <select id="otherUsersSelect" class="form-control" style="width:100%; padding:8px;">
                                <option value="">-- اختر مستخدم --</option>
                                ${otherUsersOptions}
                            </select>
                            <button class="btn btn-primary" style="margin-top:10px; width:100%;" onclick="assignImageToOther()">تأكيد</button>
                        </div>
                    </div>
                `;
            }

            document.getElementById('modalTitle').innerHTML = `<i class="fas fa-user-plus"></i> تعيين حقل ${field.type}`;
            document.getElementById('modalBody').innerHTML = modalBody;
            document.getElementById('assignModal').style.display = 'block';
        }

        // إظهار القائمة المنسدلة للمستخدم الآخر
        function showOtherUsersSelect(type) {
            document.getElementById('otherUsersSelectContainer').style.display = 'block';
            window.currentOtherType = type;
        }

        // ===== دوال التعيين للملاحظة =====
        function assignNoteToSelf() {
            closeModal();
            openNoteModal(currentField); // فتح نافذة الملاحظة مباشرة
        }

        function assignNoteToManager() {
            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].assignedTo = managerId;
                newFields[index].assignedName = managerName;
                newFields[index].noteValue = null;
                renderNewField(newFields[index]);
                updateFieldsData();
            }
            closeModal();
        }

        function assignNoteToOther() {
            const select = document.getElementById('otherUsersSelect');
            const selectedUserId = select.value;
            if (!selectedUserId) {
                alert('الرجاء اختيار مستخدم');
                return;
            }
            const selectedUserName = select.options[select.selectedIndex].text;

            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].assignedTo = selectedUserId;
                newFields[index].assignedName = selectedUserName;
                newFields[index].noteValue = null;
                renderNewField(newFields[index]);
                updateFieldsData();
            }
            closeModal();
        }

        // ===== دوال التعيين للتاريخ =====
        function assignDateToSelf() {
            closeModal(); // إغلاق نافذة التعيين
            openDateModal(currentField); // فتح نافذة التاريخ
        }

        function assignDateToManager() {
            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].assignedTo = managerId;
                newFields[index].assignedName = managerName;
                newFields[index].textValue = null;
                renderNewField(newFields[index]);
                updateFieldsData();
            }
            closeModal();
        }

        function assignDateToOther() {
            const select = document.getElementById('otherUsersSelect');
            const selectedUserId = select.value;
            if (!selectedUserId) {
                alert('الرجاء اختيار مستخدم');
                return;
            }
            const selectedUserName = select.options[select.selectedIndex].text;

            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].assignedTo = selectedUserId;
                newFields[index].assignedName = selectedUserName;
                newFields[index].textValue = null;
                renderNewField(newFields[index]);
                updateFieldsData();
            }
            closeModal();
        }

        // ===== دوال التعيين للتوقيع =====
        function assignSignatureToSelf() {
            closeModal();
            openSignatureModal();
        }

        function assignSignatureToManager() {
            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].assignedTo = managerId;
                newFields[index].assignedName = managerName;
                newFields[index].signatureData = null;
                renderNewField(newFields[index]);
                updateFieldsData();
            }
            closeModal();
        }

        function assignSignatureToOther() {
            const select = document.getElementById('otherUsersSelect');
            const selectedUserId = select.value;
            if (!selectedUserId) {
                alert('الرجاء اختيار مستخدم');
                return;
            }
            const selectedUserName = select.options[select.selectedIndex].text;

            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].assignedTo = selectedUserId;
                newFields[index].assignedName = selectedUserName;
                newFields[index].signatureData = null;
                renderNewField(newFields[index]);
                updateFieldsData();
            }
            closeModal();
        }

        // ===== دوال التعيين للصورة =====
        function assignImageToSelf() {
            closeModal();
            openImageModal();
        }

        function assignImageToManager() {
            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].assignedTo = managerId;
                newFields[index].assignedName = managerName;
                newFields[index].imageData = null;
                renderNewField(newFields[index]);
                updateFieldsData();
            }
            closeModal();
        }

        function assignImageToOther() {
            const select = document.getElementById('otherUsersSelect');
            const selectedUserId = select.value;
            if (!selectedUserId) {
                alert('الرجاء اختيار مستخدم');
                return;
            }
            const selectedUserName = select.options[select.selectedIndex].text;

            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].assignedTo = selectedUserId;
                newFields[index].assignedName = selectedUserName;
                newFields[index].imageData = null;
                renderNewField(newFields[index]);
                updateFieldsData();
            }
            closeModal();
        }

        // ===== نوافذ الإدخال =====
        function openTextModal(field) {
            currentField = field;
            document.getElementById('textInput').value = field.textValue || '';
            document.getElementById('textModal').style.display = 'block';
        }

        function saveText() {
            if (!currentField) return;
            const textValue = document.getElementById('textInput').value.trim();

            if (!textValue) {
                alert('يرجى إدخال النص');
                return;
            }

            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].textValue = textValue;
                newFields[index].assignedTo = currentUserId;
                newFields[index].assignedName = currentUserName;
                renderNewField(newFields[index]);
                updateFieldsData();
            }

            closeTextModal();
        }

        function closeTextModal() {
            document.getElementById('textModal').style.display = 'none';
        }

        function openNoteModal(field) {
            currentField = field;
            document.getElementById('noteInput').value = field.noteValue || '';
            document.getElementById('noteModal').style.display = 'block';
        }

        function initUserSearch() {
            const userSearch = document.getElementById('userSearch');
            const userList = document.getElementById('userList');
            const selectedUserDisplay = document.getElementById('selectedUserDisplay');
            const selectedUserName = document.getElementById('selectedUserName');

            userList.innerHTML = `
                <div class="user-option" data-user-id="${currentUserId}" onclick="selectUser(this)">
                    <i class="fas fa-user"></i> نفسي (أنا) - ${currentUserName}
                </div>
            `;

            allUsers.forEach(user => {
                if (user.id != currentUserId) {
                    userList.innerHTML += `
                        <div class="user-option" data-user-id="${user.id}" onclick="selectUser(this)">
                            <i class="fas fa-user-circle"></i> ${user.full_name} (${user.role_name})
                        </div>
                    `;
                }
            });

            userSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const options = userList.querySelectorAll('.user-option');

                options.forEach(option => {
                    const text = option.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                });

                userList.classList.add('visible');
            });

            document.addEventListener('click', function(e) {
                if (!userSearch.contains(e.target) && !userList.contains(e.target)) {
                    userList.classList.remove('visible');
                }
            });
        }

        function selectUser(element) {
            const userList = document.getElementById('userList');
            const selectedUserDisplay = document.getElementById('selectedUserDisplay');
            const selectedUserName = document.getElementById('selectedUserName');
            const userSearch = document.getElementById('userSearch');

            userList.querySelectorAll('.user-option').forEach(opt => {
                opt.classList.remove('selected');
            });

            element.classList.add('selected');

            selectedUserName.textContent = element.textContent;
            selectedUserDisplay.classList.add('visible');
            selectedUserDisplay.dataset.userId = element.dataset.userId;

            userSearch.value = '';
            userList.classList.remove('visible');
        }

        function clearUserSelection() {
            const selectedUserDisplay = document.getElementById('selectedUserDisplay');
            const userList = document.getElementById('userList');

            selectedUserDisplay.classList.remove('visible');
            delete selectedUserDisplay.dataset.userId;

            userList.querySelectorAll('.user-option').forEach(opt => {
                opt.classList.remove('selected');
            });
        }

        function saveNote() {
            if (!currentField) return;

            const selectedUserDisplay = document.getElementById('selectedUserDisplay');
            const noteValue = document.getElementById('noteInput').value.trim();

            if (!selectedUserDisplay.classList.contains('visible')) {
                alert('يرجى اختيار الشخص المعين للملاحظة');
                return;
            }

            const userId = selectedUserDisplay.dataset.userId;
            const userName = document.getElementById('selectedUserName').textContent;
            const finalNoteValue = noteValue || 'ملاحظة';

            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].assignedTo = userId;
                newFields[index].assignedName = userName;
                newFields[index].noteValue = finalNoteValue;

                renderNewField(newFields[index]);
                updateFieldsData();
            }

            closeNoteModal();
        }

        function closeNoteModal() {
            document.getElementById('noteModal').style.display = 'none';
        }

        // ===== نافذة التاريخ =====
        function openDateModal(field) {
            currentField = field;
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('dateInput').value = field.textValue || today;
            document.getElementById('dateModal').style.display = 'block';
        }

        function saveDate() {
            if (!currentField) return;
            const dateValue = document.getElementById('dateInput').value;

            if (!dateValue) {
                alert('يرجى إدخال التاريخ');
                return;
            }

            const dateObj = new Date(dateValue);
            const formattedDate = dateObj.toLocaleDateString('ar-EG');

            const index = newFields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                newFields[index].textValue = formattedDate;
                newFields[index].assignedTo = currentUserId;
                newFields[index].assignedName = currentUserName;
                renderNewField(newFields[index]);
                updateFieldsData();
            }

            closeDateModal();
        }

        function closeDateModal() {
            document.getElementById('dateModal').style.display = 'none';
        }

        // ===== نافذة التوقيع (اختصاراً) =====
        function openSignatureModal() {
            // يمكنك إضافة نافذة التوقيع هنا إذا أردت، ولكن خارج نطاق السؤال الحالي.
            alert('نافذة التوقيع قيد التطوير');
        }

        // ===== نافذة الصورة (اختصاراً) =====
        function openImageModal() {
            alert('نافذة الصورة قيد التطوير');
        }

        // ===== إغلاق النوافذ =====
        function closeModal() {
            document.getElementById('assignModal').style.display = 'none';
        }

        // ===== تحديث بيانات الحقول المخفية =====
        function updateFieldsData() {
            document.getElementById('newFieldsData').value = JSON.stringify(newFields);
            document.getElementById('updatedExistingFields').value = JSON.stringify(updatedExistingFields);
        }

        // ===== حفظ التحرير =====
        function saveEdit() {
            if (newFields.length === 0 && updatedExistingFields.length === 0) {
                if (!confirm('لم تقم بإجراء أي تغييرات. هل تريد العودة بدون حفظ؟')) {
                    return;
                }
                window.location.href = 'view_document.php?id=' + documentId;
                return;
            }

            const unassignedFields = newFields.filter(f => !f.assignedTo);
            if (unassignedFields.length > 0) {
                alert('يرجى تعيين جميع الحقول الجديدة قبل الحفظ');
                return;
            }

            updateFieldsData();
            document.getElementById('editForm').submit();
        }
    </script>
</body>

</html>