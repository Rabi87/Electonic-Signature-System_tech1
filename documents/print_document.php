<?php
/**
 * print_document.php - طباعة المستند مع المرفقات والحقول الموقعة
 * يستخدم embed لعرض PDF لضمان طباعة كل صفحة كاملة
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/session.php';
require_once '../includes/config.php';
require_once '../includes/database.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    die("غير مصرح: يرجى تسجيل الدخول أولاً.");
}
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_name'] ?? 'employee';

// قراءة اتجاه الطباعة (افتراضي أفقي)
$orientation = isset($_GET['orientation']) && $_GET['orientation'] === 'portrait' ? 'portrait' : 'landscape';

// التحقق من معرف المستند
if (!isset($_GET['id'])) {
    die("خطأ: معرف المستند غير محدد.");
}
$document_id = intval($_GET['id']);

try {
    $pdo = getDb();

    // جلب بيانات المستند
    $stmt = $pdo->prepare("
        SELECT d.*, u.full_name as creator_name
        FROM documents d
        LEFT JOIN users u ON d.created_by = u.id
        WHERE d.id = ?
    ");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();

    if (!$document) {
        die("خطأ: المستند غير موجود.");
    }

    // التحقق من صلاحية المستخدم
    $can_view = false;
    $is_creator = ($document['created_by'] == $user_id);
    $is_current_holder = ($document['current_holder_id'] == $user_id);

    switch ($user_role) {
        case 'admin':
        case 'ceo':
        case 'board':
        case 'department_manager':
        case 'section_manager':
            $can_view = true;
            break;
        case 'employee':
            $can_view = ($is_creator || $is_current_holder);
            break;
        default:
            $can_view = false;
    }

    if (!$can_view) {
        // تحقق إضافي: إذا كان المستخدم معيناً في حقل
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM document_fields WHERE document_id = ? AND assigned_to = ?");
        $stmt->execute([$document_id, $user_id]);
        if ($stmt->fetch()['count'] > 0) {
            $can_view = true;
        }
    }

    if (!$can_view) {
        die("خطأ: لا تملك صلاحية الوصول إلى هذا المستند.");
    }

    // جلب الحقول
    $stmt = $pdo->prepare("
        SELECT f.*,
               COALESCE(f.x_percent, (f.x_position / 1100) * 100) as x_percent,
               COALESCE(f.y_percent, (f.y_position / 1550) * 100) as y_percent,
               COALESCE(f.width_percent, (f.width / 1100) * 100) as width_percent,
               COALESCE(f.height_percent, (f.height / 1550) * 100) as height_percent
        FROM document_fields f
        WHERE f.document_id = ?
        ORDER BY f.field_order ASC
    ");
    $stmt->execute([$document_id]);
    $fields = $stmt->fetchAll();

    // جلب قيم الحقول
    $stmt = $pdo->prepare("
        SELECT v.*, u.full_name as signer_name
        FROM field_values v
        LEFT JOIN users u ON v.user_id = u.id
        WHERE v.field_id IN (SELECT id FROM document_fields WHERE document_id = ?)
    ");
    $stmt->execute([$document_id]);
    $field_values = $stmt->fetchAll();

    // جلب المرفقات
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
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}

// التحقق من وجود ملف المستند
$file_path = $document['file_path'] ?? '';
$file_exists = false;
$paths_to_try = [
    $file_path,
    '../' . $file_path,
    '../../' . $file_path,
    '../../../' . $file_path,
    'uploads/' . basename($file_path),
];
foreach ($paths_to_try as $path) {
    if ($path && file_exists($path)) {
        $file_path = $path;
        $file_exists = true;
        break;
    }
}
$file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة <?= htmlspecialchars($document['title']) ?></title>
    <!-- نحتاج PDF.js فقط لحساب أبعاد الصفحات لتحديد مواقع الحقول -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: white;
            font-family: sans-serif;
            margin: 0;
            padding: 0;
            direction: rtl;
        }
        .print-page {
            position: relative;
            width: 1100px;          /* عرض ثابت للمستند */
            margin: 0 auto;
            page-break-after: always; /* كل صفحة في ورقة منفصلة */
            background: white;
            box-shadow: none;
        }
        .page-content {
            position: relative;
            width: 1100px;
        }
        /* نعرض المستند باستخدام embed */
        .page-content embed {
            display: block;
            width: 1100px;
            height: auto;  /* الارتفاع يتحدد تلقائياً حسب محتوى PDF */
            border: none;
        }
        /* الحقول الموقعة فقط */
        .signature-field {
            position: absolute;
            border: none;
            background: none;
            pointer-events: none;
        }
        .signature-field.signed { display: block; }
        .signature-field:not(.signed) { display: none; }

        .signature-text {
            font-family: 'Times New Roman', serif;
            font-weight: bold;
            color: #001496;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            padding: 5px;
            word-break: break-word;
        }
        .signature-image {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .note-content {
            background: #ffe600;
            width: 100%;
            height: 100%;
            padding: 5px;
            overflow: hidden;
        }

        /* المرفقات */
        .attachment-wrapper {
            width: 1100px;
            margin: 0 auto;
            page-break-before: always; /* كل مرفق في صفحة جديدة */
            background: white;
            padding: 10px 0;
        }
        .attachment-container {
            width: 100%;
        }
        .attachment-container img,
        .attachment-container embed {
            display: block;
            width: 1100px;
            height: auto;
        }

        /* إخفاء أي عناصر غير مرغوب فيها */
        .no-print { display: none !important; }

        /* توجيه الصفحة حسب اختيار المستخدم */
        @page {
            size: A4 <?= $orientation ?>;
            margin: 0.5cm;
        }
    </style>
</head>
<body>
    <div id="documentPages"></div>
    <div id="attachmentsContainer"></div>

    <script>
        // بيانات من PHP
        const docData = {
            id: <?= json_encode($document_id) ?>,
            path: <?= json_encode($file_path) ?>,
            exists: <?= json_encode($file_exists) ?>,
            ext: <?= json_encode($file_ext) ?>,
            fields: <?= json_encode($fields, JSON_UNESCAPED_UNICODE) ?>,
            fieldValues: <?= json_encode($field_values, JSON_UNESCAPED_UNICODE) ?>,
            title: <?= json_encode($document['title']) ?>
        };
        const attachments = <?= json_encode($attachments, JSON_UNESCAPED_UNICODE) ?>;

        // إعداد PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // دالة لإعلام النافذة الأب بأن المحتوى جاهز
        function notifyParent() {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'printDocumentReady', documentId: docData.id }, '*');
            }
        }

        // حساب موقع الحقل (نفس المنطق السابق)
        function calculateFieldPosition(field, pageRect, isSigned) {
            const xPercent = field.x_percent || (field.x_position / 1100) * 100;
            const yPercent = field.y_percent || (field.y_position / 1550) * 100;
            const wPercent = field.width_percent || (field.width / 1100) * 100;
            const hPercent = field.height_percent || (field.height / 1550) * 100;

            let x = (xPercent / 100) * pageRect.width;
            let y = (yPercent / 100) * pageRect.height;
            let w = (wPercent / 100) * pageRect.width;
            let h = (hPercent / 100) * pageRect.height;

            if (isSigned) {
                x -= w * 0.1;
                y -= h * 0.1;
                w *= 1.2;
                h *= 1.2;
            }
            return { x, y, w, h };
        }

        // عرض المستند الرئيسي
        async function renderDocument() {
            const container = document.getElementById('documentPages');
            container.innerHTML = '';

            if (!docData.exists) {
                container.innerHTML = '<p style="color:red; text-align:center;">ملف المستند غير موجود</p>';
                return;
            }

            if (docData.ext === 'pdf') {
                // نستخدم PDF.js فقط للحصول على أبعاد الصفحات
                try {
                    const pdf = await pdfjsLib.getDocument(docData.path).promise;
                    for (let i = 1; i <= pdf.numPages; i++) {
                        const page = await pdf.getPage(i);
                        const viewport = page.getViewport({ scale: 1 });
                        // نحسب أبعاد الصفحة عند عرضها بعرض 1100px
                        const scale = 1100 / viewport.width;
                        const pageHeight = viewport.height * scale;

                        // حفظ أبعاد الصفحة لاستخدامها في الحقول
                        const pageRect = { width: 1100, height: pageHeight };
                        if (!window.pageRects) window.pageRects = {};
                        window.pageRects[i] = pageRect;

                        // إنشاء div للصفحة مع embed
                        const pageDiv = document.createElement('div');
                        pageDiv.className = 'print-page';
                        pageDiv.dataset.page = i;

                        const contentDiv = document.createElement('div');
                        contentDiv.className = 'page-content';
                        contentDiv.style.height = pageHeight + 'px';

                        // إنشاء embed لعرض PDF
                        const embed = document.createElement('embed');
                        embed.src = docData.path + '#page=' + i; // عرض صفحة محددة
                        embed.type = 'application/pdf';
                        embed.style.width = '1100px';
                        embed.style.height = pageHeight + 'px'; // تحديد الارتفاع لمنع التمرير

                        contentDiv.appendChild(embed);
                        pageDiv.appendChild(contentDiv);
                        container.appendChild(pageDiv);
                    }
                } catch (e) {
                    container.innerHTML = '<p style="color:red;">خطأ في تحميل PDF: ' + e.message + '</p>';
                }
            } else if (['jpg', 'jpeg', 'png', 'gif'].includes(docData.ext)) {
                // عرض الصورة
                const img = new Image();
                img.src = docData.path;
                await new Promise((res, rej) => { img.onload = res; img.onerror = rej; });
                const aspect = img.height / img.width;
                const imgWidth = 1100;
                const imgHeight = 1100 * aspect;
                window.pageRects = { 1: { width: imgWidth, height: imgHeight } };

                const pageDiv = document.createElement('div');
                pageDiv.className = 'print-page';
                pageDiv.dataset.page = 1;

                const contentDiv = document.createElement('div');
                contentDiv.className = 'page-content';
                contentDiv.style.height = imgHeight + 'px';

                const imgElement = document.createElement('img');
                imgElement.src = img.src;
                imgElement.style.width = '1100px';
                imgElement.style.height = imgHeight + 'px';
                contentDiv.appendChild(imgElement);
                pageDiv.appendChild(contentDiv);
                container.appendChild(pageDiv);
            } else {
                container.innerHTML = '<p style="color:red;">نوع الملف غير مدعوم</p>';
            }
        }

        // إضافة الحقول الموقعة
        function renderFields() {
            if (!window.pageRects) return;
            docData.fields.forEach(field => {
                const pageNum = field.page_number || 1;
                const pageRect = window.pageRects[pageNum];
                if (!pageRect) return;

                const fieldValue = docData.fieldValues.find(v => v.field_id == field.id);
                if (!fieldValue) return; // فقط الموقعة

                const pos = calculateFieldPosition(field, pageRect, true);

                const pageDiv = document.querySelector(`.print-page[data-page="${pageNum}"] .page-content`);
                if (!pageDiv) return;

                const fieldDiv = document.createElement('div');
                fieldDiv.className = 'signature-field signed';
                fieldDiv.style.left = pos.x + 'px';
                fieldDiv.style.top = pos.y + 'px';
                fieldDiv.style.width = pos.w + 'px';
                fieldDiv.style.height = pos.h + 'px';
                fieldDiv.style.position = 'absolute';

                // محتوى الحقل
                if (field.field_type === 'signature' && fieldValue.value_data?.startsWith('data:image')) {
                    fieldDiv.innerHTML = `<img src="${fieldValue.value_data}" class="signature-image">`;
                } else if (field.field_type === 'note') {
                    fieldDiv.innerHTML = `<div class="note-content">${fieldValue.value_data || ''}</div>`;
                } else if (field.field_type === 'image' && fieldValue.value_data?.startsWith('data:image')) {
                    fieldDiv.innerHTML = `<img src="${fieldValue.value_data}" class="signature-image">`;
                } else if (field.field_type === 'date') {
                    fieldDiv.innerHTML = `<div class="signature-text">${fieldValue.value_data || ''}</div>`;
                } else {
                    fieldDiv.innerHTML = `<div class="signature-text">${fieldValue.value_data || ''}</div>`;
                }

                pageDiv.appendChild(fieldDiv);
            });
        }

        // عرض المرفقات
        async function renderAttachments() {
            const container = document.getElementById('attachmentsContainer');
            container.innerHTML = '';

            for (let attach of attachments) {
                const isImage = attach.file_type?.startsWith('image/');
                const isPDF = attach.file_type?.includes('pdf');
                if (!isImage && !isPDF) continue;

                const attachDiv = document.createElement('div');
                attachDiv.className = 'attachment-wrapper';

                const innerDiv = document.createElement('div');
                innerDiv.style.width = '1100px';
                innerDiv.style.margin = '0 auto';

                if (isImage) {
                    const img = new Image();
                    img.src = attach.file_path;
                    await new Promise((res) => { img.onload = res; img.onerror = res; });
                    img.style.width = '1100px';
                    img.style.height = 'auto';
                    innerDiv.appendChild(img);
                } else if (isPDF) {
                    // عرض PDF كمرفق باستخدام embed (كل صفحة منفصلة)
                    try {
                        const pdf = await pdfjsLib.getDocument(attach.file_path).promise;
                        for (let i = 1; i <= pdf.numPages; i++) {
                            const embed = document.createElement('embed');
                            embed.src = attach.file_path + '#page=' + i;
                            embed.type = 'application/pdf';
                            embed.style.width = '1100px';
                            embed.style.height = 'auto';
                            innerDiv.appendChild(embed);
                        }
                    } catch (e) {
                        innerDiv.innerHTML += `<p style="color:red;">خطأ في تحميل مرفق PDF: ${attach.file_name}</p>`;
                    }
                }

                attachDiv.appendChild(innerDiv);
                container.appendChild(attachDiv);
            }
        }

        // التهيئة
        window.addEventListener('load', async () => {
            try {
                await renderDocument();
                renderFields();
                await renderAttachments();
                notifyParent(); // إعلام النافذة الأب بأن المحتوى جاهز
            } catch (e) {
                console.error('خطأ في print_document:', e);
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'printDocumentError', error: e.message }, '*');
                }
            }
        });
    </script>
</body>
</html>