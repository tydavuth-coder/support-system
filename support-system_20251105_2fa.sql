-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               8.0.30 - MySQL Community Server - GPL
-- Server OS:                    Win64
-- HeidiSQL Version:             12.1.0.6537
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Dumping data for table support-system.users: ~12 rows (approximately)
INSERT INTO `users` (`id`, `name`, `last_name`, `gender`, `dob`, `username`, `email`, `phone`, `position`, `entity`, `ministry`, `avatar`, `role`, `password_hash`, `google2fa_secret`, `two_fa_enabled`, `reset_token`, `reset_expires`, `created_at`) VALUES
	(1, 'System Admin', NULL, NULL, NULL, 'admin@example.com', 'admin@example.com', '010284782', NULL, NULL, NULL, 'uploads/avatars/a2cfcc21210592f0.png', 'admin', '$2y$10$z48qUxtBcGRwzcZnKKhw7uVTgMZC/C6VkZ9RA8RF4P6ASqsPy9A8G', NULL, 0, NULL, NULL, '2025-10-27 13:55:07'),
	(2, 'Davuth Ty', NULL, NULL, NULL, 'ty.davuth097@gmail.com', 'ty.davuth097@gmail.com', '010284782', NULL, NULL, NULL, 'uploads/avatars/user_2_1761630468.png', 'technical', '$2y$10$Gf.EFFDs3n/tC31ik1QiCOYlWFcGU46dxJsLChWmivA19F8xSGcWa', NULL, 0, '6fcc9f4a1c42365cbb2e5c737f8272cd', '2025-11-01 03:30:06', '2025-10-27 14:35:12'),
	(3, 'Ty Davuth', NULL, NULL, NULL, 'ty.davuth123@gmail.com', 'ty.davuth123@gmail.com', '010284782', NULL, NULL, NULL, 'uploads/avatars/user_3_1761630513.jpg', 'coordinator', '$2y$10$Q77slONGRyXJZ2MQy61M6O0VhrE3IrqZTJ0PMMfqx3T9wAkVu/dGa', NULL, 0, NULL, NULL, '2025-10-27 14:35:56'),
	(4, 'Davuth', NULL, NULL, NULL, 'ty.davuth2024@gmail.com', 'ty.davuth2024@gmail.com', '010284782', NULL, NULL, NULL, 'uploads/avatars/user_4_1761630398.jpg', 'user', '$2y$10$4k8sfrBU5P2GMfHGhHYh6u28BwindieGhkZk0DKpI51pDqvCXIpYG', 'fPiQcE5QdnWOxzrGkqNKgWQwQzJneUhrVktsZ29nNmNVYzZYa1dnR21jSFFaQmVXdkZ6WVJob09NUXVZZVBtV2RQeEkyaENORXVnbEIvSGk=', 1, NULL, NULL, '2025-10-27 14:38:09'),
	(5, 'user1', NULL, NULL, NULL, 'user1@email.com', 'user1@email.com', '010284782', NULL, NULL, NULL, NULL, 'user', '$2y$10$MLoR2FkCmPnD2Kq1F49qVe3f03xqFwZdOnsayUCSUBQcnA98LgUbG', NULL, 0, NULL, NULL, '2025-10-30 02:35:09'),
	(6, 'user2', NULL, NULL, NULL, 'user2@email.com', 'user2@email.com', '010284782', NULL, NULL, NULL, NULL, 'user', '$2y$10$E5TQNkKhQPklU9mEh4NW/u/8XuOLoEKoFi4gu71gEVWS9c5optPUq', NULL, 0, NULL, NULL, '2025-10-30 02:35:30'),
	(7, 'user3', NULL, NULL, NULL, 'user3@email.com', 'user3@email.com', '010284782', NULL, NULL, NULL, NULL, 'user', '$2y$10$uijLEiz5lJVuvycQ.74ImOi/z.XnRn6agWIssP/LsMimjbg7tev4K', NULL, 0, NULL, NULL, '2025-10-30 02:35:48'),
	(8, 'user4', NULL, NULL, NULL, 'user4@email.com', 'user4@email.com', '010284782', NULL, NULL, NULL, NULL, 'user', '$2y$10$x6gWyb5kuDR3tJhoBVOHW.OZnti/y6vCfuymf9Y066JjtunpFidUC', NULL, 0, NULL, NULL, '2025-10-30 02:36:04'),
	(9, 'user5', NULL, NULL, NULL, 'user5@email.com', 'user5@email.com', '010284782', NULL, NULL, NULL, NULL, 'user', '$2y$10$QCK4Z/wswOqJa5CX5pzmde346V4vg2DABIvdVpyXime7wgeHSqbGq', NULL, 0, NULL, NULL, '2025-10-30 02:36:20'),
	(10, 'user6', NULL, NULL, NULL, 'user6@email.com', 'user6@email.com', '010284782', NULL, NULL, NULL, NULL, 'user', '$2y$10$RPFCDI6THgECshjVU51pjuYCyAt1oUc46lsVCWIG3BVbOibCWlQr6', NULL, 0, NULL, NULL, '2025-10-30 02:36:39'),
	(11, 'user7', NULL, NULL, NULL, 'user7@email.com', 'user7@email.com', '010284782', NULL, NULL, NULL, NULL, 'user', '$2y$10$XvL5iGVsmZTVFAgvr3S0AuBm8Wzvh5QKHVTv4eOWvgRdb5ID3A3fq', NULL, 0, NULL, NULL, '2025-10-30 02:36:55'),
	(12, 'user8', NULL, NULL, NULL, 'user8@email.com', 'user8@email.com', '010284782', NULL, NULL, NULL, NULL, 'user', '$2y$10$inikaOn2VvlV8.OhvuXEt.2btrQ5D2ksrbT.yz.8qYu9lKjpfD92y', NULL, 0, NULL, NULL, '2025-10-30 02:37:15');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
