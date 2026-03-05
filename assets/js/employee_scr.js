// ملف employee_scr.js - نسخة نظيفة وموحدة

// ========== دوال التصفية الأساسية ==========
window.filterByImportance = function (importance) {
  const url = new URL(window.location.href);
  if (importance === "") {
    url.searchParams.delete("importance");
    url.searchParams.delete("priority");
  } else {
    url.searchParams.set("importance", importance);
    // تحويل الأهمية إلى قيمة priority في قاعدة البيانات
    if (importance === "سري") url.searchParams.set("priority", "urgent");
    else if (importance === "عاجل") url.searchParams.set("priority", "high");
    else if (importance === "عادي") url.searchParams.set("priority", "normal");
  }
  url.searchParams.set("page", "1");
  window.location.href = url.toString();
};

window.resetFilters = function () {
  const url = new URL(window.location.href);
  [
    "search",
    "document_type",
    "status",
    "priority",
    "date_from",
    "date_to",
    "importance",
    "sort_by",
    "sort_order",
  ].forEach((p) => url.searchParams.delete(p));
  window.location.href = url.toString();
};

// ========== دوال عرض الفلاتر المتقدمة ==========
window.toggleAdvancedFilters = function () {
  const section = document.getElementById("advancedFiltersSection");
  const btn = document.getElementById("toggleAdvancedFiltersBtn");
  const textSpan = document.getElementById("toggleFiltersText");
  if (!section) return;

  // استخدم getComputedStyle لمعرفة الحالة الفعلية
  const isHidden = window.getComputedStyle(section).display === "none";

  if (isHidden) {
    section.style.display = "block";
    if (textSpan) textSpan.textContent = "إخفاء الفلاتر";
    if (btn) btn.style.background = "linear-gradient(135deg, #e74c3c, #c0392b)";
    try {
      localStorage.setItem("advancedFiltersVisible", "true");
    } catch (e) {}
  } else {
    section.style.display = "none";
    if (textSpan) textSpan.textContent = "بحث متقدم";
    if (btn) btn.style.background = "linear-gradient(135deg, #3498db, #2980b9)";
    try {
      localStorage.setItem("advancedFiltersVisible", "false");
    } catch (e) {}
  }
};
// ========== دوال تغيير طريقة العرض ==========
window.changeViewMode = function (mode) {
  const url = new URL(window.location.href);
  url.searchParams.set("view", mode);
  url.searchParams.set("page", "1");
  window.location.href = url.toString();
};

// ========== دوال نافذة التتبع ==========
window.openTrackPopup = function (docId) {
  const popup = document.getElementById("trackPopup");
  const iframe = document.getElementById("trackIframe");
  if (popup && iframe) {
    iframe.src = `../dashboard/track_document.php?id=${docId}`;
    popup.style.display = "flex";
    document.body.style.overflow = "hidden";
  } else {
    window.open(
      `../dashboard/track_document.php?id=${docId}`,
      "trackWindow",
      "width=1200,height=700,scrollbars=yes",
    );
  }
};

window.closeTrackPopup = function () {
  const popup = document.getElementById("trackPopup");
  const iframe = document.getElementById("trackIframe");
  if (popup && iframe) {
    popup.style.display = "none";
    iframe.src = "";
    document.body.style.overflow = "";
  }
};

// إغلاق نافذة التتبع عند النقر خارجها أو ESC
document.addEventListener("click", function (e) {
  if (e.target.classList.contains("popup-overlay")) {
    closeTrackPopup();
  }
});
document.addEventListener("keydown", function (e) {
  if (e.key === "Escape") closeTrackPopup();
});

// ========== دوال نافذة إضافة خطوة workflow ==========
window.showAddWorkflowStepModal = function (docId) {
  document.getElementById("modalDocumentId").value = docId;
  document.getElementById("workflowStepModal").style.display = "flex";
};
window.closeWorkflowStepModal = function () {
  document.getElementById("workflowStepModal").style.display = "none";
};

// ========== دوال إدارة الحقول في نافذة الاستكمال ==========
function initFieldButtons() {
  const container = document.querySelector(".field-buttons-container");
  if (!container) return;

  container.addEventListener("click", (e) => {
    const btn = e.target.closest(".field-button");
    if (!btn) return;
    const field = btn.dataset.field;
    const selected = btn.dataset.selected === "true";
    btn.dataset.selected = (!selected).toString();
    btn.classList.toggle("selected", !selected);
    updateSelectedFieldsInput();
  });

  // تعيين الحقل الافتراضي (ملاحظة)
  const noteBtn = document.querySelector('.field-button[data-field="note"]');
  if (noteBtn) {
    noteBtn.dataset.selected = "true";
    noteBtn.classList.add("selected");
  }
  updateSelectedFieldsInput();
}

function updateSelectedFieldsInput() {
  const selected = Array.from(
    document.querySelectorAll('.field-button[data-selected="true"]'),
  ).map((b) => b.dataset.field);
  document.getElementById("selectedFieldsInput").value = selected.join(",");
  const names = {
    signature: "توقيع",
    date: "تاريخ",
    image: "ختم",
    note: "ملاحظة",
    text: "نص",
  };
  document.getElementById("selectedFieldsText").textContent = selected
    .map((f) => names[f] || f)
    .join("، ");
}

window.clearAllFields = function () {
  document.querySelectorAll(".field-button").forEach((btn) => {
    btn.dataset.selected = "false";
    btn.classList.remove("selected");
  });
  // إعادة تعيين الملاحظة كمحددة افتراضياً
  const noteBtn = document.querySelector('.field-button[data-field="note"]');
  if (noteBtn) {
    noteBtn.dataset.selected = "true";
    noteBtn.classList.add("selected");
  }
  updateSelectedFieldsInput();
};

// ========== دوال البحث الفوري في الجدول ==========
function initInstantSearch() {
  const searchInput = document.getElementById("instantTableSearch");
  const searchInfo = document.getElementById("tableSearchInfo");
  const resultsCount = document.getElementById("searchResultsCount");
  const table = document.getElementById("documentsTable");
  if (!searchInput || !table) return;

  let timeout;
  const performSearch = (term) => {
    const rows = table.querySelectorAll(".document-row");
    let matchCount = 0;
    const searchLower = term.toLowerCase();

    rows.forEach((row) => {
      const searchable = row.dataset.searchable;
      let match = false;
      if (searchable) {
        try {
          const data = JSON.parse(searchable);
          match = Object.values(data).some(
            (v) => v && String(v).toLowerCase().includes(searchLower),
          );
        } catch (e) {
          /* ignore */
        }
      }
      if (!match) match = row.textContent.toLowerCase().includes(searchLower);

      row.classList.toggle("hidden-by-search", !match);
      row.classList.toggle("highlight-search", match);
      if (match) matchCount++;
    });

    if (term.trim()) {
      searchInfo.style.display = "block";
      resultsCount.innerHTML = matchCount
        ? `<span style="color:#27ae60">${matchCount}</span> نتيجة`
        : '<span style="color:#e74c3c">لا توجد نتائج</span>';
    } else {
      searchInfo.style.display = "none";
    }
  };

  searchInput.addEventListener("input", (e) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => performSearch(e.target.value.trim()), 300);
  });
  searchInput.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      searchInput.value = "";
      performSearch("");
      searchInput.blur();
    }
  });
}

// ========== دوال Toast ==========
window.showToast = function (title, message, type = "success") {
  const container = document.getElementById("toastContainer");
  if (!container) return;
  const toast = document.createElement("div");
  toast.className = `toast ${type}`;
  toast.innerHTML = `
        <div class="toast-icon"><i class="fas ${type === "success" ? "fa-check-circle" : "fa-exclamation-triangle"}"></i></div>
        <div class="toast-content"><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div>
        <button class="toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
    `;
  container.appendChild(toast);
  setTimeout(() => toast.classList.add("show"), 10);
  setTimeout(() => {
    if (toast.parentNode) {
      toast.classList.remove("show");
      setTimeout(() => toast.remove(), 400);
    }
  }, 5000);
};

// ========== التهيئة عند تحميل الصفحة ==========
document.addEventListener("DOMContentLoaded", function () {
  // تهيئة أزرار الحقول في نافذة الاستكمال
  initFieldButtons();

  // تهيئة البحث الفوري
  initInstantSearch();

  // استعادة حالة الفلاتر المتقدمة
  const filtersSection = document.getElementById("advancedFiltersSection");
  const toggleBtn = document.getElementById("toggleAdvancedFiltersBtn");
  if (
    filtersSection &&
    toggleBtn &&
    localStorage.getItem("advancedFiltersVisible") === "true"
  ) {
    filtersSection.style.display = "block";
    toggleBtn.style.background = "linear-gradient(135deg, #e74c3c, #c0392b)";
    document.getElementById("toggleFiltersText").textContent = "إخفاء الفلاتر";
  }

  // كشف الجهاز وتعيين العرض المناسب للموبايل
  if (
    window.innerWidth <= 768 &&
    !new URLSearchParams(window.location.search).has("view")
  ) {
    const url = new URL(window.location.href);
    url.searchParams.set("view", "cards");
    window.history.replaceState({}, "", url);
  }
});

// دالة فتح مودال تأكيد الحذف
function deleteDocument(documentId) {
    document.getElementById('deleteDocumentId').value = documentId;
    document.getElementById('deleteConfirmModal').style.display = 'flex';
}

// دالة إغلاق المودال
function closeDeleteModal() {
    document.getElementById('deleteConfirmModal').style.display = 'none';
    document.getElementById('deleteDocumentId').value = '';
}

// دالة تنفيذ الحذف بعد التأكيد
function confirmDelete() {
    const documentId = document.getElementById('deleteDocumentId').value;
    if (!documentId) return;

    closeDeleteModal(); // إغلاق المودال

    // إظهار إشعار التحميل
    showToast('جاري حذف المستند...', 'info');

    // قراءة CSRF token من المتغير العام إن وُجد
    const csrfToken = window.csrfToken || '';
    const body = new URLSearchParams({
        document_id: documentId,
        csrf_token: csrfToken,
    }).toString();

    // إرسال طلب AJAX
    fetch('../documents/delete_document.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body,
    })
        .then((response) => response.json())
        .then((data) => {
            if (data.success) {
                showToast('تم حذف المستند بنجاح', 'success');
                // إعادة تحميل الصفحة بعد ثانية
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('خطأ: ' + data.message, 'error');
            }
        })
        .catch((error) => {
            showToast('حدث خطأ في الاتصال', 'error');
            console.error('Error:', error);
        });
}
