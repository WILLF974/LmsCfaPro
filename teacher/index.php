<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/layout.php';
requireTeacher();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];

// Teacher stats
$myCourses = (int)$pdo->prepare('SELECT COUNT(*) FROM lessons WHERE created_by=?')->execute([$userId]) ? $pdo->prepare('SELECT COUNT(*) FROM lessons WHERE created_by=?') : 0;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM lessons WHERE created_by=?'); $stmt->execute([$userId]);
$myCourseCount = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM quizzes WHERE created_by=?'); $stmt->execute([$userId]);
$myQuizCount = (int)$stmt->fetchColumn();

// Pending evaluations
$stmt = $pdo->prepare('SELECT COUNT(*) FROM evaluation_submissions es JOIN evaluations e ON es.evaluation_id=e.id WHERE e.created_by=? AND es.status="submitted"');
$stmt->execute([$userId]);
$pendingEvals = (int)$stmt->fetchColumn();

// My formations (as teacher)
$myFormations = $pdo->prepare("
    SELECT f.*, r.rncp_code,
           (SELECT COUNT(DISTINCT e.user_id) FROM enrollments e WHERE e.formation_id=f.id AND e.status='active') as student_count,
           (SELECT COUNT(*) FROM modules m WHERE m.formation_id=f.id) as module_count
    FROM formations f
    LEFT JOIN rncp_titles r ON f.rncp_title_id=r.id
    WHERE f.created_by=? AND f.status!='archived'
    ORDER BY f.updated_at DESC LIMIT 6
");
$myFormations->execute([$userId]);
$formations = $myFormations->fetchAll();

// Recent quiz results
$recentResults = $pdo->prepare("
    SELECT qa.*, u.first_name, u.last_name, q.title as quiz_title
    FROM quiz_attempts qa
    JOIN quizzes q ON qa.quiz_id=q.id
    JOIN users u ON qa.user_id=u.id
    WHERE q.created_by=? AND qa.status='completed'
    ORDER BY qa.completed_at DESC LIMIT 8
");
$recentResults->execute([$userId]);
$quizResults = $recentResults->fetchAll();

renderHead('Tableau de bord Enseignant');
renderSidebar('teacher');
renderTopbar('Mon espace enseignant', [['Tableau de bord', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <?php $user = currentUser(); ?>
  <!-- Welcome -->
  <div style="background:linear-gradient(135deg,rgba(16,185,129,.12),rgba(14,165,233,.08));border:1px solid rgba(16,185,129,.2);border-radius:var(--radius-xl);padding:24px 28px;margin-bottom:28px;display:flex;align-items:center;justify-content:space-between;gap:20px">
    <div>
      <h2 style="font-size:22px;font-weight:800;margin-bottom:4px">Bonjour, <?= e($user['first_name']) ?> 👋</h2>
      <p style="color:var(--text-muted);font-size:14px">Gérez vos capsules, quiz et suivez vos apprenants</p>
    </div>
    <div style="display:flex;gap:10px">
      <a href="<?= url('teacher/courses/create.php') ?>" class="btn btn-success"><i class="fas fa-plus"></i> Nouvelle capsule</a>
      <a href="<?= url('teacher/quizzes/create.php') ?>" class="btn btn-secondary"><i class="fas fa-question-circle"></i> Nouveau quiz</a>
    </div>
  </div>

  <!-- Stats -->
  <div class="stat-cards">
    <div class="stat-card" style="--card-color:#10b981;--card-color-bg:rgba(16,185,129,.15)">
      <div class="stat-icon"><i class="fas fa-book-open"></i></div>
      <div><div class="stat-label">Mes capsules</div><div class="stat-value" data-count="<?= $myCourseCount ?>"><?= $myCourseCount ?></div></div>
    </div>
    <div class="stat-card" style="--card-color:#8b5cf6;--card-color-bg:rgba(139,92,246,.15)">
      <div class="stat-icon"><i class="fas fa-question-circle"></i></div>
      <div><div class="stat-label">Mes quiz</div><div class="stat-value" data-count="<?= $myQuizCount ?>"><?= $myQuizCount ?></div></div>
    </div>
    <div class="stat-card" style="--card-color:#f59e0b;--card-color-bg:rgba(245,158,11,.15)">
      <div class="stat-icon"><i class="fas fa-tasks"></i></div>
      <div>
        <div class="stat-label">Corrections en attente</div>
        <div class="stat-value" data-count="<?= $pendingEvals ?>"><?= $pendingEvals ?></div>
        <?php if ($pendingEvals > 0): ?><div class="stat-change" style="color:var(--warning)"><i class="fas fa-exclamation"></i> À corriger</div><?php endif; ?>
      </div>
    </div>
    <div class="stat-card" style="--card-color:#0ea5e9;--card-color-bg:rgba(14,165,233,.15)">
      <div class="stat-icon"><i class="fas fa-graduation-cap"></i></div>
      <div><div class="stat-label">Mes formations</div><div class="stat-value" data-count="<?= count($formations) ?>"><?= count($formations) ?></div></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 360px;gap:24px">
    <!-- My Formations -->
    <div>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-graduation-cap" style="color:var(--primary-light);margin-right:8px"></i>Mes formations</h3>
          <a href="<?= url('teacher/courses/index.php') ?>" class="btn btn-ghost btn-sm">Voir tout <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($formations)): ?>
        <div class="empty-state" style="padding:40px"><div class="icon"><i class="fas fa-graduation-cap"></i></div><h3>Aucune formation</h3><p>Contactez un administrateur pour être assigné à une formation.</p></div>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">
          <?php foreach ($formations as $i => $f): ?>
          <div style="padding:20px;<?= $i%2===0?'border-right:1px solid var(--border-light)':'' ?>;<?= $i<count($formations)-2?'border-bottom:1px solid var(--border-light)':'' ?>">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px">
              <div style="font-size:10px;color:var(--primary-light);font-weight:700;letter-spacing:.08em"><?= e($f['rncp_code'] ?? 'RNCP') ?></div>
              <?= getStatusBadge($f['status']) ?>
            </div>
            <div style="font-size:14px;font-weight:700;color:white;margin-bottom:8px;line-height:1.3" class="truncate"><?= e($f['title']) ?></div>
            <div style="display:flex;gap:12px;font-size:12px;color:var(--text-muted);margin-bottom:12px">
              <span><i class="fas fa-users" style="margin-right:4px"></i><?= $f['student_count'] ?> étud.</span>
              <span><i class="fas fa-layer-group" style="margin-right:4px"></i><?= $f['module_count'] ?> modules</span>
            </div>
            <div style="display:flex;gap:6px">
              <a href="<?= url('admin/formations/view.php?id='.$f['id']) ?>" class="btn btn-primary btn-sm" style="flex:1;justify-content:center">Gérer</a>
              <a href="<?= url('teacher/students/index.php?formation_id='.$f['id']) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-users"></i></a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right Panel -->
    <div style="display:flex;flex-direction:column;gap:20px">

      <!-- Quick Actions -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">Actions rapides</h3></div>
        <div class="card-body" style="padding:12px;display:flex;flex-direction:column;gap:8px">
          <a href="<?= url('teacher/courses/create.php') ?>" class="btn btn-success" style="justify-content:flex-start;gap:10px"><i class="fas fa-video"></i> Ajouter une capsule vidéo</a>
          <a href="<?= url('teacher/courses/create.php?type=pdf') ?>" class="btn btn-secondary" style="justify-content:flex-start;gap:10px"><i class="fas fa-file-pdf"></i> Ajouter un document PDF</a>
          <a href="<?= url('teacher/quizzes/create.php') ?>" class="btn btn-secondary" style="justify-content:flex-start;gap:10px"><i class="fas fa-question-circle"></i> Créer un quiz</a>
          <?php if ($pendingEvals > 0): ?>
          <a href="<?= url('teacher/evaluations/index.php') ?>" class="btn btn-warning" style="justify-content:flex-start;gap:10px"><i class="fas fa-tasks"></i> Corriger (<?= $pendingEvals ?>)</a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent Quiz Results -->
      <div class="card" style="flex:1">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-bar" style="color:var(--primary-light);margin-right:8px"></i>Résultats récents</h3></div>
        <?php if (empty($quizResults)): ?>
        <div class="empty-state" style="padding:30px"><p>Aucun résultat de quiz pour l'instant.</p></div>
        <?php else: ?>
        <div style="padding:0">
          <?php foreach ($quizResults as $r): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border-light)">
            <div class="avatar avatar-sm" style="background:<?= getAvatarColor($r['first_name'].$r['last_name']) ?>">
              <?= getAvatarInitials($r['first_name'], $r['last_name']) ?>
            </div>
            <div style="flex:1;overflow:hidden">
              <div style="font-size:13px;font-weight:600;color:white"><?= e($r['first_name'].' '.$r['last_name']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)" class="truncate"><?= e($r['quiz_title']) ?></div>
            </div>
            <div style="text-align:right">
              <div style="font-size:15px;font-weight:800;color:<?= $r['passed'] ? 'var(--success)' : 'var(--danger)' ?>"><?= round($r['score']) ?>%</div>
              <div style="font-size:10px;color:<?= $r['passed'] ? 'var(--success)' : 'var(--danger)' ?>"><?= $r['passed'] ? 'Réussi' : 'Échoué' ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
<?php renderFooter(); ?>
