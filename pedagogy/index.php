<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();

$stats = [];
$queries = [
    'total_students'    => "SELECT COUNT(*) FROM users WHERE role='student' AND status='active'",
    'pending_students'  => "SELECT COUNT(*) FROM users WHERE role='student' AND status='pending'",
    'total_formations'  => "SELECT COUNT(*) FROM formations WHERE status='active'",
    'total_enrollments' => "SELECT COUNT(*) FROM enrollments WHERE status='active'",
    'completions'       => "SELECT COUNT(*) FROM enrollments WHERE status='completed'",
    'total_lessons'     => "SELECT COUNT(*) FROM lessons",
];
foreach ($queries as $key => $sql) {
    $stats[$key] = (int)$pdo->query($sql)->fetchColumn();
}

// Formations actives avec progression
$formations = $pdo->query("
    SELECT f.*, COUNT(DISTINCT e.id) as student_count,
           ROUND(AVG(e.progress_percent), 0) as avg_progress,
           r.rncp_code
    FROM formations f
    LEFT JOIN enrollments e ON f.id = e.formation_id AND e.status != 'dropped'
    LEFT JOIN rncp_titles r ON f.rncp_title_id = r.id
    WHERE f.status = 'active'
    GROUP BY f.id
    ORDER BY student_count DESC LIMIT 8
")->fetchAll();

// Inscriptions récentes
$recentEnrollments = $pdo->query("
    SELECT e.*, u.first_name, u.last_name, u.email, f.title as formation_title
    FROM enrollments e
    JOIN users u ON e.user_id = u.id
    JOIN formations f ON e.formation_id = f.id
    ORDER BY e.enrolled_at DESC LIMIT 8
")->fetchAll();

// Étudiants en difficulté (progression < 20% depuis plus de 7 jours)
$atRisk = $pdo->query("
    SELECT u.first_name, u.last_name, u.email, e.progress_percent, f.title as formation_title,
           DATEDIFF(NOW(), e.enrolled_at) as days_enrolled
    FROM enrollments e
    JOIN users u ON e.user_id = u.id
    JOIN formations f ON e.formation_id = f.id
    WHERE e.status = 'active' AND e.progress_percent < 20 AND DATEDIFF(NOW(), e.enrolled_at) > 7
    ORDER BY e.progress_percent ASC LIMIT 5
")->fetchAll();

renderHead('Tableau de bord Pédagogie');
renderSidebar('pedagogy');
renderTopbar('Tableau de bord', [['Pédagogie', url('pedagogy/index.php')], ['Dashboard', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Welcome Banner -->
  <?php $user = currentUser(); ?>
  <div style="background:linear-gradient(135deg,rgba(16,185,129,.15),rgba(6,182,212,.1));border:1px solid rgba(16,185,129,.2);border-radius:var(--radius-xl);padding:24px 28px;margin-bottom:28px;display:flex;align-items:center;justify-content:space-between;gap:20px">
    <div>
      <div style="font-size:22px;font-weight:800;margin-bottom:6px">Bonjour, <?= e($user['first_name']) ?> 👋</div>
      <div style="color:var(--text-muted);font-size:14px"><?= date('l d F Y', time()) ?> · Espace Pédagogie</div>
    </div>
    <div style="display:flex;gap:10px">
      <a href="<?= url('admin/formations/create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Créer une formation</a>
      <a href="<?= url('admin/users/index.php?status=pending') ?>" class="btn btn-secondary"><i class="fas fa-user-check"></i> Valider des comptes</a>
    </div>
  </div>

  <!-- KPIs -->
  <div class="stat-cards">
    <div class="stat-card" style="--card-color:#10b981;--card-color-bg:rgba(16,185,129,.15)">
      <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
      <div>
        <div class="stat-label">Étudiants actifs</div>
        <div class="stat-value"><?= $stats['total_students'] ?></div>
        <?php if ($stats['pending_students'] > 0): ?>
        <div class="stat-change"><i class="fas fa-clock"></i> <?= $stats['pending_students'] ?> en attente</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="stat-card" style="--card-color:#6366f1;--card-color-bg:rgba(99,102,241,.15)">
      <div class="stat-icon"><i class="fas fa-graduation-cap"></i></div>
      <div>
        <div class="stat-label">Formations actives</div>
        <div class="stat-value"><?= $stats['total_formations'] ?></div>
      </div>
    </div>
    <div class="stat-card" style="--card-color:#f59e0b;--card-color-bg:rgba(245,158,11,.15)">
      <div class="stat-icon"><i class="fas fa-book-open"></i></div>
      <div>
        <div class="stat-label">Inscriptions</div>
        <div class="stat-value"><?= $stats['total_enrollments'] ?></div>
        <div class="stat-change"><i class="fas fa-check-circle"></i> <?= $stats['completions'] ?> terminées</div>
      </div>
    </div>
    <div class="stat-card" style="--card-color:#0ea5e9;--card-color-bg:rgba(14,165,233,.15)">
      <div class="stat-icon"><i class="fas fa-film"></i></div>
      <div>
        <div class="stat-label">Capsules</div>
        <div class="stat-value"><?= $stats['total_lessons'] ?></div>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:24px">

    <!-- Formations -->
    <div>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-graduation-cap" style="color:var(--primary-light);margin-right:8px"></i>Formations actives</h3>
          <a href="<?= url('admin/formations/index.php') ?>" class="btn btn-ghost btn-sm">Gérer <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="table-container">
          <table class="table">
            <thead>
              <tr><th>Formation</th><th>RNCP</th><th>Étudiants</th><th>Progression moy.</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($formations as $f): ?>
              <tr>
                <td style="font-weight:600;color:white;font-size:13px;max-width:200px" class="truncate"><?= e($f['title']) ?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= e($f['rncp_code'] ?? '—') ?></td>
                <td style="text-align:center"><?= $f['student_count'] ?></td>
                <td style="min-width:120px">
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="progress-bar" style="flex:1;height:6px"><div class="progress-fill" style="width:<?= $f['avg_progress'] ?? 0 ?>%"></div></div>
                    <span style="font-size:11px;color:var(--text-muted)"><?= $f['avg_progress'] ?? 0 ?>%</span>
                  </div>
                </td>
                <td><a href="<?= url('admin/formations/view.php?id='.$f['id']) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-eye"></i></a></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($formations)): ?>
              <tr><td colspan="5"><div class="empty-state" style="padding:30px"><p>Aucune formation active</p></div></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Inscriptions récentes -->
      <div class="card" style="margin-top:24px">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-user-clock" style="color:var(--primary-light);margin-right:8px"></i>Inscriptions récentes</h3>
        </div>
        <div class="table-container">
          <table class="table">
            <thead>
              <tr><th>Étudiant</th><th>Formation</th><th>Progression</th><th>Statut</th><th>Date</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentEnrollments as $en): ?>
              <tr>
                <td>
                  <div>
                    <div style="font-weight:600;color:white;font-size:13px"><?= e($en['first_name'].' '.$en['last_name']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= e($en['email']) ?></div>
                  </div>
                </td>
                <td style="font-size:13px;max-width:160px" class="truncate"><?= e($en['formation_title']) ?></td>
                <td style="min-width:100px">
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="progress-bar" style="flex:1;height:6px"><div class="progress-fill" style="width:<?= $en['progress_percent'] ?>%"></div></div>
                    <span style="font-size:11px;color:var(--text-muted)"><?= $en['progress_percent'] ?>%</span>
                  </div>
                </td>
                <td><?= getStatusBadge($en['status']) ?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= formatDate($en['enrolled_at']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Sidebar droite -->
    <div style="display:flex;flex-direction:column;gap:24px">

      <!-- Actions rapides -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">Actions rapides</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:8px;padding:16px">
          <a href="<?= url('admin/formations/index.php') ?>" class="btn btn-primary" style="justify-content:flex-start;gap:12px">
            <i class="fas fa-graduation-cap"></i> Gérer les formations
          </a>
          <a href="<?= url('admin/formations/create.php') ?>" class="btn btn-secondary" style="justify-content:flex-start;gap:12px">
            <i class="fas fa-plus"></i> Créer une formation
          </a>
          <a href="<?= url('admin/users/index.php') ?>" class="btn btn-secondary" style="justify-content:flex-start;gap:12px">
            <i class="fas fa-users"></i> Voir les apprenants
          </a>
          <a href="<?= url('admin/sessions/index.php') ?>" class="btn btn-secondary" style="justify-content:flex-start;gap:12px">
            <i class="fas fa-calendar-alt"></i> Gérer les sessions
          </a>
          <a href="<?= url('admin/qualiopi/index.php') ?>" class="btn btn-secondary" style="justify-content:flex-start;gap:12px">
            <i class="fas fa-shield-alt"></i> Tableau Qualiopi
          </a>
        </div>
      </div>

      <!-- Apprenants en difficulté -->
      <?php if (!empty($atRisk)): ?>
      <div class="card" style="border-color:rgba(239,68,68,.3)">
        <div class="card-header">
          <h3 class="card-title" style="color:#f87171"><i class="fas fa-exclamation-triangle" style="margin-right:8px"></i>Apprenants en difficulté</h3>
        </div>
        <div style="padding:12px 0">
          <?php foreach ($atRisk as $i => $s): ?>
          <div style="padding:10px 20px;<?= $i < count($atRisk)-1 ? 'border-bottom:1px solid var(--border-light)' : '' ?>">
            <div style="font-size:13px;font-weight:600;color:white"><?= e($s['first_name'].' '.$s['last_name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= e($s['formation_title']) ?></div>
            <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
              <div class="progress-bar" style="flex:1;height:4px"><div class="progress-fill" style="width:<?= $s['progress_percent'] ?>%;background:#ef4444"></div></div>
              <span style="font-size:11px;color:#f87171;white-space:nowrap"><?= $s['progress_percent'] ?>% · J+<?= $s['days_enrolled'] ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php renderFooter(); ?>
