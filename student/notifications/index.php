<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Marquer tout comme lu dès l'ouverture de la page
$pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')
    ->execute([$userId]);

// 30 dernières notifications
$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

renderHead('Corrections & notifications');
renderSidebar('student');
renderTopbar('Corrections & notifications', [
    ['Mon espace', url('student/index.php')],
    ['Corrections', ''],
]);
?>
<div class="page-content fade-in" style="max-width:720px">
  <?= renderFlash() ?>

  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-bell" style="color:var(--primary-light)"></i> Mes notifications</h3>
    </div>

    <?php if (empty($notifications)): ?>
    <div class="card-body">
      <div class="empty-state" style="padding:40px 20px">
        <i class="fas fa-bell-slash" style="font-size:40px;opacity:.3;margin-bottom:12px;display:block"></i>
        <p style="margin:0;color:var(--text-muted)">Aucune notification pour le moment.</p>
      </div>
    </div>
    <?php else: ?>
    <?php foreach ($notifications as $n):
      [$icon, $color] = match($n['type']) {
        'success' => ['fas fa-check-circle',    'var(--success)'],
        'warning' => ['fas fa-undo-alt',         'var(--warning)'],
        'badge'   => ['fas fa-trophy',           '#f59e0b'],
        'quiz'    => ['fas fa-question-circle',  'var(--primary-light)'],
        default   => ['fas fa-info-circle',      'var(--text-muted)'],
      };
    ?>
    <div style="display:flex;align-items:flex-start;gap:14px;padding:16px 20px;border-bottom:1px solid var(--border-light);<?= !$n['read_at'] ? 'background:rgba(99,102,241,.05)' : '' ?>">
      <div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.05);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px">
        <i class="<?= $icon ?>" style="color:<?= $color ?>;font-size:15px"></i>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:600;color:white;margin-bottom:3px"><?= e($n['title']) ?></div>
        <div style="font-size:12px;color:var(--text-muted);line-height:1.5"><?= e($n['message']) ?></div>
        <div style="font-size:11px;color:var(--text-faint);margin-top:5px"><i class="fas fa-clock" style="margin-right:3px"></i><?= timeAgo($n['created_at']) ?></div>
      </div>
      <?php if ($n['action_url']): ?>
      <a href="<?= e($n['action_url']) ?>" class="btn btn-ghost btn-sm" style="flex-shrink:0;align-self:center" title="Voir le détail">
        <i class="fas fa-arrow-right"></i>
      </a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php renderFooter(); ?>
