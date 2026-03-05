// ======================== دوال التصفية والأزرار الدائرية ========================
function filterByImportance(importance) {
    const url = new URL(window.location.href);
    if (importance === '') {
        url.searchParams.delete('importance');
    } else {
        url.searchParams.set('importance', importance);
    }
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

function resetFilters() {
    const url = new URL(window.location.href);
    url.searchParams.delete('search');
    url.searchParams.delete('document_type');
    url.searchParams.delete('status');
    url.searchParams.delete('priority');
    url.searchParams.delete('date_from');
    url.searchParams.delete('date_to');
    url.searchParams.delete('importance');
    url.searchParams.delete('sort_by');
    url.searchParams.delete('sort_order');
    window.location.href = url.toString();
}

function toggleAdvancedFilters() {
    const filtersSection = document.getElementById('advancedFiltersSection');
    const toggleBtn = document.getElementById('toggleAdvancedFiltersBtn');
    const toggleText = document.getElementById('toggleFiltersText');
    if (!filtersSection) return;
    if (filtersSection.style.display === 'none' || filtersSection.style.display === '') {
        filtersSection.style.display = 'block';
        filtersSection.style.animation = 'fadeIn 0.3s ease';
        if (toggleText) toggleText.textContent = 'إخفاء الفلاتر ';
        if (toggleBtn) {
            toggleBtn.innerHTML = `<i class="fas fa-sliders-h"></i>`;
            toggleBtn.style.background = 'linear-gradient(135deg, #e74c3c, #c0392b)';
        }
        localStorage.setItem('advancedFiltersVisible', 'true');
    } else {
        filtersSection.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => {
            filtersSection.style.display = 'none';
        }, 250);
        if (toggleText) toggleText.textContent = 'إظهار الفلاتر ';
        if (toggleBtn) {
            toggleBtn.innerHTML = `<i class="fas fa-sliders-h"></i>`;
            toggleBtn.style.background = 'linear-gradient(135deg, #3498db, #2980b9)';
        }
        localStorage.setItem('advancedFiltersVisible', 'false');
    }
}

function changeViewMode(mode) {
    const url = new URL(window.location.href);
    url.searchParams.set('view', mode);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

// ======================== نافذة التتبع ========================
function openTrackPopup(documentId) {
    const trackPopup = document.getElementById('trackPopup');
    const trackIframe = document.getElementById('trackIframe');
    if (trackPopup && trackIframe) {
        trackIframe.src = 'track_document.php?id=' + documentId;
        trackPopup.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        trackPopup.classList.add('popup-open');
    } else {
        window.open('track_document.php?id=' + documentId, 'trackWindow', 'width=1200,height=700,scrollbars=yes');
    }
}

function closeTrackPopup() {
    const trackPopup = document.getElementById('trackPopup');
    const trackIframe = document.getElementById('trackIframe');
    if (trackPopup && trackIframe) {
        trackPopup.style.display = 'none';
        trackIframe.src = '';
        document.body.style.overflow = 'auto';
        trackPopup.classList.remove('popup-open');
    }
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeTrackPopup();
});

document.getElementById('trackPopup')?.addEventListener('click', function (e) {
    if (e.target === this) closeTrackPopup();
});

// ======================== نافذة إضافة خطوة workflow ========================
function showAddWorkflowStepModal(documentId) {
    const modal = document.getElementById('workflowStepModal');
    const docIdInput = document.getElementById('modalDocumentId');
    if (modal && docIdInput) {
        docIdInput.value = documentId;
        modal.style.display = 'flex';
    }
}

function closeWorkflowStepModal() {
    const modal = document.getElementById('workflowStepModal');
    if (modal) modal.style.display = 'none';
}

function toggleStepFields() {
    const stepType = document.getElementById('stepTypeSelect')?.value;
    const fieldsSection = document.getElementById('fieldsSection');
    if (stepType === 'approve' || stepType === 'reject') {
        if (fieldsSection) fieldsSection.style.display = 'none';
        document.querySelectorAll('input[name="fields[]"]').forEach(cb => cb.checked = false);
    } else {
        if (fieldsSection) fieldsSection.style.display = 'block';
        document.querySelectorAll('input[name="fields[]"]').forEach(cb => {
            if (cb.value !== 'image') cb.checked = true;
        });
    }
}

window.onclick = function (event) {
    const modal = document.getElementById('workflowStepModal');
    if (event.target === modal) closeWorkflowStepModal();
};

// ======================== Toast والإشعارات ========================
function showToast(title, message, type = 'success') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position:fixed; top:20px; left:20px; z-index:9999;';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <div class="toast-icon"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i></div>
        <div class="toast-content"><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div>
        <button class="toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
    `;
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        if (toast.parentNode) {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }
    }, 5000);
}

// ======================== فافيكون الإشعارات ========================
let baseFaviconImage = null;
let faviconLoaded = false;

function loadBaseFavicon(callback) {
    if (baseFaviconImage) {
        callback();
        return;
    }
    baseFaviconImage = new Image();
    baseFaviconImage.crossOrigin = 'anonymous';
    baseFaviconImage.src = '../images/favicon.ico';
    baseFaviconImage.onload = function() {
        faviconLoaded = true;
        callback();
    };
    baseFaviconImage.onerror = function() {
        faviconLoaded = false;
        callback();
    };
}

function setFaviconWithBadge(count) {
    loadBaseFavicon(function() {
        let link = document.querySelector("link[rel*='icon']") || document.createElement('link');
        link.type = 'image/x-icon';
        link.rel = 'shortcut icon';
        const canvas = document.createElement('canvas');
        canvas.width = 32;
        canvas.height = 32;
        const ctx = canvas.getContext('2d');
        if (faviconLoaded && baseFaviconImage) {
            ctx.drawImage(baseFaviconImage, 0, 0, 32, 32);
        } else {
            ctx.fillStyle = '#164a40';
            ctx.fillRect(0, 0, 32, 32);
        }
        if (count > 0) {
            const circleX = 28, circleY = 28, circleRadius = 12;
            ctx.beginPath();
            ctx.arc(circleX, circleY, circleRadius, 0, 2 * Math.PI);
            ctx.fillStyle = '#e74c3c';
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 2;
            ctx.stroke();
            ctx.font = 'bold 14px Arial';
            ctx.fillStyle = '#ffffff';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            const displayNumber = count > 99 ? '99+' : count;
            ctx.fillText(displayNumber, circleX, circleY);
        }
        link.href = canvas.toDataURL('image/x-icon');
        document.head.appendChild(link);
    });
}

// ======================== البحث الفوري في الجدول ========================
function performInstantSearch(searchTerm) {
    const table = document.getElementById('documentsTable');
    if (!table) return;
    const rows = table.querySelectorAll('.document-row');
    let matchCount = 0;
    rows.forEach(row => {
        row.classList.remove('highlight-search');
        const searchableData = row.getAttribute('data-searchable');
        let isMatch = false;
        if (searchableData) {
            try {
                const data = JSON.parse(searchableData);
                const searchableText = Object.values(data)
                    .filter(v => v != null)
                    .map(v => String(v).toLowerCase())
                    .join(' ');
                isMatch = searchableText.includes(searchTerm.toLowerCase());
                if (!isMatch) {
                    const visibleText = row.textContent.toLowerCase();
                    isMatch = visibleText.includes(searchTerm.toLowerCase());
                }
            } catch (e) {}
        }
        if (isMatch) {
            row.classList.remove('hidden-by-search');
            row.classList.add('highlight-search');
            matchCount++;
        } else {
            row.classList.add('hidden-by-search');
        }
    });
    const searchInfo = document.getElementById('tableSearchInfo');
    const resultsCount = document.getElementById('searchResultsCount');
    if (searchTerm.trim() !== '') {
        if (resultsCount) resultsCount.textContent = matchCount;
        if (searchInfo) {
            searchInfo.style.display = 'block';
            if (matchCount === 0) {
                searchInfo.innerHTML = '<span style="color:#e74c3c">لم يتم العثور على نتائج</span>';
            } else {
                searchInfo.innerHTML = `<span style="color:#27ae60">${matchCount}</span> نتيجة`;
            }
        }
    } else {
        if (searchInfo) searchInfo.style.display = 'none';
    }
}

// ======================== التهيئة عند تحميل الصفحة ========================
document.addEventListener('DOMContentLoaded', function() {
    // ربط نموذج الفلاتر
    const filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            let input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'importance';
            input.value = '';
            this.appendChild(input);
        });
    }

    // فتح/إغلاق الفلاتر بناءً على الحالة المحفوظة
    const filtersVisible = localStorage.getItem('advancedFiltersVisible');
    const filtersSection = document.getElementById('advancedFiltersSection');
    if (filtersVisible === 'true' && filtersSection) {
        setTimeout(() => {
            if (filtersSection.style.display !== 'block') toggleAdvancedFilters();
        }, 500);
    }

    // البحث الفوري
    const searchInput = document.getElementById('instantTableSearch');
    if (searchInput) {
        let timeout;
        searchInput.addEventListener('input', function(e) {
            clearTimeout(timeout);
            timeout = setTimeout(() => performInstantSearch(e.target.value.trim()), 300);
        });
        // زر إعادة تعيين داخل الحقل
        const resetBtn = document.createElement('button');
        resetBtn.innerHTML = '<i class="fas fa-times"></i>';
        resetBtn.style.cssText = 'position:absolute; left:35px; top:50%; transform:translateY(-50%); background:none; border:none; color:#95a5a6; cursor:pointer; display:none;';
        resetBtn.addEventListener('click', function() {
            searchInput.value = '';
            performInstantSearch('');
            searchInput.focus();
        });
        searchInput.parentNode.appendChild(resetBtn);
        searchInput.addEventListener('input', function() {
            resetBtn.style.display = this.value.trim() ? 'block' : 'none';
        });
    }

    // عرض الفافيكون مع عدد الإشعارات
    const unreadCountElement = document.getElementById('unreadCount'); // تأكد من وجود عنصر بهذا id أو استخدم متغير PHP
    if (typeof unreadCount !== 'undefined') {
        setFaviconWithBadge(unreadCount);
    } else {
        // إذا لم يكن المتغير معرفاً، يمكنك قراءته من عنصر مخفي
        const count = document.querySelector('meta[name="unread-count"]')?.content;
        if (count) setFaviconWithBadge(parseInt(count));
    }

    // كشف الجهاز
    if (window.innerWidth <= 768) {
        const urlParams = new URLSearchParams(window.location.search);
        if (!urlParams.has('view')) {
            urlParams.set('view', 'cards');
            history.replaceState({}, '', `${window.location.pathname}?${urlParams}`);
        }
    }
});

// تحديث الصفحة كل 3 دقائق
setTimeout(() => location.reload(), 180000);

// ======================== إدارة أزرار الحقول في نافذة الإضافة ========================
(function() {
    // متغيرات النافذة
    let modal = document.getElementById('workflowStepModal');
    if (!modal) return; // إذا لم توجد النافذة نخرج

    let fieldButtons = modal.querySelectorAll('.field-button');
    let selectedFieldsInput = document.getElementById('selectedFieldsInput');
    let selectedFieldsText = document.getElementById('selectedFieldsText');

    // تحديث قائمة الحقول المحددة
    function updateSelectedFields() {
        const selected = [];
        modal.querySelectorAll('.field-button[data-selected="true"]').forEach(btn => {
            selected.push(btn.getAttribute('data-field'));
        });
        if (selectedFieldsInput) selectedFieldsInput.value = selected.join(',');
        
        // ترجمة أسماء الحقول للعرض
        const labels = {
            signature: 'توقيع',
            date: 'تاريخ',
            image: 'ختم',
            note: 'ملاحظة'
        };
        if (selectedFieldsText) {
            selectedFieldsText.textContent = selected.map(f => labels[f] || f).join(' - ');
        }
    }

    // إضافة حدث النقر لكل زر
    fieldButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const isSelected = this.getAttribute('data-selected') === 'true';
            this.setAttribute('data-selected', isSelected ? 'false' : 'true');
            if (isSelected) {
                this.classList.remove('selected');
            } else {
                this.classList.add('selected');
            }
            updateSelectedFields();
        });
    });

    // دالة إعادة تعيين الحقول عند فتح النافذة
    window.resetFieldsForModal = function(documentId) {
        let docIdInput = document.getElementById('modalDocumentId');
        if (docIdInput) docIdInput.value = documentId;

        // تعيين الحالة الافتراضية: فقط "ملاحظة" محددة
        modal.querySelectorAll('.field-button').forEach(btn => {
            const field = btn.getAttribute('data-field');
            if (field === 'note') {
                btn.setAttribute('data-selected', 'true');
                btn.classList.add('selected');
            } else {
                btn.setAttribute('data-selected', 'false');
                btn.classList.remove('selected');
            }
        });
        updateSelectedFields();
    };

    // دالة إلغاء تحديد جميع الحقول
    window.clearAllFields = function() {
        modal.querySelectorAll('.field-button').forEach(btn => {
            btn.setAttribute('data-selected', 'false');
            btn.classList.remove('selected');
        });
        updateSelectedFields();
    };

    // تحديث النافذة عند العرض (للتأكد من الحالة الافتراضية)
    const originalShowFunction = window.showAddWorkflowStepModal;
    window.showAddWorkflowStepModal = function(documentId) {
        if (window.resetFieldsForModal) {
            window.resetFieldsForModal(documentId);
        } else {
            let docIdInput = document.getElementById('modalDocumentId');
            if (docIdInput) docIdInput.value = documentId;
        }
        if (modal) modal.style.display = 'flex';
    };
})();