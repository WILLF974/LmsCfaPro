<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();

$pdo = getDB();
$userId      = (int)$_SESSION['user_id'];
$formationId = (int)($_GET['id'] ?? 0);
if (!$formationId) { setFlash('error', 'Formation introuvable.'); redirect(url('student/formations/index.php')); }

// Vérifier l'inscription
$enrStmt = $pdo->prepare("SELECT e.*, f.title, f.description, f.thumbnail, f.duration_hours, f.start_date, f.end_date, r.rncp_code, r.title as rncp_title, r.level as rncp_level FROM enrollments e JOIN formations f ON e.formation_id=f.id LEFT JOIN rncp_titles r ON f.rncp_title_id=r.id WHERE e.user_id=? AND e.formation_id=? LIMIT 1");
$enrStmt->execute([$userId, $formationId]);
$enrollment = $enrStmt->fetch();
if (!$enrollment && isStudent()) {
    setFlash('error', 'Vous n\'êtes pas inscrit à cette formation.');
    redirect(url('student/formations/index.php'));
}
// Pour admin/enseignant sans inscription, charger la formation quand même
if (!$enrollment) {
    $fStmt = $pdo->prepare("SELECT f.*, r.rncp_code, r.title as rncp_title, r.level as rncp_level FROM formations f LEFT JOIN rncp_titles r ON f.rncp_title_id=r.id WHERE f.id=?");
    $fStmt->execute([$formationId]);
    $enrollment = $fStmt->fetch();
    if (!$enrollment) { setFlash('error', 'Formation introuvable.'); redirect(url('student/formations/index.php')); }
}

// Modules avec compétence & bloc rattachés
$modulesStmt = $pdo->prepare("
    SELECT m.*, at.code as at_code, at.title as at_title, c.code as comp_code, c.title as comp_title
    FROM modules m
    LEFT JOIN activity_types at ON m.activity_type_id = at.id
    LEFT JOIN competencies c ON m.competency_id = c.id
    WHERE m.formation_id = ?
    ORDER BY m.order_num
");
$modulesStmt->execute([$formationId]);
$modules = $modulesStmt->fetchAll();

// Capsules avec progression étudiant
$lessonsStmt = $pdo->prepare("
    SELECT l.*,
           lp.status      as progress_status,
           lp.started_at  as progress_started,
           lp.completed_at as progress_completed,
           lp.time_spent_seconds
    FROM lessons l
    LEFT JOIN lesson_progress lp ON l.id = lp.lesson_id AND lp.user_id = ?
    WHERE l.module_id IN (SELECT id FROM modules WHERE formation_id = ?)
    ORDER BY l.module_id, l.order_num
");
$lessonsStmt->execute([$userId, $formationId]);
$lessonsByModule = [];
foreach ($lessonsStmt->fetchAll() as $l) {
    $lessonsByModule[$l['module_id']][] = $l;
}

// Quiz rattachés à la formation ou aux modules, avec dernière tentative
$quizzesStmt = $pdo->prepare("
    SELECT q.*,
           qa.score        as attempt_score,
           qa.passed       as attempt_passed,
           qa.completed_at as attempt_date,
           qa.attempt_number,
           (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=q.id AND user_id=?) as total_attempts
    FROM quizzes q
    LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id AND qa.user_id = ?
        AND qa.id = (SELECT id FROM quiz_attempts WHERE quiz_id=q.id AND user_id=? ORDER BY attempt_number DESC LIMIT 1)
    WHERE q.formation_id = ?
       OR q.module_id IN (SELECT id FROM modules WHERE formation_id = ?)
    ORDER BY q.module_id, q.id
");
$quizzesStmt->execute([$userId, $userId, $userId, $formationId, $formationId]);
$quizzesByModule = [];
$standaloneQuizzes = [];
foreach ($quizzesStmt->fetchAll() as $q) {
    if ($q['module_id']) $quizzesByModule[$q['module_id']][] = $q;
    else $standaloneQuizzes[] = $q;
}

// Études de cas rattachées à la formation ou aux modules, avec statut de soumission
$standaloneCaseStudies = [];
try {
    $csStmt = $pdo->prepare("
        SELECT cs.*,
               at.code AS at_code, at.title AS at_title,
               co.code AS co_code, co.title AS co_title,
               m.title AS module_title,
               css.id           AS sub_id,
               css.status       AS sub_status,
               css.score        AS sub_score,
               css.max_score    AS sub_max_score,
               css.grade        AS sub_grade,
               css.submitted_at AS sub_submitted_at
        FROM case_studies cs
        LEFT JOIN activity_types at ON cs.activity_type_id = at.id
        LEFT JOIN competencies   co ON cs.competency_id    = co.id
        LEFT JOIN modules        m  ON cs.module_id        = m.id
        LEFT JOIN case_study_submissions css
               ON css.case_study_id = cs.id AND css.user_id = ?
        WHERE (cs.formation_id = ?
           OR  cs.module_id IN (SELECT id FROM modules WHERE formation_id = ?))
          AND cs.lesson_id IS NULL
        ORDER BY cs.module_id, cs.id
    ");
    $csStmt->execute([$userId, $formationId, $formationId]);
    $standaloneCaseStudies = $csStmt->fetchAll();
} catch (PDOException $e) {}

// Statistiques globales
$totalLessons = 0; $doneLessons = 0; $inProgressLessons = 0;
$totalXpEarned = 0; $totalXpPossible = 0;
foreach ($lessonsByModule as $mId => $lList) {
    foreach ($lList as $l) {
        $totalLessons++;
        $totalXpPossible += (int)$l['xp_reward'];
        if ($l['progress_status'] === 'completed') { $doneLessons++; $totalXpEarned += (int)$l['xp_reward']; }
        if ($l['progress_status'] === 'in_progress') $inProgressLessons++;
    }
}
$progressPct = $totalLessons > 0 ? round(($doneLessons / $totalLessons) * 100) : 0;

// Stats quiz
$totalQuizzes = 0; $passedQuizzes = 0;
foreach (array_merge($standaloneQuizzes, ...array_values($quizzesByModule ?: [[]])) as $q) {
    $totalQuizzes++;
    if ($q['attempt_passed']) $passedQuizzes++;
}

renderHead('Parcours : ' . $enrollment['title']);
renderSidebar(isStudent() ? 'student' : (isTeacher() ? 'teacher' : 'admin'));
renderTopbar($enrollment['title'], [['Mes formations', url('student/formations/index.php')], ['Parcours', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- En-tête formation -->
  <div class="page-header" style="margin-bottom:24px">
    <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap">
      <div style="flex:1;min-width:0">
        <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap">
          <?php if ($enrollment['rncp_code']): ?><span class="badge badge-primary"><?= e($enrollment['rncp_code']) ?></span><?php endif; ?>
          <?php if ($enrollment['rncp_level']): ?><span class="badge badge-secondary">Niv. <?= e($enrollment['rncp_level']) ?></span><?php endif; ?>
          <?php if (isset($enrollment['status'])): ?><?= getStatusBadge($enrollment['status']) ?><?php endif; ?>
        </div>
        <h1 style="font-size:22px;margin-bottom:4px"><?= e($enrollment['title']) ?></h1>
        <?php if ($enrollment['rncp_title']): ?><p style="color:var(--text-muted);font-size:13px"><?= e($enrollment['rncp_title']) ?></p><?php endif; ?>
      </div>
      <a href="<?= url('student/formations/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
    </div>
  </div>

  <!-- Progression globale -->
  <div class="card" style="margin-bottom:24px;border:1px solid rgba(99,102,241,.3)">
    <div class="card-body" style="padding:20px 24px">
      <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap">

        <!-- Cercle progression -->
        <div style="position:relative;width:90px;height:90px;flex-shrink:0">
          <svg width="90" height="90" viewBox="0 0 90 90">
            <circle cx="45" cy="45" r="38" fill="none" stroke="var(--bg-elevated)" stroke-width="8"/>
            <circle cx="45" cy="45" r="38" fill="none" stroke="var(--primary)" stroke-width="8"
              stroke-dasharray="<?= round(2*M_PI*38*$progressPct/100, 1) ?> 239"
              stroke-dashoffset="0" transform="rotate(-90 45 45)"
              style="transition:stroke-dasharray .5s"/>
          </svg>
          <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
            <span style="font-size:18px;font-weight:800;color:white"><?= $progressPct ?>%</span>
          </div>
        </div>

        <!-- Stats texte -->
        <div style="flex:1;display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:16px">
          <div>
            <div style="font-size:22px;font-weight:800;color:var(--success)"><?= $doneLessons ?></div>
            <div style="font-size:12px;color:var(--text-muted)">Capsules validées</div>
            <div style="font-size:11px;color:var(--text-faint)">sur <?= $totalLessons ?></div>
          </div>
          <div>
            <div style="font-size:22px;font-weight:800;color:var(--warning)"><?= $inProgressLessons ?></div>
            <div style="font-size:12px;color:var(--text-muted)">En cours</div>
          </div>
          <div>
            <div style="font-size:22px;font-weight:800;color:var(--primary-light)"><?= $passedQuizzes ?>/<?= $totalQuizzes ?></div>
            <div style="font-size:12px;color:var(--text-muted)">Quiz réussis</div>
          </div>
          <div>
            <div style="font-size:22px;font-weight:800;color:var(--warning)"><?= $totalXpEarned ?> XP</div>
            <div style="font-size:12px;color:var(--text-muted)">XP gagnés</div>
            <div style="font-size:11px;color:var(--text-faint)">sur <?= $totalXpPossible ?> possibles</div>
          </div>
        </div>

        <!-- Bouton reprendre -->
        <?php
          // Trouver la première capsule non terminée
          $nextLessonId = null;
          foreach ($lessonsByModule as $mId => $lList) {
              foreach ($lList as $l) {
                  if ($l['progress_status'] !== 'completed') { $nextLessonId = $l['id']; break 2; }
              }
          }
        ?>
        <?php if ($nextLessonId): ?>
        <a href="<?= url('student/course/view.php?id='.$nextLessonId.'&formation_id='.$formationId) ?>" class="btn btn-primary" style="flex-shrink:0;padding:12px 24px">
          <i class="fas fa-play"></i> <?= $progressPct > 0 ? 'Reprendre' : 'Commencer' ?>
        </a>
        <?php else: ?>
        <div class="btn btn-success" style="flex-shrink:0;padding:12px 24px;pointer-events:none">
          <i class="fas fa-trophy"></i> Parcours terminé !
        </div>
        <?php endif; ?>
      </div>

      <!-- Barre de progression globale -->
      <div style="margin-top:16px">
        <div class="progress-bar" style="height:10px;border-radius:999px">
          <div class="progress-fill" style="width:<?= $progressPct ?>%;border-radius:999px;transition:width .5s"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Parcours détaillé par module -->
  <div style="display:flex;flex-direction:column;gap:20px">
    <?php foreach ($modules as $i => $mod):
      $modLessons      = $lessonsByModule[$mod['id']]      ?? [];
      $modQuizzes      = $quizzesByModule[$mod['id']]      ?? [];
      $modTotal        = count($modLessons);
      $modDone     = count(array_filter($modLessons, fn($l) => $l['progress_status'] === 'completed'));
      $modPct      = $modTotal > 0 ? round(($modDone/$modTotal)*100) : 0;
      $modComplete = $modPct === 100;
    ?>
    <div class="card" style="<?= $modComplete ? 'border:1px solid rgba(16,185,129,.3)' : '' ?>">
      <!-- En-tête module -->
      <div class="card-header" style="cursor:pointer;user-select:none" onclick="toggleSection('mod-<?= $mod['id'] ?>')">
        <div style="display:flex;align-items:center;gap:14px;flex:1;min-width:0">
          <!-- Pastille numéro -->
          <div style="width:36px;height:36px;border-radius:50%;background:<?= $modComplete ? 'var(--success)' : 'var(--primary)' ?>;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:white;flex-shrink:0">
            <?= $modComplete ? '<i class="fas fa-check" style="font-size:14px"></i>' : ($i+1) ?>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($mod['title']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;display:flex;gap:12px;flex-wrap:wrap">
              <?php if ($mod['at_title']): ?><span><i class="fas fa-layer-group"></i> <?= e($mod['at_code']) ?> — <?= e($mod['at_title']) ?></span><?php endif; ?>
              <?php if ($mod['comp_title']): ?><span><i class="fas fa-bullseye"></i> <?= e($mod['comp_title']) ?></span><?php endif; ?>
              <?php if ($mod['duration_hours']): ?><span><i class="fas fa-clock"></i> <?= $mod['duration_hours'] ?>h</span><?php endif; ?>
            </div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-shrink:0">
          <div style="text-align:right">
            <div style="font-size:13px;font-weight:700;color:<?= $modComplete ? 'var(--success)' : 'var(--primary-light)' ?>"><?= $modPct ?>%</div>
            <div style="font-size:11px;color:var(--text-muted)"><?= $modDone ?>/<?= $modTotal ?></div>
          </div>
          <div style="width:44px;height:44px;position:relative">
            <svg width="44" height="44" viewBox="0 0 44 44">
              <circle cx="22" cy="22" r="17" fill="none" stroke="var(--bg-elevated)" stroke-width="4"/>
              <circle cx="22" cy="22" r="17" fill="none" stroke="<?= $modComplete ? 'var(--success)' : 'var(--primary)' ?>" stroke-width="4"
                stroke-dasharray="<?= round(2*M_PI*17*$modPct/100, 1) ?> 107"
                transform="rotate(-90 22 22)"/>
            </svg>
          </div>
          <i class="fas fa-chevron-down" id="chevron-mod-<?= $mod['id'] ?>" style="color:var(--text-muted);transition:.2s"></i>
        </div>
      </div>

      <!-- Corps module -->
      <div id="mod-<?= $mod['id'] ?>" style="display:block">
        <?php if (!empty($modLessons)): ?>
        <div style="border-top:1px solid var(--border)">
          <?php foreach ($modLessons as $j => $l):
            $status    = $l['progress_status'] ?? 'not_started';
            $isComplete = $status === 'completed';
            $isStarted  = $status === 'in_progress';
          ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--border-faint, rgba(255,255,255,.05));<?= $isComplete ? 'background:rgba(16,185,129,.04)' : ($isStarted ? 'background:rgba(99,102,241,.04)' : '') ?>">

            <!-- Icône statut -->
            <div style="width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;
              <?= $isComplete ? 'background:rgba(16,185,129,.2);color:var(--success)' : ($isStarted ? 'background:rgba(99,102,241,.2);color:var(--primary-light)' : 'background:var(--bg-elevated);color:var(--text-muted)') ?>">
              <?php if ($isComplete): ?>
                <i class="fas fa-check"></i>
              <?php elseif ($isStarted): ?>
                <i class="fas fa-spinner fa-spin"></i>
              <?php else: ?>
                <i class="<?= getContentTypeIcon($l['content_type']) ?>"></i>
              <?php endif; ?>
            </div>

            <!-- Infos capsule -->
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
                <span style="font-size:10px;color:var(--text-faint)"><?= $j+1 ?>.</span>
                <a href="<?= url('student/course/view.php?id='.$l['id'].'&formation_id='.$formationId) ?>"
                   style="font-size:14px;font-weight:<?= $isComplete ? '500' : '600' ?>;color:<?= $isComplete ? 'var(--text-muted)' : 'var(--text)' ?>;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block">
                  <?= e($l['title']) ?>
                </a>
              </div>
              <div style="display:flex;gap:10px;font-size:11px;color:var(--text-faint);flex-wrap:wrap">
                <span><i class="<?= getContentTypeIcon($l['content_type']) ?>"></i> <?= ucfirst($l['content_type']) ?></span>
                <?php if ($l['duration_minutes']): ?><span><i class="fas fa-clock"></i> <?= formatDuration($l['duration_minutes']) ?></span><?php endif; ?>
                <?php if ($l['xp_reward']): ?><span style="color:var(--warning)"><i class="fas fa-bolt"></i> +<?= $l['xp_reward'] ?> XP</span><?php endif; ?>
                <?php if (!$l['is_mandatory']): ?><span class="badge badge-secondary" style="font-size:10px">Optionnel</span><?php endif; ?>
                <?php if ($isComplete && $l['progress_completed']): ?>
                  <span style="color:var(--success)"><i class="fas fa-calendar-check"></i> Validé le <?= date('d/m/Y', strtotime($l['progress_completed'])) ?></span>
                <?php elseif ($isStarted && $l['progress_started']): ?>
                  <span><i class="fas fa-play-circle"></i> Démarré le <?= date('d/m/Y', strtotime($l['progress_started'])) ?></span>
                <?php endif; ?>
                <?php if ($isComplete && $l['time_spent_seconds'] > 0): ?>
                  <span><i class="fas fa-hourglass-half"></i> <?= formatDuration((int)($l['time_spent_seconds']/60)) ?> passées</span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Badge statut + bouton accès -->
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
              <?php if ($isComplete): ?>
                <span class="badge badge-success"><i class="fas fa-check"></i> Validé</span>
              <?php elseif ($isStarted): ?>
                <span class="badge badge-primary"><i class="fas fa-play"></i> En cours</span>
              <?php else: ?>
                <span class="badge badge-secondary"><i class="fas fa-lock-open"></i> À faire</span>
              <?php endif; ?>
              <a href="<?= url('student/course/view.php?id='.$l['id'].'&formation_id='.$formationId) ?>" class="btn btn-ghost btn-sm" title="Ouvrir" style="padding:4px 8px">
                <i class="fas fa-arrow-right"></i>
              </a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Quiz du module -->
        <?php if (!empty($modQuizzes)): ?>
        <div style="padding:0 20px 12px;border-top:1px solid var(--border)">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;padding:12px 0 8px">Quiz d'évaluation</div>
          <?php foreach ($modQuizzes as $q):
            $passed  = (bool)$q['attempt_passed'];
            $tried   = $q['total_attempts'] > 0;
            $score   = $q['attempt_score'] !== null ? round($q['attempt_score']) : null;
          ?>
          <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:var(--radius);background:<?= $passed ? 'rgba(16,185,129,.08)' : ($tried ? 'rgba(239,68,68,.06)' : 'var(--bg-elevated)') ?>;border:1px solid <?= $passed ? 'rgba(16,185,129,.25)' : ($tried ? 'rgba(239,68,68,.2)' : 'var(--border)') ?>">
            <div style="width:36px;height:36px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:18px;background:<?= $passed ? 'rgba(16,185,129,.15)' : ($tried ? 'rgba(239,68,68,.12)' : 'var(--bg-hover)') ?>">
              <?= $passed ? '✅' : ($tried ? '❌' : '📝') ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:14px;font-weight:600;color:var(--text)"><?= e($q['title']) ?></div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px;display:flex;gap:10px;flex-wrap:wrap">
                <span>Score requis : <?= $q['passing_score'] ?>%</span>
                <?php if ($score !== null): ?><span>Dernier score : <strong style="color:<?= $passed ? 'var(--success)' : 'var(--danger)' ?>"><?= $score ?>%</strong></span><?php endif; ?>
                <?php if ($q['total_attempts'] > 0): ?><span><?= $q['total_attempts'] ?>/<?= $q['max_attempts'] ?> tentative(s)</span><?php endif; ?>
                <?php if ($q['attempt_date']): ?><span><?= $passed ? 'Réussi' : 'Tenté' ?> le <?= date('d/m/Y', strtotime($q['attempt_date'])) ?></span><?php endif; ?>
                <span style="color:var(--warning)"><i class="fas fa-bolt"></i> +<?= $q['xp_reward'] ?> XP</span>
              </div>
            </div>
            <?php if ($q['total_attempts'] < $q['max_attempts'] || $passed): ?>
            <a href="<?= url('student/quiz/take.php?id='.$q['id'].'&formation_id='.$formationId) ?>" class="btn <?= $passed ? 'btn-ghost' : 'btn-primary' ?> btn-sm" style="flex-shrink:0">
              <i class="fas fa-<?= $passed ? 'redo' : 'play' ?>"></i> <?= $passed ? 'Refaire' : ($tried ? 'Réessayer' : 'Démarrer') ?>
            </a>
            <?php else: ?>
            <span class="badge badge-secondary" style="flex-shrink:0">Max tentatives</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($modLessons) && empty($modQuizzes)): ?>
        <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">Aucune capsule dans ce module.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Quiz standalone (rattachés à la formation) -->
    <?php if (!empty($standaloneQuizzes)): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fas fa-clipboard-check" style="color:var(--primary-light)"></i> Quiz de la formation</h3>
      </div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($standaloneQuizzes as $q):
          $passed = (bool)$q['attempt_passed'];
          $tried  = $q['total_attempts'] > 0;
          $score  = $q['attempt_score'] !== null ? round($q['attempt_score']) : null;
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:14px;border-radius:var(--radius);background:<?= $passed ? 'rgba(16,185,129,.08)' : ($tried ? 'rgba(239,68,68,.06)' : 'var(--bg-elevated)') ?>;border:1px solid <?= $passed ? 'rgba(16,185,129,.25)' : ($tried ? 'rgba(239,68,68,.2)' : 'var(--border)') ?>">
          <div style="font-size:28px"><?= $passed ? '🏆' : ($tried ? '🔄' : '📋') ?></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:15px;font-weight:700"><?= e($q['title']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:3px;display:flex;gap:12px;flex-wrap:wrap">
              <span>Seuil : <?= $q['passing_score'] ?>%</span>
              <?php if ($score !== null): ?><span>Score : <strong style="color:<?= $passed ? 'var(--success)' : 'var(--danger)' ?>"><?= $score ?>%</strong></span><?php endif; ?>
              <?php if ($q['time_limit_minutes']): ?><span><i class="fas fa-stopwatch"></i> <?= $q['time_limit_minutes'] ?> min</span><?php endif; ?>
              <?php if ($q['total_attempts'] > 0): ?><span><?= $q['total_attempts'] ?>/<?= $q['max_attempts'] ?> tentative(s)</span><?php endif; ?>
              <span style="color:var(--warning)"><i class="fas fa-bolt"></i> +<?= $q['xp_reward'] ?> XP</span>
            </div>
          </div>
          <?php if ($q['total_attempts'] < $q['max_attempts'] || $passed): ?>
          <a href="<?= url('student/quiz/take.php?id='.$q['id'].'&formation_id='.$formationId) ?>" class="btn <?= $passed ? 'btn-ghost' : 'btn-primary' ?>">
            <i class="fas fa-<?= $passed ? 'redo' : 'play' ?>"></i> <?= $passed ? 'Refaire' : ($tried ? 'Réessayer' : 'Démarrer le quiz') ?>
          </a>
          <?php else: ?>
          <span class="badge badge-secondary">Tentatives épuisées</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Études de cas de la formation (toutes, avec badge module si applicable) -->
    <?php
    $csTypeIcons = [
        'pdf'          => ['icon'=>'file-pdf',        'color'=>'#ef4444','label'=>'PDF'],
        'document'     => ['icon'=>'file-word',       'color'=>'#3b82f6','label'=>'Document'],
        'presentation' => ['icon'=>'file-powerpoint', 'color'=>'#f97316','label'=>'Présentation'],
        'video'        => ['icon'=>'play-circle',     'color'=>'#ef4444','label'=>'Vidéo'],
        'link'         => ['icon'=>'link',            'color'=>'#0ea5e9','label'=>'Lien'],
    ];
    ?>
    <?php if (!empty($standaloneCaseStudies)): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fas fa-folder-open" style="color:var(--primary-light)"></i> Études de cas</h3>
      </div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($standaloneCaseStudies as $cs):
          $csTi    = $csTypeIcons[$cs['file_type']] ?? $csTypeIcons['document'];
          $subSt   = $cs['sub_status'] ?? null;
          $hasGrade  = $subSt === 'graded';
          $isPending = in_array($subSt, ['submitted','under_review']);
          $notSubmit = !$subSt;
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:14px;border-radius:var(--radius);
          background:<?= $hasGrade ? 'rgba(16,185,129,.08)' : ($isPending ? 'rgba(245,158,11,.06)' : 'var(--bg-elevated)') ?>;
          border:1px solid <?= $hasGrade ? 'rgba(16,185,129,.25)' : ($isPending ? 'rgba(245,158,11,.2)' : 'var(--border)') ?>">
          <div style="width:44px;height:44px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:22px;background:<?= $csTi['color'] ?>18;flex-shrink:0">
            <i class="fas fa-<?= $csTi['icon'] ?>" style="color:<?= $csTi['color'] ?>"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:15px;font-weight:700;margin-bottom:3px"><?= e($cs['title']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);display:flex;gap:12px;flex-wrap:wrap">
              <span><?= $csTi['label'] ?></span>
              <?php if (!empty($cs['module_title'])): ?><span><i class="fas fa-cubes" style="color:#818cf8"></i> <?= e($cs['module_title']) ?></span><?php endif; ?>
              <?php if ($cs['at_code']): ?><span><i class="fas fa-layer-group" style="color:#f59e0b"></i> <?= e($cs['at_code']) ?></span><?php endif; ?>
              <?php if ($hasGrade): ?>
                <span style="color:var(--success)"><i class="fas fa-check-circle"></i> Corrigé<?php if ($cs['sub_score'] !== null): ?> · <?= $cs['sub_score'] ?>/<?= $cs['sub_max_score'] ?><?php endif; ?><?php if ($cs['sub_grade']): ?> · <?= e($cs['sub_grade']) ?><?php endif; ?></span>
              <?php elseif ($isPending): ?>
                <span style="color:var(--warning)"><i class="fas fa-clock"></i> En attente de correction</span>
              <?php endif; ?>
            </div>
          </div>
          <a href="<?= url('student/case_studies/view.php?id='.$cs['id']) ?>"
             class="btn <?= $notSubmit ? 'btn-primary' : ($hasGrade ? 'btn-ghost' : 'btn-secondary') ?>">
            <i class="fas fa-<?= $hasGrade ? 'eye' : 'arrow-right' ?>"></i>
            <?= $hasGrade ? 'Voir correction' : ($isPending ? 'Voir / Soumettre' : 'Ouvrir l\'étude de cas') ?>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
function toggleSection(id) {
  const el = document.getElementById(id);
  const chevron = document.getElementById('chevron-' + id);
  if (!el) return;
  const open = el.style.display !== 'none';
  el.style.display = open ? 'none' : 'block';
  if (chevron) chevron.style.transform = open ? 'rotate(-90deg)' : '';
}
</script>
<?php renderFooter(); ?>
