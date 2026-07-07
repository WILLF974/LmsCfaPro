<?php
/**
 * Migration runner — à exécuter UNE SEULE FOIS via le navigateur
 * puis supprimer ce fichier.
 * URL : https://lmscfapro.fr/migrate.php
 */
require_once __DIR__ . '/config/config.php';

// Sécurité basique : uniquement admin connecté
session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;color:red">Accès refusé. Connectez-vous en tant qu\'administrateur d\'abord.</p>');
}

$pdo = getDB();
$results = [];

$migrations = [

    // ── Migration 004 : Soumissions quiz + études de cas ──────────────────
    '004_add_formation_id_to_quiz_attempts' => "
        ALTER TABLE `quiz_attempts`
        ADD COLUMN IF NOT EXISTS `formation_id` INT UNSIGNED DEFAULT NULL AFTER `quiz_id`
    ",
    '004_add_submitted_for_review' => "
        ALTER TABLE `quiz_attempts`
        ADD COLUMN IF NOT EXISTS `submitted_for_review` TINYINT(1) NOT NULL DEFAULT 0
    ",
    '004_add_teacher_feedback' => "
        ALTER TABLE `quiz_attempts`
        ADD COLUMN IF NOT EXISTS `teacher_feedback` TEXT DEFAULT NULL
    ",
    '004_add_teacher_score' => "
        ALTER TABLE `quiz_attempts`
        ADD COLUMN IF NOT EXISTS `teacher_score` FLOAT DEFAULT NULL
    ",
    '004_add_review_status' => "
        ALTER TABLE `quiz_attempts`
        ADD COLUMN IF NOT EXISTS `review_status` ENUM('not_submitted','pending','graded') DEFAULT 'not_submitted'
    ",
    '004_add_graded_by_quiz' => "
        ALTER TABLE `quiz_attempts`
        ADD COLUMN IF NOT EXISTS `graded_by` INT UNSIGNED DEFAULT NULL
    ",
    '004_add_graded_at_quiz' => "
        ALTER TABLE `quiz_attempts`
        ADD COLUMN IF NOT EXISTS `graded_at` DATETIME DEFAULT NULL
    ",
    // ── Migration 014 : Kanban ───────────────────────────────────────────────
    '014_create_kanban_boards' => "
        CREATE TABLE IF NOT EXISTS `kanban_boards` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `cohort_id` INT UNSIGNED DEFAULT NULL,
          `student_id` INT UNSIGNED DEFAULT NULL,
          `created_by` INT UNSIGNED NOT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `unique_cohort_board` (`cohort_id`),
          UNIQUE KEY `unique_student_board` (`student_id`),
          FOREIGN KEY (`cohort_id`) REFERENCES `cohorts`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
          INDEX `idx_kb_student` (`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    '014_create_kanban_cards' => "
        CREATE TABLE IF NOT EXISTS `kanban_cards` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `board_id` INT UNSIGNED NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `description` TEXT DEFAULT NULL,
          `due_date` DATE DEFAULT NULL,
          `sequence_id` INT UNSIGNED DEFAULT NULL,
          `module_id` INT UNSIGNED DEFAULT NULL,
          `status` ENUM('todo','in_progress','submitted','graded') NOT NULL DEFAULT 'todo',
          `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          `created_by` INT UNSIGNED NOT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`board_id`) REFERENCES `kanban_boards`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`sequence_id`) REFERENCES `sequences`(`id`) ON DELETE SET NULL,
          FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`) ON DELETE SET NULL,
          FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
          INDEX `idx_kc_board_status` (`board_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    '014_create_kanban_member_status' => "
        CREATE TABLE IF NOT EXISTS `kanban_member_status` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `board_id` INT UNSIGNED NOT NULL,
          `student_id` INT UNSIGNED NOT NULL,
          `status` ENUM('todo','in_progress','submitted','graded') NOT NULL DEFAULT 'todo',
          `updated_by` INT UNSIGNED DEFAULT NULL,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `unique_member_status` (`board_id`, `student_id`),
          FOREIGN KEY (`board_id`) REFERENCES `kanban_boards`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
          INDEX `idx_kms_student` (`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // ── Migration 010 : Cohortes ─────────────────────────────────────────────
    '010_create_cohorts' => "
        CREATE TABLE IF NOT EXISTS `cohorts` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `name` VARCHAR(255) NOT NULL,
          `rncp_title_id` INT UNSIGNED DEFAULT NULL,
          `year` SMALLINT UNSIGNED DEFAULT NULL,
          `description` TEXT DEFAULT NULL,
          `created_by` INT UNSIGNED DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          FOREIGN KEY (`rncp_title_id`) REFERENCES `rncp_titles`(`id`) ON DELETE SET NULL,
          FOREIGN KEY (`created_by`)    REFERENCES `users`(`id`)       ON DELETE SET NULL,
          INDEX `idx_rncp` (`rncp_title_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    '010_create_cohort_members' => "
        CREATE TABLE IF NOT EXISTS `cohort_members` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `cohort_id` INT UNSIGNED NOT NULL,
          `student_id` INT UNSIGNED NOT NULL,
          `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `added_by` INT UNSIGNED DEFAULT NULL,
          UNIQUE KEY `unique_member` (`cohort_id`, `student_id`),
          FOREIGN KEY (`cohort_id`)  REFERENCES `cohorts`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`student_id`) REFERENCES `users`(`id`)   ON DELETE CASCADE,
          FOREIGN KEY (`added_by`)   REFERENCES `users`(`id`)   ON DELETE SET NULL,
          INDEX `idx_student` (`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    '011_cohort_member_excluded' => "
        ALTER TABLE `cohort_members`
        ADD COLUMN IF NOT EXISTS `excluded_from_stats` TINYINT(1) NOT NULL DEFAULT 0
    ",
    '013_agenda_cohort_nullable' => "
        ALTER TABLE `agenda_entries`
        MODIFY COLUMN `cohort_id` INT UNSIGNED DEFAULT NULL
    ",
    '013_agenda_student_id' => "
        ALTER TABLE `agenda_entries`
        ADD COLUMN IF NOT EXISTS `student_id` INT UNSIGNED DEFAULT NULL AFTER `cohort_id`
    ",
    '013_agenda_student_fk' => "
        ALTER TABLE `agenda_entries`
        ADD CONSTRAINT `fk_agenda_student_id` FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        ADD INDEX `idx_agenda_student_date` (`student_id`, `scheduled_date`)
    ",
    '012_create_agenda_entries' => "
        CREATE TABLE IF NOT EXISTS `agenda_entries` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `cohort_id` INT UNSIGNED NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `instructions` TEXT DEFAULT NULL,
          `scheduled_date` DATE NOT NULL,
          `time_start` TIME DEFAULT NULL,
          `time_end` TIME DEFAULT NULL,
          `sequence_id` INT UNSIGNED DEFAULT NULL,
          `module_id` INT UNSIGNED DEFAULT NULL,
          `created_by` INT UNSIGNED NOT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_by` INT UNSIGNED DEFAULT NULL,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          FOREIGN KEY (`cohort_id`)   REFERENCES `cohorts`(`id`)    ON DELETE CASCADE,
          FOREIGN KEY (`sequence_id`) REFERENCES `sequences`(`id`)  ON DELETE SET NULL,
          FOREIGN KEY (`module_id`)   REFERENCES `modules`(`id`)    ON DELETE SET NULL,
          FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)      ON DELETE RESTRICT,
          FOREIGN KEY (`updated_by`)  REFERENCES `users`(`id`)      ON DELETE SET NULL,
          INDEX `idx_cohort_date` (`cohort_id`, `scheduled_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // ── Migration 009 : Tutorat ──────────────────────────────────────────────
    '009_create_tutor_assignments' => "
        CREATE TABLE IF NOT EXISTS `tutor_assignments` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `student_id` INT UNSIGNED NOT NULL,
          `teacher_id` INT UNSIGNED NOT NULL,
          `assigned_by` INT UNSIGNED DEFAULT NULL,
          `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `revoked_at` DATETIME DEFAULT NULL,
          `revoked_by` INT UNSIGNED DEFAULT NULL,
          `notes` VARCHAR(500) DEFAULT NULL,
          FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`assigned_by`)  REFERENCES `users`(`id`) ON DELETE SET NULL,
          FOREIGN KEY (`revoked_by`)   REFERENCES `users`(`id`) ON DELETE SET NULL,
          INDEX `idx_student_active` (`student_id`, `revoked_at`),
          INDEX `idx_teacher` (`teacher_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // ── Migration 015 : Accès par périmètre pédagogique ────────────────────────
    '017_create_cohort_access_grants' => "
        CREATE TABLE IF NOT EXISTS `cohort_access_grants` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `cohort_id` INT UNSIGNED NOT NULL,
          `scope_type` ENUM('rncp_title','activity_type','competency','sequence','module') NOT NULL,
          `scope_id` INT UNSIGNED NOT NULL,
          `granted_by` INT UNSIGNED DEFAULT NULL,
          `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `revoked_at` DATETIME DEFAULT NULL,
          `revoked_by` INT UNSIGNED DEFAULT NULL,
          `notes` VARCHAR(255) DEFAULT NULL,
          FOREIGN KEY (`cohort_id`) REFERENCES `cohorts`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`granted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
          FOREIGN KEY (`revoked_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
          UNIQUE KEY `unique_cohort_grant` (`cohort_id`, `scope_type`, `scope_id`),
          INDEX `idx_cohort_active` (`cohort_id`, `revoked_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    '016_add_is_published_seq' => "ALTER TABLE sequences ADD COLUMN IF NOT EXISTS is_published TINYINT(1) NOT NULL DEFAULT 1",
    '016_add_is_published_mod' => "ALTER TABLE modules ADD COLUMN IF NOT EXISTS is_published TINYINT(1) NOT NULL DEFAULT 1",

    '015_create_access_grants' => "
        CREATE TABLE IF NOT EXISTS `access_grants` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT UNSIGNED NOT NULL,
          `scope_type` ENUM('rncp_title','activity_type','competency','sequence','module') NOT NULL,
          `scope_id` INT UNSIGNED NOT NULL,
          `granted_by` INT UNSIGNED DEFAULT NULL,
          `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `revoked_at` DATETIME DEFAULT NULL,
          `revoked_by` INT UNSIGNED DEFAULT NULL,
          `notes` VARCHAR(255) DEFAULT NULL,
          FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`granted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
          FOREIGN KEY (`revoked_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
          UNIQUE KEY `unique_grant` (`user_id`, `scope_type`, `scope_id`),
          INDEX `idx_user_active` (`user_id`, `revoked_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    '004_create_case_study_submissions' => "
        CREATE TABLE IF NOT EXISTS `case_study_submissions` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `case_study_id` INT UNSIGNED NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `formation_id` INT UNSIGNED DEFAULT NULL,
          `text_response` LONGTEXT DEFAULT NULL,
          `file_path` VARCHAR(500) DEFAULT NULL,
          `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `score` FLOAT DEFAULT NULL,
          `max_score` FLOAT NOT NULL DEFAULT 20,
          `grade` VARCHAR(20) DEFAULT NULL,
          `feedback` TEXT DEFAULT NULL,
          `status` ENUM('submitted','under_review','graded','returned') NOT NULL DEFAULT 'submitted',
          `graded_by` INT UNSIGNED DEFAULT NULL,
          `graded_at` DATETIME DEFAULT NULL,
          INDEX `idx_case_study_id` (`case_study_id`),
          INDEX `idx_user_id` (`user_id`),
          INDEX `idx_formation_id` (`formation_id`),
          UNIQUE KEY `unique_cs_submission` (`case_study_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
];

foreach ($migrations as $name => $sql) {
    try {
        $pdo->exec(trim($sql));
        $results[] = ['status' => 'ok', 'name' => $name];
    } catch (PDOException $e) {
        // "Duplicate column" = déjà appliqué, c'est OK
        $msg = $e->getMessage();
        $alreadyDone = strpos($msg, 'Duplicate column') !== false
                    || strpos($msg, 'already exists') !== false
                    || strpos($msg, 'Duplicate key name') !== false
                    || strpos($msg, 'Duplicate foreign key') !== false;
        $results[] = [
            'status' => $alreadyDone ? 'skip' : 'error',
            'name'   => $name,
            'msg'    => $msg,
        ];
    }
}

$ok    = array_filter($results, fn($r) => $r['status'] === 'ok');
$skips = array_filter($results, fn($r) => $r['status'] === 'skip');
$errs  = array_filter($results, fn($r) => $r['status'] === 'error');
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Migration</title>
<style>
  body { font-family:sans-serif; max-width:760px; margin:40px auto; padding:0 20px; background:#0f0f14; color:#e2e8f0; }
  h1 { color:#818cf8; }
  .ok   { color:#34d399; } .skip { color:#94a3b8; } .err  { color:#f87171; }
  .box  { background:#1e1e2e; border-radius:10px; padding:16px 20px; margin:12px 0; }
  .msg  { font-size:12px; color:#64748b; margin-top:4px; }
</style>
</head>
<body>
<h1>Migration 004 — Soumissions</h1>
<p>Résultats : <span class="ok"><?= count($ok) ?> appliquée(s)</span> · <span class="skip"><?= count($skips) ?> déjà faite(s)</span> · <span class="err"><?= count($errs) ?> erreur(s)</span></p>

<?php foreach ($results as $r): ?>
<div class="box">
  <span class="<?= $r['status'] ?>">
    <?= $r['status'] === 'ok' ? '✅' : ($r['status'] === 'skip' ? '⏭' : '❌') ?>
    <?= htmlspecialchars($r['name']) ?>
  </span>
  <?php if (!empty($r['msg'])): ?><div class="msg"><?= htmlspecialchars($r['msg']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?>

<?php if (empty($errs)): ?>
<div style="background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.3);border-radius:10px;padding:16px 20px;margin-top:20px">
  <strong class="ok">✅ Migration terminée avec succès.</strong>
  <p style="color:#94a3b8;margin:8px 0 0"><strong style="color:#f87171">Supprimez ce fichier</strong> depuis le FTP : <code>migrate.php</code></p>
</div>
<?php else: ?>
<div style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);border-radius:10px;padding:16px 20px;margin-top:20px">
  <strong class="err">⚠️ Des erreurs sont survenues. Vérifiez les messages ci-dessus.</strong>
</div>
<?php endif; ?>
</body>
</html>
