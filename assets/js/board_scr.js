// دالة التصفية حسب الأهمية
function filterByImportance(importance) {
  const url = new URL(window.location.href);

  if (importance === "") {
    // إزالة فلتر الأهمية
    url.searchParams.delete("importance");
    url.searchParams.delete("priority");
  } else {
    // تعيين فلتر الأهمية
    url.searchParams.set("importance", importance);

    // تعيين قيمة priority المناسبة في الفلاتر المتقدمة
    if (importance === "سري") {
      url.searchParams.set("priority", "urgent");
    } else if (importance === "عاجل") {
      url.searchParams.set("priority", "high");
    } else if (importance === "عادي") {
      url.searchParams.set("priority", "normal");
    }
  }

  url.searchParams.set("page", 1);
  window.location.href = url.toString();
}

// دالة إعادة تعيين الفلاتر
function resetFilters() {
  const url = new URL(window.location.href);
  // إزالة جميع معاملات التصفية
  url.searchParams.delete("search");
  url.searchParams.delete("document_type");
  url.searchParams.delete("status");
  url.searchParams.delete("priority");
  url.searchParams.delete("date_from");
  url.searchParams.delete("date_to");
  url.searchParams.delete("importance");
  url.searchParams.delete("sort_by");
  url.searchParams.delete("sort_order");
  url.searchParams.delete("page");

  window.location.href = url.toString();
}

// دالة تبديل الفلاتر المتقدمة
function toggleAdvancedFilters() {
  const filtersSection = document.getElementById("advancedFiltersSection");
  const toggleBtn = document.getElementById("toggleAdvancedFiltersBtn");
  const toggleText = document.getElementById("toggleFiltersText");

  if (
    filtersSection.style.display === "none" ||
    filtersSection.style.display === ""
  ) {
    // إظهار الفلاتر مع تأثير
    filtersSection.style.display = "block";
    filtersSection.style.animation = "fadeIn 0.3s ease";

    // تحديث الزر
    toggleText.textContent = "بحث متقدم";
    toggleBtn.innerHTML = `<i class="fas fa-sliders-h"></i>`;
    toggleBtn.style.background = "linear-gradient(135deg, #e74c3c, #c0392b)";

    // حفظ الحالة
    localStorage.setItem("advancedFiltersVisible", "true");
  } else {
    // إخفاء الفلاتر مع تأثير
    filtersSection.style.animation = "fadeOut 0.3s ease";
    setTimeout(() => {
      filtersSection.style.display = "none";
    }, 250);

    // تحديث الزر
    toggleText.textContent = "بحث متقدم";
    toggleBtn.innerHTML = `<i class="fas fa-sliders-h"></i>`;
    toggleBtn.style.background = "linear-gradient(135deg, #3498db, #2980b9)";

    // حفظ الحالة
    localStorage.setItem("advancedFiltersVisible", "false");
  }
}

// دالة تغيير طريقة العرض
function changeViewMode(mode) {
  const url = new URL(window.location.href);
  url.searchParams.set("view", mode);
  // إعادة تعيين الصفحة إلى 1 عند تغيير العرض
  url.searchParams.set("page", 1);
  window.location.href = url.toString();
}

// دالة فتح نافذة التتبع (Popup)
function openTrackPopup(documentId) {
  const trackPopup = document.getElementById("trackPopup");
  const trackIframe = document.getElementById("trackIframe");

  if (trackPopup && trackIframe) {
    trackIframe.src = "track_document.php?id=" + documentId;
    trackPopup.style.display = "flex";
    document.body.style.overflow = "hidden";
  } else {
    // خيار احتياطي إذا لم تكن النافذة موجودة
    window.open(
      "track_document.php?id=" + documentId,
      "trackWindow",
      "width=1200,height=700,scrollbars=yes",
    );
  }
}

// دالة إغلاق نافذة التتبع
function closeTrackPopup() {
  const trackPopup = document.getElementById("trackPopup");
  const trackIframe = document.getElementById("trackIframe");

  if (trackPopup && trackIframe) {
    trackPopup.style.display = "none";
    trackIframe.src = "";
    document.body.style.overflow = "auto";
  }
}

function showAddWorkflowStepModal(documentId, currentPriority) {
  document.getElementById("modalDocumentId").value = documentId;
  document.getElementById("workflowStepModal").style.display = "flex";

  // تعيين القيم الافتراضية
  document.getElementById("stepTypeSelect").value = "signature";
  document.getElementById("assignedToSelect").value = "";
  document.querySelector('textarea[name="creator_note"]').value = "";

  // تعيين الأولوية الحالية في القائمة المنسدلة
  // تعيين الأولوية الحالية في القائمة المنسدلة (إذا كان الحقل موجوداً)
  const prioritySelect = document.getElementById("prioritySelectModal");
  if (prioritySelect && currentPriority) {
    prioritySelect.value = currentPriority;
  }

  toggleStepFields(); // تطبيق العرض الأولي
}

function closeWorkflowStepModal() {
  document.getElementById("workflowStepModal").style.display = "none";
}

function toggleStepFields() {
  const stepType = document.getElementById("stepTypeSelect").value;
  const fieldsSection = document.getElementById("fieldsSection");
  const prioritySection = document.getElementById("prioritySection");

  if (stepType === "approve" || stepType === "reject") {
    // إخفاء الحقول وإظهار حقل الملاحظة فقط
    if (fieldsSection) fieldsSection.style.display = "none";
    if (prioritySection) prioritySection.style.display = "none";

    // إلغاء اختيار جميع الحقول
    document.querySelectorAll('input[name="fields[]"]').forEach((checkbox) => {
      checkbox.checked = false;
    });
  } else {
    // إظهار الحقول
    if (fieldsSection) fieldsSection.style.display = "block";
    if (prioritySection) prioritySection.style.display = "block";

    // تفعيل جميع الحقول واختيار الافتراضي
    document.querySelectorAll('input[name="fields[]"]').forEach((checkbox) => {
      if (checkbox.value !== "image") {
        checkbox.checked = true;
      }
    });
  }
}

// إغلاق النافذة عند النقر خارجها
window.onclick = function (event) {
  const modal = document.getElementById("workflowStepModal");
  if (event.target === modal) {
    closeWorkflowStepModal();
  }
};

// تحديث الصفحة كل 3 دقائق
setTimeout(function () {
  window.location.reload();
}, 180000);

// دالة عرض رسائل Toast عائمة
function showToast(title, message, type = "success") {
  const container = document.getElementById("toastContainer");

  // إنشاء Toast
  const toast = document.createElement("div");
  toast.className = `toast ${type}`;
  toast.innerHTML = `
                <div class="toast-icon">
                    <i class="fas ${type === "success" ? "fa-check-circle" : "fa-exclamation-triangle"}"></i>
                </div>
                <div class="toast-content">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;

  // إضافة للصفحة
  container.appendChild(toast);

  // إظهار مع أنيميشن
  setTimeout(() => toast.classList.add("show"), 10);

  // إزالة تلقائية بعد 5 ثواني
  setTimeout(() => {
    if (toast.parentNode) {
      toast.classList.remove("show");
      setTimeout(() => {
        if (toast.parentNode) {
          container.removeChild(toast);
        }
      }, 400);
    }
  }, 5000);

  // إزالة عند النقر على Toast
  toast.addEventListener("click", function (e) {
    if (!e.target.closest(".toast-close")) {
      this.classList.remove("show");
      setTimeout(() => {
        if (this.parentNode) {
          container.removeChild(this);
        }
      }, 400);
    }
  });
}

// تهيئة عند تحميل الصفحة
document.addEventListener("DOMContentLoaded", function () {
  const toggleBtn = document.getElementById("toggleAdvancedFiltersBtn");
  const toggleText = document.getElementById("toggleFiltersText");

  if (toggleBtn && toggleText) {
    // إضافة حدث النقر
    toggleBtn.addEventListener("click", toggleAdvancedFilters);

    // التحقق من وجود فلتر نشط
    const urlParams = new URLSearchParams(window.location.search);
    const hasActiveFilter =
      urlParams.has("search") ||
      urlParams.has("document_type") ||
      urlParams.has("status") ||
      urlParams.has("priority") ||
      urlParams.has("date_from") ||
      urlParams.has("date_to");

    // استعادة الحالة من localStorage أو العرض التلقائي
    const filtersVisible = localStorage.getItem("advancedFiltersVisible");
    const filtersSection = document.getElementById("advancedFiltersSection");

    if ((filtersVisible === "true" || hasActiveFilter) && filtersSection) {
      // عرض الفلاتر تلقائياً مع تأخير بسيط لتأثير أفضل
      setTimeout(() => {
        if (filtersSection.style.display === "none") {
          toggleAdvancedFilters();
        }
      }, 500);
    }
  }// تعديل روابط أزرار الحذف في HTML (تأكد من أن كل زر يستدعي deleteDocument(doc.id) فقط)
// الموجود بالفعل: onclick="deleteDocument(<?php echo $doc['id']; ?>)"

  // تهيئة البحث الفوري
  initializeInstantSearch();
});

// البحث الفوري في الجدول
function initializeInstantSearch() {
  const searchInput = document.getElementById("instantTableSearch");
  const searchInfo = document.getElementById("tableSearchInfo");
  const resultsCount = document.getElementById("searchResultsCount");
  const table = document.getElementById("documentsTable");

  if (!searchInput || !table) return;

  let searchTimeout;

  // دالة البحث الفوري
  function performInstantSearch(searchTerm) {
    const rows = table.querySelectorAll(".document-row");
    let matchCount = 0;

    rows.forEach((row) => {
      // إزالة التمييز السابق
      row.classList.remove("highlight-search");

      // البحث في جميع البيانات المخزنة في data-searchable
      const searchableData = row.getAttribute("data-searchable");
      let isMatch = false;

      if (searchableData) {
        try {
          const data = JSON.parse(searchableData);
          const searchableText = Object.values(data)
            .filter((value) => value !== null && value !== undefined)
            .map((value) => String(value).toLowerCase())
            .join(" ");

          // البحث في النص
          isMatch = searchableText.includes(searchTerm.toLowerCase());

          // البحث أيضًا في المحتوى المرئي (للحالات الخاصة)
          if (!isMatch) {
            const visibleText = row.textContent.toLowerCase();
            isMatch = visibleText.includes(searchTerm.toLowerCase());
          }
        } catch (e) {
          console.error("Error parsing searchable data:", e);
        }
      }

      if (isMatch) {
        row.classList.remove("hidden-by-search");
        row.classList.add("highlight-search");
        matchCount++;
      } else {
        row.classList.add("hidden-by-search");
      }
    });

    // تحديث المعلومات
    if (searchTerm.trim() !== "") {
      resultsCount.textContent = matchCount;
      searchInfo.style.display = "block";

      // إظهار رسالة إذا لم توجد نتائج
      if (matchCount === 0) {
        resultsCount.innerHTML = '<span style="color:#e74c3c">0</span>';
        searchInfo.innerHTML =
          '<span style="color:#e74c3c">لم يتم العثور على نتائج</span>';
      } else {
        searchInfo.innerHTML = `<span style="color:#27ae60">${matchCount}</span> نتيجة`;
      }
    } else {
      searchInfo.style.display = "none";
    }
  }

  // حدث البحث عند الكتابة
  searchInput.addEventListener("input", function (e) {
    const searchTerm = e.target.value.trim();

    // إظهار مؤشر البحث
    const searchIndicator = searchInput.parentNode.querySelector(
      ".searching-indicator",
    );
    if (searchIndicator) {
      searchIndicator.classList.add("active");
    }

    // مسح المهلة السابقة
    clearTimeout(searchTimeout);

    // إضافة تأخير لتحسين الأداء
    searchTimeout = setTimeout(() => {
      performInstantSearch(searchTerm);

      // إخفاء مؤشر البحث بعد الانتهاء
      setTimeout(() => {
        if (searchIndicator) {
          searchIndicator.classList.remove("active");
        }
      }, 300);
    }, 300); // تأخير 300ms لتجنب البحث مع كل ضغطة مفتاح
  });

  // إعادة تعيين البحث عند الضغط على ESC
  searchInput.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      searchInput.value = "";
      performInstantSearch("");
      searchInput.blur();
    }
  });

  // إعادة تعيين البحث عند النقر خارج الحقل (اختياري)
  document.addEventListener("click", function (e) {
    if (!searchInput.contains(e.target) && searchInput.value.trim() === "") {
      searchInfo.style.display = "none";
    }
  });

  // زر إعادة تعيين البحث (إضافة زر اختياري)
  const resetButton = document.createElement("button");
  resetButton.innerHTML = '<i class="fas fa-times"></i>';
  resetButton.style.cssText = `
                position: absolute;
                left: 35px;
                top: 50%;
                transform: translateY(-50%);
                background: none;
                border: none;
                color: #95a5a6;
                cursor: pointer;
                display: none;
            `;

  resetButton.addEventListener("click", function () {
    searchInput.value = "";
    performInstantSearch("");
    searchInput.focus();
  });

  searchInput.parentNode.appendChild(resetButton);

  // إظهار/إخفاء زر الإعادة عند الكتابة
  searchInput.addEventListener("input", function () {
    resetButton.style.display = this.value.trim() ? "block" : "none";
  });
}

// دالة حذف المستند باستخدام POST (محدثة)
function deleteDocument(docId, docTitle = "") {
  // الحصول على عنوان المستند إذا لم يُمرر
  if (!docTitle) {
    const btn = event.target.closest("button");
    const card = btn.closest(".document-card");
    const row = btn.closest("tr");

    if (card) {
      const titleEl = card.querySelector("h3");
      if (titleEl) docTitle = titleEl.textContent.trim();
    } else if (row) {
      const titleEl = row.querySelector(".document-title");
      if (titleEl) docTitle = titleEl.textContent.trim();
    }
  }

  // إنشاء مودال التأكيد
  const modal = document.createElement("div");
  modal.id = "deleteConfirmModal";
  modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(5px);
    `;

  modal.innerHTML = `
        <div style="
            background: white;
            padding: 25px;
            border-radius: 12px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-align: center;
            direction: rtl;
        ">
            <div style="margin-bottom: 20px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #e74c3c;"></i>
            </div>
            <h4 style="color: #2c3e50; margin-bottom: 15px;">تأكيد الحذف</h4>
            <p style="color: #7f8c8d; margin-bottom: 20px;">
                هل أنت متأكد من حذف المستند:<br>
                <strong style="color: #e74c3c;">${docTitle || "غير معروف"}</strong>
            </p>
            <p style="color: #e74c3c; font-size: 0.9rem; margin-bottom: 25px;">
                ⚠️ هذا الإجراء لا يمكن التراجع عنه
            </p>
            <div style="display: flex; gap: 10px;">
                <button onclick="closeDeleteModal()" style="
                    flex: 1;
                    padding: 12px;
                    background: #95a5a6;
                    color: white;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 1rem;
                ">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button onclick="proceedDelete(${docId})" style="
                    flex: 1;
                    padding: 12px;
                    background: #e74c3c;
                    color: white;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 1rem;
                ">
                    <i class="fas fa-trash"></i> حذف
                </button>
            </div>
        </div>
    `;

  document.body.appendChild(modal);
  document.body.style.overflow = "hidden";

  // تعريف دوال الإغلاق والتأكيد داخل النطاق
  window.closeDeleteModal = function () {
    const m = document.getElementById("deleteConfirmModal");
    if (m) {
      m.remove();
      document.body.style.overflow = "auto";
    }
    delete window.closeDeleteModal;
    delete window.proceedDelete;
  };

  window.proceedDelete = function (id) {
    closeDeleteModal();

    const originalBtn =
      event?.target?.closest("button") ||
      document.querySelector(`button[onclick*="deleteDocument(${id})"]`);
    let originalHTML = "";
    let btnElement = originalBtn;

    if (btnElement) {
      originalHTML = btnElement.innerHTML;
      btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      btnElement.disabled = true;
    }

    const formData = new URLSearchParams();
    formData.append("document_id", id);
    formData.append("csrf_token", csrfToken); // <-- إضافة الرمز هنا

    fetch("../documents/delete_document.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: formData.toString(),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showToast("نجاح", "تم حذف المستند بنجاح", "success");
          removeDocumentElement(id);
          updateStatsAfterDelete();
        } else {
          showToast("خطأ", data.message || "حدث خطأ أثناء الحذف", "error");
          if (btnElement) {
            btnElement.innerHTML = originalHTML;
            btnElement.disabled = false;
          }
        }
      })
      .catch((error) => {
        console.error("Error:", error);
        showToast("خطأ", "حدث خطأ في الاتصال بالخادم", "error");
        if (btnElement) {
          btnElement.innerHTML = originalHTML;
          btnElement.disabled = false;
        }
      });
  };
}

// إزالة عنصر المستند من DOM
function removeDocumentElement(docId) {
  // البحث في البطاقات
  const cards = document.querySelectorAll(".document-card");
  for (let card of cards) {
    const btn = card.querySelector(
      `button[onclick*="deleteDocument(${docId})"]`,
    );
    if (btn) {
      card.style.transition = "all 0.3s";
      card.style.opacity = "0";
      card.style.transform = "scale(0.8)";
      setTimeout(() => card.remove(), 300);
      break;
    }
  }

  // البحث في صفوف الجدول
  const rows = document.querySelectorAll(".document-row");
  for (let row of rows) {
    const btn = row.querySelector(
      `button[onclick*="deleteDocument(${docId})"]`,
    );
    if (btn) {
      row.style.transition = "all 0.3s";
      row.style.opacity = "0";
      row.style.transform = "translateX(100px)";
      setTimeout(() => row.remove(), 300);
      break;
    }
  }
}

// تحديث الإحصائيات بعد الحذف
function updateStatsAfterDelete() {
  // تحديث العداد الكلي في رأس الجدول
  const totalSpan = document.querySelector(
    ".documents-header .thired-text div:last-child",
  );
  if (totalSpan) {
    const match = totalSpan.textContent.match(/إجمالي:\s*(\d+)/);
    if (match) {
      const current = parseInt(match[1]);
      const newTotal = current - 1;
      totalSpan.innerHTML = totalSpan.innerHTML.replace(/\d+/, newTotal);
    }
  }

  // تحديث إحصائيات الأزرار الدائرية (الكل، سري، عاجل، عادي)
  // يمكن جلب العدد الجديد من الخادم أو إنقاصه يدوياً
  const allCount = document.querySelector(
    ".circle-filter-btn.all .circle-count",
  );
  if (allCount) {
    allCount.textContent = parseInt(allCount.textContent) - 1;
  }

  // تحديث العداد حسب الأولوية (يمكن تحسينه بمنطق أكثر دقة)
  // سنقوم فقط بإنقاص العدد الكلي للمستندات الظاهرة في الفلاتر
}


// دالة تحديث عداد المستندات
function updateDocumentCount(change) {
  const totalDocsElement = document.querySelector(
    ".stat-card.outgoing .stat-card-number",
  );
  if (totalDocsElement) {
    const currentCount = parseInt(totalDocsElement.textContent) || 0;
    const newCount = Math.max(0, currentCount + change);
    totalDocsElement.textContent = newCount;

    totalDocsElement.style.transform = "scale(1.2)";
    totalDocsElement.style.color = "#e74c3c";
    setTimeout(() => {
      totalDocsElement.style.transform = "scale(1)";
      totalDocsElement.style.color = "";
    }, 300);
  }

  const docCountElement = document.querySelector(".documents-header div span");
  if (docCountElement) {
    const text = docCountElement.textContent;
    const match = text.match(/إجمالي:\s*(\d+)/);
    if (match) {
      const current = parseInt(match[1]);
      const newTotal = Math.max(0, current + change);
      docCountElement.textContent = text.replace(/\d+/, newTotal);
    }
  }
}

// دالة فتح نافذة الأرشيف للديوان - محدثة لعرض ثلاثة مجلدات
function showBoardArchiveModal() {
  const modal = document.createElement("div");
  modal.id = "boardArchiveModal";
  modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                z-index: 9999;
                display: flex;
                align-items: center;
                justify-content: center;
                backdrop-filter: blur(5px);
            `;

  modal.innerHTML = `
                <div style="
                    background: white;
                    border-radius: 15px;
                    width: 95%;
                    max-width: 1200px;
                    max-height: 90vh;
                    overflow: hidden;
                    box-shadow: 0 20px 50px rgba(0,0,0,0.3);
                    position: relative;
                ">
                    <div style="
                        background: linear-gradient(135deg, #164a40 0%, #2d6a5a 100%);
                        color: white;
                        padding: 20px 30px;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                    ">
                        <h3 style="margin: 0; display: flex; align-items: center; gap: 15px;">
                            <i class="fas fa-archive"></i>
                            الأرشيف - المستندات المؤرشفة حسب الأهمية
                        </h3>
                        <button onclick="closeBoardArchiveModal()" style="
                            background: none;
                            border: none;
                            color: white;
                            font-size: 28px;
                            cursor: pointer;
                            padding: 0;
                            width: 40px;
                            height: 40px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            border-radius: 50%;
                            transition: background 0.3s;
                        ">
                            &times;
                        </button>
                    </div>
                    <div id="boardArchiveContent" style="
                        padding: 20px;
                        max-height: calc(90vh - 80px);
                        overflow-y: auto;
                    ">
                        <div style="text-align: center; padding: 40px;">
                            <i class="fas fa-spinner fa-spin" style="font-size: 40px; color: #164a40;"></i>
                            <p style="margin-top: 20px;">جاري تحميل الأرشيف...</p>
                        </div>
                    </div>
                </div>
            `;

  document.body.appendChild(modal);
  document.body.style.overflow = "hidden";

  // تحميل محتوى الأرشيف
  fetch("get_board_archive.php")
    .then((response) => response.json())
    .then((data) => {
      const contentDiv = document.getElementById("boardArchiveContent");
      if (data.success && data.documents.length > 0) {
        displayBoardArchiveContentByPriority(data.documents);
      } else {
        contentDiv.innerHTML = `
                            <div style="text-align: center; padding: 50px;">
                                <i class="fas fa-archive" style="font-size: 40px; color: #7f8c8d;"></i>
                                <p style="margin-top: 20px; color: #7f8c8d;">لا توجد مستندات مؤرشفة</p>
                            </div>
                        `;
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      document.getElementById("boardArchiveContent").innerHTML = `
                        <div style="text-align: center; padding: 50px; color: #e74c3c;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 40px;"></i>
                            <p style="margin-top: 20px;">حدث خطأ في تحميل الأرشيف</p>
                        </div>
                    `;
    });
}

// دالة إغلاق نافذة الأرشيف
function closeBoardArchiveModal() {
  const modal = document.getElementById("boardArchiveModal");
  if (modal) {
    modal.remove();
    document.body.style.overflow = "auto";
  }
}

// دالة عرض محتوى الأرشيف في ثلاثة مجلدات حسب الأولوية
function displayBoardArchiveContentByPriority(documents) {
  const contentDiv = document.getElementById("boardArchiveContent");

  // تصنيف المستندات حسب الأولوية
  const secretDocs = documents.filter((doc) => doc.priority === "urgent");
  const urgentDocs = documents.filter((doc) => doc.priority === "high");
  const normalDocs = documents.filter(
    (doc) =>
      doc.priority === "normal" ||
      doc.priority === "medium" ||
      doc.priority === "low",
  );

  let html = "";

  // مجلد المستندات السرية
  if (secretDocs.length > 0) {
    html += `
                    <div class="archive-folder secret">
                        <div class="archive-folder-header">
                            <div class="folder-icon secret">
                                <i class="fas fa-lock"></i>
                            </div>
                            <div class="folder-info">
                                <h4>المستندات السرية (${secretDocs.length})</h4>
                                <p>المستندات ذات الأولوية العالية جداً</p>
                            </div>
                        </div>
                        <div class="archive-documents-grid">
                `;

    secretDocs.forEach((doc) => {
      html += createArchiveDocumentCard(doc, "secret");
    });

    html += `
                        </div>
                    </div>
                `;
  }

  // مجلد المستندات العاجلة
  if (urgentDocs.length > 0) {
    html += `
                    <div class="archive-folder urgent">
                        <div class="archive-folder-header">
                            <div class="folder-icon urgent">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div class="folder-info">
                                <h4>المستندات العاجلة (${urgentDocs.length})</h4>
                                <p>المستندات التي تحتاج معالجة سريعة</p>
                            </div>
                        </div>
                        <div class="archive-documents-grid">
                `;

    urgentDocs.forEach((doc) => {
      html += createArchiveDocumentCard(doc, "urgent");
    });

    html += `
                        </div>
                    </div>
                `;
  }

  // مجلد المستندات العادية
  if (normalDocs.length > 0) {
    html += `
                    <div class="archive-folder normal">
                        <div class="archive-folder-header">
                            <div class="folder-icon normal">
                                <i class="fas fa-file"></i>
                            </div>
                            <div class="folder-info">
                                <h4>المستندات العادية (${normalDocs.length})</h4>
                                <p>المستندات ذات الأولوية العادية</p>
                            </div>
                        </div>
                        <div class="archive-documents-grid">
                `;

    normalDocs.forEach((doc) => {
      html += createArchiveDocumentCard(doc, "normal");
    });

    html += `
                        </div>
                    </div>
                `;
  }

  // إذا لم توجد مستندات
  if (!secretDocs.length && !urgentDocs.length && !normalDocs.length) {
    html = `
                    <div style="text-align: center; padding: 50px;">
                        <i class="fas fa-archive" style="font-size: 40px; color: #7f8c8d;"></i>
                        <p style="margin-top: 20px; color: #7f8c8d;">لا توجد مستندات مؤرشفة</p>
                    </div>
                `;
  }

  contentDiv.innerHTML = html;
}

// دالة إنشاء بطاقة مستند في الأرشيف
function createArchiveDocumentCard(doc, priorityType) {
  const priorityLabel =
    doc.priority === "urgent"
      ? "سري"
      : doc.priority === "high"
        ? "عاجل"
        : "عادي";

  const folderColors = {
    secret: "#e74c3c",
    urgent: "#f39c12",
    normal: "#3498db",
  };

  const folderColor = folderColors[priorityType];

  return `
                <div class="archive-document-card">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                        <div style="flex: 1;">
                            <strong style="color: #2c3e50; font-size: 0.95rem; display: block; margin-bottom: 5px;">
                                ${doc.title}
                            </strong>
                            <div style="font-size: 0.85rem; color: #7f8c8d; margin-bottom: 3px;">
                                <i class="fas fa-user"></i> ${doc.creator_name}
                            </div>
                            <div style="font-size: 0.85rem; color: #7f8c8d;">
                                <i class="fas fa-calendar"></i> تم الأرشيف: ${doc.archived_at}
                            </div>
                        </div>
                        <span style="
                            background: ${folderColor};
                            color: white;
                            padding: 3px 10px;
                            border-radius: 15px;
                            font-size: 0.8rem;
                            font-weight: bold;
                        ">
                            ${priorityLabel}
                        </span>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 15px;">
                        <button onclick="restoreBoardDocument(${doc.id})" 
                                style="
                                    flex: 1;
                                    background: #2ecc71;
                                    color: white;
                                    border: none;
                                    padding: 8px;
                                    border-radius: 5px;
                                    cursor: pointer;
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    gap: 5px;
                                    font-size: 0.85rem;
                                ">
                            <i class="fas fa-redo"></i> استعادة
                        </button>
                        <button onclick="viewDocumentInArchive(${doc.id})" 
                                style="
                                    flex: 1;
                                    background: #3498db;
                                    color: white;
                                    border: none;
                                    padding: 8px;
                                    border-radius: 5px;
                                    cursor: pointer;
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    gap: 5px;
                                    font-size: 0.85rem;
                                ">
                            <i class="fas fa-eye"></i> عرض
                        </button>
                    </div>
                </div>
            `;
}

// دالة استعادة المستند من الأرشيف للديوان
function restoreBoardDocument(docId) {
  if (!confirm("هل تريد استعادة هذا المستند من الأرشيف؟")) {
    return;
  }

  const btn = event.target;
  const originalHTML = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  btn.disabled = true;

  fetch("restore_board_document.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `document_id=${docId}`,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showToast("نجاح", "تمت استعادة المستند بنجاح", "success");

        // إزالة البطاقة من العرض
        const card = btn.closest(".archive-document-card");
        if (card) {
          card.style.transition = "all 0.3s";
          card.style.opacity = "0.3";
          card.style.transform = "translateX(100px)";
          setTimeout(() => {
            card.remove();
            updateArchiveFolderCounts();
          }, 300);
        }

        // إعادة تحميل الصفحة بعد 2 ثانية
        setTimeout(() => {
          closeBoardArchiveModal();
          location.reload();
        }, 2000);
      } else {
        showToast("خطأ", "حدث خطأ: " + data.message, "error");
        btn.innerHTML = originalHTML;
        btn.disabled = false;
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      showToast("خطأ", "حدث خطأ في الاتصال بالخادم", "error");
      btn.innerHTML = originalHTML;
      btn.disabled = false;
    });
}

// دالة تحديث أعداد المجلدات في الأرشيف
function updateArchiveFolderCounts() {
  // يمكن تنفيذ تحديث ديناميكي للعدادات هنا إذا لزم الأمر
}

// دالة عرض المستند من الأرشيف
function viewDocumentInArchive(docId) {
  window.open(
    `../documents/view_document.php?id=${docId}&from_archive=1`,
    "_blank",
  );
}

// دالة تصدير النتائج
function exportResults() {
  const filters = new URLSearchParams(window.location.search);
  window.location.href = "export_board_data.php?" + filters.toString();
}
// متغيرات الأرشفة
let currentArchiveId = null;
let currentArchivePriority = 'normal';

// دالة فتح مودال التأكيد
function archiveBoardDocument(docId, priority, docTitle) {
    currentArchiveId = docId;
    currentArchivePriority = priority || 'normal';
    document.getElementById('archiveDocumentTitle').innerText = 'المستند: ' + docTitle;
    document.getElementById('archiveConfirmModal').style.display = 'flex';
}

// إغلاق المودال
function closeArchiveConfirmModal() {
    document.getElementById('archiveConfirmModal').style.display = 'none';
    currentArchiveId = null;
}

// تنفيذ الأرشفة بعد التأكيد
function proceedArchive() {
    if (!currentArchiveId) {
        closeArchiveConfirmModal();
        return;
    }

    // إظهار مؤشر التحميل داخل الزر إذا أردت (اختياري)
    const confirmBtn = document.querySelector('#archiveConfirmModal .btnx:first-child');
    const originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الأرشفة...';
    confirmBtn.disabled = true;

    fetch('archive_board_document.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'document_id=' + currentArchiveId + '&priority=' + encodeURIComponent(currentArchivePriority)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('success', data.message);
            // إعادة تحميل الصفحة بعد ثانية ونصف
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('error', data.message);
            // إعادة تمكين الزر
            confirmBtn.innerHTML = originalText;
            confirmBtn.disabled = false;
        }
        closeArchiveConfirmModal();
    })
    .catch(error => {
        showToast('error', 'حدث خطأ في الاتصال بالخادم');
        confirmBtn.innerHTML = originalText;
        confirmBtn.disabled = false;
        closeArchiveConfirmModal();
    });
}

// دالة showToast (إذا لم تكن موجودة)
function showToast(type, message) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}