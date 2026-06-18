-- ============================================================
-- LMS CFA Pro - Schéma de base de données
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- Hébergement Hostinger
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================================
-- UTILISATEURS ET RÔLES
-- ============================================================
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid` VARCHAR(36) NOT NULL UNIQUE,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','pedagogy','teacher','student') NOT NULL DEFAULT 'student',
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `username` VARCHAR(100) UNIQUE,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `birth_date` DATE DEFAULT NULL,
  `status` ENUM('active','inactive','pending','suspended') NOT NULL DEFAULT 'pending',
  `xp_points` INT UNSIGNED DEFAULT 0,
  `level` TINYINT UNSIGNED DEFAULT 1,
  `streak_days` TINYINT UNSIGNED DEFAULT 0,
  `last_login` DATETIME DEFAULT NULL,
  `last_activity` DATETIME DEFAULT NULL,
  `email_verified_at` DATETIME DEFAULT NULL,
  `email_token` VARCHAR(100) DEFAULT NULL,
  `reset_token` VARCHAR(100) DEFAULT NULL,
  `reset_token_expires` DATETIME DEFAULT NULL,
  `preferences` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_role` (`role`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TITRES RNCP
-- ============================================================
CREATE TABLE `rncp_titles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rncp_code` VARCHAR(20) NOT NULL UNIQUE,
  `title` VARCHAR(500) NOT NULL,
  `acronym` VARCHAR(50) DEFAULT NULL,
  `level` TINYINT UNSIGNED NOT NULL COMMENT 'Niveau 1-8 du cadre européen',
  `sector` VARCHAR(255) DEFAULT NULL,
  `nsc_code` VARCHAR(50) DEFAULT NULL COMMENT 'Code NSF',
  `description` TEXT DEFAULT NULL,
  `objectives` TEXT DEFAULT NULL,
  `certifier` VARCHAR(255) DEFAULT NULL,
  `registration_date` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `duration_hours` INT UNSIGNED DEFAULT NULL,
  `duration_months` TINYINT UNSIGNED DEFAULT NULL,
  `status` ENUM('active','inactive','archived') DEFAULT 'active',
  `document_path` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- BLOCS DE COMPÉTENCES / ACTIVITÉS TYPES
-- ============================================================
CREATE TABLE `activity_types` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rncp_title_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `order_num` TINYINT UNSIGNED DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`rncp_title_id`) REFERENCES `rncp_titles`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_at_code` (`rncp_title_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- COMPÉTENCES (liées aux activités types)
-- ============================================================
CREATE TABLE `competencies` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `activity_type_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `evaluation_criteria` TEXT DEFAULT NULL,
  `order_num` TINYINT UNSIGNED DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`activity_type_id`) REFERENCES `activity_types`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_comp_code` (`activity_type_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FORMATIONS
-- ============================================================
CREATE TABLE `formations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rncp_title_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(500) NOT NULL,
  `slug` VARCHAR(500) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `objectives` TEXT DEFAULT NULL,
  `prerequisites` TEXT DEFAULT NULL,
  `target_audience` TEXT DEFAULT NULL,
  `pedagogical_methods` TEXT DEFAULT NULL,
  `evaluation_methods` TEXT DEFAULT NULL,
  `duration_hours` INT UNSIGNED DEFAULT NULL,
  `duration_months` TINYINT UNSIGNED DEFAULT NULL,
  `max_students` SMALLINT UNSIGNED DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `thumbnail` VARCHAR(255) DEFAULT NULL,
  `price` DECIMAL(10,2) DEFAULT NULL,
  `status` ENUM('draft','active','archived') DEFAULT 'draft',
  `access_type` ENUM('open','invitation','approval') DEFAULT 'approval',
  `qualiopi_certified` TINYINT(1) DEFAULT 0,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`rncp_title_id`) REFERENCES `rncp_titles`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MODULES DE FORMATION
-- ============================================================
CREATE TABLE `modules` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `formation_id` INT UNSIGNED NOT NULL,
  `activity_type_id` INT UNSIGNED DEFAULT NULL,
  `competency_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `duration_hours` FLOAT DEFAULT NULL,
  `order_num` SMALLINT UNSIGNED DEFAULT 1,
  `is_mandatory` TINYINT(1) DEFAULT 1,
  `unlock_after_module_id` INT UNSIGNED DEFAULT NULL COMMENT 'Déverrouillage séquentiel',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`formation_id`) REFERENCES `formations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`activity_type_id`) REFERENCES `activity_types`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`competency_id`) REFERENCES `competencies`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CAPSULES / LEÇONS
-- ============================================================
CREATE TABLE `lessons` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `module_id` INT UNSIGNED NOT NULL,
  `competency_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `content_type` ENUM('video','pdf','document','presentation','quiz','exercise','text','scorm','link') NOT NULL DEFAULT 'text',
  `content_url` VARCHAR(500) DEFAULT NULL COMMENT 'URL vidéo ou lien externe',
  `content_body` LONGTEXT DEFAULT NULL COMMENT 'Contenu HTML pour type text',
  `file_path` VARCHAR(255) DEFAULT NULL COMMENT 'Fichier uploadé',
  `thumbnail` VARCHAR(255) DEFAULT NULL,
  `duration_minutes` SMALLINT UNSIGNED DEFAULT NULL,
  `order_num` SMALLINT UNSIGNED DEFAULT 1,
  `is_mandatory` TINYINT(1) DEFAULT 1,
  `is_preview` TINYINT(1) DEFAULT 0 COMMENT 'Visible sans inscription',
  `xp_reward` SMALLINT UNSIGNED DEFAULT 10,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`competency_id`) REFERENCES `competencies`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- RESSOURCES ANNEXES (pièces jointes aux leçons)
-- ============================================================
CREATE TABLE `lesson_resources` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `lesson_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `type` ENUM('pdf','word','excel','powerpoint','video','image','link','other') NOT NULL,
  `file_path` VARCHAR(255) DEFAULT NULL,
  `url` VARCHAR(500) DEFAULT NULL,
  `file_size` INT UNSIGNED DEFAULT NULL COMMENT 'Taille en octets',
  `download_count` INT UNSIGNED DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- INSCRIPTIONS
-- ============================================================
CREATE TABLE `enrollments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `formation_id` INT UNSIGNED NOT NULL,
  `status` ENUM('pending','active','completed','suspended','dropped') DEFAULT 'pending',
  `enrolled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `validated_by` INT UNSIGNED DEFAULT NULL,
  `validated_at` DATETIME DEFAULT NULL,
  `completion_date` DATETIME DEFAULT NULL,
  `progress_percent` TINYINT UNSIGNED DEFAULT 0,
  `certificate_path` VARCHAR(255) DEFAULT NULL,
  `certificate_issued_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `funding_type` VARCHAR(100) DEFAULT NULL COMMENT 'CPF, OPCO, autofinancement...',
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`formation_id`) REFERENCES `formations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`validated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `unique_enrollment` (`user_id`, `formation_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PROGRESSION LEÇONS
-- ============================================================
CREATE TABLE `lesson_progress` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `lesson_id` INT UNSIGNED NOT NULL,
  `status` ENUM('not_started','in_progress','completed') DEFAULT 'not_started',
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `time_spent_seconds` INT UNSIGNED DEFAULT 0,
  `last_position` INT UNSIGNED DEFAULT 0 COMMENT 'Position vidéo en secondes',
  `score` TINYINT UNSIGNED DEFAULT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_progress` (`user_id`, `lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- QUIZ
-- ============================================================
CREATE TABLE `quizzes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `lesson_id` INT UNSIGNED DEFAULT NULL,
  `module_id` INT UNSIGNED DEFAULT NULL,
  `formation_id` INT UNSIGNED DEFAULT NULL,
  `activity_type_id` INT UNSIGNED DEFAULT NULL,
  `competency_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `instructions` TEXT DEFAULT NULL,
  `quiz_type` ENUM('practice','evaluation','certification','survey') DEFAULT 'practice',
  `passing_score` TINYINT UNSIGNED DEFAULT 70 COMMENT 'Pourcentage requis',
  `max_attempts` TINYINT UNSIGNED DEFAULT 3,
  `time_limit_minutes` SMALLINT UNSIGNED DEFAULT NULL,
  `shuffle_questions` TINYINT(1) DEFAULT 1,
  `shuffle_answers` TINYINT(1) DEFAULT 1,
  `show_results` TINYINT(1) DEFAULT 1,
  `show_correct_answers` TINYINT(1) DEFAULT 0,
  `xp_reward` SMALLINT UNSIGNED DEFAULT 50,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`formation_id`) REFERENCES `formations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`activity_type_id`) REFERENCES `activity_types`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`competency_id`) REFERENCES `competencies`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `quiz_questions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quiz_id` INT UNSIGNED NOT NULL,
  `question_text` TEXT NOT NULL,
  `question_type` ENUM('single_choice','multiple_choice','true_false','short_answer','long_answer','matching','ordering') NOT NULL DEFAULT 'single_choice',
  `explanation` TEXT DEFAULT NULL COMMENT 'Explication après réponse',
  `points` TINYINT UNSIGNED DEFAULT 1,
  `order_num` SMALLINT UNSIGNED DEFAULT 1,
  `media_url` VARCHAR(500) DEFAULT NULL,
  FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `quiz_options` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `question_id` INT UNSIGNED NOT NULL,
  `option_text` TEXT NOT NULL,
  `is_correct` TINYINT(1) DEFAULT 0,
  `order_num` TINYINT UNSIGNED DEFAULT 1,
  `feedback` TEXT DEFAULT NULL,
  FOREIGN KEY (`question_id`) REFERENCES `quiz_questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `quiz_attempts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `quiz_id` INT UNSIGNED NOT NULL,
  `attempt_number` TINYINT UNSIGNED DEFAULT 1,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  `time_spent_seconds` INT UNSIGNED DEFAULT 0,
  `score` FLOAT DEFAULT NULL COMMENT 'Pourcentage',
  `raw_score` FLOAT DEFAULT NULL COMMENT 'Points obtenus',
  `max_score` FLOAT DEFAULT NULL COMMENT 'Points max',
  `passed` TINYINT(1) DEFAULT NULL,
  `status` ENUM('in_progress','completed','abandoned') DEFAULT 'in_progress',
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `quiz_attempt_answers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `selected_option_ids` TEXT DEFAULT NULL COMMENT 'JSON array d IDs',
  `text_response` TEXT DEFAULT NULL,
  `is_correct` TINYINT(1) DEFAULT NULL,
  `points_earned` FLOAT DEFAULT 0,
  FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`question_id`) REFERENCES `quiz_questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- GAMIFICATION - BADGES
-- ============================================================
CREATE TABLE `badges` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `icon` VARCHAR(50) DEFAULT 'star' COMMENT 'Nom icône Font Awesome',
  `color` VARCHAR(20) DEFAULT '#f59e0b',
  `badge_type` ENUM('completion','score','streak','activity','special','certification') NOT NULL DEFAULT 'completion',
  `criteria_type` ENUM('lessons_completed','modules_completed','formations_completed','quiz_score','streak_days','login_days','xp_earned','first_login','profile_complete') NOT NULL,
  `criteria_value` INT UNSIGNED DEFAULT 1 COMMENT 'Valeur seuil',
  `xp_reward` SMALLINT UNSIGNED DEFAULT 50,
  `rarity` ENUM('common','uncommon','rare','epic','legendary') DEFAULT 'common',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `user_badges` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `badge_id` INT UNSIGNED NOT NULL,
  `earned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `context` VARCHAR(255) DEFAULT NULL COMMENT 'Formation/leçon concernée',
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`badge_id`) REFERENCES `badges`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_user_badge` (`user_id`, `badge_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `xp_transactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `amount` SMALLINT NOT NULL COMMENT 'Positif ou négatif',
  `reason` VARCHAR(255) NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL COMMENT 'lesson, quiz, badge...',
  `reference_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ÉVALUATIONS & TRAVAUX RENDUS
-- ============================================================
CREATE TABLE `evaluations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `formation_id` INT UNSIGNED NOT NULL,
  `module_id` INT UNSIGNED DEFAULT NULL,
  `competency_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `instructions` TEXT DEFAULT NULL,
  `type` ENUM('written','oral','practical','project','mixed') DEFAULT 'written',
  `format` ENUM('file_upload','quiz','text','presentation','mixed') DEFAULT 'file_upload',
  `max_score` FLOAT DEFAULT 20,
  `passing_score` FLOAT DEFAULT 10,
  `deadline` DATETIME DEFAULT NULL,
  `duration_minutes` SMALLINT UNSIGNED DEFAULT NULL,
  `allowed_formats` VARCHAR(255) DEFAULT 'pdf,doc,docx' COMMENT 'Extensions autorisées',
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`formation_id`) REFERENCES `formations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`competency_id`) REFERENCES `competencies`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `evaluation_submissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `evaluation_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) DEFAULT NULL,
  `text_response` LONGTEXT DEFAULT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  `score` FLOAT DEFAULT NULL,
  `feedback` TEXT DEFAULT NULL,
  `grade` VARCHAR(20) DEFAULT NULL COMMENT 'A, B, C, D, E, F ou Acquis/Non acquis',
  `status` ENUM('not_submitted','submitted','graded','returned') DEFAULT 'not_submitted',
  `graded_by` INT UNSIGNED DEFAULT NULL,
  `graded_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`graded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `unique_submission` (`evaluation_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SESSIONS PÉDAGOGIQUES (classes virtuelles / présentiel)
-- ============================================================
CREATE TABLE `formation_sessions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `formation_id` INT UNSIGNED NOT NULL,
  `module_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(500) NOT NULL,
  `type` ENUM('online','onsite','hybrid','virtual_class') DEFAULT 'online',
  `date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `location` VARCHAR(500) DEFAULT NULL,
  `meeting_url` VARCHAR(500) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `facilitator_id` INT UNSIGNED DEFAULT NULL,
  `max_participants` SMALLINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`formation_id`) REFERENCES `formations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`facilitator_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `attendance` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('present','absent','excused','late') DEFAULT 'absent',
  `arrival_time` TIME DEFAULT NULL,
  `departure_time` TIME DEFAULT NULL,
  `signature_path` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `recorded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`session_id`) REFERENCES `formation_sessions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `unique_attendance` (`session_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- INDICATEURS QUALIOPI (7 critères)
-- ============================================================
CREATE TABLE `qualiopi_criteria` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `criterion_number` TINYINT UNSIGNED NOT NULL,
  `indicator_number` VARCHAR(10) NOT NULL,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT NOT NULL,
  `evidence_required` TEXT DEFAULT NULL,
  `category` ENUM('information','competencies','means','adaptation','innovation','complaints','results') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `qualiopi_evidences` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `criteria_id` INT UNSIGNED NOT NULL,
  `formation_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `file_path` VARCHAR(255) DEFAULT NULL,
  `url` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('draft','submitted','validated') DEFAULT 'draft',
  `validated_by` INT UNSIGNED DEFAULT NULL,
  `validated_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`criteria_id`) REFERENCES `qualiopi_criteria`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`formation_id`) REFERENCES `formations`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`validated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SATISFACTION / ENQUÊTES
-- ============================================================
CREATE TABLE `surveys` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `formation_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(500) NOT NULL,
  `type` ENUM('pre_training','post_training','satisfaction','positioning') NOT NULL DEFAULT 'satisfaction',
  `is_anonymous` TINYINT(1) DEFAULT 0,
  `deadline` DATETIME DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`formation_id`) REFERENCES `formations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `survey_questions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `survey_id` INT UNSIGNED NOT NULL,
  `question_text` TEXT NOT NULL,
  `question_type` ENUM('rating','text','single_choice','multiple_choice','nps') DEFAULT 'rating',
  `options` TEXT DEFAULT NULL COMMENT 'JSON array pour les choix',
  `required` TINYINT(1) DEFAULT 1,
  `order_num` TINYINT UNSIGNED DEFAULT 1,
  FOREIGN KEY (`survey_id`) REFERENCES `surveys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `survey_responses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `survey_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL si anonyme',
  `responses` JSON NOT NULL,
  `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`survey_id`) REFERENCES `surveys`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MESSAGES INTERNES
-- ============================================================
CREATE TABLE `messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sender_id` INT UNSIGNED NOT NULL,
  `recipient_id` INT UNSIGNED NOT NULL,
  `subject` VARCHAR(500) DEFAULT NULL,
  `body` TEXT NOT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`recipient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- NOTIFICATIONS
-- ============================================================
CREATE TABLE `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('info','success','warning','badge','quiz','session','message','system') DEFAULT 'info',
  `action_url` VARCHAR(500) DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_unread` (`user_id`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- JOURNAL D'AUDIT (conformité Qualiopi)
-- ============================================================
CREATE TABLE `audit_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(255) NOT NULL,
  `entity_type` VARCHAR(100) DEFAULT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `old_values` JSON DEFAULT NULL,
  `new_values` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_entity` (`entity_type`, `entity_id`),
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FORUM / DISCUSSIONS
-- ============================================================
CREATE TABLE `forum_topics` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `formation_id` INT UNSIGNED DEFAULT NULL,
  `module_id` INT UNSIGNED DEFAULT NULL,
  `lesson_id` INT UNSIGNED DEFAULT NULL,
  `author_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(500) NOT NULL,
  `body` TEXT NOT NULL,
  `is_pinned` TINYINT(1) DEFAULT 0,
  `is_closed` TINYINT(1) DEFAULT 0,
  `views` INT UNSIGNED DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`formation_id`) REFERENCES `formations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `forum_replies` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `topic_id` INT UNSIGNED NOT NULL,
  `author_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `is_solution` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`topic_id`) REFERENCES `forum_topics`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PARAMÈTRES GLOBAUX
-- ============================================================
CREATE TABLE `settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT DEFAULT NULL,
  `setting_group` VARCHAR(50) DEFAULT 'general',
  `label` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
