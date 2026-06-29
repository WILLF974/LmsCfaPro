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
                    || strpos($msg, 'already exists') !== false;
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
