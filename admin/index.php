<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/layout.php';
requireAdmin();

$pdo = getDB();

// KPIs
$stats = [];
$queries = [
    'total_students'    => "SELECT COUNT(*) FROM users WHERE role='student' AND status='active'",
    'pending_students'  => "SELECT COUNT(*) FROM users WHERE role='student' AND status='pending'",
    'total_formations'  => "SELECT COUNT(*) FROM formations WHERE status='active'",
    'total_rncp'        => "SELECT COUNT(*) FROM rncp_titles WHERE status='active'",
    'total_enrollments' => "SELECT COUNT(*) FROM enrollments WHERE status='active'",
    'completions'       => "SELECT COUNT(*) FROM enrollments WHERE status='completed'",
    'total_lessons'     => "SELECT COUNT(*) FROM lessons",
    'total_teachers'    => "SELECT COUNT(*) FROM users WHERE role='teacher' AND status='active'",
];
foreach ($queries as $key => $sql) {
    $stats[$key] = (int)$pdo->query($sql)->fetchColumn();
}

// Inscriptions récentes
$recentEnrollments = $pdo->query("
    SELECT e.*, u.first_name, u.last_name, u.avatar, u.email, f.title as formation_title, f.id as fid
    FROM enrollments e
    JOIN users u ON e.user_id = u.id
    JOIN formations f ON e.formation_id = f.id
    ORDER BY e.enrolled_at DESC LIMIT 8
")->fetchAll();

// Activité récente (audit log)
$recentActivity = $pdo->query("
    SELECT a.*, u.first_name, u.last_name
    FROM audit_log a
    LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.created_at DESC LIMIT 10
")->fetchAll();

// Formations populaires
$topFormations = $pdo->query("
    SELECT f.*, COUNT(e.id) as student_count, r.rncp_code
    FROM formations f
    LEFT JOIN enrollments e ON f.id = e.formation_id AND e.status != 'dropped'
    LEFT JOIN rncp_titles r ON f.rncp_title_id = r.id
    WHERE f.status = 'active'
    GROUP BY f.id
    ORDER BY student_count DESC LIMIT 5
")->fetchAll();

renderHead('Tableau de bord Admin');
renderSidebar('admin');
renderTopbar('Tableau de bord', [['Accueil', url('admin/index.php')], ['Dashboard', '']]);
?>
<div class="page-content fade-in">
  <!-- Flash -->
  <?= renderFlash() ?>

  <!-- Welcome Banner -->
  <?php $user = currentUser(); ?>
  <div style="background:linear-gradient(135deg,rgba(99,102,241,.15),rgba(139,92,246,.1));border:1px solid rgba(99,102,241,.2);border-radius:var(--radius-xl);padding:24px 28px;margin-bottom:28px;display:flex;align-items:center;justify-content:space-between;gap:20px">
    <div>
      <div style="font-size:22px;font-weight:800;margin-bottom:6px">Bonjour, <?= e($user['first_name']) ?> 👋</div>
      <div style="color:var(--text-muted);font-size:14px"><?= date('l d F Y', time()) ?> · Tableau de bord administrateur</div>
    </div>
    <div style="display:flex;gap:10px">
      <a href="<?= url('admin/users/index.php?action=create') ?>" class="btn btn-primary"><i class="fas fa-user-plus"></i> Ajouter un utilisateur</a>
      <a href="<?= url('admin/rncp/create.php') ?>" class="btn btn-secondary"><i class="fas fa-plus"></i> Nouveau RNCP</a>
    </div>
  </div>

  <!-- Stats -->
  <div class="stat-cards">
    <div class="stat-card" style="--card-color:#6366f1;--card-color-bg:rgba(99,102,241,.15)">
      <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
      <div>
        <div class="stat-label">Étudiants actifs</div>
        <div class="stat-value" data-count="<?= $stats['total_students'] ?>"><?= $stats['total_students'] ?></div>
        <?php if ($stats['pending_students'] > 0): ?>
        <div class="stat-change"><i class="fas fa-clock"></i> <?= $stats['pending_students'] ?> en attente</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="stat-card" style="--card-color:#10b981;--card-color-bg:rgba(16,185,129,.15)">
      <div class="stat-icon"><i class="fas fa-graduation-cap"></i></div>
      <div>
        <div class="stat-label">Formations actives</div>
        <div class="stat-value" data-count="<?= $stats['total_formations'] ?>"><?= $stats['total_formations'] ?></div>
        <div class="stat-change"><i class="fas fa-certificate"></i> <?= $stats['total_rncp'] ?> titres RNCP</div>
      </div>
    </div>
    <div class="stat-card" style="--card-color:#f59e0b;--card-color-bg:rgba(245,158,11,.15)">
      <div class="stat-icon"><i class="fas fa-book-open"></i></div>
      <div>
        <div class="stat-label">Inscriptions</div>
        <div class="stat-value" data-count="<?= $stats['total_enrollments'] ?>"><?= $stats['total_enrollments'] ?></div>
        <div class="stat-change"><i class="fas fa-check-circle"></i> <?= $stats['completions'] ?> terminées</div>
      </div>
    </div>
    <div class="stat-card" style="--card-color:#0ea5e9;--card-color-bg:rgba(14,165,233,.15)">
      <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
      <div>
        <div class="stat-label">Enseignants</div>
        <div class="stat-value" data-count="<?= $stats['total_teachers'] ?>"><?= $stats['total_teachers'] ?></div>
        <div class="stat-change"><i class="fas fa-film"></i> <?= $stats['total_lessons'] ?> capsules</div>
      </div>
    </div>
  </div>

  <!-- Main Grid -->
  <div style="display:grid;grid-template-columns:1fr 360px;gap:24px">

    <!-- Left: Recent Enrollments -->
    <div>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-user-clock" style="color:var(--primary-light);margin-right:8px"></i>Inscriptions récentes</h3>
          <?php if ($stats['pending_students'] > 0): ?>
          <a href="<?= url('admin/users/index.php?status=pending') ?>" class="btn btn-warning btn-sm">
            <i class="fas fa-clock"></i> <?= $stats['pending_students'] ?> à valider
          </a>
          <?php endif; ?>
        </div>
        <div class="table-container">
          <table class="table">
            <thead>
              <tr>
                <th>Étudiant</th>
                <th>Formation</th>
                <th>Progression</th>
                <th>Statut</th>
                <th>Date</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentEnrollments as $en): ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:10px">
                    <div class="avatar avatar-sm" style="background:<?= getAvatarColor($en['first_name'].$en['last_name']) ?>">
                      <?= e(getAvatarInitials($en['first_name'], $en['last_name'])) ?>
                    </div>
                    <div>
                      <div style="font-weight:600;color:white;font-size:13px"><?= e($en['first_name'].' '.$en['last_name']) ?></div>
                      <div style="font-size:11px;color:var(--text-muted)"><?= e($en['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="font-size:13px;max-width:160px" class="truncate"><?= e($en['formation_title']) ?></td>
                <td style="min-width:100px">
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="progress-bar" style="flex:1;height:6px"><div class="progress-fill" style="width:<?= $en['progress_percent'] ?>%"></div></div>
                    <span style="font-size:11px;color:var(--text-muted);white-space:nowrap"><?= $en['progress_percent'] ?>%</span>
                  </div>
                </td>
                <td><?= getStatusBadge($en['status']) ?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= formatDate($en['enrolled_at']) ?></td>
                <td>
                  <?php if ($en['status'] === 'pending'): ?>
                  <a href="<?= url('admin/users/validate.php?enrollment_id='.$en['id']) ?>" class="btn btn-success btn-sm">Valider</a>
                  <?php else: ?>
                  <a href="<?= url('admin/users/index.php?view='.$en['user_id']) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-eye"></i></a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($recentEnrollments)): ?>
              <tr><td colspan="6"><div class="empty-state" style="padding:30px"><p>Aucune inscription</p></div></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer" style="display:flex;justify-content:flex-end">
          <a href="<?= url('admin/users/index.php') ?>" class="btn btn-ghost btn-sm">Voir tous les utilisateurs <i class="fas fa-arrow-right"></i></a>
        </div>
      </div>

      <!-- Top Formations -->
      <div class="card" style="margin-top:24px">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-fire" style="color:#f97316;margin-right:8px"></i>Formations populaires</h3>
          <a href="<?= url('admin/formations/index.php') ?>" class="btn btn-ghost btn-sm">Gérer</a>
        </div>
        <div class="card-body" style="padding:12px 0">
          <?php foreach ($topFormations as $i => $f): ?>
          <div style="display:flex;align-items:center;gap:16px;padding:10px 24px;<?= $i<count($topFormations)-1?'border-bottom:1px solid var(--border-light)':'' ?>">
            <div style="width:28px;height:28px;border-radius:50%;background:<?= ['rgba(245,158,11,.2)','rgba(148,163,184,.2)','rgba(180,83,9,.2)'][$i] ?? 'rgba(99,102,241,.15)' ?>;color:<?= ['#f59e0b','#94a3b8','#b45309'][$i] ?? 'var(--primary-light)' ?>;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0"><?= $i+1 ?></div>
            <div style="flex:1;overflow:hidden">
              <div style="font-size:13px;font-weight:600;color:white" class="truncate"><?= e($f['title']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?= e($f['rncp_code'] ?? '') ?></div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <div style="font-size:14px;font-weight:700;color:white"><?= $f['student_count'] ?></div>
              <div style="font-size:11px;color:var(--text-muted)">étudiants</div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Right Sidebar -->
    <div style="display:flex;flex-direction:column;gap:24px">

      <!-- Quick Actions -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">Actions rapides</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:8px;padding:16px">
          <a href="<?= url('admin/rncp/create.php') ?>" class="btn btn-primary" style="justify-content:flex-start;gap:12px">
            <i class="fas fa-certificate"></i> Ajouter un titre RNCP
          </a>
          <a href="<?= url('admin/formations/create.php') ?>" class="btn btn-secondary" style="justify-content:flex-start;gap:12px">
            <i class="fas fa-graduation-cap"></i> Créer une formation
          </a>
          <a href="<?= url('admin/users/create.php') ?>" class="btn btn-secondary" style="justify-content:flex-start;gap:12px">
            <i class="fas fa-user-plus"></i> Ajouter un utilisateur
          </a>
          <a href="<?= url('admin/qualiopi/index.php') ?>" class="btn btn-secondary" style="justify-content:flex-start;gap:12px">
            <i class="fas fa-shield-alt"></i> Tableau Qualiopi
          </a>
          <a href="<?= url('admin/reports/index.php') ?>" class="btn btn-secondary" style="justify-content:flex-start;gap:12px">
            <i class="fas fa-file-export"></i> Exporter les rapports
          </a>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="card" style="flex:1">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-history" style="color:var(--text-muted);margin-right:8px"></i>Activité récente</h3></div>
        <div style="padding:16px">
          <div class="timeline">
            <?php foreach ($recentActivity as $log):
              $actionLabels = ['login_success'=>'Connexion','login_failed'=>'Tentative échouée','register'=>'Inscription','logout'=>'Déconnexion','password_changed'=>'Changement mdp'];
              $icons = ['login_success'=>'sign-in-alt','login_failed'=>'ban','register'=>'user-plus','logout'=>'sign-out-alt','password_changed'=>'key'];
              $colors = ['login_success'=>'var(--success)','login_failed'=>'var(--danger)','register'=>'var(--primary)'];
            ?>
            <div class="timeline-item">
              <div class="timeline-dot" style="background:<?= $colors[$log['action']] ?? 'var(--text-muted)' ?>"></div>
              <div class="timeline-content">
                <div class="timeline-time"><?= timeAgo($log['created_at']) ?></div>
                <div class="timeline-text">
                  <i class="fas fa-<?= $icons[$log['action']] ?? 'circle' ?>" style="color:<?= $colors[$log['action']] ?? 'var(--text-muted)' ?>;margin-right:6px"></i>
                  <?= e($actionLabels[$log['action']] ?? $log['action']) ?>
                  <?php if ($log['first_name']): ?> · <strong><?= e($log['first_name'].' '.$log['last_name']) ?></strong><?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php renderFooter(); ?>
