-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 21, 2026 at 02:57 AM
-- Server version: 10.6.23-MariaDB-0ubuntu0.22.04.1
-- PHP Version: 8.4.20

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `admin_primepnew2026`
--

-- --------------------------------------------------------

--
-- Table structure for table `aa_settings`
--

CREATE TABLE `aa_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `aa_staff`
--

CREATE TABLE `aa_staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `email_1` varchar(255) NOT NULL DEFAULT '',
  `email_2` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(255) NOT NULL DEFAULT '',
  `photo` varchar(255) NOT NULL DEFAULT '',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `acc_accounts`
--

CREATE TABLE `acc_accounts` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(200) NOT NULL,
  `type` enum('asset','liability','equity','income','expense') NOT NULL,
  `sub_type` varchar(60) DEFAULT NULL,
  `parent_id` int(10) UNSIGNED DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'System accounts cannot be deleted',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `acc_settings`
--

CREATE TABLE `acc_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `acc_vouchers`
--

CREATE TABLE `acc_vouchers` (
  `id` int(10) UNSIGNED NOT NULL,
  `voucher_number` varchar(30) NOT NULL,
  `voucher_type` enum('receipt','payment','contra','journal') NOT NULL,
  `voucher_date` date NOT NULL,
  `reference` varchar(150) DEFAULT NULL,
  `narration` text DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('posted','reversed') NOT NULL DEFAULT 'posted',
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Soft-delete only',
  `created_by` int(10) UNSIGNED NOT NULL,
  `reversed_by` int(10) UNSIGNED DEFAULT NULL,
  `reversed_at` datetime DEFAULT NULL,
  `reversal_of` int(10) UNSIGNED DEFAULT NULL COMMENT 'Points to original voucher if this is a reversal',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `acc_voucher_items`
--

CREATE TABLE `acc_voucher_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `voucher_id` int(10) UNSIGNED NOT NULL,
  `account_id` int(10) UNSIGNED NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `debit_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_admit_cards`
--

CREATE TABLE `ac_admit_cards` (
  `id` int(10) UNSIGNED NOT NULL,
  `exam_name` varchar(200) NOT NULL COMMENT 'e.g. Mid Term-1 Exam',
  `semester` varchar(100) NOT NULL COMMENT 'e.g. Summer-2026',
  `dept_id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED NOT NULL,
  `batch_id` int(10) UNSIGNED DEFAULT NULL,
  `batch_label` varchar(100) DEFAULT NULL COMMENT 'e.g. 12/66',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_admit_card_courses`
--

CREATE TABLE `ac_admit_card_courses` (
  `id` int(10) UNSIGNED NOT NULL,
  `admit_card_id` int(10) UNSIGNED NOT NULL,
  `course_code` varchar(50) NOT NULL,
  `course_title` varchar(300) NOT NULL,
  `exam_date` date DEFAULT NULL,
  `time_slot` varchar(100) DEFAULT NULL,
  `section` varchar(100) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_student_overrides`
--

CREATE TABLE `ac_student_overrides` (
  `id` int(10) UNSIGNED NOT NULL,
  `admit_card_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `allowed_by` int(10) UNSIGNED NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_student_tokens`
--

CREATE TABLE `ac_student_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `admit_card_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `token` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admissions_academic_records`
--

CREATE TABLE `admissions_academic_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `application_id` int(10) UNSIGNED NOT NULL,
  `exam_name` varchar(255) DEFAULT NULL,
  `session` varchar(50) DEFAULT NULL,
  `group_name` varchar(100) DEFAULT NULL,
  `board_university` varchar(255) DEFAULT NULL,
  `year_of_passing` varchar(10) DEFAULT NULL,
  `division_grade` varchar(100) DEFAULT NULL,
  `total_marks_cgpa` varchar(100) DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admissions_applications`
--

CREATE TABLE `admissions_applications` (
  `id` int(10) UNSIGNED NOT NULL,
  `app_number` varchar(30) NOT NULL,
  `assigned_student_id` varchar(50) DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected','ready_for_admission','cancelled','admission_complete') NOT NULL DEFAULT 'draft',
  `dept_id` int(10) UNSIGNED DEFAULT NULL,
  `program_id` int(10) UNSIGNED DEFAULT NULL,
  `year` year(4) DEFAULT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `student_name` varchar(255) NOT NULL,
  `father_name` varchar(255) DEFAULT NULL,
  `mother_name` varchar(255) DEFAULT NULL,
  `present_address_1` varchar(255) DEFAULT NULL,
  `present_address_2` varchar(255) DEFAULT NULL,
  `present_area` varchar(255) DEFAULT NULL,
  `present_district_id` int(10) UNSIGNED DEFAULT NULL,
  `present_thana_id` int(10) UNSIGNED DEFAULT NULL,
  `present_post_code` varchar(20) DEFAULT NULL,
  `present_contact` varchar(50) DEFAULT NULL,
  `present_email` varchar(255) DEFAULT NULL,
  `permanent_address_1` varchar(255) DEFAULT NULL,
  `permanent_address_2` varchar(255) DEFAULT NULL,
  `permanent_area` varchar(255) DEFAULT NULL,
  `permanent_district_id` int(10) UNSIGNED DEFAULT NULL,
  `permanent_thana_id` int(10) UNSIGNED DEFAULT NULL,
  `permanent_post_code` varchar(20) DEFAULT NULL,
  `permanent_contact` varchar(50) DEFAULT NULL,
  `permanent_email` varchar(255) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `place_of_birth` varchar(255) DEFAULT NULL,
  `religion` varchar(100) DEFAULT NULL,
  `nid_birth_cert` varchar(100) DEFAULT NULL,
  `blood_group` varchar(10) DEFAULT NULL,
  `sex` enum('Male','Female','Other') DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `experience` text DEFAULT NULL,
  `guardian_name` varchar(255) DEFAULT NULL,
  `guardian_profession` varchar(255) DEFAULT NULL,
  `guardian_address_1` varchar(255) DEFAULT NULL,
  `guardian_address_2` varchar(255) DEFAULT NULL,
  `guardian_phone` varchar(50) DEFAULT NULL,
  `guardian_email` varchar(255) DEFAULT NULL,
  `guardian_relationship` varchar(100) DEFAULT NULL,
  `guardian_monthly_income` varchar(100) DEFAULT NULL,
  `local_guardian_name` varchar(255) DEFAULT NULL,
  `local_guardian_address_1` varchar(255) DEFAULT NULL,
  `local_guardian_address_2` varchar(255) DEFAULT NULL,
  `local_guardian_address_3` varchar(255) DEFAULT NULL,
  `local_guardian_contact` varchar(50) DEFAULT NULL,
  `reference_name` varchar(255) DEFAULT NULL,
  `reference_address_1` varchar(255) DEFAULT NULL,
  `reference_address_2` varchar(255) DEFAULT NULL,
  `reference_address_3` varchar(255) DEFAULT NULL,
  `reference_contact` varchar(50) DEFAULT NULL,
  `expelled_answer` enum('No','Yes') NOT NULL DEFAULT 'No',
  `expelled_detail` varchar(255) DEFAULT NULL,
  `office_university_batch` varchar(100) DEFAULT NULL,
  `office_dept_batch` varchar(100) DEFAULT NULL,
  `office_section` varchar(100) DEFAULT NULL,
  `office_shift` varchar(100) DEFAULT NULL,
  `office_decision` varchar(255) DEFAULT NULL,
  `office_checked_by` varchar(255) DEFAULT NULL,
  `promoter_source` enum('No','Yes') NOT NULL DEFAULT 'No',
  `promoter_name` varchar(255) DEFAULT NULL,
  `promoter_address` varchar(255) DEFAULT NULL,
  `promoter_contact` varchar(50) DEFAULT NULL,
  `promoter_email` varchar(255) DEFAULT NULL,
  `prime_student` enum('No','Yes') NOT NULL DEFAULT 'No',
  `prime_student_id` varchar(50) DEFAULT NULL,
  `prime_department` varchar(255) DEFAULT NULL,
  `prime_program` varchar(255) DEFAULT NULL,
  `source_note` text DEFAULT NULL,
  `financial_package_id` int(10) UNSIGNED DEFAULT NULL,
  `financial_package_name` varchar(255) DEFAULT NULL,
  `financial_total_semesters` smallint(5) UNSIGNED DEFAULT NULL,
  `financial_total_months` smallint(5) UNSIGNED DEFAULT NULL,
  `financial_tuition_per_semester` decimal(12,2) DEFAULT NULL,
  `financial_admission_fee` decimal(12,2) DEFAULT NULL,
  `financial_registration_fee_per_semester` decimal(12,2) DEFAULT NULL,
  `financial_fixed_institutional_fees` decimal(12,2) DEFAULT NULL,
  `financial_english_course_fee` decimal(12,2) DEFAULT NULL,
  `financial_form_id_fee` decimal(12,2) DEFAULT NULL,
  `scholarship_label` varchar(255) DEFAULT NULL,
  `scholarship_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `scholarship_discount_type` enum('percentage','fixed') DEFAULT NULL COMMENT 'percentage = % of tuition; fixed = fixed BDT amount',
  `scholarship_discount_pct` decimal(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Used when discount_type = percentage',
  `scholarship_scope` enum('first_semester','all_semesters') NOT NULL DEFAULT 'first_semester' COMMENT 'first_semester = first semester only; all_semesters = every semester',
  `scholarship_applies_to_fixed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Also apply % discount to institutional fees',
  `scholarship_applies_to_english` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Also apply % discount to English course fee',
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admissions_fields`
--

CREATE TABLE `admissions_fields` (
  `id` int(10) UNSIGNED NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `field_label` varchar(255) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admissions_field_mappings`
--

CREATE TABLE `admissions_field_mappings` (
  `id` int(10) UNSIGNED NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `page_number` tinyint(4) NOT NULL,
  `x_percent` decimal(6,3) NOT NULL DEFAULT 0.000,
  `y_percent` decimal(6,3) NOT NULL DEFAULT 0.000,
  `font_size` tinyint(4) NOT NULL DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admissions_settings`
--

CREATE TABLE `admissions_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admissions_templates`
--

CREATE TABLE `admissions_templates` (
  `id` int(10) UNSIGNED NOT NULL,
  `page_number` tinyint(3) UNSIGNED NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_file` varchar(255) NOT NULL,
  `file_type` enum('pdf','image') NOT NULL DEFAULT 'image',
  `width` int(11) NOT NULL DEFAULT 794,
  `height` int(11) NOT NULL DEFAULT 1123,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admissions_top_sheet_programs`
--

CREATE TABLE `admissions_top_sheet_programs` (
  `id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED NOT NULL COMMENT 'FK to dept_academic_programs.id',
  `short_label` varchar(100) NOT NULL COMMENT 'Short label shown in report table (e.g. BBA, MBA 69 cr.)',
  `full_name` varchar(255) DEFAULT NULL COMMENT 'Full degree name for the legend (e.g. Bachelor of Business Administration (BBA)- 4 Years)',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = show in report, 0 = hide'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adm_admission_fee_payments`
--

CREATE TABLE `adm_admission_fee_payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `application_id` int(10) UNSIGNED NOT NULL COMMENT 'admissions_applications.id',
  `voucher_id` int(10) UNSIGNED NOT NULL COMMENT 'acc_vouchers.id',
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','bank','mobile_banking') NOT NULL DEFAULT 'cash' COMMENT 'How payment was received',
  `mobile_banking_provider` enum('bkash','nagad','rocket') DEFAULT NULL COMMENT 'Provider when payment_method=mobile_banking',
  `transaction_number` varchar(100) DEFAULT NULL COMMENT 'External transaction/challan/reference number for non-cash payments',
  `collected_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'users.id of the staff member who collected',
  `collected_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Admission fee payments collected before a student ID is assigned';

-- --------------------------------------------------------

--
-- Table structure for table `adm_form_sales`
--

CREATE TABLE `adm_form_sales` (
  `id` int(10) UNSIGNED NOT NULL,
  `form_number` varchar(30) NOT NULL,
  `buyer_name` varchar(255) NOT NULL,
  `buyer_email` varchar(255) DEFAULT NULL,
  `buyer_mobile` varchar(50) NOT NULL,
  `form_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','used','cancelled') NOT NULL DEFAULT 'pending',
  `application_id` int(10) UNSIGNED DEFAULT NULL,
  `sold_by` int(10) UNSIGNED DEFAULT NULL,
  `sold_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adm_form_sale_academic_records`
--

CREATE TABLE `adm_form_sale_academic_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `form_sale_id` int(10) UNSIGNED NOT NULL,
  `exam_name` varchar(255) DEFAULT NULL,
  `session` varchar(50) DEFAULT NULL,
  `group_name` varchar(100) DEFAULT NULL,
  `board_university` varchar(255) DEFAULT NULL,
  `year_of_passing` varchar(10) DEFAULT NULL,
  `division_grade` varchar(100) DEFAULT NULL,
  `total_marks_cgpa` varchar(100) DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adm_form_sale_student_details`
--

CREATE TABLE `adm_form_sale_student_details` (
  `id` int(10) UNSIGNED NOT NULL,
  `form_sale_id` int(10) UNSIGNED NOT NULL,
  `token_id` int(10) UNSIGNED NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `father_name` varchar(255) DEFAULT NULL,
  `mother_name` varchar(255) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `blood_group` varchar(10) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `place_of_birth` varchar(255) DEFAULT NULL,
  `nid_birth_cert` varchar(100) DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `permanent_address_1` varchar(255) DEFAULT NULL,
  `permanent_address_2` varchar(255) DEFAULT NULL,
  `permanent_area` varchar(255) DEFAULT NULL,
  `permanent_district_id` int(10) UNSIGNED DEFAULT NULL,
  `permanent_thana_id` int(10) UNSIGNED DEFAULT NULL,
  `permanent_post_code` varchar(20) DEFAULT NULL,
  `present_same_as_permanent` tinyint(1) NOT NULL DEFAULT 0,
  `present_address_1` varchar(255) DEFAULT NULL,
  `present_address_2` varchar(255) DEFAULT NULL,
  `present_area` varchar(255) DEFAULT NULL,
  `present_district_id` int(10) UNSIGNED DEFAULT NULL,
  `present_thana_id` int(10) UNSIGNED DEFAULT NULL,
  `present_post_code` varchar(20) DEFAULT NULL,
  `experience` text DEFAULT NULL,
  `guardian_name` varchar(255) DEFAULT NULL,
  `guardian_profession` varchar(255) DEFAULT NULL,
  `guardian_relationship` varchar(100) DEFAULT NULL,
  `guardian_monthly_income` varchar(100) DEFAULT NULL,
  `guardian_address_1` varchar(255) DEFAULT NULL,
  `guardian_address_2` varchar(255) DEFAULT NULL,
  `guardian_phone` varchar(50) DEFAULT NULL,
  `guardian_email` varchar(255) DEFAULT NULL,
  `local_guardian_name` varchar(255) DEFAULT NULL,
  `local_guardian_address_1` varchar(255) DEFAULT NULL,
  `local_guardian_address_2` varchar(255) DEFAULT NULL,
  `local_guardian_address_3` varchar(255) DEFAULT NULL,
  `local_guardian_contact` varchar(50) DEFAULT NULL,
  `reference_name` varchar(255) DEFAULT NULL,
  `reference_address_1` varchar(255) DEFAULT NULL,
  `reference_address_2` varchar(255) DEFAULT NULL,
  `reference_address_3` varchar(255) DEFAULT NULL,
  `reference_contact` varchar(50) DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adm_form_sale_tokens`
--

CREATE TABLE `adm_form_sale_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `form_sale_id` int(10) UNSIGNED NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adm_fs_field_mappings`
--

CREATE TABLE `adm_fs_field_mappings` (
  `id` int(10) UNSIGNED NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `x_percent` decimal(6,3) NOT NULL DEFAULT 0.000,
  `y_percent` decimal(6,3) NOT NULL DEFAULT 0.000,
  `font_size` tinyint(4) NOT NULL DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adm_fs_templates`
--

CREATE TABLE `adm_fs_templates` (
  `id` int(10) UNSIGNED NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_file` varchar(255) NOT NULL,
  `file_type` enum('pdf','image') NOT NULL DEFAULT 'image',
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adm_student_id_settings`
--

CREATE TABLE `adm_student_id_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED NOT NULL,
  `university_code` varchar(10) NOT NULL DEFAULT '028',
  `year_code` varchar(4) NOT NULL DEFAULT '26',
  `semester_code` varchar(4) NOT NULL DEFAULT '2',
  `faculty_code` varchar(4) NOT NULL DEFAULT '05',
  `subject_code` varchar(4) NOT NULL DEFAULT '10',
  `type_of_program` varchar(4) NOT NULL DEFAULT '1',
  `next_serial` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `serial_digits` tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `alumni`
--

CREATE TABLE `alumni` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK to dept_departments; NULL = no department assigned',
  `student_id` varchar(50) DEFAULT NULL COMMENT 'University student ID (e.g. 201-15-2345)',
  `name` varchar(200) NOT NULL,
  `batch` varchar(100) DEFAULT NULL COMMENT 'Graduation batch label, e.g. 26th or Spring 2018',
  `phone` varchar(30) DEFAULT NULL COMMENT 'Contact phone (admin-only, not shown publicly)',
  `email` varchar(200) DEFAULT NULL COMMENT 'Contact email (admin-only, not shown publicly)',
  `company` varchar(200) DEFAULT NULL COMMENT 'Current employer / organisation',
  `position` varchar(200) DEFAULT NULL COMMENT 'Current role / job title',
  `linkedin_url` varchar(500) DEFAULT NULL,
  `fb_url` varchar(500) DEFAULT NULL,
  `photo` varchar(300) DEFAULT NULL COMMENT 'Filename stored under admin/uploads/alumni/',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL COMMENT 'Admin remarks (e.g. rejection reason)',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_push_tokens`
--

CREATE TABLE `api_push_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `fcm_token` text NOT NULL,
  `device_id` varchar(255) DEFAULT NULL,
  `platform` varchar(20) NOT NULL DEFAULT 'android',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_tokens`
--

CREATE TABLE `api_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token` char(64) NOT NULL,
  `device_id` varchar(255) DEFAULT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bd_districts`
--

CREATE TABLE `bd_districts` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `division` varchar(100) NOT NULL,
  `bn_name` varchar(100) DEFAULT NULL COMMENT 'Bengali name'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bd_thanas`
--

CREATE TABLE `bd_thanas` (
  `id` int(10) UNSIGNED NOT NULL,
  `district_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `bn_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `broadcasts`
--

CREATE TABLE `broadcasts` (
  `id` int(10) UNSIGNED NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` longtext NOT NULL,
  `recipient_type` enum('individual','group','all','students') NOT NULL DEFAULT 'all',
  `recipient_user_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK users.id – used when recipient_type=individual',
  `recipient_group_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK user_groups.id – used when recipient_type=group',
  `student_dept_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK dept_departments.id – NULL = all departments',
  `student_program_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK dept_academic_programs.id – NULL = all programs',
  `student_status` varchar(20) DEFAULT NULL COMMENT 'Active|Inactive|Graduated|Dropped – NULL = all',
  `student_semester` varchar(50) DEFAULT NULL COMMENT 'e.g. Summer 2025 – NULL = all semesters',
  `ack_required` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = broadcast requires recipient acknowledgment',
  `sent_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `status` enum('draft','sent','partial','pending_approval','rejected') NOT NULL DEFAULT 'draft',
  `review_note` varchar(500) DEFAULT NULL COMMENT 'Rejection reason from reviewer',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK users.id – who approved/rejected',
  `reviewed_at` datetime DEFAULT NULL COMMENT 'When approval/rejection happened',
  `sent_by` int(10) UNSIGNED NOT NULL COMMENT 'FK users.id',
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `broadcast_attachments`
--

CREATE TABLE `broadcast_attachments` (
  `id` int(10) UNSIGNED NOT NULL,
  `broadcast_id` int(10) UNSIGNED NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `broadcast_recipients`
--

CREATE TABLE `broadcast_recipients` (
  `id` int(10) UNSIGNED NOT NULL,
  `broadcast_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `email` varchar(191) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `sent_at` datetime DEFAULT current_timestamp(),
  `ack_token` varchar(64) DEFAULT NULL COMMENT 'One-time acknowledgment token',
  `acked_at` datetime DEFAULT NULL COMMENT 'When the recipient acknowledged',
  `ack_ip` varchar(45) DEFAULT NULL COMMENT 'IP address at acknowledgment time',
  `ack_department` varchar(150) DEFAULT NULL COMMENT 'Department/group of recipient at ack time'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cc_mark_distributions`
--

CREATE TABLE `cc_mark_distributions` (
  `id` int(10) UNSIGNED NOT NULL,
  `curriculum_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → course_curriculum.id',
  `distribution_name` varchar(100) NOT NULL COMMENT 'e.g. Attendance, Class Test, Mid Term, Final',
  `max_marks` decimal(5,2) NOT NULL DEFAULT 100.00 COMMENT 'Maximum marks allocated to this distribution',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Marking distribution breakdown per course curriculum subject';

-- --------------------------------------------------------

--
-- Table structure for table `cert_verification_log`
--

CREATE TABLE `cert_verification_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `queried_student_id` varchar(50) NOT NULL COMMENT 'Student ID string entered by the verifier',
  `student_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → students.id (NULL if student not found)',
  `student_found` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if student record was located',
  `verifier_type` enum('student','company') NOT NULL COMMENT 'student or company',
  `verifier_name` varchar(200) NOT NULL,
  `verifier_email` varchar(200) NOT NULL,
  `verifier_phone` varchar(50) NOT NULL,
  `company_name` varchar(300) DEFAULT NULL,
  `company_address` text DEFAULT NULL,
  `verifier_designation` varchar(200) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IPv4 or IPv6 of the requester',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log of all public certificate-verification requests';

-- --------------------------------------------------------

--
-- Table structure for table `cf_admission_requirements`
--

CREATE TABLE `cf_admission_requirements` (
  `id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED NOT NULL,
  `requirement_text` varchar(500) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cf_degree_types`
--

CREATE TABLE `cf_degree_types` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `slug` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `icon` varchar(20) DEFAULT NULL COMMENT 'Emoji icon',
  `description` varchar(300) DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cf_programs`
--

CREATE TABLE `cf_programs` (
  `id` int(10) UNSIGNED NOT NULL,
  `degree_type_id` tinyint(3) UNSIGNED NOT NULL,
  `program_slug` varchar(50) NOT NULL COMMENT 'JS-compatible slug, e.g. bba, cse, mba-regular',
  `program_name` varchar(200) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `admission_fee_base` int(10) UNSIGNED DEFAULT NULL COMMENT 'One-time admission fee (BDT) - was global in cf_settings',
  `reg_fee_per_semester` int(10) UNSIGNED DEFAULT NULL COMMENT 'Registration fee per semester (BDT) - was global in cf_settings',
  `reg_fee_total` int(10) UNSIGNED DEFAULT NULL COMMENT 'Total registration fees across all semesters (BDT) - was global in cf_settings',
  `form_id_fee` int(10) UNSIGNED DEFAULT NULL COMMENT 'Admission form + ID card fee (BDT) - was global in cf_settings',
  `id_card_fee` int(10) UNSIGNED DEFAULT NULL COMMENT 'ID card fee only (BDT) - was global in cf_settings',
  `admission_form_fee` int(10) UNSIGNED DEFAULT NULL COMMENT 'Admission form fee only (BDT) - was global in cf_settings',
  `bi_semester_start_month` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Starting month for bi-semester programs (1-12) - was global in cf_settings',
  `tri_semester_start_month` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Starting month for tri-semester programs (1-12) - was global in cf_settings',
  `total_credits` decimal(6,2) DEFAULT NULL,
  `duration_years` decimal(4,1) DEFAULT NULL,
  `total_semesters` tinyint(3) UNSIGNED DEFAULT NULL,
  `total_months` smallint(5) UNSIGNED DEFAULT NULL,
  `standard_tuition_full` int(10) UNSIGNED DEFAULT NULL,
  `tuition_per_semester` decimal(10,2) DEFAULT NULL,
  `admission_fees` int(10) UNSIGNED DEFAULT NULL COMMENT 'Total admission day payment (admission + 1st sem reg + form)',
  `fixed_institutional_fees` int(10) UNSIGNED DEFAULT NULL,
  `english_course_fee` int(10) UNSIGNED DEFAULT 0,
  `safety_net_cap` int(10) UNSIGNED DEFAULT NULL,
  `safety_net_per_semester` decimal(10,2) DEFAULT NULL,
  `attendance_requirement` tinyint(3) UNSIGNED DEFAULT 70 COMMENT '70, 60, or 50 percent attendance for safety net',
  `safety_net_gpa_threshold` decimal(4,2) DEFAULT 3.00,
  `scholarship_type` varchar(30) DEFAULT 'regular_bachelor' COMMENT 'One of: regular_bachelor, ba_bangla, llb, diploma',
  `initial_waiver_tiers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`initial_waiver_tiers`)),
  `merit_waiver_tiers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`merit_waiver_tiers`)),
  `tuition_full` int(10) UNSIGNED DEFAULT NULL,
  `admission_fee_m` int(10) UNSIGNED DEFAULT NULL,
  `registration_fee` int(10) UNSIGNED DEFAULT NULL,
  `institutional_fees` int(10) UNSIGNED DEFAULT NULL,
  `campaign_waiver` int(10) UNSIGNED DEFAULT NULL,
  `total_program_cost` int(10) UNSIGNED DEFAULT NULL,
  `total_after_waiver` int(10) UNSIGNED DEFAULT NULL,
  `monthly_fixed` decimal(10,2) DEFAULT NULL,
  `external_waiver` int(10) UNSIGNED DEFAULT NULL,
  `external_final` int(10) UNSIGNED DEFAULT NULL,
  `external_monthly` decimal(10,2) DEFAULT NULL,
  `internal_waiver` int(10) UNSIGNED DEFAULT NULL,
  `internal_final` int(10) UNSIGNED DEFAULT NULL,
  `internal_monthly` decimal(10,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cf_settings`
--

CREATE TABLE `cf_settings` (
  `id` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `page_title` varchar(300) NOT NULL DEFAULT 'Course Fee Calculator',
  `session_label` varchar(100) NOT NULL DEFAULT 'Summer 2026' COMMENT 'Semester label shown on the public calculator, e.g. "Summer 2026"',
  `disclaimer` text DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `admission_fee_base` int(10) UNSIGNED NOT NULL DEFAULT 10000 COMMENT 'Standard one-time admission fee component (BDT)',
  `reg_fee_per_semester` int(10) UNSIGNED NOT NULL DEFAULT 1000 COMMENT 'Registration fee charged per semester (BDT)',
  `reg_fee_total` int(10) UNSIGNED NOT NULL DEFAULT 12000 COMMENT 'Total registration fees across all semesters of the programme (BDT)',
  `form_id_fee` int(10) UNSIGNED NOT NULL DEFAULT 1000 COMMENT 'Admission form + ID card one-time fee (BDT)',
  `start_month` tinyint(3) UNSIGNED DEFAULT 1 COMMENT 'Starting month (1-12) for the semester (1=January, 6=June, etc.)',
  `bi_semester_start_month` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Starting month (1-12) for bi-semester programs (1=January, 6=June, etc.)',
  `tri_semester_start_month` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Starting month (1-12) for tri-semester programs (1=January, 5=May, 9=September, etc.)',
  `id_card_fee` int(10) UNSIGNED NOT NULL DEFAULT 500 COMMENT 'One-time ID card fee (BDT)',
  `admission_form_fee` int(10) UNSIGNED NOT NULL DEFAULT 500 COMMENT 'One-time admission form fee (BDT)',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `change_log`
--

CREATE TABLE `change_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT 'User who made the change',
  `module` varchar(100) NOT NULL COMMENT 'Module / section (e.g. users, knowledge-base)',
  `record_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'PK of the affected record',
  `record_label` varchar(255) DEFAULT NULL COMMENT 'Human-readable record identifier',
  `action` enum('CREATE','UPDATE','DELETE') NOT NULL DEFAULT 'UPDATE',
  `field_name` varchar(150) DEFAULT NULL COMMENT 'Specific field that changed (NULL = whole record)',
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `description` text DEFAULT NULL COMMENT 'Optional free-text summary',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ch_settings`
--

CREATE TABLE `ch_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clubs`
--

CREATE TABLE `clubs` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED DEFAULT NULL,
  `program_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `slug` varchar(220) NOT NULL,
  `goal` text DEFAULT NULL,
  `facilities` text DEFAULT NULL,
  `notice` text DEFAULT NULL,
  `cover_photo` varchar(300) DEFAULT NULL,
  `logo` varchar(300) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club_activities`
--

CREATE TABLE `club_activities` (
  `id` int(10) UNSIGNED NOT NULL,
  `club_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `activity_date` date DEFAULT NULL,
  `photo` varchar(300) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club_events`
--

CREATE TABLE `club_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `club_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(280) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `event_time` time DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `capacity` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
  `registration_deadline` date DEFAULT NULL,
  `cover_photo` varchar(300) DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club_event_registrations`
--

CREATE TABLE `club_event_registrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `event_id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `student_id_no` varchar(30) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `department` varchar(200) DEFAULT NULL,
  `program` varchar(200) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club_members`
--

CREATE TABLE `club_members` (
  `id` int(10) UNSIGNED NOT NULL,
  `club_id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `student_id_no` varchar(30) DEFAULT NULL COMMENT 'Student ID string (not FK)',
  `role_position` varchar(100) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club_photos`
--

CREATE TABLE `club_photos` (
  `id` int(10) UNSIGNED NOT NULL,
  `club_id` int(10) UNSIGNED NOT NULL,
  `caption` varchar(300) DEFAULT NULL,
  `stored_name` varchar(300) NOT NULL,
  `original_name` varchar(300) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_about_settings`
--

CREATE TABLE `cms_about_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_admission_settings`
--

CREATE TABLE `cms_admission_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_alumni`
--

CREATE TABLE `cms_alumni` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `designation` varchar(255) DEFAULT NULL,
  `organization` varchar(255) DEFAULT NULL,
  `photo` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_campus_items`
--

CREATE TABLE `cms_campus_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `image` varchar(500) DEFAULT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_coc_items`
--

CREATE TABLE `cms_coc_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `section_id` int(10) UNSIGNED NOT NULL,
  `item_text` text NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_coc_sections`
--

CREATE TABLE `cms_coc_sections` (
  `id` int(10) UNSIGNED NOT NULL,
  `section_key` varchar(50) NOT NULL COMMENT 'student | faculty | staff',
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `intro_text` text DEFAULT NULL,
  `icon` varchar(100) NOT NULL DEFAULT 'fas fa-book',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_contact_settings`
--

CREATE TABLE `cms_contact_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_features`
--

CREATE TABLE `cms_features` (
  `id` int(10) UNSIGNED NOT NULL,
  `icon` varchar(100) NOT NULL DEFAULT 'fas fa-star',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_footer_settings`
--

CREATE TABLE `cms_footer_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_header_settings`
--

CREATE TABLE `cms_header_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_menus`
--

CREATE TABLE `cms_menus` (
  `id` int(10) UNSIGNED NOT NULL,
  `parent_id` int(10) UNSIGNED DEFAULT NULL,
  `label` varchar(150) NOT NULL,
  `url` varchar(500) DEFAULT '#',
  `target` enum('_self','_blank') DEFAULT '_self',
  `type` enum('link','dropdown','megamenu') DEFAULT 'link',
  `icon` varchar(100) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_news`
--

CREATE TABLE `cms_news` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(500) NOT NULL,
  `slug` varchar(500) NOT NULL,
  `content` longtext DEFAULT NULL,
  `content_type` enum('html','text') DEFAULT 'html',
  `featured_image` varchar(500) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `is_approved` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=pending super-admin approval, 1=approved',
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `show_in_ticker` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'When 1 the article title scrolls in the homepage news ticker',
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_news_attachments`
--

CREATE TABLE `cms_news_attachments` (
  `id` int(10) UNSIGNED NOT NULL,
  `news_id` int(10) UNSIGNED NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `size` int(10) UNSIGNED DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_notices`
--

CREATE TABLE `cms_notices` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(300) NOT NULL,
  `content` longtext DEFAULT NULL,
  `content_type` enum('html','text') NOT NULL DEFAULT 'html',
  `attachment` varchar(500) DEFAULT NULL,
  `attachment_original_name` varchar(255) DEFAULT NULL,
  `attachment_mime` varchar(100) DEFAULT NULL,
  `attachment_size` int(11) DEFAULT NULL,
  `publish_as_news` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Also appear in cms_news',
  `news_id` int(11) DEFAULT NULL COMMENT 'FK to cms_news when published as news',
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `is_approved` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=pending super-admin approval, 1=approved',
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `push_sent_at` datetime DEFAULT NULL COMMENT 'Timestamp when push notification was sent to students'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_pending_changes`
--

CREATE TABLE `cms_pending_changes` (
  `id` int(10) UNSIGNED NOT NULL,
  `module` enum('news','notice') NOT NULL COMMENT 'Which table the change targets',
  `record_id` int(10) UNSIGNED NOT NULL,
  `record_title` varchar(500) NOT NULL,
  `action` enum('EDIT','DELETE') NOT NULL,
  `requested_by` int(10) UNSIGNED NOT NULL COMMENT 'FK users.id',
  `payload` longtext DEFAULT NULL COMMENT 'JSON of proposed new field values (EDIT only)',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_programs`
--

CREATE TABLE `cms_programs` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `link_text` varchar(150) DEFAULT 'Read More',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cms_sliders`
--

CREATE TABLE `cms_sliders` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subtitle` varchar(500) DEFAULT NULL,
  `image` varchar(500) NOT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `link_text` varchar(150) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `coe_settings`
--

CREATE TABLE `coe_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `coe_staff`
--

CREATE TABLE `coe_staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `email_1` varchar(255) NOT NULL DEFAULT '',
  `email_2` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(255) NOT NULL DEFAULT '',
  `phone_2` varchar(255) NOT NULL DEFAULT '',
  `photo` varchar(255) NOT NULL DEFAULT '',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

CREATE TABLE `contact_messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `email` varchar(200) NOT NULL,
  `phone` varchar(50) NOT NULL DEFAULT '',
  `subject` varchar(500) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_curriculum`
--

CREATE TABLE `course_curriculum` (
  `id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED NOT NULL,
  `intake_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK to course_curriculum_intakes.id; NULL = legacy row',
  `semester` tinyint(3) UNSIGNED NOT NULL COMMENT '1–12  (e.g. 1 = 1st Year 1st Semester)',
  `sl_no` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `bnqf_code` varchar(50) DEFAULT NULL,
  `course_code` varchar(50) DEFAULT NULL,
  `course_name` varchar(300) NOT NULL,
  `credit` decimal(4,2) DEFAULT NULL,
  `assigned_faculty_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK to dept_faculty.id; faculty responsible for teaching this subject',
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_curriculum_intakes`
--

CREATE TABLE `course_curriculum_intakes` (
  `id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED NOT NULL,
  `batch_name` varchar(150) NOT NULL COMMENT 'e.g. "Spring 2024 Intake", "Batch 30"',
  `intake_year` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'e.g. 2024',
  `intake_season` varchar(20) DEFAULT NULL COMMENT 'Spring / Summer / Fall / Winter',
  `notes` text DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = visible on public site',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `co_offers`
--

CREATE TABLE `co_offers` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL COMMENT 'Offering department',
  `program_id` int(10) UNSIGNED NOT NULL COMMENT 'Offering program',
  `batch_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → course_curriculum_intakes.id',
  `curriculum_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → course_curriculum.id (the subject)',
  `semester` varchar(50) DEFAULT NULL COMMENT 'e.g. "Spring 2026", "Summer 2026", "Fall 2026"',
  `academic_intake` varchar(100) DEFAULT NULL COMMENT 'e.g. "1st Year 1st Semester", "2nd Year 2nd Semester"',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `co_offer_subjects`
--

CREATE TABLE `co_offer_subjects` (
  `id` int(10) UNSIGNED NOT NULL,
  `offer_id` int(10) UNSIGNED NOT NULL,
  `curriculum_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → course_curriculum.id',
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `co_offer_subject_teachers`
--

CREATE TABLE `co_offer_subject_teachers` (
  `id` int(10) UNSIGNED NOT NULL,
  `offer_subject_id` int(10) UNSIGNED NOT NULL,
  `faculty_id` int(10) UNSIGNED NOT NULL,
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crhp_settings`
--

CREATE TABLE `crhp_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crhp_staff`
--

CREATE TABLE `crhp_staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `email_1` varchar(255) NOT NULL DEFAULT '',
  `email_2` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(255) NOT NULL DEFAULT '',
  `photo` varchar(255) NOT NULL DEFAULT '',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_agents`
--

CREATE TABLE `crm_import_agents` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `agent_name` varchar(100) NOT NULL,
  `address` text DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_our_student` tinyint(1) DEFAULT 0,
  `student_id` varchar(50) DEFAULT NULL,
  `batch` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_call_logs`
--

CREATE TABLE `crm_import_call_logs` (
  `id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `call_type` enum('1st call','2nd call','3rd call','Follow-up call','Office visit confirmation','Final call') NOT NULL,
  `previous_status` varchar(50) NOT NULL,
  `new_status` varchar(50) NOT NULL,
  `call_date` datetime NOT NULL DEFAULT current_timestamp(),
  `call_duration` int(11) DEFAULT NULL COMMENT 'Call duration in seconds',
  `call_notes` text DEFAULT NULL,
  `call_outcome` varchar(100) DEFAULT NULL,
  `call_converted` tinyint(1) DEFAULT 0 COMMENT '1 if call led to conversion, 0 otherwise',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_companies`
--

CREATE TABLE `crm_import_companies` (
  `id` int(11) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `contact_first_name` varchar(100) NOT NULL,
  `contact_last_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) NOT NULL,
  `system_email` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_company_subscriptions`
--

CREATE TABLE `crm_import_company_subscriptions` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `subscription_plan_id` int(11) NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime DEFAULT NULL,
  `subscription_status` enum('Active','Expired','Cancelled','Suspended') NOT NULL DEFAULT 'Active',
  `price_paid` decimal(10,2) NOT NULL,
  `payment_status` enum('Paid','Pending','Failed') NOT NULL DEFAULT 'Pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_courses`
--

CREATE TABLE `crm_import_courses` (
  `id` int(11) NOT NULL,
  `course_title` varchar(255) NOT NULL,
  `department` varchar(255) NOT NULL,
  `credit` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_leads`
--

CREATE TABLE `crm_import_leads` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `address` text DEFAULT NULL,
  `current_city` varchar(100) DEFAULT NULL,
  `applying_for` enum('Bachelor Degree','Master Degree') DEFAULT NULL,
  `semester` varchar(100) DEFAULT NULL,
  `ssc_gpa` decimal(3,2) DEFAULT NULL,
  `hsc_gpa` decimal(3,2) DEFAULT NULL,
  `bachelor_subject` varchar(255) DEFAULT NULL,
  `bachelor_cgpa` decimal(3,2) DEFAULT NULL,
  `interested_course_id` int(11) DEFAULT NULL,
  `most_recent_degree` varchar(255) DEFAULT NULL,
  `gpa` decimal(3,2) DEFAULT NULL,
  `ielts_overall` decimal(2,1) DEFAULT NULL,
  `ielts_reading` decimal(2,1) DEFAULT NULL,
  `ielts_writing` decimal(2,1) DEFAULT NULL,
  `ielts_speaking` decimal(2,1) DEFAULT NULL,
  `ielts_listening` decimal(2,1) DEFAULT NULL,
  `country_applying_from` varchar(100) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `budget` decimal(10,2) DEFAULT NULL,
  `lead_source` enum('Direct Online','Direct Campus Visit','Agent','F2F Marketing') NOT NULL,
  `assigned_campus` varchar(100) DEFAULT NULL,
  `lead_status` enum('Fresh','1st call','2nd call','3rd call','Unable to reach once','Unable to reach twice','Unable to reach trice','Dead','Will visit office','Admitted') DEFAULT 'Fresh',
  `last_status_change_date` datetime DEFAULT NULL COMMENT 'Date when lead status was last changed',
  `next_followup_date` date DEFAULT NULL COMMENT 'Calculated date for next follow-up (status change + 2 days)',
  `last_followup_action_date` datetime DEFAULT NULL COMMENT 'Date when last follow-up action was taken',
  `campus_visit_date` datetime DEFAULT NULL,
  `campus_visit_attended_at` datetime DEFAULT NULL,
  `attended_at` timestamp NULL DEFAULT NULL COMMENT 'When the lead attended their office visit',
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL COMMENT 'User ID who last updated this lead',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `agent_id` int(11) DEFAULT NULL,
  `company_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_lead_courses`
--

CREATE TABLE `crm_import_lead_courses` (
  `id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `added_by` int(11) NOT NULL,
  `added_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_lead_edit_history`
--

CREATE TABLE `crm_import_lead_edit_history` (
  `id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `edited_by` int(11) NOT NULL,
  `edited_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_lead_notes`
--

CREATE TABLE `crm_import_lead_notes` (
  `id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `note_text` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_lead_semesters`
--

CREATE TABLE `crm_import_lead_semesters` (
  `id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `added_by` int(11) NOT NULL,
  `added_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_lead_staff_assignments`
--

CREATE TABLE `crm_import_lead_staff_assignments` (
  `id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_lead_universities`
--

CREATE TABLE `crm_import_lead_universities` (
  `id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `university_id` int(11) NOT NULL,
  `added_by` int(11) NOT NULL,
  `added_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_semesters`
--

CREATE TABLE `crm_import_semesters` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_subscription_payments`
--

CREATE TABLE `crm_import_subscription_payments` (
  `id` int(11) NOT NULL,
  `company_subscription_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` datetime NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `payment_status` enum('Success','Pending','Failed') NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_subscription_plans`
--

CREATE TABLE `crm_import_subscription_plans` (
  `id` int(11) NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `plan_type` enum('Monthly','Yearly','Lifetime') NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `features` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_universities`
--

CREATE TABLE `crm_import_universities` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_users`
--

CREATE TABLE `crm_import_users` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `user_type` enum('Super Admin','Admin','Manager','Counselor','Agent') NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `account_status` enum('Active','Deactive') NOT NULL DEFAULT 'Active',
  `company_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_user_profile`
--

CREATE TABLE `crm_import_user_profile` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `mobile_number` varchar(15) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_academic_programs`
--

CREATE TABLE `dept_academic_programs` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `program_name` varchar(300) NOT NULL,
  `degree_type` varchar(100) DEFAULT NULL,
  `program_type` enum('Bachelor','Masters','Diploma','Certificate','Other') DEFAULT NULL COMMENT 'Degree level – auto-detected from degree_type or manually set',
  `duration` varchar(100) DEFAULT NULL,
  `total_credit` varchar(100) DEFAULT NULL,
  `semester_type` varchar(50) DEFAULT NULL COMMENT 'Semester system: trimester, semester, annual, etc.',
  `description` text DEFAULT NULL,
  `details_content` longtext DEFAULT NULL COMMENT 'Rich HTML content (TinyMCE) – admission info, fees, curriculum etc.',
  `admission_content` longtext DEFAULT NULL COMMENT 'Rich HTML (TinyMCE) – Admission Intake & Requirements section',
  `fees_content` longtext DEFAULT NULL COMMENT 'Rich HTML (TinyMCE) – Fees Structure section',
  `curriculum_content` longtext DEFAULT NULL COMMENT 'Rich HTML (TinyMCE) – Course Curriculum section',
  `attachment` varchar(300) DEFAULT NULL COMMENT 'Downloadable brochure/PDF filename in admin/uploads/departments/',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_alumni`
--

CREATE TABLE `dept_alumni` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `batch` varchar(100) DEFAULT NULL COMMENT 'Graduation batch / year (e.g. 2018 or Spring 2018)',
  `position` varchar(200) DEFAULT NULL,
  `company` varchar(200) DEFAULT NULL,
  `linkedin_url` varchar(500) DEFAULT NULL COMMENT 'LinkedIn profile URL',
  `photo` varchar(300) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_clubs`
--

CREATE TABLE `dept_clubs` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `logo` varchar(300) DEFAULT NULL,
  `president_name` varchar(200) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_departments`
--

CREATE TABLE `dept_departments` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `code` varchar(50) NOT NULL,
  `faculty_label` varchar(200) NOT NULL DEFAULT 'Faculty of Science & Engineering',
  `hero_title` varchar(300) NOT NULL,
  `hero_subtitle` text DEFAULT NULL,
  `hero_description` text DEFAULT NULL,
  `hero_icon` varchar(100) NOT NULL DEFAULT 'fas fa-graduation-cap',
  `image` varchar(255) DEFAULT NULL COMMENT 'Filename in admin/uploads/departments/ used for homepage card background',
  `cta_url` varchar(300) NOT NULL DEFAULT 'apply-now.html',
  `cta_text` varchar(100) NOT NULL DEFAULT 'Apply Now',
  `cta_section_title` varchar(300) DEFAULT NULL,
  `cta_section_text` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_events`
--

CREATE TABLE `dept_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(300) NOT NULL,
  `event_date` date DEFAULT NULL,
  `location` varchar(300) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_facilities`
--

CREATE TABLE `dept_facilities` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(100) NOT NULL DEFAULT 'fas fa-desktop',
  `image` varchar(300) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_faculty`
--

CREATE TABLE `dept_faculty` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `designation` varchar(200) DEFAULT NULL,
  `photo` varchar(300) DEFAULT NULL,
  `specialization` varchar(300) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `is_head` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_hero_slides`
--

CREATE TABLE `dept_hero_slides` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `image` varchar(500) NOT NULL,
  `caption` varchar(300) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_notices`
--

CREATE TABLE `dept_notices` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(300) NOT NULL,
  `content` text DEFAULT NULL,
  `attachment` varchar(300) DEFAULT NULL,
  `notice_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `push_sent_at` datetime DEFAULT NULL COMMENT 'Timestamp when push notification was sent to students'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_overview`
--

CREATE TABLE `dept_overview` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `vision` text DEFAULT NULL,
  `mission` text DEFAULT NULL,
  `head_name` varchar(200) DEFAULT NULL,
  `head_designation` varchar(200) DEFAULT NULL,
  `head_photo` varchar(300) DEFAULT NULL,
  `head_edu_qualifications` text DEFAULT NULL,
  `head_message` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_prime_pride`
--

CREATE TABLE `dept_prime_pride` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(300) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(300) DEFAULT NULL,
  `student_name` varchar(200) DEFAULT NULL,
  `position` varchar(200) DEFAULT NULL,
  `company` varchar(200) DEFAULT NULL COMMENT 'Current employer / company',
  `linkedin_url` varchar(500) DEFAULT NULL COMMENT 'LinkedIn profile URL',
  `batch_year` varchar(20) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_routines`
--

CREATE TABLE `dept_routines` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(300) NOT NULL,
  `type` enum('class','exam') NOT NULL DEFAULT 'class',
  `file_path` varchar(300) DEFAULT NULL,
  `semester` varchar(100) DEFAULT NULL,
  `section` varchar(100) DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ei_assignment_snapshots`
--

CREATE TABLE `ei_assignment_snapshots` (
  `id` int(10) UNSIGNED NOT NULL,
  `exam_id` int(10) UNSIGNED NOT NULL,
  `version_number` int(10) UNSIGNED NOT NULL COMMENT 'Sequential per-exam version number',
  `change_type` varchar(50) NOT NULL COMMENT 'auto_assign, manual_edit, clear_slot, revert',
  `change_summary` varchar(500) DEFAULT NULL,
  `changed_by_id` int(10) UNSIGNED DEFAULT NULL,
  `changed_by_name` varchar(200) DEFAULT NULL,
  `slots_count` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total slots in snapshot',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ei_assignment_snapshot_slots`
--

CREATE TABLE `ei_assignment_snapshot_slots` (
  `id` int(10) UNSIGNED NOT NULL,
  `snapshot_id` int(10) UNSIGNED NOT NULL,
  `slot_id` int(10) UNSIGNED NOT NULL,
  `faculty1_id` int(10) UNSIGNED DEFAULT NULL,
  `faculty2_id` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ei_exams`
--

CREATE TABLE `ei_exams` (
  `id` int(10) UNSIGNED NOT NULL,
  `exam_name` varchar(200) NOT NULL,
  `exam_year` year(4) NOT NULL,
  `start_date` date DEFAULT NULL COMMENT 'Exam period start date',
  `end_date` date DEFAULT NULL COMMENT 'Exam period end date',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ei_faculty`
--

CREATE TABLE `ei_faculty` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL COMMENT 'FK to dept_departments',
  `name` varchar(200) NOT NULL,
  `designation` varchar(200) DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL COMMENT 'Faculty gender',
  `weekend_available` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = available on Saturday/Sunday',
  `weekend_days` varchar(50) NOT NULL DEFAULT '0,6' COMMENT 'Faculty weekly weekend/off days; date(w) values (0=Sun..6=Sat)',
  `contact_number` varchar(50) DEFAULT NULL,
  `remuneration_per_slot` decimal(10,2) UNSIGNED NOT NULL DEFAULT 0.00 COMMENT 'Amount paid per attended invigilation slot',
  `signature` varchar(500) DEFAULT NULL COMMENT 'Signature image filename in uploads/exam-invigilation/signatures/',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ei_settings`
--

CREATE TABLE `ei_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_val` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ei_slots`
--

CREATE TABLE `ei_slots` (
  `id` int(10) UNSIGNED NOT NULL,
  `exam_id` int(10) UNSIGNED NOT NULL,
  `slot_date` date NOT NULL,
  `time_slot` varchar(100) NOT NULL COMMENT 'e.g. 9:00 AM – 12:00 PM',
  `room_number` varchar(50) NOT NULL,
  `dept_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Preferred department for Invigilator 1 (auto-assign hint)',
  `faculty1_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Primary invigilator',
  `faculty2_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Secondary invigilator (different department)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ei_slot_attendance`
--

CREATE TABLE `ei_slot_attendance` (
  `id` int(10) UNSIGNED NOT NULL,
  `exam_id` int(10) UNSIGNED NOT NULL,
  `slot_id` int(10) UNSIGNED NOT NULL,
  `faculty_id` int(10) UNSIGNED NOT NULL,
  `attended` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = attended, 0 = absent',
  `notes` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_templates`
--

CREATE TABLE `email_templates` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `action` varchar(100) NOT NULL COMMENT 'trigger slug e.g. forgot_password',
  `subject` varchar(255) NOT NULL,
  `body_html` longtext NOT NULL,
  `variables` varchar(500) DEFAULT NULL COMMENT 'comma-separated available variables e.g. {{full_name}},{{reset_link}}',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `es_settings`
--

CREATE TABLE `es_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `es_staff`
--

CREATE TABLE `es_staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `email_1` varchar(255) NOT NULL DEFAULT '',
  `email_2` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(255) NOT NULL DEFAULT '',
  `photo` varchar(255) NOT NULL DEFAULT '',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculty_files`
--

CREATE TABLE `faculty_files` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL COMMENT 'Display / label name',
  `description` text DEFAULT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = hidden from the faculty member; visible only to uploader/admin/register-office',
  `stored_name` varchar(255) NOT NULL COMMENT 'Filename on disk in uploads/faculty-profiles/files/',
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) UNSIGNED DEFAULT 0,
  `is_id_card` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = uploaded during registration',
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculty_file_delete_requests`
--

CREATE TABLE `faculty_file_delete_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `file_id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL COMMENT 'Snapshot of file label at request time',
  `faculty_user_id` int(10) UNSIGNED NOT NULL COMMENT 'Owning faculty user',
  `faculty_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL COMMENT 'Snapshot of stored filename for later cleanup',
  `requested_by` int(10) UNSIGNED NOT NULL,
  `request_note` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculty_profiles`
--

CREATE TABLE `faculty_profiles` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `designation` varchar(200) DEFAULT NULL,
  `qualification` text DEFAULT NULL,
  `official_email` varchar(200) DEFAULT NULL,
  `personal_email` varchar(200) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `research_interest` text DEFAULT NULL,
  `publications` text DEFAULT NULL,
  `experience` text DEFAULT NULL,
  `office_location` varchar(300) DEFAULT NULL,
  `room_number` varchar(100) DEFAULT NULL,
  `office_hours` varchar(300) DEFAULT NULL,
  `courses_taught` text DEFAULT NULL,
  `google_scholar` varchar(500) DEFAULT NULL,
  `orcid` varchar(500) DEFAULT NULL,
  `research_profiles` text DEFAULT NULL,
  `cv_file` varchar(255) DEFAULT NULL,
  `awards` text DEFAULT NULL,
  `professional_memberships` text DEFAULT NULL,
  `social_links` text DEFAULT NULL,
  `projects_grants` text DEFAULT NULL,
  `supervision` text DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `languages` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculty_registrations`
--

CREATE TABLE `faculty_registrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(191) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `dept_id` int(10) UNSIGNED DEFAULT NULL,
  `id_card_stored` varchar(255) DEFAULT NULL COMMENT 'Generated filename in uploads/faculty-registrations/',
  `id_card_original` varchar(255) DEFAULT NULL COMMENT 'Original uploaded filename',
  `id_card_mime` varchar(100) DEFAULT NULL,
  `id_card_size` bigint(20) UNSIGNED DEFAULT 0,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL COMMENT 'Admin notes on approval/rejection',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculty_subject_assignments`
--

CREATE TABLE `faculty_subject_assignments` (
  `id` int(10) UNSIGNED NOT NULL,
  `faculty_user_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → users.id (the requesting faculty)',
  `course_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → course_curriculum.id',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → users.id (admin/HoD who reviewed)',
  `reviewed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'Reviewer notes / rejection reason',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fake_id_verifications`
--

CREATE TABLE `fake_id_verifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` varchar(100) NOT NULL COMMENT 'Presented (fake) student ID',
  `student_name` varchar(200) NOT NULL COMMENT 'Presented name on fake document',
  `to_email` varchar(254) DEFAULT NULL COMMENT 'Recipient email',
  `to_name` varchar(200) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `ref_no` varchar(100) DEFAULT NULL,
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `checked_by` int(10) UNSIGNED NOT NULL COMMENT 'FK → users.id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `file_manager_files`
--

CREATE TABLE `file_manager_files` (
  `id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `creator_id` int(10) UNSIGNED NOT NULL,
  `file_location` varchar(500) DEFAULT NULL COMMENT 'Physical / cabinet location of the real document',
  `uploaded_file` varchar(255) DEFAULT NULL COMMENT 'Optional digital copy stored on disk',
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `proposal` text DEFAULT NULL COMMENT 'Proposal or purpose of the file',
  `page_number` varchar(50) DEFAULT NULL COMMENT 'Page / reference number',
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `initiator_name` varchar(150) DEFAULT NULL COMMENT 'Name of the person who initiated the file',
  `initiator_department` varchar(200) DEFAULT NULL COMMENT 'Department of the initiator',
  `initiator_designation` varchar(200) DEFAULT NULL COMMENT 'Designation / job title of the initiator',
  `current_holder_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'User currently holding / responsible for the file'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `file_manager_pages`
--

CREATE TABLE `file_manager_pages` (
  `id` int(10) UNSIGNED NOT NULL,
  `file_id` int(10) UNSIGNED NOT NULL,
  `page_number` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `title` varchar(255) DEFAULT NULL,
  `category` enum('Document','Notes') NOT NULL DEFAULT 'Document',
  `subject` varchar(300) DEFAULT NULL COMMENT 'Required when category = Notes',
  `uploaded_file` varchar(255) DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(10) UNSIGNED DEFAULT NULL,
  `requires_signature` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `file_manager_page_signatures`
--

CREATE TABLE `file_manager_page_signatures` (
  `id` int(10) UNSIGNED NOT NULL,
  `page_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `position_id` int(10) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `signed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `file_manager_page_sign_positions`
--

CREATE TABLE `file_manager_page_sign_positions` (
  `id` int(10) UNSIGNED NOT NULL,
  `page_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `x_percent` decimal(5,2) NOT NULL DEFAULT 50.00,
  `y_percent` decimal(5,2) NOT NULL DEFAULT 80.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `show_datetime` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Show signed date/time below signature'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `file_manager_page_text_notes`
--

CREATE TABLE `file_manager_page_text_notes` (
  `id` int(10) UNSIGNED NOT NULL,
  `page_id` int(10) UNSIGNED NOT NULL,
  `content` text NOT NULL,
  `x_percent` decimal(5,2) NOT NULL DEFAULT 10.00,
  `y_percent` decimal(5,2) NOT NULL DEFAULT 10.00,
  `font_size` tinyint(3) UNSIGNED NOT NULL DEFAULT 12,
  `color` varchar(7) NOT NULL DEFAULT '#000000',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `file_manager_tagged_users`
--

CREATE TABLE `file_manager_tagged_users` (
  `id` int(10) UNSIGNED NOT NULL,
  `file_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `tagged_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `file_manager_transfers`
--

CREATE TABLE `file_manager_transfers` (
  `id` int(10) UNSIGNED NOT NULL,
  `file_id` int(10) UNSIGNED NOT NULL,
  `from_user_id` int(10) UNSIGNED NOT NULL,
  `to_user_id` int(10) UNSIGNED NOT NULL,
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `message` text DEFAULT NULL COMMENT 'Transfer request message',
  `response_note` text DEFAULT NULL COMMENT 'Reason when accepting/rejecting',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gallery_albums`
--

CREATE TABLE `gallery_albums` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK dept_departments; NULL = university-wide',
  `program_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK dept_academic_programs; optional',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` date DEFAULT NULL COMMENT 'Date of the event photographed',
  `cover_photo` varchar(300) DEFAULT NULL COMMENT 'stored filename inside gallery/covers/',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gallery_photos`
--

CREATE TABLE `gallery_photos` (
  `id` int(10) UNSIGNED NOT NULL,
  `album_id` int(10) UNSIGNED NOT NULL,
  `stored_name` varchar(300) NOT NULL COMMENT 'random filename on disk',
  `original_name` varchar(300) NOT NULL COMMENT 'original filename from uploader',
  `caption` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `glance_highlights`
--

CREATE TABLE `glance_highlights` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `icon` varchar(120) NOT NULL,
  `description` text NOT NULL,
  `color_theme` varchar(30) NOT NULL,
  `tag_label` varchar(100) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `glance_leaders`
--

CREATE TABLE `glance_leaders` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `role` varchar(120) NOT NULL,
  `bio` text DEFAULT NULL,
  `photo` varchar(300) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `glance_messages`
--

CREATE TABLE `glance_messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `msg_key` varchar(30) NOT NULL COMMENT 'chairman or vc',
  `tab_label` varchar(100) NOT NULL,
  `person_name` varchar(200) NOT NULL,
  `person_role` varchar(200) NOT NULL,
  `photo` varchar(300) DEFAULT NULL,
  `body` text NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `glance_milestones`
--

CREATE TABLE `glance_milestones` (
  `id` int(10) UNSIGNED NOT NULL,
  `year_label` varchar(60) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `glance_settings`
--

CREATE TABLE `glance_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `glance_stats`
--

CREATE TABLE `glance_stats` (
  `id` int(10) UNSIGNED NOT NULL,
  `icon` varchar(120) NOT NULL DEFAULT 'fas fa-star',
  `value` varchar(60) NOT NULL COMMENT 'Display value e.g. 2002 or 30K+',
  `label` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `global_settings`
--

CREATE TABLE `global_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `governing_body_members`
--

CREATE TABLE `governing_body_members` (
  `id` int(10) UNSIGNED NOT NULL,
  `page_type` enum('board-of-trustees','pu-syndicates','deans','head-of-departments') NOT NULL,
  `section` varchar(100) NOT NULL DEFAULT 'member',
  `full_name` varchar(150) NOT NULL,
  `designation` varchar(150) DEFAULT NULL,
  `department` varchar(150) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `photo` varchar(500) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `glance_officer` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Show in Key Administrative Officers on PU At a Glance',
  `glance_link` varchar(255) DEFAULT NULL COMMENT 'Optional URL for the officer card on PU At a Glance (e.g. /office-of-pro-vc.php)',
  `glance_msg_tab` varchar(120) DEFAULT NULL COMMENT 'Tab label for Words from Our Leadership section',
  `glance_message` text DEFAULT NULL COMMENT 'Message body for Words from Our Leadership section',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `governing_body_pages`
--

CREATE TABLE `governing_body_pages` (
  `id` int(10) UNSIGNED NOT NULL,
  `page_type` enum('board-of-trustees','pu-syndicates','deans','head-of-departments') NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `subtitle` varchar(255) NOT NULL DEFAULT '',
  `hero_intro` text DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_dept_scope`
--

CREATE TABLE `group_dept_scope` (
  `id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_module_access`
--

CREATE TABLE `group_module_access` (
  `id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `module_id` int(10) UNSIGNED NOT NULL,
  `can_view` tinyint(1) DEFAULT 1,
  `can_create` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_delete` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `homepage_stats`
--

CREATE TABLE `homepage_stats` (
  `id` int(11) NOT NULL,
  `icon` varchar(100) NOT NULL DEFAULT 'fas fa-star' COMMENT 'Font Awesome class e.g. fas fa-user-graduate',
  `value` varchar(50) NOT NULL COMMENT 'Numeric or text value e.g. 15000 or 32+',
  `label` varchar(120) NOT NULL COMMENT 'Label shown below the number',
  `suffix` varchar(20) DEFAULT '+' COMMENT 'Suffix appended after animated number e.g. +',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `homepage_testimonials`
--

CREATE TABLE `homepage_testimonials` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `designation` varchar(200) DEFAULT NULL,
  `quote` text NOT NULL,
  `photo` varchar(255) DEFAULT NULL COMMENT 'Filename inside uploads/homepage/',
  `rating` tinyint(4) NOT NULL DEFAULT 5 COMMENT '1–5 stars',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `it_settings`
--

CREATE TABLE `it_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `it_staff`
--

CREATE TABLE `it_staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `email_1` varchar(255) NOT NULL DEFAULT '',
  `email_2` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(255) NOT NULL DEFAULT '',
  `photo` varchar(255) NOT NULL DEFAULT '',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(500) NOT NULL,
  `slug` varchar(500) NOT NULL,
  `department` varchar(200) NOT NULL DEFAULT '',
  `job_type` enum('full-time','part-time','contract','internship') NOT NULL DEFAULT 'full-time',
  `location` varchar(200) NOT NULL DEFAULT '',
  `description` longtext NOT NULL,
  `requirements` longtext DEFAULT NULL,
  `salary_range` varchar(100) DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_applications`
--

CREATE TABLE `job_applications` (
  `id` int(10) UNSIGNED NOT NULL,
  `job_id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `email` varchar(200) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `cover_letter` text DEFAULT NULL,
  `cv_filename` varchar(255) DEFAULT NULL,
  `cv_original_name` varchar(255) DEFAULT NULL,
  `status` enum('pending','reviewing','shortlisted','rejected') NOT NULL DEFAULT 'pending',
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kb_articles`
--

CREATE TABLE `kb_articles` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` enum('video','document') NOT NULL DEFAULT 'document',
  `description` text DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `video_url` varchar(500) DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_original` varchar(255) DEFAULT NULL,
  `file_mime` varchar(100) DEFAULT NULL,
  `file_size` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leads`
--

CREATE TABLE `leads` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_number` varchar(30) NOT NULL COMMENT 'e.g. LD-2025-0001',
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(200) DEFAULT NULL,
  `phone` varchar(30) NOT NULL,
  `address` text DEFAULT NULL,
  `current_city` varchar(200) DEFAULT NULL,
  `degree_type` enum('bachelor','master') NOT NULL DEFAULT 'bachelor',
  `dept_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Interested department',
  `program_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Interested program',
  `preferred_semester` varchar(50) DEFAULT NULL,
  `ssc_gpa` decimal(3,2) DEFAULT NULL COMMENT 'SSC / O-Level GPA',
  `hsc_gpa` decimal(3,2) DEFAULT NULL COMMENT 'HSC / A-Level GPA',
  `bachelor_subject` varchar(255) DEFAULT NULL COMMENT 'Bachelor degree subject (for Master applicants)',
  `bachelor_cgpa` decimal(3,2) DEFAULT NULL COMMENT 'Bachelor CGPA (for Master applicants)',
  `preferred_call_time` varchar(50) DEFAULT NULL COMMENT 'e.g. Morning (9 AM – 12 PM)',
  `next_followup_date` date DEFAULT NULL COMMENT 'Scheduled next follow-up date',
  `followup_notes` varchar(500) DEFAULT NULL COMMENT 'Notes about the next follow-up',
  `status` enum('fresh','1st_call','2nd_call','3rd_call','unable_to_reach','interested','not_interested','will_visit','converted') NOT NULL DEFAULT 'fresh',
  `source` enum('online','campus_visit','agent','f2f_marketing','facebook') NOT NULL DEFAULT 'online',
  `assigned_to` int(10) UNSIGNED DEFAULT NULL COMMENT 'Primary assigned user',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_appointments`
--

CREATE TABLE `lead_appointments` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time DEFAULT NULL,
  `purpose` varchar(300) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('scheduled','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_assignments`
--

CREATE TABLE `lead_assignments` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `assigned_by` int(10) UNSIGNED DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_call_logs`
--

CREATE TABLE `lead_call_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT 'Staff who made/logged the call',
  `call_type` enum('1st_call','2nd_call','3rd_call','followup_call','visit_confirmation','final_call') NOT NULL DEFAULT '1st_call',
  `previous_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `call_date` datetime NOT NULL DEFAULT current_timestamp(),
  `call_duration` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Duration in minutes',
  `call_notes` text DEFAULT NULL,
  `call_outcome` varchar(100) DEFAULT NULL,
  `call_converted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if call led to conversion',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_fb_contacts`
--

CREATE TABLE `lead_fb_contacts` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Linked lead record (nullable)',
  `psid` varchar(100) NOT NULL COMMENT 'Facebook Page-Scoped User ID',
  `fb_name` varchar(300) DEFAULT NULL,
  `fb_picture` varchar(500) DEFAULT NULL,
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_message_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_fb_messages`
--

CREATE TABLE `lead_fb_messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `contact_id` int(10) UNSIGNED NOT NULL,
  `direction` enum('in','out') NOT NULL COMMENT 'in = from Facebook user, out = sent by staff',
  `message_text` text DEFAULT NULL,
  `attachment_type` varchar(50) DEFAULT NULL COMMENT 'image/audio/video/file/sticker',
  `attachment_url` varchar(1000) DEFAULT NULL,
  `fb_mid` varchar(200) DEFAULT NULL COMMENT 'Facebook message_id for deduplication',
  `sent_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'users.id for outgoing messages',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_fb_settings`
--

CREATE TABLE `lead_fb_settings` (
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_history`
--

CREATE TABLE `lead_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(50) NOT NULL COMMENT 'created, updated, status_changed, assigned, note_added, appointment_set …',
  `field_name` varchar(100) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_notes`
--

CREATE TABLE `lead_notes` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `note` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `library_audit_log`
--

CREATE TABLE `library_audit_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `record_id` int(10) UNSIGNED DEFAULT NULL,
  `record_label` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Immutable audit trail for library module actions';

-- --------------------------------------------------------

--
-- Table structure for table `library_books`
--

CREATE TABLE `library_books` (
  `id` int(10) UNSIGNED NOT NULL,
  `isbn` varchar(30) DEFAULT NULL,
  `title` varchar(300) NOT NULL,
  `subtitle` varchar(300) DEFAULT NULL,
  `author` text NOT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `edition` varchar(50) DEFAULT NULL,
  `pub_year` year(4) DEFAULT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `language` varchar(60) NOT NULL DEFAULT 'English',
  `description` text DEFAULT NULL,
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `shelf_rack` varchar(30) DEFAULT NULL,
  `shelf_row` varchar(30) DEFAULT NULL,
  `total_copies` smallint(6) NOT NULL DEFAULT 1,
  `available_copies` smallint(6) NOT NULL DEFAULT 1,
  `is_digital` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Master catalogue of library books (physical and digital)';

-- --------------------------------------------------------

--
-- Table structure for table `library_book_copies`
--

CREATE TABLE `library_book_copies` (
  `id` int(10) UNSIGNED NOT NULL,
  `book_id` int(10) UNSIGNED NOT NULL,
  `barcode` varchar(60) DEFAULT NULL,
  `copy_number` smallint(6) NOT NULL DEFAULT 1,
  `condition_status` enum('Good','Fair','Poor','Lost','Damaged') NOT NULL DEFAULT 'Good',
  `notes` varchar(255) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Individual physical copies of catalogued books';

-- --------------------------------------------------------

--
-- Table structure for table `library_categories`
--

CREATE TABLE `library_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `slug` varchar(130) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `parent_id` int(10) UNSIGNED DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Hierarchical subject/category tree for the library collection';

-- --------------------------------------------------------

--
-- Table structure for table `library_circulation`
--

CREATE TABLE `library_circulation` (
  `id` int(10) UNSIGNED NOT NULL,
  `copy_id` int(10) UNSIGNED NOT NULL,
  `book_id` int(10) UNSIGNED NOT NULL,
  `member_id` int(10) UNSIGNED NOT NULL,
  `issued_by` int(10) UNSIGNED NOT NULL,
  `returned_to` int(10) UNSIGNED DEFAULT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `return_date` date DEFAULT NULL,
  `status` enum('Issued','Returned','Overdue','Lost') NOT NULL DEFAULT 'Issued',
  `renewal_count` tinyint(4) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Book circulation records: issue, return, overdue, and lost tracking';

-- --------------------------------------------------------

--
-- Table structure for table `library_dept_collections`
--

CREATE TABLE `library_dept_collections` (
  `id` int(10) UNSIGNED NOT NULL,
  `label` varchar(80) NOT NULL,
  `sub_label` varchar(160) NOT NULL DEFAULT '',
  `icon_class` varchar(80) NOT NULL DEFAULT 'fas fa-book',
  `color_from` varchar(20) NOT NULL DEFAULT '#0f2a6b',
  `color_to` varchar(20) NOT NULL DEFAULT '#1e4db7',
  `image_file` varchar(255) NOT NULL DEFAULT '',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `library_digital_resources`
--

CREATE TABLE `library_digital_resources` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(300) NOT NULL,
  `resource_type` enum('E-Book','Journal','Research Paper','Thesis','Dissertation','Other') NOT NULL DEFAULT 'E-Book',
  `author` varchar(255) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `pub_year` year(4) DEFAULT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `description` text DEFAULT NULL,
  `file_name` varchar(255) NOT NULL COMMENT 'Server-stored filename',
  `original_name` varchar(255) NOT NULL COMMENT 'Original upload filename',
  `mime_type` varchar(100) NOT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT 0 COMMENT 'File size in bytes',
  `access_level` enum('Public','Students','Faculty','Staff','Admin') NOT NULL DEFAULT 'Students',
  `cover_image` varchar(255) DEFAULT NULL,
  `download_count` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `uploaded_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Digital resources: e-books, journals, theses, dissertations';

-- --------------------------------------------------------

--
-- Table structure for table `library_facilities`
--

CREATE TABLE `library_facilities` (
  `id` int(10) UNSIGNED NOT NULL,
  `icon_class` varchar(80) NOT NULL DEFAULT 'fas fa-star',
  `name` varchar(120) NOT NULL,
  `description` varchar(400) NOT NULL DEFAULT '',
  `icon_bg_color` varchar(20) NOT NULL DEFAULT '#f9e8eb',
  `icon_text_color` varchar(20) NOT NULL DEFAULT '#b5182e',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `library_fines`
--

CREATE TABLE `library_fines` (
  `id` int(10) UNSIGNED NOT NULL,
  `circulation_id` int(10) UNSIGNED DEFAULT NULL,
  `member_id` int(10) UNSIGNED NOT NULL,
  `fine_type` enum('Late','Lost','Damaged','Other') NOT NULL DEFAULT 'Late',
  `amount` decimal(10,2) NOT NULL,
  `days_overdue` smallint(6) DEFAULT NULL,
  `status` enum('Unpaid','Paid','Waived') NOT NULL DEFAULT 'Unpaid',
  `paid_at` datetime DEFAULT NULL,
  `collected_by` int(10) UNSIGNED DEFAULT NULL,
  `receipt_number` varchar(60) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Fine records for late returns, lost books, and damages';

-- --------------------------------------------------------

--
-- Table structure for table `library_librarians`
--

CREATE TABLE `library_librarians` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `designation` varchar(150) NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `room_number` varchar(50) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Library staff and librarian profiles';

-- --------------------------------------------------------

--
-- Table structure for table `library_members`
--

CREATE TABLE `library_members` (
  `id` int(10) UNSIGNED NOT NULL,
  `member_type` enum('Student','Faculty','Staff') NOT NULL DEFAULT 'Student',
  `student_id` int(10) UNSIGNED DEFAULT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `member_code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `dept_id` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `joined_at` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Library membership records (students, faculty, staff)';

-- --------------------------------------------------------

--
-- Table structure for table `library_notifications`
--

CREATE TABLE `library_notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `member_id` int(10) UNSIGNED NOT NULL,
  `notification_type` enum('DueReminder','OverdueAlert','ReservationAvailable','FineAlert','General') NOT NULL DEFAULT 'General',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='In-system notifications for library members';

-- --------------------------------------------------------

--
-- Table structure for table `library_reservations`
--

CREATE TABLE `library_reservations` (
  `id` int(10) UNSIGNED NOT NULL,
  `book_id` int(10) UNSIGNED NOT NULL,
  `member_id` int(10) UNSIGNED NOT NULL,
  `reserved_by` int(10) UNSIGNED NOT NULL,
  `status` enum('Pending','Available','Fulfilled','Cancelled','Expired') NOT NULL DEFAULT 'Pending',
  `reserved_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL COMMENT 'Auto-calculated: reserved_at + 48 hours',
  `notified_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Book reservation and hold queue management';

-- --------------------------------------------------------

--
-- Table structure for table `library_settings`
--

CREATE TABLE `library_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Key-value configuration store for library settings';

-- --------------------------------------------------------

--
-- Table structure for table `ll_notices`
--

CREATE TABLE `ll_notices` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(400) NOT NULL,
  `body` mediumtext DEFAULT NULL,
  `notice_date` date DEFAULT NULL,
  `category` enum('notice','circular','policy','announcement') NOT NULL DEFAULT 'notice',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ll_services`
--

CREATE TABLE `ll_services` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(300) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(100) NOT NULL DEFAULT 'fas fa-gavel',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ll_settings`
--

CREATE TABLE `ll_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ll_staff`
--

CREATE TABLE `ll_staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `designation` varchar(200) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mc_appointments`
--

CREATE TABLE `mc_appointments` (
  `id` int(11) NOT NULL,
  `patient_name` varchar(200) NOT NULL,
  `patient_type` enum('student','faculty','staff','officer') NOT NULL DEFAULT 'student',
  `patient_id_no` varchar(50) DEFAULT NULL,
  `department` varchar(200) DEFAULT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(200) DEFAULT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `chief_complaint` text DEFAULT NULL,
  `status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `token_number` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mc_health_tips`
--

CREATE TABLE `mc_health_tips` (
  `id` int(11) NOT NULL,
  `title` varchar(300) NOT NULL,
  `content` text NOT NULL,
  `category` varchar(100) DEFAULT 'General',
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mc_medicines`
--

CREATE TABLE `mc_medicines` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `generic_name` varchar(200) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `unit` varchar(50) DEFAULT 'tablet',
  `quantity_in_stock` int(11) NOT NULL DEFAULT 0,
  `reorder_level` int(11) NOT NULL DEFAULT 10,
  `supplier` varchar(200) DEFAULT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mc_prescriptions`
--

CREATE TABLE `mc_prescriptions` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `patient_name` varchar(200) NOT NULL,
  `patient_type` enum('student','faculty','staff','officer') NOT NULL DEFAULT 'student',
  `patient_id_no` varchar(50) DEFAULT NULL,
  `department` varchar(200) DEFAULT NULL,
  `age` varchar(20) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `medicines_json` text DEFAULT NULL COMMENT 'JSON array of prescribed medicines',
  `advice` text DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `prescription_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mc_schedules`
--

CREATE TABLE `mc_schedules` (
  `id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL COMMENT '0=Sun,1=Mon,...,6=Sat',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `max_slots` int(11) NOT NULL DEFAULT 10,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mc_settings`
--

CREATE TABLE `mc_settings` (
  `id` int(11) NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `modules`
--

CREATE TABLE `modules` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(100) DEFAULT 'fas fa-circle',
  `parent_id` int(10) UNSIGNED DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `can_view` tinyint(1) DEFAULT 1,
  `can_create` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_delete` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notice_documents`
--

CREATE TABLE `notice_documents` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `document_file` varchar(255) NOT NULL COMMENT 'Stored filename of the uploaded PDF or image',
  `original_name` varchar(255) NOT NULL,
  `document_type` enum('pdf','image') NOT NULL DEFAULT 'pdf',
  `created_by` int(10) UNSIGNED NOT NULL,
  `status` enum('draft','active','completed') NOT NULL DEFAULT 'draft',
  `completed_at` datetime DEFAULT NULL,
  `fm_file_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Link to file_manager_files once completed',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notice_signatures`
--

CREATE TABLE `notice_signatures` (
  `id` int(10) UNSIGNED NOT NULL,
  `document_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `position_id` int(10) UNSIGNED DEFAULT NULL,
  `signed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notice_sign_positions`
--

CREATE TABLE `notice_sign_positions` (
  `id` int(10) UNSIGNED NOT NULL,
  `document_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `page_num` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `x_percent` decimal(6,3) NOT NULL DEFAULT 0.000 COMMENT 'Left offset as % of document width',
  `y_percent` decimal(6,3) NOT NULL DEFAULT 0.000 COMMENT 'Top offset as % of document height',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_settings`
--

CREATE TABLE `op_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_staff`
--

CREATE TABLE `op_staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `email_1` varchar(255) NOT NULL DEFAULT '',
  `email_2` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(255) NOT NULL DEFAULT '',
  `photo` varchar(255) NOT NULL DEFAULT '',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pages`
--

CREATE TABLE `pages` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `category` enum('general','profile','policy') NOT NULL DEFAULT 'general',
  `page_heading` varchar(255) DEFAULT NULL COMMENT 'Optional banner/section heading',
  `page_intro` text DEFAULT NULL COMMENT 'Introductory paragraph shown below heading',
  `meta_description` text DEFAULT NULL,
  `featured_image` varchar(500) DEFAULT NULL,
  `gjs_html` longtext DEFAULT NULL,
  `gjs_css` longtext DEFAULT NULL,
  `gjs_data` longtext DEFAULT NULL COMMENT 'GrapesJS component JSON (for re-editing)',
  `content` longtext DEFAULT NULL COMMENT 'TinyMCE HTML content for policy pages',
  `policy_type` varchar(100) DEFAULT NULL COMMENT 'e.g. Privacy Policy, Terms of Use',
  `effective_date` date DEFAULT NULL,
  `profile_subtitle` varchar(150) DEFAULT NULL COMMENT 'Subtitle badge label e.g. "Leadership"',
  `is_published` tinyint(1) DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `page_profiles`
--

CREATE TABLE `page_profiles` (
  `id` int(10) UNSIGNED NOT NULL,
  `page_id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `designation` varchar(150) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `photo` varchar(500) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `is_featured` tinyint(1) DEFAULT 0 COMMENT '1 = highlighted card (chairman-style)',
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(191) NOT NULL,
  `token` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `policy_procedure_sections`
--

CREATE TABLE `policy_procedure_sections` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `popup_settings`
--

CREATE TABLE `popup_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `portal_global_notice`
--

CREATE TABLE `portal_global_notice` (
  `id` int(10) UNSIGNED NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `notice_type` enum('info','warning','danger','success') NOT NULL DEFAULT 'warning',
  `title` varchar(255) DEFAULT NULL,
  `message` text NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `program_eligibility_criteria`
--

CREATE TABLE `program_eligibility_criteria` (
  `id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED NOT NULL,
  `category` varchar(150) NOT NULL DEFAULT 'General' COMMENT 'e.g. Academic, English Proficiency',
  `criterion` text NOT NULL COMMENT 'The requirement text',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `program_intake_periods`
--

CREATE TABLE `program_intake_periods` (
  `id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED NOT NULL,
  `intake_name` varchar(200) NOT NULL COMMENT 'e.g. Spring 2025',
  `open_date` date DEFAULT NULL,
  `close_date` date DEFAULT NULL,
  `intake_status` enum('open','upcoming','closed') NOT NULL DEFAULT 'upcoming',
  `notes` text DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pvc_settings`
--

CREATE TABLE `pvc_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reg_settings`
--

CREATE TABLE `reg_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reg_staff`
--

CREATE TABLE `reg_staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `email_1` varchar(255) NOT NULL DEFAULT '',
  `email_2` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(255) NOT NULL DEFAULT '',
  `photo` varchar(255) NOT NULL DEFAULT '',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `result_exams`
--

CREATE TABLE `result_exams` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → dept_departments.id',
  `program_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → dept_academic_programs.id',
  `batch` varchar(50) DEFAULT NULL COMMENT 'e.g. 52nd',
  `enrollment_semester` varchar(50) DEFAULT NULL COMMENT 'e.g. Fall-2019',
  `completion_semester` varchar(50) DEFAULT NULL COMMENT 'e.g. Summer-2023',
  `exam_title` varchar(300) NOT NULL COMMENT 'Display title, e.g. Foundation Courses Result',
  `exam_level` varchar(100) DEFAULT NULL COMMENT 'e.g. Foundation Courses, Year 1',
  `notes` text DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = visible to students',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Result exam/session header';

-- --------------------------------------------------------

--
-- Table structure for table `result_grades`
--

CREATE TABLE `result_grades` (
  `id` int(10) UNSIGNED NOT NULL,
  `exam_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → result_exams.id',
  `subject_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → result_subjects.id',
  `student_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → students.id',
  `student_sid` varchar(25) NOT NULL COMMENT 'Student ID string (e.g. 193020101021)',
  `student_name` varchar(200) DEFAULT NULL COMMENT 'Snapshot from students.full_name',
  `marks` decimal(5,2) DEFAULT NULL COMMENT 'Numerical marks (0–100)',
  `letter_grade` varchar(10) DEFAULT NULL COMMENT 'e.g. A+, B-',
  `grade_point` decimal(4,2) DEFAULT NULL COMMENT 'e.g. 4.00, 3.25',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `marked_by` varchar(200) DEFAULT NULL COMMENT 'Name of person who entered the marks',
  `reviewed_by` varchar(200) DEFAULT NULL COMMENT 'Name of reviewer',
  `approved_by` varchar(200) DEFAULT NULL COMMENT 'Name of approver'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Student grades per subject per exam';

-- --------------------------------------------------------

--
-- Table structure for table `result_grade_details`
--

CREATE TABLE `result_grade_details` (
  `id` int(10) UNSIGNED NOT NULL,
  `grade_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → result_grades.id',
  `category_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → result_mark_categories.id',
  `marks_obtained` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Marks obtained in this category',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-category marks for each student grade row';

-- --------------------------------------------------------

--
-- Table structure for table `result_mark_categories`
--

CREATE TABLE `result_mark_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `subject_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → result_subjects.id',
  `category_name` varchar(100) NOT NULL COMMENT 'e.g. Attendance, Class Test, Mid Term, Final',
  `max_marks` decimal(5,2) NOT NULL DEFAULT 100.00 COMMENT 'Maximum marks allocated to this category',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Marking category breakdown per subject';

-- --------------------------------------------------------

--
-- Table structure for table `result_mark_sheets`
--

CREATE TABLE `result_mark_sheets` (
  `id` int(10) UNSIGNED NOT NULL,
  `chain_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → wf_chains.id',
  `current_step_order` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Step awaiting action',
  `dept_id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED DEFAULT NULL,
  `semester` varchar(100) NOT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `curriculum_id` int(10) UNSIGNED DEFAULT NULL,
  `subject_code` varchar(50) DEFAULT NULL,
  `subject_title` varchar(300) NOT NULL,
  `credits` decimal(4,2) DEFAULT NULL,
  `workflow_status` enum('draft','pending','returned','published') NOT NULL DEFAULT 'draft',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Workflow mark sheet header (one per subject per semester)';

-- --------------------------------------------------------

--
-- Table structure for table `result_sheet_grades`
--

CREATE TABLE `result_sheet_grades` (
  `id` int(10) UNSIGNED NOT NULL,
  `sheet_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED DEFAULT NULL,
  `student_sid` varchar(25) NOT NULL,
  `student_name` varchar(200) DEFAULT NULL,
  `is_absent` tinyint(1) NOT NULL DEFAULT 0,
  `attendance` decimal(5,2) DEFAULT NULL,
  `class_test` decimal(5,2) DEFAULT NULL,
  `mid_term` decimal(5,2) DEFAULT NULL,
  `final_exam` decimal(5,2) DEFAULT NULL,
  `marks_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of mark values by distribution index [val0, val1, ...]; takes precedence over legacy columns when set' CHECK (json_valid(`marks_json`)),
  `absent_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON boolean array of per-segment absent flags [false,false,true,...]; null means no per-segment absents' CHECK (json_valid(`absent_json`)),
  `total_marks` decimal(5,2) DEFAULT NULL,
  `letter_grade` varchar(10) DEFAULT NULL,
  `grade_point` decimal(4,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `result_subjects`
--

CREATE TABLE `result_subjects` (
  `id` int(10) UNSIGNED NOT NULL,
  `exam_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → result_exams.id',
  `curriculum_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → course_curriculum.id (optional)',
  `course_code` varchar(50) DEFAULT NULL COMMENT 'e.g. BEL-111',
  `course_title` varchar(300) NOT NULL COMMENT 'e.g. English Reading Skills',
  `credits` decimal(4,2) DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Subjects/courses in a result exam';

-- --------------------------------------------------------

--
-- Table structure for table `sa_settings`
--

CREATE TABLE `sa_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sa_staff`
--

CREATE TABLE `sa_staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `email_1` varchar(255) NOT NULL DEFAULT '',
  `email_2` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(255) NOT NULL DEFAULT '',
  `photo` varchar(255) NOT NULL DEFAULT '',
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sc_awards`
--

CREATE TABLE `sc_awards` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `policy_id` int(10) UNSIGNED NOT NULL,
  `tier_id` int(10) UNSIGNED DEFAULT NULL,
  `semester` varchar(50) NOT NULL COMMENT 'Semester the award applies to, e.g. Fall 2025',
  `gpa_used` decimal(5,2) DEFAULT NULL COMMENT 'The GPA value used to determine the tier',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','revoked') NOT NULL DEFAULT 'active',
  `note` text DEFAULT NULL,
  `awarded_by` int(10) UNSIGNED DEFAULT NULL,
  `awarded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_by` int(10) UNSIGNED DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sc_policies`
--

CREATE TABLE `sc_policies` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `type` enum('gpa_based','merit_based','flat') NOT NULL DEFAULT 'gpa_based',
  `description` text DEFAULT NULL,
  `applies_to_fixed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = policy discount also covers fixed institutional fees',
  `applies_to_english` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = policy discount also covers English course fee',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sc_settings`
--

CREATE TABLE `sc_settings` (
  `id` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `gpa_label` varchar(100) NOT NULL DEFAULT 'SSC+HSC Combined GPA',
  `max_combined_gpa` decimal(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Max possible combined GPA (e.g. 5+5=10)',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sc_tiers`
--

CREATE TABLE `sc_tiers` (
  `id` int(10) UNSIGNED NOT NULL,
  `policy_id` int(10) UNSIGNED NOT NULL,
  `label` varchar(100) DEFAULT NULL COMMENT 'e.g. Gold, Silver',
  `min_gpa` decimal(5,2) DEFAULT NULL COMMENT 'NULL for flat-type policies',
  `max_gpa` decimal(5,2) DEFAULT NULL COMMENT 'NULL for flat-type policies',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `seo_entries`
--

CREATE TABLE `seo_entries` (
  `id` int(10) UNSIGNED NOT NULL,
  `page_type` varchar(50) NOT NULL COMMENT 'home|page|department|faculty|news|notice|job|club|gallery|static',
  `page_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK to the referenced record (nullable for home/static)',
  `page_url` varchar(500) NOT NULL COMMENT 'Relative URL e.g. /index.php or /department.php?slug=cse',
  `page_label` varchar(255) NOT NULL COMMENT 'Human-readable name e.g. "Home Page", "CSE Department"',
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `meta_keywords` varchar(500) DEFAULT NULL,
  `og_title` varchar(255) DEFAULT NULL,
  `og_description` text DEFAULT NULL,
  `og_image` varchar(500) DEFAULT NULL COMMENT 'Relative path or full URL',
  `canonical_url` varchar(500) DEFAULT NULL,
  `robots` varchar(100) DEFAULT 'index,follow',
  `schema_json` longtext DEFAULT NULL COMMENT 'Structured data JSON-LD',
  `custom_head_code` text DEFAULT NULL COMMENT 'Raw HTML injected into <head>',
  `sitemap_include` tinyint(1) NOT NULL DEFAULT 1,
  `sitemap_priority` decimal(2,1) NOT NULL DEFAULT 0.5,
  `sitemap_changefreq` enum('always','hourly','daily','weekly','monthly','yearly','never') NOT NULL DEFAULT 'weekly',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `seo_settings`
--

CREATE TABLE `seo_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `label` varchar(255) DEFAULT NULL,
  `group` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sfp_packages`
--

CREATE TABLE `sfp_packages` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `cf_program_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Source cf_programs.id for reference; nullable on program deletion',
  `program_name` varchar(200) NOT NULL,
  `payment_type` enum('merit','fixed') NOT NULL DEFAULT 'merit' COMMENT 'merit = calculated monthly fee; fixed = flat monthly_payment that never changes automatically',
  `monthly_payment` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Flat agreed monthly fee, used only when payment_type = fixed',
  `total_semesters` tinyint(3) UNSIGNED NOT NULL,
  `total_months` smallint(5) UNSIGNED NOT NULL,
  `months_per_semester` decimal(6,2) NOT NULL COMMENT 'total_months / total_semesters',
  `bi_semester_start_month` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Snapshotted bi-semester start month from cf_programs',
  `tri_semester_start_month` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Snapshotted tri-semester start month from cf_programs',
  `standard_tuition_full` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `tuition_per_semester` decimal(10,2) NOT NULL DEFAULT 0.00,
  `admission_fees` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'One-time admission day cost; already paid separately – stored for reference',
  `fixed_institutional_fees` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `english_course_fee` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `reg_fee_per_semester` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Per-semester registration fee (snapshotted from cf_settings)',
  `form_id_fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'One-time form & ID card fee (snapshotted from cf_settings)',
  `safety_net_cap` int(10) UNSIGNED DEFAULT NULL,
  `safety_net_per_semester` decimal(10,2) DEFAULT NULL,
  `attendance_requirement` tinyint(3) UNSIGNED NOT NULL DEFAULT 70,
  `safety_net_gpa_threshold` decimal(4,2) NOT NULL DEFAULT 3.00,
  `monthly_fixed_fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'fixed_institutional_fees / total_months',
  `monthly_english_fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'english_course_fee / total_months',
  `note` text DEFAULT NULL,
  `assigned_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Snapshotted fee package assigned to a student';

-- --------------------------------------------------------

--
-- Table structure for table `sfp_payments`
--

CREATE TABLE `sfp_payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL COMMENT 'FK students.id',
  `package_id` int(10) UNSIGNED NOT NULL COMMENT 'FK sfp_packages.id',
  `semester_fee_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK sfp_semester_fees.id – NULL for admission/registration/other',
  `fee_type` enum('admission','form_fee','id_card_fee','registration','semester_tuition','fixed_fee','english_fee','retake_fee','improvement_fee','special_exam_midterm','special_exam_final','other') NOT NULL,
  `semester_number` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Semester number (1-based) – mirrors semester_fee_id.semester_number for easy filtering',
  `month_number` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Month within the semester (1-based); NULL for non-monthly or legacy payments',
  `payment_method` enum('cash','bank','mobile_banking') NOT NULL DEFAULT 'cash' COMMENT 'How payment was received',
  `mobile_banking_provider` enum('bkash','nagad','rocket') DEFAULT NULL COMMENT 'Provider when payment_method=mobile_banking',
  `transaction_number` varchar(100) DEFAULT NULL COMMENT 'External transaction/challan/reference number for non-cash payments',
  `amount` decimal(10,2) NOT NULL COMMENT 'Amount actually received in this payment',
  `voucher_id` int(10) UNSIGNED NOT NULL COMMENT 'FK acc_vouchers.id – the receipt voucher that recorded the money',
  `note` text DEFAULT NULL,
  `collected_by` int(10) UNSIGNED DEFAULT NULL,
  `collected_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Actual student fee payments; each row is backed by an acc_vouchers receipt';

-- --------------------------------------------------------

--
-- Table structure for table `sfp_semester_fees`
--

CREATE TABLE `sfp_semester_fees` (
  `id` int(10) UNSIGNED NOT NULL,
  `package_id` int(10) UNSIGNED NOT NULL,
  `semester_number` tinyint(3) UNSIGNED NOT NULL COMMENT '1-based index within the programme',
  `semester_label` varchar(50) DEFAULT NULL COMMENT 'e.g. "Summer 2026" – set by admin',
  `tuition_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `scholarship_award_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK sc_awards.id – for traceability when linked to a formal award',
  `scholarship_discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `scholarship_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tuition_payable` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'tuition_fee − scholarship_amount',
  `fixed_discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Aggregate discount applied to fixed institutional fee this semester',
  `english_discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Aggregate discount applied to English course fee this semester',
  `note` text DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-semester fee records for a student fee package';

-- --------------------------------------------------------

--
-- Table structure for table `sfp_semester_scholarships`
--

CREATE TABLE `sfp_semester_scholarships` (
  `id` int(10) UNSIGNED NOT NULL,
  `sf_id` int(10) UNSIGNED NOT NULL COMMENT 'FK sfp_semester_fees.id',
  `label` varchar(200) NOT NULL COMMENT 'Scholarship type label, e.g. "Initial Waiver", "Sports", "Freedom Fighter"',
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Percentage of tuition_fee to waive',
  `discount_type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage' COMMENT 'percentage = percentage-based; fixed = fixed BDT amount',
  `fixed_amount` decimal(10,2) DEFAULT NULL COMMENT 'For fixed-type scholarships: the BDT amount entered by admin',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Calculated: tuition_fee * discount_pct / 100 at time of creation',
  `note` text DEFAULT NULL,
  `is_from_policy` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = created via sc_policies quick-fill; 0 = manual entry',
  `applies_to_fixed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = discount also applies to fixed institutional fee',
  `applies_to_english` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = discount also applies to English course fee',
  `support_doc_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK student_files.id – mandatory for manual (non-policy) scholarships',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Individual scholarship entries per semester; totals aggregated into sfp_semester_fees';

-- --------------------------------------------------------

--
-- Table structure for table `sr_results`
--

CREATE TABLE `sr_results` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(300) NOT NULL COMMENT 'e.g. Spring 2026 Result',
  `semester` varchar(100) DEFAULT NULL COMMENT 'e.g. Spring 2026',
  `description` text DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = visible on public page',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Result sets (one per semester/occasion)';

-- --------------------------------------------------------

--
-- Table structure for table `sr_result_entries`
--

CREATE TABLE `sr_result_entries` (
  `id` int(10) UNSIGNED NOT NULL,
  `result_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → sr_results.id',
  `student_id` varchar(50) NOT NULL COMMENT 'Student ID string',
  `student_name` varchar(200) DEFAULT NULL,
  `course_code` varchar(50) DEFAULT NULL,
  `course_title` varchar(300) NOT NULL,
  `letter_grade` varchar(10) NOT NULL COMMENT 'e.g. A+, B-',
  `grade_point` decimal(4,2) DEFAULT NULL COMMENT 'e.g. 4.00, 3.25',
  `credit` decimal(4,2) DEFAULT NULL COMMENT 'Credit hours for the course',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Individual student course grade rows';

-- --------------------------------------------------------

--
-- Table structure for table `staff_departments`
--

CREATE TABLE `staff_departments` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `type` enum('administrative','educational') NOT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `dept_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'For educational type: links to dept_departments.id'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_profiles`
--

CREATE TABLE `staff_profiles` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `employee_id` varchar(100) DEFAULT NULL,
  `department_type` enum('administrative','educational') DEFAULT NULL,
  `staff_dept_id` int(10) UNSIGNED DEFAULT NULL,
  `designation` varchar(200) DEFAULT NULL,
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_relation` varchar(100) DEFAULT NULL,
  `emergency_contact_address` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(10) UNSIGNED NOT NULL,
  `portal_user_id` int(11) DEFAULT NULL COMMENT 'FK to users.id – the portal user account for this student',
  `student_id` varchar(20) NOT NULL COMMENT '12-digit auto-generated or manual ID',
  `dept_id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED DEFAULT NULL,
  `admitted_semester` varchar(50) NOT NULL COMMENT 'e.g. Summer 2025',
  `semester_type` varchar(30) DEFAULT NULL COMMENT 'Semester system: bi_semester or trimester',
  `batch` varchar(50) DEFAULT NULL COMMENT 'Intake batch, e.g. 35th',
  `batch_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK student_batches.id',
  `year` varchar(10) DEFAULT NULL COMMENT 'Enrollment/academic year e.g. 2025',
  `shift` varchar(25) DEFAULT NULL COMMENT 'Day / Evening / Morning',
  `section` varchar(5) DEFAULT NULL COMMENT 'Class section, e.g. A, B, C, D, E, F, G',
  `poor_meritorious` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = poor/meritorious quota',
  `freedom_fighter_quota` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = freedom fighter family quota',
  `waiver_percent` varchar(10) DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `father_name` varchar(200) DEFAULT NULL,
  `father_phone` varchar(30) DEFAULT NULL,
  `father_occupation` varchar(200) DEFAULT NULL,
  `father_yearly_income` decimal(15,2) DEFAULT NULL,
  `mother_name` varchar(200) DEFAULT NULL,
  `mother_phone` varchar(30) DEFAULT NULL,
  `mother_occupation` varchar(200) DEFAULT NULL,
  `mother_yearly_income` decimal(15,2) DEFAULT NULL,
  `guardian_name` varchar(200) DEFAULT NULL,
  `guardian_profession` varchar(200) DEFAULT NULL,
  `guardian_address` text DEFAULT NULL,
  `guardian_phone` varchar(30) DEFAULT NULL,
  `guardian_relationship` varchar(100) DEFAULT NULL,
  `reference_name` varchar(200) DEFAULT NULL,
  `reference_address` text DEFAULT NULL,
  `reference_contact` varchar(30) DEFAULT NULL,
  `reference_email` varchar(200) DEFAULT NULL,
  `local_guardian_name` varchar(200) DEFAULT NULL,
  `local_guardian_contact` varchar(30) DEFAULT NULL,
  `local_guardian_address` text DEFAULT NULL,
  `local_guardian_email` varchar(200) DEFAULT NULL,
  `present_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `district_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK bd_districts.id',
  `thana_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK bd_thanas.id',
  `faculty_label` varchar(200) DEFAULT NULL COMMENT 'Cached dept faculty_label at time of registration',
  `nationality` varchar(100) DEFAULT NULL,
  `country` varchar(100) NOT NULL DEFAULT 'Bangladesh',
  `email` varchar(200) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `dob` date DEFAULT NULL COMMENT 'Date of birth',
  `blood_group` varchar(10) DEFAULT NULL,
  `nid` varchar(50) DEFAULT NULL COMMENT 'National ID number',
  `passport_no` varchar(100) DEFAULT NULL COMMENT 'Passport number (optional)',
  `place_of_birth` varchar(200) DEFAULT NULL,
  `marital_status` varchar(50) DEFAULT NULL COMMENT 'e.g. Single, Married, Divorced, Widowed',
  `sex` enum('Male','Female','Other') DEFAULT NULL,
  `religion` varchar(100) DEFAULT NULL,
  `photo` varchar(300) DEFAULT NULL,
  `status` enum('Active','Inactive','Graduated','Dropped','Not Admitted Yet') NOT NULL DEFAULT 'Active',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → users.id – portal account for this student',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `form_fee` int(11) DEFAULT NULL,
  `regi_fee` int(11) DEFAULT NULL,
  `tuition_fee` int(11) DEFAULT NULL,
  `misc_fee` varchar(50) DEFAULT NULL,
  `project_fee` int(11) DEFAULT NULL,
  `total_fee` int(11) DEFAULT NULL,
  `waiver_amount` int(11) DEFAULT NULL,
  `waiver_courses` text DEFAULT NULL COMMENT 'JSON array of waiver course objects imported from old system',
  `total_waiver_credits` decimal(5,2) DEFAULT NULL COMMENT 'Total credit hours waived',
  `certificate_map` text DEFAULT NULL COMMENT 'JSON array [{exam, filename}] – maps exam name to certificate file for bulk file import',
  `total_payable` varchar(50) DEFAULT NULL,
  `monthly_installment` varchar(50) DEFAULT NULL,
  `ref_number` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_academic_qualifications`
--

CREATE TABLE `student_academic_qualifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `exam_name` varchar(200) DEFAULT NULL COMMENT 'e.g. SSC, HSC, B.Sc.',
  `exam_title_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK student_exam_titles.id',
  `session` varchar(100) DEFAULT NULL,
  `group_name` varchar(100) DEFAULT NULL,
  `group_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK student_groups.id',
  `board_university` varchar(200) DEFAULT NULL,
  `board_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK student_boards.id',
  `passing_year` varchar(20) DEFAULT NULL,
  `division_class_grade` varchar(100) DEFAULT NULL,
  `obtained_marks_gpa` varchar(100) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_batches`
--

CREATE TABLE `student_batches` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_boards`
--

CREATE TABLE `student_boards` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `short_name` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_comments`
--

CREATE TABLE `student_comments` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `comment` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_enrollment_status`
--

CREATE TABLE `student_enrollment_status` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` varchar(30) NOT NULL,
  `photo` varchar(255) DEFAULT NULL COMMENT 'Filename only; resolved via admin/uploads/students/photos/',
  `full_name` varchar(150) NOT NULL,
  `department` varchar(150) NOT NULL,
  `program` varchar(150) NOT NULL,
  `batch` varchar(50) NOT NULL,
  `enrollment_status` enum('Active','On Leave','Completed','Dropped') NOT NULL DEFAULT 'Active',
  `current_semester` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `total_semesters` tinyint(3) UNSIGNED NOT NULL DEFAULT 12,
  `cgpa` decimal(4,2) DEFAULT NULL COMMENT 'Calculated on completed credits only',
  `completed_credits` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `total_credits` smallint(5) UNSIGNED NOT NULL DEFAULT 144,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Standalone student enrollment status records (public-facing)';

-- --------------------------------------------------------

--
-- Table structure for table `student_exam_titles`
--

CREATE TABLE `student_exam_titles` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `short_name` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_files`
--

CREATE TABLE `student_files` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `stored_name` varchar(300) NOT NULL,
  `original_name` varchar(300) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_groups`
--

CREATE TABLE `student_groups` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_pdf_pending`
--

CREATE TABLE `student_pdf_pending` (
  `id` int(10) UNSIGNED NOT NULL,
  `batch_token` varchar(64) NOT NULL COMMENT 'Groups PDFs from one upload session',
  `original_name` varchar(300) NOT NULL COMMENT 'Original filename as uploaded',
  `stored_name` varchar(300) NOT NULL COMMENT 'Randomly-named file in uploads/students/pending/',
  `file_size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `extracted_text` mediumtext DEFAULT NULL COMMENT 'Text extracted from PDF',
  `candidate_ids` text DEFAULT NULL COMMENT 'JSON array of digit sequences found in text',
  `file_label` varchar(200) NOT NULL DEFAULT 'Student Document',
  `description` text DEFAULT NULL,
  `status` enum('pending','assigned','dismissed') NOT NULL DEFAULT 'pending',
  `assigned_student_pk` int(10) UNSIGNED DEFAULT NULL COMMENT 'students.id after manual assignment',
  `uploaded_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PDFs awaiting manual student assignment after Smart Upload';

-- --------------------------------------------------------

--
-- Table structure for table `student_portal_log`
--

CREATE TABLE `student_portal_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL DEFAULT 'created',
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `sms_sent` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_portal_settings`
--

CREATE TABLE `student_portal_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_push_tokens`
--

CREATE TABLE `student_push_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT 'users.id (student portal account)',
  `fcm_token` text NOT NULL,
  `device_id` varchar(255) DEFAULT NULL,
  `platform` enum('android','ios') NOT NULL DEFAULT 'android',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_results`
--

CREATE TABLE `student_results` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK students.id – NULL when SID unmatched',
  `student_sid` varchar(25) DEFAULT NULL COMMENT 'Original student SID',
  `student_name` varchar(100) DEFAULT NULL,
  `batch` varchar(20) DEFAULT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `semester_year` varchar(20) DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL,
  `program` varchar(50) DEFAULT NULL,
  `level` varchar(30) DEFAULT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `subject_code` varchar(30) DEFAULT NULL,
  `grade` varchar(20) DEFAULT NULL,
  `credits` varchar(20) DEFAULT NULL,
  `gpa` varchar(11) DEFAULT NULL,
  `cgpa` varchar(15) DEFAULT NULL,
  `subject_code1` varchar(20) DEFAULT NULL,
  `grade1` varchar(10) DEFAULT NULL,
  `credits1` varchar(10) DEFAULT NULL,
  `gpa1` varchar(10) DEFAULT NULL,
  `subject_code2` varchar(20) DEFAULT NULL,
  `grade2` varchar(10) DEFAULT NULL,
  `credits2` varchar(10) DEFAULT NULL,
  `gpa2` varchar(10) DEFAULT NULL,
  `subject_code3` varchar(20) DEFAULT NULL,
  `grade3` varchar(10) DEFAULT NULL,
  `credits3` varchar(10) DEFAULT NULL,
  `gpa3` varchar(10) DEFAULT NULL,
  `subject_code4` varchar(20) DEFAULT NULL,
  `grade4` varchar(10) DEFAULT NULL,
  `credits4` varchar(10) DEFAULT NULL,
  `gpa4` varchar(10) DEFAULT NULL,
  `recorded_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Semester result records – linked to students via student_id';

-- --------------------------------------------------------

--
-- Table structure for table `student_verifications`
--

CREATE TABLE `student_verifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → students.id',
  `verified_by` int(10) UNSIGNED NOT NULL COMMENT 'FK → users.id',
  `student_data_ok` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=details match presented documents, 0=data mismatch',
  `student_data_issues` text DEFAULT NULL COMMENT 'Description of student data mismatch (Step 1)',
  `cert_transcript_ok` tinyint(1) NOT NULL DEFAULT 0,
  `cert_transcript_issues` text DEFAULT NULL,
  `admission_form_ok` tinyint(1) NOT NULL DEFAULT 0,
  `admission_form_issues` text DEFAULT NULL,
  `admission_form_file_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → student_files.id (Admission Form)',
  `tabulation_ok` tinyint(1) NOT NULL DEFAULT 0,
  `tabulation_issues` text DEFAULT NULL,
  `tabulation_file_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → student_files.id (Tabulation)',
  `overall_status` enum('Verified','Failed') NOT NULL,
  `verified_pdf` varchar(300) DEFAULT NULL COMMENT 'stored file name in uploads/student-verification/',
  `verifier_email` varchar(200) DEFAULT NULL,
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `email_sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_settings`
--

CREATE TABLE `support_settings` (
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_sla_rules`
--

CREATE TABLE `support_sla_rules` (
  `id` int(10) UNSIGNED NOT NULL,
  `priority` enum('Low','Medium','High','Critical') NOT NULL,
  `hours` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticket_number` varchar(25) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text NOT NULL,
  `category` enum('Hardware','Software','Network','Email','Other') NOT NULL DEFAULT 'Other',
  `priority` enum('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
  `status` enum('Open','In Progress','Pending','Resolved','Closed','Reopened') NOT NULL DEFAULT 'Open',
  `department` varchar(200) DEFAULT NULL,
  `user_type` enum('Student','Faculty','Administrative Employee') DEFAULT NULL,
  `student_id` varchar(20) DEFAULT NULL,
  `student_department` varchar(200) DEFAULT NULL,
  `student_program` varchar(200) DEFAULT NULL,
  `student_batch` varchar(100) DEFAULT NULL,
  `submitter_name` varchar(200) DEFAULT NULL,
  `submitter_email` varchar(255) DEFAULT NULL,
  `submitter_phone` varchar(50) DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `deadline` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_ticket_attachments`
--

CREATE TABLE `support_ticket_attachments` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticket_id` int(10) UNSIGNED NOT NULL,
  `original_name` varchar(500) NOT NULL,
  `stored_name` varchar(120) NOT NULL,
  `mime_type` varchar(200) NOT NULL,
  `file_size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` int(10) UNSIGNED NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_ticket_comments`
--

CREATE TABLE `support_ticket_comments` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticket_id` int(10) UNSIGNED NOT NULL,
  `comment` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_ticket_comment_attachments`
--

CREATE TABLE `support_ticket_comment_attachments` (
  `id` int(10) UNSIGNED NOT NULL,
  `comment_id` int(10) UNSIGNED NOT NULL,
  `original_name` varchar(500) NOT NULL,
  `stored_name` varchar(120) NOT NULL,
  `mime_type` varchar(200) NOT NULL,
  `file_size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_ticket_user_tags`
--

CREATE TABLE `support_ticket_user_tags` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticket_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `tagged_by` int(10) UNSIGNED NOT NULL,
  `tagged_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tr_settings`
--

CREATE TABLE `tr_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'bcrypt hash',
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `student_sid` varchar(50) DEFAULT NULL COMMENT 'Links this admin user to a student record by student_id',
  `avatar` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `signature_file` varchar(255) DEFAULT NULL COMMENT 'PNG signature image uploaded by the user'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_dept_scope`
--

CREATE TABLE `user_dept_scope` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_groups`
--

CREATE TABLE `user_groups` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_super` tinyint(1) DEFAULT 0 COMMENT '1 = super admin group',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_group_assignments`
--

CREATE TABLE `user_group_assignments` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `assigned_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_module_access`
--

CREATE TABLE `user_module_access` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `module_id` int(10) UNSIGNED NOT NULL,
  `can_view` tinyint(1) DEFAULT 1,
  `can_create` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_delete` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vc_former_vcs`
--

CREATE TABLE `vc_former_vcs` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `title` varchar(200) NOT NULL DEFAULT 'Former Vice Chancellor',
  `tenure` varchar(100) NOT NULL DEFAULT '',
  `photo` varchar(500) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vc_scholarship_approvals`
--

CREATE TABLE `vc_scholarship_approvals` (
  `id` int(10) UNSIGNED NOT NULL,
  `package_id` int(10) UNSIGNED NOT NULL COMMENT 'sfp_packages.id',
  `student_id` int(10) UNSIGNED NOT NULL COMMENT 'students.id',
  `sf_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'sfp_semester_fees.id; NULL when apply_to_all=1',
  `apply_to_all` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = applies to every semester in the package',
  `label` varchar(255) NOT NULL,
  `discount_type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `discount_pct` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `fixed_amount` decimal(10,2) DEFAULT NULL,
  `sc_note` text DEFAULT NULL,
  `is_from_policy` tinyint(1) NOT NULL DEFAULT 0,
  `applies_to_fixed` tinyint(1) NOT NULL DEFAULT 0,
  `applies_to_english` tinyint(1) NOT NULL DEFAULT 0,
  `support_doc_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'student_files.id for the supporting document',
  `status` enum('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
  `requested_by` int(10) UNSIGNED NOT NULL COMMENT 'users.id – admin who submitted the request',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'users.id – VC who approved/rejected',
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` text DEFAULT NULL COMMENT 'VC note on approval or rejection',
  `revoked_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'users.id – super-admin who revoked',
  `revoked_at` datetime DEFAULT NULL,
  `revoke_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vc_settings`
--

CREATE TABLE `vc_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` mediumtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wf_chains`
--

CREATE TABLE `wf_chains` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL COMMENT 'Human-readable chain name',
  `description` text DEFAULT NULL,
  `dept_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL = global / all depts',
  `program_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL = all programs in dept',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Named workflow chains – one per dept/program scope';

-- --------------------------------------------------------

--
-- Table structure for table `wf_chain_steps`
--

CREATE TABLE `wf_chain_steps` (
  `id` int(10) UNSIGNED NOT NULL,
  `chain_id` int(10) UNSIGNED NOT NULL,
  `step_order` tinyint(3) UNSIGNED NOT NULL COMMENT 'Ascending: 1, 2, 3…',
  `step_label` varchar(200) NOT NULL COMMENT 'e.g. Course Teacher, HOD',
  `group_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → user_groups.id',
  `is_entry` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = this step submits the sheet',
  `is_final` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = approving this step publishes'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ordered approval steps within a workflow chain';

-- --------------------------------------------------------

--
-- Table structure for table `wf_sheet_history`
--

CREATE TABLE `wf_sheet_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `sheet_id` int(10) UNSIGNED NOT NULL,
  `step_order` tinyint(3) UNSIGNED NOT NULL,
  `step_label` varchar(200) DEFAULT NULL,
  `group_id` int(10) UNSIGNED DEFAULT NULL,
  `action` enum('created','submitted','approved','returned','published') NOT NULL,
  `acted_by` int(10) UNSIGNED DEFAULT NULL,
  `acted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `remarks` text DEFAULT NULL,
  `returned_to_step` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Set on return actions'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail: every workflow action on every mark sheet';

--
-- Indexes for dumped tables
--

--
-- Indexes for table `aa_settings`
--
ALTER TABLE `aa_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `aa_staff`
--
ALTER TABLE `aa_staff`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `acc_accounts`
--
ALTER TABLE `acc_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_acc_code` (`code`),
  ADD KEY `idx_acc_type` (`type`),
  ADD KEY `idx_acc_parent` (`parent_id`);

--
-- Indexes for table `acc_settings`
--
ALTER TABLE `acc_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `acc_vouchers`
--
ALTER TABLE `acc_vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_voucher_number` (`voucher_number`),
  ADD KEY `idx_voucher_type` (`voucher_type`),
  ADD KEY `idx_voucher_date` (`voucher_date`),
  ADD KEY `idx_voucher_status` (`status`),
  ADD KEY `fk_voucher_creator` (`created_by`),
  ADD KEY `fk_voucher_reverser` (`reversed_by`),
  ADD KEY `fk_voucher_reversal` (`reversal_of`);

--
-- Indexes for table `acc_voucher_items`
--
ALTER TABLE `acc_voucher_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vi_voucher` (`voucher_id`),
  ADD KEY `idx_vi_account` (`account_id`);

--
-- Indexes for table `ac_admit_cards`
--
ALTER TABLE `ac_admit_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dept` (`dept_id`),
  ADD KEY `idx_program` (`program_id`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `ac_admit_card_courses`
--
ALTER TABLE `ac_admit_card_courses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admit_card` (`admit_card_id`);

--
-- Indexes for table `ac_student_overrides`
--
ALTER TABLE `ac_student_overrides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_card_student` (`admit_card_id`,`student_id`),
  ADD KEY `idx_student` (`student_id`);

--
-- Indexes for table `ac_student_tokens`
--
ALTER TABLE `ac_student_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_card_student_token` (`admit_card_id`,`student_id`),
  ADD UNIQUE KEY `uq_token` (`token`),
  ADD KEY `idx_student` (`student_id`);

--
-- Indexes for table `admissions_academic_records`
--
ALTER TABLE `admissions_academic_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_adm_app_id` (`application_id`);

--
-- Indexes for table `admissions_applications`
--
ALTER TABLE `admissions_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_app_number` (`app_number`),
  ADD KEY `idx_financial_package` (`financial_package_id`);

--
-- Indexes for table `admissions_fields`
--
ALTER TABLE `admissions_fields`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_field_key` (`field_key`);

--
-- Indexes for table `admissions_field_mappings`
--
ALTER TABLE `admissions_field_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_field_page` (`field_key`,`page_number`);

--
-- Indexes for table `admissions_settings`
--
ALTER TABLE `admissions_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `admissions_templates`
--
ALTER TABLE `admissions_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_page_number` (`page_number`);

--
-- Indexes for table `admissions_top_sheet_programs`
--
ALTER TABLE `admissions_top_sheet_programs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ts_program_id` (`program_id`);

--
-- Indexes for table `adm_admission_fee_payments`
--
ALTER TABLE `adm_admission_fee_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_afp_txn` (`transaction_number`),
  ADD KEY `idx_afp_application` (`application_id`),
  ADD KEY `idx_afp_voucher` (`voucher_id`);

--
-- Indexes for table `adm_form_sales`
--
ALTER TABLE `adm_form_sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_form_number` (`form_number`),
  ADD KEY `fk_fs_application` (`application_id`);

--
-- Indexes for table `adm_form_sale_academic_records`
--
ALTER TABLE `adm_form_sale_academic_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fsar_form_sale` (`form_sale_id`);

--
-- Indexes for table `adm_form_sale_student_details`
--
ALTER TABLE `adm_form_sale_student_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fssd_form_sale` (`form_sale_id`),
  ADD KEY `fk_fssd_token` (`token_id`);

--
-- Indexes for table `adm_form_sale_tokens`
--
ALTER TABLE `adm_form_sale_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fst_token` (`token`),
  ADD KEY `idx_fst_form_sale` (`form_sale_id`);

--
-- Indexes for table `adm_fs_field_mappings`
--
ALTER TABLE `adm_fs_field_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fs_field_key` (`field_key`);

--
-- Indexes for table `adm_fs_templates`
--
ALTER TABLE `adm_fs_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `adm_student_id_settings`
--
ALTER TABLE `adm_student_id_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sid_program` (`program_id`);

--
-- Indexes for table `alumni`
--
ALTER TABLE `alumni`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alumni_dept` (`dept_id`),
  ADD KEY `idx_alumni_status` (`status`);

--
-- Indexes for table `api_push_tokens`
--
ALTER TABLE `api_push_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_push_device` (`user_id`,`device_id`),
  ADD KEY `idx_push_user` (`user_id`);

--
-- Indexes for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_api_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `bd_districts`
--
ALTER TABLE `bd_districts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bd_thanas`
--
ALTER TABLE `bd_thanas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_thana_district` (`district_id`);

--
-- Indexes for table `broadcasts`
--
ALTER TABLE `broadcasts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sent_by` (`sent_by`),
  ADD KEY `idx_recipient` (`recipient_type`),
  ADD KEY `recipient_user_id` (`recipient_user_id`),
  ADD KEY `recipient_group_id` (`recipient_group_id`),
  ADD KEY `fk_bc_student_dept` (`student_dept_id`),
  ADD KEY `fk_bc_student_program` (`student_program_id`),
  ADD KEY `fk_bc_reviewed_by` (`reviewed_by`);

--
-- Indexes for table `broadcast_attachments`
--
ALTER TABLE `broadcast_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_broadcast` (`broadcast_id`);

--
-- Indexes for table `broadcast_recipients`
--
ALTER TABLE `broadcast_recipients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ack_token` (`ack_token`),
  ADD KEY `idx_broadcast` (`broadcast_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_ack_token` (`ack_token`);

--
-- Indexes for table `cc_mark_distributions`
--
ALTER TABLE `cc_mark_distributions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ccmd_curriculum` (`curriculum_id`);

--
-- Indexes for table `cert_verification_log`
--
ALTER TABLE `cert_verification_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cvl_student` (`student_id`),
  ADD KEY `idx_cvl_queried` (`queried_student_id`),
  ADD KEY `idx_cvl_type` (`verifier_type`),
  ADD KEY `idx_cvl_found` (`student_found`),
  ADD KEY `idx_cvl_created` (`created_at`);

--
-- Indexes for table `cf_admission_requirements`
--
ALTER TABLE `cf_admission_requirements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cfar_prog` (`program_id`);

--
-- Indexes for table `cf_degree_types`
--
ALTER TABLE `cf_degree_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `cf_programs`
--
ALTER TABLE `cf_programs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `program_slug` (`program_slug`),
  ADD KEY `idx_cfp_dtype` (`degree_type_id`);

--
-- Indexes for table `cf_settings`
--
ALTER TABLE `cf_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `change_log`
--
ALTER TABLE `change_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cl_user` (`user_id`),
  ADD KEY `idx_cl_module` (`module`),
  ADD KEY `idx_cl_created` (`created_at`);

--
-- Indexes for table `ch_settings`
--
ALTER TABLE `ch_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `clubs`
--
ALTER TABLE `clubs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_clubs_dept` (`dept_id`),
  ADD KEY `idx_clubs_program` (`program_id`),
  ADD KEY `idx_clubs_active` (`is_active`);

--
-- Indexes for table `club_activities`
--
ALTER TABLE `club_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_clubact_club` (`club_id`);

--
-- Indexes for table `club_events`
--
ALTER TABLE `club_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_clubev_club` (`club_id`),
  ADD KEY `idx_clubev_published` (`is_published`);

--
-- Indexes for table `club_event_registrations`
--
ALTER TABLE `club_event_registrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_clubreg_event` (`event_id`),
  ADD KEY `idx_clubreg_status` (`status`);

--
-- Indexes for table `club_members`
--
ALTER TABLE `club_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_clubmem_club` (`club_id`);

--
-- Indexes for table `club_photos`
--
ALTER TABLE `club_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_clubphoto_club` (`club_id`);

--
-- Indexes for table `cms_about_settings`
--
ALTER TABLE `cms_about_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_about_key` (`setting_key`);

--
-- Indexes for table `cms_admission_settings`
--
ALTER TABLE `cms_admission_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_admission_key` (`setting_key`);

--
-- Indexes for table `cms_alumni`
--
ALTER TABLE `cms_alumni`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cms_campus_items`
--
ALTER TABLE `cms_campus_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cms_coc_items`
--
ALTER TABLE `cms_coc_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_coc_items_section` (`section_id`);

--
-- Indexes for table `cms_coc_sections`
--
ALTER TABLE `cms_coc_sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `section_key` (`section_key`);

--
-- Indexes for table `cms_contact_settings`
--
ALTER TABLE `cms_contact_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_contact_key` (`setting_key`);

--
-- Indexes for table `cms_features`
--
ALTER TABLE `cms_features`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cms_footer_settings`
--
ALTER TABLE `cms_footer_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `idx_key` (`setting_key`);

--
-- Indexes for table `cms_header_settings`
--
ALTER TABLE `cms_header_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `cms_menus`
--
ALTER TABLE `cms_menus`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cms_news`
--
ALTER TABLE `cms_news`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_slug` (`slug`(191)),
  ADD KEY `idx_news_approved` (`is_approved`),
  ADD KEY `idx_news_creator` (`created_by`);

--
-- Indexes for table `cms_news_attachments`
--
ALTER TABLE `cms_news_attachments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cms_notices`
--
ALTER TABLE `cms_notices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_notice_slug` (`slug`),
  ADD KEY `idx_notice_approved` (`is_approved`),
  ADD KEY `idx_notice_creator` (`created_by`);

--
-- Indexes for table `cms_pending_changes`
--
ALTER TABLE `cms_pending_changes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pch_module_status` (`module`,`status`),
  ADD KEY `idx_pch_record` (`module`,`record_id`),
  ADD KEY `idx_pch_requester` (`requested_by`);

--
-- Indexes for table `cms_programs`
--
ALTER TABLE `cms_programs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cms_sliders`
--
ALTER TABLE `cms_sliders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `coe_settings`
--
ALTER TABLE `coe_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `coe_staff`
--
ALTER TABLE `coe_staff`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contact_messages_is_read` (`is_read`),
  ADD KEY `idx_contact_messages_created` (`created_at`);

--
-- Indexes for table `course_curriculum`
--
ALTER TABLE `course_curriculum`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_program_semester` (`program_id`,`semester`),
  ADD KEY `idx_cc_intake` (`intake_id`),
  ADD KEY `idx_cc_assigned_faculty` (`assigned_faculty_id`);

--
-- Indexes for table `course_curriculum_intakes`
--
ALTER TABLE `course_curriculum_intakes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cci_program` (`program_id`);

--
-- Indexes for table `co_offers`
--
ALTER TABLE `co_offers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_co_batch_subject` (`batch_id`,`curriculum_id`),
  ADD KEY `idx_co_dept` (`dept_id`),
  ADD KEY `idx_co_program` (`program_id`);

--
-- Indexes for table `co_offer_subjects`
--
ALTER TABLE `co_offer_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cos_offer_curriculum` (`offer_id`,`curriculum_id`),
  ADD KEY `idx_cos_curriculum` (`curriculum_id`);

--
-- Indexes for table `co_offer_subject_teachers`
--
ALTER TABLE `co_offer_subject_teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cost_subject_faculty` (`offer_subject_id`,`faculty_id`),
  ADD KEY `idx_cost_faculty` (`faculty_id`);

--
-- Indexes for table `crhp_settings`
--
ALTER TABLE `crhp_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `crhp_staff`
--
ALTER TABLE `crhp_staff`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `crm_import_agents`
--
ALTER TABLE `crm_import_agents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `crm_import_call_logs`
--
ALTER TABLE `crm_import_call_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_call_lead_id` (`lead_id`),
  ADD KEY `fk_call_staff_id` (`staff_id`);

--
-- Indexes for table `crm_import_companies`
--
ALTER TABLE `crm_import_companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_name` (`company_name`),
  ADD UNIQUE KEY `contact_email` (`contact_email`),
  ADD UNIQUE KEY `system_email` (`system_email`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `crm_import_company_subscriptions`
--
ALTER TABLE `crm_import_company_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `subscription_plan_id` (`subscription_plan_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `crm_import_courses`
--
ALTER TABLE `crm_import_courses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_department` (`department`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `fk_courses_updated_by` (`updated_by`);

--
-- Indexes for table `crm_import_leads`
--
ALTER TABLE `crm_import_leads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_leads_created_by` (`created_by`),
  ADD KEY `fk_leads_agent_id` (`agent_id`),
  ADD KEY `fk_leads_company_id` (`company_id`),
  ADD KEY `fk_leads_updated_by` (`updated_by`),
  ADD KEY `fk_leads_interested_course` (`interested_course_id`),
  ADD KEY `idx_next_followup_date` (`next_followup_date`),
  ADD KEY `idx_last_status_change` (`last_status_change_date`);

--
-- Indexes for table `crm_import_lead_courses`
--
ALTER TABLE `crm_import_lead_courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_lead_course` (`lead_id`,`course_id`),
  ADD KEY `fk_lead_course_course_id` (`course_id`),
  ADD KEY `fk_lead_course_added_by` (`added_by`);

--
-- Indexes for table `crm_import_lead_edit_history`
--
ALTER TABLE `crm_import_lead_edit_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_lead_history_lead_id` (`lead_id`),
  ADD KEY `fk_lead_history_edited_by` (`edited_by`);

--
-- Indexes for table `crm_import_lead_notes`
--
ALTER TABLE `crm_import_lead_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_lead_notes_lead_id` (`lead_id`),
  ADD KEY `fk_lead_notes_created_by` (`created_by`);

--
-- Indexes for table `crm_import_lead_semesters`
--
ALTER TABLE `crm_import_lead_semesters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_lead_intake` (`lead_id`,`semester_id`),
  ADD KEY `fk_lead_intake_intake_id` (`semester_id`),
  ADD KEY `fk_lead_intake_added_by` (`added_by`);

--
-- Indexes for table `crm_import_lead_staff_assignments`
--
ALTER TABLE `crm_import_lead_staff_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_lead_staff` (`lead_id`,`staff_id`),
  ADD KEY `fk_assignment_staff_id` (`staff_id`),
  ADD KEY `fk_assignment_assigned_by` (`assigned_by`);

--
-- Indexes for table `crm_import_lead_universities`
--
ALTER TABLE `crm_import_lead_universities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_lead_university` (`lead_id`,`university_id`),
  ADD KEY `fk_lead_university_university_id` (`university_id`),
  ADD KEY `fk_lead_university_added_by` (`added_by`);

--
-- Indexes for table `crm_import_semesters`
--
ALTER TABLE `crm_import_semesters`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `crm_import_subscription_payments`
--
ALTER TABLE `crm_import_subscription_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_subscription_id` (`company_subscription_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `crm_import_subscription_plans`
--
ALTER TABLE `crm_import_subscription_plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `crm_import_universities`
--
ALTER TABLE `crm_import_universities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `crm_import_users`
--
ALTER TABLE `crm_import_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `users_company_fk` (`company_id`);

--
-- Indexes for table `crm_import_user_profile`
--
ALTER TABLE `crm_import_user_profile`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `dept_academic_programs`
--
ALTER TABLE `dept_academic_programs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_academic_programs_dept` (`dept_id`);

--
-- Indexes for table `dept_alumni`
--
ALTER TABLE `dept_alumni`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_alumni_dept` (`dept_id`);

--
-- Indexes for table `dept_clubs`
--
ALTER TABLE `dept_clubs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_clubs_dept` (`dept_id`);

--
-- Indexes for table `dept_departments`
--
ALTER TABLE `dept_departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `dept_events`
--
ALTER TABLE `dept_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_events_dept` (`dept_id`);

--
-- Indexes for table `dept_facilities`
--
ALTER TABLE `dept_facilities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_facilities_dept` (`dept_id`);

--
-- Indexes for table `dept_faculty`
--
ALTER TABLE `dept_faculty`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_faculty_dept` (`dept_id`),
  ADD KEY `fk_df_user` (`user_id`);

--
-- Indexes for table `dept_hero_slides`
--
ALTER TABLE `dept_hero_slides`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dept_hero_slides_dept` (`dept_id`);

--
-- Indexes for table `dept_notices`
--
ALTER TABLE `dept_notices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notices_dept` (`dept_id`);

--
-- Indexes for table `dept_overview`
--
ALTER TABLE `dept_overview`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dept_overview` (`dept_id`);

--
-- Indexes for table `dept_prime_pride`
--
ALTER TABLE `dept_prime_pride`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_prime_pride_dept` (`dept_id`);

--
-- Indexes for table `dept_routines`
--
ALTER TABLE `dept_routines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_routines_dept` (`dept_id`);

--
-- Indexes for table `ei_assignment_snapshots`
--
ALTER TABLE `ei_assignment_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ei_snap_exam_ver` (`exam_id`,`version_number`),
  ADD KEY `idx_ei_snap_exam` (`exam_id`);

--
-- Indexes for table `ei_assignment_snapshot_slots`
--
ALTER TABLE `ei_assignment_snapshot_slots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ei_ss_snap_slot` (`snapshot_id`,`slot_id`),
  ADD KEY `idx_ei_ss_snapshot` (`snapshot_id`),
  ADD KEY `idx_ei_ss_slot` (`slot_id`);

--
-- Indexes for table `ei_exams`
--
ALTER TABLE `ei_exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ei_exams_year` (`exam_year`);

--
-- Indexes for table `ei_faculty`
--
ALTER TABLE `ei_faculty`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ei_faculty_dept` (`dept_id`);

--
-- Indexes for table `ei_settings`
--
ALTER TABLE `ei_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `ei_slots`
--
ALTER TABLE `ei_slots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ei_slots_exam` (`exam_id`),
  ADD KEY `idx_ei_slots_date` (`slot_date`),
  ADD KEY `idx_ei_slots_faculty1` (`faculty1_id`),
  ADD KEY `idx_ei_slots_faculty2` (`faculty2_id`),
  ADD KEY `idx_ei_slots_dept` (`dept_id`);

--
-- Indexes for table `ei_slot_attendance`
--
ALTER TABLE `ei_slot_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_attendance` (`slot_id`,`faculty_id`),
  ADD KEY `idx_eia_exam` (`exam_id`),
  ADD KEY `idx_eia_slot` (`slot_id`),
  ADD KEY `idx_eia_faculty` (`faculty_id`);

--
-- Indexes for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `action` (`action`),
  ADD KEY `idx_action` (`action`);

--
-- Indexes for table `es_settings`
--
ALTER TABLE `es_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `es_staff`
--
ALTER TABLE `es_staff`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `faculty_files`
--
ALTER TABLE `faculty_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ff_user` (`user_id`),
  ADD KEY `idx_ff_uploader` (`uploaded_by`);

--
-- Indexes for table `faculty_file_delete_requests`
--
ALTER TABLE `faculty_file_delete_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fdr_file` (`file_id`),
  ADD KEY `idx_fdr_requester` (`requested_by`),
  ADD KEY `idx_fdr_status` (`status`),
  ADD KEY `fk_fdr_reviewer` (`reviewed_by`);

--
-- Indexes for table `faculty_profiles`
--
ALTER TABLE `faculty_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_id` (`user_id`);

--
-- Indexes for table `faculty_registrations`
--
ALTER TABLE `faculty_registrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fr_status` (`status`),
  ADD KEY `idx_fr_email` (`email`),
  ADD KEY `idx_fr_dept` (`dept_id`),
  ADD KEY `idx_fr_reviewer` (`reviewed_by`);

--
-- Indexes for table `faculty_subject_assignments`
--
ALTER TABLE `faculty_subject_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fsa_faculty_course` (`faculty_user_id`,`course_id`),
  ADD KEY `idx_fsa_faculty` (`faculty_user_id`),
  ADD KEY `idx_fsa_course` (`course_id`),
  ADD KEY `idx_fsa_status` (`status`);

--
-- Indexes for table `fake_id_verifications`
--
ALTER TABLE `fake_id_verifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fiv_checker` (`checked_by`),
  ADD KEY `idx_fiv_created` (`created_at`);

--
-- Indexes for table `file_manager_files`
--
ALTER TABLE `file_manager_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_creator` (`creator_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_category` (`category`);

--
-- Indexes for table `file_manager_pages`
--
ALTER TABLE `file_manager_pages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_file` (`file_id`),
  ADD KEY `idx_cat` (`category`);

--
-- Indexes for table `file_manager_page_signatures`
--
ALTER TABLE `file_manager_page_signatures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_page_user` (`page_id`,`user_id`),
  ADD KEY `fk_fmps_user` (`user_id`);

--
-- Indexes for table `file_manager_page_sign_positions`
--
ALTER TABLE `file_manager_page_sign_positions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_page_user` (`page_id`,`user_id`),
  ADD KEY `fk_fmpsp_user` (`user_id`);

--
-- Indexes for table `file_manager_page_text_notes`
--
ALTER TABLE `file_manager_page_text_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_page` (`page_id`);

--
-- Indexes for table `file_manager_tagged_users`
--
ALTER TABLE `file_manager_tagged_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_file_user` (`file_id`,`user_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `file_manager_transfers`
--
ALTER TABLE `file_manager_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_file` (`file_id`),
  ADD KEY `idx_to_user` (`to_user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_fmt_from_user` (`from_user_id`);

--
-- Indexes for table `gallery_albums`
--
ALTER TABLE `gallery_albums`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gal_albums_dept` (`dept_id`),
  ADD KEY `idx_gal_albums_program` (`program_id`),
  ADD KEY `idx_gal_albums_active` (`is_active`);

--
-- Indexes for table `gallery_photos`
--
ALTER TABLE `gallery_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gal_photos_album` (`album_id`),
  ADD KEY `idx_gal_photos_status` (`status`);

--
-- Indexes for table `glance_highlights`
--
ALTER TABLE `glance_highlights`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `glance_leaders`
--
ALTER TABLE `glance_leaders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `glance_messages`
--
ALTER TABLE `glance_messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `msg_key` (`msg_key`);

--
-- Indexes for table `glance_milestones`
--
ALTER TABLE `glance_milestones`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `glance_settings`
--
ALTER TABLE `glance_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `glance_stats`
--
ALTER TABLE `glance_stats`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `global_settings`
--
ALTER TABLE `global_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`);

--
-- Indexes for table `governing_body_members`
--
ALTER TABLE `governing_body_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_page_section` (`page_type`,`section`),
  ADD KEY `idx_page_sort` (`page_type`,`sort_order`),
  ADD KEY `idx_glance_officer` (`page_type`,`glance_officer`),
  ADD KEY `idx_glance_message` (`page_type`,`glance_msg_tab`(20));

--
-- Indexes for table `governing_body_pages`
--
ALTER TABLE `governing_body_pages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_page_type` (`page_type`);

--
-- Indexes for table `group_dept_scope`
--
ALTER TABLE `group_dept_scope`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_gds` (`group_id`,`dept_id`),
  ADD KEY `idx_gds_group` (`group_id`),
  ADD KEY `idx_gds_dept` (`dept_id`);

--
-- Indexes for table `group_module_access`
--
ALTER TABLE `group_module_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_group_module` (`group_id`,`module_id`),
  ADD KEY `module_id` (`module_id`);

--
-- Indexes for table `homepage_stats`
--
ALTER TABLE `homepage_stats`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `homepage_testimonials`
--
ALTER TABLE `homepage_testimonials`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `it_settings`
--
ALTER TABLE `it_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `it_staff`
--
ALTER TABLE `it_staff`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_job_slug` (`slug`(191));

--
-- Indexes for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_job_applications_job` (`job_id`),
  ADD KEY `idx_job_applications_status` (`status`);

--
-- Indexes for table `kb_articles`
--
ALTER TABLE `kb_articles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `leads`
--
ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lead_number` (`lead_number`),
  ADD KEY `idx_leads_status` (`status`),
  ADD KEY `idx_leads_source` (`source`),
  ADD KEY `idx_leads_dept` (`dept_id`),
  ADD KEY `idx_leads_program` (`program_id`),
  ADD KEY `idx_leads_assigned` (`assigned_to`),
  ADD KEY `fk_leads_created` (`created_by`),
  ADD KEY `fk_leads_updated` (`updated_by`),
  ADD KEY `idx_leads_followup` (`next_followup_date`);

--
-- Indexes for table `lead_appointments`
--
ALTER TABLE `lead_appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_appt_lead` (`lead_id`),
  ADD KEY `idx_appt_date` (`appointment_date`),
  ADD KEY `idx_appt_status` (`status`),
  ADD KEY `fk_appt_created` (`created_by`);

--
-- Indexes for table `lead_assignments`
--
ALTER TABLE `lead_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lead_user` (`lead_id`,`user_id`),
  ADD KEY `fk_la_user` (`user_id`);

--
-- Indexes for table `lead_call_logs`
--
ALTER TABLE `lead_call_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_clog_lead` (`lead_id`),
  ADD KEY `idx_clog_user` (`user_id`),
  ADD KEY `idx_clog_date` (`call_date`);

--
-- Indexes for table `lead_fb_contacts`
--
ALTER TABLE `lead_fb_contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `psid` (`psid`),
  ADD KEY `idx_fbc_lead` (`lead_id`);

--
-- Indexes for table `lead_fb_messages`
--
ALTER TABLE `lead_fb_messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `fb_mid` (`fb_mid`),
  ADD KEY `idx_fbm_contact` (`contact_id`),
  ADD KEY `idx_fbm_direction` (`direction`),
  ADD KEY `idx_fbm_created` (`created_at`),
  ADD KEY `fk_fbm_sent_by` (`sent_by`);

--
-- Indexes for table `lead_fb_settings`
--
ALTER TABLE `lead_fb_settings`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `lead_history`
--
ALTER TABLE `lead_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lh_lead` (`lead_id`),
  ADD KEY `fk_lh_user` (`user_id`);

--
-- Indexes for table `lead_notes`
--
ALTER TABLE `lead_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ln_lead` (`lead_id`),
  ADD KEY `fk_ln_user` (`user_id`);

--
-- Indexes for table `library_audit_log`
--
ALTER TABLE `library_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_module` (`module`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_record` (`record_id`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `library_books`
--
ALTER TABLE `library_books`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_book_isbn` (`isbn`),
  ADD KEY `idx_book_category` (`category_id`),
  ADD KEY `idx_book_department` (`department_id`),
  ADD KEY `idx_book_created_by` (`created_by`),
  ADD KEY `idx_book_title` (`title`(100));

--
-- Indexes for table `library_book_copies`
--
ALTER TABLE `library_book_copies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_copy_barcode` (`barcode`),
  ADD KEY `idx_copy_book` (`book_id`),
  ADD KEY `idx_copy_available` (`is_available`);

--
-- Indexes for table `library_categories`
--
ALTER TABLE `library_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_category_slug` (`slug`),
  ADD KEY `idx_category_parent` (`parent_id`);

--
-- Indexes for table `library_circulation`
--
ALTER TABLE `library_circulation`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_circ_copy` (`copy_id`),
  ADD KEY `idx_circ_book` (`book_id`),
  ADD KEY `idx_circ_member` (`member_id`),
  ADD KEY `idx_circ_issued_by` (`issued_by`),
  ADD KEY `idx_circ_returned_to` (`returned_to`),
  ADD KEY `idx_circ_status` (`status`),
  ADD KEY `idx_circ_due_date` (`due_date`);

--
-- Indexes for table `library_dept_collections`
--
ALTER TABLE `library_dept_collections`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `library_digital_resources`
--
ALTER TABLE `library_digital_resources`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_digres_category` (`category_id`),
  ADD KEY `idx_digres_department` (`department_id`),
  ADD KEY `idx_digres_uploaded_by` (`uploaded_by`),
  ADD KEY `idx_digres_type` (`resource_type`),
  ADD KEY `idx_digres_active` (`is_active`),
  ADD KEY `idx_digres_access` (`access_level`);

--
-- Indexes for table `library_facilities`
--
ALTER TABLE `library_facilities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `library_fines`
--
ALTER TABLE `library_fines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fine_receipt` (`receipt_number`),
  ADD KEY `idx_fine_circulation` (`circulation_id`),
  ADD KEY `idx_fine_member` (`member_id`),
  ADD KEY `idx_fine_collected_by` (`collected_by`),
  ADD KEY `idx_fine_status` (`status`);

--
-- Indexes for table `library_librarians`
--
ALTER TABLE `library_librarians`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_librarians_active_sort` (`is_active`,`sort_order`);

--
-- Indexes for table `library_members`
--
ALTER TABLE `library_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_member_code` (`member_code`),
  ADD KEY `idx_member_student` (`student_id`),
  ADD KEY `idx_member_user` (`user_id`),
  ADD KEY `idx_member_dept` (`dept_id`),
  ADD KEY `idx_member_active_type` (`is_active`,`member_type`);

--
-- Indexes for table `library_notifications`
--
ALTER TABLE `library_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_member` (`member_id`),
  ADD KEY `idx_notif_is_read` (`is_read`),
  ADD KEY `idx_notif_type` (`notification_type`),
  ADD KEY `idx_notif_created` (`created_at`);

--
-- Indexes for table `library_reservations`
--
ALTER TABLE `library_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_resv_book` (`book_id`),
  ADD KEY `idx_resv_member` (`member_id`),
  ADD KEY `idx_resv_reserved_by` (`reserved_by`),
  ADD KEY `idx_resv_status` (`status`),
  ADD KEY `idx_resv_expires_at` (`expires_at`);

--
-- Indexes for table `library_settings`
--
ALTER TABLE `library_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_setting_key` (`setting_key`);

--
-- Indexes for table `ll_notices`
--
ALTER TABLE `ll_notices`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ll_services`
--
ALTER TABLE `ll_services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ll_settings`
--
ALTER TABLE `ll_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `ll_staff`
--
ALTER TABLE `ll_staff`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mc_appointments`
--
ALTER TABLE `mc_appointments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mc_health_tips`
--
ALTER TABLE `mc_health_tips`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mc_medicines`
--
ALTER TABLE `mc_medicines`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mc_prescriptions`
--
ALTER TABLE `mc_prescriptions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mc_schedules`
--
ALTER TABLE `mc_schedules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mc_settings`
--
ALTER TABLE `mc_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`);

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_slug` (`slug`),
  ADD KEY `idx_parent` (`parent_id`);

--
-- Indexes for table `notice_documents`
--
ALTER TABLE `notice_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `notice_signatures`
--
ALTER TABLE `notice_signatures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_doc_signer` (`document_id`,`user_id`),
  ADD KEY `idx_document` (`document_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `fk_ns_pos` (`position_id`);

--
-- Indexes for table `notice_sign_positions`
--
ALTER TABLE `notice_sign_positions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_doc_user` (`document_id`,`user_id`),
  ADD KEY `idx_document` (`document_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `op_settings`
--
ALTER TABLE `op_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `op_staff`
--
ALTER TABLE `op_staff`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pages`
--
ALTER TABLE `pages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pages_slug` (`slug`(191));

--
-- Indexes for table `page_profiles`
--
ALTER TABLE `page_profiles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pp_page` (`page_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_token` (`token`);

--
-- Indexes for table `policy_procedure_sections`
--
ALTER TABLE `policy_procedure_sections`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `popup_settings`
--
ALTER TABLE `popup_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `portal_global_notice`
--
ALTER TABLE `portal_global_notice`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `program_eligibility_criteria`
--
ALTER TABLE `program_eligibility_criteria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pec_program` (`program_id`);

--
-- Indexes for table `program_intake_periods`
--
ALTER TABLE `program_intake_periods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pip_program` (`program_id`);

--
-- Indexes for table `pvc_settings`
--
ALTER TABLE `pvc_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `reg_settings`
--
ALTER TABLE `reg_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `reg_staff`
--
ALTER TABLE `reg_staff`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `result_exams`
--
ALTER TABLE `result_exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_re_dept` (`dept_id`),
  ADD KEY `idx_re_program` (`program_id`);

--
-- Indexes for table `result_grades`
--
ALTER TABLE `result_grades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_grade` (`exam_id`,`subject_id`,`student_sid`),
  ADD KEY `idx_rg_exam` (`exam_id`),
  ADD KEY `idx_rg_subject` (`subject_id`),
  ADD KEY `idx_rg_student` (`student_id`);

--
-- Indexes for table `result_grade_details`
--
ALTER TABLE `result_grade_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_grade_cat` (`grade_id`,`category_id`),
  ADD KEY `idx_rgd_grade` (`grade_id`),
  ADD KEY `idx_rgd_category` (`category_id`);

--
-- Indexes for table `result_mark_categories`
--
ALTER TABLE `result_mark_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rmc_subject` (`subject_id`);

--
-- Indexes for table `result_mark_sheets`
--
ALTER TABLE `result_mark_sheets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rms_chain` (`chain_id`),
  ADD KEY `idx_rms_dept` (`dept_id`),
  ADD KEY `idx_rms_program` (`program_id`),
  ADD KEY `idx_rms_status` (`workflow_status`),
  ADD KEY `idx_rms_creator` (`created_by`),
  ADD KEY `fk_rms_curriculum` (`curriculum_id`);

--
-- Indexes for table `result_sheet_grades`
--
ALTER TABLE `result_sheet_grades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sheet_student` (`sheet_id`,`student_sid`),
  ADD KEY `idx_rsg_sheet` (`sheet_id`),
  ADD KEY `idx_rsg_student` (`student_id`);

--
-- Indexes for table `result_subjects`
--
ALTER TABLE `result_subjects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rs_exam` (`exam_id`),
  ADD KEY `idx_rs_curriculum` (`curriculum_id`);

--
-- Indexes for table `sa_settings`
--
ALTER TABLE `sa_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `sa_staff`
--
ALTER TABLE `sa_staff`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sc_awards`
--
ALTER TABLE `sc_awards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sca_student` (`student_id`),
  ADD KEY `idx_sca_semester` (`semester`),
  ADD KEY `fk_sca_policy` (`policy_id`),
  ADD KEY `fk_sca_tier` (`tier_id`),
  ADD KEY `fk_sca_awarded_by` (`awarded_by`),
  ADD KEY `fk_sca_revoked_by` (`revoked_by`);

--
-- Indexes for table `sc_policies`
--
ALTER TABLE `sc_policies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_scp_created` (`created_by`);

--
-- Indexes for table `sc_settings`
--
ALTER TABLE `sc_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sc_tiers`
--
ALTER TABLE `sc_tiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sct_policy` (`policy_id`);

--
-- Indexes for table `seo_entries`
--
ALTER TABLE `seo_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_seo_url` (`page_url`(191)),
  ADD KEY `idx_seo_type_id` (`page_type`,`page_id`);

--
-- Indexes for table `seo_settings`
--
ALTER TABLE `seo_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_seo_setting` (`setting_key`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_settings_key` (`key`);

--
-- Indexes for table `sfp_packages`
--
ALTER TABLE `sfp_packages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sfp_student` (`student_id`) COMMENT 'One active package per student',
  ADD KEY `idx_sfp_cf_prog` (`cf_program_id`),
  ADD KEY `fk_sfp_assigned` (`assigned_by`);

--
-- Indexes for table `sfp_payments`
--
ALTER TABLE `sfp_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sfpp_txn` (`transaction_number`),
  ADD KEY `idx_sfpp_student` (`student_id`),
  ADD KEY `idx_sfpp_package` (`package_id`),
  ADD KEY `idx_sfpp_sem_fee` (`semester_fee_id`),
  ADD KEY `idx_sfpp_type` (`fee_type`),
  ADD KEY `idx_sfpp_voucher` (`voucher_id`),
  ADD KEY `fk_sfpp_collector` (`collected_by`);

--
-- Indexes for table `sfp_semester_fees`
--
ALTER TABLE `sfp_semester_fees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sfpsf_package` (`package_id`),
  ADD KEY `fk_sfpsf_award` (`scholarship_award_id`),
  ADD KEY `fk_sfpsf_updated` (`updated_by`);

--
-- Indexes for table `sfp_semester_scholarships`
--
ALTER TABLE `sfp_semester_scholarships`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sfpss_sf` (`sf_id`),
  ADD KEY `fk_sfpss_created` (`created_by`),
  ADD KEY `fk_sfpss_doc` (`support_doc_id`);

--
-- Indexes for table `sr_results`
--
ALTER TABLE `sr_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sr_published` (`is_published`);

--
-- Indexes for table `sr_result_entries`
--
ALTER TABLE `sr_result_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sre_result` (`result_id`),
  ADD KEY `idx_sre_student` (`student_id`);

--
-- Indexes for table `staff_departments`
--
ALTER TABLE `staff_departments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sd_dept` (`dept_id`);

--
-- Indexes for table `staff_profiles`
--
ALTER TABLE `staff_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_sp_user` (`user_id`),
  ADD KEY `fk_sp_dept` (`staff_dept_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`),
  ADD KEY `idx_students_dept` (`dept_id`),
  ADD KEY `idx_students_program` (`program_id`),
  ADD KEY `idx_students_status` (`status`),
  ADD KEY `fk_students_batch` (`batch_id`),
  ADD KEY `fk_students_district` (`district_id`),
  ADD KEY `fk_students_thana` (`thana_id`);

--
-- Indexes for table `student_academic_qualifications`
--
ALTER TABLE `student_academic_qualifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_qual_student` (`student_id`),
  ADD KEY `fk_qual_exam_title` (`exam_title_id`),
  ADD KEY `fk_qual_board` (`board_id`),
  ADD KEY `fk_qual_group` (`group_id`);

--
-- Indexes for table `student_batches`
--
ALTER TABLE `student_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_boards`
--
ALTER TABLE `student_boards`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_comments`
--
ALTER TABLE `student_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_comments_student` (`student_id`);

--
-- Indexes for table `student_enrollment_status`
--
ALTER TABLE `student_enrollment_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_student_id` (`student_id`);

--
-- Indexes for table `student_exam_titles`
--
ALTER TABLE `student_exam_titles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_files`
--
ALTER TABLE `student_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_files_student` (`student_id`);

--
-- Indexes for table `student_groups`
--
ALTER TABLE `student_groups`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_pdf_pending`
--
ALTER TABLE `student_pdf_pending`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_spp_batch` (`batch_token`),
  ADD KEY `idx_spp_status` (`status`),
  ADD KEY `fk_spp_student` (`assigned_student_pk`),
  ADD KEY `fk_spp_uploader` (`uploaded_by`);

--
-- Indexes for table `student_portal_log`
--
ALTER TABLE `student_portal_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `student_portal_settings`
--
ALTER TABLE `student_portal_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_key` (`setting_key`);

--
-- Indexes for table `student_push_tokens`
--
ALTER TABLE `student_push_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_student_device` (`user_id`,`device_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `student_results`
--
ALTER TABLE `student_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sr_student` (`student_id`),
  ADD KEY `idx_sr_sid` (`student_sid`);

--
-- Indexes for table `student_verifications`
--
ALTER TABLE `student_verifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sv_student` (`student_id`),
  ADD KEY `idx_sv_verifier` (`verified_by`),
  ADD KEY `idx_sv_status` (`overall_status`);

--
-- Indexes for table `support_settings`
--
ALTER TABLE `support_settings`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `support_sla_rules`
--
ALTER TABLE `support_sla_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sla_priority` (`priority`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ticket_number` (`ticket_number`),
  ADD KEY `idx_st_created_by` (`created_by`),
  ADD KEY `idx_st_assigned_to` (`assigned_to`),
  ADD KEY `idx_st_status` (`status`),
  ADD KEY `idx_st_priority` (`priority`);

--
-- Indexes for table `support_ticket_attachments`
--
ALTER TABLE `support_ticket_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sta_ticket` (`ticket_id`),
  ADD KEY `fk_sta_user` (`uploaded_by`);

--
-- Indexes for table `support_ticket_comments`
--
ALTER TABLE `support_ticket_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stc_ticket` (`ticket_id`),
  ADD KEY `fk_stc_user` (`created_by`);

--
-- Indexes for table `support_ticket_comment_attachments`
--
ALTER TABLE `support_ticket_comment_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stca_comment` (`comment_id`);

--
-- Indexes for table `support_ticket_user_tags`
--
ALTER TABLE `support_ticket_user_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ticket_user_tag` (`ticket_id`,`user_id`),
  ADD KEY `fk_stut_user` (`user_id`),
  ADD KEY `fk_stut_tagger` (`tagged_by`);

--
-- Indexes for table `tr_settings`
--
ALTER TABLE `tr_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `group_id` (`group_id`);

--
-- Indexes for table `user_dept_scope`
--
ALTER TABLE `user_dept_scope`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_uds` (`user_id`,`dept_id`),
  ADD KEY `idx_uds_user` (`user_id`),
  ADD KEY `idx_uds_dept` (`dept_id`);

--
-- Indexes for table `user_groups`
--
ALTER TABLE `user_groups`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_group_assignments`
--
ALTER TABLE `user_group_assignments`
  ADD PRIMARY KEY (`user_id`,`group_id`),
  ADD KEY `idx_uga_group` (`group_id`);

--
-- Indexes for table `user_module_access`
--
ALTER TABLE `user_module_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_module` (`user_id`,`module_id`),
  ADD KEY `idx_uma_user` (`user_id`),
  ADD KEY `idx_uma_module` (`module_id`);

--
-- Indexes for table `vc_former_vcs`
--
ALTER TABLE `vc_former_vcs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `vc_scholarship_approvals`
--
ALTER TABLE `vc_scholarship_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_package` (`package_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_requested_by` (`requested_by`);

--
-- Indexes for table `vc_settings`
--
ALTER TABLE `vc_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `wf_chains`
--
ALTER TABLE `wf_chains`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wfc_dept` (`dept_id`),
  ADD KEY `idx_wfc_program` (`program_id`),
  ADD KEY `fk_wfc_creator` (`created_by`);

--
-- Indexes for table `wf_chain_steps`
--
ALTER TABLE `wf_chain_steps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chain_step` (`chain_id`,`step_order`),
  ADD KEY `idx_wfcs_chain` (`chain_id`),
  ADD KEY `idx_wfcs_group` (`group_id`);

--
-- Indexes for table `wf_sheet_history`
--
ALTER TABLE `wf_sheet_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wfsh_sheet` (`sheet_id`),
  ADD KEY `fk_wfsh_user` (`acted_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `aa_settings`
--
ALTER TABLE `aa_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `aa_staff`
--
ALTER TABLE `aa_staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `acc_accounts`
--
ALTER TABLE `acc_accounts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `acc_vouchers`
--
ALTER TABLE `acc_vouchers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `acc_voucher_items`
--
ALTER TABLE `acc_voucher_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ac_admit_cards`
--
ALTER TABLE `ac_admit_cards`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ac_admit_card_courses`
--
ALTER TABLE `ac_admit_card_courses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ac_student_overrides`
--
ALTER TABLE `ac_student_overrides`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ac_student_tokens`
--
ALTER TABLE `ac_student_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admissions_academic_records`
--
ALTER TABLE `admissions_academic_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admissions_applications`
--
ALTER TABLE `admissions_applications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admissions_fields`
--
ALTER TABLE `admissions_fields`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admissions_field_mappings`
--
ALTER TABLE `admissions_field_mappings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admissions_templates`
--
ALTER TABLE `admissions_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admissions_top_sheet_programs`
--
ALTER TABLE `admissions_top_sheet_programs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adm_admission_fee_payments`
--
ALTER TABLE `adm_admission_fee_payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adm_form_sales`
--
ALTER TABLE `adm_form_sales`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adm_form_sale_academic_records`
--
ALTER TABLE `adm_form_sale_academic_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adm_form_sale_student_details`
--
ALTER TABLE `adm_form_sale_student_details`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adm_form_sale_tokens`
--
ALTER TABLE `adm_form_sale_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adm_fs_field_mappings`
--
ALTER TABLE `adm_fs_field_mappings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adm_fs_templates`
--
ALTER TABLE `adm_fs_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adm_student_id_settings`
--
ALTER TABLE `adm_student_id_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `alumni`
--
ALTER TABLE `alumni`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_push_tokens`
--
ALTER TABLE `api_push_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_tokens`
--
ALTER TABLE `api_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bd_districts`
--
ALTER TABLE `bd_districts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bd_thanas`
--
ALTER TABLE `bd_thanas`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `broadcasts`
--
ALTER TABLE `broadcasts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `broadcast_attachments`
--
ALTER TABLE `broadcast_attachments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `broadcast_recipients`
--
ALTER TABLE `broadcast_recipients`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cc_mark_distributions`
--
ALTER TABLE `cc_mark_distributions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cert_verification_log`
--
ALTER TABLE `cert_verification_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cf_admission_requirements`
--
ALTER TABLE `cf_admission_requirements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cf_degree_types`
--
ALTER TABLE `cf_degree_types`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cf_programs`
--
ALTER TABLE `cf_programs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `change_log`
--
ALTER TABLE `change_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ch_settings`
--
ALTER TABLE `ch_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clubs`
--
ALTER TABLE `clubs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `club_activities`
--
ALTER TABLE `club_activities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `club_events`
--
ALTER TABLE `club_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `club_event_registrations`
--
ALTER TABLE `club_event_registrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `club_members`
--
ALTER TABLE `club_members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `club_photos`
--
ALTER TABLE `club_photos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_about_settings`
--
ALTER TABLE `cms_about_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_admission_settings`
--
ALTER TABLE `cms_admission_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_alumni`
--
ALTER TABLE `cms_alumni`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_campus_items`
--
ALTER TABLE `cms_campus_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_coc_items`
--
ALTER TABLE `cms_coc_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_coc_sections`
--
ALTER TABLE `cms_coc_sections`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_contact_settings`
--
ALTER TABLE `cms_contact_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_features`
--
ALTER TABLE `cms_features`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_footer_settings`
--
ALTER TABLE `cms_footer_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_header_settings`
--
ALTER TABLE `cms_header_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_menus`
--
ALTER TABLE `cms_menus`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_news`
--
ALTER TABLE `cms_news`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_news_attachments`
--
ALTER TABLE `cms_news_attachments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_notices`
--
ALTER TABLE `cms_notices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_pending_changes`
--
ALTER TABLE `cms_pending_changes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_programs`
--
ALTER TABLE `cms_programs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cms_sliders`
--
ALTER TABLE `cms_sliders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `coe_settings`
--
ALTER TABLE `coe_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `coe_staff`
--
ALTER TABLE `coe_staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_messages`
--
ALTER TABLE `contact_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_curriculum`
--
ALTER TABLE `course_curriculum`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_curriculum_intakes`
--
ALTER TABLE `course_curriculum_intakes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `co_offers`
--
ALTER TABLE `co_offers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `co_offer_subjects`
--
ALTER TABLE `co_offer_subjects`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `co_offer_subject_teachers`
--
ALTER TABLE `co_offer_subject_teachers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crhp_settings`
--
ALTER TABLE `crhp_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crhp_staff`
--
ALTER TABLE `crhp_staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_agents`
--
ALTER TABLE `crm_import_agents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_call_logs`
--
ALTER TABLE `crm_import_call_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_companies`
--
ALTER TABLE `crm_import_companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_company_subscriptions`
--
ALTER TABLE `crm_import_company_subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_courses`
--
ALTER TABLE `crm_import_courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_leads`
--
ALTER TABLE `crm_import_leads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_lead_courses`
--
ALTER TABLE `crm_import_lead_courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_lead_edit_history`
--
ALTER TABLE `crm_import_lead_edit_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_lead_notes`
--
ALTER TABLE `crm_import_lead_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_lead_semesters`
--
ALTER TABLE `crm_import_lead_semesters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_lead_staff_assignments`
--
ALTER TABLE `crm_import_lead_staff_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_lead_universities`
--
ALTER TABLE `crm_import_lead_universities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_semesters`
--
ALTER TABLE `crm_import_semesters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_subscription_payments`
--
ALTER TABLE `crm_import_subscription_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_subscription_plans`
--
ALTER TABLE `crm_import_subscription_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_universities`
--
ALTER TABLE `crm_import_universities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_users`
--
ALTER TABLE `crm_import_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_import_user_profile`
--
ALTER TABLE `crm_import_user_profile`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_academic_programs`
--
ALTER TABLE `dept_academic_programs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_alumni`
--
ALTER TABLE `dept_alumni`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_clubs`
--
ALTER TABLE `dept_clubs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_departments`
--
ALTER TABLE `dept_departments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_events`
--
ALTER TABLE `dept_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_facilities`
--
ALTER TABLE `dept_facilities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_faculty`
--
ALTER TABLE `dept_faculty`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_hero_slides`
--
ALTER TABLE `dept_hero_slides`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_notices`
--
ALTER TABLE `dept_notices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_overview`
--
ALTER TABLE `dept_overview`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_prime_pride`
--
ALTER TABLE `dept_prime_pride`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_routines`
--
ALTER TABLE `dept_routines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ei_assignment_snapshots`
--
ALTER TABLE `ei_assignment_snapshots`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ei_assignment_snapshot_slots`
--
ALTER TABLE `ei_assignment_snapshot_slots`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ei_exams`
--
ALTER TABLE `ei_exams`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ei_faculty`
--
ALTER TABLE `ei_faculty`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ei_slots`
--
ALTER TABLE `ei_slots`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ei_slot_attendance`
--
ALTER TABLE `ei_slot_attendance`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `es_settings`
--
ALTER TABLE `es_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `es_staff`
--
ALTER TABLE `es_staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty_files`
--
ALTER TABLE `faculty_files`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty_file_delete_requests`
--
ALTER TABLE `faculty_file_delete_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty_profiles`
--
ALTER TABLE `faculty_profiles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty_registrations`
--
ALTER TABLE `faculty_registrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty_subject_assignments`
--
ALTER TABLE `faculty_subject_assignments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fake_id_verifications`
--
ALTER TABLE `fake_id_verifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `file_manager_files`
--
ALTER TABLE `file_manager_files`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `file_manager_pages`
--
ALTER TABLE `file_manager_pages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `file_manager_page_signatures`
--
ALTER TABLE `file_manager_page_signatures`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `file_manager_page_sign_positions`
--
ALTER TABLE `file_manager_page_sign_positions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `file_manager_page_text_notes`
--
ALTER TABLE `file_manager_page_text_notes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `file_manager_tagged_users`
--
ALTER TABLE `file_manager_tagged_users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `file_manager_transfers`
--
ALTER TABLE `file_manager_transfers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gallery_albums`
--
ALTER TABLE `gallery_albums`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gallery_photos`
--
ALTER TABLE `gallery_photos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `glance_highlights`
--
ALTER TABLE `glance_highlights`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `glance_leaders`
--
ALTER TABLE `glance_leaders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `glance_messages`
--
ALTER TABLE `glance_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `glance_milestones`
--
ALTER TABLE `glance_milestones`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `glance_settings`
--
ALTER TABLE `glance_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `glance_stats`
--
ALTER TABLE `glance_stats`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `global_settings`
--
ALTER TABLE `global_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `governing_body_members`
--
ALTER TABLE `governing_body_members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `governing_body_pages`
--
ALTER TABLE `governing_body_pages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_dept_scope`
--
ALTER TABLE `group_dept_scope`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_module_access`
--
ALTER TABLE `group_module_access`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homepage_stats`
--
ALTER TABLE `homepage_stats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homepage_testimonials`
--
ALTER TABLE `homepage_testimonials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `it_settings`
--
ALTER TABLE `it_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `it_staff`
--
ALTER TABLE `it_staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_applications`
--
ALTER TABLE `job_applications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kb_articles`
--
ALTER TABLE `kb_articles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leads`
--
ALTER TABLE `leads`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lead_appointments`
--
ALTER TABLE `lead_appointments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lead_assignments`
--
ALTER TABLE `lead_assignments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lead_call_logs`
--
ALTER TABLE `lead_call_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lead_fb_contacts`
--
ALTER TABLE `lead_fb_contacts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lead_fb_messages`
--
ALTER TABLE `lead_fb_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lead_history`
--
ALTER TABLE `lead_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lead_notes`
--
ALTER TABLE `lead_notes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_audit_log`
--
ALTER TABLE `library_audit_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_books`
--
ALTER TABLE `library_books`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_book_copies`
--
ALTER TABLE `library_book_copies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_categories`
--
ALTER TABLE `library_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_circulation`
--
ALTER TABLE `library_circulation`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_dept_collections`
--
ALTER TABLE `library_dept_collections`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_digital_resources`
--
ALTER TABLE `library_digital_resources`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_facilities`
--
ALTER TABLE `library_facilities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_fines`
--
ALTER TABLE `library_fines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_librarians`
--
ALTER TABLE `library_librarians`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_members`
--
ALTER TABLE `library_members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_notifications`
--
ALTER TABLE `library_notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_reservations`
--
ALTER TABLE `library_reservations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_settings`
--
ALTER TABLE `library_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ll_notices`
--
ALTER TABLE `ll_notices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ll_services`
--
ALTER TABLE `ll_services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ll_settings`
--
ALTER TABLE `ll_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ll_staff`
--
ALTER TABLE `ll_staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mc_appointments`
--
ALTER TABLE `mc_appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mc_health_tips`
--
ALTER TABLE `mc_health_tips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mc_medicines`
--
ALTER TABLE `mc_medicines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mc_prescriptions`
--
ALTER TABLE `mc_prescriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mc_schedules`
--
ALTER TABLE `mc_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mc_settings`
--
ALTER TABLE `mc_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notice_documents`
--
ALTER TABLE `notice_documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notice_signatures`
--
ALTER TABLE `notice_signatures`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notice_sign_positions`
--
ALTER TABLE `notice_sign_positions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_settings`
--
ALTER TABLE `op_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_staff`
--
ALTER TABLE `op_staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pages`
--
ALTER TABLE `pages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `page_profiles`
--
ALTER TABLE `page_profiles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `policy_procedure_sections`
--
ALTER TABLE `policy_procedure_sections`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `popup_settings`
--
ALTER TABLE `popup_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `portal_global_notice`
--
ALTER TABLE `portal_global_notice`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `program_eligibility_criteria`
--
ALTER TABLE `program_eligibility_criteria`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `program_intake_periods`
--
ALTER TABLE `program_intake_periods`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pvc_settings`
--
ALTER TABLE `pvc_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reg_settings`
--
ALTER TABLE `reg_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reg_staff`
--
ALTER TABLE `reg_staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `result_exams`
--
ALTER TABLE `result_exams`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `result_grades`
--
ALTER TABLE `result_grades`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `result_grade_details`
--
ALTER TABLE `result_grade_details`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `result_mark_categories`
--
ALTER TABLE `result_mark_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `result_mark_sheets`
--
ALTER TABLE `result_mark_sheets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `result_sheet_grades`
--
ALTER TABLE `result_sheet_grades`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `result_subjects`
--
ALTER TABLE `result_subjects`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sa_settings`
--
ALTER TABLE `sa_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sa_staff`
--
ALTER TABLE `sa_staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sc_awards`
--
ALTER TABLE `sc_awards`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sc_policies`
--
ALTER TABLE `sc_policies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sc_tiers`
--
ALTER TABLE `sc_tiers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `seo_entries`
--
ALTER TABLE `seo_entries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `seo_settings`
--
ALTER TABLE `seo_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sfp_packages`
--
ALTER TABLE `sfp_packages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sfp_payments`
--
ALTER TABLE `sfp_payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sfp_semester_fees`
--
ALTER TABLE `sfp_semester_fees`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sfp_semester_scholarships`
--
ALTER TABLE `sfp_semester_scholarships`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sr_results`
--
ALTER TABLE `sr_results`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sr_result_entries`
--
ALTER TABLE `sr_result_entries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_departments`
--
ALTER TABLE `staff_departments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_profiles`
--
ALTER TABLE `staff_profiles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_academic_qualifications`
--
ALTER TABLE `student_academic_qualifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_batches`
--
ALTER TABLE `student_batches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_boards`
--
ALTER TABLE `student_boards`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_comments`
--
ALTER TABLE `student_comments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_enrollment_status`
--
ALTER TABLE `student_enrollment_status`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_exam_titles`
--
ALTER TABLE `student_exam_titles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_files`
--
ALTER TABLE `student_files`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_groups`
--
ALTER TABLE `student_groups`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_pdf_pending`
--
ALTER TABLE `student_pdf_pending`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_portal_log`
--
ALTER TABLE `student_portal_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_portal_settings`
--
ALTER TABLE `student_portal_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_push_tokens`
--
ALTER TABLE `student_push_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_results`
--
ALTER TABLE `student_results`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_verifications`
--
ALTER TABLE `student_verifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_sla_rules`
--
ALTER TABLE `support_sla_rules`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_ticket_attachments`
--
ALTER TABLE `support_ticket_attachments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_ticket_comments`
--
ALTER TABLE `support_ticket_comments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_ticket_comment_attachments`
--
ALTER TABLE `support_ticket_comment_attachments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_ticket_user_tags`
--
ALTER TABLE `support_ticket_user_tags`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tr_settings`
--
ALTER TABLE `tr_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_dept_scope`
--
ALTER TABLE `user_dept_scope`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_groups`
--
ALTER TABLE `user_groups`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_module_access`
--
ALTER TABLE `user_module_access`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vc_former_vcs`
--
ALTER TABLE `vc_former_vcs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vc_scholarship_approvals`
--
ALTER TABLE `vc_scholarship_approvals`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vc_settings`
--
ALTER TABLE `vc_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wf_chains`
--
ALTER TABLE `wf_chains`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wf_chain_steps`
--
ALTER TABLE `wf_chain_steps`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wf_sheet_history`
--
ALTER TABLE `wf_sheet_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `acc_accounts`
--
ALTER TABLE `acc_accounts`
  ADD CONSTRAINT `fk_acc_parent` FOREIGN KEY (`parent_id`) REFERENCES `acc_accounts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `acc_vouchers`
--
ALTER TABLE `acc_vouchers`
  ADD CONSTRAINT `fk_voucher_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_voucher_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `acc_vouchers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_voucher_reverser` FOREIGN KEY (`reversed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `acc_voucher_items`
--
ALTER TABLE `acc_voucher_items`
  ADD CONSTRAINT `fk_vi_account` FOREIGN KEY (`account_id`) REFERENCES `acc_accounts` (`id`),
  ADD CONSTRAINT `fk_vi_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `acc_vouchers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ac_admit_card_courses`
--
ALTER TABLE `ac_admit_card_courses`
  ADD CONSTRAINT `fk_acc_admit_card` FOREIGN KEY (`admit_card_id`) REFERENCES `ac_admit_cards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ac_student_overrides`
--
ALTER TABLE `ac_student_overrides`
  ADD CONSTRAINT `fk_aso_admit_card` FOREIGN KEY (`admit_card_id`) REFERENCES `ac_admit_cards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ac_student_tokens`
--
ALTER TABLE `ac_student_tokens`
  ADD CONSTRAINT `fk_ast_admit_card` FOREIGN KEY (`admit_card_id`) REFERENCES `ac_admit_cards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `admissions_academic_records`
--
ALTER TABLE `admissions_academic_records`
  ADD CONSTRAINT `fk_adm_app_id` FOREIGN KEY (`application_id`) REFERENCES `admissions_applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `admissions_applications`
--
ALTER TABLE `admissions_applications`
  ADD CONSTRAINT `fk_adm_financial_package` FOREIGN KEY (`financial_package_id`) REFERENCES `cf_programs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `adm_form_sales`
--
ALTER TABLE `adm_form_sales`
  ADD CONSTRAINT `fk_fs_application` FOREIGN KEY (`application_id`) REFERENCES `admissions_applications` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `adm_form_sale_academic_records`
--
ALTER TABLE `adm_form_sale_academic_records`
  ADD CONSTRAINT `fk_fsar_form_sale` FOREIGN KEY (`form_sale_id`) REFERENCES `adm_form_sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `adm_form_sale_student_details`
--
ALTER TABLE `adm_form_sale_student_details`
  ADD CONSTRAINT `fk_fssd_form_sale` FOREIGN KEY (`form_sale_id`) REFERENCES `adm_form_sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fssd_token` FOREIGN KEY (`token_id`) REFERENCES `adm_form_sale_tokens` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `adm_form_sale_tokens`
--
ALTER TABLE `adm_form_sale_tokens`
  ADD CONSTRAINT `fk_fst_form_sale` FOREIGN KEY (`form_sale_id`) REFERENCES `adm_form_sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `adm_student_id_settings`
--
ALTER TABLE `adm_student_id_settings`
  ADD CONSTRAINT `fk_sid_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `alumni`
--
ALTER TABLE `alumni`
  ADD CONSTRAINT `fk_alumni_module_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `api_push_tokens`
--
ALTER TABLE `api_push_tokens`
  ADD CONSTRAINT `fk_push_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bd_thanas`
--
ALTER TABLE `bd_thanas`
  ADD CONSTRAINT `fk_thana_district` FOREIGN KEY (`district_id`) REFERENCES `bd_districts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `broadcasts`
--
ALTER TABLE `broadcasts`
  ADD CONSTRAINT `broadcasts_ibfk_1` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `broadcasts_ibfk_2` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `broadcasts_ibfk_3` FOREIGN KEY (`recipient_group_id`) REFERENCES `user_groups` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bc_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bc_student_dept` FOREIGN KEY (`student_dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bc_student_program` FOREIGN KEY (`student_program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `broadcast_attachments`
--
ALTER TABLE `broadcast_attachments`
  ADD CONSTRAINT `broadcast_attachments_ibfk_1` FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `broadcast_recipients`
--
ALTER TABLE `broadcast_recipients`
  ADD CONSTRAINT `broadcast_recipients_ibfk_1` FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cc_mark_distributions`
--
ALTER TABLE `cc_mark_distributions`
  ADD CONSTRAINT `fk_ccmd_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `course_curriculum` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cert_verification_log`
--
ALTER TABLE `cert_verification_log`
  ADD CONSTRAINT `fk_cvl_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `cf_admission_requirements`
--
ALTER TABLE `cf_admission_requirements`
  ADD CONSTRAINT `fk_cfar_prog` FOREIGN KEY (`program_id`) REFERENCES `cf_programs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cf_programs`
--
ALTER TABLE `cf_programs`
  ADD CONSTRAINT `fk_cfp_dtype` FOREIGN KEY (`degree_type_id`) REFERENCES `cf_degree_types` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `change_log`
--
ALTER TABLE `change_log`
  ADD CONSTRAINT `fk_cl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `clubs`
--
ALTER TABLE `clubs`
  ADD CONSTRAINT `fk_clubs_tbl_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_clubs_tbl_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `club_activities`
--
ALTER TABLE `club_activities`
  ADD CONSTRAINT `fk_clubact_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `club_events`
--
ALTER TABLE `club_events`
  ADD CONSTRAINT `fk_clubev_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `club_event_registrations`
--
ALTER TABLE `club_event_registrations`
  ADD CONSTRAINT `fk_clubreg_event` FOREIGN KEY (`event_id`) REFERENCES `club_events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `club_members`
--
ALTER TABLE `club_members`
  ADD CONSTRAINT `fk_clubmem_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `club_photos`
--
ALTER TABLE `club_photos`
  ADD CONSTRAINT `fk_clubphoto_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cms_coc_items`
--
ALTER TABLE `cms_coc_items`
  ADD CONSTRAINT `fk_coc_items_section` FOREIGN KEY (`section_id`) REFERENCES `cms_coc_sections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `course_curriculum`
--
ALTER TABLE `course_curriculum`
  ADD CONSTRAINT `fk_cc_assigned_faculty` FOREIGN KEY (`assigned_faculty_id`) REFERENCES `dept_faculty` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cc_intake` FOREIGN KEY (`intake_id`) REFERENCES `course_curriculum_intakes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cc_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `course_curriculum_intakes`
--
ALTER TABLE `course_curriculum_intakes`
  ADD CONSTRAINT `fk_cci_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `co_offers`
--
ALTER TABLE `co_offers`
  ADD CONSTRAINT `fk_co_batch` FOREIGN KEY (`batch_id`) REFERENCES `student_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_co_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_co_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `co_offer_subjects`
--
ALTER TABLE `co_offer_subjects`
  ADD CONSTRAINT `fk_cos_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `course_curriculum` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cos_offer` FOREIGN KEY (`offer_id`) REFERENCES `co_offers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `co_offer_subject_teachers`
--
ALTER TABLE `co_offer_subject_teachers`
  ADD CONSTRAINT `fk_cost_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `dept_faculty` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cost_subject` FOREIGN KEY (`offer_subject_id`) REFERENCES `co_offer_subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `crm_import_agents`
--
ALTER TABLE `crm_import_agents`
  ADD CONSTRAINT `crm_import_fk_agents_user_id` FOREIGN KEY (`user_id`) REFERENCES `crm_import_users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_agents_user_id` FOREIGN KEY (`user_id`) REFERENCES `crm_import_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `crm_import_call_logs`
--
ALTER TABLE `crm_import_call_logs`
  ADD CONSTRAINT `crm_import_fk_call_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `crm_import_fk_call_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `fk_call_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_call_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `crm_import_users` (`id`);

--
-- Constraints for table `crm_import_companies`
--
ALTER TABLE `crm_import_companies`
  ADD CONSTRAINT `companies_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `crm_import_companies_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `crm_import_company_subscriptions`
--
ALTER TABLE `crm_import_company_subscriptions`
  ADD CONSTRAINT `company_subscriptions_company_fk` FOREIGN KEY (`company_id`) REFERENCES `crm_import_companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `company_subscriptions_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `company_subscriptions_plan_fk` FOREIGN KEY (`subscription_plan_id`) REFERENCES `crm_import_subscription_plans` (`id`),
  ADD CONSTRAINT `crm_import_company_subscriptions_company_fk` FOREIGN KEY (`company_id`) REFERENCES `crm_import_companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `crm_import_company_subscriptions_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `crm_import_company_subscriptions_plan_fk` FOREIGN KEY (`subscription_plan_id`) REFERENCES `crm_import_subscription_plans` (`id`);

--
-- Constraints for table `crm_import_courses`
--
ALTER TABLE `crm_import_courses`
  ADD CONSTRAINT `crm_import_fk_courses_created_by` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `crm_import_fk_courses_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_courses_created_by` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_courses_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `crm_import_leads`
--
ALTER TABLE `crm_import_leads`
  ADD CONSTRAINT `crm_import_fk_leads_agent_id` FOREIGN KEY (`agent_id`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `crm_import_fk_leads_company_id` FOREIGN KEY (`company_id`) REFERENCES `crm_import_companies` (`id`),
  ADD CONSTRAINT `crm_import_fk_leads_created_by` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `crm_import_fk_leads_interested_course` FOREIGN KEY (`interested_course_id`) REFERENCES `crm_import_courses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `crm_import_fk_leads_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leads_agent_id` FOREIGN KEY (`agent_id`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leads_company_id` FOREIGN KEY (`company_id`) REFERENCES `crm_import_companies` (`id`),
  ADD CONSTRAINT `fk_leads_created_by` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `fk_leads_interested_course` FOREIGN KEY (`interested_course_id`) REFERENCES `crm_import_courses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_leads_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `crm_import_lead_courses`
--
ALTER TABLE `crm_import_lead_courses`
  ADD CONSTRAINT `crm_import_fk_lead_course_added_by` FOREIGN KEY (`added_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `crm_import_fk_lead_course_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lead_course_added_by` FOREIGN KEY (`added_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `fk_lead_course_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `crm_import_lead_edit_history`
--
ALTER TABLE `crm_import_lead_edit_history`
  ADD CONSTRAINT `crm_import_fk_lead_history_edited_by` FOREIGN KEY (`edited_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `crm_import_fk_lead_history_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lead_history_edited_by` FOREIGN KEY (`edited_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `fk_lead_history_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `crm_import_lead_notes`
--
ALTER TABLE `crm_import_lead_notes`
  ADD CONSTRAINT `crm_import_fk_lead_notes_created_by` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `crm_import_fk_lead_notes_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lead_notes_created_by` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `fk_lead_notes_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `crm_import_lead_semesters`
--
ALTER TABLE `crm_import_lead_semesters`
  ADD CONSTRAINT `crm_import_fk_lead_intake_added_by` FOREIGN KEY (`added_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `crm_import_fk_lead_intake_intake_id` FOREIGN KEY (`semester_id`) REFERENCES `crm_import_semesters` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `crm_import_fk_lead_intake_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lead_intake_added_by` FOREIGN KEY (`added_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `fk_lead_intake_intake_id` FOREIGN KEY (`semester_id`) REFERENCES `crm_import_semesters` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lead_intake_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `crm_import_lead_staff_assignments`
--
ALTER TABLE `crm_import_lead_staff_assignments`
  ADD CONSTRAINT `crm_import_fk_assignment_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `crm_import_fk_assignment_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `crm_import_fk_assignment_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `fk_assignment_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `fk_assignment_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_assignment_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `crm_import_users` (`id`);

--
-- Constraints for table `crm_import_lead_universities`
--
ALTER TABLE `crm_import_lead_universities`
  ADD CONSTRAINT `crm_import_fk_lead_university_added_by` FOREIGN KEY (`added_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `crm_import_fk_lead_university_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `crm_import_fk_lead_university_university_id` FOREIGN KEY (`university_id`) REFERENCES `crm_import_universities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lead_university_added_by` FOREIGN KEY (`added_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `fk_lead_university_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `crm_import_leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lead_university_university_id` FOREIGN KEY (`university_id`) REFERENCES `crm_import_universities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `crm_import_semesters`
--
ALTER TABLE `crm_import_semesters`
  ADD CONSTRAINT `crm_import_semesters_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `crm_import_semesters_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `semesters_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`),
  ADD CONSTRAINT `semesters_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `crm_import_users` (`id`);

--
-- Constraints for table `crm_import_subscription_payments`
--
ALTER TABLE `crm_import_subscription_payments`
  ADD CONSTRAINT `crm_import_subscription_payments_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `crm_import_subscription_payments_subscription_fk` FOREIGN KEY (`company_subscription_id`) REFERENCES `crm_import_company_subscriptions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscription_payments_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `subscription_payments_subscription_fk` FOREIGN KEY (`company_subscription_id`) REFERENCES `crm_import_company_subscriptions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `crm_import_users`
--
ALTER TABLE `crm_import_users`
  ADD CONSTRAINT `crm_import_users_company_fk` FOREIGN KEY (`company_id`) REFERENCES `crm_import_companies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `crm_import_users_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `crm_import_users` (`id`);

--
-- Constraints for table `crm_import_user_profile`
--
ALTER TABLE `crm_import_user_profile`
  ADD CONSTRAINT `crm_import_user_profile_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `crm_import_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_academic_programs`
--
ALTER TABLE `dept_academic_programs`
  ADD CONSTRAINT `fk_academic_programs_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_alumni`
--
ALTER TABLE `dept_alumni`
  ADD CONSTRAINT `fk_alumni_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_clubs`
--
ALTER TABLE `dept_clubs`
  ADD CONSTRAINT `fk_clubs_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_events`
--
ALTER TABLE `dept_events`
  ADD CONSTRAINT `fk_events_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_facilities`
--
ALTER TABLE `dept_facilities`
  ADD CONSTRAINT `fk_facilities_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_faculty`
--
ALTER TABLE `dept_faculty`
  ADD CONSTRAINT `fk_df_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_faculty_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_hero_slides`
--
ALTER TABLE `dept_hero_slides`
  ADD CONSTRAINT `fk_hero_slides_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_notices`
--
ALTER TABLE `dept_notices`
  ADD CONSTRAINT `fk_notices_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_overview`
--
ALTER TABLE `dept_overview`
  ADD CONSTRAINT `fk_overview_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_prime_pride`
--
ALTER TABLE `dept_prime_pride`
  ADD CONSTRAINT `fk_prime_pride_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_routines`
--
ALTER TABLE `dept_routines`
  ADD CONSTRAINT `fk_routines_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ei_assignment_snapshots`
--
ALTER TABLE `ei_assignment_snapshots`
  ADD CONSTRAINT `fk_ei_snap_exam` FOREIGN KEY (`exam_id`) REFERENCES `ei_exams` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ei_assignment_snapshot_slots`
--
ALTER TABLE `ei_assignment_snapshot_slots`
  ADD CONSTRAINT `fk_ei_ss_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `ei_assignment_snapshots` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ei_faculty`
--
ALTER TABLE `ei_faculty`
  ADD CONSTRAINT `fk_ei_faculty_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ei_slots`
--
ALTER TABLE `ei_slots`
  ADD CONSTRAINT `fk_ei_slots_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ei_slots_exam` FOREIGN KEY (`exam_id`) REFERENCES `ei_exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ei_slots_fac1` FOREIGN KEY (`faculty1_id`) REFERENCES `ei_faculty` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ei_slots_fac2` FOREIGN KEY (`faculty2_id`) REFERENCES `ei_faculty` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ei_slot_attendance`
--
ALTER TABLE `ei_slot_attendance`
  ADD CONSTRAINT `fk_eia_exam` FOREIGN KEY (`exam_id`) REFERENCES `ei_exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_eia_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `ei_faculty` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_eia_slot` FOREIGN KEY (`slot_id`) REFERENCES `ei_slots` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `faculty_files`
--
ALTER TABLE `faculty_files`
  ADD CONSTRAINT `fk_ff_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `faculty_file_delete_requests`
--
ALTER TABLE `faculty_file_delete_requests`
  ADD CONSTRAINT `fk_fdr_file` FOREIGN KEY (`file_id`) REFERENCES `faculty_files` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fdr_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fdr_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `faculty_profiles`
--
ALTER TABLE `faculty_profiles`
  ADD CONSTRAINT `fk_fp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `faculty_registrations`
--
ALTER TABLE `faculty_registrations`
  ADD CONSTRAINT `fk_fr_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_fr_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `faculty_subject_assignments`
--
ALTER TABLE `faculty_subject_assignments`
  ADD CONSTRAINT `fk_fsa_course` FOREIGN KEY (`course_id`) REFERENCES `course_curriculum` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fsa_faculty` FOREIGN KEY (`faculty_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `fake_id_verifications`
--
ALTER TABLE `fake_id_verifications`
  ADD CONSTRAINT `fk_fiv_user` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `file_manager_pages`
--
ALTER TABLE `file_manager_pages`
  ADD CONSTRAINT `fk_fmp_file` FOREIGN KEY (`file_id`) REFERENCES `file_manager_files` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `file_manager_page_signatures`
--
ALTER TABLE `file_manager_page_signatures`
  ADD CONSTRAINT `fk_fmps_page` FOREIGN KEY (`page_id`) REFERENCES `file_manager_pages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fmps_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `file_manager_page_sign_positions`
--
ALTER TABLE `file_manager_page_sign_positions`
  ADD CONSTRAINT `fk_fmpsp_page` FOREIGN KEY (`page_id`) REFERENCES `file_manager_pages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fmpsp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `file_manager_page_text_notes`
--
ALTER TABLE `file_manager_page_text_notes`
  ADD CONSTRAINT `fk_fmptn_page` FOREIGN KEY (`page_id`) REFERENCES `file_manager_pages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `file_manager_tagged_users`
--
ALTER TABLE `file_manager_tagged_users`
  ADD CONSTRAINT `fk_fmtu_file` FOREIGN KEY (`file_id`) REFERENCES `file_manager_files` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fmtu_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `file_manager_transfers`
--
ALTER TABLE `file_manager_transfers`
  ADD CONSTRAINT `fk_fmt_file` FOREIGN KEY (`file_id`) REFERENCES `file_manager_files` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fmt_from_user` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fmt_to_user` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `gallery_albums`
--
ALTER TABLE `gallery_albums`
  ADD CONSTRAINT `fk_gal_albums_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_gal_albums_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `gallery_photos`
--
ALTER TABLE `gallery_photos`
  ADD CONSTRAINT `fk_gal_photos_album` FOREIGN KEY (`album_id`) REFERENCES `gallery_albums` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_dept_scope`
--
ALTER TABLE `group_dept_scope`
  ADD CONSTRAINT `group_dept_scope_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_dept_scope_ibfk_2` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_module_access`
--
ALTER TABLE `group_module_access`
  ADD CONSTRAINT `group_module_access_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_module_access_ibfk_2` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD CONSTRAINT `fk_job_applications_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leads`
--
ALTER TABLE `leads`
  ADD CONSTRAINT `fk_leads_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leads_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leads_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leads_updated` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `lead_appointments`
--
ALTER TABLE `lead_appointments`
  ADD CONSTRAINT `fk_appt_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appt_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `lead_assignments`
--
ALTER TABLE `lead_assignments`
  ADD CONSTRAINT `fk_la_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_la_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `lead_fb_contacts`
--
ALTER TABLE `lead_fb_contacts`
  ADD CONSTRAINT `fk_fbc_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `lead_fb_messages`
--
ALTER TABLE `lead_fb_messages`
  ADD CONSTRAINT `fk_fbm_contact` FOREIGN KEY (`contact_id`) REFERENCES `lead_fb_contacts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fbm_sent_by` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `lead_history`
--
ALTER TABLE `lead_history`
  ADD CONSTRAINT `fk_lh_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `lead_notes`
--
ALTER TABLE `lead_notes`
  ADD CONSTRAINT `fk_ln_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ln_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `library_audit_log`
--
ALTER TABLE `library_audit_log`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `library_books`
--
ALTER TABLE `library_books`
  ADD CONSTRAINT `fk_book_category` FOREIGN KEY (`category_id`) REFERENCES `library_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_book_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_book_department` FOREIGN KEY (`department_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `library_book_copies`
--
ALTER TABLE `library_book_copies`
  ADD CONSTRAINT `fk_copy_book` FOREIGN KEY (`book_id`) REFERENCES `library_books` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `library_categories`
--
ALTER TABLE `library_categories`
  ADD CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `library_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `library_circulation`
--
ALTER TABLE `library_circulation`
  ADD CONSTRAINT `fk_circ_book` FOREIGN KEY (`book_id`) REFERENCES `library_books` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_circ_copy` FOREIGN KEY (`copy_id`) REFERENCES `library_book_copies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_circ_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_circ_member` FOREIGN KEY (`member_id`) REFERENCES `library_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_circ_returned_to` FOREIGN KEY (`returned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `library_digital_resources`
--
ALTER TABLE `library_digital_resources`
  ADD CONSTRAINT `fk_digres_category` FOREIGN KEY (`category_id`) REFERENCES `library_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_digres_department` FOREIGN KEY (`department_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_digres_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `library_fines`
--
ALTER TABLE `library_fines`
  ADD CONSTRAINT `fk_fine_circulation` FOREIGN KEY (`circulation_id`) REFERENCES `library_circulation` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fine_collected_by` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fine_member` FOREIGN KEY (`member_id`) REFERENCES `library_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `library_members`
--
ALTER TABLE `library_members`
  ADD CONSTRAINT `fk_member_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_member_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_member_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `library_notifications`
--
ALTER TABLE `library_notifications`
  ADD CONSTRAINT `fk_notif_member` FOREIGN KEY (`member_id`) REFERENCES `library_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `library_reservations`
--
ALTER TABLE `library_reservations`
  ADD CONSTRAINT `fk_resv_book` FOREIGN KEY (`book_id`) REFERENCES `library_books` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_resv_member` FOREIGN KEY (`member_id`) REFERENCES `library_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_resv_reserved_by` FOREIGN KEY (`reserved_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `notice_signatures`
--
ALTER TABLE `notice_signatures`
  ADD CONSTRAINT `fk_ns_doc` FOREIGN KEY (`document_id`) REFERENCES `notice_documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ns_pos` FOREIGN KEY (`position_id`) REFERENCES `notice_sign_positions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notice_sign_positions`
--
ALTER TABLE `notice_sign_positions`
  ADD CONSTRAINT `fk_nsp_doc` FOREIGN KEY (`document_id`) REFERENCES `notice_documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_nsp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `page_profiles`
--
ALTER TABLE `page_profiles`
  ADD CONSTRAINT `page_profiles_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `program_eligibility_criteria`
--
ALTER TABLE `program_eligibility_criteria`
  ADD CONSTRAINT `fk_pec_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `program_intake_periods`
--
ALTER TABLE `program_intake_periods`
  ADD CONSTRAINT `fk_pip_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `result_exams`
--
ALTER TABLE `result_exams`
  ADD CONSTRAINT `fk_re_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_re_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `result_grades`
--
ALTER TABLE `result_grades`
  ADD CONSTRAINT `fk_rg_exam` FOREIGN KEY (`exam_id`) REFERENCES `result_exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rg_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rg_subject` FOREIGN KEY (`subject_id`) REFERENCES `result_subjects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `result_grade_details`
--
ALTER TABLE `result_grade_details`
  ADD CONSTRAINT `fk_rgd_category` FOREIGN KEY (`category_id`) REFERENCES `result_mark_categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rgd_grade` FOREIGN KEY (`grade_id`) REFERENCES `result_grades` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `result_mark_categories`
--
ALTER TABLE `result_mark_categories`
  ADD CONSTRAINT `fk_rmc_subject` FOREIGN KEY (`subject_id`) REFERENCES `result_subjects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `result_mark_sheets`
--
ALTER TABLE `result_mark_sheets`
  ADD CONSTRAINT `fk_rms_chain` FOREIGN KEY (`chain_id`) REFERENCES `wf_chains` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rms_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rms_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `course_curriculum` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rms_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rms_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `result_sheet_grades`
--
ALTER TABLE `result_sheet_grades`
  ADD CONSTRAINT `fk_rsg_sheet` FOREIGN KEY (`sheet_id`) REFERENCES `result_mark_sheets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rsg_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `result_subjects`
--
ALTER TABLE `result_subjects`
  ADD CONSTRAINT `fk_rs_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `course_curriculum` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rs_exam` FOREIGN KEY (`exam_id`) REFERENCES `result_exams` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sc_awards`
--
ALTER TABLE `sc_awards`
  ADD CONSTRAINT `fk_sca_awarded_by` FOREIGN KEY (`awarded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sca_policy` FOREIGN KEY (`policy_id`) REFERENCES `sc_policies` (`id`),
  ADD CONSTRAINT `fk_sca_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sca_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sca_tier` FOREIGN KEY (`tier_id`) REFERENCES `sc_tiers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sc_policies`
--
ALTER TABLE `sc_policies`
  ADD CONSTRAINT `fk_scp_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sc_tiers`
--
ALTER TABLE `sc_tiers`
  ADD CONSTRAINT `fk_sct_policy` FOREIGN KEY (`policy_id`) REFERENCES `sc_policies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sfp_packages`
--
ALTER TABLE `sfp_packages`
  ADD CONSTRAINT `fk_sfp_assigned` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sfp_cf_program` FOREIGN KEY (`cf_program_id`) REFERENCES `cf_programs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sfp_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sfp_payments`
--
ALTER TABLE `sfp_payments`
  ADD CONSTRAINT `fk_sfpp_collector` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sfpp_package` FOREIGN KEY (`package_id`) REFERENCES `sfp_packages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sfpp_sem_fee` FOREIGN KEY (`semester_fee_id`) REFERENCES `sfp_semester_fees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sfpp_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sfpp_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `acc_vouchers` (`id`);

--
-- Constraints for table `sfp_semester_fees`
--
ALTER TABLE `sfp_semester_fees`
  ADD CONSTRAINT `fk_sfpsf_award` FOREIGN KEY (`scholarship_award_id`) REFERENCES `sc_awards` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sfpsf_package` FOREIGN KEY (`package_id`) REFERENCES `sfp_packages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sfpsf_updated` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sfp_semester_scholarships`
--
ALTER TABLE `sfp_semester_scholarships`
  ADD CONSTRAINT `fk_sfpss_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sfpss_doc` FOREIGN KEY (`support_doc_id`) REFERENCES `student_files` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sfpss_sf` FOREIGN KEY (`sf_id`) REFERENCES `sfp_semester_fees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sr_result_entries`
--
ALTER TABLE `sr_result_entries`
  ADD CONSTRAINT `fk_sre_result` FOREIGN KEY (`result_id`) REFERENCES `sr_results` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_departments`
--
ALTER TABLE `staff_departments`
  ADD CONSTRAINT `fk_sd_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `staff_profiles`
--
ALTER TABLE `staff_profiles`
  ADD CONSTRAINT `fk_sp_dept` FOREIGN KEY (`staff_dept_id`) REFERENCES `staff_departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_batch` FOREIGN KEY (`batch_id`) REFERENCES `student_batches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_students_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`),
  ADD CONSTRAINT `fk_students_district` FOREIGN KEY (`district_id`) REFERENCES `bd_districts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_students_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_students_thana` FOREIGN KEY (`thana_id`) REFERENCES `bd_thanas` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `student_academic_qualifications`
--
ALTER TABLE `student_academic_qualifications`
  ADD CONSTRAINT `fk_qual_board` FOREIGN KEY (`board_id`) REFERENCES `student_boards` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_qual_exam_title` FOREIGN KEY (`exam_title_id`) REFERENCES `student_exam_titles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_qual_group` FOREIGN KEY (`group_id`) REFERENCES `student_groups` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_qual_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_comments`
--
ALTER TABLE `student_comments`
  ADD CONSTRAINT `fk_comments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_files`
--
ALTER TABLE `student_files`
  ADD CONSTRAINT `fk_files_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_pdf_pending`
--
ALTER TABLE `student_pdf_pending`
  ADD CONSTRAINT `fk_spp_student` FOREIGN KEY (`assigned_student_pk`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_spp_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_results`
--
ALTER TABLE `student_results`
  ADD CONSTRAINT `fk_sr_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `student_verifications`
--
ALTER TABLE `student_verifications`
  ADD CONSTRAINT `fk_sv_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sv_user` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `fk_st_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_st_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `support_ticket_attachments`
--
ALTER TABLE `support_ticket_attachments`
  ADD CONSTRAINT `fk_sta_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sta_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `support_ticket_comments`
--
ALTER TABLE `support_ticket_comments`
  ADD CONSTRAINT `fk_stc_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stc_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `support_ticket_comment_attachments`
--
ALTER TABLE `support_ticket_comment_attachments`
  ADD CONSTRAINT `fk_stca_comment` FOREIGN KEY (`comment_id`) REFERENCES `support_ticket_comments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `support_ticket_user_tags`
--
ALTER TABLE `support_ticket_user_tags`
  ADD CONSTRAINT `fk_stut_tagger` FOREIGN KEY (`tagged_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stut_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stut_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`);

--
-- Constraints for table `user_dept_scope`
--
ALTER TABLE `user_dept_scope`
  ADD CONSTRAINT `user_dept_scope_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_dept_scope_ibfk_2` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_group_assignments`
--
ALTER TABLE `user_group_assignments`
  ADD CONSTRAINT `user_group_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_group_assignments_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_module_access`
--
ALTER TABLE `user_module_access`
  ADD CONSTRAINT `user_module_access_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_module_access_ibfk_2` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `wf_chains`
--
ALTER TABLE `wf_chains`
  ADD CONSTRAINT `fk_wfc_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wfc_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wfc_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `wf_chain_steps`
--
ALTER TABLE `wf_chain_steps`
  ADD CONSTRAINT `fk_wfcs_chain` FOREIGN KEY (`chain_id`) REFERENCES `wf_chains` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wfcs_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `wf_sheet_history`
--
ALTER TABLE `wf_sheet_history`
  ADD CONSTRAINT `fk_wfsh_sheet` FOREIGN KEY (`sheet_id`) REFERENCES `result_mark_sheets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wfsh_user` FOREIGN KEY (`acted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
