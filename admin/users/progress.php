<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireAdmin();

$pdo    = getDB();
$userId = (int)($_GET['id'] ?? 0);
if (!$userId) { setFlash('error', 'Utilisateur introuvable.'); redirect(url('admin/users/index.php')); }

// Charger l'étudiant
$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$student = $userStmt->fetch();
if (!$student) { setFlash('error', 'Utilisateur introuvable.'); redirect(url('admin/users/index.php')); }

// ── Actions POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action     = $_POST['action'] ?? '';
    $formationId = (int)($_POST['formation_id'] ?? 0);

    if ($action === 'reset_formation' && $formationId) {
        // IDs des leçons de cette formation
        $lessonIds = $pdo->prepare("SELECT l.id FROM lessons l JOIN modules m ON l.module_id=m.id WHERE m.formation_id=?");
        $lessonIds->execute([$formationId]);
        $lessonIds = array_column($lessonIds->fetchAll(), 'id');

        // IDs des quiz de cette formation
        $quizIds = $pdo->prepare("SELECT id FROM quizzes WHERE formation_id=? OR module_id IN (SELECT id FROM modules WHERE formation_id=?)");
        $quizIds->execute([$formationId, $formationId]);
        $quizIds = array_column($quizIds->fetchAll(), 'id');

        // Progression capsules
        $pdo->prepare("DELETE lp FROM lesson_progress lp JOIN lessons l ON lp.lesson_id=l.id JOIN modules m ON l.module_id=m.id WHERE m.formation_id=? AND lp.user_id=?")->execute([$formationId, $userId]);

        // Réponses + tentatives quiz
        $pdo->prepare("DELETE qaa FROM quiz_attempt_answers qaa JOIN quiz_attempts qa ON qaa.attempt_id=qa.id JOIN quizzes q ON qa.quiz_id=q.id WHERE (q.formation_id=? OR q.module_id IN (SELECT id FROM modules WHERE formation_id=?)) AND qa.user_id=?")->execute([$formationId, $formationId, $userId]);
        $pdo->prepare("DELETE qa FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id=q.id WHERE (q.formation_id=? OR q.module_id IN (SELECT id FROM modules WHERE formation_id=?)) AND qa.user_id=?")->execute([$formationId, $formationId, $userId]);

        // XP lié à cette formation (capsules + quiz + formation)
        $xpToRemove = 0;
        if (!empty($lessonIds)) {
            $ph = implode(',', array_fill(0, count($lessonIds), '?'));
            $xpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM xp_transactions WHERE user_id=? AND reference_type='lesson' AND reference_id IN ($ph)");
            $xpStmt->execute(array_merge([$userId], $lessonIds));
            $xpToRemove += (int)$xpStmt->fetchColumn();
            $pdo->prepare("DELETE FROM xp_transactions WHERE user_id=? AND reference_type='lesson' AND reference_id IN ($ph)")->execute(array_merge([$userId], $lessonIds));
        }
        if (!empty($quizIds)) {
            $ph = implode(',', array_fill(0, count($quizIds), '?'));
            $xpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM xp_transactions WHERE user_id=? AND reference_type='quiz' AND reference_id IN ($ph)");
            $xpStmt->execute(array_merge([$userId], $quizIds));
            $xpToRemove += (int)$xpStmt->fetchColumn();
            $pdo->prepare("DELETE FROM xp_transactions WHERE user_id=? AND reference_type='quiz' AND reference_id IN ($ph)")->execute(array_merge([$userId], $quizIds));
        }
        // XP formation
        $xpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM xp_transactions WHERE user_id=? AND reference_type='formation' AND reference_id=?");
        $xpStmt->execute([$userId, $formationId]);
        $xpToRemove += (int)$xpStmt->fetchColumn();
        $pdo->prepare("DELETE FROM xp_transactions WHERE user_id=? AND reference_type='formation' AND reference_id=?")->execute([$userId, $formationId]);

        // XP badges liés à cette formation (badges dont l'XP est tracé)
        // On retire aussi les badges obtenus grâce à cette formation uniquement si liés
        $badgeXpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM xp_transactions WHERE user_id=? AND reference_type='badge' AND reference_id IN (SELECT badge_id FROM user_badges WHERE user_id=? AND context LIKE ?)");
        $badgeXpStmt->execute([$userId, $userId, '%formation:'.$formationId.'%']);
        $xpToRemove += (int)$badgeXpStmt->fetchColumn();

        $pdo->prepare("DELETE FROM xp_transactions WHERE user_id=? AND reference_type='badge' AND reference_id IN (SELECT badge_id FROM user_badges WHERE user_id=? AND context LIKE ?)")->execute([$userId, $userId, '%formation:'.$formationId.'%']);
        $pdo->prepare("DELETE FROM user_badges WHERE user_id=? AND context LIKE ?")->execute([$userId, '%formation:'.$formationId.'%']);

        // Déduire l'XP et recalculer le niveau
        if ($xpToRemove > 0) {
            $newXp = max(0, $student['xp_points'] - $xpToRemove);
            $newLevel = max(1, floor($newXp / 100) + 1);
            $pdo->prepare("UPDATE users SET xp_points=?, level=? WHERE id=?")->execute([$newXp, $newLevel, $userId]);
        }

        // Remettre l'enrollment à 0
        $pdo->prepare("UPDATE enrollments SET progress_percent=0, status='active' WHERE user_id=? AND formation_id=?")->execute([$userId, $formationId]);

        auditLog('student_progress_reset', 'enrollment', $formationId);
        setFlash('success', 'Parcours réinitialisé (progression, quiz, XP et badges liés supprimés).');
        redirect(url("admin/users/progress.php?id=$userId"));
    }

    if ($action === 'reset_all') {
        // Réponses quiz
        $pdo->prepare("DELETE qaa FROM quiz_attempt_answers qaa JOIN quiz_attempts qa ON qaa.attempt_id=qa.id WHERE qa.user_id=?")->execute([$userId]);
        // Tentatives quiz
        $pdo->prepare("DELETE FROM quiz_attempts WHERE user_id=?")->execute([$userId]);
        // Progression capsules
        $pdo->prepare("DELETE FROM lesson_progress WHERE user_id=?")->execute([$userId]);
        // Enrollments remis à 0
        $pdo->prepare("UPDATE enrollments SET progress_percent=0, status='active' WHERE user_id=?")->execute([$userId]);
        // Toutes les transactions XP
        $pdo->prepare("DELETE FROM xp_transactions WHERE user_id=?")->execute([$userId]);
        // Tous les badges
        $pdo->prepare("DELETE FROM user_badges WHERE user_id=?")->execute([$userId]);
        // XP et niveau remis à 0
        $pdo->prepare("UPDATE users SET xp_points=0, level=1 WHERE id=?")->execute([$userId]);

        auditLog('student_all_progress_reset', 'user', $userId);
        setFlash('success', 'Tout le parcours a été réinitialisé (progression, quiz, XP et badges supprimés).');
        redirect(url("admin/users/progress.php?id=$userId"));
    }
}

// ── Inscriptions + formations ────────────────────────────────
$enrStmt = $pdo->prepare("
    SELECT e.*, f.id as f_id, f.title as f_title, f.duration_hours,
           r.rncp_code, r.title as rncp_title, r.level as rncp_level,
           (SELECT COUNT(*) FROM modules WHERE formation_id=f.id) as total_modules,
           (SELECT COUNT(*) FROM lessons l JOIN modules m ON l.module_id=m.id WHERE m.formation_id=f.id AND l.is_mandatory=1) as total_lessons
    FROM enrollments e
    JOIN formations f ON e.formation_id=f.id
    LEFT JOIN rncp_titles r ON f.rncp_title_id=r.id
    WHERE e.user_id=?
    ORDER BY e.enrolled_at DESC
");
$enrStmt->execute([$userId]);
$enrollments = $enrStmt->fetchAll();

// ── Stats globales ───────────────────────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
      (SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status='active')          as active_formations,
      (SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status='completed')        as done_formations,
      (SELECT COUNT(*) FROM lesson_progress WHERE user_id=? AND status='completed')   as done_lessons,
      (SELECT COUNT(*) FROM lesson_progress WHERE user_id=? AND status='in_progress') as in_progress_lessons,
      (SELECT COUNT(*) FROM quiz_attempts WHERE user_id=? AND passed=1)               as passed_quizzes,
      (SELECT COUNT(*) FROM quiz_attempts WHERE user_id=? AND status='completed')     as total_quiz_attempts,
      (SELECT SUM(time_spent_seconds) FROM lesson_progress WHERE user_id=?)           as total_time_sec,
      (SELECT SUM(time_spent_seconds) FROM quiz_attempts WHERE user_id=? AND status='completed') as quiz_time_sec
");
$statsStmt->execute(array_fill(0, 8, $userId));
$stats = $statsStmt->fetch();

// ── Pour chaque formation : modules + leçons + quiz ─────────
$formationsData = [];
foreach ($enrollments as $enr) {
    $fId = $enr['f_id'];

    // Modules avec activité type & compétence
    $modStmt = $pdo->prepare("
        SELECT m.*, at.code as at_code, at.title as at_title,
               c.code as comp_code, c.title as comp_title
        FROM modules m
        LEFT JOIN activity_types at ON m.activity_type_id=at.id
        LEFT JOIN competencies c ON m.competency_id=c.id
        WHERE m.formation_id=? ORDER BY m.order_num
    ");
    $modStmt->execute([$fId]);
    $modules = $modStmt->fetchAll();

    // Leçons + progression par module
    $lesStmt = $pdo->prepare("
        SELECT l.id, l.title, l.content_type, l.duration_minutes, l.xp_reward, l.is_mandatory, l.module_id,
               lp.status as prog_status, lp.started_at, lp.completed_at, lp.time_spent_seconds
        FROM lessons l
        LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.user_id=?
        WHERE l.module_id IN (SELECT id FROM modules WHERE formation_id=?)
        ORDER BY l.module_id, l.order_num
    ");
    $lesStmt->execute([$userId, $fId]);
    $lessonsByMod = [];
    foreach ($lesStmt->fetchAll() as $l) {
        $lessonsByMod[$l['module_id']][] = $l;
    }

    // Quiz de la formation (formation-level + module-level)
    $qzStmt = $pdo->prepare("
        SELECT q.id, q.title, q.quiz_type, q.passing_score, q.max_attempts, q.xp_reward,
               q.module_id, q.formation_id, q.activity_type_id, q.competency_id,
               at.code as at_code, at.title as at_title,
               c.code as comp_code, c.title as comp_title,
               (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=q.id AND user_id=? AND status='completed') as used_attempts,
               (SELECT MAX(score) FROM quiz_attempts WHERE quiz_id=q.id AND user_id=? AND status='completed') as best_score,
               (SELECT passed FROM quiz_attempts WHERE quiz_id=q.id AND user_id=? AND passed=1 LIMIT 1) as is_passed,
               (SELECT completed_at FROM quiz_attempts WHERE quiz_id=q.id AND user_id=? ORDER BY id DESC LIMIT 1) as last_attempt_date
        FROM quizzes q
        LEFT JOIN activity_types at ON q.activity_type_id=at.id
        LEFT JOIN competencies c ON q.competency_id=c.id
        WHERE q.formation_id=? OR q.module_id IN (SELECT id FROM modules WHERE formation_id=?)
        ORDER BY q.module_id, q.id
    ");
    $qzStmt->execute([$userId, $userId, $userId, $userId, $fId, $fId]);
    $quizzesByMod = []; $standaloneQuizzes = [];
    foreach ($qzStmt->fetchAll() as $qz) {
        if ($qz['module_id']) $quizzesByMod[$qz['module_id']][] = $qz;
        else $standaloneQuizzes[] = $qz;
    }

    // Blocs RNCP + compétences de la formation
    $blocsStmt = $pdo->prepare("
        SELECT at.id, at.code, at.title,
               (SELECT COUNT(*) FROM competencies WHERE activity_type_id=at.id) as comp_count
        FROM activity_types at
        JOIN formations f ON at.rncp_title_id=f.rncp_title_id
        WHERE f.id=? ORDER BY at.order_num
    ");
    $blocsStmt->execute([$fId]);
    $blocs = $blocsStmt->fetchAll();

    $compsStmt = $pdo->prepare("
        SELECT c.*, at.code as at_code
        FROM competencies c
        JOIN activity_types at ON c.activity_type_id=at.id
        WHERE at.id IN (SELECT id FROM activity_types WHERE rncp_title_id=(SELECT rncp_title_id FROM formations WHERE id=?))
        ORDER BY at.order_num, c.order_num
    ");
    $compsStmt->execute([$fId]);
    $compsByBloc = [];
    foreach ($compsStmt->fetchAll() as $c) {
        $compsByBloc[$c['activity_type_id']][] = $c;
    }

    // Stats par module
    $modStats = [];
    foreach ($modules as $mod) {
        $mLessons = $lessonsByMod[$mod['id']] ?? [];
        $total    = count($mLessons);
        $done     = count(array_filter($mLessons, fn($l) => $l['prog_status'] === 'completed'));
        $modStats[$mod['id']] = ['total'=>$total,'done'=>$done,'pct'=>$total>0?round($done/$total*100):0];
    }

    $formationsData[$fId] = compact('modules','lessonsByMod','quizzesByMod','standaloneQuizzes','blocs','compsByBloc','modStats');
}

// ── Render ───────────────────────────────────────────────────
$name = e($student['first_name'] . ' ' . $student['last_name']);
renderHead('Suivi pédagogique — ' . $name);
renderSidebar('admin');
renderTopbar('Suivi pédagogique', [
    ['Utilisateurs', url('admin/users/index.php')],
    [$name, url('admin/users/edit.php?id='.$userId)],
    ['Progression', '']
]);
?>
<div class="page-content fade-in">

  <!-- En-tête étudiant -->
  <div class="card" style="margin-bottom:24px">
    <div class="card-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;padding:20px 24px">
      <div class="avatar" style="width:64px;height:64px;font-size:22px;flex-shrink:0;background:<?= getAvatarColor($student['first_name'].$student['last_name']) ?>">
        <?php if ($student['avatar'] && file_exists(UPLOADS_PATH.'/'.$student['avatar'])): ?>
          <img src="<?= e(uploadUrl($student['avatar'])) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%">
        <?php else: ?>
          <?= getAvatarInitials($student['first_name'], $student['last_name']) ?>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;gap:8px;margin-bottom:6px;flex-wrap:wrap">
          <?= getStatusBadge($student['status']) ?>
          <span class="badge badge-secondary"><?= ucfirst($student['role']) ?></span>
          <span class="badge badge-warning"><i class="fas fa-bolt"></i> <?= number_format($student['xp_points']) ?> XP · Niv.<?= $student['level'] ?></span>
        </div>
        <h1 style="font-size:20px;margin-bottom:2px"><?= $name ?></h1>
        <div style="font-size:13px;color:var(--text-muted)"><?= e($student['email']) ?><?= $student['phone'] ? ' · '.e($student['phone']) : '' ?></div>
      </div>
      <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap">
        <a href="<?= url('admin/users/edit.php?id='.$userId) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> Modifier</a>
        <?php if (!empty($enrollments)): ?>
        <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger);border-color:rgba(239,68,68,.3)"
          onclick="document.getElementById('modal-reset-all').style.display='flex'">
          <i class="fas fa-redo"></i> Réinitialiser tout
        </button>
        <?php endif; ?>
        <a href="<?= url('admin/users/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
      </div>
    </div>
  </div>

  <!-- KPIs globaux -->
  <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px">
    <?php
    $totalTimeSec = ($stats['total_time_sec'] ?? 0) + ($stats['quiz_time_sec'] ?? 0);
    $timeLabel = $totalTimeSec > 3600 ? round($totalTimeSec/3600,1).'h' : round($totalTimeSec/60).' min';
    $kpis = [
      ['label'=>'Formations actives',  'val'=>$stats['active_formations'],   'color'=>'var(--primary-light)', 'icon'=>'book-open'],
      ['label'=>'Formations terminées','val'=>$stats['done_formations'],      'color'=>'var(--success)',       'icon'=>'graduation-cap'],
      ['label'=>'Capsules validées',   'val'=>$stats['done_lessons'],         'color'=>'var(--info)',          'icon'=>'check-circle'],
      ['label'=>'En cours',            'val'=>$stats['in_progress_lessons'],  'color'=>'var(--warning)',       'icon'=>'spinner'],
      ['label'=>'Quiz réussis',        'val'=>$stats['passed_quizzes'].'/'.$stats['total_quiz_attempts'], 'color'=>'var(--success)', 'icon'=>'clipboard-check'],
      ['label'=>'Temps de formation',  'val'=>$timeLabel,                     'color'=>'var(--text-muted)',    'icon'=>'clock'],
    ];
    foreach ($kpis as $k): ?>
    <div class="card">
      <div class="card-body" style="padding:14px;text-align:center">
        <i class="fas fa-<?= $k['icon'] ?>" style="color:<?= $k['color'] ?>;font-size:18px;margin-bottom:6px;display:block"></i>
        <div style="font-size:20px;font-weight:800;color:white"><?= $k['val'] ?></div>
        <div style="font-size:11px;color:var(--text-muted);line-height:1.3"><?= $k['label'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (empty($enrollments)): ?>
  <div class="empty-state">
    <div class="icon">📚</div>
    <h3>Aucune inscription</h3>
    <p>Cet étudiant n'est inscrit à aucune formation.</p>
  </div>
  <?php endif; ?>

  <?php foreach ($enrollments as $enr):
    $fId   = $enr['f_id'];
    $fData = $formationsData[$fId];
    $prog  = $enr['progress_percent'];
    $isDone = $enr['status'] === 'completed';
  ?>
  <!-- ═══════════ FORMATION ═══════════ -->
  <div class="card" style="margin-bottom:28px">

    <!-- Header formation -->
    <div class="card-header" style="background:rgba(99,102,241,.07);padding:16px 20px">
      <div style="display:flex;align-items:flex-start;gap:16px;flex:1;min-width:0">
        <div style="flex:1">
          <div style="display:flex;gap:8px;margin-bottom:6px;flex-wrap:wrap">
            <?php if ($enr['rncp_code']): ?><span class="badge badge-primary"><?= e($enr['rncp_code']) ?></span><?php endif; ?>
            <?php if ($enr['rncp_level']): ?><span class="badge badge-secondary">Niv.<?= e($enr['rncp_level']) ?></span><?php endif; ?>
            <?= getStatusBadge($enr['status']) ?>
          </div>
          <div style="font-size:17px;font-weight:800;color:white"><?= e($enr['f_title']) ?></div>
          <?php if ($enr['rncp_title']): ?><div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= e($enr['rncp_title']) ?></div><?php endif; ?>
        </div>
        <div style="flex-shrink:0;text-align:right;min-width:120px">
          <div style="font-size:26px;font-weight:900;color:<?= $isDone?'var(--success)':'var(--primary-light)' ?>"><?= $prog ?>%</div>
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:8px">Inscrit le <?= formatDate($enr['enrolled_at']) ?></div>
          <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger);border-color:rgba(239,68,68,.3);font-size:11px"
            onclick="document.getElementById('modal-reset-<?= $fId ?>').style.display='flex'">
            <i class="fas fa-redo"></i> Réinitialiser
          </button>
        </div>
      </div>
    </div>

    <!-- Barre progression -->
    <div style="height:6px;background:var(--bg-elevated)">
      <div style="height:100%;width:<?= $prog ?>%;background:<?= $isDone?'var(--success)':'var(--primary)' ?>;transition:width .4s"></div>
    </div>

    <div style="padding:20px;display:flex;flex-direction:column;gap:24px">

      <!-- ── BLOCS RNCP ── -->
      <?php if (!empty($fData['blocs'])): ?>
      <div>
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:10px"><i class="fas fa-layer-group" style="margin-right:6px"></i>Blocs RNCP & Compétences</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
          <?php foreach ($fData['blocs'] as $bloc):
            $bComps = $fData['compsByBloc'][$bloc['id']] ?? [];
            // Calculer les modules rattachés à ce bloc et leur progression
            $blocModules = array_filter($fData['modules'], fn($m) => $m['activity_type_id'] == $bloc['id']);
            $blocDone = 0; $blocTotal = 0;
            foreach ($blocModules as $bm) {
              $s = $fData['modStats'][$bm['id']] ?? ['done'=>0,'total'=>0];
              $blocDone += $s['done']; $blocTotal += $s['total'];
            }
            $blocPct = $blocTotal > 0 ? round($blocDone/$blocTotal*100) : 0;
          ?>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:14px;border:1px solid var(--border)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
              <div>
                <span class="badge badge-primary" style="font-size:10px"><?= e($bloc['code']) ?></span>
                <div style="font-size:13px;font-weight:700;margin-top:4px;color:white"><?= e(mb_substr($bloc['title'],0,45)) ?><?= strlen($bloc['title'])>45?'…':'' ?></div>
              </div>
              <div style="font-size:16px;font-weight:800;color:<?= $blocPct===100?'var(--success)':'var(--primary-light)' ?>;flex-shrink:0;margin-left:8px"><?= $blocTotal>0?$blocPct.'%':'—' ?></div>
            </div>
            <?php if ($blocTotal > 0): ?>
            <div style="height:4px;background:var(--bg-hover);border-radius:99px;margin-bottom:8px">
              <div style="height:100%;width:<?= $blocPct ?>%;background:<?= $blocPct===100?'var(--success)':'var(--primary)' ?>;border-radius:99px"></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($bComps)): ?>
            <div style="display:flex;flex-wrap:wrap;gap:4px">
              <?php foreach ($bComps as $bc): ?>
              <span style="font-size:10px;padding:2px 8px;background:rgba(99,102,241,.12);border-radius:99px;color:var(--primary-light);border:1px solid rgba(99,102,241,.2)"><?= e($bc['code']) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── MODULES + CAPSULES + QUIZ ── -->
      <div>
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:10px"><i class="fas fa-cubes" style="margin-right:6px"></i>Modules & Capsules</div>

        <?php if (empty($fData['modules'])): ?>
        <div style="color:var(--text-faint);font-size:13px;padding:16px">Aucun module dans cette formation.</div>
        <?php endif; ?>

        <?php foreach ($fData['modules'] as $i => $mod):
          $mLessons = $fData['lessonsByMod'][$mod['id']] ?? [];
          $mQuizzes = $fData['quizzesByMod'][$mod['id']] ?? [];
          $mStats   = $fData['modStats'][$mod['id']];
          $mDone    = $mStats['pct'] === 100;
        ?>
        <div style="margin-bottom:12px;border:1px solid <?= $mDone?'rgba(16,185,129,.3)':'var(--border)' ?>;border-radius:var(--radius-lg);overflow:hidden">

          <!-- En-tête module -->
          <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:<?= $mDone?'rgba(16,185,129,.06)':'var(--bg-elevated)' ?>;cursor:pointer" onclick="toggleSection('mod-<?= $fId ?>-<?= $mod['id'] ?>')">
            <div style="width:30px;height:30px;border-radius:50%;background:<?= $mDone?'var(--success)':'var(--primary)' ?>;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0">
              <?= $mDone ? '<i class="fas fa-check" style="font-size:11px"></i>' : ($i+1) ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:14px;font-weight:700"><?= e($mod['title']) ?></div>
              <div style="font-size:11px;color:var(--text-muted);display:flex;gap:10px;flex-wrap:wrap;margin-top:2px">
                <?php if ($mod['at_title']): ?><span><i class="fas fa-layer-group"></i> <?= e($mod['at_code']) ?> — <?= e(mb_substr($mod['at_title'],0,35)) ?></span><?php endif; ?>
                <?php if ($mod['comp_title']): ?><span><i class="fas fa-bullseye"></i> <?= e($mod['comp_code']) ?> <?= e(mb_substr($mod['comp_title'],0,30)) ?></span><?php endif; ?>
              </div>
            </div>
            <div style="flex-shrink:0;display:flex;align-items:center;gap:10px">
              <div style="text-align:right">
                <div style="font-size:14px;font-weight:800;color:<?= $mDone?'var(--success)':'var(--primary-light)' ?>"><?= $mStats['pct'] ?>%</div>
                <div style="font-size:10px;color:var(--text-faint)"><?= $mStats['done'] ?>/<?= $mStats['total'] ?> capsules</div>
              </div>
              <i class="fas fa-chevron-down" id="chev-<?= $fId ?>-<?= $mod['id'] ?>" style="color:var(--text-muted);transition:.2s"></i>
            </div>
          </div>

          <!-- Corps module -->
          <div id="mod-<?= $fId ?>-<?= $mod['id'] ?>">

            <?php if (!empty($mLessons)): ?>
            <!-- Table capsules -->
            <div style="overflow-x:auto;border-top:1px solid var(--border)">
              <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                  <tr style="background:var(--bg-surface)">
                    <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">#</th>
                    <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Capsule</th>
                    <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Type</th>
                    <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Durée</th>
                    <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Statut</th>
                    <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Date validation</th>
                    <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">XP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($mLessons as $j => $l):
                    $st = $l['prog_status'] ?? 'not_started';
                  ?>
                  <tr style="border-top:1px solid var(--border-faint, rgba(255,255,255,.04));background:<?= $st==='completed'?'rgba(16,185,129,.03)':($st==='in_progress'?'rgba(99,102,241,.03)':'') ?>">
                    <td style="padding:9px 14px;color:var(--text-faint)"><?= $j+1 ?></td>
                    <td style="padding:9px 14px">
                      <div style="display:flex;align-items:center;gap:8px">
                        <i class="<?= getContentTypeIcon($l['content_type']) ?>" style="color:var(--text-muted);width:14px;flex-shrink:0"></i>
                        <span style="font-weight:<?= $st==='completed'?'500':'600' ?>;color:<?= $st==='completed'?'var(--text-muted)':'var(--text)' ?>"><?= e($l['title']) ?></span>
                        <?php if (!$l['is_mandatory']): ?><span class="badge badge-secondary" style="font-size:10px">Opt.</span><?php endif; ?>
                      </div>
                    </td>
                    <td style="padding:9px 14px;text-align:center"><span class="badge badge-secondary" style="font-size:10px"><?= ucfirst($l['content_type']) ?></span></td>
                    <td style="padding:9px 14px;text-align:center;color:var(--text-muted)"><?= $l['duration_minutes'] ? formatDuration($l['duration_minutes']) : '—' ?></td>
                    <td style="padding:9px 14px;text-align:center">
                      <?php if ($st==='completed'): ?>
                        <span class="badge badge-success"><i class="fas fa-check"></i> Validé</span>
                      <?php elseif ($st==='in_progress'): ?>
                        <span class="badge badge-primary"><i class="fas fa-play"></i> En cours</span>
                      <?php else: ?>
                        <span class="badge badge-secondary">À faire</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:9px 14px;text-align:center;color:var(--text-muted)">
                      <?= $l['completed_at'] ? '<span style="color:var(--success)">'.formatDate($l['completed_at']).'</span>' : ($l['started_at'] ? formatDate($l['started_at']) : '—') ?>
                    </td>
                    <td style="padding:9px 14px;text-align:center">
                      <?php if ($st==='completed'): ?><span style="color:var(--warning);font-weight:700">+<?= $l['xp_reward'] ?></span><?php else: ?><span style="color:var(--text-faint)"><?= $l['xp_reward'] ?></span><?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

            <!-- Quiz du module -->
            <?php if (!empty($mQuizzes)): ?>
            <div style="border-top:1px solid var(--border);padding:12px 16px;background:rgba(99,102,241,.03)">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--primary-light);margin-bottom:8px"><i class="fas fa-clipboard-check"></i> Quiz du module</div>
              <?php foreach ($mQuizzes as $qz): ?>
              <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:var(--radius);background:<?= $qz['is_passed']?'rgba(16,185,129,.08)':($qz['used_attempts']>0?'rgba(239,68,68,.06)':'var(--bg-elevated)') ?>;border:1px solid <?= $qz['is_passed']?'rgba(16,185,129,.25)':($qz['used_attempts']>0?'rgba(239,68,68,.2)':'var(--border)') ?>;margin-bottom:6px">
                <div style="font-size:20px"><?= $qz['is_passed'] ? '✅' : ($qz['used_attempts'] > 0 ? '❌' : '📝') ?></div>
                <div style="flex:1;min-width:0">
                  <div style="font-size:13px;font-weight:600"><?= e($qz['title']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);display:flex;gap:10px;flex-wrap:wrap;margin-top:2px">
                    <span>Seuil : <?= $qz['passing_score'] ?>%</span>
                    <?php if ($qz['best_score'] !== null): ?><span>Meilleur score : <strong style="color:<?= $qz['is_passed']?'var(--success)':'var(--danger)' ?>"><?= round($qz['best_score']) ?>%</strong></span><?php endif; ?>
                    <span><?= $qz['used_attempts'] ?>/<?= $qz['max_attempts'] ?> tentative(s)</span>
                    <?php if ($qz['last_attempt_date']): ?><span><?= formatDate($qz['last_attempt_date']) ?></span><?php endif; ?>
                    <?php if ($qz['at_title']): ?><span><i class="fas fa-layer-group"></i> <?= e($qz['at_code']) ?></span><?php endif; ?>
                    <?php if ($qz['comp_title']): ?><span><i class="fas fa-bullseye"></i> <?= e($qz['comp_code']) ?></span><?php endif; ?>
                  </div>
                </div>
                <div style="flex-shrink:0">
                  <?php if ($qz['used_attempts'] === 0): ?>
                    <span class="badge badge-secondary">Non démarré</span>
                  <?php elseif ($qz['is_passed']): ?>
                    <span class="badge badge-success"><i class="fas fa-check"></i> Réussi</span>
                  <?php elseif ($qz['used_attempts'] >= $qz['max_attempts']): ?>
                    <span class="badge badge-danger">Épuisé</span>
                  <?php else: ?>
                    <span class="badge badge-warning">En cours (<?= $qz['max_attempts']-$qz['used_attempts'] ?> restante(s))</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (empty($mLessons) && empty($mQuizzes)): ?>
            <div style="padding:16px;text-align:center;color:var(--text-faint);font-size:13px;border-top:1px solid var(--border)">Aucune capsule dans ce module.</div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- ── Quiz de formation (standalone) ── -->
      <?php if (!empty($fData['standaloneQuizzes'])): ?>
      <div>
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:10px"><i class="fas fa-file-alt" style="margin-right:6px"></i>Quiz de la formation</div>
        <?php foreach ($fData['standaloneQuizzes'] as $qz): ?>
        <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:var(--radius-lg);background:<?= $qz['is_passed']?'rgba(16,185,129,.08)':($qz['used_attempts']>0?'rgba(239,68,68,.06)':'var(--bg-elevated)') ?>;border:1px solid <?= $qz['is_passed']?'rgba(16,185,129,.3)':($qz['used_attempts']>0?'rgba(239,68,68,.2)':'var(--border)') ?>;margin-bottom:8px">
          <div style="font-size:28px"><?= $qz['is_passed'] ? '🏆' : ($qz['used_attempts'] > 0 ? '🔄' : '📋') ?></div>
          <div style="flex:1">
            <div style="font-size:15px;font-weight:700"><?= e($qz['title']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);display:flex;gap:12px;flex-wrap:wrap;margin-top:3px">
              <span>Seuil : <?= $qz['passing_score'] ?>%</span>
              <?php if ($qz['best_score'] !== null): ?><span>Meilleur : <strong style="color:<?= $qz['is_passed']?'var(--success)':'var(--danger)' ?>"><?= round($qz['best_score']) ?>%</strong></span><?php endif; ?>
              <span><?= $qz['used_attempts'] ?>/<?= $qz['max_attempts'] ?> tentative(s)</span>
              <?php if ($qz['last_attempt_date']): ?><span>Dernier : <?= formatDate($qz['last_attempt_date']) ?></span><?php endif; ?>
              <span style="color:var(--warning)"><i class="fas fa-bolt"></i> <?= $qz['xp_reward'] ?> XP</span>
            </div>
          </div>
          <?php if ($qz['used_attempts'] === 0): ?>
            <span class="badge badge-secondary">Non démarré</span>
          <?php elseif ($qz['is_passed']): ?>
            <span class="badge badge-success"><i class="fas fa-trophy"></i> Réussi</span>
          <?php elseif ($qz['used_attempts'] >= $qz['max_attempts']): ?>
            <span class="badge badge-danger">Tentatives épuisées</span>
          <?php else: ?>
            <span class="badge badge-warning">Échoué — <?= $qz['max_attempts']-$qz['used_attempts'] ?> restante(s)</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div><!-- /padding formation -->
  </div><!-- /card formation -->
  <?php endforeach; ?>

</div>

<!-- Modal : réinitialiser tout -->
<?php if (!empty($enrollments)): ?>
<div id="modal-reset-all" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:460px;max-width:95vw">
    <div class="card-header">
      <h3 class="card-title" style="color:var(--danger)"><i class="fas fa-exclamation-triangle"></i> Réinitialiser tout le parcours</h3>
      <button onclick="document.getElementById('modal-reset-all').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <p style="color:var(--text-muted);margin-bottom:12px">
        Cette action va <strong style="color:var(--danger)">tout supprimer</strong> pour <strong><?= $name ?></strong> :
      </p>
      <ul style="font-size:13px;color:var(--text-muted);margin:0 0 16px 18px;line-height:1.8">
        <li>Progressions de capsules (toutes formations)</li>
        <li>Tentatives de quiz et réponses</li>
        <li>Tous les points XP (remis à 0)</li>
        <li>Tous les badges obtenus</li>
        <li>Niveau remis à 1</li>
      </ul>
      <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:var(--radius);padding:10px 14px;font-size:13px;color:var(--danger);margin-bottom:20px">
        <i class="fas fa-exclamation-circle"></i> Action irréversible — à utiliser uniquement en test/debug.
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-reset-all').style.display='none'">Annuler</button>
        <form method="POST" style="margin:0">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reset_all">
          <button type="submit" class="btn" style="background:var(--danger);color:white"><i class="fas fa-redo"></i> Tout réinitialiser</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modals : réinitialiser par formation -->
<?php foreach ($enrollments as $enr): ?>
<div id="modal-reset-<?= $enr['f_id'] ?>" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:460px;max-width:95vw">
    <div class="card-header">
      <h3 class="card-title" style="color:var(--danger)"><i class="fas fa-redo"></i> Réinitialiser le parcours</h3>
      <button onclick="document.getElementById('modal-reset-<?= $enr['f_id'] ?>').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <p style="color:var(--text-muted);margin-bottom:12px">
        Réinitialiser la progression de <strong><?= $name ?></strong> sur la formation :
      </p>
      <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:12px 16px;margin-bottom:16px;font-weight:700">
        <?= e($enr['f_title']) ?>
      </div>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:8px">Seront supprimés :</p>
      <ul style="font-size:13px;color:var(--text-muted);margin:0 0 16px 18px;line-height:1.8">
        <li>Toutes les progressions de capsules</li>
        <li>Toutes les tentatives de quiz et leurs réponses</li>
        <li>Les points XP gagnés sur cette formation</li>
        <li>Les badges obtenus via cette formation</li>
        <li>Le pourcentage de progression (remis à 0)</li>
      </ul>
      <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:var(--radius);padding:10px 14px;font-size:13px;color:var(--danger);margin-bottom:20px">
        <i class="fas fa-exclamation-circle"></i> Action irréversible — à utiliser uniquement en test/debug.
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-reset-<?= $enr['f_id'] ?>').style.display='none'">Annuler</button>
        <form method="POST" style="margin:0">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reset_formation">
          <input type="hidden" name="formation_id" value="<?= $enr['f_id'] ?>">
          <button type="submit" class="btn" style="background:var(--danger);color:white"><i class="fas fa-redo"></i> Réinitialiser</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script>
function toggleSection(id) {
  const el = document.getElementById(id);
  const chevId = 'chev-' + id.replace('mod-','');
  const chev = document.getElementById(chevId);
  if (!el) return;
  const open = el.style.display !== 'none';
  el.style.display = open ? 'none' : 'block';
  if (chev) chev.style.transform = open ? 'rotate(-90deg)' : '';
}

// Fermer les modales en cliquant à l'extérieur
document.querySelectorAll('[id^="modal-reset"]').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
});
</script>
<?php renderFooter(); ?>
