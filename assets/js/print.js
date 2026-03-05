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
                case 'signature': return 'signature';
                case 'text': return 'font';
                case 'date': return 'calendar-alt';
                case 'note': return 'sticky-note';
                case 'image': return 'image';
                default: return 'edit';
            }
        }

        function getFieldTypeName(type) {
            switch (type) {
                case 'signature': return 'توقيع';
                case 'text': return 'نص';
                case 'date': return 'تاريخ';
                case 'note': return 'ملاحظة';
                case 'image': return 'صورة';
                default: return 'حقل';
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
                const viewport = page.getViewport({ scale: 1 });

                // حساب مقياس التكبير بناءً على العرض الثابت
                const scale = (targetWidth / viewport.width) * currentScale;
                const scaledViewport = page.getViewport({ scale: scale });

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
            const minWidth = 30, minHeight = 20;
            const maxWidth = 300, maxHeight = 200;

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
                    showNotification('تم حفظ الحجم الجديد', 'success');
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
                        document.getElementById('imageInput').addEventListener('change', function (e) {
                            const file = e.target.files[0];
                            if (file) {
                                if (file.size > 2 * 1024 * 1024) {
                                    alert('حجم الصورة كبير جداً. الحد الأقصى 2MB');
                                    this.value = '';
                                    return;
                                }

                                const reader = new FileReader();
                                reader.onload = function (e) {
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
                    reader.onload = async function (e) {
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
                    showNotification('تم حفظ القيمة بنجاح', 'success');
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
        document.getElementById('attachmentFile').addEventListener('change', function (e) {
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
                showNotification('جاري حذف المرفق...', 'info');

                const response = await fetch('../documents/delete_attachment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `attachment_id=${attachId}&document_id=${docData.id}`
                });

                const result = await response.json();
                if (result.success) {
                    showNotification('تم حذف المرفق بنجاح', 'success');
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
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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
                    const viewport = page.getViewport({ scale: 1 });

                    const scale = Math.min(availableWidth / viewport.width, 1.2);
                    const scaledViewport = page.getViewport({ scale: scale });

                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    canvas.width = scaledViewport.width;
                    canvas.height = scaledViewport.height;

                    await page.render({ canvasContext: ctx, viewport: scaledViewport }).promise;

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
        document.addEventListener('DOMContentLoaded', function () {
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

                img.onload = function () {
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
        window.onclick = function (event) {
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
        };

        document.addEventListener('keydown', function (event) {
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
        document.addEventListener('DOMContentLoaded', function () {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('track') === '1') {
                setTimeout(() => {
                    openTrackModal();
                }, 1000);
            }
        });
        // ============== حذف ملاحظة ==============
        async function deleteNoteField(fieldId) {
            if (!confirm('هل أنت متأكد من حذف هذه الملاحظة؟ هذا الإجراء لا يمكن التراجع عنه.')) {
                return;
            }

            try {
                showNotification('جاري حذف الملاحظة...', 'info');

                const formData = new FormData();
                formData.append('field_id', fieldId);
                formData.append('document_id', docData.id);

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
            }
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
                const viewport = page.getViewport({ scale: 1 });
                // حساب scale المناسب لملء العرض مع تطبيق scale المطلوب
                const fitScale = (containerWidth / viewport.width) * scale;
                const scaledViewport = page.getViewport({ scale: fitScale });

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = scaledViewport.width;
                canvas.height = scaledViewport.height;

                await page.render({ canvasContext: ctx, viewport: scaledViewport }).promise;

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
        document.addEventListener('DOMContentLoaded', function () {
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

            iframe.onerror = function () {
                clearTimeout(printTimeout);
                showNotification('فشل تحميل صفحة الطباعة', 'error');
                document.body.removeChild(iframe);
            };
        }
