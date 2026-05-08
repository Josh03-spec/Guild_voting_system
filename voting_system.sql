-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 08, 2026 at 07:11 PM
-- Server version: 8.0.45-0ubuntu0.24.04.1
-- PHP Version: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `voting_system`
--
CREATE DATABASE IF NOT EXISTS `voting_system` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `voting_system`;

-- --------------------------------------------------------

--
-- Table structure for table `candidates`
--

CREATE TABLE `candidates` (
  `id` int UNSIGNED NOT NULL,
  `election_id` int UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `candidates`
--

INSERT INTO `candidates` (`id`, `election_id`, `name`, `description`, `added_at`) VALUES
(1, 1, 'Kateregga Allan', 'Third year, Bachelor of Commerce. Campaigning for affordable guild services and better student welfare.', '2026-05-06 19:04:59'),
(2, 1, 'Namukasa Joan', 'Fourth year, Bachelor of Laws. Focused on academic rights and improved library access.', '2026-05-06 19:04:59'),
(3, 1, 'Byarugaba Derrick', 'Third year, Bachelor of Engineering. Pushing for better ICT infrastructure on campus.', '2026-05-06 19:04:59'),
(4, 2, 'Nankya Ritah', 'Second year, Bachelor of Education. Experienced in student debate and representation.', '2026-05-06 19:04:59'),
(5, 2, 'Lubega Charles', 'Third year, Bachelor of Science. Former class representative with strong communication skills.', '2026-05-06 19:04:59'),
(6, 3, 'Namusoke Irene', 'Faculty of Arts representative candidate.', '2026-05-06 19:04:59'),
(7, 3, 'Ssali Brian', 'Faculty of Science representative candidate.', '2026-05-06 19:04:59'),
(8, 3, 'Akello Doreen', 'Faculty of Business representative candidate.', '2026-05-06 19:04:59');

-- --------------------------------------------------------

--
-- Table structure for table `elections`
--

CREATE TABLE `elections` (
  `id` int UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text,
  `status` enum('pending','open','closed') NOT NULL DEFAULT 'pending',
  `created_by` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `elections`
--

INSERT INTO `elections` (`id`, `title`, `description`, `status`, `created_by`, `created_at`) VALUES
(1, 'Guild President Election 2026-2027', 'Annual election for the Student Guild President position. All registered students are eligible to vote.', 'closed', 1, '2026-05-06 19:01:04'),
(2, 'Guild Speaker Election 2026-2027', 'Election for the Student Guild Speaker who chairs guild parliament sessions.', 'open', 1, '2026-05-06 19:01:04'),
(3, 'Best Faculty Representative 2026-2027', 'Closed election held in the previous semester. Results are available for viewing.', 'closed', 1, '2026-05-06 19:01:04');

-- --------------------------------------------------------

--
-- Table structure for table `eligible_students`
--

CREATE TABLE `eligible_students` (
  `id` int UNSIGNED NOT NULL,
  `student_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `official_email` varchar(150) NOT NULL,
  `is_claimed` tinyint(1) NOT NULL DEFAULT '0',
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `eligible_students`
--

INSERT INTO `eligible_students` (`id`, `student_number`, `full_name`, `official_email`, `is_claimed`, `added_at`) VALUES
(1, '2500501001', 'Nakamya Brenda', 'nakamya.brenda@stud.umu.ac.ug', 1, '2026-05-06 18:22:12'),
(2, '2500501002', 'Okello James', 'okello.james@stud.umu.ac.ug', 1, '2026-05-06 18:22:12'),
(3, '2500501003', 'Auma Grace', 'auma.grace@stud.umu.ac.ug', 1, '2026-05-06 18:22:12'),
(4, '2500501004', 'Mugisha Daniel', 'mugisha.daniel@stud.umu.ac.ug', 1, '2026-05-06 18:22:12'),
(5, '2500501005', 'Nalwoga Fiona', 'nalwoga.fiona@stud.umu.ac.ug', 1, '2026-05-06 18:22:12'),
(6, '2500501006', 'Ssemakula Peter', 'ssemakula.peter@stud.umu.ac.ug', 1, '2026-05-06 18:22:12'),
(7, '2500501007', 'Atim Catherine', 'atim.catherine@stud.umu.ac.ug', 1, '2026-05-06 18:22:12'),
(8, '2500501008', 'Tumwine Robert', 'tumwine.robert@stud.umu.ac.ug', 0, '2026-05-06 18:22:12'),
(9, '2500501009', 'Nabukenya Shamim', 'nabukenya.shamim@stud.umu.ac.ug', 0, '2026-05-06 18:22:12'),
(10, '2500501010', 'Waiswa Emmanuel', 'waiswa.emmanuel@stud.umu.ac.ug', 0, '2026-05-06 18:22:12'),
(11, '2500502026', 'Josh Admin', 'josh.admin@stud.umu.ac.ug', 1, '2026-05-07 15:22:16'),
(12, '2500501021', 'Nakibungo Joan', 'nakibungo.joan@stud.umu.ac.ug', 1, '2026-05-08 21:04:30'),
(13, '2500501022', 'Namaganda Olive', 'namaganda.olive@stud.umu.ac.ug', 1, '2026-05-08 21:22:41'),
(14, '2500501023', 'Ayambe Treasure', 'ayambe.treasure@stud.umu.ac.ug', 1, '2026-05-08 21:23:41'),
(15, '2500501024', 'Adome_John', 'adome.john@stud.umu.ac.ug', 0, '2026-05-08 22:04:36'),
(16, '2500501025', 'Echoku_Robert', 'echoku.robert@stud.umu.ac.ug', 0, '2026-05-08 22:04:36');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `student_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','admin') NOT NULL DEFAULT 'student',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `registered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `student_number`, `full_name`, `email`, `password_hash`, `role`, `is_active`, `registered_at`) VALUES
(1, 'ADMIN0001', 'System Administrator', 'admin@umu.ac.ug', '$2y$12$qcKDl1CVOUjrMHhmkdj73.TGlnKtwhqjLuFYXwQkEdBnreUji8LfW', 'admin', 1, '2026-05-06 18:57:01'),
(2, '2500501001', 'Nakamya Brenda', 'nakamya.brenda@stud.umu.ac.ug', '$2y$12$R03ZVGbmF0SIhDfXeEHNSu3rEu9Bip/sTwsNitvXBJ04ADyyxkg32', 'student', 1, '2026-05-06 18:57:01'),
(3, '2500501002', 'Okello James', 'okello.james@stud.umu.ac.ug', '$2y$12$R03ZVGbmF0SIhDfXeEHNSu3rEu9Bip/sTwsNitvXBJ04ADyyxkg32', 'student', 1, '2026-05-06 18:57:01'),
(4, '2500501003', 'Auma Grace', 'auma.grace@stud.umu.ac.ug', '$2y$12$R03ZVGbmF0SIhDfXeEHNSu3rEu9Bip/sTwsNitvXBJ04ADyyxkg32', 'student', 1, '2026-05-06 18:57:01'),
(5, '2500501004', 'Mugisha Daniel', 'mugisha.daniel@stud.umu.ac.ug', '$2y$12$R03ZVGbmF0SIhDfXeEHNSu3rEu9Bip/sTwsNitvXBJ04ADyyxkg32', 'student', 1, '2026-05-06 18:57:01'),
(6, '2500501005', 'Nalwoga Fiona', 'nalwoga.fiona@stud.umu.ac.ug', '$2y$12$R03ZVGbmF0SIhDfXeEHNSu3rEu9Bip/sTwsNitvXBJ04ADyyxkg32', 'student', 1, '2026-05-06 18:57:01'),
(7, '2500501006', 'Ssemakula Peter', 'ssemakula.peter@stud.umu.ac.ug', '$2y$12$R03ZVGbmF0SIhDfXeEHNSu3rEu9Bip/sTwsNitvXBJ04ADyyxkg32', 'student', 1, '2026-05-06 18:57:01'),
(8, '2500501007', 'Atim Catherine', 'atim.catherine@stud.umu.ac.ug', '$2y$12$kCX/qDYOPWgxuvN5Z.yQuOXvsS2d6.0uulw.wE3iwoCfNM9Dshhkm', 'student', 1, '2026-05-07 12:13:20'),
(9, '2500502026', 'Josh Admin', 'josh.admin@stud.umu.ac.ug', '$2y$12$CkbquJjOUzb2F8Q5q3TuLu9QOsQTmgICnZ2zzeZqsDxNFm9oZ/jyK', 'admin', 1, '2026-05-07 15:23:54'),
(10, '2500501021', 'Nakibungo Joan', 'nakibungo.joan@stud.umu.ac.ug', '$2y$12$SkSdXiLlaXHmo8AK2QZUxel/d.1zSV9dqw9xj7uI5gsdwU9vClGnS', 'student', 1, '2026-05-08 21:15:36'),
(11, '2500501022', 'Namaganda Olive', 'namaganda.olive@stud.umu.ac.ug', '$2y$12$/64ROWNT9Llq/.OXAXdaYex4iFy04uZJFrNOjiK15KTzZ85FBL1Hy', 'student', 1, '2026-05-08 21:25:30'),
(12, '2500501023', 'Ayambe Treasure', 'ayambe.treasure@stud.umu.ac.ug', '$2y$12$cXZLi.zzd0mo/sEQcTQEFetk7OoSNC8eY.cg5ei2UU6YK9kiK4CwK', 'student', 1, '2026-05-08 21:26:16');

-- --------------------------------------------------------

--
-- Table structure for table `votes`
--

CREATE TABLE `votes` (
  `id` int UNSIGNED NOT NULL,
  `student_id` int UNSIGNED NOT NULL,
  `election_id` int UNSIGNED NOT NULL,
  `candidate_id` int UNSIGNED NOT NULL,
  `voted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `votes`
--

INSERT INTO `votes` (`id`, `student_id`, `election_id`, `candidate_id`, `voted_at`) VALUES
(1, 3, 1, 1, '2026-05-06 19:07:34'),
(2, 4, 1, 2, '2026-05-06 19:07:34'),
(3, 5, 1, 1, '2026-05-06 19:07:34'),
(4, 6, 1, 3, '2026-05-06 19:07:34'),
(5, 2, 3, 6, '2026-05-06 19:07:34'),
(6, 3, 3, 7, '2026-05-06 19:07:34'),
(7, 4, 3, 5, '2026-05-06 19:07:34'),
(8, 5, 3, 5, '2026-05-06 19:07:34'),
(9, 6, 3, 6, '2026-05-06 19:07:34'),
(10, 9, 1, 3, '2026-05-07 15:24:52'),
(11, 10, 2, 4, '2026-05-08 21:16:49'),
(12, 11, 2, 5, '2026-05-08 21:25:57'),
(13, 12, 2, 5, '2026-05-08 21:26:57');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `candidates`
--
ALTER TABLE `candidates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_candidate_election` (`election_id`);

--
-- Indexes for table `elections`
--
ALTER TABLE `elections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_election_creator` (`created_by`);

--
-- Indexes for table `eligible_students`
--
ALTER TABLE `eligible_students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_number` (`student_number`),
  ADD UNIQUE KEY `official_email` (`official_email`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_number` (`student_number`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `votes`
--
ALTER TABLE `votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_one_vote_per_election` (`student_id`,`election_id`),
  ADD KEY `fk_vote_election` (`election_id`),
  ADD KEY `fk_vote_candidate` (`candidate_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `candidates`
--
ALTER TABLE `candidates`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `elections`
--
ALTER TABLE `elections`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `eligible_students`
--
ALTER TABLE `eligible_students`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `votes`
--
ALTER TABLE `votes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `candidates`
--
ALTER TABLE `candidates`
  ADD CONSTRAINT `fk_candidate_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `elections`
--
ALTER TABLE `elections`
  ADD CONSTRAINT `fk_election_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `votes`
--
ALTER TABLE `votes`
  ADD CONSTRAINT `fk_vote_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_vote_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_vote_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
