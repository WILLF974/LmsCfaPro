<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Filtres GET ───────────────────────────────────────────────────────────────
$search      = trim($_GET['q']              ?? '');
$type        = $_GET['type']               ?? '';
$rncpId      = (int)($_GET['rncp_id']       ?? 0);
$formationId = (int)($_GET['formation_id']  ?? 0);
$moduleId    = (int)($_GET['module_id']     ?? 0);
$atId        = (int)($_GET['at_id']         ?? 0);
$compId      = (int)($_GET['competency_id'] ?? 0);
$page        = max(1, (int)($_GET['page']   ?? 1));

// ── WHERE dynamique ───────────────────────────────────────────────────────────
$where  = ['l.created_by = ?'];
$params = [$userId];

if ($search)      { $where[] = 'l.title LIKE ?';          $params[] = '%' . $search . '%'; }
if ($type)        { $where[] = 'l.content_type = ?';      $params[] = $type; }
if ($rncpId)      { $where[] = 'f.rncp_title_id = ?';     $params[] = $rncpId; }
if ($formationId) { $where[] = 'm.formation_id = ?';      $params[] = $formationId; }
if ($moduleId)    { $where[] = 'l.module_id = ?';         $params[] = $moduleId; }
if ($atId)        { $where[] = 'm.activity_type_id = ?';  $params[] = $atId; }
if ($compId)      { $where[] = 'l.competency_id = ?';     $params[] = $compId; }

$ws = implode(' AND ', $where);

// ── Compte + pagination ───────────────────────────────────────────────────────
$totalStmt = $pdo->prepare("
    SELECT COUNT(*) FROM lessons l
    JOIN modules m ON l.module_id = m.id
    JOIN formations f ON m.formation_id = f.id
    WHERE $ws
");
$totalStmt->execute($params);
$p = paginate((int)$totalStmt->fetchColumn(), 15, $page);

// ── Liste capsules ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT l.*,
           m.title AS module_title, m.formation_id, m.activity_type_id,
           f.title AS formation_title, f.rncp_title_id,
           rt.rncp_code,
           at.code AS at_code, at.title AS at_title,
           co.code AS co_code, co.title AS co_title,
           (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.lesson_id = l.id AND lp.status = 'completed') AS completed_count,
           (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.lesson_id = l.id) AS started_count
    FROM lessons l
    JOIN modules m ON l.module_id = m.id
    JOIN formations f ON m.formation_id = f.id
    LEFT JOIN rncp_titles rt ON f.rncp_title_id = rt.id
    LEFT JOIN activity_types at ON m.activity_type_id = at.id
    LEFT JOIN competencies co ON l.competency_id = co.id
    WHERE $ws
    ORDER BY l.updated_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
$lessons = $stmt->fetchAll();

// ── Stats globales ────────────────────────────────────────────────────────────
$statsStmt = $pdo->prepare("SELECT content_type, COUNT(*) AS cnt FROM lessons WHERE created_by = ? GROUP BY content_type");
$statsStmt->execute([$userId]);
$typeStats = array_column($statsStmt->fetchAll(), 'cnt', 'content_type');

// ── Données pour les dropdowns filtres ───────────────────────────────────────

// RNCPs qui ont des capsules de cet enseignant
$filterRncpsStmt = $pdo->prepare("
    SELECT DISTINCT rt.id, rt.rncp_code, rt.title
    FROM rncp_titles rt
    JOIN formations f ON f.rncp_title_id = rt.id
    JOIN modules m ON m.formation_id = f.id
    JOIN lessons l ON l.module_id = m.id
    WHERE l.created_by = ?
    ORDER BY rt.rncp_code
");
$filterRncpsStmt->execute([$userId]);
$filterRncps = $filterRncpsStmt->fetchAll();

// Formations (filtrées par RNCP si sélectionné, sinon toutes celles avec des capsules de cet enseignant)
$fSql    = "SELECT DISTINCT f.id, f.title FROM formations f
            JOIN modules m ON m.formation_id = f.id
            JOIN lessons l ON l.module_id = m.id
            WHERE l.created_by = ?";
$fParams = [$userId];
if ($rncpId) { $fSql .= " AND f.rncp_title_id = ?"; $fParams[] = $rncpId; }
$fSql .= " ORDER BY f.title";
$filterFormationsStmt = $pdo->prepare($fSql);
$filterFormationsStmt->execute($fParams);
$filterFormations = $filterFormationsStmt->fetchAll();

// Modules (filtrés par formation si sélectionnée)
$filterModules = [];
if ($formationId) {
    $s = $pdo->prepare("
        SELECT DISTINCT m.id, m.title FROM modules m
        JOIN lessons l ON l.module_id = m.id
        WHERE m.formation_id = ? AND l.created_by = ?
        ORDER BY m.order_num, m.title
    ");
    $s->execute([$formationId, $userId]);
    $filterModules = $s->fetchAll();
}

// ATs : depuis le RNCP sélectionné, ou depuis la formation sélectionnée
$filterATs  = [];
$atRncpId   = $rncpId;
if (!$atRncpId && $formationId) {
    $r = $pdo->prepare("SELECT rncp_title_id FROM formations WHERE id = ?");
    $r->execute([$formationId]);
    $row       = $r->fetch();
    $atRncpId  = $row ? (int)$row['rncp_title_id'] : 0;
}
if ($atRncpId) {
    $s = $pdo->prepare("SELECT id, code, title FROM activity_types WHERE rncp_title_id = ? ORDER BY order_num, code");
    $s->execute([$atRncpId]);
    $filterATs = $s->fetchAll();
}

// Compétences (filtrées par AT sélectionné)
$filterComps = [];
if ($atId) {
    $s = $pdo->prepare("SELECT id, code, title FROM competencies WHERE activity_type_id = ? ORDER BY order_num, code");
    $s->execute([$atId]);
    $filterComps = $s->fetchAll();
}

// ── Labels des filtres actifs (pour les chips d'affichage) ───────────────────
$activeFilters = [];
if ($rncpId) {
    foreach ($filterRncps as $r) {
        if ($r['id'] == $rncpId) { $activeFilters['rncp_id'] = 'RNCP : ' . $r['rncp_code']; break; }
    }
    if (!isset($activeFilters['rncp_id'])) {
        $r = $pdo->prepare("SELECT rncp_code FROM rncp_titles WHERE id = ?"); $r->execute([$rncpId]);
        $row = $r->fetch(); if ($row) $activeFilters['rncp_id'] = 'RNCP : ' . $row['rncp_code'];
    }
}
if ($formationId) {
    foreach ($filterFormations as $f) {
        if ($f['id'] == $formationId) { $activeFilters['formation_id'] = mb_substr($f['title'], 0, 28); break; }
    }
}
if ($moduleId && $filterModules) {
    foreach ($filterModules as $m) {
        if ($m['id'] == $moduleId) { $activeFilters['module_id'] = mb_substr($m['title'], 0, 28); break; }
    }
}
if ($atId && $filterATs) {
    foreach ($filterATs as $a) {
        if ($a['id'] == $atId) { $activeFilters['at_id'] = $a['code'] . ' — ' . mb_substr($a['title'], 0, 22); break; }
    }
}
if ($compId && $filterComps) {
    foreach ($filterComps as $c) {
        if ($c['id'] == $compId) { $activeFilters['competency_id'] = $c['code'] . ' — ' . mb_substr($c['title'], 0, 22); break; }
    }
}

$hasFilters = $search || $type || $rncpId || $formationId || $moduleId || $atId || $compId;

// URL de base pour les chips (retire un paramètre en conservant les autres)
function filterRemoveUrl(string $removeKey): string {
    $params = $_GET;
    unset($params[$removeKey], $params['page']);
    // Cascade : retirer les enfants quand on retire un parent
    $cascade = [
        'rncp_id'      => ['formation_id','module_id','at_id','competency_id'],
        'formation_id' => ['module_id','at_id','competency_id'],
        'at_id'        => ['competency_id'],
    ];
    foreach ($cascade[$removeKey] ?? [] as $child) unset($params[$child]);
    return url('teacher/courses/index.php?' . http_build_query($params));
}

renderHead('Mes capsules');
renderSidebar('teacher');
renderTopbar('Mes capsules', [['Enseignant', url('teacher/index.php')], ['Capsules', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Stats rapides -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
    <?php
    $types = ['video'=>['Vidéos','fa-play-circle','#ef4444'],'pdf'=>['PDF','fa-file-pdf','#f59e0b'],'quiz'=>['Quiz','fa-question-circle','#8b5cf6'],'text'=>['Textes','fa-align-left','#6366f1']];
    foreach ($types as $t => [$label, $icon, $color]):
    ?>
    <div class="card">
      <div class="card-body" style="display:flex;align-items:center;gap:12px;padding:14px">
        <div style="width:40px;height:40px;border-radius:var(--radius);background:<?= $color ?>22;display:flex;align-items:center;justify-content:center">
          <i class="fas <?= $icon ?>" style="color:<?= $color ?>"></i>
        </div>
        <div>
          <div style="font-size:20px;font-weight:800;color:white"><?= $typeStats[$t] ?? 0 ?></div>
          <div style="font-size:11px;color:var(--text-muted)"><?= $label ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div><h1>Mes capsules</h1><p><?= $p['total'] ?> capsule(s)</p></div>
      <div style="display:flex;gap:8px">
        <a href="<?= url('teacher/courses/order.php') ?>" class="btn btn-secondary"><i class="fas fa-sort"></i> Ordonner</a>
        <a href="<?= url('teacher/courses/create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvelle capsule</a>
      </div>
    </div>
  </div>

  <!-- Filtres -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px;display:flex;flex-direction:column;gap:12px">
      <form id="filter-form" method="GET">

        <!-- Ligne 1 : recherche texte + type + actions -->
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <div class="search-input" style="flex:1;min-width:180px">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Rechercher une capsule..." value="<?= e($search) ?>">
          </div>
          <select name="type" class="form-control" style="width:160px" onchange="cascadeSubmit('type')">
            <option value="">Tous types</option>
            <option value="video"        <?= $type==='video'?'selected':'' ?>>Vidéo</option>
            <option value="pdf"          <?= $type==='pdf'?'selected':'' ?>>PDF</option>
            <option value="document"     <?= $type==='document'?'selected':'' ?>>Document</option>
            <option value="presentation" <?= $type==='presentation'?'selected':'' ?>>Présentation</option>
            <option value="quiz"         <?= $type==='quiz'?'selected':'' ?>>Quiz</option>
            <option value="exercise"     <?= $type==='exercise'?'selected':'' ?>>Exercice</option>
            <option value="text"         <?= $type==='text'?'selected':'' ?>>Texte</option>
          </select>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtrer</button>
          <?php if ($hasFilters): ?>
          <a href="<?= url('teacher/courses/index.php') ?>" class="btn btn-ghost btn-sm" title="Réinitialiser les filtres">
            <i class="fas fa-times"></i> Réinitialiser
          </a>
          <?php endif; ?>
        </div>

        <!-- Ligne 2 : filtres pédagogiques en cascade -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;border-top:1px solid var(--border);padding-top:12px">

          <!-- RNCP -->
          <div style="min-width:160px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
              <i class="fas fa-certificate" style="color:#8b5cf6;margin-right:4px"></i>Titre RNCP
            </label>
            <select name="rncp_id" class="form-control" style="font-size:12px" onchange="cascadeSubmit('rncp_id')">
              <option value="">— Tous —</option>
              <?php foreach ($filterRncps as $r): ?>
              <option value="<?= $r['id'] ?>" <?= $rncpId == $r['id'] ? 'selected' : '' ?>>
                <?= e($r['rncp_code']) ?> — <?= e(mb_substr($r['title'], 0, 30)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Formation -->
          <div style="min-width:160px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
              <i class="fas fa-graduation-cap" style="color:#0ea5e9;margin-right:4px"></i>Formation
            </label>
            <select name="formation_id" class="form-control" style="font-size:12px" onchange="cascadeSubmit('formation_id')">
              <option value="">— Toutes —</option>
              <?php foreach ($filterFormations as $f): ?>
              <option value="<?= $f['id'] ?>" <?= $formationId == $f['id'] ? 'selected' : '' ?>>
                <?= e(mb_substr($f['title'], 0, 35)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Module (affiché seulement si formation sélectionnée) -->
          <?php if ($formationId && !empty($filterModules)): ?>
          <div style="min-width:150px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
              <i class="fas fa-cube" style="color:#10b981;margin-right:4px"></i>Module
            </label>
            <select name="module_id" class="form-control" style="font-size:12px" onchange="cascadeSubmit('module_id')">
              <option value="">— Tous —</option>
              <?php foreach ($filterModules as $m): ?>
              <option value="<?= $m['id'] ?>" <?= $moduleId == $m['id'] ? 'selected' : '' ?>>
                <?= e(mb_substr($m['title'], 0, 35)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <!-- Séparateur visuel -->
          <?php if (!empty($filterATs)): ?>
          <div style="display:flex;align-items:flex-end;padding-bottom:1px;color:var(--border);font-size:18px">|</div>
          <?php endif; ?>

          <!-- Bloc / Activité type (affiché si RNCP ou formation sélectionné) -->
          <?php if (!empty($filterATs)): ?>
          <div style="min-width:180px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
              <i class="fas fa-layer-group" style="color:#f59e0b;margin-right:4px"></i>Bloc / Activité type
            </label>
            <select name="at_id" class="form-control" style="font-size:12px" onchange="cascadeSubmit('at_id')">
              <option value="">— Tous —</option>
              <?php foreach ($filterATs as $a): ?>
              <option value="<?= $a['id'] ?>" <?= $atId == $a['id'] ? 'selected' : '' ?>>
                <?= e($a['code']) ?> — <?= e(mb_substr($a['title'], 0, 28)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <!-- Compétence (affichée si AT sélectionné) -->
          <?php if ($atId && !empty($filterComps)): ?>
          <div style="min-width:180px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
              <i class="fas fa-star" style="color:#ef4444;margin-right:4px"></i>Compétence
            </label>
            <select name="competency_id" class="form-control" style="font-size:12px" onchange="cascadeSubmit('competency_id')">
              <option value="">— Toutes —</option>
              <?php foreach ($filterComps as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $compId == $c['id'] ? 'selected' : '' ?>>
                <?= e($c['code']) ?> — <?= e(mb_substr($c['title'], 0, 28)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

        </div>

      </form>

      <!-- Chips des filtres actifs -->
      <?php if (!empty($activeFilters) || $search || $type): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;padding-top:4px">
        <?php if ($search): ?>
        <a href="<?= filterRemoveUrl('q') ?>" class="badge badge-primary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px">
          <i class="fas fa-search" style="font-size:10px"></i> <?= e(mb_substr($search, 0, 25)) ?> <i class="fas fa-times" style="font-size:9px;opacity:.7"></i>
        </a>
        <?php endif; ?>
        <?php if ($type): ?>
        <a href="<?= filterRemoveUrl('type') ?>" class="badge badge-secondary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px">
          <?= e(ucfirst($type)) ?> <i class="fas fa-times" style="font-size:9px;opacity:.7"></i>
        </a>
        <?php endif; ?>
        <?php foreach ($activeFilters as $key => $label): ?>
        <a href="<?= filterRemoveUrl($key) ?>" class="badge badge-secondary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px;max-width:220px">
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($label) ?></span>
          <i class="fas fa-times" style="font-size:9px;opacity:.7;flex-shrink:0"></i>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Liste -->
  <?php if (empty($lessons)): ?>
  <div class="empty-state">
    <div class="icon">📖</div>
    <h3><?= $hasFilters ? 'Aucune capsule ne correspond aux filtres' : 'Aucune capsule' ?></h3>
    <p><?= $hasFilters ? 'Essayez d\'élargir vos critères de recherche.' : 'Créez votre première capsule pédagogique.' ?></p>
    <?php if ($hasFilters): ?>
    <a href="<?= url('teacher/courses/index.php') ?>" class="btn btn-secondary" style="margin-top:8px"><i class="fas fa-times"></i> Réinitialiser les filtres</a>
    <?php else: ?>
    <a href="<?= url('teacher/courses/create.php') ?>" class="btn btn-primary">Créer une capsule</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="table">
        <thead>
          <tr>
            <th>Capsule</th>
            <th>Formation / Module</th>
            <th>Rattachement</th>
            <th>Type</th>
            <th>Durée</th>
            <th>XP</th>
            <th>Complétion</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lessons as $l): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <i class="<?= getContentTypeIcon($l['content_type']) ?>" style="font-size:18px;width:20px"></i>
                <div>
                  <div style="font-weight:600;font-size:14px"><?= e($l['title']) ?></div>
                  <?php if (!$l['is_mandatory']): ?><span style="font-size:10px;color:var(--text-faint)">Optionnel</span><?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <div style="font-size:13px"><?= e(mb_substr($l['formation_title'], 0, 28)) ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?= e(mb_substr($l['module_title'], 0, 28)) ?></div>
              <?php if ($l['rncp_code']): ?>
              <div style="font-size:10px;color:var(--text-faint);margin-top:2px"><?= e($l['rncp_code']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;flex-direction:column;gap:4px">
                <?php if ($l['at_code']): ?>
                <span class="badge badge-secondary" style="font-size:10px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($l['at_title']) ?>">
                  <i class="fas fa-layer-group" style="color:#f59e0b"></i> <?= e($l['at_code']) ?>
                </span>
                <?php endif; ?>
                <?php if ($l['co_code']): ?>
                <span class="badge badge-secondary" style="font-size:10px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($l['co_title']) ?>">
                  <i class="fas fa-star" style="color:#ef4444"></i> <?= e($l['co_code']) ?>
                </span>
                <?php endif; ?>
                <?php if (!$l['at_code'] && !$l['co_code']): ?>
                <span style="font-size:11px;color:var(--text-faint)">—</span>
                <?php endif; ?>
              </div>
            </td>
            <td><span class="badge badge-secondary"><?= e(ucfirst($l['content_type'])) ?></span></td>
            <td><?= $l['duration_minutes'] ? formatDuration($l['duration_minutes']) : '—' ?></td>
            <td><span style="color:var(--warning)">+<?= $l['xp_reward'] ?> XP</span></td>
            <td>
              <?php if ($l['started_count'] > 0): ?>
              <div style="font-size:12px"><?= $l['completed_count'] ?>/<?= $l['started_count'] ?> apprenants</div>
              <div class="progress-bar" style="height:4px;margin-top:4px"><div class="progress-fill" style="width:<?= round($l['completed_count'] / $l['started_count'] * 100) ?>%"></div></div>
              <?php else: ?>
              <span style="color:var(--text-faint);font-size:12px">—</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= url('teacher/courses/create.php?id=' . $l['id']) ?>" class="btn btn-secondary btn-sm" title="Modifier"><i class="fas fa-edit"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
  $paginationParams = array_filter(compact('search', 'type') + [
      'rncp_id' => $rncpId ?: '', 'formation_id' => $formationId ?: '',
      'module_id' => $moduleId ?: '', 'at_id' => $atId ?: '', 'competency_id' => $compId ?: '',
  ]);
  echo $p['totalPages'] > 1 ? renderPagination($p, url('teacher/courses/index.php?' . http_build_query(array_filter($paginationParams)))) : '';
  ?>
  <?php endif; ?>
</div>

<script>
var CASCADE_DEPS = {
  rncp_id:      ['formation_id','module_id','at_id','competency_id'],
  formation_id: ['module_id','at_id','competency_id'],
  at_id:        ['competency_id']
};
function cascadeSubmit(name) {
  var deps = CASCADE_DEPS[name] || [];
  deps.forEach(function(dep) {
    var el = document.querySelector('#filter-form [name="' + dep + '"]');
    if (el) el.value = '';
  });
  document.getElementById('filter-form').submit();
}
</script>

<?php renderFooter(); ?>
