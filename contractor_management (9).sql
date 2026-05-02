-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 22, 2026 at 08:17 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `contractor_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `contact_persons`
--

CREATE TABLE `contact_persons` (
  `id` int(11) NOT NULL,
  `contractor_id` int(11) NOT NULL,
  `first_name` varchar(150) DEFAULT NULL,
  `last_name` varchar(150) DEFAULT NULL,
  `contact_no` varchar(20) DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  `saved_by` int(11) DEFAULT NULL,
  `saved_at` datetime DEFAULT NULL,
  `status` tinyint(1) DEFAULT 0 COMMENT '	0-default 1-is updated 3-delete',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contractors`
--

CREATE TABLE `contractors` (
  `id` int(11) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `contact_no` varchar(20) DEFAULT NULL,
  `contact_no2` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `category` enum('A','B','C') NOT NULL,
  `documents_submitted` tinyint(1) DEFAULT 0 COMMENT 'yes-1\r\nno-0',
  `active_status` enum('Pending','Active','Inactive') DEFAULT 'Pending',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user_id` int(11) DEFAULT 150,
  `saved_by` int(11) DEFAULT NULL,
  `saved_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `contractor_services` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `record_status` tinyint(1) DEFAULT 0 COMMENT '0-default\r\n1-is updated\r\n3-delete',
  `status` tinyint(1) DEFAULT 0,
  `training_done` tinyint(1) DEFAULT 0 COMMENT 'yes-1\r\nno-0',
  `agreement_done` tinyint(1) DEFAULT 0 COMMENT 'yes-1\r\nno-0',
  `deleted_at` datetime DEFAULT NULL,
  `contact_person2` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contractor_services`
--

CREATE TABLE `contractor_services` (
  `id` int(11) NOT NULL,
  `contractor_id` int(11) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT 150,
  `saved_by` int(11) DEFAULT NULL,
  `saved_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `service_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT 150,
  `saved_by` int(11) DEFAULT NULL,
  `saved_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `service_name`, `created_at`, `user_id`, `saved_by`, `saved_at`, `deleted_by`, `deleted_at`, `is_deleted`) VALUES
(1, 'Electrical', '2026-01-05 08:18:26', 500, 500, '2026-01-05 08:18:26', 500, '2026-01-05 08:20:27', 1),
(2, 'Welding', '2026-01-05 08:18:30', 500, 500, '2026-01-05 08:18:30', NULL, NULL, 0),
(3, 'Plumbing', '2026-01-05 08:18:37', 500, 500, '2026-01-05 08:18:37', NULL, NULL, 0),
(4, 'Electrical', '2026-01-22 04:18:27', 500, 500, '2026-01-22 04:18:27', NULL, NULL, 0),
(5, 'Civil', '2026-01-22 04:18:32', 500, 500, '2026-01-22 04:18:32', NULL, NULL, 0),
(6, 'WES', '2026-01-22 04:53:43', 500, 500, '2026-01-22 04:53:43', NULL, NULL, 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `contact_persons`
--
ALTER TABLE `contact_persons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contractor_id` (`contractor_id`);

--
-- Indexes for table `contractors`
--
ALTER TABLE `contractors`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `contractor_services`
--
ALTER TABLE `contractor_services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_contractor_service` (`contractor_id`,`service_id`),
  ADD KEY `service_id` (`service_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `contact_persons`
--
ALTER TABLE `contact_persons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contractors`
--
ALTER TABLE `contractors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contractor_services`
--
ALTER TABLE `contractor_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `contractor_services`
--
ALTER TABLE `contractor_services`
  ADD CONSTRAINT `contractor_services_ibfk_1` FOREIGN KEY (`contractor_id`) REFERENCES `contractors` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `contractor_services_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
