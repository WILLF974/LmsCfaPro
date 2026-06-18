<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();

// Global stats
$stats = [];
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'"); $stats['students'] = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM formations WHERE status='active'"); $stats['formations'] = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status='completed'"); $stats['completions'] = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM quiz_attempts WHERE passed=1"); $stats['quiz_passed'] = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT AVG(e.progress_percent) FROM enrollments e WHERE e.status='active'"); $stats['avg_progress'] = round($stmt->fetchColumn() ?? 0);
$stmt = $pdo->query("SELECT SUM(xp_points) FROM users WHERE role='student'"); $stats['total_xp'] = $stmt->fetchColumn() ?? 0;

// Monthly enrollments (last 6 months)
$monthly = $pdo->query("
    SELECT DATE_FORMAT(enrolled_at,'%Y-%m') as month, COUNT(*) as count
    FROM enrollments WHERE enrolled_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month ORDER BY month
")->fetchAll();

// Top students by XP
$topStudents = $pdo->query("
    SELECT u.*, (SELECT COUNT(*) FROM enrollments e WHERE e.user_id=u.id AND e.status='completed') as completed_count
    FROM users u WHERE u.role='student' AND u.status='active'
    ORDER BY u.xp_points DESC LIMIT 10
")->fetchAll();

// Formation completion rates
$formationStats = $pdo->query("
    SELECT f.title, f.id,
           COUNT(e.id) as total_enrolled,
           SUM(CASE WHEN e.status='completed' THEN 1 ELSE 0 END) as completed,
           AVG(e.progress_percent) as avg_progress
    FROM formations f
    LEFT JOIN enrollments e ON f.id=e.formation_id
    WHERE f.status='active'
    GROUP BY f.id ORDER BY total_enrolled DESC LIMIT 8
")->fetchAll();

renderHead('Rapports');
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar('Rapports & Statistiques', [['Admin', url('admin/index.php')], ['Rapports', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- KPIs -->
  <div class="stat-cards" style="margin-bottom:28px">
    <div class="stat-card" style="--card-color:#6366f1;--card-color-bg:rgba(99,102,241,.15)">
      <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
      <div><div class="stat-label">Étudiants actifs</div><div class="stat-value" data-count="<?= $stats['students'] ?>"><?= $stats['students'] ?></div></div>
    </div>
    <div class="stat-card" style="--card-color:#10b981;--card-color-bg:rgba(16,185,129,.15)">
      <div class="stat-icon"><i class="fas fa-graduation-cap"></i></div>
      <div><div class="stat-label">Certifications obtenues</div><div class="stat-value" data-count="<?= $stats['completions'] ?>"><?= $stats['completions'] ?></div></div>
    </div>
    <div class="stat-card" style="--card-color:#f59e0b;--card-color-bg:rgba(245,158,11,.15)">
      <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
      <div><div class="stat-label">Progression moyenne</div><div class="stat-value"><?= $stats['avg_progress'] ?>%</div></div>
    </div>
    <div class="stat-card" style="--card-color:#0ea5e9;--card-color-bg:rgba(14,165,233,.15)">
      <div class="stat-icon"><i class="fas fa-trophy"></i></div>
      <div><div class="stat-label">XP total distribué</div><div class="stat-value"><?= number_format((int)$stats['total_xp']) ?></div></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px">
    <!-- Formation Stats -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Taux de complétion par formation</h3>
        <a href="#" onclick="exportTable('formation-table')" class="btn btn-ghost btn-sm"><i class="fas fa-download"></i> CSV</a>
      </div>
      <div class="table-container">
        <table class="table" id="formation-table">
          <thead><tr><th>Formation</th><th>Inscrits</th><th>Terminés</th><th>Progression</th></tr></thead>
          <tbody>
            <?php foreach ($formationStats as $fs): ?>
            <tr>
              <td style="max-width:160px" class="truncate"><?= e($fs['title']) ?></td>
              <td><?= $fs['total_enrolled'] ?></td>
              <td><span class="badge badge-success"><?= $fs['completed'] ?></span></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div class="progress-bar" style="flex:1;height:6px"><div class="progress-fill" style="width:<?= round($fs['avg_progress']??0) ?>%"></div></div>
                  <span style="font-size:11px;white-space:nowrap"><?= round($fs['avg_progress']??0) ?>%</span>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Top Students -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">🏆 Top étudiants XP</h3>
        <a href="#" onclick="exportTable('students-table')" class="btn btn-ghost btn-sm"><i class="fas fa-download"></i> CSV</a>
      </div>
      <div class="table-container">
        <table class="table" id="students-table">
          <thead><tr><th>#</th><th>Étudiant</th><th>XP</th><th>Niveau</th><th>Certif.</th></tr></thead>
          <tbody>
            <?php foreach ($topStudents as $i => $s): ?>
            <tr>
              <td style="font-weight:800;color:<?= ['#f59e0b','#94a3b8','#b45309'][$i]??'var(--text-muted)' ?>"><?= $i+1 ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div class="avatar avatar-sm" style="background:<?= getAvatarColor($s['first_name'].$s['last_name']) ?>"><?= getAvatarInitials($s['first_name'],$s['last_name']) ?></div>
                  <span style="font-size:13px"><?= e($s['first_name'].' '.$s['last_name']) ?></span>
                </div>
              </td>
              <td style="font-weight:700"><?= number_format((int)$s['xp_points']) ?></td>
              <td><span class="badge badge-warning">Niv. <?= getLevel((int)$s['xp_points']) ?></span></td>
              <td><?= $s['completed_count'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Export buttons -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">Exports Qualiopi</h3></div>
    <div class="card-body" style="display:flex;gap:12px;flex-wrap:wrap">
      <a href="<?= url('api/export.php?type=enrollments') ?>" class="btn btn-secondary"><i class="fas fa-file-excel"></i> Inscriptions (CSV)</a>
      <a href="<?= url('api/export.php?type=progress') ?>" class="btn btn-secondary"><i class="fas fa-file-csv"></i> Progression (CSV)</a>
      <a href="<?= url('api/export.php?type=attendance') ?>" class="btn btn-secondary"><i class="fas fa-calendar-check"></i> Présences (CSV)</a>
      <a href="<?= url('api/export.php?type=evaluations') ?>" class="btn btn-secondary"><i class="fas fa-tasks"></i> Évaluations (CSV)</a>
      <a href="<?= url('api/export.php?type=audit_log') ?>" class="btn btn-secondary"><i class="fas fa-history"></i> Journal d'audit (CSV)</a>
    </div>
  </div>
</div>

<script src="<?= asset('js/main.js') ?>"></script>
<script>
function exportTable(tableId) {
  const table = document.getElementById(tableId);
  let csv = '';
  table.querySelectorAll('tr').forEach(row => {
    const cols = [...row.querySelectorAll('th, td')].map(c => '"' + c.innerText.replace(/"/g,'""') + '"');
    csv += cols.join(',') + '\n';
  });
  const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = tableId + '_' + new Date().toISOString().split('T')[0] + '.csv';
  link.click();
}
</script>
<?php renderFooter(['']); ?>
