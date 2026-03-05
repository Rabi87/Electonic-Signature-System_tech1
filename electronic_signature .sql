-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: mysql:3306
-- Generation Time: Feb 28, 2026 at 05:25 AM
-- Server version: 8.0.43
-- PHP Version: 8.2.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `electronic_signature`
--

-- --------------------------------------------------------

--
-- Table structure for table `archive_logs`
--

CREATE TABLE `archive_logs` (
  `id` int NOT NULL,
  `document_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` enum('archive','restore') NOT NULL,
  `old_path` varchar(500) DEFAULT NULL,
  `new_path` varchar(500) DEFAULT NULL,
  `priority` varchar(50) DEFAULT NULL,
  `archived_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `manager_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `description`, `manager_id`, `created_at`) VALUES
(1, 'الإدارة العامة', 'القسم الرئيسي للإدارة', NULL, '2026-01-18 07:14:09'),
(2, 'التنمية الإدارية', '', NULL, '2026-01-18 11:24:40'),
(3, 'المعلوماتية', '', NULL, '2026-01-18 18:07:44'),
(4, 'الإداري', 'القسم الإداري', NULL, '2026-02-10 11:05:46');

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `template_id` int DEFAULT NULL,
  `has_interactive_fields` tinyint(1) DEFAULT '0',
  `field_count` int DEFAULT '0',
  `file_size` int DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `current_status` enum('draft','under_review','pending','completion_required','partially_signed','partially_completed','completed','responded','approved','rejected','archived','side_track') DEFAULT NULL,
  `current_holder_id` int DEFAULT NULL,
  `priority` enum('high','urgent','normal') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'normal',
  `owner_department_id` int DEFAULT NULL,
  `current_department_id` int DEFAULT NULL,
  `archived` tinyint(1) DEFAULT '0',
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Triggers `documents`
--
DELIMITER $$
CREATE TRIGGER `log_status_change` AFTER UPDATE ON `documents` FOR EACH ROW BEGIN
    IF OLD.current_status != NEW.current_status THEN
        INSERT INTO document_status_history 
        (document_id, old_status, new_status, changed_by, changed_at)
        VALUES (NEW.id, OLD.current_status, NEW.current_status, 
                NEW.current_holder_id, NOW());
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `document_attachments`
--

CREATE TABLE `document_attachments` (
  `id` int NOT NULL,
  `document_id` int NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` int DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `uploaded_by` int NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_comments`
--

CREATE TABLE `document_comments` (
  `id` int NOT NULL,
  `document_id` int NOT NULL,
  `user_id` int NOT NULL,
  `comment` text NOT NULL,
  `comment_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_internal` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_fields`
--

CREATE TABLE `document_fields` (
  `id` int NOT NULL,
  `document_id` int NOT NULL,
  `field_type` enum('signature','text','date','note','checkbox','initial','image') NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `field_key` varchar(100) DEFAULT NULL,
  `default_value` text,
  `x_position` int NOT NULL,
  `y_position` int NOT NULL,
  `width` int NOT NULL,
  `height` int NOT NULL,
  `page_number` int NOT NULL DEFAULT '1',
  `assigned_to` int DEFAULT NULL,
  `assigned_name` varchar(255) DEFAULT NULL,
  `field_order` int DEFAULT NULL,
  `required` tinyint(1) DEFAULT '1',
  `status` enum('pending','completed','rejected') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `metadata` json DEFAULT NULL,
  `is_side_track` tinyint(1) DEFAULT '0',
  `x_percent` decimal(5,2) DEFAULT '0.00',
  `y_percent` decimal(5,2) DEFAULT '0.00',
  `width_percent` decimal(5,2) DEFAULT '10.00',
  `height_percent` decimal(5,2) DEFAULT '5.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_status_history`
--

CREATE TABLE `document_status_history` (
  `id` int NOT NULL,
  `document_id` int NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `changed_by` int NOT NULL,
  `change_reason` text,
  `changed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_templates`
--

CREATE TABLE `document_templates` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `file_path` varchar(500) NOT NULL,
  `created_by` int NOT NULL,
  `department_id` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_user_status`
--

CREATE TABLE `document_user_status` (
  `id` int NOT NULL,
  `document_id` int NOT NULL,
  `user_id` int NOT NULL,
  `status` enum('pending','completion_required','partially_signed','partially_completed','completed','responded','approved','rejected','signed','side_track_pending','side_track_approved','side_track_rejected','side_track_sent','draft') DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `is_owner_department` tinyint(1) DEFAULT '1',
  `action_required` enum('approve','reject','forward','complete','review') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `notes` text,
  `is_side_track` tinyint(1) DEFAULT '0',
  `return_to_user` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_versions`
--

CREATE TABLE `document_versions` (
  `id` int NOT NULL,
  `document_id` int NOT NULL,
  `version_number` int NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `created_by` int NOT NULL,
  `change_description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_workflow`
--

CREATE TABLE `document_workflow` (
  `id` int NOT NULL,
  `document_id` int NOT NULL,
  `from_user_id` int DEFAULT NULL,
  `to_user_id` int DEFAULT NULL,
  `action_type` varchar(100) NOT NULL,
  `action_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text,
  `status_before` varchar(50) DEFAULT NULL,
  `status_after` varchar(50) DEFAULT NULL,
  `is_current_step` tinyint(1) DEFAULT '0',
  `is_side_track` tinyint(1) DEFAULT '0',
  `return_to_user` int DEFAULT NULL,
  `branch_direction` varchar(10) DEFAULT 'top',
  `branch_from_user_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_workflow_steps`
--

CREATE TABLE `document_workflow_steps` (
  `id` int NOT NULL,
  `document_id` int NOT NULL,
  `step_order` int NOT NULL,
  `assigned_to` int NOT NULL,
  `step_type` enum('signature','review','approval','note') DEFAULT 'signature',
  `required` tinyint(1) DEFAULT '1',
  `completed` tinyint(1) DEFAULT '0',
  `completed_by` int DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `field_images`
--

CREATE TABLE `field_images` (
  `id` int NOT NULL,
  `field_id` int NOT NULL,
  `document_id` int NOT NULL,
  `user_id` int NOT NULL,
  `image_path` varchar(500) NOT NULL,
  `image_name` varchar(255) NOT NULL,
  `image_type` varchar(50) DEFAULT NULL,
  `image_size` int DEFAULT NULL,
  `width` int DEFAULT NULL,
  `height` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `field_settings`
--

CREATE TABLE `field_settings` (
  `id` int NOT NULL,
  `field_type` varchar(50) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `field_values`
--

CREATE TABLE `field_values` (
  `id` int NOT NULL,
  `field_id` int NOT NULL,
  `user_id` int NOT NULL,
  `field_type` enum('signature','text','date','note','checkbox','initial','image') DEFAULT NULL,
  `value_type` varchar(50) NOT NULL,
  `value_data` mediumtext,
  `image_path` varchar(500) DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `groups`
--

CREATE TABLE `groups` (
  `id` int NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `success` tinyint(1) DEFAULT '0',
  `attempt_time` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `sender_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int NOT NULL,
  `permission_name` varchar(50) NOT NULL,
  `description` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `permission_name`, `description`) VALUES
(1, 'create_document', 'إنشاء مستند جديد'),
(2, 'view_document', 'عرض المستندات'),
(3, 'edit_document', 'تعديل المستندات'),
(4, 'delete_document', 'حذف المستندات'),
(5, 'sign_document', 'توقيع المستندات'),
(6, 'approve_document', 'الموافقة على المستندات'),
(7, 'reject_document', 'رفض المستندات'),
(8, 'manage_users', 'إدارة المستخدمين'),
(9, 'manage_roles', 'إدارة الأدوار'),
(10, 'manage_departments', 'إدارة الأقسام'),
(11, 'view_reports', 'عرض التقارير');

-- --------------------------------------------------------

--
-- Table structure for table `remember_me_tokens`
--

CREATE TABLE `remember_me_tokens` (
  `id` int NOT NULL,
  `selector` char(32) NOT NULL,
  `hashed_validator` char(64) NOT NULL,
  `user_id` int NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` text,
  `parent_role_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_name`, `description`, `parent_role_id`, `created_at`) VALUES
(1, 'admin', 'مسؤول النظام - يتحكم بكل شيء', NULL, '2026-01-18 07:14:09'),
(2, 'ceo', 'الرئيس التنفيذي - أعلى سلطة', NULL, '2026-01-18 07:14:09'),
(3, 'department_manager', 'رئيس الدائرة', NULL, '2026-01-18 07:14:09'),
(4, 'section_manager', 'رئيس القسم', NULL, '2026-01-18 07:14:09'),
(5, 'employee', 'موظف عادي', NULL, '2026-01-18 07:14:09'),
(6, 'board', '', NULL, '2026-01-18 12:50:54'),
(9, 'private_board', 'ديوان فرعي خاص بالاقسام', NULL, '2026-02-18 19:30:21'),
(10, 'sub_board', 'كوظفي الديوان العام', NULL, '2026-02-18 19:31:42'),
(11, 'deputy_ceo', 'نائب الرئيس التنفيذي', NULL, '2026-02-25 10:13:03'),
(12, 'office_manager', 'مدير المكتب', NULL, '2026-02-27 13:07:35');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int NOT NULL,
  `permission_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `signatures`
--

CREATE TABLE `signatures` (
  `id` int NOT NULL,
  `document_id` int NOT NULL,
  `field_id` int DEFAULT NULL,
  `user_id` int NOT NULL,
  `signature_data` text NOT NULL,
  `signed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `signature_order` int DEFAULT NULL,
  `is_final` tinyint(1) DEFAULT '0',
  `signature_type` enum('workflow','interactive') DEFAULT 'workflow',
  `is_interactive` tinyint(1) DEFAULT '0',
  `field_data` json DEFAULT NULL,
  `page_number` int DEFAULT NULL,
  `x_position` int DEFAULT NULL,
  `y_position` int DEFAULT NULL,
  `signature_image` longblob
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `setting_group` varchar(50) DEFAULT 'general',
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_group`, `description`, `created_at`, `updated_at`) VALUES
(31, 'site_name', 'الشركة السورية للبترول SPC', 'general', 'اسم النظام', '2026-01-18 18:26:05', '2026-02-04 12:35:54'),
(32, 'site_description', 'نظام إدارة وتوقيع المستندات', 'general', 'وصف النظام', '2026-01-18 18:26:05', '2026-02-04 12:35:54'),
(33, 'site_url', 'http://localhost/signature_system', 'general', 'رابط النظام', '2026-01-18 18:26:05', '2026-02-04 12:35:54'),
(34, 'contact_email', 'admin@example.com', 'general', 'البريد الإلكتروني للاتصال', '2026-01-18 18:26:05', '2026-02-04 12:35:54'),
(35, 'timezone', 'Asia/Damascus', 'general', 'المنطقة الزمنية', '2026-01-18 18:26:05', '2026-02-04 12:35:54'),
(36, 'smtp_host', 'smtp.gmail.com', 'email', 'خادم SMTP', '2026-01-18 18:26:05', '2026-02-04 12:35:54'),
(37, 'smtp_port', '587', 'email', 'منفذ SMTP', '2026-01-18 18:26:05', '2026-02-04 12:35:54'),
(38, 'smtp_username', '', 'email', 'اسم مستخدم SMTP', '2026-01-18 18:26:05', '2026-02-04 12:35:54'),
(39, 'smtp_password', '', 'email', 'كلمة مرور SMTP', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(40, 'smtp_encryption', 'tls', 'email', 'نوع التشفير', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(41, 'from_email', 'noreply@example.com', 'email', 'البريد المرسل منه', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(42, 'from_name', 'نظام التوقيع الإلكتروني', 'email', 'اسم المرسل', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(43, 'password_min_length', '6', 'security', 'الحد الأدنى لطول كلمة المرور', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(44, 'password_require_numbers', '1', 'security', 'طلب أرقام في كلمة المرور', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(45, 'login_attempts', '2', 'security', 'عدد محاولات تسجيل الدخول المسموحة', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(46, 'session_timeout', '5', 'security', 'مدة انتهاء الجلسة (بالدقائق)', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(47, 'require_2fa', '0', 'security', 'تطلب المصادقة الثنائية', '2026-01-18 18:26:06', '2026-01-18 18:26:06'),
(48, 'max_file_size', '10', 'document', 'الحد الأقصى لحجم الملف (بايت)', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(49, 'allowed_file_types', 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'document', 'صيغ الملفات المسموحة', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(50, 'default_priority', 'medium', 'document', 'الأولية الافتراضية', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(51, 'auto_archive_days', '30', 'document', 'الأيام التلقائية للأرشفة', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(52, 'enable_comments', '1', 'document', 'تفعيل التعليقات', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(53, 'theme_color', '#8ae234', 'appearance', 'لون السمة الرئيسي', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(54, 'logo_url', '../assets/images/logo.png', 'appearance', 'رابط الشعار', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(55, 'favicon_url', '../assets/images/favicon.ico', 'appearance', 'رابط الأيقونة', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(56, 'rtl_enabled', '1', 'appearance', 'تفعيل اتجاه اليمين لليسار', '2026-01-18 18:26:06', '2026-02-04 12:35:54'),
(57, 'password_require_uppercase', '0', 'security', 'طلب أحرف كبيرة في كلمة المرور', '2026-02-04 12:31:57', '2026-02-04 12:31:57'),
(58, 'password_require_special', '0', 'security', 'طلب رموز خاصة في كلمة المرور', '2026-02-04 12:31:57', '2026-02-04 12:31:57'),
(59, 'enable_reminders', '0', 'document', 'تفعيل التذكيرات التلقائية', '2026-02-04 12:31:57', '2026-02-04 12:31:57'),
(60, 'enable_versioning', '0', 'document', 'تفعيل إصدارات المستندات', '2026-02-04 12:31:57', '2026-02-04 12:31:57'),
(61, 'background_color', '#2e3436', 'appearance', 'لون الخلفية', '2026-02-04 12:31:57', '2026-02-04 12:35:54'),
(62, 'heading_font', 'Cairo', 'appearance', 'خط العنوان', '2026-02-04 12:31:57', '2026-02-04 12:35:54'),
(63, 'body_font', 'Cairo', 'appearance', 'خط المحتوى', '2026-02-04 12:31:57', '2026-02-04 12:35:54'),
(64, 'maintenance_mode', '0', 'maintenance', 'تفعيل وضع الصيانة', '2026-02-04 12:31:57', '2026-02-04 12:31:57'),
(65, 'maintenance_message', 'النظام قيد الصيانة حالياً. سنعود قريباً.', 'maintenance', 'رسالة وضع الصيانة', '2026-02-04 12:31:57', '2026-02-04 12:35:54'),
(66, 'error_logging', '1', 'maintenance', 'تفعيل تسجيل الأخطاء', '2026-02-04 12:31:57', '2026-02-04 12:35:54'),
(67, 'auto_backup', '0', 'maintenance', 'تفعيل النسخ الاحتياطي التلقائي', '2026-02-04 12:31:57', '2026-02-04 12:31:57'),
(68, 'log_retention_days', '90', 'maintenance', 'فترة احتفاظ السجلات (أيام)', '2026-02-04 12:31:57', '2026-02-04 12:35:54');

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `assigned_to` int NOT NULL,
  `assigned_by` int NOT NULL,
  `department_id` int DEFAULT NULL,
  `task_type` enum('document','review','followup','other') DEFAULT 'document',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `due_date` date DEFAULT NULL,
  `template_id` int DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `task_attachments`
--

CREATE TABLE `task_attachments` (
  `id` int NOT NULL,
  `task_id` int NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `task_comments`
--

CREATE TABLE `task_comments` (
  `id` int NOT NULL,
  `task_id` int NOT NULL,
  `user_id` int NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `template_fields`
--

CREATE TABLE `template_fields` (
  `id` int NOT NULL,
  `template_id` int NOT NULL,
  `field_type` enum('signature','text','date','note','checkbox','initial','image') NOT NULL,
  `label` varchar(255) NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `default_value` text,
  `page_number` int NOT NULL DEFAULT '1',
  `x_position` int NOT NULL,
  `y_position` int NOT NULL,
  `width` int NOT NULL,
  `height` int NOT NULL,
  `required` tinyint(1) DEFAULT '1',
  `assigned_role` varchar(50) DEFAULT NULL,
  `field_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'test@test.com',
  `full_name` varchar(100) NOT NULL,
  `role_id` int NOT NULL,
  `department_id` int DEFAULT NULL,
  `supervisor_id` int DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `site` varchar(255) NOT NULL DEFAULT 'الإدارة العامة',
  `title` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `full_name`, `role_id`, `department_id`, `supervisor_id`, `is_active`, `created_at`, `last_login`, `site`, `title`) VALUES
(1, 'admin', '$2y$10$qrNne.EtpeKGznAD9ubbvekZKerKQN3YLkWYa/QXczn5RHI5Jttpu', 'admin@example.com', 'المسؤول العام', 1, 1, NULL, 1, '2026-01-18 07:14:09', '2026-02-28 05:16:22', 'الإدارة العامة', NULL),
(6, 'fadi', '$2y$10$d/eaUdn8cveKR/oNuoO3QeL8.dnT0KC0KPBNDPgnjpsFmbu4tMZ02', 's@gmail.com', 'فادي قاسم', 6, 1, NULL, 1, '2026-01-18 17:51:46', '2026-02-27 11:52:46', 'الإدارة المركزية', 'رئيس الديوان'),
(9, 'ceo', '$2y$10$KSTbl6ZKbfVhSyijIhkgBOCwoFgMaFOpulja95Y44CeZ7wv5gscnO', 'y@gmail.com', 'الرئيس التنفيذي', 2, 1, 6, 1, '2026-01-18 18:10:18', '2026-02-27 12:07:35', 'الإدارة العامة', NULL),
(29, 'shadi', '$2y$10$63kHNKz0XWooP6SJKEk4b.7jqEIfpsERpxvgQ4KrugOecQ1TE.NmW', 'shadi@example.com', 'شادي الألحان', 9, 3, NULL, 1, '2026-02-21 09:57:55', '2026-02-28 03:34:06', 'الإدارة المركزية', 'ديوان المعلوماتية'),
(30, 'radi', '$2y$10$4jF73GIVqb4IcJ3/aCnBAOBbWJyM6p4Tu47yfmqi/thTWQ6vAKUWK', 'radi@example.com', 'رضا الرحمن', 9, 2, NULL, 1, '2026-02-21 09:59:20', NULL, 'الإدارة المركزية', 'ديوان التنمية'),
(31, 'hadi', '$2y$10$bKDeuT2Zu5ff4RBrK8zlMOkn5ofRGt97SclS2aRNASv1T8RBu46ii', 'hadi@example.com', 'هادي العقل', 3, 3, 29, 1, '2026-02-21 10:02:50', '2026-02-27 11:52:38', 'الإدارة المركزية', 'رئيس الدائرة'),
(32, 'ramadan', '$2y$10$YrhRGP3.kmQfShaGheYrEewxRdx/gTfguJXBmJaLXYS7ZSF58Stny', 'ramadan@example.com', 'رمضان كريم', 4, 3, 29, 1, '2026-02-21 10:04:49', '2026-02-27 11:50:45', 'الإدارة المركزية', 'قسم الشبكات'),
(33, 'eid', '$2y$10$sAM0HOQVyRUxYrgyyVo52upUD//SSo9X1JRRaj9YVSEhVycK8uvpq', 'eid@example.com', 'عيد سعيد', 4, 3, 29, 1, '2026-02-21 10:07:00', '2026-02-26 10:35:05', 'الإدارة المركزية', 'قسم البرمجة'),
(34, 'yahya', '$2y$10$0RRMG9R7BCuWhXmPSzVA3e1ldkqNCcLUJTi2JbJpTw/o4jdOkn1ZW', 'yahya@example.com', 'يحيى العدل', 5, 3, 29, 1, '2026-02-21 10:12:39', '2026-02-27 11:49:32', 'الإدارة المركزية', 'دعم فني'),
(35, 'milad', '$2y$10$dvLN4Kt1k69M9SYCcB8y3u8BaX7MGZEwTJS5DboqxTv/MwSVxfMmK', 'milad@example.com', 'ميلاد مجيد', 5, 3, 29, 1, '2026-02-21 10:16:11', '2026-02-21 10:19:32', 'الإدارة المركزية', 'محلل النظم'),
(36, 'siraj', '$2y$10$wKt4b7UzIRu9Pq5ggqLC7.YnLCBafYiOiK.tuSuAmBUzTQcZM2i4i', 'siraj@example.com', 'سراج منير', 11, 1, 6, 1, '2026-02-25 09:57:05', '2026-02-27 12:05:11', 'الإدارة المركزية', 'نائب الرئيس التنفيذي'),
(37, 'sami', '$2y$10$LLI4Ah6p6AVX0Hp.zuTztuMNl4mtGHfkKyl9mZTt87K.YfBjJ6fFa', 'sami@example.com', 'سامي نص لسان', 12, 1, 36, 1, '2026-02-27 13:10:07', NULL, 'الإدارة المركزية', 'مدير مكتب ناب المدير');

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_logs`
--

CREATE TABLE `user_activity_logs` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `document_id` int DEFAULT NULL,
  `additional_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_logs`
--

CREATE TABLE `user_logs` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `document_id` int DEFAULT NULL,
  `details` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_push_subscriptions`
--

CREATE TABLE `user_push_subscriptions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `endpoint` varchar(500) NOT NULL,
  `p256dh` text NOT NULL,
  `auth` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `last_activity` datetime DEFAULT CURRENT_TIMESTAMP,
  `device_info` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `archive_logs`
--
ALTER TABLE `archive_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_document_id` (`document_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `manager_id` (`manager_id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `current_holder_id` (`current_holder_id`),
  ADD KEY `fk_documents_template` (`template_id`),
  ADD KEY `owner_department_id` (`owner_department_id`),
  ADD KEY `current_department_id` (`current_department_id`);

--
-- Indexes for table `document_attachments`
--
ALTER TABLE `document_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `document_comments`
--
ALTER TABLE `document_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `document_fields`
--
ALTER TABLE `document_fields`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_document_field_key` (`document_id`,`field_key`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_doc_fields_doc_type` (`document_id`,`field_type`);

--
-- Indexes for table `document_status_history`
--
ALTER TABLE `document_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `document_templates`
--
ALTER TABLE `document_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `document_user_status`
--
ALTER TABLE `document_user_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_doc_user` (`document_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_action_required` (`action_required`),
  ADD KEY `idx_doc_user_composite` (`document_id`,`user_id`,`status`),
  ADD KEY `idx_is_owner_department` (`is_owner_department`);

--
-- Indexes for table `document_versions`
--
ALTER TABLE `document_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_doc_version` (`document_id`,`version_number`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `document_workflow`
--
ALTER TABLE `document_workflow`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `from_user_id` (`from_user_id`),
  ADD KEY `to_user_id` (`to_user_id`);

--
-- Indexes for table `document_workflow_steps`
--
ALTER TABLE `document_workflow_steps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `completed_by` (`completed_by`);

--
-- Indexes for table `field_images`
--
ALTER TABLE `field_images`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_field_image` (`field_id`),
  ADD KEY `field_id` (`field_id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `field_settings`
--
ALTER TABLE `field_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_field_setting` (`field_type`,`setting_key`);

--
-- Indexes for table `field_values`
--
ALTER TABLE `field_values`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_unique_field_user` (`field_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_value_type` (`value_type`),
  ADD KEY `idx_signed_at` (`signed_at`),
  ADD KEY `idx_field_values_field_id` (`field_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempt_time`),
  ADD KEY `idx_time` (`attempt_time`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user` (`user_id`),
  ADD KEY `idx_notifications_sender` (`sender_id`),
  ADD KEY `idx_notifications_read` (`is_read`),
  ADD KEY `idx_notifications_created` (`created_at` DESC);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permission_name` (`permission_name`);

--
-- Indexes for table `remember_me_tokens`
--
ALTER TABLE `remember_me_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `selector` (`selector`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_selector` (`selector`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`),
  ADD KEY `parent_role_id` (`parent_role_id`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `signatures`
--
ALTER TABLE `signatures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `field_id` (`field_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `assigned_by` (`assigned_by`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `template_id` (`template_id`);

--
-- Indexes for table `task_attachments`
--
ALTER TABLE `task_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `task_comments`
--
ALTER TABLE `task_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `template_fields`
--
ALTER TABLE `template_fields`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_template_field_key` (`template_id`,`field_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `supervisor_id` (`supervisor_id`);

--
-- Indexes for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_document_id` (`document_id`);

--
-- Indexes for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_document_id` (`document_id`),
  ADD KEY `idx_action` (`action`);

--
-- Indexes for table `user_push_subscriptions`
--
ALTER TABLE `user_push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_endpoint` (`user_id`,`endpoint`(200));

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_last_activity` (`last_activity`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `archive_logs`
--
ALTER TABLE `archive_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=490;

--
-- AUTO_INCREMENT for table `document_attachments`
--
ALTER TABLE `document_attachments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `document_comments`
--
ALTER TABLE `document_comments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_fields`
--
ALTER TABLE `document_fields`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2668;

--
-- AUTO_INCREMENT for table `document_status_history`
--
ALTER TABLE `document_status_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=651;

--
-- AUTO_INCREMENT for table `document_templates`
--
ALTER TABLE `document_templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_user_status`
--
ALTER TABLE `document_user_status`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1679;

--
-- AUTO_INCREMENT for table `document_versions`
--
ALTER TABLE `document_versions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_workflow`
--
ALTER TABLE `document_workflow`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3781;

--
-- AUTO_INCREMENT for table `document_workflow_steps`
--
ALTER TABLE `document_workflow_steps`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `field_images`
--
ALTER TABLE `field_images`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `field_settings`
--
ALTER TABLE `field_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `field_values`
--
ALTER TABLE `field_values`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2165;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1489;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `remember_me_tokens`
--
ALTER TABLE `remember_me_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `signatures`
--
ALTER TABLE `signatures`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=281;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `task_attachments`
--
ALTER TABLE `task_attachments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_comments`
--
ALTER TABLE `task_comments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `template_fields`
--
ALTER TABLE `template_fields`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=328;

--
-- AUTO_INCREMENT for table `user_logs`
--
ALTER TABLE `user_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_push_subscriptions`
--
ALTER TABLE `user_push_subscriptions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `archive_logs`
--
ALTER TABLE `archive_logs`
  ADD CONSTRAINT `archive_logs_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `archive_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`current_holder_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `documents_ibfk_3` FOREIGN KEY (`owner_department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `documents_ibfk_4` FOREIGN KEY (`current_department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `fk_documents_template` FOREIGN KEY (`template_id`) REFERENCES `document_templates` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `document_attachments`
--
ALTER TABLE `document_attachments`
  ADD CONSTRAINT `document_attachments_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `document_comments`
--
ALTER TABLE `document_comments`
  ADD CONSTRAINT `document_comments_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_fields`
--
ALTER TABLE `document_fields`
  ADD CONSTRAINT `document_fields_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_fields_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `document_status_history`
--
ALTER TABLE `document_status_history`
  ADD CONSTRAINT `document_status_history_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_templates`
--
ALTER TABLE `document_templates`
  ADD CONSTRAINT `document_templates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `document_templates_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `document_user_status`
--
ALTER TABLE `document_user_status`
  ADD CONSTRAINT `document_user_status_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_user_status_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `document_user_status_ibfk_3` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`);

--
-- Constraints for table `document_versions`
--
ALTER TABLE `document_versions`
  ADD CONSTRAINT `document_versions_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_versions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_workflow`
--
ALTER TABLE `document_workflow`
  ADD CONSTRAINT `document_workflow_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_workflow_ibfk_2` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `document_workflow_ibfk_3` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_workflow_steps`
--
ALTER TABLE `document_workflow_steps`
  ADD CONSTRAINT `document_workflow_steps_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_workflow_steps_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `document_workflow_steps_ibfk_3` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `field_images`
--
ALTER TABLE `field_images`
  ADD CONSTRAINT `field_images_ibfk_1` FOREIGN KEY (`field_id`) REFERENCES `document_fields` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `field_images_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `field_images_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `field_values`
--
ALTER TABLE `field_values`
  ADD CONSTRAINT `field_values_ibfk_1` FOREIGN KEY (`field_id`) REFERENCES `document_fields` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `field_values_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_sender_id_fk` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `remember_me_tokens`
--
ALTER TABLE `remember_me_tokens`
  ADD CONSTRAINT `remember_me_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `roles_ibfk_1` FOREIGN KEY (`parent_role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `signatures`
--
ALTER TABLE `signatures`
  ADD CONSTRAINT `signatures_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `signatures_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `signatures_ibfk_3` FOREIGN KEY (`field_id`) REFERENCES `document_fields` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tasks_ibfk_3` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tasks_ibfk_4` FOREIGN KEY (`template_id`) REFERENCES `document_templates` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `task_attachments`
--
ALTER TABLE `task_attachments`
  ADD CONSTRAINT `task_attachments_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `task_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `task_comments`
--
ALTER TABLE `task_comments`
  ADD CONSTRAINT `task_comments_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `task_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `template_fields`
--
ALTER TABLE `template_fields`
  ADD CONSTRAINT `template_fields_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `document_templates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_ibfk_3` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD CONSTRAINT `user_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_push_subscriptions`
--
ALTER TABLE `user_push_subscriptions`
  ADD CONSTRAINT `user_push_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
