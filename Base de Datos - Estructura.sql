-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: mysql-deskserviced.alwaysdata.net
-- Generation Time: Nov 09, 2026 at 10:34 PM
-- Server version: 11.4.13-MariaDB
-- PHP Version: 8.4.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `deskserviced_maincloud`
--

-- --------------------------------------------------------

--
-- Table structure for table `actas`
--

CREATE TABLE `actas` (
  `id` int(11) NOT NULL,
  `type` enum('entrega','mantenimiento') NOT NULL,
  `title` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `content` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `response_comment` text DEFAULT NULL,
  `saas_client_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `agent_devices`
--

CREATE TABLE `agent_devices` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(100) NOT NULL,
  `device_name` varchar(255) NOT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `os_version` varchar(255) DEFAULT NULL,
  `agent_version` varchar(50) DEFAULT NULL,
  `status` enum('online','offline','pending') DEFAULT 'pending',
  `last_check_in` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `agent_policies`
--

CREATE TABLE `agent_policies` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(100) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `parameters` text DEFAULT NULL,
  `executed` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_documents`
--

CREATE TABLE `ai_documents` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `title` varchar(190) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `ext` varchar(10) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `kind` varchar(12) NOT NULL DEFAULT 'document',
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `sha1` char(40) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `status_message` varchar(500) DEFAULT NULL,
  `extract_method` varchar(40) DEFAULT NULL,
  `char_count` int(11) NOT NULL DEFAULT 0,
  `chunk_count` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `indexed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_doc_chunks`
--

CREATE TABLE `ai_doc_chunks` (
  `id` bigint(20) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `chunk_index` int(11) NOT NULL,
  `content` mediumtext NOT NULL,
  `terms` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `id` int(11) NOT NULL,
  `type_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `status` enum('active','maintenance','retired') DEFAULT 'active',
  `purchase_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `saas_client_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `asset_fields`
--

CREATE TABLE `asset_fields` (
  `id` int(11) NOT NULL,
  `type_id` int(11) NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `field_label` varchar(100) NOT NULL,
  `field_type` enum('text','number','date','select') DEFAULT 'text',
  `field_options` text DEFAULT NULL,
  `required` tinyint(1) DEFAULT 0,
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `asset_field_values`
--

CREATE TABLE `asset_field_values` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `field_id` int(11) NOT NULL,
  `field_value` text DEFAULT NULL,
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `asset_types`
--

CREATE TABLE `asset_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `icon` varchar(50) DEFAULT 'fas fa-box',
  `created_at` datetime DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assignment_rules`
--

CREATE TABLE `assignment_rules` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(190) NOT NULL,
  `category_id` int(11) NOT NULL,
  `group_id` int(11) DEFAULT NULL,
  `assigned_user_id` int(11) DEFAULT NULL,
  `priority` int(11) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attachments`
--

CREATE TABLE `attachments` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `message_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `tenant_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `saas_client_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'fa-folder',
  `color` varchar(20) DEFAULT '#4e73df',
  `tenant_id` int(11) DEFAULT NULL,
  `priority` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `changes`
--

CREATE TABLE `changes` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `risk_level` enum('low','medium','high','critical') DEFAULT 'low',
  `status` enum('draft','pending_approval','approved','in_progress','completed','rejected','rolled_back') DEFAULT 'draft',
  `impact_analysis` text DEFAULT NULL,
  `rollback_plan` text DEFAULT NULL,
  `scheduled_start` datetime DEFAULT NULL,
  `scheduled_end` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_conversations`
--

CREATE TABLE `chat_conversations` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `owner_key` varchar(80) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(160) NOT NULL DEFAULT 'Nueva conversación',
  `state` varchar(40) NOT NULL DEFAULT 'IDLE',
  `data_json` mediumtext DEFAULT NULL,
  `ticket_id` int(11) DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_histories`
--

CREATE TABLE `chat_histories` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `chat_id` varchar(100) NOT NULL,
  `source` enum('telegram','whatsapp','web') NOT NULL DEFAULT 'telegram',
  `user_name` varchar(255) DEFAULT NULL,
  `agent_id` int(11) NOT NULL,
  `started_at` datetime DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` bigint(20) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `role` varchar(12) NOT NULL,
  `content` mediumtext NOT NULL,
  `files_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_requests`
--

CREATE TABLE `email_requests` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `from_email` varchar(190) NOT NULL,
  `from_name` varchar(190) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `attachments` text DEFAULT NULL COMMENT 'JSON con info de adjuntos temporales',
  `ai_subject` varchar(255) DEFAULT NULL,
  `ai_reply` mediumtext DEFAULT NULL,
  `ai_confidence` int(11) DEFAULT NULL,
  `ai_sentiment` varchar(20) DEFAULT NULL,
  `ai_classification` varchar(150) DEFAULT NULL,
  `ai_kb_id` int(11) DEFAULT NULL,
  `ai_status` enum('none','drafted','sent','escalated') DEFAULT 'none',
  `ai_sent_at` datetime DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `entities`
--

CREATE TABLE `entities` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `groups`
--

CREATE TABLE `groups` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_users`
--

CREATE TABLE `group_users` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `integrations`
--

CREATE TABLE `integrations` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `internal_chat_messages`
--

CREATE TABLE `internal_chat_messages` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `internal_chat_rooms`
--

CREATE TABLE `internal_chat_rooms` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user1_id` int(11) NOT NULL,
  `user2_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `knowledge_base`
--

CREATE TABLE `knowledge_base` (
  `id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `keywords` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `problems`
--

CREATE TABLE `problems` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `status` enum('open','investigating','identified','resolved') DEFAULT 'open',
  `root_cause` text DEFAULT NULL,
  `workaround` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `k` char(64) NOT NULL,
  `hits` int(11) NOT NULL DEFAULT 0,
  `window_start` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `saas_payments`
--

CREATE TABLE `saas_payments` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `next_expiry_date` date NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_categories`
--

CREATE TABLE `service_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'fa-box',
  `color` varchar(20) DEFAULT '#4e73df',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sla_config`
--

CREATE TABLE `sla_config` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `response_time_hours` int(11) DEFAULT 2,
  `resolution_time_hours` int(11) DEFAULT 24,
  `priority_multiplier_high` decimal(3,2) DEFAULT 0.50,
  `priority_multiplier_low` decimal(3,2) DEFAULT 1.50,
  `response_unit` enum('hours','days') DEFAULT 'hours',
  `resolution_unit` enum('hours','days') DEFAULT 'hours',
  `saas_client_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `telegram_logs`
--

CREATE TABLE `telegram_logs` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT 1,
  `source` enum('telegram','whatsapp') DEFAULT 'telegram',
  `chat_id` bigint(20) NOT NULL,
  `direction` enum('in','out') NOT NULL,
  `message` text DEFAULT NULL,
  `ticket_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `telegram_sessions`
--

CREATE TABLE `telegram_sessions` (
  `chat_id` bigint(20) NOT NULL,
  `tenant_id` int(11) NOT NULL DEFAULT 1,
  `source` enum('telegram','whatsapp') DEFAULT 'telegram',
  `state` varchar(50) NOT NULL,
  `data` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `id` int(11) NOT NULL,
  `name` varchar(190) NOT NULL,
  `subdomain` varchar(100) DEFAULT NULL,
  `domain` varchar(190) DEFAULT NULL,
  `contact_email` varchar(190) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `plan` varchar(50) DEFAULT 'basic',
  `plan_status` enum('trial','paid','expired') DEFAULT 'trial',
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `trial_ends_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenant_payments`
--

CREATE TABLE `tenant_payments` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `payment_id` varchar(190) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `gateway` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenant_settings`
--

CREATE TABLE `tenant_settings` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
  `local_id` int(11) DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `service_category_id` int(11) DEFAULT NULL,
  `assigned_tech_id` int(11) DEFAULT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `status` enum('open','pending','closed') DEFAULT 'open',
  `due_date` datetime DEFAULT NULL,
  `incident_at` datetime DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `saas_client_id` int(11) DEFAULT NULL,
  `problem_id` int(11) DEFAULT NULL,
  `csat_rating` int(11) DEFAULT NULL COMMENT '1 to 5 stars',
  `csat_feedback` text DEFAULT NULL,
  `rating` int(11) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `source` enum('web','email','whatsapp','telegram','chatbot') DEFAULT 'web'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_assets`
--

CREATE TABLE `ticket_assets` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_rules`
--

CREATE TABLE `ticket_rules` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `assign_to_tech_id` int(11) DEFAULT NULL,
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_solicitors`
--

CREATE TABLE `ticket_solicitors` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_techs`
--

CREATE TABLE `ticket_techs` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `tech_id` int(11) NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trusted_devices`
--

CREATE TABLE `trusted_devices` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(100) NOT NULL,
  `expires_at` datetime NOT NULL,
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `local_id` int(11) DEFAULT NULL,
  `saas_client_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','tech','user','supervisor') DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `phone` varchar(20) DEFAULT NULL,
  `area` varchar(100) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `verification_code` char(6) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `reset_code` char(6) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  `telegram_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `user_id` int(11) NOT NULL,
  `ui_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wa_processed`
--

CREATE TABLE `wa_processed` (
  `msg_id` varchar(128) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_logs`
--

CREATE TABLE `whatsapp_logs` (
  `id` int(11) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `direction` enum('in','out') NOT NULL,
  `message` text DEFAULT NULL,
  `ticket_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_sessions`
--

CREATE TABLE `whatsapp_sessions` (
  `phone` varchar(20) NOT NULL,
  `state` varchar(50) NOT NULL,
  `data` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tenant_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `actas`
--
ALTER TABLE `actas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `asset_id` (`asset_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `saas_client_id` (`saas_client_id`),
  ADD KEY `idx_actas_tenant` (`tenant_id`);

--
-- Indexes for table `agent_devices`
--
ALTER TABLE `agent_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `agent_id` (`agent_id`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `agent_policies`
--
ALTER TABLE `agent_policies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `ai_documents`
--
ALTER TABLE `ai_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_aidoc_tenant` (`tenant_id`),
  ADD KEY `idx_aidoc_status` (`tenant_id`,`status`),
  ADD KEY `idx_aidoc_sha` (`tenant_id`,`sha1`);

--
-- Indexes for table `ai_doc_chunks`
--
ALTER TABLE `ai_doc_chunks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_aichunk_tenant_doc` (`tenant_id`,`document_id`);

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `serial_number` (`serial_number`),
  ADD KEY `type_id` (`type_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `saas_client_id` (`saas_client_id`),
  ADD KEY `idx_assets_tenant` (`tenant_id`),
  ADD KEY `idx_tenant_v2` (`tenant_id`);

--
-- Indexes for table `asset_fields`
--
ALTER TABLE `asset_fields`
  ADD PRIMARY KEY (`id`),
  ADD KEY `type_id` (`type_id`),
  ADD KEY `idx_asset_fields_tenant` (`tenant_id`);

--
-- Indexes for table `asset_field_values`
--
ALTER TABLE `asset_field_values`
  ADD PRIMARY KEY (`id`),
  ADD KEY `asset_id` (`asset_id`),
  ADD KEY `field_id` (`field_id`),
  ADD KEY `idx_asset_field_values_tenant` (`tenant_id`);

--
-- Indexes for table `asset_types`
--
ALTER TABLE `asset_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_asset_types_tenant` (`tenant_id`);

--
-- Indexes for table `assignment_rules`
--
ALTER TABLE `assignment_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rule_tenant` (`tenant_id`),
  ADD KEY `idx_rule_category` (`category_id`),
  ADD KEY `idx_rule_group` (`group_id`),
  ADD KEY `idx_rule_user` (`assigned_user_id`);

--
-- Indexes for table `attachments`
--
ALTER TABLE `attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `message_id` (`message_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_attachments_tenant` (`tenant_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `saas_client_id` (`saas_client_id`),
  ADD KEY `idx_categories_tenant` (`tenant_id`),
  ADD KEY `idx_tenant_v2` (`tenant_id`);

--
-- Indexes for table `changes`
--
ALTER TABLE `changes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chatconv_owner` (`tenant_id`,`owner_key`,`status`,`updated_at`);

--
-- Indexes for table `chat_histories`
--
ALTER TABLE `chat_histories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_tenant` (`tenant_id`),
  ADD KEY `idx_chat_id` (`chat_id`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chatmsg_conv` (`conversation_id`,`id`),
  ADD KEY `idx_chatmsg_tenant` (`tenant_id`);

--
-- Indexes for table `email_requests`
--
ALTER TABLE `email_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email_req_tenant` (`tenant_id`),
  ADD KEY `idx_email_req_status` (`status`);

--
-- Indexes for table `entities`
--
ALTER TABLE `entities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_entity_tenant` (`tenant_id`);

--
-- Indexes for table `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group_tenant` (`tenant_id`);

--
-- Indexes for table `group_users`
--
ALTER TABLE `group_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_group_user` (`group_id`,`user_id`),
  ADD KEY `idx_guser_tenant` (`tenant_id`),
  ADD KEY `idx_guser_group` (`group_id`),
  ADD KEY `idx_guser_user` (`user_id`);

--
-- Indexes for table `integrations`
--
ALTER TABLE `integrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_tenant_provider_key` (`tenant_id`,`provider`,`setting_key`);

--
-- Indexes for table `internal_chat_messages`
--
ALTER TABLE `internal_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_msg_room` (`room_id`),
  ADD KEY `idx_msg_sender` (`sender_id`),
  ADD KEY `idx_msg_read` (`is_read`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `internal_chat_rooms`
--
ALTER TABLE `internal_chat_rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_room` (`tenant_id`,`user1_id`,`user2_id`),
  ADD KEY `idx_room_tenant` (`tenant_id`),
  ADD KEY `idx_room_user1` (`user1_id`),
  ADD KEY `idx_room_user2` (`user2_id`);

--
-- Indexes for table `knowledge_base`
--
ALTER TABLE `knowledge_base`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_knowledge_base_tenant` (`tenant_id`),
  ADD KEY `idx_tenant_v2` (`tenant_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_messages_tenant` (`tenant_id`),
  ADD KEY `idx_tenant_v2` (`tenant_id`);

--
-- Indexes for table `problems`
--
ALTER TABLE `problems`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`k`);

--
-- Indexes for table `saas_payments`
--
ALTER TABLE `saas_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `service_categories`
--
ALTER TABLE `service_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `sla_config`
--
ALTER TABLE `sla_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_id` (`category_id`),
  ADD KEY `saas_client_id` (`saas_client_id`),
  ADD KEY `idx_sla_config_tenant` (`tenant_id`);

--
-- Indexes for table `telegram_logs`
--
ALTER TABLE `telegram_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat` (`chat_id`),
  ADD KEY `idx_ticket` (`ticket_id`),
  ADD KEY `idx_tel_log_tenant` (`tenant_id`);

--
-- Indexes for table `telegram_sessions`
--
ALTER TABLE `telegram_sessions`
  ADD PRIMARY KEY (`chat_id`,`tenant_id`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subdomain` (`subdomain`),
  ADD KEY `idx_tenant_status` (`status`);

--
-- Indexes for table `tenant_payments`
--
ALTER TABLE `tenant_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment_tenant` (`tenant_id`),
  ADD KEY `idx_payment_id` (`payment_id`);

--
-- Indexes for table `tenant_settings`
--
ALTER TABLE `tenant_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_tenant_key` (`tenant_id`,`setting_key`),
  ADD KEY `idx_tenant_key` (`tenant_id`,`setting_key`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `asset_id` (`asset_id`),
  ADD KEY `saas_client_id` (`saas_client_id`),
  ADD KEY `fk_ticket_category` (`service_category_id`),
  ADD KEY `fk_ticket_problem` (`problem_id`),
  ADD KEY `idx_tickets_tenant` (`tenant_id`),
  ADD KEY `idx_tenant_local` (`tenant_id`,`local_id`),
  ADD KEY `idx_tenant_v2` (`tenant_id`);

--
-- Indexes for table `ticket_assets`
--
ALTER TABLE `ticket_assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ticket_asset` (`ticket_id`,`asset_id`),
  ADD KEY `asset_id` (`asset_id`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `ticket_rules`
--
ALTER TABLE `ticket_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `assign_to_tech_id` (`assign_to_tech_id`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `ticket_solicitors`
--
ALTER TABLE `ticket_solicitors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ts` (`ticket_id`,`user_id`),
  ADD KEY `idx_ts_ticket` (`ticket_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_ticket_solicitors_tenant` (`tenant_id`);

--
-- Indexes for table `ticket_techs`
--
ALTER TABLE `ticket_techs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ticket_tech` (`ticket_id`,`tech_id`),
  ADD KEY `idx_ticket_techs_tenant` (`tenant_id`);

--
-- Indexes for table `trusted_devices`
--
ALTER TABLE `trusted_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_token` (`user_id`,`token`),
  ADD KEY `idx_trusted_device_tenant` (`tenant_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_telegram_tenant` (`telegram_id`,`tenant_id`),
  ADD UNIQUE KEY `idx_tenant_email` (`tenant_id`,`email`),
  ADD KEY `saas_client_id` (`saas_client_id`),
  ADD KEY `idx_user_tenant` (`tenant_id`),
  ADD KEY `idx_user_local_id` (`tenant_id`,`local_id`),
  ADD KEY `idx_tenant_v2` (`tenant_id`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `wa_processed`
--
ALTER TABLE `wa_processed`
  ADD PRIMARY KEY (`msg_id`);

--
-- Indexes for table `whatsapp_logs`
--
ALTER TABLE `whatsapp_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_ticket` (`ticket_id`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `whatsapp_sessions`
--
ALTER TABLE `whatsapp_sessions`
  ADD PRIMARY KEY (`phone`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `actas`
--
ALTER TABLE `actas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_devices`
--
ALTER TABLE `agent_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_policies`
--
ALTER TABLE `agent_policies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_documents`
--
ALTER TABLE `ai_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_doc_chunks`
--
ALTER TABLE `ai_doc_chunks`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `asset_fields`
--
ALTER TABLE `asset_fields`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `asset_field_values`
--
ALTER TABLE `asset_field_values`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `asset_types`
--
ALTER TABLE `asset_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignment_rules`
--
ALTER TABLE `assignment_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attachments`
--
ALTER TABLE `attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `changes`
--
ALTER TABLE `changes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_histories`
--
ALTER TABLE `chat_histories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_requests`
--
ALTER TABLE `email_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `entities`
--
ALTER TABLE `entities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `groups`
--
ALTER TABLE `groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_users`
--
ALTER TABLE `group_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `integrations`
--
ALTER TABLE `integrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `internal_chat_messages`
--
ALTER TABLE `internal_chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `internal_chat_rooms`
--
ALTER TABLE `internal_chat_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `knowledge_base`
--
ALTER TABLE `knowledge_base`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `problems`
--
ALTER TABLE `problems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `saas_payments`
--
ALTER TABLE `saas_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_categories`
--
ALTER TABLE `service_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sla_config`
--
ALTER TABLE `sla_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `telegram_logs`
--
ALTER TABLE `telegram_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tenant_payments`
--
ALTER TABLE `tenant_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tenant_settings`
--
ALTER TABLE `tenant_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_assets`
--
ALTER TABLE `ticket_assets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_rules`
--
ALTER TABLE `ticket_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_solicitors`
--
ALTER TABLE `ticket_solicitors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_techs`
--
ALTER TABLE `ticket_techs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trusted_devices`
--
ALTER TABLE `trusted_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `whatsapp_logs`
--
ALTER TABLE `whatsapp_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `internal_chat_messages`
--
ALTER TABLE `internal_chat_messages`
  ADD CONSTRAINT `fk_msg_room` FOREIGN KEY (`room_id`) REFERENCES `internal_chat_rooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `internal_chat_rooms`
--
ALTER TABLE `internal_chat_rooms`
  ADD CONSTRAINT `fk_room_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_room_user1` FOREIGN KEY (`user1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_room_user2` FOREIGN KEY (`user2_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
