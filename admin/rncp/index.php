<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$where = ['1=1']; $params = [];
if ($search) {
    $where[] = '(r.rncp_code LIKE ? OR r.title LIKE ? OR r.certifier LIKE ?)';
    $like = "%$search%"; $params = [$like,$like,$like];
}
$whereStr = implode(' AND ', $where);
$total = $pdo->prepare("SELECT COUNT(*) FROM rncp_titles r WHERE $whereStr");
$total->execute($params);
$p = paginate((int)$total->fetchColumn(), 15, $page);

$stmt = $pdo->prepare("
    SELECT r.*,
           u.first_name, u.last_name,
           (SELECT COUNT(*) FROM activity_types a WHERE a.rncp_title_id=r.id) as at_count,
           (SELECT COUNT(*) FROM formations f WHERE f.rncp_title_id=r.id) as formation_count
    FROM rncp_titles r
    LEFT JOIN users u ON r.created_by=u.id
    WHERE $whereStr ORDER BY r.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
$titles = $stmt->fetchAll();

renderHead('Titres RNCP');
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar('Titres RNCP', [['Admin', url('admin/index.php')], ['Titres RNCP', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>
  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div><h1>Titres RNCP</h1><p>Référentiel des certifications professionnelles</p></div>
      <div style="display:flex;gap:8px">
        <a href="<?= url('admin/rncp/import.php') ?>" class="btn btn-secondary"><i class="fas fa-file-import"></i> Importer un REAC</a>
        <a href="<?= url('admin/rncp/create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter un titre</a>
      </div>
    </div>
  </div>

  <!-- Search -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <form method="GET" style="display:flex;gap:12px">
        <div class="search-input" style="flex:1">
          <i class="fas fa-search"></i>
          <input type="text" name="q" placeholder="Chercher par code RNCP, intitulé, certificateur..." value="<?= e($search) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Chercher</button>
      </form>
    </div>
  </div>

  <!-- Grid -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px">
    <?php foreach ($titles as $t): ?>
    <div class="card" style="transition:all .2s">
      <div class="card-body">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px">
          <div style="flex:1">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
              <span class="badge badge-primary"><?= e($t['rncp_code']) ?></span>
              <span class="badge badge-info">Niveau <?= e($t['level']) ?></span>
              <?= getStatusBadge($t['status']) ?>
            </div>
            <h3 style="font-size:15px;font-weight:700;color:white;line-height:1.4;margin-bottom:6px"><?= e($t['title']) ?></h3>
            <?php if ($t['certifier']): ?><p style="font-size:12px;color:var(--text-muted)"><?= e($t['certifier']) ?></p><?php endif; ?>
          </div>
          <div style="width:52px;height:52px;border-radius:var(--radius-lg);background:linear-gradient(135deg,rgba(99,102,241,.2),rgba(139,92,246,.15));border:1px solid rgba(99,102,241,.3);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0">🎓</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px">
          <div style="text-align:center;background:var(--bg-elevated);border-radius:var(--radius);padding:10px">
            <div style="font-size:18px;font-weight:800;color:white"><?= $t['at_count'] ?></div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px">Blocs</div>
          </div>
          <div style="text-align:center;background:var(--bg-elevated);border-radius:var(--radius);padding:10px">
            <div style="font-size:18px;font-weight:800;color:white"><?= $t['formation_count'] ?></div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px">Formations</div>
          </div>
          <div style="text-align:center;background:var(--bg-elevated);border-radius:var(--radius);padding:10px">
            <div style="font-size:18px;font-weight:800;color:white"><?= $t['duration_hours'] ?? '–' ?></div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px">Heures</div>
          </div>
        </div>

        <?php if ($t['expiry_date']): ?>
        <?php $expired = strtotime($t['expiry_date']) < time(); ?>
        <div style="font-size:12px;color:<?= $expired ? 'var(--danger)' : 'var(--text-muted)' ?>;margin-bottom:12px">
          <i class="fas fa-calendar"></i> Expiration : <?= formatDate($t['expiry_date']) ?>
          <?= $expired ? '<span class="badge badge-danger" style="margin-left:6px">Expiré</span>' : '' ?>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:8px">
          <a href="<?= url('admin/rncp/view.php?id='.$t['id']) ?>" class="btn btn-primary btn-sm" style="flex:1;justify-content:center">
            <i class="fas fa-sitemap"></i> Voir la structure
          </a>
          <a href="<?= url('admin/rncp/edit.php?id='.$t['id']) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
          <a href="<?= url('admin/formations/create.php?rncp_id='.$t['id']) ?>" class="btn btn-ghost btn-sm" title="Créer une formation"><i class="fas fa-plus"></i></a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($titles)): ?>
    <div style="grid-column:1/-1">
      <div class="empty-state">
        <div class="icon">🎓</div>
        <h3>Aucun titre RNCP</h3>
        <p>Commencez par ajouter votre premier titre RNCP.</p>
        <a href="<?= url('admin/rncp/create.php') ?>" class="btn btn-primary">Ajouter un titre RNCP</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?= $p['totalPages'] > 1 ? renderPagination($p, url('admin/rncp/index.php?q='.urlencode($search))) : '' ?>
</div>
<?php renderFooter(); ?>
