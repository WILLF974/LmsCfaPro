<?php
require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (($_POST['action'] ?? '') === 'delete') {
        $cId = (int)($_POST['cohort_id'] ?? 0);
        if ($cId) {
            $pdo->prepare("DELETE FROM cohorts WHERE id=?")->execute([$cId]);
            auditLog('cohort_deleted', 'cohort', $cId);
            setFlash('success', 'Cohorte supprimée.');
        }
    }
    redirect(url('pedagogy/cohorts/index.php'));
}

$cohorts = $pdo->query("
    SELECT c.*,
           rt.rncp_code, rt.title as rncp_title,
           COUNT(DISTINCT cm.student_id) as member_count
    FROM cohorts c
    LEFT JOIN rncp_titles rt ON c.rncp_title_id = rt.id
    LEFT JOIN cohort_members cm ON cm.cohort_id = c.id
    GROUP BY c.id
    ORDER BY c.year DESC, c.name
")->fetchAll();

renderHead('Cohortes');
renderSidebar('pedagogy');
renderTopbar('Cohortes', [['Pédagogie', url('pedagogy/index.php')], ['Cohortes', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div>
        <h1>Cohortes</h1>
        <p><?= count($cohorts) ?> cohorte<?= count($cohorts) > 1 ? 's' : '' ?></p>
      </div>
      <a href="<?= url('pedagogy/cohorts/create.php') ?>" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouvelle cohorte
      </a>
    </div>
  </div>

  <?php if (empty($cohorts)): ?>
  <div class="empty-state">
    <div class="icon"><i class="fas fa-layer-group"></i></div>
    <h3>Aucune cohorte</h3>
    <p>Créez votre première cohorte pour regrouper les apprenants par promotion.</p>
    <a href="<?= url('pedagogy/cohorts/create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Créer une cohorte</a>
  </div>
  <?php else: ?>
  <div class="card">
    <div class="table-container">
      <table class="table">
        <thead>
          <tr>
            <th>Cohorte</th>
            <th>Titre RNCP</th>
            <th style="text-align:center">Promotion</th>
            <th style="text-align:center">Apprenants</th>
            <th>Créée le</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cohorts as $c): ?>
          <tr>
            <td>
              <div style="font-weight:700;color:white"><?= e($c['name']) ?></div>
              <?php if ($c['description']): ?>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= e(mb_substr($c['description'],0,60)) ?><?= mb_strlen($c['description'])>60?'…':'' ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($c['rncp_code']): ?>
              <span class="badge badge-primary" style="font-size:11px"><?= e($c['rncp_code']) ?></span>
              <div style="font-size:11px;color:var(--text-muted);margin-top:3px"><?= e(mb_substr($c['rncp_title'],0,45)) ?><?= mb_strlen($c['rncp_title'])>45?'…':'' ?></div>
              <?php else: ?>
              <span style="color:var(--text-faint);font-size:12px">—</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php if ($c['year']): ?>
              <span class="badge badge-secondary"><?= $c['year'] ?></span>
              <?php else: ?><span style="color:var(--text-faint)">—</span><?php endif; ?>
            </td>
            <td style="text-align:center">
              <span style="font-size:16px;font-weight:800;color:white"><?= $c['member_count'] ?></span>
            </td>
            <td style="font-size:12px;color:var(--text-muted)"><?= formatDate($c['created_at']) ?></td>
            <td style="text-align:right">
              <div style="display:flex;gap:6px;justify-content:flex-end">
                <a href="<?= url('pedagogy/cohorts/view.php?id='.$c['id']) ?>" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> Voir</a>
                <a href="<?= url('pedagogy/cohorts/create.php?id='.$c['id']) ?>" class="btn btn-ghost btn-sm" title="Modifier"><i class="fas fa-edit"></i></a>
                <form method="POST" onsubmit="return confirm('Supprimer la cohorte « <?= e(addslashes($c['name'])) ?> » ?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="cohort_id" value="<?= $c['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="Supprimer"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
