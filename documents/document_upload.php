<?php
require_once '../includes/session.php';
checkLogin();
require_once '../includes/config.php';
require_once '../includes/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// توليد CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_name'];
$db = getDB();

// جلب المدير المباشر والقسم الحالي
$manager_id = null;
$manager_name = "غير محدد";
$user_department_id = null;

$stmt = $db->prepare("SELECT supervisor_id, department_id, site FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$manager_id = $user['supervisor_id'] ?? null;
$user_department_id = $user['department_id'] ?? null;
$user_site = $user['site'] ?? null; // إضافة الموقع

if ($manager_id) {
    $stmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$manager_id]);
    $manager = $stmt->fetch(PDO::FETCH_ASSOC);
    $manager_name = $manager['full_name'] ?? "غير محدد";
}

// جلب جميع المستخدمين من نفس القسم (باستثناء المستخدم الحالي)
if ($user_department_id && $user_site) {
    $stmt = $db->prepare("SELECT id, full_name, title FROM users WHERE department_id = ? AND site = ? AND id != ? ORDER BY full_name");
    $stmt->execute([$user_department_id, $user_site, $user_id]);
    $users_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $users_list = [];
}
$users_json = json_encode($users_list, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// تحديد الصفحة الرئيسية
function getDashboardPage($role_name)
{
    $dashboards = [
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
    return $dashboards[$role_name] ?? 'dashboard.php';
}

$dashboard_page = getDashboardPage($user_role);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>رفع مستند جديد</title>
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <link href="../assets/css/upload.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
</head>

<body>
    <div class="background-animation">
        <div class="floating-element"></div>
        <div class="floating-element"></div>
        <div class="floating-element"></div>
    </div>

    <div class="container">

        <div class="header">
            <h1><i class="fas fa-file-upload"></i> رفع مستند جديد</h1>
            <button type="button" class="btn btn-secondary"
                onclick="window.location.href='../<?php echo $dashboard_page; ?>'">
                <i class="fas fa-arrow-right"></i> العودة
            </button>
        </div>

        <form id="uploadForm" method="POST" action="process_upload.php" enctype="multipart/form-data"
            class="upload-area">
            <!-- CSRF token -->
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <!-- الشريط الجانبي مخفي على الأجهزة الصغيرة -->
            <div class="sidebar" id="mainSidebar">
                <div class="form-group">
                    <label for="document_name"><i class="fas fa-file-signature"></i> اسم المستند</label>
                    <input type="text" id="document_name" name="document_name" class="form-control" required
                        placeholder="أدخل اسم المستند">
                </div>

                <div class="form-group">
                    <label for="importance"><i class="fas fa-exclamation-circle"></i> الأهمية</label>
                    <select id="importance" name="importance" class="form-control" required>
                        <option value="normal" selected>عادي</option>
                        <option value="high">عاجل</option>
                        <option value="urgent">سري</option>
                    </select>
                </div>

                <div class="form-group">
                    <div class="file-upload-area" id="fileUploadArea">
                        <i class="fas fa-cloud-upload-alt" style="font-size: 48px; color: #3498db;"></i>
                        <p>اسحب ملف PDF هنا أو انقر للاختيار</p>
                    </div>
                    <input type="file" id="pdf_file" name="pdf_file" accept=".pdf" required style="display: none;">
                    <div class="file-info" id="fileInfo"></div>
                </div>

                <!-- قائمة الأدوات الأصلية (للشاشات الكبيرة) -->
                 <?php if ($user_site == 'الإدارة المركزية'): ?>
                <div class="form-group tools-desktop">
                    <h3><i class="fas fa-tools"></i> أدوات إضافة الحقول</h3>
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
                        <button type="button" class="tool-item" onclick="resetFieldPositions()">
                            <i class="fas fa-redo"></i> إعادة تعيين
                        </button>


                    </div>
                    
                </div>
                <?php endif; ?>


            </div>


            <div class="pdf-preview">
                <div id="pdfPlaceholder" class="pdf-placeholder">
                    <i class="fas fa-file-pdf"></i>
                    <h3>لم يتم اختيار ملف PDF بعد</h3>
                    <p>سيظهر معاينة الملف هنا بعد الاختيار</p>
                </div>
                <div id="pdfContainer" class="pdf-container"></div>
            </div>

            <input type="hidden" name="fields_data" id="fieldsData" value="[]">
            <input type="hidden" name="user_role" value="<?php echo $user_role; ?>">
            <input type="hidden" name="redirect_page" value="<?php echo $dashboard_page; ?>">

            <div class="floating-actions">
                <button type="submit" form="uploadForm" class="btn1 btn1-primary" title="إرسال المستند">
                    <i class="fas fa-paper-plane"></i>
                </button>
                <button type="button" class="btn1 btn1-secondary" onclick="window.location.href='../<?php echo $dashboard_page; ?>'" title="العودة للوحة الرئيسية">
                    <i class="fas fa-times"></i> </button>

            </div>

        </form>
        <!-- شريط الأزرار العائم -->


    </div>

    <!-- زر الأدوات العائم للشاشات الصغيرة -->
    <button class="floating-tools-btn" id="floatingToolsBtn">
        <i class="fas fa-tools"></i>
    </button>

    <!-- زر النموذج العائم للشاشات الصغيرة -->
    <button class="floating-form-btn" id="floatingFormBtn">
        <i class="fas fa-file-alt"></i>
    </button>

    <!-- نافذة الأدوات المنبثقة -->
    <div class="tools-popup" id="toolsPopup">
        <h3 style="margin-bottom:10px;color:#2c3e50;font-size:16px;">
            <i class="fas fa-tools"></i> أدوات إضافة الحقول
        </h3>
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
            <button type="button" class="tool-item" onclick="resetFieldPositions()">
                <i class="fas fa-redo"></i> إعادة تعيين الحقول
            </button>
        </div>
        <div style="text-align:center; margin-top:10px;">
            <button class="btn btn-secondary" onclick="closeToolsPopup()" style="padding:8px 15px;font-size:14px;">
                <i class="fas fa-times"></i> إغلاق
            </button>
        </div>
    </div>

    <!-- نافذة النموذج المنبثقة -->
    <div class="form-popup" id="formPopup">
        <h3 style="margin-bottom:15px;color:#2c3e50;font-size:18px;">
            <i class="fas fa-file-alt"></i> تفاصيل المستند
        </h3>
        <div class="form-group">
            <label for="popup_document_name"><i class="fas fa-file-signature"></i> اسم المستند</label>
            <input type="text" id="popup_document_name" name="popup_document_name" class="form-control" required
                placeholder="أدخل اسم المستند">
        </div>
        <div class="form-group">
            <label for="popup_importance"><i class="fas fa-exclamation-circle"></i> الأهمية</label>
            <select id="popup_importance" name="popup_importance" class="form-control" required>
                <option value="normal" selected>عادي</option>
                <option value="high">عاجل</option>
                <option value="urgent">سري</option>
            </select>
        </div>
        <div class="form-group">
            <div class="file-upload-area" id="popupFileUploadArea">
                <i class="fas fa-cloud-upload-alt" style="font-size: 36px; color: #3498db;"></i>
                <p>اختر ملف PDF</p>
            </div>
            <input type="file" id="popup_pdf_file" name="popup_pdf_file" accept=".pdf" required style="display: none;">
            <div class="file-info" id="popupFileInfo"></div>
        </div>
        <div style="display:flex; gap:10px; margin-top:15px;">
            <button class="btn btn-primary" onclick="saveFormData()" style="flex:1; padding:10px;">
                <i class="fas fa-check"></i> حفظ
            </button>
            <button class="btn btn-secondary" onclick="closeFormPopup()" style="flex:1; padding:10px;">
                <i class="fas fa-times"></i> إغلاق
            </button>
        </div>
    </div>

    <!-- نافذة التعيين -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 style="margin-bottom:20px;color:#2c3e50" id="modalTitle">
                <i class="fas fa-user-plus"></i> تعيين حقل
            </h3>
            <div id="modalBody"></div>
        </div>
    </div>

    <!-- نافذة إدخال التاريخ -->
    <div id="dateInputModal" class="modal date-input-modal">
        <div class="modal-content">
            <span class="close" onclick="closeDateInputModal()">&times;</span>
            <h3 style="margin-bottom:20px;color:#2c3e50">
                <i class="fas fa-calendar-alt"></i> إدخال التاريخ
            </h3>
            <div class="form-group">
                <label for="dateInput">التاريخ</label>
                <input type="date" id="dateInput" class="form-control">
            </div>
            <div class="form-group">
                <label for="dateFormat">تنسيق التاريخ</label>
                <select id="dateFormat" class="form-control">
                    <option value="ar-SA">هـ/م/ي (العربي)</option>
                    <option value="en-US">م/ي/هـ (الميلادي)</option>
                </select>
            </div>
            <div style="text-align:center; margin-top:20px;">
                <button class="btn btn-secondary" onclick="closeDateInputModal()" style="margin-right:10px;">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button class="btn btn-primary" onclick="saveDateInput()">
                    <i class="fas fa-check"></i> حفظ
                </button>
            </div>
        </div>
    </div>

    <!-- نافذة إدخال الملاحظة -->
    <div id="noteModal" class="modal note-input-modal">
        <div class="modal-content">
            <span class="close" onclick="closeNoteModal()">&times;</span>
            <h3 style="margin-bottom:20px;color:#2c3e50">
                <i class="fas fa-sticky-note"></i> إدخال الملاحظة
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

            <div class="form-group">
                <textarea id="noteInput" class="note-input-area" placeholder="أدخل الملاحظة هنا... (اختياري)"></textarea>
                <small style="color:#666;display:block;margin-top:5px;">إذا تركتها فارغة سيتم استخدام "ملاحظة" كقيمة افتراضية</small>
            </div>

            <div style="text-align:center; margin-top:20px;">
                <button class="btn btn-secondary" onclick="closeNoteModal()" style="margin-right:10px;">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button class="btn btn-primary" onclick="saveNote()">
                    <i class="fas fa-check"></i> حفظ
                </button>
            </div>
        </div>
    </div>
    <!-- نافذة إدخال النص -->
    <div id="textInputModal" class="modal note-input-modal">
        <div class="modal-content">
            <span class="close" onclick="closeTextInputModal()">&times;</span>
            <h3 style="margin-bottom:20px;color:#2c3e50">
                <i class="fas fa-font"></i> إدخال النص
            </h3>
            <div class="form-group">
                <label for="textInput">رقم داخلي</label>
                <input type="text" id="textInput" class="form-control" placeholder="أدخل النص (رقم داخلي)...">
            </div>
            <div style="text-align:center; margin-top:20px;">
                <button class="btn btn-secondary" onclick="closeTextInputModal()" style="margin-right:10px;">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button class="btn btn-primary" onclick="saveTextInput()">
                    <i class="fas fa-check"></i> حفظ
                </button>
            </div>
        </div>
    </div>

    <!-- نافذة التوقيع -->
    <div id="signatureModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="document.getElementById('signatureModal').style.display='none'">&times;</span>
            <div id="signatureModalBody"></div>
        </div>
    </div>

    <!-- نافذة الصورة -->
    <div id="imageModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="document.getElementById('imageModal').style.display='none'">&times;</span>
            <div id="imageModalBody"></div>
        </div>
    </div>

    <script>
        // تهيئة مكتبة PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // المتغيرات الأساسية
        let pdfDoc = null;
        let scale = 1.5;
        let fields = [];
        let currentField = null;
        let pageViewports = {};

        const userId = <?php echo json_encode($user_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const managerId = <?php echo json_encode($manager_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const managerName = <?php echo json_encode($manager_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const dashboardPage = <?php echo json_encode($dashboard_page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const allUsers = <?php echo $users_json; ?>; // تم ترميزه مسبقاً بـ json_encode مع الخيارات

        // عند تحميل الصفحة
        document.addEventListener('DOMContentLoaded', function() {
            // رفع الملف
            const fileUploadArea = document.getElementById('fileUploadArea');
            const fileInput = document.getElementById('pdf_file');

            fileUploadArea.addEventListener('click', function() {
                fileInput.click();
            });

            fileUploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.style.background = 'rgba(52, 152, 219, 0.1)';
            });

            fileUploadArea.addEventListener('dragleave', function() {
                this.style.background = '#e3f2fd';
            });

            fileUploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.background = '#e3f2fd';

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    fileInput.dispatchEvent(new Event('change'));
                }
            });

            fileInput.addEventListener('change', function(e) {
                if (this.files.length > 0) {
                    previewPDF(this);
                }
            });

            // إدارة زر الأدوات العائم
            const floatingToolsBtn = document.getElementById('floatingToolsBtn');
            const toolsPopup = document.getElementById('toolsPopup');

            floatingToolsBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toolsPopup.classList.toggle('show');
                formPopup.classList.remove('show');
            });

            floatingToolsBtn.addEventListener('touchstart', function(e) {
                e.stopPropagation();
                e.preventDefault();
                toolsPopup.classList.toggle('show');
                formPopup.classList.remove('show');
            });

            // إدارة زر النموذج العائم
            const floatingFormBtn = document.getElementById('floatingFormBtn');
            const formPopup = document.getElementById('formPopup');

            floatingFormBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                formPopup.classList.toggle('show');
                toolsPopup.classList.remove('show');
            });

            floatingFormBtn.addEventListener('touchstart', function(e) {
                e.stopPropagation();
                e.preventDefault();
                formPopup.classList.toggle('show');
                toolsPopup.classList.remove('show');
            });

            // تهيئة رفع الملف للنافذة المنبثقة
            const popupFileUploadArea = document.getElementById('popupFileUploadArea');
            const popupFileInput = document.getElementById('popup_pdf_file');

            popupFileUploadArea.addEventListener('click', function() {
                popupFileInput.click();
            });

            popupFileInput.addEventListener('change', function(e) {
                if (this.files.length > 0) {
                    showPopupFileInfo(this.files[0]);
                }
            });

            // إغلاق النوافذ المنبثقة عند النقر خارجها
            document.addEventListener('click', function(e) {
                if (toolsPopup.classList.contains('show') &&
                    !toolsPopup.contains(e.target) &&
                    !floatingToolsBtn.contains(e.target)) {
                    toolsPopup.classList.remove('show');
                }
                if (formPopup.classList.contains('show') &&
                    !formPopup.contains(e.target) &&
                    !floatingFormBtn.contains(e.target)) {
                    formPopup.classList.remove('show');
                }
            });

            // تهيئة نظام السحب للأدوات
            initToolDrag();
            initUserSearch();

            // إخفاء الشريط الجانبي على الأجهزة الصغيرة
            if (window.innerWidth <= 991) {
                document.getElementById('mainSidebar').style.display = 'none';
            }
        });

        // إغلاق نافذة الأدوات
        function closeToolsPopup() {
            document.getElementById('toolsPopup').classList.remove('show');
        }

        // إغلاق نافذة النموذج
        function closeFormPopup() {
            document.getElementById('formPopup').classList.remove('show');
        }

        // حفظ البيانات من النافذة المنبثقة إلى الحقول الرئيسية
        function saveFormData() {
            const popupDocName = document.getElementById('popup_document_name').value;
            const popupImportance = document.getElementById('popup_importance').value;
            const popupFileInput = document.getElementById('popup_pdf_file');

            document.getElementById('document_name').value = popupDocName;
            document.getElementById('importance').value = popupImportance;

            if (popupFileInput.files.length > 0) {
                const mainFileInput = document.getElementById('pdf_file');
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(popupFileInput.files[0]);
                mainFileInput.files = dataTransfer.files;
                previewPDF(mainFileInput);
            }
            closeFormPopup();
        }

        // ===== معاينة PDF =====
        async function previewPDF(input) {
            const file = input.files[0];
            if (!file) return;

            if (file.size > 10 * 1024 * 1024) {
                alert('حجم الملف أكبر من 10MB المسموح بها');
                clearFile();
                return;
            }

            if (file.type !== 'application/pdf') {
                alert('الرجاء اختيار ملف PDF فقط');
                clearFile();
                return;
            }

            showFileInfo(file);
            const fileURL = URL.createObjectURL(file);

            try {
                const loadingTask = pdfjsLib.getDocument(fileURL);
                pdfDoc = await loadingTask.promise;

                document.getElementById('pdfPlaceholder').style.display = 'none';
                document.getElementById('pdfContainer').style.display = 'block';

                await renderAllPages();
            } catch (error) {
                alert('خطأ في تحميل ملف PDF: ' + error.message);
                clearFile();
            }
        }

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

            fields.forEach(field => renderField(field));
        }

        function initPageForDrop(pageContainer, pageNum, canvas, viewport) {
            pageContainer.addEventListener('dragover', function(e) {
                e.preventDefault();
            });

            pageContainer.addEventListener('drop', function(e) {
                e.preventDefault();
                const fieldType = e.dataTransfer.getData('text/plain');
                if (!fieldType) return;

                const rect = canvas.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;

                createField(x, y, fieldType, pageNum, viewport, rect);
            });
        }

        // ===== نظام السحب والإفلات =====
        function initToolDrag() {
            const toolItems = document.querySelectorAll('.tool-item[draggable="true"]');

            toolItems.forEach(item => {
                item.addEventListener('dragstart', function(e) {
                    e.dataTransfer.setData('text/plain', this.dataset.type);
                    this.style.opacity = '0.5';
                    closeToolsPopup();
                });

                item.addEventListener('dragend', function() {
                    this.style.opacity = '1';
                });

                item.addEventListener('touchstart', function(e) {
                    e.preventDefault();
                    this.dataset.dragging = 'true';
                    this.style.opacity = '0.5';
                    closeToolsPopup();

                    if (e.touches.length === 1) {
                        const touch = e.touches[0];
                        const fakeEvent = new Event('dragstart');
                        fakeEvent.dataTransfer = {
                            setData: function(type, data) {
                                this._data = data;
                            },
                            getData: function(type) {
                                return this._data;
                            },
                            _data: this.dataset.type
                        };
                        this.dispatchEvent(fakeEvent);
                    }
                }, {
                    passive: false
                });

                item.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                    this.dataset.dragging = 'false';
                });
            });
        }

        // ===== إنشاء وعرض الحقول =====
        function createField(x, y, type, pageNum, viewport, canvasRect) {
            const fieldId = 'field-' + Date.now();

            const pointsPerPixelX = viewport.width / canvasRect.width;
            const pointsPerPixelY = viewport.height / canvasRect.height;

            const xPoints = x * pointsPerPixelX;
            const yPoints = y * pointsPerPixelY;

            const dimensions = {
                'توقيع': {
                    width: 100,
                    height: 40
                },
                'تاريخ': {
                    width: 80,
                    height: 30
                },
                'نص': {
                    width: 120,
                    height: 30
                },
                'ملاحظة': {
                    width: 150,
                    height: 60
                },
                'صورة': {
                    width: 60,
                    height: 60
                }
            };

            const dim = dimensions[type] || {
                width: 100,
                height: 40
            };

            const adjustedX = xPoints - (dim.width / 2);
            const adjustedY = yPoints - (dim.height / 2);

            const finalX = Math.max(0, Math.min(adjustedX, viewport.width - dim.width));
            const finalY = Math.max(0, Math.min(adjustedY, viewport.height - dim.height));

            const field = {
                id: fieldId,
                type: type,
                x: finalX,
                y: finalY,
                width: dim.width,
                height: dim.height,
                page: pageNum,
                assignedTo: null,
                assignedName: null,
                textValue: null,
                noteValue: null,
                signatureData: null,
                imageData: null,
                xPercent: (finalX / viewport.width) * 100,
                yPercent: (finalY / viewport.height) * 100,
                widthPercent: (dim.width / viewport.width) * 100,
                heightPercent: (dim.height / viewport.height) * 100
            };

            fields.push(field);
            renderField(field);
            updateFieldsData();

            openAssignModal(field);

            return field;
        }

        function renderField(field) {
            const pageContainer = document.querySelector(`.pdf-page-container[data-page-number="${field.page}"]`);
            if (!pageContainer) return;

            const viewport = pageViewports[field.page];
            const canvas = pageContainer.querySelector('canvas');
            if (!canvas || !viewport) return;

            const canvasRect = canvas.getBoundingClientRect();

            const displayX = (field.xPercent / 100) * canvasRect.width;
            const displayY = (field.yPercent / 100) * canvasRect.height;
            const displayWidth = (field.widthPercent / 100) * canvasRect.width;
            const displayHeight = (field.heightPercent / 100) * canvasRect.height;

            const existingField = document.getElementById(field.id);
            if (existingField) existingField.remove();

            const fieldElement = document.createElement('div');
            fieldElement.id = field.id;
            fieldElement.className = 'field-marker';
            fieldElement.style.left = displayX + 'px';
            fieldElement.style.top = displayY + 'px';
            fieldElement.style.width = displayWidth + 'px';
            fieldElement.style.height = displayHeight + 'px';

            // منع القائمة السياقية (النقر بالزر الأيمن) على الحقل
            fieldElement.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                return false;
            });

            let className = '';
            let content = '';

            if (field.type === 'توقيع' && field.signatureData) {
                className = 'signature';
                content = `<img src="${field.signatureData}" style="max-width:95%;max-height:95%;object-fit:contain;">`;
            } else if (field.type === 'تاريخ' && field.textValue) {
                className = 'date';
                content = `<i class="fas fa-calendar-alt"></i><span>${field.textValue}</span>`;
            } else if (field.type === 'نص' && field.textValue) {
                className = 'text';
                content = `<i class="fas fa-font"></i><span>${field.textValue}</span>`;
            } else if (field.type === 'ملاحظة' && field.noteValue) {
                className = 'note';
                content = `<i class="fas fa-sticky-note"></i><span>${field.noteValue.substring(0, 15)}${field.noteValue.length > 15 ? '...' : ''}</span>`;
            } else if (field.type === 'صورة' && field.imageData) {
                className = 'image';
                content = `<img src="${field.imageData}" style="max-width:95%;max-height:95%;object-fit:contain;">`;
            } else {
                className = field.type === 'توقيع' ? 'signature' :
                    field.type === 'تاريخ' ? 'date' :
                    field.type === 'نص' ? 'text' :
                    field.type === 'ملاحظة' ? 'note' : 'image';
                content = `<i class="fas fa-${field.type === 'توقيع' ? 'signature' :
                    field.type === 'تاريخ' ? 'calendar-alt' :
                        field.type === 'نص' ? 'font' :
                            field.type === 'ملاحظة' ? 'sticky-note' : 'stamp'}"></i>
                           <span>${field.type}</span>`;
            }

            fieldElement.innerHTML = `
                <div class="field-marker-inner ${className}">
                    ${content}
                    <button class="delete-field-btn" onclick="deleteField('${field.id}')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;

            makeFieldDraggable(fieldElement, field);

            pageContainer.appendChild(fieldElement);
        }

        function makeFieldDraggable(element, fieldData) {
            let isDragging = false;
            let startX, startY;
            let initialLeft, initialTop;

            element.addEventListener('mousedown', function(e) {
                if (e.target.closest('.field-marker-inner')) {
                    isDragging = true;
                    startX = e.clientX;
                    startY = e.clientY;

                    initialLeft = parseFloat(element.style.left);
                    initialTop = parseFloat(element.style.top);

                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                    e.preventDefault();
                }
            });

            function onMouseMove(e) {
                if (!isDragging) return;
                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                updatePosition(dx, dy);
            }

            function onMouseUp() {
                isDragging = false;
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            }

            function updatePosition(dx, dy) {
                const newLeft = initialLeft + dx;
                const newTop = initialTop + dy;

                element.style.left = newLeft + 'px';
                element.style.top = newTop + 'px';

                const pageContainer = element.parentElement;
                const canvas = pageContainer.querySelector('canvas');
                const viewport = pageViewports[fieldData.page];
                const canvasRect = canvas.getBoundingClientRect();

                const newXPercent = (newLeft / canvasRect.width) * 100;
                const newYPercent = (newTop / canvasRect.height) * 100;

                const fieldIndex = fields.findIndex(f => f.id === fieldData.id);
                if (fieldIndex !== -1) {
                    fields[fieldIndex].xPercent = newXPercent;
                    fields[fieldIndex].yPercent = newYPercent;
                    fields[fieldIndex].x = (newXPercent / 100) * viewport.width;
                    fields[fieldIndex].y = (newYPercent / 100) * viewport.height;
                    updateFieldsData();
                }
            }
        }

        // ===== دوال مساعدة =====
        function showFileInfo(file) {
            const fileInfo = document.getElementById('fileInfo');
            fileInfo.innerHTML = `
                <div style="background:#e8f5e9;padding:10px;border-radius:5px;margin-top:10px;">
                    <i class="fas fa-check-circle" style="color:#4caf50;"></i> 
                    <strong>${file.name}</strong> (${(file.size / 1024 / 1024).toFixed(2)} MB)
                </div>
            `;
        }

        function showPopupFileInfo(file) {
            const fileInfo = document.getElementById('popupFileInfo');
            fileInfo.innerHTML = `
                <div style="background:#e8f5e9;padding:8px;border-radius:5px;margin-top:8px;font-size:12px;">
                    <i class="fas fa-check-circle" style="color:#4caf50;"></i> 
                    <strong>${file.name}</strong> (${(file.size / 1024 / 1024).toFixed(2)} MB)
                </div>
            `;
        }

        function clearFile() {
            document.getElementById('pdf_file').value = '';
            document.getElementById('fileInfo').innerHTML = '';
            document.getElementById('popup_pdf_file').value = '';
            document.getElementById('popupFileInfo').innerHTML = '';
            document.getElementById('pdfPlaceholder').style.display = 'block';
            document.getElementById('pdfContainer').style.display = 'none';
            document.getElementById('pdfContainer').innerHTML = '';
            pdfDoc = null;
            fields = [];
            updateFieldsData();
        }

        function deleteField(fieldId) {
            if (confirm('هل تريد حذف هذا الحقل؟')) {
                fields = fields.filter(f => f.id !== fieldId);
                const el = document.getElementById(fieldId);
                if (el) el.remove();
                updateFieldsData();
            }
        }

        function resetFieldPositions() {
            if (fields.length === 0) {
                alert('لا توجد حقول لإعادة تعيينها.');
                return;
            }

            if (confirm('سيتم حذف جميع الحقول. هل تريد الاستمرار؟')) {
                fields = [];
                const fieldElements = document.querySelectorAll('.field-marker');
                fieldElements.forEach(el => el.remove());
                updateFieldsData();
                alert('تم حذف جميع الحقول.');
                closeToolsPopup();
            }
        }

        function updateFieldsData() {
            const data = JSON.stringify(fields);
            document.getElementById('fieldsData').value = data;
        }

        function updateFieldInView(field) {
            const index = fields.findIndex(f => f.id === field.id);
            if (index !== -1) {
                fields[index] = {
                    ...fields[index],
                    ...field
                };
            }
            updateFieldsData();
            renderField(field);
        }

        function closeModal() {
            document.getElementById('assignModal').style.display = 'none';
            const container = document.getElementById('otherUsersSelectContainer');
            if (container) container.style.display = 'none';
        }

        function closeDateInputModal() {
            document.getElementById('dateInputModal').style.display = 'none';
        }

        function closeNoteInputModal() {
            document.getElementById('noteInputModal').style.display = 'none';
        }

        function closeTextInputModal() {
            document.getElementById('textInputModal').style.display = 'none';
        }

        // ===== نوافذ التعيين المطورة =====
        function openAssignModal(field) {
            currentField = field;
            let modalBody = '';

            // بناء خيارات المستخدمين الآخرين
            let otherUsersOptions = '';
            allUsers.forEach(user => {
                otherUsersOptions += `<option value="${user.id}">${user.full_name} - ${user.title}</option>`;
            });

            if (field.type === 'نص') {
                openTextInputModal();
                return;
            } else if (field.type === 'ملاحظة') {
                openNoteModal(field);
                return;


            } else if (field.type === 'تاريخ') {
                modalBody = `
                    <div id="dateModal">
                        <p>اختر من سيتم تعيين حقل التاريخ:</p>
                        <div class="assign-options">
                            <div class="assign-option" onclick="assignDateToSelf()">
                                <i class="fas fa-user"></i>
                                <div class="assign-option-info">
                                    <span>ملئ من قبلي</span>
                                    <small>سأدخل التاريخ بنفسي</small>
                                </div>
                            </div>
                            ${managerId !== null ? `
                            <div class="assign-option" onclick="assignDateToManager()">
                                <i class="fas fa-user-tie"></i>
                                <div class="assign-option-info">
                                    <span>مديري المباشر</span>
                                    <small>${managerName}</small>
                                </div>
                            </div>
                            ` : ''}
                            ${managerId === null ? `
                            <div class="assign-option" onclick="showOtherUsersSelect('date')">
                                <i class="fas fa-users"></i>
                                <div class="assign-option-info">
                                    <span>استكمال من قبل</span>
                                    <small>اختر من القائمة</small>
                                </div>
                            </div>
                            ` : ''}
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
                            ${managerId !== null ? `
                            <div class="assign-option" onclick="assignSignatureToManager()">
                                <i class="fas fa-user-tie"></i>
                                <div class="assign-option-info">
                                    <span>مديري المباشر</span>
                                    <small>${managerName}</small>
                                </div>
                            </div>
                            ` : ''}
                            ${managerId === null ? `
                            <div class="assign-option" onclick="showOtherUsersSelect('signature')">
                                <i class="fas fa-users"></i>
                                <div class="assign-option-info">
                                    <span>استكمال من قبل</span>
                                    <small>اختر من القائمة</small>
                                </div>
                            </div>
                            ` : ''}
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
                            ${managerId !== null ? `
                            <div class="assign-option" onclick="assignImageToManager()">
                                <i class="fas fa-user-tie"></i>
                                <div class="assign-option-info">
                                    <span>مديري المباشر</span>
                                    <small>${managerName}</small>
                                </div>
                            </div>
                            ` : ''}
                            ${managerId === null ? `
                            <div class="assign-option" onclick="showOtherUsersSelect('image')">
                                <i class="fas fa-users"></i>
                                <div class="assign-option-info">
                                    <span>استكمال من قبل</span>
                                    <small>اختر من القائمة</small>
                                </div>
                            </div>
                            ` : ''}
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
            window.currentOtherType = type; // لتحديد أي نوع يتم تعيينه
        }

        // ===== دوال التعيين لاستكمال من قبل =====
        function assignNoteToOther() {
            const select = document.getElementById('otherUsersSelect');
            const selectedUserId = select.value;
            if (!selectedUserId) {
                alert('الرجاء اختيار مستخدم');
                return;
            }
            const selectedUserName = select.options[select.selectedIndex].text;

            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = selectedUserId;
                fields[index].assignedName = selectedUserName;
                fields[index].noteValue = null; // فارغ، سيملؤه المستخدم لاحقاً
                updateFieldInView(fields[index]);
                closeModal();
            }
        }

        function assignDateToOther() {
            const select = document.getElementById('otherUsersSelect');
            const selectedUserId = select.value;
            if (!selectedUserId) {
                alert('الرجاء اختيار مستخدم');
                return;
            }
            const selectedUserName = select.options[select.selectedIndex].text;

            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = selectedUserId;
                fields[index].assignedName = selectedUserName;
                fields[index].textValue = null;
                updateFieldInView(fields[index]);
                closeModal();
            }
        }

        function assignSignatureToOther() {
            const select = document.getElementById('otherUsersSelect');
            const selectedUserId = select.value;
            if (!selectedUserId) {
                alert('الرجاء اختيار مستخدم');
                return;
            }
            const selectedUserName = select.options[select.selectedIndex].text;

            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = selectedUserId;
                fields[index].assignedName = selectedUserName;
                fields[index].signatureData = null;
                updateFieldInView(fields[index]);
                closeModal();
            }
        }

        function assignImageToOther() {
            const select = document.getElementById('otherUsersSelect');
            const selectedUserId = select.value;
            if (!selectedUserId) {
                alert('الرجاء اختيار مستخدم');
                return;
            }
            const selectedUserName = select.options[select.selectedIndex].text;

            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = selectedUserId;
                fields[index].assignedName = selectedUserName;
                fields[index].imageData = null;
                updateFieldInView(fields[index]);
                closeModal();
            }
        }

        // ===== دوال التعيين البسيطة (نفسي، مدير) =====
        window.assignNoteToSelf = function() {
            closeModal();
            openNoteInputModal();
        };

        window.assignNoteToManager = function() {
            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = managerId;
                fields[index].assignedName = managerName;
                fields[index].noteValue = null;
                updateFieldInView(fields[index]);
                closeModal();
            }
        };

        window.assignDateToSelf = function() {
            closeModal();
            openDateInputModal();
        };

        window.assignDateToManager = function() {
            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = managerId;
                fields[index].assignedName = managerName;
                fields[index].textValue = null;
                updateFieldInView(fields[index]);
                closeModal();
            }
        };

        window.assignSignatureToSelf = function() {
            closeModal();
            openSignatureModal();
        };

        window.assignSignatureToManager = function() {
            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = managerId;
                fields[index].assignedName = managerName;
                fields[index].signatureData = null;
                updateFieldInView(fields[index]);
                closeModal();
            }
        };

        window.assignImageToSelf = function() {
            closeModal();
            openImageModal();
        };

        window.assignImageToManager = function() {
            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = managerId;
                fields[index].assignedName = managerName;
                fields[index].imageData = null;
                updateFieldInView(fields[index]);
                closeModal();
            }
        };

        // فتح نافذة إدخال التاريخ
        function openDateInputModal() {
            const today = new Date();
            const formattedDate = today.toISOString().split('T')[0];
            document.getElementById('dateInput').value = formattedDate;

            document.getElementById('dateInputModal').style.display = 'block';
        }

        // حفظ التاريخ
        function saveDateInput() {
            const dateInput = document.getElementById('dateInput').value;
            const dateFormat = document.getElementById('dateFormat').value;

            if (!dateInput) {
                alert('يرجى اختيار تاريخ');
                return;
            }

            const dateObj = new Date(dateInput);
            let formattedDate;

            if (dateFormat === 'ar-SA') {
                formattedDate = dateObj.toLocaleDateString('ar-SA');
            } else {
                formattedDate = dateObj.toLocaleDateString('en-US');
            }

            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].textValue = formattedDate;
                fields[index].assignedTo = userId;
                fields[index].assignedName = 'نفسي (أنا)';
                updateFieldInView(fields[index]);
                closeDateInputModal();
            }
        }

        // فتح نافذة إدخال الملاحظة
        function openNoteInputModal() {
            document.getElementById('noteInputModal').style.display = 'block';
        }

        // حفظ الملاحظة
        function saveNoteInput() {
            const noteValue = document.getElementById('noteInput').value.trim();
            if (!noteValue) {
                alert('يرجى إدخال ملاحظة');
                return;
            }

            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].noteValue = noteValue;
                fields[index].assignedTo = userId;
                fields[index].assignedName = 'نفسي (أنا)';
                updateFieldInView(fields[index]);
                closeNoteInputModal();
                closeModal();
            }
        }

        // فتح نافذة إدخال النص
        function openTextInputModal() {
            document.getElementById('textInputModal').style.display = 'block';
        }

        // حفظ النص
        function saveTextInput() {
            const textValue = document.getElementById('textInput').value.trim();
            if (!textValue) {
                alert('يرجى إدخال نص');
                return;
            }

            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].textValue = textValue;
                fields[index].assignedTo = userId;
                fields[index].assignedName = 'نفسي (أنا)';
                updateFieldInView(fields[index]);
                closeTextInputModal();
                closeModal();
            }
        }

        // ===== نافذة التوقيع =====
        function openSignatureModal() {
            document.getElementById('signatureModalBody').innerHTML = `
                <div style="text-align:center; padding:15px;">
                    <h3><i class="fas fa-signature"></i> إنشاء توقيع</h3>
                    <div style="margin:15px 0;">
                        <canvas id="signatureCanvas" style="width:100%; height:250px; border:2px dashed #3498db; background:#fff; touch-action:none;"></canvas>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="btn btn-secondary" onclick="clearSignatureCanvas()" style="flex:1; min-width:100px;">
                            <i class="fas fa-eraser"></i> مسح
                        </button>
                        <button class="btn btn-secondary" onclick="document.getElementById('signatureModal').style.display='none'" style="flex:1; min-width:100px;">
                            <i class="fas fa-times"></i> إلغاء
                        </button>
                        <button class="btn btn-primary" onclick="saveSignature()" style="flex:1; min-width:100px;">
                            <i class="fas fa-check"></i> حفظ
                        </button>
                    </div>
                </div>
            `;

            document.getElementById('signatureModal').style.display = 'block';

            setTimeout(() => {
                const canvas = document.getElementById('signatureCanvas');
                const parent = canvas.parentElement;
                canvas.width = parent.offsetWidth;
                canvas.height = parent.offsetHeight;

                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.strokeStyle = '#001496';
                ctx.lineWidth = 5;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';

                let isDrawing = false;
                let lastX = 0;
                let lastY = 0;

                canvas.addEventListener('mousedown', (e) => {
                    isDrawing = true;
                    [lastX, lastY] = [e.offsetX, e.offsetY];
                });

                canvas.addEventListener('mousemove', (e) => {
                    if (!isDrawing) return;
                    ctx.beginPath();
                    ctx.moveTo(lastX, lastY);
                    ctx.lineTo(e.offsetX, e.offsetY);
                    ctx.stroke();
                    [lastX, lastY] = [e.offsetX, e.offsetY];
                });

                canvas.addEventListener('mouseup', () => isDrawing = false);
                canvas.addEventListener('mouseout', () => isDrawing = false);
            }, 100);
        }

        window.clearSignatureCanvas = function() {
            const canvas = document.getElementById('signatureCanvas');
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        };

        window.saveSignature = function() {
            const canvas = document.getElementById('signatureCanvas');
            const signatureData = canvas.toDataURL('image/png');

            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].signatureData = signatureData;
                fields[index].assignedTo = userId;
                fields[index].assignedName = 'نفسي (أنا)';
                updateFieldInView(fields[index]);
                document.getElementById('signatureModal').style.display = 'none';
            }
        };

        // ===== نافذة الصورة =====
        function openImageModal() {
            document.getElementById('imageModalBody').innerHTML = `
                <div style="text-align:center; padding:15px;">
                    <h3><i class="fas fa-stamp"></i>   رفع صورة الختـــم    </h3>
                    <div style="margin:15px 0; border:2px dashed #34db7c; padding:30px; border-radius:10px; background:#e3f2fd; cursor:pointer;" id="imageUploadArea">
                        <i class="fas fa-cloud-upload-alt" style="font-size:48px; color:#3498db; margin-bottom:15px;"></i>
                        <p>انقر لاختيار صورة</p>
                        <input type="file" id="imageUpload" accept="image/*" style="display:none;">
                    </div>
                    <div id="imagePreview" style="margin:15px 0; display:none;">
                        <img id="previewImage" style="max-width:100%; max-height:200px; border:1px solid #ddd; border-radius:5px; object-fit:contain;">
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="btn btn-secondary" onclick="document.getElementById('imageModal').style.display='none'" style="flex:1; min-width:100px;">
                            <i class="fas fa-times"></i> إلغاء
                        </button>
                        <button class="btn btn-primary" onclick="saveImage()" style="flex:1; min-width:100px;">
                            <i class="fas fa-check"></i> حفظ
                        </button>
                    </div>
                </div>
            `;

            document.getElementById('imageModal').style.display = 'block';

            setTimeout(() => {
                const uploadArea = document.getElementById('imageUploadArea');
                const fileInput = document.getElementById('imageUpload');

                uploadArea.addEventListener('click', () => fileInput.click());

                fileInput.addEventListener('change', (e) => {
                    if (e.target.files.length > 0) {
                        previewImage(e.target.files[0]);
                    }
                });
            }, 100);
        }

        function previewImage(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('imagePreview');
                const img = document.getElementById('previewImage');
                img.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }

        window.saveImage = function() {
            const fileInput = document.getElementById('imageUpload');
            if (!fileInput.files.length) {
                alert('يرجى اختيار صورة');
                return;
            }

            const file = fileInput.files[0];
            if (file.size > 2 * 1024 * 1024) {
                alert('حجم الصورة يجب أن يكون أقل من 2MB');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const imageData = e.target.result;
                const index = fields.findIndex(f => f.id === currentField.id);
                if (index !== -1) {
                    fields[index].imageData = imageData;
                    fields[index].assignedTo = userId;
                    fields[index].assignedName = 'نفسي (أنا)';
                    updateFieldInView(fields[index]);
                    document.getElementById('imageModal').style.display = 'none';
                }
            };
            reader.readAsDataURL(file);
        };

        // ===== التحقق من النموذج =====
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const pdfFile = document.getElementById('pdf_file').files[0];
            const docName = document.getElementById('document_name').value.trim();

            if (!pdfFile) {
                alert('يرجى اختيار ملف PDF');
                e.preventDefault();
                return;
            }

            if (!docName) {
                alert('يرجى إدخال اسم المستند');
                e.preventDefault();
                return;
            }
           
            const textFields = fields.filter(f => f.type === 'نص');
            if (textFields.length === 0) {
                alert('يجب إضافة حقل "رقم داخلي" واحد على الأقل');
                e.preventDefault();
                return;
            }
                 

            const unassignedFields = fields.filter(f => !f.assignedTo);
            if (unassignedFields.length > 0) {
                alert('يجب تعيين جميع الحقول قبل إرسال المستند');
                e.preventDefault();
                return;
            }

            const myFields = fields.filter(f => f.assignedTo == userId);
            const incompleteMyFields = myFields.filter(f => {
                if (f.type === 'نص' && !f.textValue) return true;
                if (f.type === 'ملاحظة' && !f.noteValue) return true;
                if (f.type === 'تاريخ' && !f.textValue) return true;
                if (f.type === 'توقيع' && !f.signatureData) return true;
                if (f.type === 'صورة' && !f.imageData) return true;
                return false;
            });

            if (incompleteMyFields.length > 0) {
                alert('يجب ملء جميع الحقول المعينة لك قبل إرسال المستند');
                e.preventDefault();
                return;
            }

            updateFieldsData();
        });

        // تهيئة البحث عن المستخدمين
        function initUserSearch() {
            const userSearch = document.getElementById('userSearch');
            const userList = document.getElementById('userList');
            const selectedUserDisplay = document.getElementById('selectedUserDisplay');
            const selectedUserName = document.getElementById('selectedUserName');

            if (!userSearch || !userList) return;

            // بناء قائمة المستخدمين
            userList.innerHTML = '';

            // إضافة خيار "نفسي (أنا)"
            userList.innerHTML += `
        <div class="user-option" data-user-id="${userId}" onclick="selectUser(this)">
            <i class="fas fa-user"></i> نفسي (أنا) - ${userId}
        </div>
    `;

            // إضافة باقي المستخدمين
            allUsers.forEach(user => {
                if (user.id != userId) {
                    userList.innerHTML += `
                <div class="user-option" data-user-id="${user.id}" onclick="selectUser(this)">
                    <i class="fas fa-user-circle"></i> ${user.full_name}
                </div>
            `;
                }
            });

            // البحث الفوري
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

            // إخفاء القائمة عند النقر خارجها
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

            selectedUserName.textContent = element.textContent.trim();
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

        // فتح نافذة الملاحظة
        function openNoteModal(field) {
            currentField = field;
            document.getElementById('noteInput').value = field.noteValue || '';
            document.getElementById('noteModal').style.display = 'block';
            initUserSearch(); // إعادة تهيئة القائمة في كل مرة
        }

        function closeNoteModal() {
            document.getElementById('noteModal').style.display = 'none';
            clearUserSelection();
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

            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = userId;
                fields[index].assignedName = userName;
                fields[index].noteValue = finalNoteValue;
                updateFieldInView(fields[index]);
                closeNoteModal();
            }
        }

        // تعديل دالة assignNoteToSelf لفتح نافذة الملاحظة مباشرة
        window.assignNoteToSelf = function() {
            closeModal(); // إغلاق نافذة التعيين القديمة
            openNoteModal(currentField);
        };

        window.assignNoteToManager = function() {
            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = managerId;
                fields[index].assignedName = managerName;
                fields[index].noteValue = null; // سيتم ملؤها لاحقاً من قبل المدير
                updateFieldInView(fields[index]);
                closeModal();
            }
        };

        window.assignNoteToOther = function() {
            const select = document.getElementById('otherUsersSelect');
            const selectedUserId = select.value;
            if (!selectedUserId) {
                alert('الرجاء اختيار مستخدم');
                return;
            }
            const selectedUserName = select.options[select.selectedIndex].text;

            const index = fields.findIndex(f => f.id === currentField.id);
            if (index !== -1) {
                fields[index].assignedTo = selectedUserId;
                fields[index].assignedName = selectedUserName;
                fields[index].noteValue = null; // سيتم ملؤها لاحقاً من قبل المستخدم المعين
                updateFieldInView(fields[index]);
                closeModal();
            }
        };
    </script>


</body>

</html>