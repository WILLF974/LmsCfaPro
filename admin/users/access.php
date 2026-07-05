<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo    = getDB();
$userId = (int)($_GET['id'] ?? 0);
if (!$userId) { setFlash('error', 'Utilisateur introuvable.'); redirect(url('admin/users/index.php')); }

$student = $pdo->prepare('SELECT * FROM users WHERE id = ? AND role = "student"');
$student->execute([$userId]);
$student = $student->fetch();
if (!$student) { setFlash('error', 'Utilisateur introuvable.'); redirect(url('admin/users/index.php')); }

// ── Inscriptions formation ─────────────────────────────────────────────────────
$enrollments = $pdo->prepare("
    SELECT e.*, f.title as formation_title
    FROM enrollments e JOIN formations f ON e.formation_id=f.id
    WHERE e.user_id=? ORDER BY e.enrolled_at DESC
");
$enrollments->execute([$userId]);
$enrollments = $enrollments->fetchAll();

// ── Accès grants actifs ────────────────────────────────────────────────────────
$grants = $pdo->prepare("
    SELECT ag.*, gb.first_name AS gb_first, gb.last_name AS gb_last
    FROM access_grants ag
    LEFT JOIN users gb ON ag.granted_by = gb.id
    WHERE ag.user_id = ? AND ag.revoked_at IS NULL
    ORDER BY ag.granted_at DESC
");
$grants->execute([$userId]);
$grants = $grants->fetchAll();

// Résoudre les libellés et l'ascendance
$scopeInfo = [];
foreach ($grants as $g) {
    $info = ['label' => '', 'breadcrumb' => []];
    switch ($g['scope_type']) {
        case 'rncp_title':
            $r = $pdo->prepare("SELECT rncp_code, title FROM rncp_titles WHERE id=?"); $r->execute([$g['scope_id']]);
            if ($row = $r->fetch()) {
                $info['label'] = $row['rncp_code'] . ' — ' . $row['title'];
                $info['breadcrumb'] = [['RNCP', $row['rncp_code'] . ' ' . $row['title']]];
            }
            break;
        case 'activity_type':
            $r = $pdo->prepare("SELECT at.code, at.title, rt.rncp_code FROM activity_types at JOIN rncp_titles rt ON at.rncp_title_id=rt.id WHERE at.id=?"); $r->execute([$g['scope_id']]);
            if ($row = $r->fetch()) {
                $info['label'] = $row['code'] . ' — ' . $row['title'];
                $info['breadcrumb'] = [['RNCP', $row['rncp_code']], ['AT', $row['code'] . ' ' . $row['title']]];
            }
            break;
        case 'competency':
            $r = $pdo->prepare("SELECT c.code, c.title, at.code AS at_code, rt.rncp_code FROM competencies c JOIN activity_types at ON c.activity_type_id=at.id JOIN rncp_titles rt ON at.rncp_title_id=rt.id WHERE c.id=?"); $r->execute([$g['scope_id']]);
            if ($row = $r->fetch()) {
                $info['label'] = $row['code'] . ' — ' . $row['title'];
                $info['breadcrumb'] = [['RNCP', $row['rncp_code']], ['AT', $row['at_code']], ['Comp', $row['code'] . ' ' . $row['title']]];
            }
            break;
        case 'sequence':
            $r = $pdo->prepare("SELECT s.title, c.code AS comp_code, at.code AS at_code, rt.rncp_code FROM sequences s JOIN competencies c ON s.competency_id=c.id JOIN activity_types at ON c.activity_type_id=at.id JOIN rncp_titles rt ON at.rncp_title_id=rt.id WHERE s.id=?"); $r->execute([$g['scope_id']]);
            if ($row = $r->fetch()) {
                $info['label'] = $row['title'];
                $info['breadcrumb'] = [['RNCP', $row['rncp_code']], ['AT', $row['at_code']], ['Comp', $row['comp_code']], ['Séq', $row['title']]];
            }
            break;
        case 'module':
            $r = $pdo->prepare("SELECT m.title, s.title AS seq_title, c.code AS comp_code, at.code AS at_code, rt.rncp_code FROM modules m LEFT JOIN sequences s ON m.sequence_id=s.id LEFT JOIN competencies c ON s.competency_id=c.id LEFT JOIN activity_types at ON c.activity_type_id=at.id LEFT JOIN rncp_titles rt ON at.rncp_title_id=rt.id WHERE m.id=?"); $r->execute([$g['scope_id']]);
            if ($row = $r->fetch()) {
                $info['label'] = $row['title'];
                $info['breadcrumb'] = array_filter([
                    $row['rncp_code']   ? ['RNCP', $row['rncp_code']]    : null,
                    $row['at_code']     ? ['AT',   $row['at_code']]       : null,
                    $row['comp_code']   ? ['Comp',  $row['comp_code']]    : null,
                    $row['seq_title']   ? ['Séq',   $row['seq_title']]    : null,
                    ['Séance', $row['title']],
                ]);
            }
            break;
    }
    $scopeInfo[$g['id']] = $info;
}

// ── Progression module_progress ────────────────────────────────────────────────
$progStmt = $pdo->prepare("
    SELECT mp.module_id, mp.status
    FROM module_progress mp WHERE mp.user_id=?
");
$progStmt->execute([$userId]);
$progress = array_column($progStmt->fetchAll(), 'status', 'module_id');

$scopeColors = [
    'rncp_title'    => ['color' => '#a78bfa', 'bg' => 'rgba(139,92,246,.15)', 'label' => 'RNCP entier',   'icon' => 'certificate'],
    'activity_type' => ['color' => '#f59e0b', 'bg' => 'rgba(245,158,11,.15)', 'label' => 'Activité type', 'icon' => 'layer-group'],
    'competency'    => ['color' => '#ef4444', 'bg' => 'rgba(239,68,68,.15)',  'label' => 'Compétence',    'icon' => 'bullseye'],
    'sequence'      => ['color' => '#10b981', 'bg' => 'rgba(16,185,129,.15)', 'label' => 'Séquence',      'icon' => 'list-ol'],
    'module'        => ['color' => '#0ea5e9', 'bg' => 'rgba(14,165,233,.15)', 'label' => 'Séance',        'icon' => 'play-circle'],
];

$name = e($student['first_name'] . ' ' . $student['last_name']);
renderHead('Accès — ' . strip_tags($name));
renderSidebar('pedagogy');
renderTopbar('Accès — ' . $name, [
    ['Pédagogie', url('pedagogy/index.php')],
    ['Apprenants', url('admin/users/index.php?role=student')],
    [$name, url('admin/users/progress.php?id=' . $userId)],
    ['Accès', ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- En-tête apprenant -->
  <div class="card" style="margin-bottom:20px;padding:18px 20px">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
      <div style="width:46px;height:46px;border-radius:50%;background:rgba(99,102,241,.15);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:#6366f1;flex-shrink:0">
        <?= e(mb_strtoupper(mb_substr($student['first_name'],0,1).mb_substr($student['last_name'],0,1))) ?>
      </div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:16px"><?= $name ?></div>
        <div style="font-size:12px;color:var(--text-muted)"><?= e($student['email']) ?></div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= url('admin/users/progress.php?id='.$userId) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-chart-line"></i> Suivi pédagogique</a>
        <a href="<?= url('pedagogy/access/create.php?user_id='.$userId) ?>" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Donner un accès</a>
      </div>
    </div>
  </div>

  <!-- Inscriptions formations -->
  <div style="margin-bottom:24px">
    <div style="font-weight:700;font-size:13px;margin-bottom:12px;display:flex;align-items:center;gap:8px">
      <i class="fas fa-graduation-cap" style="color:#0ea5e9"></i> Inscriptions formations
      <span style="background:rgba(14,165,233,.15);color:#0ea5e9;border-radius:20px;padding:1px 8px;font-size:11px"><?= count($enrollments) ?></span>
    </div>
    <?php if (empty($enrollments)): ?>
    <div style="font-size:13px;color:var(--text-muted);font-style:italic">Aucune inscription à une formation.</div>
    <?php else: ?>
    <div style="display:grid;gap:8px">
      <?php foreach ($enrollments as $e): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;background:rgba(14,165,233,.05);border:1px solid rgba(14,165,233,.2);border-radius:var(--radius-lg);padding:10px 14px;gap:10px;flex-wrap:wrap">
        <div>
          <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($e['formation_title'], ENT_QUOTES) ?></div>
          <div style="font-size:11px;color:var(--text-muted)">Inscrit le <?= formatDate($e['enrolled_at']) ?></div>
        </div>
        <?php
        $stBadge = ['active'=>['#10b981','Actif'],'completed'=>['#6366f1','Terminé'],'pending'=>['#f59e0b','En attente'],'suspended'=>['#ef4444','Suspendu']];
        [$sc,$sl] = $stBadge[$e['status']] ?? ['#64748b',$e['status']];
        ?>
        <span style="background:<?= $sc ?>22;color:<?= $sc ?>;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700"><?= $sl ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Accès par périmètre -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
    <div style="font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px">
      <i class="fas fa-key" style="color:#a78bfa"></i> Accès par périmètre
      <span style="background:rgba(139,92,246,.15);color:#a78bfa;border-radius:20px;padding:1px 8px;font-size:11px"><?= count($grants) ?></span>
    </div>
    <a href="<?= url('pedagogy/access/create.php?user_id='.$userId) ?>" class="btn btn-ghost btn-sm" style="color:var(--primary-light)">
      <i class="fas fa-plus"></i> Ajouter
    </a>
  </div>

  <?php if (empty($grants)): ?>
  <div style="background:rgba(255,255,255,.03);border:1px dashed var(--border-color);border-radius:var(--radius-lg);padding:20px;text-align:center;color:var(--text-muted);font-size:13px">
    Aucun accès par périmètre accordé.
    <a href="<?= url('pedagogy/access/create.php?user_id='.$userId) ?>" style="color:var(--primary-light);margin-left:6px">Donner un accès →</a>
  </div>
  <?php else: ?>
  <div style="display:grid;gap:10px">
    <?php foreach ($grants as $g):
      $st   = $scopeColors[$g['scope_type']] ?? ['color'=>'#64748b','bg'=>'rgba(100,116,139,.15)','label'=>$g['scope_type'],'icon'=>'circle'];
      $info = $scopeInfo[$g['id']] ?? ['label'=>'#'.$g['scope_id'],'breadcrumb'=>[]];
    ?>
    <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:14px 16px">
      <div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap">
        <!-- Badge niveau -->
        <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border-radius:20px;padding:3px 11px;font-size:11px;font-weight:700;white-space:nowrap;flex-shrink:0">
          <i class="fas fa-<?= $st['icon'] ?>"></i> <?= $st['label'] ?>
        </span>
        <!-- Libellé + breadcrumb -->
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:13px;margin-bottom:4px"><?= htmlspecialchars($info['label'], ENT_QUOTES) ?></div>
          <?php if (!empty($info['breadcrumb'])): ?>
          <div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap">
            <?php foreach ($info['breadcrumb'] as $i => [$crumbType, $crumbLabel]): ?>
            <?php if ($i > 0): ?><i class="fas fa-chevron-right" style="font-size:8px;color:var(--text-muted)"></i><?php endif; ?>
            <span style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($crumbType.': '.$crumbLabel, ENT_QUOTES) ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
            Accordé le <?= formatDate($g['granted_at']) ?>
            <?= $g['gb_first'] ? ' par ' . htmlspecialchars($g['gb_first'].' '.$g['gb_last'], ENT_QUOTES) : '' ?>
            <?= $g['notes'] ? ' · <em>' . htmlspecialchars($g['notes'], ENT_QUOTES) . '</em>' : '' ?>
          </div>
        </div>
        <!-- Révoquer -->
        <form method="POST" action="<?= url('pedagogy/access/revoke.php') ?>" onsubmit="return confirm('Révoquer cet accès ?')" style="flex-shrink:0">
          <?= csrfField() ?>
          <input type="hidden" name="grant_id" value="<?= $g['id'] ?>">
          <input type="hidden" name="redirect_to" value="admin/users/access.php?id=<?= $userId ?>">
          <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="Révoquer"><i class="fas fa-ban"></i></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
<?php renderFooter(); ?>
