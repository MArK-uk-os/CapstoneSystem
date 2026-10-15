-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 05, 2026 at 03:07 PM
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `billing`
--

INSERT INTO `billing` (`id`, `invoice_number`, `customer_id`, `customer_service_id`, `billing_period_start`, `billing_period_end`, `due_date`, `amount`, `status`, `created_at`, `updated_at`) VALUES
(1, 'INV-20260918-3CA426', 1, 1, '2026-09-01', '2026-09-30', '2026-10-02', 1500.00, 'overdue', '2026-09-17 23:50:16', '2026-10-05 13:02:39');

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `account_number`, `full_name`, `contact_number`, `email`, `address`, `service_id`, `status`, `created_at`, `updated_at`) VALUES
(1, '1', 'TEST1', '09761778023', 'cloudinterweb@mailinator.com', 'test', 3, 'active', '2026-09-16 23:34:57', '2026-09-16 23:37:18');

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_services`
--

INSERT INTO `customer_services` (`id`, `customer_id`, `service_id`, `billing_amount`, `billing_frequency`, `start_date`, `next_billing_date`, `due_days`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 3, 1500.00, 'monthly', '2026-09-17', '2026-09-17', 14, 'active', '2026-09-17 23:09:44', '2026-09-17 23:09:44');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `billing_id` int(11) DEFAULT NULL,
  `type` enum('billing','payment_confirmation','overdue') NOT NULL,
  `channel` enum('sms','email') NOT NULL,
  `status` enum('sent','delivered','failed') NOT NULL DEFAULT 'sent',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `customer_id`, `billing_id`, `type`, `channel`, `status`, `sent_at`) VALUES
(1, 1, 1, 'billing', 'email', 'failed', '2026-09-17 23:50:20'),
(2, 1, 1, 'billing', 'sms', 'failed', '2026-09-17 23:50:23');

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
(1, 'Wi-Fi Installation', 799.00, 'Residential/commercial Wi-Fi internet subscription', 'active', '2026-08-27 02:02:39', '2026-08-27 02:02:39'),
(2, 'Vendo Wi-Fi', 0.00, 'Coin/load-operated public Wi-Fi vending service', 'active', '2026-08-27 02:02:39', '2026-08-27 02:02:39'),
(3, 'CCTV Installation', 1500.00, 'CCTV camera installation and monitoring service', 'active', '2026-08-27 02:02:39', '2026-08-27 02:02:39');

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
(1, 'admin', '$2y$10$jAR/rrHO2lumluKsMU3sZu4zc6Sg76Ns7qAg.2oS2kXAckgVmyw4W', 'System Administrator', NULL, 'admin', 'active', '2026-08-27 02:03:07', '2026-08-27 02:03:07');

--
-- Indexes for dumped tables
--

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
  ADD UNIQUE KEY `uq_customer_service` (`customer_id`,`service_id`),
  ADD KEY `fk_customer_services_service` (`service_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notifications_customer` (`customer_id`),
  ADD KEY `fk_notifications_billing` (`billing_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `collections`
--
ALTER TABLE `collections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

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
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`);

--
-- Constraints for table `customer_services`
--
ALTER TABLE `customer_services`
  ADD CONSTRAINT `fk_customer_services_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_customer_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`),
  ADD CONSTRAINT `fk_notifications_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`),
  ADD CONSTRAINT `fk_payments_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
