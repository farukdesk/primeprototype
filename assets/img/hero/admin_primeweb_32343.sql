-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Feb 16, 2026 at 09:30 AM
-- Server version: 10.6.23-MariaDB-0ubuntu0.22.04.1
-- PHP Version: 8.4.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `admin_primeweb_32343`
--

-- --------------------------------------------------------

--
-- Table structure for table `aca_calenders`
--

CREATE TABLE `aca_calenders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `upload_file` varchar(191) NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `aca_programs`
--

CREATE TABLE `aca_programs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `p_name` varchar(191) NOT NULL,
  `credits` varchar(191) NOT NULL,
  `m_year` varchar(191) NOT NULL,
  `m_course` varchar(191) NOT NULL,
  `cgpa` varchar(191) NOT NULL,
  `p_objective` longtext NOT NULL,
  `p_outcome` longtext NOT NULL,
  `c_structure` longtext NOT NULL,
  `c_distribution` longtext NOT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `status` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `administrators`
--

CREATE TABLE `administrators` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `o_name` varchar(191) NOT NULL,
  `status` bigint(20) NOT NULL,
  `priority` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admission_promotions`
--

CREATE TABLE `admission_promotions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `a_title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admission_requirements`
--

CREATE TABLE `admission_requirements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` int(11) NOT NULL,
  `schedule` varchar(191) NOT NULL,
  `a_l_date` varchar(191) NOT NULL,
  `a_t_date` varchar(191) NOT NULL,
  `a_requirement` longtext NOT NULL,
  `how_to_apply` longtext NOT NULL,
  `admission_fee` longtext NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batches`
--

CREATE TABLE `batches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `batch` varchar(191) NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `breaking_news`
--

CREATE TABLE `breaking_news` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `b_title` text NOT NULL,
  `url` text DEFAULT NULL,
  `file` varchar(191) NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_exams`
--

CREATE TABLE `class_exams` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `file` varchar(191) NOT NULL,
  `date` varchar(191) NOT NULL,
  `type` varchar(191) NOT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `status` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clubs`
--

CREATE TABLE `clubs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `description` longtext DEFAULT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club_details`
--

CREATE TABLE `club_details` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `vision` longtext NOT NULL,
  `activities` longtext NOT NULL,
  `faculty_member_id` bigint(20) NOT NULL,
  `description` longtext NOT NULL,
  `club_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club__committees`
--

CREATE TABLE `club__committees` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `designation` varchar(191) NOT NULL,
  `name` varchar(191) NOT NULL,
  `batch` varchar(191) NOT NULL,
  `club_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `controller_of_examinations`
--

CREATE TABLE `controller_of_examinations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `papers` longtext NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `controller_of_examination_applications`
--

CREATE TABLE `controller_of_examination_applications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `a_title` varchar(191) NOT NULL,
  `uplode_file` varchar(191) NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `convocation_register`
--

CREATE TABLE `convocation_register` (
  `id` int(11) NOT NULL,
  `serial_no` varchar(20) NOT NULL,
  `year` varchar(10) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `program_name` varchar(255) DEFAULT NULL,
  `degree_type` varchar(100) DEFAULT NULL,
  `admission_year` varchar(10) DEFAULT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `batch` varchar(50) DEFAULT NULL,
  `total_credits` decimal(10,2) DEFAULT NULL,
  `cgpa` decimal(3,2) DEFAULT NULL,
  `contact` varchar(20) NOT NULL,
  `email` varchar(255) NOT NULL,
  `degree_selection` varchar(200) DEFAULT NULL,
  `another_id` varchar(222) DEFAULT NULL,
  `payment_status` varchar(1) DEFAULT 'N',
  `pay_amount` varchar(222) DEFAULT NULL,
  `payment_type` varchar(255) DEFAULT NULL,
  `payment_methode` varchar(255) DEFAULT NULL,
  `transaction_id` varchar(222) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_ext` varchar(10) DEFAULT NULL,
  `file_content` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `convo_eligible`
--

CREATE TABLE `convo_eligible` (
  `id` int(11) NOT NULL,
  `student_name` varchar(100) NOT NULL,
  `program_name` varchar(50) NOT NULL,
  `admission_year` int(11) NOT NULL,
  `semester` varchar(20) NOT NULL,
  `total_credits` float NOT NULL,
  `degree_type` varchar(20) NOT NULL,
  `cgpa` decimal(3,2) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `cell_email` varchar(255) DEFAULT NULL,
  `batch` varchar(10) DEFAULT NULL,
  `eligibility` char(5) DEFAULT NULL,
  `payment` char(1) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crhps`
--

CREATE TABLE `crhps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `about` longtext NOT NULL,
  `vision` longtext NOT NULL,
  `mission` longtext NOT NULL,
  `responsibilities` longtext NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crhp_details`
--

CREATE TABLE `crhp_details` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `file` varchar(191) NOT NULL,
  `date` varchar(191) DEFAULT NULL,
  `category` int(11) NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `faculty_id` bigint(20) UNSIGNED NOT NULL,
  `status` int(11) DEFAULT 1,
  `priority` int(11) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_addresses`
--

CREATE TABLE `dept_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `address` longtext DEFAULT NULL,
  `phone_number` longtext DEFAULT NULL,
  `email` longtext DEFAULT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_events`
--

CREATE TABLE `dept_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `date` varchar(191) NOT NULL,
  `place` varchar(191) NOT NULL,
  `description` longtext DEFAULT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_news_media`
--

CREATE TABLE `dept_news_media` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `date` varchar(191) NOT NULL,
  `description` longtext DEFAULT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_notable_alumnis`
--

CREATE TABLE `dept_notable_alumnis` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `designation` varchar(191) NOT NULL,
  `description` varchar(191) DEFAULT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept_notices`
--

CREATE TABLE `dept_notices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `date` varchar(191) NOT NULL,
  `file` varchar(191) NOT NULL,
  `description` longtext NOT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dept__details`
--

CREATE TABLE `dept__details` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `designations`
--

CREATE TABLE `designations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `designation` varchar(191) DEFAULT NULL,
  `adi_designation` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `detp_sliders`
--

CREATE TABLE `detp_sliders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employs`
--

CREATE TABLE `employs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `e_name` varchar(191) NOT NULL,
  `e_designation` varchar(191) NOT NULL,
  `email` text NOT NULL,
  `image` varchar(191) NOT NULL,
  `about` varchar(191) NOT NULL,
  `number` text NOT NULL,
  `administrators_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` bigint(20) DEFAULT NULL,
  `parity` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_infos`
--

CREATE TABLE `exam_infos` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `exam_name` varchar(191) NOT NULL,
  `exam_session_id` bigint(20) UNSIGNED NOT NULL,
  `date_of_exam` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_sessions`
--

CREATE TABLE `exam_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `exam_session` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `facilities`
--

CREATE TABLE `facilities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `description` longtext DEFAULT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculties`
--

CREATE TABLE `faculties` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `establish_year` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculty_members`
--

CREATE TABLE `faculty_members` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `m_name` varchar(191) NOT NULL,
  `designation_id` varchar(191) NOT NULL,
  `adi_designation_id` varchar(191) DEFAULT NULL,
  `edu_que` longtext DEFAULT NULL,
  `email` text DEFAULT NULL,
  `phone` text DEFAULT NULL,
  `about_me` longtext DEFAULT NULL,
  `research_area` longtext DEFAULT NULL,
  `research_interest` longtext DEFAULT NULL,
  `publication` longtext DEFAULT NULL,
  `experience` longtext DEFAULT NULL,
  `image` varchar(191) NOT NULL,
  `type` varchar(191) NOT NULL,
  `leave` varchar(191) DEFAULT NULL,
  `dept_id` bigint(20) UNSIGNED DEFAULT NULL,
  `school_id` bigint(20) DEFAULT NULL,
  `status` varchar(191) DEFAULT NULL,
  `parity` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(191) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fees_paymens`
--

CREATE TABLE `fees_paymens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `program_id` bigint(20) UNSIGNED NOT NULL,
  `course_fee` varchar(191) NOT NULL,
  `p_result` text NOT NULL,
  `p_amount` text NOT NULL,
  `m_fee` text NOT NULL,
  `scholarship` longtext NOT NULL,
  `financial_waver` longtext NOT NULL,
  `r_q_admission` longtext NOT NULL,
  `general_information` longtext NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `former_vice_chancellors`
--

CREATE TABLE `former_vice_chancellors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `f_name` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `s_date` varchar(191) NOT NULL,
  `e_date` varchar(191) NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_or_sections`
--

CREATE TABLE `group_or_sections` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `groupsection` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `home_addresses`
--

CREATE TABLE `home_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `address` varchar(191) DEFAULT NULL,
  `phone_number` varchar(191) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `home_events`
--

CREATE TABLE `home_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `date` varchar(191) NOT NULL,
  `place` varchar(191) NOT NULL,
  `description` longtext DEFAULT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `home_news_media`
--

CREATE TABLE `home_news_media` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `date` varchar(191) NOT NULL,
  `description` longtext DEFAULT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `home_notable_alumnis`
--

CREATE TABLE `home_notable_alumnis` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `designation` varchar(191) NOT NULL,
  `description` varchar(191) DEFAULT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `home_notices`
--

CREATE TABLE `home_notices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `date` varchar(191) NOT NULL,
  `file` varchar(191) NOT NULL,
  `description` longtext NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `home_sliders`
--

CREATE TABLE `home_sliders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `s_title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `importent_dates`
--

CREATE TABLE `importent_dates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `i_title` varchar(191) NOT NULL,
  `s_date` varchar(191) NOT NULL,
  `e_date` varchar(191) NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `iqacs`
--

CREATE TABLE `iqacs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `about` longtext NOT NULL,
  `vision` longtext NOT NULL,
  `mission` longtext NOT NULL,
  `responsibilities` longtext NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `iqac_details`
--

CREATE TABLE `iqac_details` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `file` varchar(191) NOT NULL,
  `category` int(11) NOT NULL,
  `date` varchar(191) NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `iqac_members`
--

CREATE TABLE `iqac_members` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` int(11) NOT NULL,
  `employ_id` bigint(20) DEFAULT NULL,
  `faculty_member_id` bigint(20) DEFAULT NULL,
  `administrator_type` int(11) NOT NULL,
  `iqac_designation` int(11) NOT NULL,
  `ext` varchar(191) NOT NULL,
  `dept_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(191) NOT NULL,
  `parity` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `iqac_resources`
--

CREATE TABLE `iqac_resources` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `r_title` varchar(191) NOT NULL,
  `upload_file` varchar(191) NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `iqac_workshops`
--

CREATE TABLE `iqac_workshops` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `w_title` varchar(191) NOT NULL,
  `w_details` longtext NOT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_applies`
--

CREATE TABLE `job_applies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `phone` varchar(191) NOT NULL,
  `email` varchar(191) NOT NULL,
  `date` varchar(191) NOT NULL,
  `upload_file` varchar(191) NOT NULL,
  `image` varchar(191) DEFAULT NULL,
  `job_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_posts`
--

CREATE TABLE `job_posts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `j_title` varchar(191) NOT NULL,
  `location` varchar(191) NOT NULL,
  `vacation` varchar(191) NOT NULL,
  `e_status` varchar(191) NOT NULL,
  `experience` varchar(191) NOT NULL,
  `e_requirement` longtext NOT NULL,
  `salary` longtext NOT NULL,
  `description` longtext NOT NULL,
  `age` varchar(191) NOT NULL,
  `p_date` varchar(191) NOT NULL,
  `d_date` varchar(191) NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `library_eresources`
--

CREATE TABLE `library_eresources` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `category` int(11) NOT NULL,
  `title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `link` varchar(191) NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `massage_chairmen`
--

CREATE TABLE `massage_chairmen` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `e_id` bigint(20) UNSIGNED NOT NULL,
  `short_massage` longtext NOT NULL,
  `long_massage` longtext NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `massage_deans`
--

CREATE TABLE `massage_deans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `faculty_id` bigint(20) UNSIGNED NOT NULL,
  `e_id` bigint(20) UNSIGNED NOT NULL,
  `short_massage` longtext NOT NULL,
  `long_massage` longtext NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `massage_dept_hads`
--

CREATE TABLE `massage_dept_hads` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `faculty_member_id` bigint(20) UNSIGNED NOT NULL,
  `short_massage` longtext NOT NULL,
  `long_massage` longtext NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `massage_directors`
--

CREATE TABLE `massage_directors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `e_id` bigint(20) UNSIGNED NOT NULL,
  `short_massage` longtext NOT NULL,
  `long_massage` longtext NOT NULL,
  `s_facilities` longtext NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `massage_v_c_s`
--

CREATE TABLE `massage_v_c_s` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `e_id` bigint(20) UNSIGNED NOT NULL,
  `short_massage` varchar(191) NOT NULL,
  `long_massage` longtext NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(191) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(191) NOT NULL,
  `token` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(191) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `photo_galleries`
--

CREATE TABLE `photo_galleries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `category` varchar(191) NOT NULL,
  `image` text NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `popup`
--

CREATE TABLE `popup` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `image_type` varchar(200) DEFAULT NULL,
  `file_data` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `program` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `results`
--

CREATE TABLE `results` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `student_id` int(11) NOT NULL,
  `e_semester` bigint(20) UNSIGNED NOT NULL,
  `f_semester` int(11) NOT NULL,
  `cgpa` varchar(191) NOT NULL,
  `p_date` varchar(191) NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schools`
--

CREATE TABLE `schools` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `b_image` varchar(191) NOT NULL,
  `title` varchar(191) NOT NULL,
  `image` varchar(191) NOT NULL,
  `description` longtext NOT NULL,
  `status` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `school_details`
--

CREATE TABLE `school_details` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `school_id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(191) NOT NULL,
  `duration_course` varchar(191) DEFAULT NULL,
  `duration_class` varchar(191) DEFAULT NULL,
  `time` varchar(191) DEFAULT NULL,
  `weekly_class` varchar(191) DEFAULT NULL,
  `course_status` varchar(191) DEFAULT NULL,
  `course_fee` varchar(191) DEFAULT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `shift` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `start_sessions`
--

CREATE TABLE `start_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `startsession` varchar(191) NOT NULL,
  `status` int(11) NOT NULL DEFAULT 0,
  `active` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `studends`
--

CREATE TABLE `studends` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `student_name` varchar(191) NOT NULL,
  `student_id` varchar(191) NOT NULL,
  `start_session_id` bigint(20) UNSIGNED NOT NULL,
  `program_id` bigint(20) UNSIGNED NOT NULL,
  `shift_id` bigint(20) UNSIGNED NOT NULL,
  `batche_id` bigint(20) UNSIGNED NOT NULL,
  `group_or_section_id` bigint(20) UNSIGNED NOT NULL,
  `std_image` varchar(191) NOT NULL,
  `departments_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_adminastrations`
--

CREATE TABLE `student_adminastrations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `student_id` bigint(20) UNSIGNED DEFAULT NULL,
  `faculty_id` bigint(20) UNSIGNED DEFAULT NULL,
  `dept_id` bigint(20) UNSIGNED DEFAULT NULL,
  `program_id` bigint(20) UNSIGNED DEFAULT NULL,
  `session_id` bigint(20) UNSIGNED DEFAULT NULL,
  `shift` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_education`
--

CREATE TABLE `student_education` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `student_id` bigint(20) UNSIGNED DEFAULT NULL,
  `exam_name` text NOT NULL,
  `institute` text NOT NULL,
  `major_area` text NOT NULL,
  `passing_year` text NOT NULL,
  `grade` text NOT NULL,
  `upload_file` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_infos`
--

CREATE TABLE `student_infos` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `s_id` varchar(191) DEFAULT NULL,
  `image` varchar(191) DEFAULT NULL,
  `batch` varchar(191) DEFAULT NULL,
  `d_iss` varchar(191) DEFAULT NULL,
  `d_exp` varchar(191) DEFAULT NULL,
  `s_name` varchar(191) DEFAULT NULL,
  `f_name` varchar(191) DEFAULT NULL,
  `m_name` varchar(191) DEFAULT NULL,
  `b_date` varchar(191) DEFAULT NULL,
  `gender` int(11) DEFAULT NULL,
  `nationality` varchar(191) DEFAULT NULL,
  `enroll_date` varchar(191) DEFAULT NULL,
  `phone` varchar(191) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `freedom_fighter` int(11) DEFAULT 0,
  `poor` int(11) DEFAULT 0,
  `f_semester` int(11) NOT NULL DEFAULT 0,
  `status` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_permanent_addresses`
--

CREATE TABLE `student_permanent_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `country` varchar(191) NOT NULL,
  `district` varchar(191) NOT NULL,
  `thana` varchar(191) NOT NULL,
  `police_station` varchar(191) NOT NULL,
  `post_code` varchar(191) NOT NULL,
  `address` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_prasent_addresses`
--

CREATE TABLE `student_prasent_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `country` varchar(191) NOT NULL,
  `district` varchar(191) NOT NULL,
  `thana` varchar(191) NOT NULL,
  `police_station` varchar(191) NOT NULL,
  `post_code` varchar(191) NOT NULL,
  `address` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_user`
--

CREATE TABLE `student_user` (
  `student_user_id` int(10) UNSIGNED NOT NULL,
  `student_user_name` varchar(45) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_email` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_username` varchar(45) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_sid` varchar(25) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `student_user_batch` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `shift` varchar(25) DEFAULT NULL,
  `sgroup` varchar(1) DEFAULT NULL,
  `student_user_password` varchar(45) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_security` int(10) UNSIGNED DEFAULT NULL,
  `student_user_fathers_name` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_mothers_name` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_total_number_c` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_dob_month` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_enroll_month` varchar(30) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_dob` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_ssc_exam_name` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `school_board` varchar(50) NOT NULL,
  `student_user_ssc_grade` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `student_user_Inst` varchar(150) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `student_user_hsc_exam_name` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `college_board` varchar(50) NOT NULL,
  `student_user_hsc_grade` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `student_user_hsc_inst` varchar(150) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `student_user_graduate_exam_name` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `student_user_graduate_grade` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `student_user_graduate_inst` varchar(150) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `versityl_name` varchar(250) DEFAULT NULL,
  `bscl_pyear` varchar(100) DEFAULT NULL,
  `degreel_name` varchar(250) DEFAULT NULL,
  `degreel_grade` varchar(100) DEFAULT NULL,
  `versitybed_name` varchar(250) DEFAULT NULL,
  `bscbed_pyear` varchar(100) DEFAULT NULL,
  `degreebed_name` varchar(250) DEFAULT NULL,
  `degreebed_grade` varchar(100) DEFAULT NULL,
  `student_user_enroll` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `student_user_enroll_year` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_program` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_duration` varchar(25) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `student_user_address_p` varchar(150) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_address_m` varchar(150) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_ps` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_department` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_post` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_city` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_zipcode` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_nationality` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_form_fee` int(11) DEFAULT NULL,
  `student_user_regi_fee` int(11) DEFAULT NULL,
  `student_user_tution_fee` int(11) DEFAULT NULL,
  `student_user_misc_fee` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_project_fee` int(11) DEFAULT NULL,
  `student_user_total` int(11) DEFAULT NULL,
  `student_user_weiver` int(11) DEFAULT NULL,
  `student_user_waiver_percent` varchar(10) DEFAULT NULL,
  `student_user_total_payable` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_monthly_installment` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_phone` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_mobile` varchar(40) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_district` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `student_user_country` varchar(150) DEFAULT NULL,
  `sex` varchar(15) DEFAULT NULL,
  `student_user_upazilla` varchar(100) DEFAULT NULL,
  `student_user_bsemester` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `name` varchar(30) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `mime` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `size` bigint(20) UNSIGNED DEFAULT NULL,
  `size1` bigint(20) UNSIGNED DEFAULT NULL,
  `filename` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `filename1` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `path` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `path1` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `poor_merotorius` varchar(3) DEFAULT NULL,
  `freedom_fighter` varchar(3) DEFAULT NULL,
  `ssc_pyear` varchar(20) DEFAULT NULL,
  `hsc_pyear` varchar(20) DEFAULT NULL,
  `bsc_pyear` varchar(20) DEFAULT NULL,
  `hsc_group` varchar(20) DEFAULT NULL,
  `student_user_dte_created` date DEFAULT NULL,
  `student_user_dte_altered` date DEFAULT NULL,
  `student_user_tme_created` time DEFAULT NULL,
  `flag` varchar(1) DEFAULT NULL,
  `item_delete` int(11) DEFAULT 0,
  `user` varchar(50) DEFAULT NULL,
  `ip` varchar(100) DEFAULT NULL,
  `dis_action` varchar(10000) DEFAULT NULL,
  `ref_number` varchar(100) DEFAULT NULL,
  `blood_group` varchar(15) DEFAULT NULL,
  `nid` varchar(30) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `s_result_entry`
--

CREATE TABLE `s_result_entry` (
  `s_resultentry_id` int(10) UNSIGNED NOT NULL,
  `s_resultentry_batch` varchar(15) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_semester` varchar(30) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_semester_year` varchar(30) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_department` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_prog` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_gr_under_gra` varchar(30) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_subject` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_cgpa` varchar(15) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_sub_code` varchar(30) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_sub_code1` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_sub_code2` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_sub_code3` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_sub_code4` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_sid` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_sname` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_grade` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_grade1` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_grade2` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_grade3` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_grade4` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_credits` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_credits1` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_credits2` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_credits3` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_credits4` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_gpa` varchar(11) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_gpa1` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_gpa2` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_gpa3` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `s_resultentry_gpa4` varchar(10) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `flag` int(11) DEFAULT NULL,
  `pvc` int(11) DEFAULT NULL,
  `oc` int(11) DEFAULT NULL,
  `s_resultentry_dte_created` date DEFAULT NULL,
  `s_resultentry_dte_altered` date DEFAULT NULL,
  `s_resultentry_tme_created` time DEFAULT NULL,
  `s_resultentry_tme_altered` time DEFAULT NULL,
  `pdate` varchar(10) DEFAULT NULL,
  `user` varchar(100) DEFAULT NULL,
  `ip` varchar(100) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `role` int(11) NOT NULL,
  `name` varchar(191) NOT NULL,
  `email` varchar(191) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(191) NOT NULL,
  `department_id` int(11) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vision_missions`
--

CREATE TABLE `vision_missions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `image` varchar(191) NOT NULL,
  `vision_description` longtext NOT NULL,
  `mission_description` longtext NOT NULL,
  `dept_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `visitors_counts`
--

CREATE TABLE `visitors_counts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `location` varchar(191) NOT NULL,
  `total_number` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `aca_calenders`
--
ALTER TABLE `aca_calenders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `aca_calenders_session_id_index` (`session_id`);

--
-- Indexes for table `aca_programs`
--
ALTER TABLE `aca_programs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `aca_programs_dept_id_index` (`dept_id`);

--
-- Indexes for table `administrators`
--
ALTER TABLE `administrators`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admission_promotions`
--
ALTER TABLE `admission_promotions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admission_requirements`
--
ALTER TABLE `admission_requirements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `breaking_news`
--
ALTER TABLE `breaking_news`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `class_exams`
--
ALTER TABLE `class_exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_exams_dept_id_index` (`dept_id`);

--
-- Indexes for table `clubs`
--
ALTER TABLE `clubs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `clubs_dept_id_index` (`dept_id`);

--
-- Indexes for table `club_details`
--
ALTER TABLE `club_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `club_details_club_id_index` (`club_id`);

--
-- Indexes for table `club__committees`
--
ALTER TABLE `club__committees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `club__committees_club_id_index` (`club_id`);

--
-- Indexes for table `controller_of_examinations`
--
ALTER TABLE `controller_of_examinations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `controller_of_examination_applications`
--
ALTER TABLE `controller_of_examination_applications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `convocation_register`
--
ALTER TABLE `convocation_register`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- Indexes for table `convo_eligible`
--
ALTER TABLE `convo_eligible`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `crhps`
--
ALTER TABLE `crhps`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `crhp_details`
--
ALTER TABLE `crhp_details`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `departments_faculty_id_index` (`faculty_id`);

--
-- Indexes for table `dept_addresses`
--
ALTER TABLE `dept_addresses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dept_addresses_dept_id_index` (`dept_id`);

--
-- Indexes for table `dept_events`
--
ALTER TABLE `dept_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dept_events_dept_id_index` (`dept_id`);

--
-- Indexes for table `dept_news_media`
--
ALTER TABLE `dept_news_media`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dept_news_media_dept_id_index` (`dept_id`);

--
-- Indexes for table `dept_notable_alumnis`
--
ALTER TABLE `dept_notable_alumnis`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dept_notable_alumnis_dept_id_index` (`dept_id`);

--
-- Indexes for table `dept_notices`
--
ALTER TABLE `dept_notices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dept_notices_dept_id_index` (`dept_id`);

--
-- Indexes for table `dept__details`
--
ALTER TABLE `dept__details`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `designations`
--
ALTER TABLE `designations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `detp_sliders`
--
ALTER TABLE `detp_sliders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `detp_sliders_dept_id_index` (`dept_id`);

--
-- Indexes for table `employs`
--
ALTER TABLE `employs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employs_administrators_id_index` (`administrators_id`);

--
-- Indexes for table `exam_infos`
--
ALTER TABLE `exam_infos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_infos_exam_session_id_index` (`exam_session_id`);

--
-- Indexes for table `exam_sessions`
--
ALTER TABLE `exam_sessions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `facilities`
--
ALTER TABLE `facilities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `facilities_dept_id_index` (`dept_id`);

--
-- Indexes for table `faculties`
--
ALTER TABLE `faculties`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `faculty_members`
--
ALTER TABLE `faculty_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `faculty_members_dept_id_index` (`dept_id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `fees_paymens`
--
ALTER TABLE `fees_paymens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fees_paymens_dept_id_index` (`dept_id`),
  ADD KEY `fees_paymens_program_id_index` (`program_id`);

--
-- Indexes for table `former_vice_chancellors`
--
ALTER TABLE `former_vice_chancellors`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `group_or_sections`
--
ALTER TABLE `group_or_sections`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `home_addresses`
--
ALTER TABLE `home_addresses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `home_events`
--
ALTER TABLE `home_events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `home_news_media`
--
ALTER TABLE `home_news_media`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `home_notable_alumnis`
--
ALTER TABLE `home_notable_alumnis`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `home_notices`
--
ALTER TABLE `home_notices`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `home_sliders`
--
ALTER TABLE `home_sliders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `importent_dates`
--
ALTER TABLE `importent_dates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `iqacs`
--
ALTER TABLE `iqacs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `iqac_details`
--
ALTER TABLE `iqac_details`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `iqac_members`
--
ALTER TABLE `iqac_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `iqac_members_dept_id_index` (`dept_id`);

--
-- Indexes for table `iqac_resources`
--
ALTER TABLE `iqac_resources`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `iqac_workshops`
--
ALTER TABLE `iqac_workshops`
  ADD PRIMARY KEY (`id`),
  ADD KEY `iqac_workshops_dept_id_index` (`dept_id`);

--
-- Indexes for table `job_applies`
--
ALTER TABLE `job_applies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_applies_job_id_index` (`job_id`);

--
-- Indexes for table `job_posts`
--
ALTER TABLE `job_posts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `library_eresources`
--
ALTER TABLE `library_eresources`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `massage_chairmen`
--
ALTER TABLE `massage_chairmen`
  ADD PRIMARY KEY (`id`),
  ADD KEY `massage_chairmen_e_id_index` (`e_id`);

--
-- Indexes for table `massage_deans`
--
ALTER TABLE `massage_deans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `massage_deans_faculty_id_index` (`faculty_id`),
  ADD KEY `massage_deans_e_id_index` (`e_id`);

--
-- Indexes for table `massage_dept_hads`
--
ALTER TABLE `massage_dept_hads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `massage_dept_hads_dept_id_index` (`dept_id`),
  ADD KEY `massage_dept_hads_faculty_member_id_index` (`faculty_member_id`);

--
-- Indexes for table `massage_directors`
--
ALTER TABLE `massage_directors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `massage_directors_e_id_index` (`e_id`);

--
-- Indexes for table `massage_v_c_s`
--
ALTER TABLE `massage_v_c_s`
  ADD PRIMARY KEY (`id`),
  ADD KEY `massage_v_c_s_e_id_index` (`e_id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD KEY `password_resets_email_index` (`email`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `photo_galleries`
--
ALTER TABLE `photo_galleries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `popup`
--
ALTER TABLE `popup`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `results`
--
ALTER TABLE `results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `results_student_id_index` (`student_id`),
  ADD KEY `results_e_semester_index` (`e_semester`);

--
-- Indexes for table `schools`
--
ALTER TABLE `schools`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `school_details`
--
ALTER TABLE `school_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `school_details_school_id_index` (`school_id`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `start_sessions`
--
ALTER TABLE `start_sessions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `studends`
--
ALTER TABLE `studends`
  ADD PRIMARY KEY (`id`),
  ADD KEY `studends_start_session_id_index` (`start_session_id`),
  ADD KEY `studends_program_id_index` (`program_id`),
  ADD KEY `studends_shift_id_index` (`shift_id`),
  ADD KEY `studends_batche_id_index` (`batche_id`),
  ADD KEY `studends_group_or_section_id_index` (`group_or_section_id`),
  ADD KEY `studends_departments_id_index` (`departments_id`);

--
-- Indexes for table `student_adminastrations`
--
ALTER TABLE `student_adminastrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_adminastrations_student_id_index` (`student_id`),
  ADD KEY `student_adminastrations_faculty_id_index` (`faculty_id`),
  ADD KEY `student_adminastrations_dept_id_index` (`dept_id`),
  ADD KEY `student_adminastrations_program_id_index` (`program_id`),
  ADD KEY `student_adminastrations_session_id_index` (`session_id`);

--
-- Indexes for table `student_education`
--
ALTER TABLE `student_education`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_education_student_id_index` (`student_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `student_infos`
--
ALTER TABLE `student_infos`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_permanent_addresses`
--
ALTER TABLE `student_permanent_addresses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_permanent_addresses_student_id_index` (`student_id`);

--
-- Indexes for table `student_prasent_addresses`
--
ALTER TABLE `student_prasent_addresses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_prasent_addresses_student_id_index` (`student_id`);

--
-- Indexes for table `student_user`
--
ALTER TABLE `student_user`
  ADD PRIMARY KEY (`student_user_id`);

--
-- Indexes for table `s_result_entry`
--
ALTER TABLE `s_result_entry`
  ADD PRIMARY KEY (`s_resultentry_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `vision_missions`
--
ALTER TABLE `vision_missions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vision_missions_dept_id_index` (`dept_id`);

--
-- Indexes for table `visitors_counts`
--
ALTER TABLE `visitors_counts`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `aca_calenders`
--
ALTER TABLE `aca_calenders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `aca_programs`
--
ALTER TABLE `aca_programs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `administrators`
--
ALTER TABLE `administrators`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admission_promotions`
--
ALTER TABLE `admission_promotions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admission_requirements`
--
ALTER TABLE `admission_requirements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `breaking_news`
--
ALTER TABLE `breaking_news`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_exams`
--
ALTER TABLE `class_exams`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clubs`
--
ALTER TABLE `clubs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `club_details`
--
ALTER TABLE `club_details`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `club__committees`
--
ALTER TABLE `club__committees`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `controller_of_examinations`
--
ALTER TABLE `controller_of_examinations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `controller_of_examination_applications`
--
ALTER TABLE `controller_of_examination_applications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `convocation_register`
--
ALTER TABLE `convocation_register`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `convo_eligible`
--
ALTER TABLE `convo_eligible`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crhps`
--
ALTER TABLE `crhps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crhp_details`
--
ALTER TABLE `crhp_details`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_addresses`
--
ALTER TABLE `dept_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_events`
--
ALTER TABLE `dept_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_news_media`
--
ALTER TABLE `dept_news_media`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_notable_alumnis`
--
ALTER TABLE `dept_notable_alumnis`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept_notices`
--
ALTER TABLE `dept_notices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dept__details`
--
ALTER TABLE `dept__details`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `designations`
--
ALTER TABLE `designations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `detp_sliders`
--
ALTER TABLE `detp_sliders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employs`
--
ALTER TABLE `employs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_infos`
--
ALTER TABLE `exam_infos`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_sessions`
--
ALTER TABLE `exam_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `facilities`
--
ALTER TABLE `facilities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculties`
--
ALTER TABLE `faculties`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty_members`
--
ALTER TABLE `faculty_members`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fees_paymens`
--
ALTER TABLE `fees_paymens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `former_vice_chancellors`
--
ALTER TABLE `former_vice_chancellors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_or_sections`
--
ALTER TABLE `group_or_sections`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `home_addresses`
--
ALTER TABLE `home_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `home_events`
--
ALTER TABLE `home_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `home_news_media`
--
ALTER TABLE `home_news_media`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `home_notable_alumnis`
--
ALTER TABLE `home_notable_alumnis`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `home_notices`
--
ALTER TABLE `home_notices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `home_sliders`
--
ALTER TABLE `home_sliders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `importent_dates`
--
ALTER TABLE `importent_dates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `iqacs`
--
ALTER TABLE `iqacs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `iqac_details`
--
ALTER TABLE `iqac_details`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `iqac_members`
--
ALTER TABLE `iqac_members`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `iqac_resources`
--
ALTER TABLE `iqac_resources`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `iqac_workshops`
--
ALTER TABLE `iqac_workshops`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_applies`
--
ALTER TABLE `job_applies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_posts`
--
ALTER TABLE `job_posts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_eresources`
--
ALTER TABLE `library_eresources`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `massage_chairmen`
--
ALTER TABLE `massage_chairmen`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `massage_deans`
--
ALTER TABLE `massage_deans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `massage_dept_hads`
--
ALTER TABLE `massage_dept_hads`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `massage_directors`
--
ALTER TABLE `massage_directors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `massage_v_c_s`
--
ALTER TABLE `massage_v_c_s`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `photo_galleries`
--
ALTER TABLE `photo_galleries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `popup`
--
ALTER TABLE `popup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `results`
--
ALTER TABLE `results`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schools`
--
ALTER TABLE `schools`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `school_details`
--
ALTER TABLE `school_details`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `start_sessions`
--
ALTER TABLE `start_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `studends`
--
ALTER TABLE `studends`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_adminastrations`
--
ALTER TABLE `student_adminastrations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_education`
--
ALTER TABLE `student_education`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_infos`
--
ALTER TABLE `student_infos`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_permanent_addresses`
--
ALTER TABLE `student_permanent_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_prasent_addresses`
--
ALTER TABLE `student_prasent_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_user`
--
ALTER TABLE `student_user`
  MODIFY `student_user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `s_result_entry`
--
ALTER TABLE `s_result_entry`
  MODIFY `s_resultentry_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vision_missions`
--
ALTER TABLE `vision_missions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `visitors_counts`
--
ALTER TABLE `visitors_counts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `aca_calenders`
--
ALTER TABLE `aca_calenders`
  ADD CONSTRAINT `aca_calenders_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `start_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `aca_programs`
--
ALTER TABLE `aca_programs`
  ADD CONSTRAINT `aca_programs_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `class_exams`
--
ALTER TABLE `class_exams`
  ADD CONSTRAINT `class_exams_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `clubs`
--
ALTER TABLE `clubs`
  ADD CONSTRAINT `clubs_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `club_details`
--
ALTER TABLE `club_details`
  ADD CONSTRAINT `club_details_club_id_foreign` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `club__committees`
--
ALTER TABLE `club__committees`
  ADD CONSTRAINT `club__committees_club_id_foreign` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_faculty_id_foreign` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_addresses`
--
ALTER TABLE `dept_addresses`
  ADD CONSTRAINT `dept_addresses_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_events`
--
ALTER TABLE `dept_events`
  ADD CONSTRAINT `dept_events_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_news_media`
--
ALTER TABLE `dept_news_media`
  ADD CONSTRAINT `dept_news_media_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_notable_alumnis`
--
ALTER TABLE `dept_notable_alumnis`
  ADD CONSTRAINT `dept_notable_alumnis_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dept_notices`
--
ALTER TABLE `dept_notices`
  ADD CONSTRAINT `dept_notices_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `detp_sliders`
--
ALTER TABLE `detp_sliders`
  ADD CONSTRAINT `detp_sliders_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employs`
--
ALTER TABLE `employs`
  ADD CONSTRAINT `employs_administrators_id_foreign` FOREIGN KEY (`administrators_id`) REFERENCES `administrators` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_infos`
--
ALTER TABLE `exam_infos`
  ADD CONSTRAINT `exam_infos_exam_session_id_foreign` FOREIGN KEY (`exam_session_id`) REFERENCES `start_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `facilities`
--
ALTER TABLE `facilities`
  ADD CONSTRAINT `facilities_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `faculty_members`
--
ALTER TABLE `faculty_members`
  ADD CONSTRAINT `faculty_members_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `iqac_workshops`
--
ALTER TABLE `iqac_workshops`
  ADD CONSTRAINT `iqac_workshops_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_applies`
--
ALTER TABLE `job_applies`
  ADD CONSTRAINT `job_applies_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `job_posts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `massage_chairmen`
--
ALTER TABLE `massage_chairmen`
  ADD CONSTRAINT `massage_chairmen_e_id_foreign` FOREIGN KEY (`e_id`) REFERENCES `employs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `massage_deans`
--
ALTER TABLE `massage_deans`
  ADD CONSTRAINT `massage_deans_e_id_foreign` FOREIGN KEY (`e_id`) REFERENCES `faculty_members` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `massage_deans_faculty_id_foreign` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `massage_dept_hads`
--
ALTER TABLE `massage_dept_hads`
  ADD CONSTRAINT `massage_dept_hads_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `massage_dept_hads_faculty_member_id_foreign` FOREIGN KEY (`faculty_member_id`) REFERENCES `faculty_members` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `massage_directors`
--
ALTER TABLE `massage_directors`
  ADD CONSTRAINT `massage_directors_e_id_foreign` FOREIGN KEY (`e_id`) REFERENCES `employs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `massage_v_c_s`
--
ALTER TABLE `massage_v_c_s`
  ADD CONSTRAINT `massage_v_c_s_e_id_foreign` FOREIGN KEY (`e_id`) REFERENCES `employs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `school_details`
--
ALTER TABLE `school_details`
  ADD CONSTRAINT `school_details_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `studends`
--
ALTER TABLE `studends`
  ADD CONSTRAINT `studends_batche_id_foreign` FOREIGN KEY (`batche_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `studends_departments_id_foreign` FOREIGN KEY (`departments_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `studends_group_or_section_id_foreign` FOREIGN KEY (`group_or_section_id`) REFERENCES `group_or_sections` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `studends_program_id_foreign` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `studends_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `studends_start_session_id_foreign` FOREIGN KEY (`start_session_id`) REFERENCES `start_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_permanent_addresses`
--
ALTER TABLE `student_permanent_addresses`
  ADD CONSTRAINT `student_permanent_addresses_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `student_infos` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_prasent_addresses`
--
ALTER TABLE `student_prasent_addresses`
  ADD CONSTRAINT `student_prasent_addresses_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `student_infos` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vision_missions`
--
ALTER TABLE `vision_missions`
  ADD CONSTRAINT `vision_missions_dept_id_foreign` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
