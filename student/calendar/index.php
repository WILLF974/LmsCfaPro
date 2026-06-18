<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Sessions auxquelles l'étudiant est inscrit
try {
    $stmt = $pdo->prepare("
        SELECT fs.*, f.title as formation_title, f.id as formation_id,
               a.status as attendance_status
        FROM formation_sessions fs
        JOIN formations f ON fs.formation_id = f.id
        JOIN enrollments e ON e.formation_id = f.id AND e.user_id = ? AND e.status IN ('active','completed')
        LEFT JOIN attendance a ON a.session_id = fs.id AND a.user_id = ?
        ORDER BY fs.date ASC, fs.start_time ASC
    ");
    $stmt->execute([$userId, $userId]);
    $sessions = $stmt->fetchAll();
    $tableExists = true;
} catch (PDOException $e) {
    $sessions = [];
    $tableExists = false;
}

// Grouper par mois
$byMonth = [];
foreach ($sessions as $s) {
    $month = date('Y-m', strtotime($s['date']));
    $byMonth[$month][] = $s;
}

$today = date('Y-m-d');
$upcoming = array_filter($sessions, fn($s) => $s['date'] >= $today);
$past     = array_filter($sessions, fn($s) => $s['date'] < $today);

renderHead('Mon calendrier');
renderSidebar('student');
renderTopbar('Mon calendrier', [['Mon espace', url('student/index.php')], ['Calendrier', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <?php if (!$tableExists): ?>
  <div class="alert alert-info"><i class="fas fa-info-circle"></i> Le module de sessions n'est pas encore configuré.</div>
  <?php elseif (empty($sessions)): ?>
  <div class="empty-state">
    <div class="icon">📅</div>
    <h3>Aucune session planifiée</h3>
    <p>Vos sessions de formation apparaîtront ici dès qu'elles seront planifiées.</p>
    <a href="<?= url('student/formations/index.php') ?>" class="btn btn-primary">Voir mes formations</a>
  </div>
  <?php else: ?>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px">
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(99,102,241,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-calendar-check" style="color:var(--primary-light)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:white"><?= count($upcoming) ?></div><div style="font-size:12px;color:var(--text-muted)">À venir</div></div>
    </div></div>
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(16,185,129,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-check" style="color:var(--success)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:white"><?= count($past) ?></div><div style="font-size:12px;color:var(--text-muted)">Passées</div></div>
    </div></div>
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(245,158,11,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-calendar-alt" style="color:var(--warning)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:white"><?= count($sessions) ?></div><div style="font-size:12px;color:var(--text-muted)">Total</div></div>
    </div></div>
  </div>

  <!-- Sessions à venir -->
  <?php if (!empty($upcoming)): ?>
  <h2 style="font-size:16px;font-weight:700;margin-bottom:16px"><i class="fas fa-calendar-check" style="color:var(--success);margin-right:8px"></i>Sessions à venir</h2>
  <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:32px">
    <?php foreach ($upcoming as $s):
      $isToday    = $s['date'] === $today;
      $isTomorrow = $s['date'] === date('Y-m-d', strtotime('+1 day'));
      $isOnline   = in_array($s['type'], ['online', 'virtual_class']);
      $typeLabels = ['online'=>'En ligne','onsite'=>'Présentiel','hybrid'=>'Hybride','virtual_class'=>'Classe virtuelle'];
      $typeIcons  = ['online'=>'fa-video','onsite'=>'fa-map-marker-alt','hybrid'=>'fa-random','virtual_class'=>'fa-desktop'];
    ?>
    <div class="card" style="border-left:4px solid <?= $isToday ? 'var(--success)' : ($isOnline ? 'var(--info)' : 'var(--primary)') ?>">
      <div class="card-body">
        <div style="display:flex;align-items:flex-start;gap:16px">
          <!-- Date block -->
          <div style="text-align:center;background:var(--bg-elevated);border-radius:var(--radius);padding:10px 16px;min-width:60px;flex-shrink:0">
            <div style="font-size:22px;font-weight:800;color:<?= $isToday ? 'var(--success)' : 'white' ?>"><?= date('d', strtotime($s['date'])) ?></div>
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase"><?= date('M', strtotime($s['date'])) ?></div>
          </div>
          <div style="flex:1">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap">
              <?php if ($isToday): ?><span class="badge badge-success">Aujourd'hui</span>
              <?php elseif ($isTomorrow): ?><span class="badge badge-warning">Demain</span>
              <?php endif; ?>
              <span class="badge badge-secondary">
                <i class="fas <?= $typeIcons[$s['type']] ?? 'fa-calendar' ?>"></i>
                <?= $typeLabels[$s['type']] ?? $s['type'] ?>
              </span>
            </div>
            <h3 style="font-size:15px;font-weight:700;margin-bottom:4px"><?= e($s['title']) ?></h3>
            <div style="font-size:13px;color:var(--text-muted);margin-bottom:4px"><i class="fas fa-graduation-cap"></i> <?= e($s['formation_title']) ?></div>
            <div style="display:flex;gap:16px;font-size:12px;color:var(--text-faint);flex-wrap:wrap">
              <?php if ($s['start_time']): ?><span><i class="fas fa-clock"></i> <?= substr($s['start_time'],0,5) ?><?= $s['end_time'] ? ' — ' . substr($s['end_time'],0,5) : '' ?></span><?php endif; ?>
              <?php if ($s['location']): ?><span><i class="fas fa-map-marker-alt"></i> <?= e($s['location']) ?></span><?php endif; ?>
            </div>
            <?php if ($s['description']): ?>
            <p style="font-size:12px;color:var(--text-faint);margin-top:6px;margin-bottom:0"><?= e(mb_strimwidth($s['description'],0,120,'…')) ?></p>
            <?php endif; ?>
          </div>
          <?php if ($isOnline && $s['meeting_url']): ?>
          <a href="<?= e($s['meeting_url']) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm" style="flex-shrink:0"><i class="fas fa-video"></i> Rejoindre</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Sessions passées -->
  <?php if (!empty($past)): ?>
  <h2 style="font-size:16px;font-weight:700;margin-bottom:16px;color:var(--text-muted)">Sessions passées</h2>
  <div style="display:flex;flex-direction:column;gap:8px">
    <?php
    $attColors = ['present'=>'var(--success)','late'=>'var(--warning)','excused'=>'var(--info)','absent'=>'var(--danger)'];
    $attIcons  = ['present'=>'fa-check-circle','late'=>'fa-clock','excused'=>'fa-envelope','absent'=>'fa-times-circle'];
    $attLabels = ['present'=>'Présent','late'=>'En retard','excused'=>'Excusé','absent'=>'Absent'];
    foreach (array_reverse($past) as $s):
      $as = $s['attendance_status'] ?? null;
    ?>
    <div class="card" style="opacity:.75">
      <div class="card-body" style="padding:12px 16px">
        <div style="display:flex;align-items:center;gap:12px">
          <div style="text-align:center;min-width:50px;flex-shrink:0">
            <div style="font-size:16px;font-weight:700;color:var(--text-muted)"><?= date('d/m', strtotime($s['date'])) ?></div>
            <div style="font-size:10px;color:var(--text-faint)"><?= date('Y', strtotime($s['date'])) ?></div>
          </div>
          <div style="flex:1">
            <div style="font-size:13px;font-weight:600"><?= e($s['title']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= e($s['formation_title']) ?><?= $s['start_time'] ? ' · ' . substr($s['start_time'],0,5) : '' ?></div>
          </div>
          <?php if ($as): ?>
          <span style="font-size:11px;color:<?= $attColors[$as] ?>;display:flex;align-items:center;gap:4px;flex-shrink:0">
            <i class="fas <?= $attIcons[$as] ?>"></i> <?= $attLabels[$as] ?>
          </span>
          <?php else: ?>
          <i class="fas fa-check-circle" style="color:var(--success);opacity:.4"></i>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
