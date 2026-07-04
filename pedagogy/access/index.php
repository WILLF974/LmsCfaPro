<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();

// ── Filtres ────────────────────────────────────────────────────────────────────
$studentId = (int)($_GET['student_id'] ?? 0);
$where     = 'ag.revoked_at IS NULL';
$params    = [];
if ($studentId) { $where .= ' AND ag.user_id = ?'; $params[] = $studentId; }

// ── Données ────────────────────────────────────────────────────────────────────
$grants = $pdo->prepare("
    SELECT ag.*,
           u.first_name, u.last_name, u.email,
           gb.first_name AS gb_first, gb.last_name AS gb_last
    FROM access_grants ag
    JOIN users u  ON ag.user_id    = u.id
    LEFT JOIN users gb ON ag.granted_by = gb.id
    WHERE $where
    ORDER BY ag.granted_at DESC
");
$grants->execute($params);
$grants = $grants->fetchAll();

$students = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='student' AND status='active' ORDER BY last_name, first_name")->fetchAll();

// Résoudre les libellés de scope
$scopeLabels = [];
foreach ($grants as $g) {
    $k = $g['scope_type'] . ':' . $g['scope_id'];
    if (!isset($scopeLabels[$k])) {
        $label = '';
        switch ($g['scope_type']) {
            case 'rncp_title':
                $r = $pdo->prepare("SELECT rncp_code, title FROM rncp_titles WHERE id=?"); $r->execute([$g['scope_id']]);
                if ($row = $r->fetch()) $label = $row['rncp_code'] . ' — ' . $row['title'];
                break;
            case 'activity_type':
                $r = $pdo->prepare("SELECT code, title FROM activity_types WHERE id=?"); $r->execute([$g['scope_id']]);
                if ($row = $r->fetch()) $label = $row['code'] . ' — ' . $row['title'];
                break;
            case 'competency':
                $r = $pdo->prepare("SELECT code, title FROM competencies WHERE id=?"); $r->execute([$g['scope_id']]);
                if ($row = $r->fetch()) $label = $row['code'] . ' — ' . $row['title'];
                break;
            case 'sequence':
                $r = $pdo->prepare("SELECT title FROM sequences WHERE id=?"); $r->execute([$g['scope_id']]);
                if ($row = $r->fetch()) $label = $row['title'];
                break;
            case 'module':
                $r = $pdo->prepare("SELECT title FROM modules WHERE id=?"); $r->execute([$g['scope_id']]);
                if ($row = $r->fetch()) $label = $row['title'];
                break;
        }
        $scopeLabels[$k] = $label ?: '#' . $g['scope_id'];
    }
}

$scopeColors = [
    'rncp_title'    => ['color' => '#a78bfa', 'bg' => 'rgba(139,92,246,.15)', 'label' => 'RNCP',       'icon' => 'certificate'],
    'activity_type' => ['color' => '#f59e0b', 'bg' => 'rgba(245,158,11,.15)', 'label' => 'Activité',   'icon' => 'layer-group'],
    'competency'    => ['color' => '#ef4444', 'bg' => 'rgba(239,68,68,.15)',  'label' => 'Compétence', 'icon' => 'bullseye'],
    'sequence'      => ['color' => '#10b981', 'bg' => 'rgba(16,185,129,.15)', 'label' => 'Séquence',   'icon' => 'list-ol'],
    'module'        => ['color' => '#0ea5e9', 'bg' => 'rgba(14,165,233,.15)', 'label' => 'Séance',     'icon' => 'play-circle'],
];

renderHead('Accès ressources');
renderSidebar('pedagogy');
renderTopbar('Accès aux ressources', [['Pédagogie', url('pedagogy/index.php')], ['Accès', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header" style="margin-bottom:20px">
    <div>
      <h1><i class="fas fa-key" style="color:var(--primary-light);margin-right:10px"></i>Accès aux ressources</h1>
      <p>Gérez les accès directs des apprenants aux ressources pédagogiques.</p>
    </div>
    <a href="<?= url('pedagogy/access/create.php') ?>" class="btn btn-primary">
      <i class="fas fa-plus"></i> Donner un accès
    </a>
  </div>

  <!-- Filtre apprenant -->
  <div class="card" style="margin-bottom:16px;padding:14px 18px">
    <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <label style="font-size:12px;font-weight:600;white-space:nowrap">Filtrer par apprenant :</label>
      <select name="student_id" class="form-control" style="max-width:280px">
        <option value="">— Tous les apprenants —</option>
        <?php foreach ($students as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $studentId==$s['id']?'selected':'' ?>><?= e($s['last_name'].' '.$s['first_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-secondary">Filtrer</button>
      <?php if ($studentId): ?><a href="<?= url('pedagogy/access/index.php') ?>" class="btn btn-ghost">Réinitialiser</a><?php endif; ?>
    </form>
  </div>

  <?php if (empty($grants)): ?>
  <div class="empty-state">
    <div class="icon"><i class="fas fa-key"></i></div>
    <h3>Aucun accès accordé</h3>
    <p>Les accès permettent à un apprenant d'accéder à des ressources sans inscription à une formation complète.</p>
    <a href="<?= url('pedagogy/access/create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Donner un accès</a>
  </div>
  <?php else: ?>
  <div class="card" style="overflow:hidden">
    <table class="table">
      <thead>
        <tr>
          <th>Apprenant</th>
          <th>Niveau d'accès</th>
          <th>Périmètre</th>
          <th>Accordé par</th>
          <th>Date</th>
          <th>Note</th>
          <th style="width:80px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($grants as $g):
          $st = $scopeColors[$g['scope_type']] ?? ['color'=>'#64748b','bg'=>'rgba(100,116,139,.15)','label'=>$g['scope_type'],'icon'=>'circle'];
          $lbl = $scopeLabels[$g['scope_type'].':'.$g['scope_id']];
        ?>
        <tr>
          <td>
            <div style="font-weight:600"><?= e($g['last_name'].' '.$g['first_name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= e($g['email']) ?></div>
          </td>
          <td>
            <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700">
              <i class="fas fa-<?= $st['icon'] ?>"></i> <?= $st['label'] ?>
            </span>
          </td>
          <td style="max-width:280px">
            <div style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= e($lbl) ?>"><?= e($lbl) ?></div>
          </td>
          <td style="font-size:12px;color:var(--text-secondary)"><?= $g['gb_first'] ? e($g['gb_first'].' '.$g['gb_last']) : '—' ?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= formatDate($g['granted_at']) ?></td>
          <td style="font-size:11px;color:var(--text-muted);max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($g['notes'] ?? '') ?></td>
          <td>
            <form method="POST" action="<?= url('pedagogy/access/revoke.php') ?>" onsubmit="return confirm('Révoquer cet accès ?')">
              <?= csrfField() ?>
              <input type="hidden" name="grant_id" value="<?= $g['id'] ?>">
              <input type="hidden" name="redirect_to" value="pedagogy/access/index.php<?= $studentId ? '?student_id='.$studentId : '' ?>">
              <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="Révoquer l'accès"><i class="fas fa-ban"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="font-size:12px;color:var(--text-muted);margin-top:8px"><?= count($grants) ?> accès actif<?= count($grants)>1?'s':'' ?></div>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
