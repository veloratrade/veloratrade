-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 05, 2026 at 11:27 PM
-- Server version: 8.0.46-cll-lve
-- PHP Version: 8.4.23

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `piknet_velora`
--

-- --------------------------------------------------------

--
-- Table structure for table `email_notifications`
--

CREATE TABLE `email_notifications` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` longtext COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `sent_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `error_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_notifications`
--

INSERT INTO `email_notifications` (`id`, `user_id`, `event_type`, `recipient_email`, `subject`, `payload_json`, `status`, `sent_at`, `failed_at`, `error_message`, `created_at`) VALUES
(1, 24, 'VERIFICATION_EMAIL', 'gejewo5909@bora4d.com', 'تأیید ایمیل VELORA TRADE', NULL, 'sent', '2026-08-05 13:32:59', NULL, NULL, '2026-08-05 13:32:59'),
(2, 24, 'WELCOME_EMAIL', 'gejewo5909@bora4d.com', 'به VELORA TRADE خوش آمدید', NULL, 'sent', '2026-08-05 13:34:19', NULL, NULL, '2026-08-05 13:34:19'),
(3, 24, 'ACHIEVEMENT_UNLOCKED', 'gejewo5909@bora4d.com', '🏆 دستاورد جدید: تأیید ایمیل کاربری | VELORA TRADE', NULL, 'sent', '2026-08-05 13:34:20', NULL, NULL, '2026-08-05 13:34:20'),
(4, 24, 'NEW_DEVICE_DETECTED', 'gejewo5909@bora4d.com', 'هشدار امنیتی: ورود از دستگاه جدید | VELORA TRADE', NULL, 'sent', '2026-08-05 13:34:37', NULL, NULL, '2026-08-05 13:34:37'),
(5, 24, 'FIRST_TRADE_RECORDED', 'gejewo5909@bora4d.com', 'تبریک! اولین معامله شما در VELORA TRADE ثبت شد', NULL, 'failed', NULL, '2026-08-05 13:35:31', NULL, '2026-08-05 13:35:31'),
(6, 24, 'ACHIEVEMENT_UNLOCKED', 'gejewo5909@bora4d.com', '🏆 دستاورد جدید: اولین معامله در VELORA | VELORA TRADE', NULL, 'sent', '2026-08-05 13:35:31', NULL, NULL, '2026-08-05 13:35:31'),
(7, 25, 'VERIFICATION_EMAIL', 'vaxexat605@bejum.com', 'تأیید ایمیل VELORA TRADE', NULL, 'sent', '2026-08-05 14:03:00', NULL, NULL, '2026-08-05 14:03:00'),
(8, 26, 'VERIFICATION_EMAIL', 'tixix54381@bejum.com', 'تأیید ایمیل VELORA TRADE', NULL, 'sent', '2026-08-05 14:05:58', NULL, NULL, '2026-08-05 14:05:58'),
(9, 3, 'PASSWORD_RESET_LINK', 'yadgar.s.ict@gmail.com', 'VELORA TRADE | Password reset', NULL, 'sent', '2026-08-05 14:08:47', NULL, NULL, '2026-08-05 14:08:47'),
(10, 27, 'VERIFICATION_EMAIL', 'y2856798@gmail.com', 'تأیید ایمیل VELORA TRADE', NULL, 'sent', '2026-08-05 14:12:00', NULL, NULL, '2026-08-05 14:12:00'),
(11, 28, 'VERIFICATION_EMAIL', 'wilixa6682@bora4d.com', 'VELORA TRADE | تأیید ایمیل حساب کاربری', NULL, 'sent', '2026-08-05 14:21:42', NULL, NULL, '2026-08-05 14:21:42'),
(15, 29, 'VERIFICATION_EMAIL', 'vonocid988@bora4d.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 14:41:20', NULL, NULL, '2026-08-05 14:41:20'),
(16, 29, 'WELCOME_EMAIL', 'vonocid988@bora4d.com', 'VELORA TRADE | Welcome (خوش‌آمدید)', NULL, 'sent', '2026-08-05 14:42:09', NULL, NULL, '2026-08-05 14:42:09'),
(17, 29, 'ACHIEVEMENT_UNLOCKED', 'vonocid988@bora4d.com', '🏆 دستاورد جدید: تأیید ایمیل کاربری | VELORA TRADE', NULL, 'sent', '2026-08-05 14:42:10', NULL, NULL, '2026-08-05 14:42:10'),
(18, 29, 'PASSWORD_RESET_LINK', 'vonocid988@bora4d.com', 'VELORA TRADE | Password reset', NULL, 'sent', '2026-08-05 14:42:30', NULL, NULL, '2026-08-05 14:42:30'),
(19, 30, 'VERIFICATION_EMAIL', 'volalan892@bejum.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 15:10:43', NULL, NULL, '2026-08-05 15:10:43'),
(20, 30, 'VERIFICATION_EMAIL', 'volalan892@bejum.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 15:23:57', NULL, NULL, '2026-08-05 15:23:57'),
(21, 30, 'VERIFICATION_EMAIL', 'volalan892@bejum.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 15:24:19', NULL, NULL, '2026-08-05 15:24:19'),
(22, 31, 'VERIFICATION_EMAIL', 'betosew472@aghism.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 15:26:31', NULL, NULL, '2026-08-05 15:26:31'),
(23, 31, 'VERIFICATION_EMAIL', 'betosew472@aghism.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 15:26:56', NULL, NULL, '2026-08-05 15:26:56'),
(24, 31, 'VERIFICATION_EMAIL', 'betosew472@aghism.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 15:27:26', NULL, NULL, '2026-08-05 15:27:26'),
(25, 32, 'VERIFICATION_EMAIL', 'yotab16836@copawoke.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 15:43:16', NULL, NULL, '2026-08-05 15:43:16'),
(26, 33, 'VERIFICATION_EMAIL', 'panap78203@amupx.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 15:53:22', NULL, NULL, '2026-08-05 15:53:22'),
(27, 34, 'VERIFICATION_EMAIL', 'simeb77855@applamos.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 16:06:47', NULL, NULL, '2026-08-05 16:06:47'),
(28, 35, 'VERIFICATION_EMAIL', 'gedim71531@bora4d.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 16:23:51', NULL, NULL, '2026-08-05 16:23:51'),
(29, 35, 'VERIFICATION_EMAIL', 'gedim71531@bora4d.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 16:32:52', NULL, NULL, '2026-08-05 16:32:52'),
(30, 36, 'VERIFICATION_EMAIL', 'yandgar.s.ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 16:44:35', NULL, NULL, '2026-08-05 16:44:35'),
(31, 35, 'VERIFICATION_EMAIL', 'gedim71531@bora4d.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'failed', NULL, '2026-08-05 17:15:18', NULL, '2026-08-05 17:15:18'),
(32, 37, 'VERIFICATION_EMAIL', 'gedim715g31@bora4d.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 17:16:11', NULL, NULL, '2026-08-05 17:16:11'),
(33, 39, 'VERIFICATION_EMAIL', 'nsndns@heueue.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 17:43:41', NULL, NULL, '2026-08-05 17:46:07'),
(34, 40, 'VERIFICATION_EMAIL', 'hdjdjsj@ehhehe.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 17:45:04', NULL, NULL, '2026-08-05 17:49:53'),
(35, 41, 'VERIFICATION_EMAIL', 'yadngar.s.ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 17:53:51', NULL, NULL, '2026-08-05 17:53:51'),
(36, 42, 'VERIFICATION_EMAIL', 'yadgar.hs.ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 18:06:04', NULL, NULL, '2026-08-05 18:06:04'),
(37, 43, 'VERIFICATION_EMAIL', 'yabbdgar.s.ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 18:14:52', NULL, NULL, '2026-08-05 18:14:52'),
(38, 44, 'VERIFICATION_EMAIL', 'yewati3916@bejum.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 18:16:12', NULL, NULL, '2026-08-05 18:16:12'),
(39, 45, 'VERIFICATION_EMAIL', 'vdhdeh@ehejej.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 18:18:04', NULL, NULL, '2026-08-05 18:18:04'),
(40, 46, 'VERIFICATION_EMAIL', 'yajdgar.s.ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 18:27:04', NULL, NULL, '2026-08-05 18:27:04'),
(41, 44, 'VERIFICATION_EMAIL', 'yewati3916@bejum.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 18:28:03', NULL, NULL, '2026-08-05 18:28:03'),
(42, 44, 'VERIFICATION_EMAIL', 'yewati3916@bejum.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 18:28:58', NULL, NULL, '2026-08-05 18:28:58'),
(43, 47, 'VERIFICATION_EMAIL', 'yadgar.s.9ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 18:45:43', NULL, NULL, '2026-08-05 18:45:43'),
(44, 48, 'VERIFICATION_EMAIL', 'sjejejj@ehehe6.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 18:54:50', NULL, NULL, '2026-08-05 18:54:50'),
(45, 49, 'VERIFICATION_EMAIL', 'yadgar.s.icbt@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 19:01:29', NULL, NULL, '2026-08-05 19:01:29'),
(46, 50, 'VERIFICATION_EMAIL', 'jdjdjd@82i2.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 19:09:37', NULL, NULL, '2026-08-05 19:09:37'),
(47, 50, 'VERIFICATION_EMAIL', 'jdjdjd@82i2.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 19:10:12', NULL, NULL, '2026-08-05 19:10:12'),
(48, 50, 'VERIFICATION_EMAIL', 'jdjdjd@82i2.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 19:11:36', NULL, NULL, '2026-08-05 19:11:36'),
(49, 51, 'VERIFICATION_EMAIL', 'jdjdjd@iv82i2.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 19:16:34', NULL, NULL, '2026-08-05 19:16:34'),
(50, 52, 'VERIFICATION_EMAIL', 'jdjdjdfj@82i2.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 19:18:02', NULL, NULL, '2026-08-05 19:18:02'),
(51, 53, 'VERIFICATION_EMAIL', 'jdjdjd@82i2.comj', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 19:24:00', NULL, NULL, '2026-08-05 19:24:00'),
(52, 54, 'VERIFICATION_EMAIL', 'yadghar.s.ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 20:41:44', NULL, NULL, '2026-08-05 20:41:44'),
(53, 55, 'VERIFICATION_EMAIL', 'yadgar.s.ict@gmail.comjb', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 21:06:37', NULL, NULL, '2026-08-05 21:06:37'),
(54, 56, 'VERIFICATION_EMAIL', 'yadgar.s.ictbv@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 21:33:34', NULL, NULL, '2026-08-05 21:33:34'),
(55, 57, 'VERIFICATION_EMAIL', 'yadgarb.s.ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 21:43:08', NULL, NULL, '2026-08-05 21:43:08'),
(56, 58, 'VERIFICATION_EMAIL', 'yadgar.s.ibbct@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 21:57:41', NULL, NULL, '2026-08-05 21:57:41'),
(57, 59, 'VERIFICATION_EMAIL', 'yadgar.s.ichht@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 21:59:22', NULL, NULL, '2026-08-05 21:59:22'),
(58, 60, 'VERIFICATION_EMAIL', 'yadgar.s.ict@dbsbgmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 22:10:42', NULL, NULL, '2026-08-05 22:10:42'),
(59, 61, 'VERIFICATION_EMAIL', 'yadgbnar.s.ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 22:20:12', NULL, NULL, '2026-08-05 22:20:12'),
(60, 62, 'VERIFICATION_EMAIL', 'yadgarbwbw.s.ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 22:47:49', NULL, NULL, '2026-08-05 22:47:49'),
(61, 63, 'VERIFICATION_EMAIL', 'yadgar.jdks.ict@gmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 22:48:51', NULL, NULL, '2026-08-05 22:48:51'),
(62, 64, 'VERIFICATION_EMAIL', 'yadgar.s.ict@bjgmail.com', 'VELORA TRADE | Verify Email (تأیید ایمیل)', NULL, 'sent', '2026-08-05 22:52:27', NULL, NULL, '2026-08-05 22:52:27');

-- --------------------------------------------------------

--
-- Table structure for table `email_preferences`
--

CREATE TABLE `email_preferences` (
  `user_id` bigint UNSIGNED NOT NULL,
  `welcome_email` tinyint(1) NOT NULL DEFAULT '1',
  `security_alerts` tinyint(1) NOT NULL DEFAULT '1',
  `trade_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `weekly_report` tinyint(1) NOT NULL DEFAULT '1',
  `monthly_report` tinyint(1) NOT NULL DEFAULT '1',
  `achievement_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_verifications`
--

CREATE TABLE `email_verifications` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_verifications`
--

INSERT INTO `email_verifications` (`id`, `user_id`, `token_hash`, `expires_at`, `verified_at`, `created_at`) VALUES
(1, 7, 'c10706f05e383ceb81e02e99b156a438b2da2b3d96907926868214ca2d567c48', '2026-08-05 22:57:40', NULL, '2026-08-04 22:57:40'),
(2, 8, '19451abc8de1d7a6abbd7abddbde1de74ad17d9c4e5c1dbfd967d829f35370fa', '2026-08-05 23:16:29', NULL, '2026-08-04 23:16:29'),
(3, 9, '87c26ed3d5a21af0edd0a11a94ed221769a0a13fe9bd91f57c0ae3974ff4bdf1', '2026-08-05 23:19:24', NULL, '2026-08-04 23:19:24'),
(4, 10, '24b084c2f3fcba90651aebf29830f346ae2e99966eadded477efec28f5ff76ce', '2026-08-05 23:21:28', NULL, '2026-08-04 23:21:28'),
(5, 11, '562d1bf9454d36c0602bdedb2b9cc19d342d7f101c6ae9fe6be26ba810a7ae32', '2026-08-05 23:57:49', NULL, '2026-08-04 23:57:49'),
(6, 12, '675e46de30320b405f4071cddb3d333a2289e81bce73b77f4d940bd88888f22d', '2026-08-06 00:00:40', NULL, '2026-08-05 00:00:40'),
(7, 13, '6b6423c8e5a12805abd659f8a4b704f6bc9009db543c1c975f7042fa62a3c205', '2026-08-06 00:11:17', NULL, '2026-08-05 00:11:17'),
(8, 14, 'b4d7a85235f54361a1cfdf9e16443ac3674a47fa2fcd02d7683ee7e26c9f0595', '2026-08-06 00:16:24', '2026-08-05 00:19:05', '2026-08-05 00:16:24'),
(9, 15, 'ffc0f1236b54c19184f9d052d9d2c63fcbcecba10724563de4e87e4fba4934a4', '2026-08-06 00:38:38', NULL, '2026-08-05 00:38:38'),
(10, 16, '5c0f90cdb7f0df1180e466612fc94c6991eadf6472a79550168ebc5292c4cc98', '2026-08-06 00:43:13', NULL, '2026-08-05 00:43:13'),
(11, 17, 'd9840ac2697dc8258473a32fc44f26fd7f94a629c447122445b8488ba9c9eff8', '2026-08-06 00:44:59', NULL, '2026-08-05 00:44:59'),
(12, 18, 'db630c8a03bb582e4c4d0bce08d10058bcef440615c26c7e28571f46c6396624', '2026-08-06 00:52:08', NULL, '2026-08-05 00:52:08'),
(13, 19, '16f724585e1fa497524c39113438c82b34470db7de40f83896c6ab7a7487ca54', '2026-08-06 00:56:34', NULL, '2026-08-05 00:56:34'),
(14, 20, '029d14edd08cbab4321f4dac16fa35912e6b1afcc478c0147a9c05b2fffb8554', '2026-08-06 10:18:54', NULL, '2026-08-05 10:18:54'),
(15, 21, '43a84cfe1c6beca8ccaa74c5fa9d02ce5259b6ae23c3cd1fa6ded9d38e10146e', '2026-08-06 10:27:36', '2026-08-05 10:28:38', '2026-08-05 10:27:36'),
(16, 22, 'f6c2fb8ff8d84262ec8378a4bf714c5f6713a24cf13e787546cc749e05797e0d', '2026-08-06 11:09:04', '2026-08-05 11:09:59', '2026-08-05 11:09:04'),
(17, 23, 'cffe7604ad4f71ef1257a948ae18956659ad39e0c03d456986a6e10cc357f92c', '2026-08-06 12:03:21', NULL, '2026-08-05 12:03:21'),
(18, 24, '3c1c40fc324e91738a041add7799e84d74cecb4febfd1512bca196f6f5924317', '2026-08-06 13:32:58', '2026-08-05 13:34:19', '2026-08-05 13:32:58'),
(19, 25, 'ca78ed5ad4a127f0789d223ee553a711c593ad05a547ec3ed8a3d4762e30dc30', '2026-08-06 14:03:00', NULL, '2026-08-05 14:03:00'),
(20, 26, '5076dbfb79c60414ff56d57a470584257e44d02cec6a7200093c084b8a85bad0', '2026-08-06 14:05:57', NULL, '2026-08-05 14:05:57'),
(21, 27, 'f4cc3bbfe879d97391239b69d6531a9517e25ccf69843e859bfd3471f59f3d14', '2026-08-06 14:12:00', NULL, '2026-08-05 14:12:00'),
(22, 28, '0b151e3014e529b89f2ec73778becae28738fc59bd42bdea5bdc7c44c2a0367d', '2026-08-06 14:21:41', NULL, '2026-08-05 14:21:41'),
(23, 29, '442dfe2773efe0aa0ae18cce02587c52e7362765d728fff0a4a22c699d3daa33', '2026-08-06 14:41:19', '2026-08-05 14:42:09', '2026-08-05 14:41:19'),
(24, 30, 'f68ae22acc191f28fe1371004daccc4d38f4390e6da19c60bb32742ac6e756d0', '2026-08-06 15:10:42', '2026-08-05 15:23:57', '2026-08-05 15:10:42'),
(25, 30, 'a62e636c2a51760a4af8f51e84122edfba6840c2066eccc2558a962a50646d9c', '2026-08-06 15:23:57', '2026-08-05 15:24:18', '2026-08-05 15:23:57'),
(26, 30, '6997593f7fa19bf8046b99d49f6921923100df208ca862282741856d75413034', '2026-08-06 15:24:18', NULL, '2026-08-05 15:24:18'),
(27, 31, '13b507923f57598024a7a2e2a651401c35064b629a189e4ba4eb0959ca3bd1d5', '2026-08-06 15:26:31', '2026-08-05 15:26:55', '2026-08-05 15:26:31'),
(28, 31, '13cb79b186819b442f46eb8e7d6eec6c7d2ccc9ffe1bbac2befb61b03c76fd23', '2026-08-06 15:26:55', '2026-08-05 15:27:26', '2026-08-05 15:26:55'),
(29, 31, '440e438aebedcffbaeef56f716158cbd4f6a9f9e956cb95f369c0d2321512af1', '2026-08-06 15:27:26', NULL, '2026-08-05 15:27:26'),
(30, 32, '60c60edf7a596fdac7f2c94f5b05c3c2a03c034e242a5c2fc842df87f2ee7a98', '2026-08-06 15:43:15', NULL, '2026-08-05 15:43:15'),
(31, 33, 'ed2045efea1566436bcbf2d01849eb40319167e69f83f4b78524920d9dfba964', '2026-08-06 15:53:21', NULL, '2026-08-05 15:53:21'),
(32, 34, 'b3e825390d9764ef65c0dbffbb9b1e29014f73875779bdf9debf8a794d208af8', '2026-08-06 16:06:46', NULL, '2026-08-05 16:06:46'),
(33, 35, '92f5cd0a3d5c1e7f881e683b050cdae26ea91acc1c2e8158d94326fdaf20b64d', '2026-08-06 16:23:51', '2026-08-05 16:32:51', '2026-08-05 16:23:51'),
(34, 35, 'b9d84476feb8bebedbdaf9581fab5467c11a11537c2de49d1170a922ff8e3a5f', '2026-08-06 16:32:51', '2026-08-05 17:15:03', '2026-08-05 16:32:51'),
(35, 36, '3e503242babcb51c7a4bf9d3b59642e6599297c15eb2492ec986fc02a65c6b46', '2026-08-06 16:44:34', NULL, '2026-08-05 16:44:34'),
(36, 35, 'bbed6f38622db8de02a78064af215e1bc9016083988d0f222433a096448a873c', '2026-08-06 17:15:03', NULL, '2026-08-05 17:15:03'),
(37, 37, '16cb77067b2a0c5cf218cec7d48b4e6fa3f2123132ce6152a9e6b23b1e5587dd', '2026-08-06 17:16:11', NULL, '2026-08-05 17:16:11'),
(38, 39, 'e28792c4d5cac7c69c7e903c8820bea402c9990b7b4cf3d21f56fd347e02cf7e', '2026-08-06 17:43:38', NULL, '2026-08-05 17:43:38'),
(39, 40, 'df104664c0204f34d7ffb77c1a17388050c5605f9e9a782175681c3b1ac7d028', '2026-08-06 17:44:58', NULL, '2026-08-05 17:44:58'),
(40, 41, 'cdb6e2018919fdfe5c1c0dabf4f281ff730b3b3ab3eefc58999d30a8d79f207d', '2026-08-06 17:53:51', NULL, '2026-08-05 17:53:51'),
(41, 42, 'd9aa0ebcb6cb8236628fa45c26c7e3ae55149ed21f813a49fbe899351fb7ed83', '2026-08-06 18:06:03', NULL, '2026-08-05 18:06:03'),
(42, 43, '62527ea4a32c9ff5f1b4fddc017ce0d7cdbee6f68b66a10e90fa935758078395', '2026-08-06 18:14:51', NULL, '2026-08-05 18:14:51'),
(43, 44, '0dd048f278e672e881548615d131e2fc2ec3d034598b8e3de62c903370636ccc', '2026-08-06 18:16:12', '2026-08-05 18:28:03', '2026-08-05 18:16:12'),
(44, 45, '2a86c39dd2d4296daf60e3fe5ec0524684ca5ddbf2ce0e155fb77091661e2d9e', '2026-08-06 18:18:04', NULL, '2026-08-05 18:18:04'),
(45, 46, '39c88c5b752c0fbdd81ee75737d8e269cfb6781a74fd5f587f69d778992df27c', '2026-08-06 18:27:03', NULL, '2026-08-05 18:27:03'),
(46, 44, 'ee7dd4bd6f88013eb584888b17590e2445d9b0072f8f71ebb7c7b5ffa0d700ac', '2026-08-06 18:28:03', '2026-08-05 18:28:58', '2026-08-05 18:28:03'),
(47, 44, '93a8313b14a3652b300833ab118f5e1afd09c103b82a2cd09b240f8e2b56480d', '2026-08-06 18:28:58', NULL, '2026-08-05 18:28:58'),
(48, 47, '9efc7049731f5047ca96c192ffade167e120a4b540ff8820a07a5340c5651b59', '2026-08-06 18:45:42', NULL, '2026-08-05 18:45:42'),
(49, 48, '986c134132abf69cb95277edac6c1f64f60e24cb2da693c8625a0d566719877f', '2026-08-06 18:54:49', NULL, '2026-08-05 18:54:49'),
(50, 49, 'a59167fa29a84c9579025f98359cc259301659dcc552c97442250cf826457cb3', '2026-08-06 19:01:28', NULL, '2026-08-05 19:01:28'),
(51, 50, '1e9c993986f6e6ceec3ed40ffb253da5e7544e2cb9a0e40f6bba0a668c849234', '2026-08-06 19:09:36', '2026-08-05 19:10:12', '2026-08-05 19:09:36'),
(52, 50, 'c05a5ff3e20e1fd963de1103cd3582ba37eaf35284cbf684d504c99bab69f37d', '2026-08-06 19:10:12', '2026-08-05 19:11:35', '2026-08-05 19:10:12'),
(53, 50, 'a1b215c53024def1a4d7391de3c20aa6b8cc47802f0820ac1aa4391c3ca8ba5b', '2026-08-06 19:11:35', NULL, '2026-08-05 19:11:35'),
(54, 51, 'd3d56d2b5c9db44002105727d8d26cf60abcacda6999d0a494b2cf38a847c000', '2026-08-06 19:16:33', NULL, '2026-08-05 19:16:33'),
(55, 52, '5b85fc5912c4ebb06e740326adf1d91bfb1c133985d87023e47b2b045872b5f9', '2026-08-06 19:18:01', NULL, '2026-08-05 19:18:01'),
(56, 53, 'ad82ad83ad01be09fe43a129d836701cb4330deaed88965327bcd1c25e12049f', '2026-08-06 19:24:00', NULL, '2026-08-05 19:24:00'),
(57, 54, '0d38e7a897b4912bff32f041c15f10bad006c468fa6d54b4222f77c0df9cfa5e', '2026-08-06 20:41:43', NULL, '2026-08-05 20:41:43'),
(58, 55, '472ccd3d2a02d716eae4e91a188d50e0859908b7ca544522b3517307cfafe836', '2026-08-06 21:06:36', NULL, '2026-08-05 21:06:36'),
(59, 56, '4125c646efe8bd6aef5acfdb5fa41728528ce242ad640edf20c12357cb269776', '2026-08-06 21:33:34', NULL, '2026-08-05 21:33:34'),
(60, 57, '998f197c7335ace6d1f93454e5ee75d3be72e3b760509740b3cb4b3b0e835d0e', '2026-08-06 21:43:08', NULL, '2026-08-05 21:43:08'),
(61, 58, 'beb44f4b4684fe6af8659c3692c70034bdd9399a24f5cf7ef6bf86969022e290', '2026-08-06 21:57:40', NULL, '2026-08-05 21:57:40'),
(62, 59, '502599544f1cd55d0613ecacefc2bc995b41610aa09ab127adff04143ae0b66f', '2026-08-06 21:59:21', NULL, '2026-08-05 21:59:21'),
(63, 60, 'e91ecdc04d475dff031a4ef8ba7dd159f06c3f68b0b1951d0a59af0b0430a6f8', '2026-08-06 22:10:42', NULL, '2026-08-05 22:10:42'),
(64, 61, '93e4665cad22fd40b867baea9ae08b6171b44665e0de4083e5f58235c1ce2aff', '2026-08-06 22:20:11', NULL, '2026-08-05 22:20:11'),
(65, 62, 'e44c045845675d16989edee379efd2eac84e452a2dc2bbf27e32bbcc23e84cff', '2026-08-06 22:47:48', NULL, '2026-08-05 22:47:48'),
(66, 63, '73cd66ccddfb522dd42a033953510cdd335a7dcc39e163ba3c2e27f84d04c465', '2026-08-06 22:48:50', NULL, '2026-08-05 22:48:50'),
(67, 64, 'cec43bfe0009900e7f796bfb05e71cf342e5f14505b41314237e7c680d73123b', '2026-08-06 22:52:27', NULL, '2026-08-05 22:52:27');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at`) VALUES
(1, 3, 'cd6a91bf5302e6de92db733ee6ade19f594de3e35f02b75f987e3d025f54818d', '2026-08-03 11:10:53', '2026-08-03 10:28:22', '2026-08-03 10:10:53'),
(2, 3, '9dd3ced25a152f77dacbbb1ad0b4e3a0d65e53d992f38f5ea1f77bc75061a65d', '2026-08-03 11:28:22', '2026-08-03 11:56:50', '2026-08-03 10:28:22'),
(3, 3, '6eedaf898eb504cd11dee2c9841c95da8c71b350615bbc5eeb95786a7c4e811e', '2026-08-03 12:56:50', '2026-08-03 12:03:21', '2026-08-03 11:56:50'),
(4, 3, '3eefba131ddad4e190995b72c63d6e22b48918dadf2710c906e98a9a28a97d90', '2026-08-03 13:03:21', '2026-08-03 13:36:51', '2026-08-03 12:03:21'),
(5, 3, 'ab1d753992457364fd62671af98a01d4e14958cd6f438fb551ddec54a9296e4b', '2026-08-03 14:36:51', '2026-08-03 14:38:08', '2026-08-03 13:36:51'),
(6, 3, 'f4dca5b8354cd6e4d19818e4db9a55c0811dac7a537ff781dc58fdce9fa6026f', '2026-08-03 15:38:08', '2026-08-03 16:23:26', '2026-08-03 14:38:08'),
(7, 1, '200076211a195bf39dde09fb7e7138c06a875bbb2e19d3f9c9f2edbeaa86f9ad', '2026-08-03 15:48:33', NULL, '2026-08-03 14:48:33'),
(8, 3, '5f0c180d26b2a9c7a480384bca98442c9ec97c608071f1b44db80a85a7cab682', '2026-08-03 17:23:26', '2026-08-03 18:28:07', '2026-08-03 16:23:26'),
(9, 3, '0a8609fc393c688a1f052cc06c4052f7888e618b6ee137c56f4f21b1b117ddd4', '2026-08-03 19:28:07', '2026-08-03 22:25:10', '2026-08-03 18:28:07'),
(10, 3, '1b5ad67b4b3b695a40251809f5c569485ffa1c7315a0bcde362f861b20f21653', '2026-08-03 23:25:10', '2026-08-03 22:48:36', '2026-08-03 22:25:10'),
(11, 4, 'cd6e99ef5d1e42d3fa96d5bd2e5e9a6eb69fb3d43bbfa4e7217d71f455d2747a', '2026-08-03 23:37:59', '2026-08-03 22:47:22', '2026-08-03 22:37:59'),
(12, 4, 'ff6ce7e8943a69567110848d46255c6a3230528277996ba4929c0ab973051348', '2026-08-03 23:47:22', '2026-08-03 23:06:40', '2026-08-03 22:47:22'),
(13, 3, '10c1198f55da7fb7d3a8c89004c80fdd0b37d8aeea731233c6d53d70a5d996e8', '2026-08-03 23:48:36', '2026-08-03 23:19:25', '2026-08-03 22:48:36'),
(14, 4, 'b32fdb5b06e606204bee955c43010ff5a5d03d4e2bd17e9c703edf81a980081c', '2026-08-04 00:06:40', '2026-08-03 23:14:51', '2026-08-03 23:06:40'),
(15, 4, 'ab53f5c338f5191fd3e61d13e8e1d193a8abe18b2f8a7a4387be17494507a160', '2026-08-04 00:14:51', '2026-08-04 00:31:44', '2026-08-03 23:14:51'),
(16, 3, 'e1d8f5e3206e2f59d2d4878a771d71b41e87dc5461ad74882276dc43794af8fe', '2026-08-04 00:19:25', '2026-08-03 23:26:35', '2026-08-03 23:19:25'),
(17, 3, 'ddc96b9a6851878d4c132d2791ff36d0b2a3ce669bf5b16ecb644b387eb30c0e', '2026-08-04 00:26:35', '2026-08-03 23:41:16', '2026-08-03 23:26:35'),
(18, 3, '672c3ac0516b18a5696aab4e8da3c579da5890e8d9dc6f88465289d0b6ed1be4', '2026-08-04 00:41:16', '2026-08-03 23:44:01', '2026-08-03 23:41:16'),
(19, 3, 'b6c1e93d71477fbbd900736fd150c060dd13cff2aed38f96132c4c54d4d837cf', '2026-08-04 01:04:15', '2026-08-04 00:20:40', '2026-08-04 00:04:15'),
(20, 3, '86db1c2d8180f2240b1e0f9392d1a14bda89e82779f577ff7f1d6d65d1dfba75', '2026-08-04 00:50:40', '2026-08-04 00:28:43', '2026-08-04 00:20:40'),
(21, 3, 'd0502c9c2134af4b75307d390e1a5b14ab3e376ca8d4ebe36cdb449e4040d11b', '2026-08-04 00:58:43', '2026-08-04 00:29:47', '2026-08-04 00:28:43'),
(22, 3, '2f3645f1f1db4d50ed6493918156c8e6fe1ffaaed73158d9aac38c2313a22cde', '2026-08-04 00:59:47', '2026-08-04 00:39:16', '2026-08-04 00:29:47'),
(23, 4, 'f7a5fc6c32c60a63233751568d7d4f6a466dc650ee219a4b606c37423d74106f', '2026-08-04 01:01:45', '2026-08-04 00:42:11', '2026-08-04 00:31:45'),
(24, 3, '3e4d603023e00e6b0fdd107b81e93d5fa87115b3b707a3ef1fa0fb6200c4d014', '2026-08-04 01:09:16', '2026-08-04 00:49:19', '2026-08-04 00:39:16'),
(25, 4, '877558adeec0ff751f0f7a1fbeccd319bbd66188fc57f3dab8148935be7c04e6', '2026-08-04 01:12:11', '2026-08-04 01:08:05', '2026-08-04 00:42:11'),
(26, 4, '636659b1f1c492ff7f1c1748d18ac3253c6cc4fdc442898c84acbc1066b896c9', '2026-08-04 01:12:11', '2026-08-04 01:08:05', '2026-08-04 00:42:11'),
(27, 3, 'a1a40e3bf5bcabcfea0b3ad73afb52528ff9d1899d731366b52b556132704f42', '2026-08-04 01:19:19', '2026-08-04 00:56:57', '2026-08-04 00:49:19'),
(28, 3, 'bc54025f89afe9e1af167c905077348bc63649c73a20ea1995cb8b8a6a3e9e99', '2026-08-04 01:26:57', '2026-08-04 01:01:49', '2026-08-04 00:56:57'),
(29, 3, '3c77af5700abb9b5e93c12b7c3990b2930073895e3f5f57e0f96dcda3fd0c692', '2026-08-04 01:31:49', '2026-08-04 11:27:44', '2026-08-04 01:01:49'),
(30, 4, 'dcb9fa77342065914ed2e5f5b38e6e7b410d9ce6a54c0bf740bc87a0c4d531b5', '2026-08-04 01:38:05', '2026-08-04 13:31:03', '2026-08-04 01:08:05'),
(31, 3, '2b80ad2d67edfa002cfbc9ff13bb85d51089098ef239da45980b848f83d29f6b', '2026-08-04 11:57:47', '2026-08-04 12:53:50', '2026-08-04 11:27:47'),
(32, 3, '46f5fc326a9024696f134c92561695a4e81fd299b067bc4f6fe751b01630e186', '2026-08-04 13:53:51', '2026-08-04 12:59:38', '2026-08-04 12:53:51'),
(33, 3, 'ac290eeff3ff8665b5e1fde4b52defdfd68868025b32078f1c4f8f01b4ea362e', '2026-08-04 13:59:38', '2026-08-04 13:05:10', '2026-08-04 12:59:38'),
(34, 3, '593831db586549ef59642345dc39305def2f3c347d4744d5b5717a71a6d17ca4', '2026-08-04 14:05:10', '2026-08-04 13:12:26', '2026-08-04 13:05:10'),
(35, 3, '0942f3a6418cc7cc34966068d4ccd734787c6a2b75212357b0a1525a675a28b6', '2026-08-04 14:12:26', '2026-08-04 13:16:21', '2026-08-04 13:12:26'),
(36, 3, 'ced27a8178adba0b0e36dc72414105de318f85c85ec78b51335cbdcdef07efe0', '2026-08-04 14:16:21', '2026-08-04 13:23:20', '2026-08-04 13:16:21'),
(37, 3, '5dd2bbd07483a883dd239c27e18c6f97408adfff114677fa83d255a99e49b09e', '2026-08-04 14:23:20', '2026-08-04 13:29:49', '2026-08-04 13:23:20'),
(38, 3, '0672b6ee1dde870d63da989224c9415df8fd1dcc9583508aaebb8cc597fa8a6e', '2026-08-04 14:29:50', '2026-08-04 13:50:47', '2026-08-04 13:29:50'),
(39, 4, 'f8318b3939ee08beb2d6f79993f334742eaacbc5424fbe6176f7023548b7a30f', '2026-08-04 14:31:03', '2026-08-04 13:35:52', '2026-08-04 13:31:03'),
(40, 4, '291cbc1ddb94cc04a180e7ef365978b9f5707d89923625e80d5ec45ac13809b7', '2026-08-04 14:35:53', '2026-08-04 13:41:27', '2026-08-04 13:35:53'),
(41, 4, '74f3f9441be267182fe18a12f3fb08cf6ebe361f94acaebaec1d8d52d1d44c62', '2026-08-04 14:41:27', '2026-08-04 13:48:57', '2026-08-04 13:41:27'),
(42, 4, '731670335420e00492ddea39a9cac6f46f6c378fec59a60f1857c8410e88d826', '2026-08-04 14:48:57', '2026-08-04 14:16:58', '2026-08-04 13:48:57'),
(43, 3, '4103a2d1c42aec57a95953e62fca4021ad4ce245518d91c910d73b79d2846f51', '2026-08-04 14:50:47', '2026-08-04 13:57:53', '2026-08-04 13:50:47'),
(44, 3, '17e74b8c12de5d2330c9beba4a9e09cf931de5705fb40fed5ddc8fdf6d9f1555', '2026-08-04 14:57:54', '2026-08-04 14:09:57', '2026-08-04 13:57:54'),
(45, 3, '295e977361f9d35ac12760988d9928ffaf3c8c7a635f62fa11f5775a66d43480', '2026-08-04 15:09:57', '2026-08-04 14:15:50', '2026-08-04 14:09:57'),
(46, 3, '2f0b513510363d1e1ae5bb4e0ca4565e47e1132f8602e08f940ed108adae880f', '2026-08-04 15:15:51', '2026-08-04 14:24:12', '2026-08-04 14:15:51'),
(47, 4, '4effa5e365790d7cd9a4994bb6f3052d58c5d3a1cf2c2cf3c9fd3df3c078119a', '2026-08-04 15:16:58', '2026-08-04 14:25:15', '2026-08-04 14:16:58'),
(48, 3, '38154de4c92ec47e7b327ab0584a72d6c5406290ca2ce6903f5555d7705801b4', '2026-08-04 15:24:12', '2026-08-04 14:35:11', '2026-08-04 14:24:12'),
(49, 4, '5db816aff122dbb6984cab5c41105133427402b16612fc6db6a872bfcf0f0d25', '2026-08-04 15:25:15', '2026-08-04 14:25:38', '2026-08-04 14:25:15'),
(50, 4, '4ea1d9675b15f083cda1a02e6ebcf545de77eefb0f939bf6c4e8803e9e7317df', '2026-08-04 15:25:40', '2026-08-04 14:30:31', '2026-08-04 14:25:40'),
(51, 4, 'c47e20104b7b11d6573f12fe578d339c783cc10c5182e43e95ae7a9341c56f55', '2026-08-04 15:30:31', '2026-08-04 22:51:35', '2026-08-04 14:30:31'),
(52, 3, '5393fdd9cc8c04b738be0818fd0b59694e55a445dfd090787ea93fa06b986277', '2026-08-04 15:35:11', '2026-08-04 14:48:05', '2026-08-04 14:35:11'),
(53, 3, 'c51faef2251d52344d3625c18e05f973de6456d91668b0fab898268fd855e5a0', '2026-08-04 15:48:05', '2026-08-04 22:49:58', '2026-08-04 14:48:05'),
(54, 3, 'f4343c8cdbec2cfdcc3692d96c21f51cd3761b9a8aef055ae59ae76c6dfb9bd7', '2026-08-04 23:49:58', '2026-08-05 14:08:46', '2026-08-04 22:49:58'),
(55, 4, 'a66da3aeeb7f1deb205f7cc2aa5eebc4881e50026855f8260cbbf73513913a53', '2026-08-04 23:51:41', '2026-08-04 22:52:53', '2026-08-04 22:51:41'),
(56, 3, '0bad6947bb95fd8f6cf865e296a32369dd6c3f09f4ff8387bf0cabf8337697be', '2026-08-05 15:08:46', NULL, '2026-08-05 14:08:46'),
(57, 29, '2caaa6d537049c5474c22a2a1f826d93e6bc77e2ed113acbf783e38f47263d0a', '2026-08-05 15:42:30', NULL, '2026-08-05 14:42:30');

-- --------------------------------------------------------

--
-- Table structure for table `trades`
--

CREATE TABLE `trades` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED DEFAULT NULL,
  `symbol` varchar(32) NOT NULL,
  `direction` enum('buy','sell') NOT NULL,
  `entry_price` decimal(15,5) NOT NULL,
  `exit_price` decimal(15,5) NOT NULL,
  `volume` decimal(15,2) NOT NULL,
  `commission` decimal(15,2) NOT NULL DEFAULT '0.00',
  `swap` decimal(15,2) NOT NULL DEFAULT '0.00',
  `profit_loss` decimal(15,2) NOT NULL,
  `r_multiple` decimal(10,4) DEFAULT NULL,
  `stop_loss` decimal(15,5) DEFAULT NULL,
  `take_profit` decimal(15,5) DEFAULT NULL,
  `open_time` datetime NOT NULL,
  `close_time` datetime NOT NULL,
  `strategy_tag` varchar(64) DEFAULT NULL,
  `emotional_score` tinyint UNSIGNED DEFAULT NULL,
  `notes` text,
  `source` enum('manual','auto_sync') NOT NULL DEFAULT 'manual',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `trades`
--

INSERT INTO `trades` (`id`, `user_id`, `account_id`, `symbol`, `direction`, `entry_price`, `exit_price`, `volume`, `commission`, `swap`, `profit_loss`, `r_multiple`, `stop_loss`, `take_profit`, `open_time`, `close_time`, `strategy_tag`, `emotional_score`, `notes`, `source`, `created_at`, `updated_at`) VALUES
(1, 3, NULL, 'XAUUSDT', 'buy', 4325.00000, 4329.00000, 0.01, 0.00, 0.00, 4000.00, 0.8000, 4320.00000, NULL, '2026-08-02 14:28:00', '2026-08-02 16:28:00', 'Breakout', NULL, NULL, 'manual', '2026-08-02 16:28:46', '2026-08-02 16:28:46'),
(2, 3, NULL, 'GBPUSD', 'buy', 1.23600, 1.43200, 0.10, 0.00, 0.00, 1960.00, NULL, NULL, NULL, '2026-08-02 21:37:00', '2026-08-02 22:37:00', NULL, 4, NULL, 'manual', '2026-08-02 23:38:04', '2026-08-02 23:38:04'),
(3, 3, NULL, 'GBPUSD', 'sell', 12.32000, 12.45000, 0.20, 0.00, 0.00, -2600.00, NULL, NULL, NULL, '2026-08-02 22:08:00', '2026-08-03 00:08:00', NULL, NULL, NULL, 'manual', '2026-08-03 00:09:11', '2026-08-03 00:09:11'),
(4, 24, NULL, 'GBPUSD', 'buy', 1232.00000, 1239.00000, 0.01, 0.00, 0.00, 7000.00, NULL, NULL, NULL, '2026-08-05 10:35:00', '2026-08-05 13:35:00', NULL, NULL, NULL, 'manual', '2026-08-05 13:35:15', '2026-08-05 13:35:15');

-- --------------------------------------------------------

--
-- Table structure for table `trade_exits`
--

CREATE TABLE `trade_exits` (
  `id` bigint UNSIGNED NOT NULL,
  `trade_id` bigint UNSIGNED NOT NULL,
  `exit_type` enum('tp','sl','manual','partial') NOT NULL DEFAULT 'manual',
  `exit_price` decimal(15,5) NOT NULL,
  `volume` decimal(15,2) NOT NULL,
  `pnl` decimal(15,2) NOT NULL DEFAULT '0.00',
  `exited_at` datetime NOT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trading_accounts`
--

CREATE TABLE `trading_accounts` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `provider` enum('MT4','MT5','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `label` varchar(120) NOT NULL DEFAULT '',
  `account_number_masked` varchar(32) NOT NULL DEFAULT '',
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `leverage` varchar(16) DEFAULT NULL,
  `status` enum('connected','error','disconnected') NOT NULL DEFAULT 'disconnected',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `equity` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) NOT NULL DEFAULT '',
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `email_verified_at`, `password_hash`, `full_name`, `role`, `timezone`, `status`, `created_at`, `updated_at`) VALUES
(1, 'admin@velora.dev', '2026-08-04 16:13:32', '$2y$12$yO9S51Z8MVFVTQvoddgLf.5ZW82e3.NhjgU3VRPJ2gTv6atDVm4vy', 'مدیر سیستم', 'admin', 'UTC', 'active', '2026-08-02 16:21:25', '2026-08-04 16:13:32'),
(2, 'demo@velora.dev', '2026-08-04 16:13:32', '$2y$12$0kCcZg3x4daWlIC6mROJ4eAl5PVSLCP2mBaAnfX2Mxr5r.AujJkle', 'کاربر نمایشی', 'user', 'UTC', 'active', '2026-08-02 16:21:25', '2026-08-04 16:13:32'),
(3, 'yadgar.s.ict@gmail.com', '2026-08-04 16:13:32', '$2y$12$A4fpmy/PXysqaT/7/pof.Oy4Z6NYrO51nqKQsPazPiV4C/qJNr8MG', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-02 16:27:09', '2026-08-04 16:13:32'),
(4, 'saeedyadgare1@gmail.com', '2026-08-04 16:13:32', '$2y$12$J3vrFIrX0g8BCUt2nhUuUO2RjFf0LHF71SrX6fo9fCHuCr3EzSuKC', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-03 22:36:59', '2026-08-04 22:52:52'),
(5, 'y0907781@gmail.com', '2026-08-04 16:13:32', '$2y$12$xAGtm1v5I1zqwO8SXCjsgOna.p1KA3CIgayOCO4JBiyY1zxkkBMuC', 'محمد یاسین', 'user', 'UTC', 'active', '2026-08-03 23:48:59', '2026-08-04 16:13:32'),
(6, 'yasinnnavzbi@gmail.com', '2026-08-04 16:13:32', '$2y$12$V3vtWSXCFWIasO042pU0Iek8TuC8z26jy76kVfxC1kTqjeHE5pLmm', 'سایفون سلام', 'user', 'UTC', 'active', '2026-08-03 23:57:27', '2026-08-04 16:13:32'),
(7, 'piweg43974@bora4d.com', NULL, '$2y$12$S/jFZYa05ztHum9RF49tVOOzEsgcZDirA3kLuwupLGL4aiM7RfaoW', 'Test', 'user', 'UTC', 'active', '2026-08-04 22:57:27', '2026-08-04 22:57:27'),
(8, 'didaji8265@bejum.com', NULL, '$2y$12$UnkLrXDw5v2ZjnJiQRQJlOBQsL9fvUj3iDeJ8kZ50TsgaF.YrkyRW', 'ت', 'user', 'UTC', 'active', '2026-08-04 23:16:29', '2026-08-04 23:16:29'),
(9, 'test@mailna.co', NULL, '$2y$12$J.2eqhqXPOzW81NTWgbpluFsV.f2CEvla6fPwg0kg01rqI8aBdcmi', 'test@mailna.co', 'user', 'UTC', 'active', '2026-08-04 23:19:24', '2026-08-04 23:19:24'),
(10, 'yamidil989@davopa.com', NULL, '$2y$12$fN4YZr6R4VJWu5SqIjtpFeAAJx2ELLfXbvAsXLRCr1Y/.iJoFiZc.', 'yamidil989@davopa.com', 'user', 'UTC', 'active', '2026-08-04 23:21:28', '2026-08-04 23:21:28'),
(11, 'decey39109@amupx.com', NULL, '$2y$12$11qzIhGk9.pTQza0rNI7Nu0F/fcyS58BynaFff1u4W/jgZphzpuye', 'decey39109@amupx.com', 'user', 'UTC', 'active', '2026-08-04 23:57:49', '2026-08-04 23:57:49'),
(12, 'jamidek929@copawoke.com', NULL, '$2y$12$RDqc6UC741qRDrsg4/8QbO/NNxb3QjVjMjXftL.IG0JZ8v/OjzSZm', 'jamidek929@copawoke.com', 'user', 'UTC', 'active', '2026-08-05 00:00:40', '2026-08-05 00:00:40'),
(13, 'womit21595@bejum.com', NULL, '$2y$12$fcE.B1HBVslFHazRcaVJieQuAnLX.fNY.pTELrDE3FJTrdIp1nGLi', 'womit21595@bejum.com', 'user', 'UTC', 'active', '2026-08-05 00:11:17', '2026-08-05 00:11:17'),
(14, 'woneked378@amupx.com', '2026-08-05 00:19:06', '$2y$12$QTz47mELyzMkY7uxA0VKIOLW7nJhP9shlK5s6r.2ew3jD6aKg1Dl6', 'woneked378@amupx.com', 'user', 'UTC', 'active', '2026-08-05 00:16:24', '2026-08-05 00:19:06'),
(15, 'cesode1977@bora4d.com', NULL, '$2y$12$TVnVklHTcT2NOD/YPi6upu048gsEi7hBUhv2KQokZ6GPMNsI40MC6', 'cesode1977@bora4d.com', 'user', 'UTC', 'active', '2026-08-05 00:37:23', '2026-08-05 00:37:23'),
(16, 'yepaxi3244@copawoke.com', NULL, '$2y$12$MC4GjwM5G0JWBN5kbSFPweQwvTlbEB867D8nOZbIPoPuP/g8kcZfO', 'yepaxi3244@copawoke.com', 'user', 'UTC', 'active', '2026-08-05 00:42:54', '2026-08-05 00:42:54'),
(17, 'filin31374@bejum.com', NULL, '$2y$12$ag7gsM1Xg3NPGOlUzgxaJOwpBc6m7XbXOiCjCBOtQA4ZGiOpK.TrK', 'filin31374@bejum.com', 'user', 'UTC', 'active', '2026-08-05 00:44:59', '2026-08-05 00:44:59'),
(18, 'pifoj12618@ayable.com', NULL, '$2y$12$PBnlcEczee5Py0ACAI6SLu037FkwWlYFMx0bxYQZAD.TqNACpm36W', 'pifoj12618@ayable.com', 'user', 'UTC', 'active', '2026-08-05 00:52:08', '2026-08-05 00:52:08'),
(19, 'cireye2420@ayable.com', NULL, '$2y$12$oIUlGs0MICQkkn4upqzy4.QxbStZcf3fPkDh2Zr.4Go.gQfnAFv7m', 'cireye2420@ayable.com', 'user', 'UTC', 'active', '2026-08-05 00:56:34', '2026-08-05 00:56:34'),
(20, 'mebito7309@bora4d.com', NULL, '$2y$12$LWq0UQLyG8zRxLKJ8RB4z.2nATftL8jSmqGUM4CUvtuLy4kBhRbQG', 'mebito7309@bora4d.com', 'user', 'UTC', 'active', '2026-08-05 10:18:53', '2026-08-05 10:18:53'),
(21, 'yeyemag217@bora4d.com', '2026-08-05 10:28:38', '$2y$12$lN58d7.YCtPtidh8UrUKYeyfQfAmKSRKhAzQ.p0s9UV6C1db1JCsi', 'yeyemag217@bora4d.com', 'user', 'UTC', 'active', '2026-08-05 10:27:36', '2026-08-05 10:28:38'),
(22, 'vexicig718@bora4d.com', '2026-08-05 11:09:59', '$2y$12$hrHPZChsisv/BcDXXLL5zer.GoXCkMCA7Xt25ixbXagpA2p/wrcyS', 'vexicig718@bora4d.com', 'user', 'UTC', 'active', '2026-08-05 11:09:04', '2026-08-05 11:09:59'),
(23, 'balirav820@bejum.com', NULL, '$2y$12$FEXz0tE4nYblbcGwOX1EK.QY1KIcvbSXUTlu17Ppph33mgdtuPg7i', 'balirav820@bejum.com', 'user', 'UTC', 'active', '2026-08-05 12:03:21', '2026-08-05 12:03:21'),
(24, 'gejewo5909@bora4d.com', '2026-08-05 13:34:19', '$2y$12$dHmUErfxOcwh0LjAUopBXue79NZQlt/f.egyXrA6hukszGAd7Ia8C', 'gejewo5909@bora4d.com', 'user', 'UTC', 'active', '2026-08-05 13:32:58', '2026-08-05 13:34:19'),
(25, 'vaxexat605@bejum.com', NULL, '$2y$12$fBpzsTJbtETTQjYqw5sESu5YChAfPIY0WcDIHGHZO5X22yN86uo7.', 'vaxexat605@bejum.com', 'user', 'UTC', 'active', '2026-08-05 14:03:00', '2026-08-05 14:03:00'),
(26, 'tixix54381@bejum.com', NULL, '$2y$12$sGfaL9K/M4W3fOBEgM.BJ.Fzoq5mn7GsDUJZfjpqp0lf8N3sLeVym', 'tixix54381@bejum.com', 'user', 'UTC', 'active', '2026-08-05 14:05:57', '2026-08-05 14:05:57'),
(27, 'y2856798@gmail.com', NULL, '$2y$12$RT6I3L0bKHBGKocixbGGV.7wIEbRBKAXtkYiEztz45MYj0qHN5LsG', 'Yasin', 'user', 'UTC', 'active', '2026-08-05 14:11:59', '2026-08-05 14:11:59'),
(28, 'wilixa6682@bora4d.com', NULL, '$2y$12$SPF7WMmNomQewuYP0iWKnOhTivqSSVrd/nRTNaFwo2iDcAVxm5RK6', 'wilixa6682@bora4d.com', 'user', 'UTC', 'active', '2026-08-05 14:21:41', '2026-08-05 14:21:41'),
(29, 'vonocid988@bora4d.com', '2026-08-05 14:42:09', '$2y$12$li0r6ziUfIL5qB1nxWMslOdEpSrSvojeybsPXIF2EZICNvRd11NjC', 'vonocid988@bora4d.com', 'user', 'UTC', 'active', '2026-08-05 14:41:19', '2026-08-05 14:42:09'),
(30, 'volalan892@bejum.com', NULL, '$2y$12$eRluvktkDRZuVbUW5JUhO.A4kflZmO6XbPsSxNSJCBxzTniXA0mLC', 'سعید', 'user', 'UTC', 'active', '2026-08-05 15:10:42', '2026-08-05 15:10:42'),
(31, 'betosew472@aghism.com', NULL, '$2y$12$Dz1w7apbJn6vFkWHAikh2uO0F.7bsgeuQ6NXIIanzrhvPNjhb/LUO', 'volalan892@bejum.com', 'user', 'UTC', 'active', '2026-08-05 15:26:31', '2026-08-05 15:26:31'),
(32, 'yotab16836@copawoke.com', NULL, '$2y$12$aVXO5irAlKSGSWxSo9QrBOtfUq.Q.mpM.sC5ZWmK87XGSkmHT6Ggu', 'yotab16836@copawoke.com', 'user', 'UTC', 'active', '2026-08-05 15:43:15', '2026-08-05 15:43:15'),
(33, 'panap78203@amupx.com', NULL, '$2y$12$ysMPS6glEnaZ3jpMVV2UveNC7.TQXJ1PsWr4Y9OXbfJMusGD83FhG', 'panap78203@amupx.com', 'user', 'UTC', 'active', '2026-08-05 15:53:21', '2026-08-05 15:53:21'),
(34, 'simeb77855@applamos.com', NULL, '$2y$12$Va5cLKyl12/uYjx0KvUY/.ukx1aQ24j.isM3DNnRPpJqpk7p1MdV.', 'simeb77855@applamos.com', 'user', 'UTC', 'active', '2026-08-05 16:06:46', '2026-08-05 16:06:46'),
(35, 'gedim71531@bora4d.com', NULL, '$2y$12$lwQWwRegKFo9HdJj/Jaeee6.F5eVjxDB9ACpQm1sUNjTWgFCSelOe', 'gedim71531@bora4d.com', 'user', 'UTC', 'active', '2026-08-05 16:23:51', '2026-08-05 16:23:51'),
(36, 'yandgar.s.ict@gmail.com', NULL, '$2y$12$81wfA8uBfze2M/M/Gx90yejGbJrFGI1SCupQbgZQVIVw00ONTNhRC', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 16:44:34', '2026-08-05 16:44:34'),
(37, 'gedim715g31@bora4d.com', NULL, '$2y$12$RFZBaRpjwahQ.vqm.aKg1ePuhsNF7ge9S3jRcfzKpUGlaBtGefPR.', 'gedim71531@bora4d.com', 'user', 'UTC', 'active', '2026-08-05 17:16:11', '2026-08-05 17:16:11'),
(38, 'hbhhhhj@gggg.com', NULL, '$2y$12$oEsM4khDWbK53bsn3TLntOE/BPTtE9qRvRNnQcagkdCnrm7SIkoUC', 'علی رضایی', 'user', 'UTC', 'active', '2026-08-05 17:35:20', '2026-08-05 17:35:20'),
(39, 'nsndns@heueue.com', NULL, '$2y$12$Wx9vifPcR3.AmcieZsroKem8e0JgX2TJSi3mkehiq8Q9lymVqUuci', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 17:35:59', '2026-08-05 17:35:59'),
(40, 'hdjdjsj@ehhehe.com', NULL, '$2y$12$CQ87BPirqGuoDWX4jweDHeC4.XyQWGL4WPnBICREfiERXV3AvkJf.', 'علی رضایی', 'user', 'UTC', 'active', '2026-08-05 17:43:00', '2026-08-05 17:43:00'),
(41, 'yadngar.s.ict@gmail.com', NULL, '$2y$12$69h./UrVWFK5WMnmwpHJeu5WCa6zwymXRYBAYkPQ1dj9bZV8hC8ae', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 17:53:51', '2026-08-05 17:53:51'),
(42, 'yadgar.hs.ict@gmail.com', NULL, '$2y$12$BDeLuPDXvK29pbnwFbgJU.WcuYZvw3OdMMdrubIvRV7OUpnLoXoQq', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 18:06:03', '2026-08-05 18:06:03'),
(43, 'yabbdgar.s.ict@gmail.com', NULL, '$2y$12$mLt2Vlnn7bnEgkYlfVFYqesr4oPiKjB9ueRFxJlt8pUHs3gBX9ZcW', 'علی رضایی', 'user', 'UTC', 'active', '2026-08-05 18:14:51', '2026-08-05 18:14:51'),
(44, 'yewati3916@bejum.com', NULL, '$2y$12$.IYBqG9PBosHv9br9t5L5Of5zEbG.dXYD2P8l7Adyfn42nrtFkQyO', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 18:16:12', '2026-08-05 18:16:12'),
(45, 'vdhdeh@ehejej.com', NULL, '$2y$12$XUQNrC9Q48b3IlA0YChu4eO3P88Bonz6WmO08k7QsVRXXs77y.tvS', 'علی رضایی', 'user', 'UTC', 'active', '2026-08-05 18:18:04', '2026-08-05 18:18:04'),
(46, 'yajdgar.s.ict@gmail.com', NULL, '$2y$12$isgg.nEa93ohvNicSizXJejEF2cMRqkuHtefKM6v68zyrG9k4ttRi', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 18:27:03', '2026-08-05 18:27:03'),
(47, 'yadgar.s.9ict@gmail.com', NULL, '$2y$12$7IganZ3QDNLn1OaBsaTsUuReAsS5zVgAG1rYBpEe1aAD93QyKD.hq', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 18:45:42', '2026-08-05 18:45:42'),
(48, 'sjejejj@ehehe6.com', NULL, '$2y$12$aHfXIiicXPgi/kN0irQ.7ush3K.Teb.RDGbAYIldZGZ3zyQgk3KHa', 'Test', 'user', 'UTC', 'active', '2026-08-05 18:54:49', '2026-08-05 18:54:49'),
(49, 'yadgar.s.icbt@gmail.com', NULL, '$2y$12$z4Q2X6kQL3Hen2pu97MSK./Vx4dRH6ga1X.vZtl37X2fPenzIY8ry', 'yadgar.s.ict@gmail.com', 'user', 'UTC', 'active', '2026-08-05 19:01:27', '2026-08-05 19:01:27'),
(50, 'jdjdjd@82i2.com', NULL, '$2y$12$hjxUxLr3GpSN7A4m2jmfhunzc2mXjyt0ye91E3wYEskKMauDagRdC', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 19:09:36', '2026-08-05 19:09:36'),
(51, 'jdjdjd@iv82i2.com', NULL, '$2y$12$NwdfenCvvkcs.34GFrL/EuLxKu/lxXrR9e/5SIvyRyLUnbvR9ZhOC', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 19:16:33', '2026-08-05 19:16:33'),
(52, 'jdjdjdfj@82i2.com', NULL, '$2y$12$1MPFIQZQTXgYv6pljSvMA.UBmKrno8n7guqGdLuDt4uSrtFl/Jx4e', 'jdjdjd@82i2.com', 'user', 'UTC', 'active', '2026-08-05 19:18:01', '2026-08-05 19:18:01'),
(53, 'jdjdjd@82i2.comj', NULL, '$2y$12$KhPPN42ql6aoccKgHYAbpORslexsVK0YqSU1G8vIYL.YQtp3Z4pfu', 'jdjdjd@82i2.com', 'user', 'UTC', 'active', '2026-08-05 19:24:00', '2026-08-05 19:24:00'),
(54, 'yadghar.s.ict@gmail.com', NULL, '$2y$12$xFUqHBN.89oH.Tfj7bfm5.w8s42ET3fvdlQWvE8NZw/gDXzg4UisS', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 20:41:43', '2026-08-05 20:41:43'),
(55, 'yadgar.s.ict@gmail.comjb', NULL, '$2y$12$LDGzqToUvzeT3WVjsNylt.zZDYXxNS/nSnMa6Xihb4vFx8G2GbwTO', 'resend', 'user', 'UTC', 'active', '2026-08-05 21:06:36', '2026-08-05 21:06:36'),
(56, 'yadgar.s.ictbv@gmail.com', NULL, '$2y$12$aVI/hhjXyCo4R4ERjL5lDOXnORemJiicbqPZKU4zs23oH4LQsGBSG', 'علی رضایی', 'user', 'UTC', 'active', '2026-08-05 21:33:34', '2026-08-05 21:33:34'),
(57, 'yadgarb.s.ict@gmail.com', NULL, '$2y$12$KwDZyVt5R8QL0mYtGE9xvOHrtDmtNleyodTr7SDEj85KOfqSLkmP6', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 21:43:08', '2026-08-05 21:43:08'),
(58, 'yadgar.s.ibbct@gmail.com', NULL, '$2y$12$BY.1lsJoy.birKmq9jNnuObTxME2M2nUFPRYHmMWH2JDMOMptsMXq', 'علی رضایی', 'user', 'UTC', 'active', '2026-08-05 21:57:40', '2026-08-05 21:57:40'),
(59, 'yadgar.s.ichht@gmail.com', NULL, '$2y$12$AL4DoQ639jeibXqyXIwLMuwgUd/bPTqO7fp.8WbTWFycJIGazwMrW', 'علی رضایی', 'user', 'UTC', 'active', '2026-08-05 21:59:21', '2026-08-05 21:59:21'),
(60, 'yadgar.s.ict@dbsbgmail.com', NULL, '$2y$12$mJu2RoZS7nUpXFC.veo5x.R6ldQpp90SVON4dm2HfPR6zyfkoL0hy', 'Heheh', 'user', 'UTC', 'active', '2026-08-05 22:10:42', '2026-08-05 22:10:42'),
(61, 'yadgbnar.s.ict@gmail.com', NULL, '$2y$12$t7xkAhq7CbampKEc5/g6guHwMtyUVhj7e930bKIpK5dwPM0srjxvO', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 22:20:11', '2026-08-05 22:20:11'),
(62, 'yadgarbwbw.s.ict@gmail.com', NULL, '$2y$12$u6otPkWcZQSxdQWMGiuCN./H4daA3HdJ.D7LUB5HD5layg3jx95ya', 'سعید یادگاری', 'user', 'UTC', 'active', '2026-08-05 22:47:48', '2026-08-05 22:47:48'),
(63, 'yadgar.jdks.ict@gmail.com', NULL, '$2y$12$18eO5iZ6CPD1owQmmwSD6OoU.MZu3YseABB2nHmEZI/yU1Zw4h702', 'Jejeje', 'user', 'UTC', 'active', '2026-08-05 22:48:50', '2026-08-05 22:48:50'),
(64, 'yadgar.s.ict@bjgmail.com', NULL, '$2y$12$6qugSTnjXw0uHqkPl/osnORmkij0QoMV80/xUEkr.9l19YmdxuUVW', 'خخخ', 'user', 'UTC', 'active', '2026-08-05 22:52:27', '2026-08-05 22:52:27');

-- --------------------------------------------------------

--
-- Table structure for table `user_achievements`
--

CREATE TABLE `user_achievements` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `achievement_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `achieved_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `metadata_json` longtext COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_achievements`
--

INSERT INTO `user_achievements` (`id`, `user_id`, `achievement_key`, `achieved_at`, `metadata_json`) VALUES
(1, 24, 'EMAIL_VERIFIED', '2026-08-05 13:34:19', '{\"title\":\"تأیید ایمیل کاربری\",\"description\":\"تکمیل موفقیت‌آمیز تأیید ایمیل و عضویت رسمی در پلتفرم VELORA TRADE\",\"unlocked_at\":\"2026-08-05T13:34:19+00:00\"}'),
(2, 24, 'FIRST_TRADE', '2026-08-05 13:35:31', '{\"title\":\"اولین معامله در VELORA\",\"description\":\"ثبت اولین معامله در ژورنال هوشمند VELORA TRADE و شروع مسیر تحلیل حرفه‌ای عملکرد.\",\"unlocked_at\":\"2026-08-05T13:35:31+00:00\"}'),
(3, 29, 'EMAIL_VERIFIED', '2026-08-05 14:42:09', '{\"title\":\"تأیید ایمیل کاربری\",\"description\":\"تکمیل موفقیت‌آمیز تأیید ایمیل و عضویت رسمی در پلتفرم VELORA TRADE\",\"unlocked_at\":\"2026-08-05T14:42:09+00:00\"}');

-- --------------------------------------------------------

--
-- Table structure for table `user_devices`
--

CREATE TABLE `user_devices` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `fingerprint` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_devices`
--

INSERT INTO `user_devices` (`id`, `user_id`, `fingerprint`, `ip_address`, `user_agent`, `first_seen_at`, `last_seen_at`) VALUES
(1, 24, 'dd574b045a55bbc2b97a0be653de2a634f66f1631aa4c4ab40aebd1b64980339', '205.252.135.211', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-05 13:34:37', '2026-08-05 13:34:37');

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `refresh_token_hash` char(64) NOT NULL,
  `access_token_hash` char(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user_sessions`
--

INSERT INTO `user_sessions` (`id`, `user_id`, `refresh_token_hash`, `access_token_hash`, `ip_address`, `user_agent`, `expires_at`, `revoked_at`, `created_at`) VALUES
(1, 1, '331a546dd050845b258e14b57d809d527962f55b77c153a614066279b47f5035', '07814d4ad81de87f0b70a464cdad084c7365490a005b8c513c0e527897678492', '205.252.135.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-09-01 16:24:36', '2026-08-02 16:26:31', '2026-08-02 16:24:36'),
(2, 3, '30c5505d345b32127aab004561efdbdde2a6a601b3d7b623adb4072ca9843c7f', 'c1776bcdbd92c58129e105bda091a3f07973f222730f3e28ae1b3648dd0bd013', '5.22.104.153', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-09-01 16:48:36', '2026-08-03 23:44:02', '2026-08-02 16:27:09'),
(3, 3, '9bf7c5de183a1066d63331495b6fbf7aa04da247094ffadbf1d2ae65c2902970', 'fa78ddb875b6541459cd795cd2ac035ef007d8c450d416700c2feda86191aba3', '185.242.113.75', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-09-01 17:14:11', '2026-08-02 17:16:38', '2026-08-02 17:14:11'),
(4, 1, 'cba896d3ad7089f600b5ae55497572de92fd3cbd423cb0a802cdae2b1e237a15', '9464b58f77b381ae4a6285fabbfd9e22638f7a676bf0a9d4fa4de8f517bcb8d5', '185.242.113.75', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-09-01 17:16:45', '2026-08-02 17:25:09', '2026-08-02 17:16:45'),
(5, 3, '55acc706b2a17c1c8f9e3e42dafe79f290fd9bf7fc9bff24b0a9aa1f00a71b0d', '55c6ce7571774de5efda1531f1cdb2252366622a6ea0806ae8531e8a9e0591d9', '8.221.173.55', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-01 21:21:45', '2026-08-03 23:44:02', '2026-08-02 17:25:17'),
(6, 3, '98bc3812f80c01508427128fa5de8c9924c75e0e9bc48723e7af3ea90b2827de', '1ae9665d1c7c8aaaa35dc270f7a2d26e9248a92b38de0c4854ebdcd419fc32e9', '205.252.135.142', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-09-02 01:08:02', '2026-08-03 01:16:35', '2026-08-02 21:24:54'),
(7, 3, '0cebbcf2f2e123c730c026e35550e59fdb73002343aeb24af6683a4826281434', '76d95d1aa53d026fc92fe153bca27cf4febeedf65c4355aa3892de49ee79a517', '5.123.6.213', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-09-02 10:09:33', '2026-08-03 23:44:02', '2026-08-03 10:09:33'),
(8, 3, 'b06cef521771fe91d216171d8e092bacfe0e4161290bb4915a9084db4ecc759d', '64d4358374e9dd6fdee9d98b90653c43ff31492002fa2cf849c1f92f689db286', '5.123.6.213', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-09-02 10:09:33', '2026-08-03 23:44:02', '2026-08-03 10:09:33'),
(9, 3, '6188468ef8d329e231d6434e2c76c7aa93f8f6cfc1c012757155e9219abeeaa1', 'a89cc9cf1806f34f0780a7fc05df97c6ce5a2860424a00c0b6e2a4d446a77011', '5.22.64.237', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-09-02 10:09:35', '2026-08-03 10:10:52', '2026-08-03 10:09:35'),
(10, 3, '199ab66defa7885da40c607f39804b167e76e36f46cad3f8fb888dca549dc25d', 'd48caf754e255f5fd140be7a19351e3c1600f17bb8706adaf63d91575533ecda', '5.123.6.213', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-02 11:33:20', '2026-08-03 11:33:32', '2026-08-03 11:33:20'),
(11, 3, '2977bf987f4658fe22e9b33b527c2d15ee3cb96b27a1872a58b844b5229dca08', '2b7e35bded1641f8978cc1a05373ac5f8592ac6717c663507376405b3d0be540', '5.123.6.213', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-02 11:56:32', '2026-08-03 11:56:42', '2026-08-03 11:56:32'),
(12, 4, '301741795901913e47f89d326384c2905bb26e95d46e28e6448d61b5cd1ad452', 'bbaf105e83effacb837570836b82998b38c1d95867d278c0ab8c62f971f7ab48', '205.252.135.35', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-02 22:37:02', '2026-08-03 22:37:47', '2026-08-03 22:37:02'),
(13, 3, 'b79e88cf81b0c54796a571d90097480d6f0013d58703e47f60a2064d8278894b', 'fe4c692d018c1942dbde08164290bd4b8bb420422ebe33e8c8a952c547e0fced', '205.252.135.222', 'Mozilla/5.0 (Android 16; Mobile; rv:153.0) Gecko/153.0 Firefox/153.0', '2026-09-03 00:38:25', NULL, '2026-08-03 23:44:29'),
(14, 5, '285fa7f5a82667bb13e9e2fef353c1f4df81c7002913b687f72d9489adf38669', '677168563690eb5f28468b1cbdec60256c0b49c353954073dcd7f87d2117dedb', '205.252.135.38', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-02 23:49:07', NULL, '2026-08-03 23:49:07'),
(15, 6, '3b8efacd13333fc2b1e728c7246deeb506c8ee5d86e16e70530afc6db7c2a36c', 'd6d37d6cf892705b2a8b4f6aef2f1b571d834754e31ee161f96104d9c53ead59', '93.110.208.53', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-02 23:57:38', '2026-08-04 00:04:04', '2026-08-03 23:57:38'),
(16, 3, 'ae72d301e80304447099b7bdc349aa3b1b1dfd6679ab07f7ffaadbe2a352d4ee', '1f3b28cd991ff27142dd59008bfab7f98599de14f4bfda71fa55d0f7d77a989b', '205.252.135.116', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-03 22:49:23', NULL, '2026-08-04 16:24:08'),
(17, 3, '369a3f6e65b4813922a1a51ece13d8de527cc28d6f1f84aee85522b56e9f3eb9', '32640bb2bb1dd17ebf425136066d735f305e939e53b9bc65bbbbf8f1e6f9a4c6', '205.252.135.116', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-03 22:49:33', '2026-08-04 22:49:49', '2026-08-04 22:49:33'),
(18, 4, '0a724225bda7fe88f133bf5806eb27890b8ae57323586ba0e28104b29cad3b57', 'b7d08e7c5bd6aed772cd574645f6dfa69ab5756852d6699188c87c3f9ba1e041', '205.252.135.213', 'Mozilla/5.0 (Android 16; Mobile; rv:153.0) Gecko/153.0 Firefox/153.0', '2026-09-04 15:42:13', '2026-08-05 15:42:26', '2026-08-04 22:53:18'),
(19, 14, 'f7ee4e2cf96a295131e6da4bb0f3d76429c07ae26602fd97fa9c655f198d1780', '397546121e2229b6dd37e3e8c3a5ebeb76fe5565cb05a8b4f921c24553e69bdf', '5.250.87.43', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-04 00:19:21', NULL, '2026-08-05 00:19:21'),
(20, 21, '2a9aa6831c10ff64f522b975585436c599ce8af1047e0283445786c6da5bf747', '954f0300a8442631fffa6e3391b5d279ee78fc9e1227c51b3234d9fb1dad2716', '5.250.87.43', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-04 11:08:22', NULL, '2026-08-05 10:28:51'),
(21, 22, 'f9ad39bcaaf2f9770a99a408d0971481b2b38ba105a9d941577df9e54ff1252b', '860ef53d0790052d841d6ae7639097fd854793f85dbb1054afca20e438928f1d', '5.250.87.43', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-09-04 12:02:05', '2026-08-05 12:02:34', '2026-08-05 11:10:16'),
(22, 24, 'fa79744aa8759b9ac1c8392c10c57add30d2ed44a47c670187142d67e94922c0', 'f8743ee7547c965002f63b38221c226765b6081bdc6c91032ec53de2f0f941db', '205.252.135.150', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-04 14:02:16', '2026-08-05 14:02:20', '2026-08-05 13:34:37');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_en_user_event` (`user_id`,`event_type`),
  ADD KEY `idx_en_status` (`status`);

--
-- Indexes for table `email_preferences`
--
ALTER TABLE `email_preferences`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ev_token` (`token_hash`),
  ADD KEY `idx_ev_user` (`user_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pr_token` (`token_hash`),
  ADD KEY `idx_pr_user` (`user_id`);

--
-- Indexes for table `trades`
--
ALTER TABLE `trades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trades_user_open` (`user_id`,`open_time`),
  ADD KEY `idx_trades_user_symbol` (`user_id`,`symbol`),
  ADD KEY `idx_trades_account_open` (`account_id`,`open_time`),
  ADD KEY `idx_trades_account_symbol` (`account_id`,`symbol`),
  ADD KEY `idx_trades_close_time` (`close_time`);

--
-- Indexes for table `trade_exits`
--
ALTER TABLE `trade_exits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_exits_trade` (`trade_id`);

--
-- Indexes for table `trading_accounts`
--
ALTER TABLE `trading_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_accounts_user` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`);

--
-- Indexes for table `user_achievements`
--
ALTER TABLE `user_achievements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_achievement` (`user_id`,`achievement_key`);

--
-- Indexes for table `user_devices`
--
ALTER TABLE `user_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_device_fingerprint` (`user_id`,`fingerprint`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sessions_refresh_hash` (`refresh_token_hash`),
  ADD KEY `idx_sessions_user` (`user_id`),
  ADD KEY `idx_sessions_access_hash` (`access_token_hash`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `email_notifications`
--
ALTER TABLE `email_notifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `email_verifications`
--
ALTER TABLE `email_verifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `trades`
--
ALTER TABLE `trades`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `trade_exits`
--
ALTER TABLE `trade_exits`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trading_accounts`
--
ALTER TABLE `trading_accounts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `user_achievements`
--
ALTER TABLE `user_achievements`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `user_devices`
--
ALTER TABLE `user_devices`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD CONSTRAINT `fk_en_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `email_preferences`
--
ALTER TABLE `email_preferences`
  ADD CONSTRAINT `fk_ep_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD CONSTRAINT `fk_ev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trades`
--
ALTER TABLE `trades`
  ADD CONSTRAINT `fk_trades_account` FOREIGN KEY (`account_id`) REFERENCES `trading_accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_trades_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trade_exits`
--
ALTER TABLE `trade_exits`
  ADD CONSTRAINT `fk_exits_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_achievements`
--
ALTER TABLE `user_achievements`
  ADD CONSTRAINT `fk_ua_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_devices`
--
ALTER TABLE `user_devices`
  ADD CONSTRAINT `fk_ud_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
