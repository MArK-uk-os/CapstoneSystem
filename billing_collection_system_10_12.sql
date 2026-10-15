-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 12, 2026 at 03:26 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `billing_collection_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `automation_settings`
--

CREATE TABLE `automation_settings` (
  `id` int(11) NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 0,
  `channels` varchar(100) NOT NULL DEFAULT 'telegram,email',
  `pre_due_days` int(11) NOT NULL DEFAULT 3,
  `overdue_repeat_days` int(11) NOT NULL DEFAULT 7,
  `follow_up_days` int(11) NOT NULL DEFAULT 3,
  `send_time` time NOT NULL DEFAULT '08:00:00',
  `last_scheduled_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `automation_settings`
--

INSERT INTO `automation_settings` (`id`, `enabled`, `channels`, `pre_due_days`, `overdue_repeat_days`, `follow_up_days`, `send_time`, `last_scheduled_date`) VALUES
(1, 1, 'telegram,email,sms', 3, 7, 3, '20:31:00', '2026-10-12');

-- --------------------------------------------------------

--
-- Table structure for table `billing`
--

CREATE TABLE `billing` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(30) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `customer_service_id` int(11) DEFAULT NULL,
  `billing_period_start` date NOT NULL,
  `billing_period_end` date NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('unpaid','paid','overdue') NOT NULL DEFAULT 'unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `service_name_snapshot` varchar(100) DEFAULT NULL,
  `account_number_snapshot` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `billing`
--

INSERT INTO `billing` (`id`, `invoice_number`, `customer_id`, `customer_service_id`, `billing_period_start`, `billing_period_end`, `due_date`, `amount`, `status`, `created_at`, `updated_at`, `service_name_snapshot`, `account_number_snapshot`) VALUES
(1, 'INV-20260918-3CA426', 1, 1, '2026-09-01', '2026-09-30', '2026-10-12', 1500.00, 'paid', '2026-09-17 23:50:16', '2026-10-12 13:04:36', 'CCTV Installation', 'ACC-000001'),
(3, 'INV-20261007-062688', 1, 1, '2026-10-01', '2026-10-31', '2026-10-15', 1500.00, 'paid', '2026-10-07 01:21:29', '2026-10-12 13:07:59', 'CCTV Installation', 'ACC-000001');

-- --------------------------------------------------------

--
-- Table structure for table `collections`
--

CREATE TABLE `collections` (
  `id` int(11) NOT NULL,
  `billing_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `outstanding_balance` decimal(10,2) NOT NULL,
  `overdue_days` int(11) NOT NULL DEFAULT 0,
  `collection_status` enum('pending','in_progress','settled') NOT NULL DEFAULT 'pending',
  `follow_up_date` date DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `collections`
--

INSERT INTO `collections` (`id`, `billing_id`, `customer_id`, `outstanding_balance`, `overdue_days`, `collection_status`, `follow_up_date`, `remarks`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 0.00, 0, 'settled', '2026-10-14', NULL, NULL, '2026-10-11 11:12:50', '2026-10-12 13:04:36');

-- --------------------------------------------------------

--
-- Table structure for table `collection_activities`
--

CREATE TABLE `collection_activities` (
  `id` int(11) NOT NULL,
  `collection_id` int(11) NOT NULL,
  `status` varchar(30) NOT NULL,
  `follow_up_date` date DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `collection_activities`
--

INSERT INTO `collection_activities` (`id`, `collection_id`, `status`, `follow_up_date`, `remarks`, `staff_id`, `created_at`) VALUES
(1, 1, 'pending', '2026-10-14', NULL, NULL, '2026-10-11 11:12:50');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `account_number` varchar(30) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `telegram_chat_id` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `account_number`, `full_name`, `contact_number`, `email`, `address`, `service_id`, `status`, `created_at`, `updated_at`, `telegram_chat_id`) VALUES
(1, '1', 'TEST1', '09065224841', 'cloudinterweb@mailinator.com', 'test', 3, 'active', '2026-09-16 23:34:57', '2026-10-11 10:58:04', '1552560807');

-- --------------------------------------------------------

--
-- Table structure for table `customer_services`
--

CREATE TABLE `customer_services` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `billing_amount` decimal(10,2) NOT NULL,
  `billing_frequency` enum('monthly','quarterly','annually') NOT NULL DEFAULT 'monthly',
  `start_date` date NOT NULL,
  `next_billing_date` date DEFAULT NULL,
  `due_days` int(11) NOT NULL DEFAULT 14,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `service_account_number` varchar(30) DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_services`
--

INSERT INTO `customer_services` (`id`, `customer_id`, `service_id`, `billing_amount`, `billing_frequency`, `start_date`, `next_billing_date`, `due_days`, `status`, `created_at`, `updated_at`, `service_account_number`, `plan_id`) VALUES
(1, 1, 3, 1500.00, 'monthly', '2026-09-17', '2026-11-01', 14, 'active', '2026-09-17 23:09:44', '2026-10-11 11:13:09', 'ACC-000001', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `internet_plans`
--

CREATE TABLE `internet_plans` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `speed_mbps` int(11) NOT NULL DEFAULT 0,
  `monthly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `enabled` tinyint(4) NOT NULL DEFAULT 0,
  `category` enum('Residential','Business','Lease Line') NOT NULL DEFAULT 'Residential'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `internet_plans`
--

INSERT INTO `internet_plans` (`id`, `name`, `speed_mbps`, `monthly_rate`, `enabled`, `category`) VALUES
(1, 'Residential 100 Mbps', 100, 999.00, 1, 'Residential'),
(2, 'Business 50 Mbps', 50, 2000.00, 1, 'Business'),
(3, 'Lease Line 200 Mbps', 200, 10000.00, 1, 'Lease Line'),
(4, 'Residential 150 Mbps', 150, 1299.00, 1, 'Residential'),
(5, 'Residential 200 Mbps', 200, 1499.00, 1, 'Residential'),
(6, 'Residential 300 Mbps', 300, 1899.00, 1, 'Residential'),
(7, 'Business 100 Mbps', 100, 2499.00, 1, 'Business'),
(8, 'Business 200 Mbps', 200, 3499.00, 1, 'Business'),
(9, 'Lease Line 500 Mbps', 500, 30000.00, 1, 'Lease Line'),
(10, 'Lease Line 1 Gbps', 1000, 45000.00, 1, 'Lease Line');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `billing_id` int(11) DEFAULT NULL,
  `type` enum('billing','payment_confirmation','overdue') NOT NULL,
  `channel` enum('sms','email','telegram') NOT NULL,
  `status` enum('sent','delivered','failed') NOT NULL DEFAULT 'sent',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `customer_id`, `billing_id`, `type`, `channel`, `status`, `sent_at`) VALUES
(1, 1, 1, 'billing', 'email', 'failed', '2026-09-17 23:50:20'),
(2, 1, 1, 'billing', 'sms', 'failed', '2026-09-17 23:50:23'),
(3, 1, 1, 'billing', 'email', 'sent', '2026-10-07 01:20:14'),
(4, 1, 3, 'billing', 'email', 'sent', '2026-10-07 01:21:42'),
(5, 1, 3, 'billing', 'sms', 'sent', '2026-10-07 01:21:46'),
(6, 1, 3, 'billing', 'email', 'sent', '2026-10-07 01:22:36'),
(7, 1, 3, 'billing', 'sms', 'sent', '2026-10-07 02:00:06'),
(8, 1, 3, 'billing', 'sms', 'sent', '2026-10-08 02:44:18'),
(9, 1, 3, 'billing', 'sms', 'sent', '2026-10-08 03:30:56'),
(10, 1, 3, 'billing', 'sms', 'sent', '2026-10-08 03:36:41'),
(11, 1, 1, 'billing', 'sms', 'sent', '2026-10-11 10:17:47'),
(12, 1, 3, 'billing', 'sms', 'sent', '2026-10-11 10:23:47'),
(13, 1, 1, 'billing', 'telegram', 'sent', '2026-10-11 10:58:23'),
(14, 1, 1, 'overdue', 'telegram', 'sent', '2026-10-11 11:13:11'),
(15, 1, 1, 'overdue', 'email', 'sent', '2026-10-11 11:13:17'),
(16, 1, 3, 'billing', 'sms', 'failed', '2026-10-11 13:14:17'),
(17, 1, 3, 'billing', 'sms', 'failed', '2026-10-11 13:15:13'),
(18, 1, 3, 'billing', 'sms', 'failed', '2026-10-11 13:18:23'),
(19, 1, 3, 'billing', 'sms', 'sent', '2026-10-11 13:20:11'),
(20, 1, 1, 'billing', 'telegram', 'sent', '2026-10-11 20:59:34'),
(21, 1, 1, 'billing', 'sms', 'failed', '2026-10-11 21:00:23'),
(22, 1, 1, 'billing', 'sms', 'failed', '2026-10-11 21:01:13'),
(23, 1, 3, 'billing', 'sms', 'failed', '2026-10-11 21:39:20'),
(24, 1, 3, 'billing', 'sms', 'failed', '2026-10-11 21:39:28'),
(25, 1, 3, 'billing', 'sms', 'failed', '2026-10-11 21:42:04'),
(26, 1, 3, 'billing', 'sms', 'failed', '2026-10-11 21:46:01'),
(27, 1, 3, 'billing', 'sms', 'sent', '2026-10-11 21:46:22'),
(28, 1, 3, 'billing', 'sms', 'sent', '2026-10-11 21:51:35'),
(29, 1, 3, 'billing', 'sms', 'sent', '2026-10-11 21:52:00'),
(30, 1, 1, 'billing', 'telegram', 'sent', '2026-10-12 12:24:36'),
(31, 1, 1, 'billing', 'email', 'sent', '2026-10-12 12:24:42'),
(32, 1, 1, 'billing', 'sms', 'sent', '2026-10-12 12:24:43'),
(33, 1, 3, 'billing', 'telegram', 'sent', '2026-10-12 12:24:44'),
(34, 1, 3, 'billing', 'email', 'sent', '2026-10-12 12:24:48'),
(35, 1, 3, 'billing', 'sms', 'sent', '2026-10-12 12:24:49'),
(36, 1, 1, 'billing', 'telegram', 'sent', '2026-10-12 12:24:53'),
(37, 1, 1, 'billing', 'email', 'sent', '2026-10-12 12:24:58'),
(38, 1, 1, 'billing', 'sms', 'sent', '2026-10-12 12:24:59'),
(39, 1, 3, 'billing', 'telegram', 'sent', '2026-10-12 12:25:00'),
(40, 1, 3, 'billing', 'email', 'sent', '2026-10-12 12:25:05'),
(41, 1, 3, 'billing', 'sms', 'sent', '2026-10-12 12:25:06'),
(42, 1, 3, 'billing', 'sms', 'sent', '2026-10-12 12:26:40'),
(43, 1, 1, 'payment_confirmation', 'telegram', 'sent', '2026-10-12 13:04:59'),
(44, 1, 1, 'payment_confirmation', 'email', 'sent', '2026-10-12 13:05:06'),
(45, 1, 1, 'payment_confirmation', 'sms', 'sent', '2026-10-12 13:05:07'),
(46, 1, 3, 'payment_confirmation', 'telegram', 'sent', '2026-10-12 13:08:59'),
(47, 1, 3, 'payment_confirmation', 'email', 'sent', '2026-10-12 13:09:06'),
(48, 1, 3, 'payment_confirmation', 'sms', 'sent', '2026-10-12 13:09:07');

-- --------------------------------------------------------

--
-- Table structure for table `notification_queue`
--

CREATE TABLE `notification_queue` (
  `id` int(11) NOT NULL,
  `event_key` varchar(191) NOT NULL,
  `billing_id` int(11) NOT NULL,
  `kind` varchar(30) NOT NULL,
  `channel` varchar(20) NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_error` varchar(255) DEFAULT NULL,
  `payment_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notification_queue`
--

INSERT INTO `notification_queue` (`id`, `event_key`, `billing_id`, `kind`, `channel`, `due_date`, `status`, `attempts`, `next_attempt_at`, `last_error`, `payment_id`) VALUES
(1, '1:overdue:2026-10-02:1:telegram', 1, 'overdue', 'telegram', '2026-10-02', 'sent', 1, '2026-10-12 01:13:11', NULL, NULL),
(2, '1:overdue:2026-10-02:1:email', 1, 'overdue', 'email', '2026-10-02', 'sent', 1, '2026-10-12 01:13:17', NULL, NULL),
(15, '1:due_changed:2026-10-12:7ca04315:telegram', 1, 'due_changed', 'telegram', '2026-10-12', 'sent', 1, '2026-10-13 02:24:36', NULL, NULL),
(16, '1:due_changed:2026-10-12:7ca04315:email', 1, 'due_changed', 'email', '2026-10-12', 'sent', 1, '2026-10-13 02:24:42', NULL, NULL),
(17, '1:due_changed:2026-10-12:7ca04315:sms', 1, 'due_changed', 'sms', '2026-10-12', 'sent', 1, '2026-10-13 02:24:43', NULL, NULL),
(18, '3:due_changed:2026-10-12:6a9f50e4:telegram', 3, 'due_changed', 'telegram', '2026-10-12', 'cancelled', 0, '2026-10-12 05:39:11', NULL, NULL),
(19, '3:due_changed:2026-10-12:6a9f50e4:email', 3, 'due_changed', 'email', '2026-10-12', 'cancelled', 0, '2026-10-12 05:39:11', NULL, NULL),
(20, '3:due_changed:2026-10-12:6a9f50e4:sms', 3, 'due_changed', 'sms', '2026-10-12', 'cancelled', 0, '2026-10-12 05:39:11', NULL, NULL),
(21, '3:due_changed:2026-10-15:fa8164cf:telegram', 3, 'due_changed', 'telegram', '2026-10-15', 'cancelled', 0, '2026-10-12 20:18:18', NULL, NULL),
(22, '3:due_changed:2026-10-15:fa8164cf:email', 3, 'due_changed', 'email', '2026-10-15', 'cancelled', 0, '2026-10-12 20:18:18', NULL, NULL),
(23, '3:due_changed:2026-10-15:fa8164cf:sms', 3, 'due_changed', 'sms', '2026-10-15', 'cancelled', 0, '2026-10-12 20:18:18', NULL, NULL),
(24, '3:due_changed:2026-10-14:54490b71:telegram', 3, 'due_changed', 'telegram', '2026-10-14', 'sent', 1, '2026-10-13 02:24:44', NULL, NULL),
(25, '3:due_changed:2026-10-14:54490b71:email', 3, 'due_changed', 'email', '2026-10-14', 'sent', 1, '2026-10-13 02:24:48', NULL, NULL),
(26, '3:due_changed:2026-10-14:54490b71:sms', 3, 'due_changed', 'sms', '2026-10-14', 'sent', 1, '2026-10-13 02:24:49', NULL, NULL),
(27, '1:due:2026-10-12::telegram', 1, 'due', 'telegram', '2026-10-12', 'sent', 1, '2026-10-13 02:24:53', NULL, NULL),
(28, '1:due:2026-10-12::email', 1, 'due', 'email', '2026-10-12', 'sent', 1, '2026-10-13 02:24:58', NULL, NULL),
(29, '1:due:2026-10-12::sms', 1, 'due', 'sms', '2026-10-12', 'sent', 1, '2026-10-13 02:24:59', NULL, NULL),
(30, '3:pre_due:2026-10-14::telegram', 3, 'pre_due', 'telegram', '2026-10-14', 'sent', 1, '2026-10-13 02:25:00', NULL, NULL),
(31, '3:pre_due:2026-10-14::email', 3, 'pre_due', 'email', '2026-10-14', 'sent', 1, '2026-10-13 02:25:05', NULL, NULL),
(32, '3:pre_due:2026-10-14::sms', 3, 'pre_due', 'sms', '2026-10-14', 'sent', 1, '2026-10-13 02:25:06', NULL, NULL),
(33, '3:due_changed:2026-10-15:6ebf68de:telegram', 3, 'due_changed', 'telegram', '2026-10-15', 'pending', 0, '2026-10-12 20:29:26', NULL, NULL),
(34, '3:due_changed:2026-10-15:6ebf68de:email', 3, 'due_changed', 'email', '2026-10-15', 'pending', 0, '2026-10-12 20:29:26', NULL, NULL),
(35, '3:due_changed:2026-10-15:6ebf68de:sms', 3, 'due_changed', 'sms', '2026-10-15', 'pending', 0, '2026-10-12 20:29:26', NULL, NULL),
(36, 'payment:1:telegram', 1, 'payment', 'telegram', '2026-10-12', 'sent', 1, '2026-10-13 03:04:59', NULL, 1),
(37, 'payment:1:email', 1, 'payment', 'email', '2026-10-12', 'sent', 1, '2026-10-13 03:05:06', NULL, 1),
(38, 'payment:1:sms', 1, 'payment', 'sms', '2026-10-12', 'sent', 1, '2026-10-13 03:05:07', NULL, 1),
(39, 'payment:2:telegram', 3, 'payment', 'telegram', '2026-10-15', 'sent', 1, '2026-10-13 03:08:59', NULL, 2),
(40, 'payment:2:email', 3, 'payment', 'email', '2026-10-15', 'sent', 1, '2026-10-13 03:09:06', NULL, 2),
(41, 'payment:2:sms', 3, 'payment', 'sms', '2026-10-15', 'sent', 1, '2026-10-13 03:09:07', NULL, 2);

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `billing_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `proof_of_payment` varchar(255) DEFAULT NULL,
  `verification_status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `receipt_number` varchar(30) DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `billing_id`, `payment_date`, `amount_paid`, `payment_method`, `reference_number`, `proof_of_payment`, `verification_status`, `receipt_number`, `verified_by`, `created_at`) VALUES
(1, 1, '2026-10-12', 1500.00, 'Cash', NULL, NULL, 'verified', 'RCT-20261012-9611A4', 1, '2026-10-12 13:03:51'),
(2, 3, '2026-10-12', 1500.00, 'Cash', NULL, NULL, 'verified', 'RCT-20261012-865BF3', 1, '2026-10-12 13:06:30');

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `service_name` varchar(100) NOT NULL,
  `monthly_fee` decimal(10,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `service_name`, `monthly_fee`, `description`, `status`, `created_at`, `updated_at`) VALUES
(3, 'Test service', 1500.00, 'service 1', 'active', '2026-08-27 02:02:39', '2026-10-12 12:59:48');

-- --------------------------------------------------------

--
-- Table structure for table `subscription_changes`
--

CREATE TABLE `subscription_changes` (
  `id` int(11) NOT NULL,
  `customer_service_id` int(11) NOT NULL,
  `old_plan_id` int(11) DEFAULT NULL,
  `new_plan_id` int(11) DEFAULT NULL,
  `old_rate` decimal(10,2) NOT NULL,
  `new_rate` decimal(10,2) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `profile_photo`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$jAR/rrHO2lumluKsMU3sZu4zc6Sg76Ns7qAg.2oS2kXAckgVmyw4W', 'System Administrator', '/assets/uploads/profiles/04959e845918bee6a033868a87cbc281.png', 'admin', 'active', '2026-08-27 02:03:07', '2026-10-06 01:47:22');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `automation_settings`
--
ALTER TABLE `automation_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `billing`
--
ALTER TABLE `billing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `fk_billing_customer` (`customer_id`),
  ADD KEY `fk_billing_customer_service` (`customer_service_id`);

--
-- Indexes for table `collections`
--
ALTER TABLE `collections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_collections_billing` (`billing_id`),
  ADD KEY `fk_collections_customer` (`customer_id`),
  ADD KEY `fk_collections_staff` (`updated_by`);

--
-- Indexes for table `collection_activities`
--
ALTER TABLE `collection_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `collection_id` (`collection_id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_number` (`account_number`),
  ADD KEY `fk_customers_service` (`service_id`);

--
-- Indexes for table `customer_services`
--
ALTER TABLE `customer_services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `service_account_number` (`service_account_number`),
  ADD KEY `fk_customer_services_service` (`service_id`),
  ADD KEY `fk_subscription_plan` (`plan_id`),
  ADD KEY `idx_subscription_customer` (`customer_id`);

--
-- Indexes for table `internet_plans`
--
ALTER TABLE `internet_plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notifications_customer` (`customer_id`),
  ADD KEY `fk_notifications_billing` (`billing_id`);

--
-- Indexes for table `notification_queue`
--
ALTER TABLE `notification_queue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `event_key` (`event_key`),
  ADD KEY `billing_id` (`billing_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_payments_billing` (`billing_id`),
  ADD KEY `fk_payments_verifier` (`verified_by`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `subscription_changes`
--
ALTER TABLE `subscription_changes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_service_id` (`customer_service_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `billing`
--
ALTER TABLE `billing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `collections`
--
ALTER TABLE `collections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `collection_activities`
--
ALTER TABLE `collection_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_services`
--
ALTER TABLE `customer_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `notification_queue`
--
ALTER TABLE `notification_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `subscription_changes`
--
ALTER TABLE `subscription_changes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `billing`
--
ALTER TABLE `billing`
  ADD CONSTRAINT `fk_billing_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_billing_customer_service` FOREIGN KEY (`customer_service_id`) REFERENCES `customer_services` (`id`);

--
-- Constraints for table `collections`
--
ALTER TABLE `collections`
  ADD CONSTRAINT `fk_collections_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`),
  ADD CONSTRAINT `fk_collections_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_collections_staff` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `collection_activities`
--
ALTER TABLE `collection_activities`
  ADD CONSTRAINT `collection_activities_ibfk_1` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`),
  ADD CONSTRAINT `collection_activities_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`);

--
-- Constraints for table `customer_services`
--
ALTER TABLE `customer_services`
  ADD CONSTRAINT `fk_customer_services_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_customer_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`),
  ADD CONSTRAINT `fk_subscription_plan` FOREIGN KEY (`plan_id`) REFERENCES `internet_plans` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`),
  ADD CONSTRAINT `fk_notifications_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

--
-- Constraints for table `notification_queue`
--
ALTER TABLE `notification_queue`
  ADD CONSTRAINT `notification_queue_ibfk_1` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`),
  ADD CONSTRAINT `fk_payments_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `subscription_changes`
--
ALTER TABLE `subscription_changes`
  ADD CONSTRAINT `subscription_changes_ibfk_1` FOREIGN KEY (`customer_service_id`) REFERENCES `customer_services` (`id`),
  ADD CONSTRAINT `subscription_changes_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
