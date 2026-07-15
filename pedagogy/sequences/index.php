<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();

// ── Toggle publication ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $seqId  = (int)($_POST['seq_id'] ?? 0);
    if ($action === 'toggle_publish' && $seqId) {
        $pdo->prepare("UPDATE sequences SET is_published = 1 - is_published WHERE id = ?")->execute([$seqId]);
    }
    redirect(url('pedagogy/sequences/index.php'));
}

// ── Filtres ───────────────────────────────────────────────────────────────────
$rncpId  = (int)($_GET['rncp_id']  ?? 0);
$atId    = (int)($_GET['at_id']    ?? 0);
$compId  = (int)($_GET['comp_id']  ?? 0);
$search  = trim($_GET['q'] ?? '');
$hasFilters = $rncpId || $atId || $compId || $search;

$where  = ['1=1'];
$params = [];
if ($rncpId) { $where[] = 'at.rncp_title_id = ?'; $params[] = $rncpId; }
if ($atId)   { $where[] = 'c.activity_type_id = ?'; $params[] = $atId; }
if ($compId) { $where[] = 'seq.competency_id = ?'; $params[] = $compId; }
if ($search) { $where[] = 'seq.title LIKE ?'; $params[] = '%' . $search . '%'; }

$sequences = $pdo->prepare("
    SELECT seq.id, seq.title, seq.description, seq.order_num, seq.created_by,
           seq.competency_id, seq.formation_id, seq.is_published, seq.scenario_path,
           c.code AS comp_code, c.title AS comp_title,
           at.id AS at_id, at.code AS at_code, at.title AS at_title,
           rt.id AS rncp_id, rt.rncp_code, rt.title AS rncp_title,
           f.title AS formation_title,
           u.first_name, u.last_name, u.role AS creator_role,
           (SELECT COUNT(*) FROM modules m WHERE m.sequence_id = seq.id) AS nb_seances
    FROM sequences seq
    LEFT JOIN competencies c  ON seq.competency_id = c.id
    LEFT JOIN activity_types at ON c.activity_type_id = at.id
    LEFT JOIN rncp_titles rt ON at.rncp_title_id = rt.id
    LEFT JOIN formations f ON seq.formation_id = f.id
    LEFT JOIN users u ON seq.created_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY rt.rncp_code, at.order_num, c.order_num, seq.order_num, seq.title
");
$sequences->execute($params);
$sequences = $sequences->fetchAll();

// Dropdowns filtres
$filterRncps = $pdo->query("
    SELECT DISTINCT rt.id, rt.rncp_code, rt.title
    FROM rncp_titles rt
    JOIN activity_types at ON at.rncp_title_id = rt.id
    JOIN competencies c ON c.activity_type_id = at.id
    JOIN sequences seq ON seq.competency_id = c.id
    ORDER BY rt.rncp_code
")->fetchAll();

$filterATs = [];
if ($rncpId) {
    $s = $pdo->prepare("SELECT id, code, title FROM activity_types WHERE rncp_title_id = ? ORDER BY order_num, code");
    $s->execute([$rncpId]); $filterATs = $s->fetchAll();
}
$filterComps = [];
if ($atId) {
    $s = $pdo->prepare("SELECT id, code, title FROM competencies WHERE activity_type_id = ? ORDER BY order_num, code");
    $s->execute([$atId]); $filterComps = $s->fetchAll();
}

// Stats globales
$stats = $pdo->query("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN created_by IS NOT NULL THEN 1 ELSE 0 END) AS by_teachers,
      COUNT(DISTINCT competency_id) AS nb_comps
    FROM sequences
")->fetch();


renderHead('Séquences — Pédagogie');
renderSidebar('pedagogy');
renderTopbar('Séquences pédagogiques', [
    ['Pédagogie', url('pedagogy/index.php')],
    ['Séquences', ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Action rapide -->
  <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
    <a href="<?= url('pedagogy/sequences/create.php') ?>" class="btn btn-primary btn-sm">
      <i class="fas fa-plus"></i> Nouvelle séquence
    </a>
  </div>

  <!-- Stats + action doublons -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:18px">
    <?php foreach ([
      ['fas fa-list-ol',            '#10b981', 'Séquences',               $stats['total']],
      ['fas fa-bullseye',           '#ef4444', 'Compétences couvertes',   $stats['nb_comps']],
      ['fas fa-chalkboard-teacher', '#8b5cf6', 'Créées par enseignants',  $stats['by_teachers']],
    ] as [$icon, $color, $label, $val]): ?>
    <div class="card">
      <div class="card-body" style="padding:14px 16px;display:flex;align-items:center;gap:12px">
        <div style="width:36px;height:36px;border-radius:9px;background:<?= $color ?>20;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="<?= $icon ?>" style="color:<?= $color ?>;font-size:15px"></i>
        </div>
        <div>
          <div style="font-weight:800;font-size:20px;color:var(--text-primary);line-height:1"><?= $val ?></div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= $label ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

  </div>

  <!-- Filtres -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-body" style="padding:16px 20px">
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div style="min-width:200px;flex:2">
          <label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">Recherche</label>
          <input type="text" name="q" class="form-control" style="font-size:12px" placeholder="Titre…" value="<?= e($search) ?>">
        </div>
        <div style="min-width:200px;flex:1">
          <label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
            <i class="fas fa-certificate" style="color:#8b5cf6;margin-right:3px"></i>RNCP
          </label>
          <select name="rncp_id" class="form-control" style="font-size:12px" onchange="this.form.at_id.value='';this.form.comp_id.value='';this.form.submit()">
            <option value="">— Tous —</option>
            <?php foreach ($filterRncps as $r): ?>
            <option value="<?= $r['id'] ?>" <?= $rncpId==$r['id']?'selected':'' ?>>
              <?= e($r['rncp_code']) ?> — <?= e($r['title']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (!empty($filterATs)): ?>
        <div style="min-width:200px;flex:1">
          <label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
            <i class="fas fa-layer-group" style="color:#f59e0b;margin-right:3px"></i>Activité type
          </label>
          <select name="at_id" class="form-control" style="font-size:12px" onchange="this.form.comp_id.value='';this.form.submit()">
            <option value="">— Toutes —</option>
            <?php foreach ($filterATs as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $atId==$a['id']?'selected':'' ?>><?= e($a['code']) ?> — <?= e($a['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if (!empty($filterComps)): ?>
        <div style="min-width:200px;flex:1">
          <label style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
            <i class="fas fa-bullseye" style="color:#ef4444;margin-right:3px"></i>Compétence
          </label>
          <select name="comp_id" class="form-control" style="font-size:12px" onchange="this.form.submit()">
            <option value="">— Toutes —</option>
            <?php foreach ($filterComps as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $compId==$c['id']?'selected':'' ?>><?= e($c['code']) ?> — <?= e($c['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:6px;align-self:flex-end">
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtrer</button>
          <?php if ($hasFilters): ?>
          <a href="<?= url('pedagogy/sequences/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Réinitialiser</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <?php if (empty($sequences)): ?>
  <div class="card">
    <div class="card-body" style="padding:40px;text-align:center;color:var(--text-muted)">
      <i class="fas fa-list-ol" style="font-size:36px;margin-bottom:12px;display:block;opacity:.3"></i>
      <?= $hasFilters ? 'Aucune séquence trouvée pour ces critères.' : 'Aucune séquence dans la base.' ?>
    </div>
  </div>
  <?php else: ?>
  <div class="card">
    <div class="card-body" style="padding:0">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color)">
            <th style="padding:11px 16px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:left;width:36px">#</th>
            <th style="padding:11px 16px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:left">Séquence</th>
            <th style="padding:11px 16px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:left">Compétence → AT → RNCP</th>
            <th style="padding:11px 16px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:left">Créé par</th>
            <th style="padding:11px 10px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:center">Séances</th>
            <th style="padding:11px 16px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($sequences as $s): ?>
        <tr style="border-bottom:1px solid var(--border-color);<?= !$s['is_published'] ? 'opacity:.45' : '' ?>;transition:opacity .2s">
          <td style="padding:12px 16px;color:var(--text-muted);font-size:12px"><?= $s['order_num'] ?></td>
          <td style="padding:12px 16px">
            <div style="display:flex;align-items:center;gap:8px">
              <span style="font-weight:600;color:var(--text-primary)"><?= e($s['title']) ?></span>
              <?php if (!empty($s['scenario_path'])): ?>
              <a href="<?= uploadUrl($s['scenario_path']) ?>" target="_blank"
                 style="display:inline-flex;align-items:center;gap:3px;color:#6366f1;text-decoration:none;font-size:10px;font-weight:600;letter-spacing:.02em;white-space:nowrap"
                 title="Scénario pédagogique disponible — cliquer pour télécharger">
                <i class="fas fa-file-alt"></i> Scénario
              </a>
              <?php endif; ?>
            </div>
            <?php if ($s['description']): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;line-height:1.4"><?= e(mb_substr($s['description'], 0, 80)) ?><?= mb_strlen($s['description'])>80?'…':'' ?></div>
            <?php endif; ?>
            <?php if ($s['formation_title']): ?>
            <div style="margin-top:4px"><span style="font-size:10px;background:rgba(14,165,233,.12);color:#0ea5e9;padding:2px 7px;border-radius:20px"><i class="fas fa-graduation-cap" style="font-size:9px;margin-right:3px"></i><?= e($s['formation_title']) ?></span></div>
            <?php endif; ?>
          </td>
          <td style="padding:12px 16px">
            <?php if ($s['comp_code']): ?>
            <div style="display:flex;flex-direction:column;gap:3px">
              <span style="font-size:11px"><i class="fas fa-bullseye" style="color:#ef4444;margin-right:4px;font-size:10px"></i><strong><?= e($s['comp_code']) ?></strong> <?= e($s['comp_title']) ?></span>
              <?php if ($s['at_code']): ?>
              <span style="font-size:11px;color:var(--text-secondary)"><i class="fas fa-layer-group" style="color:#f59e0b;margin-right:4px;font-size:10px"></i><?= e($s['at_code']) ?> — <?= e($s['at_title']) ?></span>
              <?php endif; ?>
              <?php if ($s['rncp_code']): ?>
              <span style="font-size:10px;color:var(--text-muted)"><i class="fas fa-certificate" style="color:#8b5cf6;margin-right:4px;font-size:9px"></i><?= e($s['rncp_code']) ?></span>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <span style="color:var(--text-muted);font-size:12px">—</span>
            <?php endif; ?>
          </td>
          <td style="padding:12px 16px;font-size:12px;color:var(--text-secondary)">
            <?php if ($s['first_name']): ?>
              <?php if (in_array($s['creator_role'], ['admin', 'pedagogy'], true)): ?>
              <i class="fas fa-user-tie" style="color:#10b981;margin-right:5px;font-size:11px" title="Service pédagogie"></i><?= e($s['first_name'] . ' ' . $s['last_name']) ?>
              <?php else: ?>
              <i class="fas fa-chalkboard-teacher" style="color:#8b5cf6;margin-right:5px;font-size:11px" title="Enseignant"></i><?= e($s['first_name'] . ' ' . $s['last_name']) ?>
              <?php endif; ?>
            <?php else: ?>
            <span style="color:var(--text-faint)">Système</span>
            <?php endif; ?>
          </td>
          <td style="padding:12px 10px;text-align:center">
            <?php if ($s['nb_seances'] > 0): ?>
            <span style="font-weight:700;color:#10b981"><?= $s['nb_seances'] ?></span>
            <?php else: ?>
            <span style="color:var(--text-faint)">0</span>
            <?php endif; ?>
          </td>
          <td style="padding:12px 16px;text-align:right;white-space:nowrap">
            <form method="POST" style="display:inline" title="<?= $s['is_published'] ? 'Publié — masquer aux apprenants' : 'Masqué — rendre visible' ?>">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="toggle_publish">
              <input type="hidden" name="seq_id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm" style="margin-right:4px;color:<?= $s['is_published'] ? '#10b981' : 'var(--text-muted)' ?>" title="<?= $s['is_published'] ? 'Publié' : 'Masqué' ?>">
                <i class="fas <?= $s['is_published'] ? 'fa-eye' : 'fa-eye-slash' ?>"></i>
              </button>
            </form>
            <a href="<?= url('pedagogy/sequences/create.php?id='.$s['id']) ?>" class="btn btn-ghost btn-sm" title="Modifier" style="margin-right:4px">
              <i class="fas fa-edit"></i>
            </a>
            <form method="POST" action="<?= url('pedagogy/sequences/delete.php') ?>" style="display:inline" onsubmit="return confirm('Supprimer la séquence « <?= e(addslashes($s['title'])) ?> » ?\n\nCette action est irréversible.')">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm" title="Supprimer" style="color:#ef4444">
                <i class="fas fa-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:12px 20px;border-top:1px solid var(--border-color);font-size:12px;color:var(--text-muted)">
      <?= count($sequences) ?> séquence<?= count($sequences) !== 1 ? 's' : '' ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
