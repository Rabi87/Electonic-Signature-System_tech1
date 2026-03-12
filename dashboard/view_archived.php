<?php
// view_archived.php - عرض نسخة مؤرشفة من المستند (snapshot)
require_once '../includes/session.php';
require_once '../includes/config.php';
require_once '../includes/database.php';

checkLogin();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$archive_id = $_GET['id'] ?? 0;
if (!$archive_id) {
    die("معرف غير صحيح");
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// جلب معلومات الأرشفة والتحقق من ملكية المستخدم
$stmt = $db->prepare("
    SELECT ua.*, d.title, d.description, d.created_at, d.current_status, d.priority,
           u.full_name as creator_name
    FROM user_archives ua
    JOIN documents d ON ua.document_id = d.id
    LEFT JOIN users u ON d.created_by = u.id
    WHERE ua.id = ? AND ua.user_id = ?
");
$stmt->execute([$archive_id, $user_id]);
$archive = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$archive) {
    die("الملف غير موجود أو لا تملك صلاحية الوصول");
}

$document_id = $archive['document_id'];
$archivedFilePath = $archive['archived_file_path'];
$file_path = $archivedFilePath ?: $archive['file_path']; // استخدم المؤرشف إن وجد، وإلا الأصلي

// التحقق من وجود الملف
$file_exists = false;
$file_paths_to_try = [
    $file_path,
    '../' . $file_path,
    '../../' . $file_path,
    '../../../' . $file_path,
    basename($file_path),
    'uploads/' . basename($file_path),
    '../uploads/' . basename($file_path),
];

foreach ($file_paths_to_try as $path) {
    if ($path && file_exists($path)) {
        $file_path = $path;
        $file_exists = true;
        break;
    }
}

$file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

// جلب الحقول (document_fields) لهذا المستند
$stmt = $db->prepare("
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

// جلب قيم الحقول الموقعة (field_values)
$stmt = $db->prepare("
    SELECT v.*, u.full_name as signer_name
    FROM field_values v
    LEFT JOIN users u ON v.user_id = u.id
    WHERE v.field_id IN (SELECT id FROM document_fields WHERE document_id = ?)
    ORDER BY v.signed_at ASC
");
$stmt->execute([$document_id]);
$field_values = $stmt->fetchAll();

// إنشاء خريطة field_id => value للوصول السريع
$valueMap = [];
foreach ($field_values as $fv) {
    $valueMap[$fv['field_id']] = $fv;
}

// تصفية الحقول: نعرض فقط الحقول التي لها قيم (موقعة)
$fieldsWithValues = array_filter($fields, function($f) use ($valueMap) {
    return isset($valueMap[$f['id']]);
});

// إعادة ترتيب المفاتيح (اختياري)
$fieldsWithValues = array_values($fieldsWithValues);

// دوال مساعدة
function getFieldIcon($type) {
    switch ($type) {
        case 'signature': return 'signature';
        case 'text': return 'font';
        case 'date': return 'calendar-alt';
        case 'note': return 'sticky-note';
        case 'image': return 'image';
        default: return 'edit';
    }
}

function getFieldTypeName($type) {
    switch ($type) {
        case 'signature': return 'توقيع';
        case 'text': return 'نص';
        case 'date': return 'تاريخ';
        case 'note': return 'ملاحظة';
        case 'image': return 'صورة';
        default: return 'حقل';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>عرض نسخة مؤرشفة - <?= htmlspecialchars($archive['title']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/print.css">
    <style>
        :root {
            --primary-color: #2b4438;
            --secondary-color: #2b4438;
            --accent-color: #8c774f;
        }
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Cairo', sans-serif; }
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #164a40 0%, rgba(140,119,79,1) 50%, rgba(140,119,79,1) 100%);
            padding: 1rem;
            overflow: hidden;
        }
        .background-animation {
            position: fixed;
            top:0; left:0; right:0; bottom:0;
            background: radial-gradient(circle at 20% 50%, rgba(22,74,64,0.4) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(140,119,79,0.3) 0%, transparent 50%),
                        radial-gradient(circle at 40% 40%, rgba(45,106,90,0.5) 0%, transparent 50%);
            animation: gradientShift 15s ease infinite;
            z-index: -1;
        }
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding-bottom: 20px;
        }
        .header-info {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 15px 25px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
            flex-shrink: 0;
        }
        .header-info h2 {
            font-size: 1.5rem;
            margin: 0;
        }
        .header-info .badge {
            background: #9b59b6;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 0.9rem;
        }
        .document-wrapper {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(5px);
            border-radius: 20px;
            padding: 20px;
            border: 1px solid rgba(255,255,255,0.1);
            flex: 1 1 auto;
            overflow-y: auto;
            overflow-x: auto;
            min-height: 0;
        }
        #documentContainer {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: fit-content;
            margin: 0 auto;
        }
        .page {
            position: relative;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border-radius: 10px;
            overflow: hidden;
        }
        .signature-field {
            position: absolute;
            border: 2px solid rgba(155, 89, 182, 0.5);
            background: rgba(255,255,255,0.1);
          
            border-radius: 5px;
            pointer-events: none;
            box-sizing: border-box;
            overflow: hidden;
        }
        .signature-field.signed {
            border: 2px solid #2ecc71;
            background: rgba(46, 204, 113, 0.1);
        }
        .field-content {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .signature-image {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .signature-text {
            color: #001496;
            background: rgba(255,255,255,0.8);
            padding: 5px;
            border-radius: 3px;
            font-size: 14px;
            font-weight: 500;
        }
        .note-content {
            background: #fff9c4;
            color: #333;
            padding: 10px;
            border-radius: 5px;
            width: 100%;
            height: 100%;
            overflow: auto;
            font-size: 12px;
        }
        .field-info {
            display: none;
        }
        .floating-actions {
            position: fixed;
            bottom: 30px;
            left: 30px;
            display: flex;
            gap: 15px;
            z-index: 1000;
        }
        .btn-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            border: 2px solid rgba(255,255,255,0.2);
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.3s;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .btn-circle:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
        }
        .btn-circle.download {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }
        .btn-circle.print {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }
        .btn-circle.back {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }
        .zoom-controls {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            padding: 10px 20px;
            display: flex;
            gap: 15px;
            color: white;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .zoom-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s;
        }
        .zoom-btn:hover {
            background: rgba(255,255,255,0.1);
        }
        #zoomLevel {
            font-size: 1rem;
            min-width: 60px;
            text-align: center;
            line-height: 40px;
        }
        @media print {
            @page {
                size: A4;
                margin: 1cm;
            }
            .floating-actions, .zoom-controls, .header-info { display: none; }
            body { 
                background: white; 
                overflow: visible; 
                padding: 0;
            }
            .container { 
                height: auto; 
                overflow: visible; 
                max-width: 100%;
                padding: 0;
            }
            .document-wrapper { 
                overflow: visible; 
                background: white; 
                padding: 0; 
                box-shadow: none;
                backdrop-filter: none;
            }
            #documentContainer { 
                width: 100%; 
            }
            .page {
                box-shadow: none;
                page-break-after: always;
                margin: 0 auto;
                width: 100% !important;
                height: auto !important;
                border-radius: 0;
            }
            .page canvas, .page img {
                width: 100% !important;
                height: auto !important;
            }
            .signature-field {
                border: none;
                background: transparent;
                backdrop-filter: none;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .signature-field.signed {
                border: none;
            }
            .note-content {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .signature-text {
                background: rgba(255,255,255,0.9);
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
<div class="background-animation"></div>
<div class="container">
    <div class="header-info">
        <h2><i class="fas fa-archive" style="color: #9b59b6;"></i> <?= htmlspecialchars($archive['title']) ?></h2>
        <div class="badge"><i class="fas fa-camera"></i> نسخة مؤرشفة - <?= date('Y-m-d H:i', strtotime($archive['archived_at'])) ?></div>
    </div>

    <div class="document-wrapper" id="documentWrapper">
        <div id="documentContainer">
            <?php if (!$file_exists): ?>
                <div style="padding: 100px; text-align: center; color: white;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 4rem;"></i>
                    <h3>الملف غير موجود على الخادم</h3>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- أزرار عائمة -->
<div class="floating-actions">
    <?php if ($file_exists): ?>
        <button class="btn-circle download" onclick="downloadFile()" title="تحميل">
            <i class="fas fa-download"></i>
        </button>
        <button class="btn-circle print" onclick="window.print()" title="طباعة">
            <i class="fas fa-print"></i>
        </button>
    <?php endif; ?>
    <button class="btn-circle back" onclick="goBack()" title="عودة">
        <i class="fas fa-arrow-right"></i>
    </button>
</div>

<!-- أدوات التكبير/التصغير -->
<?php if ($file_exists): ?>
<div class="zoom-controls">
    <button class="zoom-btn" onclick="zoomOut()"><i class="fas fa-search-minus"></i></button>
    <span id="zoomLevel">100%</span>
    <button class="zoom-btn" onclick="zoomIn()"><i class="fas fa-search-plus"></i></button>
    <button class="zoom-btn" onclick="resetZoom()"><i class="fas fa-sync-alt"></i></button>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

const docData = {
    path: '<?= addslashes($file_path) ?>',
    ext: '<?= $file_ext ?>',
    fields: <?= json_encode($fieldsWithValues, JSON_UNESCAPED_UNICODE) ?>,
    fieldValues: <?= json_encode($field_values, JSON_UNESCAPED_UNICODE) ?>
};

let pdfDoc = null;
let currentScale = 1;
let pageRects = {};

// تحميل وعرض المستند
async function loadDocument() {
    if (docData.ext === 'pdf') {
        try {
            pdfDoc = await pdfjsLib.getDocument(docData.path).promise;
            await renderPDF();
        } catch (e) {
            console.error(e);
            document.getElementById('documentContainer').innerHTML = '<div style="color:white; padding:50px;">خطأ في تحميل PDF</div>';
        }
    } else if (['jpg', 'jpeg', 'png', 'gif'].includes(docData.ext)) {
        const container = document.getElementById('documentContainer');
        const img = document.createElement('img');
        img.src = docData.path;
        img.style.maxWidth = '100%';
        img.style.height = 'auto';
        img.style.display = 'block';
        img.style.margin = '0 auto';
        container.appendChild(img);
        img.onload = function() {
            pageRects[1] = {
                width: img.width,
                height: img.height,
                scale: 1,
                originalWidth: img.naturalWidth,
                originalHeight: img.naturalHeight
            };
            renderFields();
        };
    } else {
        document.getElementById('documentContainer').innerHTML = '<div style="color:white; padding:50px;">لا يمكن عرض هذا النوع من الملفات</div>';
    }
}

async function renderPDF() {
    const container = document.getElementById('documentContainer');
    container.innerHTML = '';
    const targetWidth = 1100;

    for (let i = 1; i <= pdfDoc.numPages; i++) {
        const page = await pdfDoc.getPage(i);
        const viewport = page.getViewport({ scale: 1 });
        const scale = (targetWidth / viewport.width) * currentScale;
        const scaledViewport = page.getViewport({ scale });

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

        await page.render({ canvasContext: ctx, viewport: scaledViewport }).promise;

        const pageDiv = document.createElement('div');
        pageDiv.className = 'page';
        pageDiv.dataset.page = i;
        pageDiv.style.width = scaledViewport.width + 'px';
        pageDiv.style.height = scaledViewport.height + 'px';
        pageDiv.style.margin = '0 auto 20px';
        pageDiv.appendChild(canvas);

        container.appendChild(pageDiv);
    }
    renderFields();
}

function calculateFieldPosition(field, pageRect, isSigned = false) {
    const xPercent = field.x_percent || (field.x_position / 1100) * 100;
    const yPercent = field.y_percent || (field.y_position / 1550) * 100;
    const widthPercent = field.width_percent || (field.width / 1100) * 100;
    const heightPercent = field.height_percent || (field.height / 1550) * 100;

    const x = (xPercent / 100) * pageRect.width;
    const y = (yPercent / 100) * pageRect.height;
    const w = (widthPercent / 100) * pageRect.width;
    const h = (heightPercent / 100) * pageRect.height;

    if (isSigned) {
        return {
            x: x - (w * 0.1),
            y: y - (h * 0.1),
            width: w * 1.2,
            height: h * 1.2
        };
    }
    return { x, y, width: w, height: h };
}

function renderFields() {
    document.querySelectorAll('.signature-field').forEach(el => el.remove());

    docData.fields.forEach(field => {
        const pageNum = field.page_number || 1;
        const pageRect = pageRects[pageNum];
        if (!pageRect) return;

        const fieldValue = docData.fieldValues.find(v => v.field_id == field.id);
        const isSigned = fieldValue !== undefined;
        const fieldType = field.field_type || 'signature';

        const position = calculateFieldPosition(field, pageRect, isSigned);

        const pageElement = document.querySelector(`[data-page="${pageNum}"]`);
        if (!pageElement) return;

        const fieldDiv = document.createElement('div');
        fieldDiv.className = 'signature-field';
        if (isSigned) fieldDiv.classList.add('signed');
        fieldDiv.dataset.fieldId = field.id;
        fieldDiv.dataset.fieldType = fieldType;
        fieldDiv.style.left = position.x + 'px';
        fieldDiv.style.top = position.y + 'px';
        fieldDiv.style.width = position.width + 'px';
        fieldDiv.style.height = position.height + 'px';

        let contentHtml = '';
        if (isSigned && fieldValue) {
            if (fieldType === 'signature' && fieldValue.value_data && fieldValue.value_data.startsWith('data:image')) {
                contentHtml = `<img src="${fieldValue.value_data}" class="signature-image">`;
            } else if (fieldType === 'note' && fieldValue.value_data) {
                contentHtml = `<div class="note-content">${fieldValue.value_data}</div>`;
            } else if (fieldType === 'image' && fieldValue.value_data && fieldValue.value_data.startsWith('data:image')) {
                contentHtml = `<img src="${fieldValue.value_data}" class="signature-image">`;
            } else if (fieldType === 'date' && fieldValue.value_data) {
                contentHtml = `<div class="signature-text">${fieldValue.value_data}</div>`;
            } else if (fieldValue.value_data) {
                contentHtml = `<div class="signature-text">${fieldValue.value_data}</div>`;
            } else {
                contentHtml = `<div class="signature-text">[${getFieldTypeName(fieldType)}]</div>`;
            }
        } else {
            return;
        }

        fieldDiv.innerHTML = `<div class="field-content">${contentHtml}</div>`;
        pageElement.appendChild(fieldDiv);
    });
}

function zoomIn() {
    if (pdfDoc) currentScale = Math.min(3, currentScale + 0.1);
    else if (docData.ext !== 'pdf') {
        const img = document.querySelector('#documentContainer img');
        if (img) {
            currentScale = Math.min(3, currentScale + 0.1);
            img.style.transform = `scale(${currentScale})`;
            img.style.transformOrigin = 'top left';
        }
    }
    updateZoom();
}

function zoomOut() {
    if (pdfDoc) currentScale = Math.max(0.5, currentScale - 0.1);
    else if (docData.ext !== 'pdf') {
        const img = document.querySelector('#documentContainer img');
        if (img) {
            currentScale = Math.max(0.5, currentScale - 0.1);
            img.style.transform = `scale(${currentScale})`;
            img.style.transformOrigin = 'top left';
        }
    }
    updateZoom();
}

function resetZoom() {
    currentScale = 1;
    if (pdfDoc) {
        renderPDF();
    } else {
        const img = document.querySelector('#documentContainer img');
        if (img) {
            img.style.transform = 'scale(1)';
        }
    }
    document.getElementById('zoomLevel').textContent = '100%';
}

function updateZoom() {
    document.getElementById('zoomLevel').textContent = Math.round(currentScale * 100) + '%';
    if (pdfDoc) renderPDF();
}

function downloadFile() {
    const a = document.createElement('a');
    a.href = docData.path;
    a.download = '<?= addslashes($archive['title']) ?>.' + docData.ext;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function goBack() {
    window.location.href = 'board_archive.php';
}

document.addEventListener('DOMContentLoaded', loadDocument);
</script>
</body>
</html>