-- GymZone evaluation database: the accounts and data used for marking.
-- Import in phpMyAdmin (Import tab) or: mysql -u root < database/gymzone-evaluation.sql
-- It creates the database gymzone. Do not run install.php afterwards.

CREATE DATABASE IF NOT EXISTS `gymzone` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gymzone`;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `apartments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `apartments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `number` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_apartments_number` (`number`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `apartments` WRITE;
/*!40000 ALTER TABLE `apartments` DISABLE KEYS */;
INSERT INTO `apartments` VALUES (1,'1.STTV','2026-10-21 13:47:53'),(2,'2TH','2026-10-21 14:19:24'),(3,'5STTH','2026-10-21 14:22:51'),(4,'2TV','2026-10-22 12:18:19'),(5,'6A','2026-10-22 12:19:13'),(6,'12','2026-10-22 13:27:34');
/*!40000 ALTER TABLE `apartments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `checked_in_at` datetime NOT NULL,
  `checked_out_at` datetime DEFAULT NULL,
  `auto_checked_out` tinyint(1) NOT NULL DEFAULT 0,
  `checkout_reported_at` datetime DEFAULT NULL,
  `active_user` int(10) unsigned GENERATED ALWAYS AS (case when `checked_out_at` is null then `user_id` end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_booking` (`booking_id`),
  UNIQUE KEY `uq_attendance_one_active` (`active_user`),
  KEY `idx_attendance_open` (`checked_out_at`),
  KEY `fk_attendance_user` (`user_id`),
  CONSTRAINT `fk_attendance_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_attendance_order` CHECK (`checked_out_at` is null or `checked_out_at` >= `checked_in_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES (1,1,2,'2026-10-21 13:52:10','2026-10-21 13:54:44',0,NULL,NULL),(2,3,3,'2026-10-21 14:20:24','2026-10-21 15:00:00',0,NULL,NULL);
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bookings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `zone_id` int(10) unsigned NOT NULL,
  `booking_date` date NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `source` enum('booking','walk_in') NOT NULL DEFAULT 'booking',
  `status` enum('booked','checked_in','completed','cancelled','released') NOT NULL DEFAULT 'booked',
  `created_at` datetime NOT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `day_key` date GENERATED ALWAYS AS (case when `status` in ('booked','checked_in','completed') then `booking_date` end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bookings_one_zone_per_day` (`user_id`,`zone_id`,`day_key`),
  KEY `idx_bookings_zone_period` (`zone_id`,`start_at`,`end_at`,`status`),
  KEY `idx_bookings_user_period` (`user_id`,`start_at`),
  KEY `idx_bookings_date` (`booking_date`),
  KEY `fk_bookings_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_bookings_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_bookings_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`),
  CONSTRAINT `chk_bookings_order` CHECK (`end_at` > `start_at`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `bookings` WRITE;
/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
INSERT INTO `bookings` VALUES (1,2,2,'2026-10-21','2026-10-21 13:00:00','2026-10-21 14:00:00','walk_in','completed','2026-10-21 13:52:10',NULL,NULL,NULL,'2026-10-21'),(2,2,3,'2026-10-23','2026-10-23 11:00:00','2026-10-23 12:00:00','booking','cancelled','2026-10-21 13:56:19','2026-10-21 13:56:28',2,'Cancelled by the resident',NULL),(3,3,2,'2026-10-21','2026-10-21 14:00:00','2026-10-21 15:00:00','walk_in','completed','2026-10-21 14:20:24',NULL,NULL,NULL,'2026-10-21'),(4,2,1,'2026-10-22','2026-10-22 14:20:00','2026-10-22 15:20:00','booking','released','2026-10-22 12:15:57',NULL,NULL,NULL,NULL),(5,2,3,'2026-10-22','2026-10-22 16:00:00','2026-10-22 17:00:00','booking','released','2026-10-22 13:24:22',NULL,NULL,NULL,NULL),(6,7,1,'2026-10-22','2026-10-22 13:45:00','2026-10-22 14:45:00','booking','cancelled','2026-10-22 13:29:19','2026-10-22 13:29:30',7,'Cancelled by the resident',NULL),(7,2,1,'2026-10-29','2026-10-29 14:05:00','2026-10-29 15:20:00','booking','released','2026-10-29 13:56:30',NULL,NULL,NULL,NULL),(8,2,1,'2026-10-30','2026-10-30 14:15:00','2026-10-30 15:15:00','booking','released','2026-10-29 13:56:49',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `bookings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `closures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `closures` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `zone_id` int(10) unsigned DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_closures_period` (`starts_at`,`ends_at`),
  KEY `fk_closures_zone` (`zone_id`),
  KEY `fk_closures_creator` (`created_by`),
  CONSTRAINT `fk_closures_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_closures_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_closures_order` CHECK (`ends_at` > `starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `closures` WRITE;
/*!40000 ALTER TABLE `closures` DISABLE KEYS */;
/*!40000 ALTER TABLE `closures` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(190) NOT NULL,
  `apartment` varchar(20) DEFAULT NULL,
  `subject` varchar(120) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL,
  `handled_at` datetime DEFAULT NULL,
  `handled_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contact_open` (`handled_at`,`created_at`),
  KEY `fk_contact_user` (`user_id`),
  KEY `fk_contact_handler` (`handled_by`),
  CONSTRAINT `fk_contact_handler` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_contact_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `contact_messages` WRITE;
/*!40000 ALTER TABLE `contact_messages` DISABLE KEYS */;
INSERT INTO `contact_messages` VALUES (1,NULL,'dewan','dsraja@local.com','G2','I have not received my invitation','please send me the invitation','2026-10-22 11:06:29','2026-10-22 11:08:37',1);
/*!40000 ALTER TABLE `contact_messages` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `operating_hours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `operating_hours` (
  `weekday` tinyint(3) unsigned NOT NULL,
  `is_open` tinyint(1) NOT NULL DEFAULT 1,
  `open_time` time NOT NULL,
  `close_time` time NOT NULL,
  PRIMARY KEY (`weekday`),
  CONSTRAINT `chk_hours_weekday` CHECK (`weekday` between 1 and 7),
  CONSTRAINT `chk_hours_order` CHECK (`close_time` > `open_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `operating_hours` WRITE;
/*!40000 ALTER TABLE `operating_hours` DISABLE KEYS */;
INSERT INTO `operating_hours` VALUES (1,1,'00:00:00','24:00:00'),(2,1,'00:00:00','24:00:00'),(3,1,'00:00:00','24:00:00'),(4,1,'00:00:00','24:00:00'),(5,1,'00:00:00','24:00:00'),(6,1,'00:00:00','24:00:00'),(7,1,'00:00:00','24:00:00');
/*!40000 ALTER TABLE `operating_hours` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `name` varchar(50) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES ('auto_checkout_hours','6'),('booking_window_days','21'),('checkin_early_minutes','10'),('checkin_grace_minutes','15'),('contact_email','admin@gymzone.local'),('contact_hours',''),('contact_phone',''),('max_session_minutes','240'),('schema_version','3'),('session_minutes','60');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role` enum('resident','admin') NOT NULL DEFAULT 'resident',
  `status` enum('pending','active','inactive') NOT NULL DEFAULT 'pending',
  `full_name` varchar(100) NOT NULL,
  `username` varchar(30) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `apartment_id` int(10) unsigned DEFAULT NULL,
  `session_version` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `activated_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_apartment` (`apartment_id`),
  KEY `idx_users_role_status` (`role`,`status`),
  CONSTRAINT `fk_users_apartment` FOREIGN KEY (`apartment_id`) REFERENCES `apartments` (`id`),
  CONSTRAINT `chk_users_resident_apartment` CHECK (`role` = 'admin' or `apartment_id` is not null),
  CONSTRAINT `chk_users_active_password` CHECK (`status` <> 'active' or `password_hash` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','active','dsraja','dsraja','admin@gymzone.local','$2y$10$.MBUlJt8ZKrFn/74IYVApuWSSk7OlGbkrXPuVon7E7MWRb8UvAMtK',NULL,2,'2026-10-21 13:45:48','2026-10-21 13:45:48','2026-11-12 10:21:03'),(2,'resident','active','Omor S Chowdhury','omor.chowdhury','boomshaka@gmail.com','$2y$10$SN08ZjV/fJH5xQZ0sdWLMOjob3u3Vhiljt5Z7aAGNbThkK.fCUJxG',1,4,'2026-10-21 13:47:53','2026-10-21 13:51:11','2026-11-12 11:14:43'),(3,'resident','active','Randy','randy','randi@d.com','$2y$10$RMjmV89ivGywbVi5d0EtH.C0Ic9gdBVZOtAy1v9cZ2UeW.q1yysuG',2,1,'2026-10-21 14:19:24','2026-10-21 14:20:02','2026-10-21 14:20:12'),(4,'resident','inactive','Triple H','triple.h','stephanie@gmail.com','$2y$10$pvxfXzvijwvXNjeoqEsaA.hx4O5jhJANK9ghrPCNVQxdZBHv2sxo.',3,2,'2026-10-21 14:22:51','2026-10-21 14:24:07','2026-10-21 14:24:16'),(5,'resident','pending','Dewan','dewan','dewan@gmail.com',NULL,4,1,'2026-10-22 12:18:19',NULL,NULL),(6,'resident','active','Moon','moon','moon@gmail.com','$2y$10$RgcQJ6t6baPdpjW99Itm.uwktdfcjwC.WeOmlwmguKq0w07yx3E26',5,1,'2026-10-22 12:19:13','2026-10-22 12:20:11','2026-10-22 12:20:13'),(7,'resident','active','jon','jon','jon@gmail.com','$2y$10$FlMo9C1KbW805zNLmuPF9Ok7wJXLrCb2tOaKp7BNl6hULdew4A0NS',6,1,'2026-10-22 13:27:34','2026-10-22 13:28:38','2026-10-22 13:28:59');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `zones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `capacity` smallint(5) unsigned NOT NULL,
  `is_open` tinyint(1) NOT NULL DEFAULT 1,
  `closed_note` varchar(255) DEFAULT NULL,
  `notice` varchar(255) DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zones_name` (`name`),
  CONSTRAINT `chk_zones_capacity` CHECK (`capacity` between 1 and 500)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `zones` WRITE;
/*!40000 ALTER TABLE `zones` DISABLE KEYS */;
INSERT INTO `zones` VALUES (1,'Cardio','Treadmills, exercise bikes and rowing machines',15,1,NULL,'Treadmill no.4 is out of order',1),(2,'Weights','Free weights, benches and squat racks',10,1,NULL,NULL,2),(3,'Machines','Resistance and cable machines',8,1,NULL,NULL,3);
/*!40000 ALTER TABLE `zones` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `verification_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `verification_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `purpose` enum('activation','email_change','password_change') NOT NULL,
  `token_hash` char(64) NOT NULL,
  `new_email` varchar(190) DEFAULT NULL,
  `new_password_hash` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_verification_token` (`token_hash`),
  KEY `idx_verification_user` (`user_id`,`purpose`),
  CONSTRAINT `fk_verification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `requested_ip` varchar(45) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_reset_token` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(30) NOT NULL,
  `subject` varchar(190) NOT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rate_limits_lookup` (`action`,`subject`,`attempted_at`),
  KEY `idx_rate_limits_time` (`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

