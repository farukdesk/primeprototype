-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Apr 03, 2026 at 10:27 AM
-- Server version: 10.6.23-MariaDB-0ubuntu0.22.04.1
-- PHP Version: 8.4.17

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
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
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
-- Table structure for table `dept_academic_programs`
--

CREATE TABLE `dept_academic_programs` (
  `id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `program_name` varchar(300) NOT NULL,
  `degree_type` varchar(100) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `total_credit` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
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
  `position` varchar(200) DEFAULT NULL,
  `company` varchar(200) DEFAULT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
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
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` varchar(20) NOT NULL COMMENT '12-digit auto-generated or manual ID',
  `dept_id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED DEFAULT NULL,
  `admitted_semester` varchar(50) NOT NULL COMMENT 'e.g. Summer 2025',
  `full_name` varchar(200) NOT NULL,
  `father_name` varchar(200) DEFAULT NULL,
  `father_phone` varchar(30) DEFAULT NULL,
  `father_occupation` varchar(200) DEFAULT NULL,
  `father_yearly_income` decimal(15,2) DEFAULT NULL,
  `mother_name` varchar(200) DEFAULT NULL,
  `mother_phone` varchar(30) DEFAULT NULL,
  `mother_occupation` varchar(200) DEFAULT NULL,
  `mother_yearly_income` decimal(15,2) DEFAULT NULL,
  `present_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `place_of_birth` varchar(200) DEFAULT NULL,
  `sex` enum('Male','Female','Other') DEFAULT NULL,
  `religion` varchar(100) DEFAULT NULL,
  `photo` varchar(300) DEFAULT NULL,
  `status` enum('Active','Inactive','Graduated','Dropped') NOT NULL DEFAULT 'Active',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_academic_qualifications`
--

CREATE TABLE `student_academic_qualifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `exam_name` varchar(200) DEFAULT NULL COMMENT 'e.g. SSC, HSC, B.Sc.',
  `session` varchar(100) DEFAULT NULL,
  `group_name` varchar(100) DEFAULT NULL,
  `board_university` varchar(200) DEFAULT NULL,
  `passing_year` varchar(20) DEFAULT NULL,
  `division_class_grade` varchar(100) DEFAULT NULL,
  `obtained_marks_gpa` varchar(100) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
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
  `deadline` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
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
  `avatar` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
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

--
-- Indexes for dumped tables
--

--
-- Indexes for table `change_log`
--
ALTER TABLE `change_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cl_user` (`user_id`),
  ADD KEY `idx_cl_module` (`module`),
  ADD KEY `idx_cl_created` (`created_at`);

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
  ADD UNIQUE KEY `uq_slug` (`slug`(191));

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
  ADD UNIQUE KEY `uq_notice_slug` (`slug`);

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
-- Indexes for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contact_messages_is_read` (`is_read`),
  ADD KEY `idx_contact_messages_created` (`created_at`);

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
-- Indexes for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `action` (`action`),
  ADD KEY `idx_action` (`action`);

--
-- Indexes for table `faculty_profiles`
--
ALTER TABLE `faculty_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_id` (`user_id`);

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
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_slug` (`slug`),
  ADD KEY `idx_parent` (`parent_id`);

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
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`),
  ADD KEY `idx_students_dept` (`dept_id`),
  ADD KEY `idx_students_program` (`program_id`),
  ADD KEY `idx_students_status` (`status`);

--
-- Indexes for table `student_academic_qualifications`
--
ALTER TABLE `student_academic_qualifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_qual_student` (`student_id`);

--
-- Indexes for table `student_comments`
--
ALTER TABLE `student_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_comments_student` (`student_id`);

--
-- Indexes for table `student_files`
--
ALTER TABLE `student_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_files_student` (`student_id`);

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
-- Indexes for table `user_groups`
--
ALTER TABLE `user_groups`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `change_log`
--
ALTER TABLE `change_log`
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
-- AUTO_INCREMENT for table `contact_messages`
--
ALTER TABLE `contact_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty_profiles`
--
ALTER TABLE `faculty_profiles`
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
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
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
-- AUTO_INCREMENT for table `student_comments`
--
ALTER TABLE `student_comments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_files`
--
ALTER TABLE `student_files`
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
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_groups`
--
ALTER TABLE `user_groups`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `change_log`
--
ALTER TABLE `change_log`
  ADD CONSTRAINT `fk_cl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `faculty_profiles`
--
ALTER TABLE `faculty_profiles`
  ADD CONSTRAINT `fk_fp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `page_profiles`
--
ALTER TABLE `page_profiles`
  ADD CONSTRAINT `page_profiles_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`),
  ADD CONSTRAINT `fk_students_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `student_academic_qualifications`
--
ALTER TABLE `student_academic_qualifications`
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
-- Constraints for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `fk_st_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_st_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
-- Seed data for table `email_templates`
--

INSERT INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`) VALUES
(
  'Forgot Password',
  'forgot_password',
  'Reset Your Password – {{app_name}}',
  '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reset Your Password</title>
<style>
  body { margin:0; padding:0; background:#f4f6fb; font-family:''Inter'',Arial,sans-serif; }
  .wrapper { max-width:580px; margin:40px auto; background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
  .header { background:linear-gradient(135deg,#1a1f36 0%,#2d3561 100%); padding:36px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:1.5rem; font-weight:700; }
  .header p  { color:rgba(255,255,255,.7); margin:8px 0 0; font-size:.9rem; }
  .body { padding:36px 40px; color:#374151; }
  .body p  { margin:0 0 16px; line-height:1.7; font-size:.925rem; }
  .btn-wrap { text-align:center; margin:28px 0; }
  .btn { display:inline-block; padding:14px 36px; background:linear-gradient(135deg,#4f8ef7,#2d63e8); color:#fff !important;
         text-decoration:none; border-radius:10px; font-weight:600; font-size:.95rem; }
  .expire { background:#fff8e1; border-left:4px solid #f5a623; padding:12px 16px; border-radius:6px; font-size:.85rem; color:#7a5c00; margin:20px 0; }
  .footer { background:#f4f6fb; padding:20px 40px; text-align:center; font-size:.78rem; color:#9ca3af; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>Password Reset Request</h1>
    <p>{{app_name}}</p>
  </div>
  <div class="body">
    <p>Hi <strong>{{full_name}}</strong>,</p>
    <p>We received a request to reset the password for your admin account. Click the button below to choose a new password:</p>
    <div class="btn-wrap">
      <a href="{{reset_link}}" class="btn">Reset My Password</a>
    </div>
    <div class="expire">
      <strong>⏰ This link expires in {{expire_minutes}} minutes.</strong><br>
      If you did not request a password reset, please ignore this email – your account remains secure.
    </div>
    <p>If the button above does not work, copy and paste the following link into your browser:</p>
    <p style="word-break:break-all;font-size:.82rem;color:#6b7280;">{{reset_link}}</p>
  </div>
  <div class="footer">
    &copy; {{app_name}} &mdash; This is an automated message, please do not reply.
  </div>
</div>
</body>
</html>',
  '{{full_name}},{{reset_link}},{{app_name}},{{expire_minutes}}',
  1
)
ON DUPLICATE KEY UPDATE
  `name`      = VALUES(`name`),
  `subject`   = VALUES(`subject`),
  `body_html` = VALUES(`body_html`),
  `variables` = VALUES(`variables`),
  `is_active` = VALUES(`is_active`);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
