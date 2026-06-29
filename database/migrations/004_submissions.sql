-- Migration 004 : Système de soumissions pour quiz et études de cas
-- Date : 2026-06-22

-- 1. Ajouter formation_id et champs de correction aux tentatives de quiz
ALTER TABLE `quiz_attempts`
  ADD COLUMN `formation_id` INT UNSIGNED DEFAULT NULL AFTER `quiz_id`,
  ADD COLUMN `submitted_for_review` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `teacher_feedback` TEXT DEFAULT NULL,
  ADD COLUMN `teacher_score` FLOAT DEFAULT NULL,
  ADD COLUMN `review_status` ENUM('not_submitted','pending','graded') DEFAULT 'not_submitted',
  ADD COLUMN `graded_by` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN `graded_at` DATETIME DEFAULT NULL,
  ADD FOREIGN KEY (`formation_id`) REFERENCES `formations`(`id`) ON DELETE SET NULL,
  ADD FOREIGN KEY (`graded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- 2. Table de soumissions pour les études de cas
CREATE TABLE IF NOT EXISTS `case_study_submissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `case_study_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `formation_id` INT UNSIGNED NOT NULL,
  `text_response` LONGTEXT DEFAULT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `score` FLOAT DEFAULT NULL,
  `max_score` FLOAT NOT NULL DEFAULT 20,
  `grade` VARCHAR(20) DEFAULT NULL COMMENT 'Ex: Acquis / Non acquis / A / B / C',
  `feedback` TEXT DEFAULT NULL,
  `status` ENUM('submitted','under_review','graded','returned') NOT NULL DEFAULT 'submitted',
  `graded_by` INT UNSIGNED DEFAULT NULL,
  `graded_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`case_study_id`) REFERENCES `case_studies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`formation_id`) REFERENCES `formations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`graded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `unique_cs_submission` (`case_study_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
