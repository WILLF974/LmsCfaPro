<?php
require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/layout.php';
requireTeacher();

$pdo = getDB();

try {
    $cohorts = $pdo->query("
        SELECT c.*,
               rt.rncp_code, rt.title as rncp_title,
               COUNT(DISTINCT cm.student_id) as member_count,
               (SELECT COUNT(*) FROM agenda_entries ae
                WHERE ae.cohort_id = c.id
                  AND MONTH(ae.scheduled_date) = MONTH(NOW())
                  AND YEAR(ae.scheduled_date) = YEAR(NOW())) as entries_this_month,
               (SELECT MAX(ae2.scheduled_date) FROM agenda_entries ae2 WHERE ae2.cohort_id = c.id) as last_entry_date
        FROM cohorts c
        LEFT JOIN rncp_titles rt ON c.rncp_title_id = rt.id
        LEFT JOIN cohort_members cm ON cm.cohort_id = c.id AND cm.excluded_from_stats = 0
        GROUP BY c.id
        ORDER BY c.year DESC, c.name
    ")->fetchAll();
} catch (PDOException $e) {
    $cohorts = [];
}

renderHead('Cahier de texte');
renderSidebar('teacher');
renderTopbar('Cahier de texte', [['Cahier de texte', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header">
    <div>
      <h1><i class="fas fa-book-open" style="color:var(--primary-light);margin-right:10px"></i>Cahier de texte</h1>
      <p>Ajoutez et consultez les notes par cohorte</p>
    </div>
  </div>

  <?php if (empty($cohorts)): ?>
  <div class="empty-state">
    <div class="icon"><i class="fas fa-book-open"></i></div>
    <h3>Aucune cohorte</h3>
    <p>Les cohortes sont créées et gérées par le compte pédagogie. Rapprochez-vous de l'équipe pédagogique.</p>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px">
    <?php foreach ($cohorts as $c):
      $hasActivity = !empty($c['last_entry_date']);
      $isRecent    = $hasActivity && strtotime($c['last_entry_date']) > strtotime('-30 days');
    ?>
    <div class="card">
      <div class="card-body" style="padding:18px 20px">
        <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap">
          <?php if ($c['rncp_code']): ?>
          <span class="badge badge-primary"><?= e($c['rncp_code']) ?></span>
          <?php endif; ?>
          <?php if ($c['year']): ?>
          <span class="badge badge-secondary"><?= $c['year'] ?></span>
          <?php endif; ?>
          <?php if ($isRecent): ?>
          <span class="badge" style="background:rgba(52,211,153,.15);color:#34d399;border:1px solid rgba(52,211,153,.3)"><i class="fas fa-circle" style="font-size:7px"></i> Actif</span>
          <?php endif; ?>
        </div>

        <div style="font-weight:700;font-size:15px;color:white;margin-bottom:3px"><?= e($c['name']) ?></div>
        <?php if ($c['rncp_title']): ?>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px"><?= e(mb_substr($c['rncp_title'],0,58)) ?><?= mb_strlen($c['rncp_title'])>58?'…':'' ?></div>
        <?php endif; ?>

        <div style="display:flex;gap:16px;font-size:12px;color:var(--text-faint);margin-bottom:14px">
          <span><i class="fas fa-users" style="color:var(--primary-light)"></i> <?= $c['member_count'] ?> apprenant<?= $c['member_count']!=1?'s':'' ?></span>
          <span><i class="fas fa-calendar-check" style="color:var(--success)"></i>
            <?php if ($c['entries_this_month'] > 0): ?>
            <strong style="color:var(--success)"><?= $c['entries_this_month'] ?></strong> note<?= $c['entries_this_month']!=1?'s':'' ?> ce mois
            <?php else: ?>
            Aucune note ce mois
            <?php endif; ?>
          </span>
        </div>

        <?php if ($hasActivity): ?>
        <div style="font-size:11px;color:var(--text-faint);margin-bottom:12px">
          <i class="fas fa-clock"></i> Dernière note : <?= formatDate($c['last_entry_date']) ?>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:8px">
          <a href="<?= url('pedagogy/cohorts/agenda.php?id='.$c['id']) ?>" class="btn btn-primary btn-sm" style="flex:1;justify-content:center">
            <i class="fas fa-book-open"></i> Cahier
          </a>
          <a href="<?= url('pedagogy/cohorts/kanban.php?id='.$c['id']) ?>" class="btn btn-secondary btn-sm" style="flex:1;justify-content:center">
            <i class="fas fa-columns"></i> Kanban
          </a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
