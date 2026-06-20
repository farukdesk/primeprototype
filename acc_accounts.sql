-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 21, 2026 at 05:57 AM
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

--
-- Dumping data for table `acc_accounts`
--

INSERT INTO `acc_accounts` (`id`, `code`, `name`, `type`, `sub_type`, `parent_id`, `is_system`, `is_active`, `opening_balance`, `description`, `created_at`, `updated_at`) VALUES
(1, '1000', 'Cash & Bank', 'asset', 'current_asset', NULL, 1, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(2, '1100', 'Petty Cash', 'asset', 'current_asset', NULL, 1, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(3, '1200', 'Main Bank Account', 'asset', 'current_asset', NULL, 1, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(4, '1300', 'Other Bank Accounts', 'asset', 'current_asset', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(5, '1400', 'Accounts Receivable', 'asset', 'current_asset', NULL, 1, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(6, '1500', 'Prepaid Expenses', 'asset', 'current_asset', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(7, '1600', 'Fixed Assets', 'asset', 'fixed_asset', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(8, '2100', 'Accounts Payable', 'liability', 'current_liability', NULL, 1, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(9, '2200', 'Accrued Liabilities', 'liability', 'current_liability', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(10, '2300', 'Deferred Revenue', 'liability', 'current_liability', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(11, '2400', 'Long-Term Liabilities', 'liability', 'long_term_liability', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(12, '3100', 'University Fund', 'equity', 'equity', NULL, 1, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(13, '3200', 'Retained Surplus', 'equity', 'equity', NULL, 1, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(14, '4100', 'Tuition Fees', 'income', 'revenue', NULL, 1, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(15, '4200', 'Admission Fees', 'income', 'revenue', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(16, '4300', 'Library Fees', 'income', 'revenue', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(17, '4400', 'Examination Fees', 'income', 'revenue', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(18, '4500', 'Lab Fees', 'income', 'revenue', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(19, '4600', 'Form Sale Revenue', 'income', 'revenue', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(20, '4700', 'Miscellaneous Income', 'income', 'revenue', NULL, 1, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(21, '5100', 'Faculty Salaries', 'expense', 'operating_expense', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(22, '5200', 'Administrative Salaries', 'expense', 'operating_expense', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(23, '5300', 'Utilities', 'expense', 'operating_expense', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(24, '5400', 'Office Supplies', 'expense', 'operating_expense', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(25, '5500', 'Maintenance & Repairs', 'expense', 'operating_expense', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(26, '5600', 'Marketing & Advertising', 'expense', 'operating_expense', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(27, '5700', 'IT & Technology', 'expense', 'operating_expense', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(28, '5800', 'Library Expenses', 'expense', 'operating_expense', NULL, 0, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(29, '5900', 'Miscellaneous Expenses', 'expense', 'operating_expense', NULL, 1, 1, 0.00, NULL, '2026-04-10 03:43:21', '2026-04-10 03:43:21'),
(30, '3020000', 'Admission Form Sale', 'income', 'other_income', 31, 0, 1, 0.00, NULL, '2026-04-10 03:53:58', '2026-04-10 04:12:06'),
(31, '12000', 'Student Accounts', 'income', 'revenue', NULL, 0, 1, 0.00, NULL, '2026-04-10 04:11:24', '2026-04-10 04:11:24'),
(32, '02', 'Admission Fee', 'income', 'revenue', 31, 0, 1, 0.00, NULL, '2026-04-10 04:14:02', '2026-05-07 06:43:28'),
(33, '324324', 'Student Registration Fee', 'income', 'revenue', 31, 0, 1, 0.00, NULL, '2026-05-07 08:12:17', '2026-05-07 08:12:17'),
(34, '34324', 'Student Admission Fee', 'income', 'revenue', 31, 0, 1, 0.00, NULL, '2026-05-07 08:13:11', '2026-05-07 08:13:11'),
(35, '2324', 'Student ID Card Fee', 'income', 'revenue', 31, 0, 1, 0.00, NULL, '2026-05-07 08:13:29', '2026-05-07 08:13:29'),
(36, '4354', 'Student Admission Form Fee', 'income', 'revenue', 31, 0, 1, 0.00, NULL, '2026-05-07 08:14:04', '2026-05-07 08:14:04'),
(37, '23444', 'Student Monthly Fee', 'income', 'revenue', 31, 0, 1, 0.00, NULL, '2026-05-07 08:14:27', '2026-05-07 08:14:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `acc_accounts`
--
ALTER TABLE `acc_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_acc_code` (`code`),
  ADD KEY `idx_acc_type` (`type`),
  ADD KEY `idx_acc_parent` (`parent_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `acc_accounts`
--
ALTER TABLE `acc_accounts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `acc_accounts`
--
ALTER TABLE `acc_accounts`
  ADD CONSTRAINT `fk_acc_parent` FOREIGN KEY (`parent_id`) REFERENCES `acc_accounts` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
