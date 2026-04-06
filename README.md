# نظام التوقيع الإلكتروني - Electronic Signature System

<div dir="rtl">

## نظرة عامة

نظام التوقيع الإلكتروني هو تطبيق ويب متكامل لإدارة وتوقيع الوثائق إلكترونياً، مصمم لتبسيط وإدارة عمليات التوقيع والموافقة على الوثائق في المؤسسات، مما يقلل من استخدام الورق ويسرع العمليات الإدارية.

## المميزات الرئيسية

- ✅ **نظام توقيع إلكتروني متكامل**: توقيع الوثائق عبر لوحات توقيع تفاعلية
- ✅ **سير عمل متعدد المستويات**: توجيه الوثائق عبر مسارات مراجعة معقدة
- ✅ **إدارة الأدوار والصلاحيات**: 12 دور مختلف مع صلاحيات قابلة للتخصيص
- ✅ **لوحات تحكم متعددة**: لوحة تحكم مخصصة لكل دور
- ✅ **نظام إشعارات فوري**: إشعارات AJAX فورية
- ✅ **أرشفة ذكية**: أرشفة الوثائق حسب الأولوية
- ✅ **أمان عالي**: حماية CSRF, XSS, SQL Injection
- ✅ **توليد PDF**: دعم FPDF و FPDI لتوليد وطباعة PDF
- ✅ **واجهة عربية RTL**: تصميم متجاوب يدعم جميع الأجهزة
- ✅ **نظام PKI**: شهادات رقمية RSA 2048

## المتطلبات

### متطلبات التشغيل
- **PHP**: 8.2+
- **MySQL**: 8.0+
- **Docker**: Docker Compose v2+
- **المتصفحات**: Chrome, Firefox, Safari, Edge (أحدث إصدارين)

### إضافات PHP المطلوبة
- PDO
- PDO_MySQL
- mbstring
- json
- gd
- curl
- zip
- xml

## التثبيت السريع باستخدام Docker

### 1. استنساخ المشروع
```bash
git clone <repository-url>
cd electronic-signature-system
```

### 2. إعداد متغيرات البيئة
```bash
cp .env.example .env
```

قم بتعديل ملف `.env` وتغيير القيم الافتراضية:
```env
DB_PASSWORD=your_secure_password_here
SESSION_SECRET=generate_random_32_chars
ENCRYPTION_KEY=generate_random_encryption_key
```

### 3. تشغيل النظام
```bash
docker-compose up -d
```

### 4. الوصول للنظام
- **التطبيق**: http://localhost:8080
- **phpMyAdmin**: http://localhost:8081

### 5. بيانات الدخول الافتراضية
```
اسم المستخدم: admin
كلمة المرور: admin123
```

> ⚠️ **مهم**: قم بتغيير كلمة المرور الافتراضية فوراً بعد أول تسجيل دخول

## بنية المشروع

```
electronic-signature-system/
├── admin/                    # ملفات الإدارة
│   ├── manage_users.php      # إدارة المستخدمين
│   ├── manage_roles.php      # إدارة الأدوار
│   ├── manage_departments.php # إدارة الأقسام
│   ├── logs.php              # سجل النشاطات
│   ├── system_settings.php   # إعدادات النظام
│   └── organization_chart.php # المخطط التنظيمي
├── assets/                   # الملفات الثابتة
│   ├── css/                  # ملفات CSS
│   ├── js/                   # ملفات JavaScript
│   ├── audio/                # ملفات الصوت
│   └── fontawesome/          # مكتبة Font Awesome
├── dashboard/                # لوحات التحكم
│   ├── dashboard_admin.php           # لوحة المسؤول
│   ├── ceo_dashboard.php             # لوحة الرئيس التنفيذي
│   ├── department_manager_dashboard.php # لوحة رئيس الدائرة
│   ├── section_manager_dashboard.php # لوحة رئيس القسم
│   ├── employee_dashboard.php        # لوحة الموظف
│   ├── board_dashboard.php           # لوحة الديوان
│   ├── pboard_dashboard.php          # لوحة الديوان الفرعي
│   ├── sboard_dashboard.php          # لوحة كوظفي الديوان
│   ├── deputy_ceo_dashboard.php      # لوحة نائب الرئيس
│   └── office_mgr_dashboard.php      # لوحة مدير المكتب
├── documents/                # إدارة الوثائق
│   ├── document_upload.php   # رفع وثيقة
│   ├── view_document.php     # عرض وثيقة
│   ├── edit_document.php     # تعديل وثيقة
│   ├── print_document.php    # طباعة/PDF
│   ├── attach_file.php       # إرفاق ملف
│   └── send_reminder.php     # إرسال تذكير
├── includes/                 # الملفات المساعدة
│   ├── auto_security.php     # نظام الحماية التلقائي
│   ├── database.php          # الاتصال بقاعدة البيانات
│   ├── functions.php         # الدوال المساعدة
│   ├── auth.php              # المصادقة
│   ├── csrf.php              # حماية CSRF
│   └── brute_force.php       # حماية Brute Force
├── fpdf/                     # مكتبة FPDF
├── fpdi/                     # مكتبة FPDI
├── uploads/                  # الملفات المرفوعة
├── logs/                     # سجلات النظام
├── images/                   # الصور
├── login.php                 # صفحة تسجيل الدخول
├── logout.php                # تسجيل الخروج
├── index.php                 # الصفحة الرئيسية
├── manage_certificates.php   # إدارة الشهادات الرقمية
├── docker-compose.yml        # إعدادات Docker
├── .env.example              # نموذج متغيرات البيئة
└── electronic_signature.sql  # ملف قاعدة البيانات
```

## الأدوار والصلاحيات

### الأدوار المتاحة

| الدور | الوصف |
|-------|-------|
| admin | مسؤول النظام - تحكم كامل |
| ceo | الرئيس التنفيذي - أعلى سلطة |
| deputy_ceo | نائب الرئيس التنفيذي |
| office_manager | مدير المكتب |
| department_manager | رئيس الدائرة |
| section_manager | رئيس القسم |
| employee | موظف عادي |
| board | الديوان |
| private_board | ديوان فرعي |
| sub_board | كوظفي الديوان العام |

### الصلاحيات المتاحة

| الصلاحية | الوصف |
|----------|-------|
| create_document | إنشاء وثيقة |
| view_document | عرض وثيقة |
| edit_document | تعديل وثيقة |
| delete_document | حذف وثيقة |
| sign_document | توقيع وثيقة |
| approve_document | اعتماد وثيقة |
| reject_document | رفض وثيقة |
| manage_users | إدارة المستخدمين |
| manage_roles | إدارة الأدوار |
| manage_departments | إدارة الأقسام |
| view_reports | عرض التقارير |

## سير العمل (Workflow)

### حالات الوثيقة

```
draft → under_review → pending → partially_signed → completed → archived
                                    ↓
                              rejected
```

| الحالة | الوصف |
|--------|-------|
| draft | مسودة - الوثيقة في مرحلة الإنشاء |
| under_review | قيد المراجعة |
| pending | معلق - في انتظار إجراء |
| partially_signed | موقّع جزئياً |
| completed | مكتمل |
| approved | معتمد |
| rejected | مرفوض |
| archived | مؤرشف |

### أنواع خطوات سير العمل

| النوع | الوصف |
|-------|-------|
| signature | توقيع |
| review | مراجعة |
| approval | اعتماد |
| note | ملاحظة |

## أنواع الحقول التفاعلية

| النوع | الوصف |
|-------|-------|
| signature | لوح توقيع إلكتروني |
| text | حقل نصي |
| date | حقل تاريخ |
| note | حقل ملاحظات |
| checkbox | خانة اختيار |
| initial | توقيع مختصر |
| image | صورة مرفوعة |

## نظام الأمان

### الحماية المطبقة

- **CSRF Protection**: حماية من هجمات CSRF
- **XSS Filter**: حماية من هجمات XSS
- **SQL Injection Prevention**: حماية من حقن SQL عبر PDO
- **Password Hashing**: تشفير كلمات المرور بـ bcrypt
- **Session Security**: جلسات آمنة مع انتهاء صلاحية 30 دقيقة
- **Brute Force Protection**: حماية من هجمات القوة الغاشمة
- **HTTP Security Headers**: رؤوس HTTP أمنية
- **Auto Sanitization**: تصفية تلقائية للمدخلات

### إعدادات الأمان

راجع الملفات التالية لمزيد من التفاصيل:
- [`SECURITY_README.md`](SECURITY_README.md) - دليل نظام الحماية
- [`SECURITY_SETUP.md`](SECURITY_SETUP.md) - دليل الإعداد الأمني
- [`includes/auto_security.php`](includes/auto_security.php) - نظام الحماية التلقائي

## قاعدة البيانات

### الجداول الرئيسية

| الجدول | الوصف |
|--------|-------|
| users | بيانات المستخدمين |
| roles | الأدوار والصلاحيات |
| departments | الأقسام |
| documents | الوثائق |
| document_fields | حقول الوثيقة التفاعلية |
| document_workflow | سير عمل الوثيقة |
| signatures | التوقيعات |
| notifications | الإشعارات |
| user_activity_logs | سجلات النشاط |
| document_attachments | مرفقات الوثائق |
| document_comments | تعليقات الوثائق |
| document_versions | إصدارات الوثائق |
| field_values | قيم الحقول |
| login_attempts | محاولات تسجيل الدخول |
| user_sessions | جلسات المستخدمين |

## الاستخدام

### رفع وثيقة جديدة

1. تسجيل الدخول بالنظام
2. الانتقال إلى صفحة رفع الوثائق
3. اختيار الملف (PDF, DOC, DOCX, XLS, XLSX, JPG, PNG)
4. إضافة العنوان والوصف
5. تحديد الأولوية والأقسام
6. إضافة الحقول التفاعلية (سحب وإفلات)
7. تعيين المستخدمين للحقول
8. حفظ الوثيقة

### توقيع وثيقة

1. فتح الوثيقة من لوحة التحكم
2. النقر على حقل التوقيع المخصص
3. التوقيع باستخدام الماوس أو اللمس
4. حفظ التوقيع
5. توجيه الوثيقة للخطوة التالية

### إدارة المستخدمين

1. الانتقال إلى صفحة إدارة المستخدمين (للمسؤول فقط)
2. إضافة مستخدم جديد أو تعديل موجود
3. تعيين الدور والقسم والمشرف
4. تفعيل/تعطيل الحساب

## استكشاف الأخطاء

### المشكلة: لا يمكن الاتصال بقاعدة البيانات
**الحل**: تأكد من تشغيل حاوية MySQL ومراجعة متغيرات البيئة في ملف `.env`

### المشكلة: خطأ في رفع الملفات
**الحل**: تحقق من صلاحيات مجلد `uploads/` وحجم الملف المسموح

### المشكلة: لا تظهر الإشعارات
**الحل**: تأكد من تفعيل JavaScript في المتصفح ومراجعة اتصال AJAX

### المشكلة: خطأ في توليد PDF
**الحل**: تحقق من وجود مكتبات FPDF و FPDI في المسارات الصحيحة

## النسخ الاحتياطي

### نسخ قاعدة البيانات
```bash
docker-compose exec mysql mysqldump -u app_user -p electronic_signature > backup.sql
```

### استعادة النسخة الاحتياطية
```bash
docker-compose exec -T mysql mysql -u app_user -p electronic_signature < backup.sql
```

## التحديثات

### تحديث النظام
```bash
git pull origin main
docker-compose down
docker-compose up -d --build
```

## السجلات

### موقع السجلات
- سجلات PHP: `logs/`
- سجلات النشاط: جدول `user_activity_logs`
- سجلات تسجيل الدخول: جدول `login_attempts`

### عرض السجلات
يمكن عرض السجلات من خلال:
- صفحة [`admin/logs.php`](admin/logs.php)
- تصدير السجلات من [`dashboard/export_logs.php`](dashboard/export_logs.php)

## الدعم

للحصول على الدعم أو الإبلاغ عن مشكلة:
- فتح issue على GitHub
- مراجعة [`PROJECT_DOCUMENTATION.md`](PROJECT_DOCUMENTATION.md)
- مراجعة [`SRS_Electronic_Signature_System.md`](SRS_Electronic_Signature_System.md)

## الترخيص

هذا المشروع مرخص تحت رخصة MIT.

## الاعتمادات

- **FPDF**: مكتبة توليد PDF
- **FPDI**: مكتبة معالجة PDF
- **Font Awesome**: مكتبة الأيقونات
- **Google Fonts**: خط Cairo
- **PHP**: لغة البرمجة
- **MySQL**: قاعدة البيانات
- **Docker**: بيئة التشغيل

---

**آخر تحديث**: 2026-04-06
**الإصدار**: 1.0

</div>
