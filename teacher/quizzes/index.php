<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Filtres GET ───────────────────────────────────────────────────────────────
$search      = trim($_GET['q']              ?? '');
$qtype       = $_GET['quiz_type']          ?? '';
$rncpId      = (int)($_GET['rncp_id']       ?? 0);
$formationId = (int)($_GET['formation_id']  ?? 0);
$moduleId    = (int)($_GET['module_id']     ?? 0);
$atId        = (int)($_GET['at_id']         ?? 0);
$compId      = (int)($_GET['competency_id'] ?? 0);
$page        = max(1, (int)($_GET['page']   ?? 1));

// ── WHERE dynamique ───────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($search)      { $where[] = 'q.title LIKE ?';           $params[] = '%' . $search . '%'; }
if ($qtype)       { $where[] = 'q.quiz_type = ?';          $params[] = $qtype; }
if ($rncpId)      { $where[] = 'f.rncp_title_id = ?';      $params[] = $rncpId; }
if ($formationId) { $where[] = 'q.formation_id = ?';       $params[] = $formationId; }
if ($moduleId)    { $where[] = 'q.module_id = ?';          $params[] = $moduleId; }
if ($atId)        { $where[] = 'q.activity_type_id = ?';   $params[] = $atId; }
if ($compId)      { $where[] = 'q.competency_id = ?';      $params[] = $compId; }

$ws = implode(' AND ', $where);

// ── Compte + pagination ───────────────────────────────────────────────────────
$totalStmt = $pdo->prepare("
    SELECT COUNT(*) FROM quizzes q
    LEFT JOIN formations f ON q.formation_id = f.id
    WHERE $ws
");
$totalStmt->execute($params);
$p = paginate((int)$totalStmt->fetchColumn(), 15, $page);

// ── Liste quizzes ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT q.*,
           f.title AS formation_title, f.rncp_title_id,
           rt.rncp_code,
           m.title AS module_title,
           at.code AS at_code, at.title AS at_title,
           co.code AS co_code, co.title AS co_title,
           (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count,
           (SELECT COUNT(DISTINCT qa.user_id) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.status = 'completed') AS attempt_count,
           (SELECT ROUND(AVG(qa.score),1) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.status = 'completed') AS avg_score
    FROM quizzes q
    LEFT JOIN formations f ON q.formation_id = f.id
    LEFT JOIN rncp_titles rt ON f.rncp_title_id = rt.id
    LEFT JOIN modules m ON q.module_id = m.id
    LEFT JOIN activity_types at ON q.activity_type_id = at.id
    LEFT JOIN competencies co ON q.competency_id = co.id
    WHERE $ws
    ORDER BY q.updated_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
$quizzes = $stmt->fetchAll();

// ── Données pour les dropdowns filtres ───────────────────────────────────────

// RNCPs qui ont des quizzes
$filterRncpsStmt = $pdo->prepare("
    SELECT DISTINCT rt.id, rt.rncp_code, rt.title
    FROM rncp_titles rt
    JOIN formations f ON f.rncp_title_id = rt.id
    JOIN quizzes q ON q.formation_id = f.id
    ORDER BY rt.rncp_code
");
$filterRncpsStmt->execute();
$filterRncps = $filterRncpsStmt->fetchAll();

// Formations (filtrées par RNCP si sélectionné)
$fSql    = "SELECT DISTINCT f.id, f.title FROM formations f JOIN quizzes q ON q.formation_id = f.id WHERE 1=1";
$fParams = [];
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
        JOIN quizzes q ON q.module_id = m.id
        WHERE q.formation_id = ?
        ORDER BY m.order_num, m.title
    ");
    $s->execute([$formationId]);
    $filterModules = $s->fetchAll();
}

// ATs : depuis le RNCP sélectionné, ou depuis la formation sélectionnée
$filterATs = [];
$atRncpId  = $rncpId;
if (!$atRncpId && $formationId) {
    $r = $pdo->prepare("SELECT rncp_title_id FROM formations WHERE id = ?");
    $r->execute([$formationId]);
    $row      = $r->fetch();
    $atRncpId = $row ? (int)$row['rncp_title_id'] : 0;
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

// ── Labels des filtres actifs (chips) ────────────────────────────────────────
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

$hasFilters = $search || $qtype || $rncpId || $formationId || $moduleId || $atId || $compId;

function quizFilterRemoveUrl(string $removeKey): string {
    $params = $_GET;
    unset($params[$removeKey], $params['page']);
    $cascade = [
        'rncp_id'      => ['formation_id','module_id','at_id','competency_id'],
        'formation_id' => ['module_id','at_id','competency_id'],
        'at_id'        => ['competency_id'],
    ];
    foreach ($cascade[$removeKey] ?? [] as $child) unset($params[$child]);
    return url('teacher/quizzes/index.php?' . http_build_query($params));
}

renderHead('Quiz & Évaluations');
renderSidebar('teacher');
renderTopbar('Quiz & Évaluations', [['Enseignant', url('teacher/index.php')], ['Quiz', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div><h1>Quiz & Évaluations</h1><p><?= $p['total'] ?> quiz créé(s)</p></div>
      <a href="<?= url('teacher/quizzes/create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Créer un quiz</a>
    </div>
  </div>

  <!-- Filtres -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px;display:flex;flex-direction:column;gap:12px">
      <form id="filter-form" method="GET">

        <!-- Ligne 1 : recherche + type quiz + actions -->
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <div class="search-input" style="flex:1;min-width:180px">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Rechercher un quiz..." value="<?= e($search) ?>">
          </div>
          <select name="quiz_type" class="form-control" style="width:180px" onchange="cascadeSubmit('quiz_type')">
            <option value="">Tous types</option>
            <option value="practice"      <?= $qtype==='practice'?'selected':'' ?>>Entraînement</option>
            <option value="evaluation"    <?= $qtype==='evaluation'?'selected':'' ?>>Évaluation</option>
            <option value="certification" <?= $qtype==='certification'?'selected':'' ?>>Certification</option>
            <option value="survey"        <?= $qtype==='survey'?'selected':'' ?>>Sondage</option>
          </select>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtrer</button>
          <?php if ($hasFilters): ?>
          <a href="<?= url('teacher/quizzes/index.php') ?>" class="btn btn-ghost btn-sm" title="Réinitialiser les filtres">
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

          <!-- Module (seulement si formation sélectionnée) -->
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

          <!-- Bloc / Activité type (si RNCP ou formation sélectionné) -->
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

          <!-- Compétence (si AT sélectionné) -->
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

      <!-- Chips filtres actifs -->
      <?php if (!empty($activeFilters) || $search || $qtype): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;padding-top:4px">
        <?php if ($search): ?>
        <a href="<?= quizFilterRemoveUrl('q') ?>" class="badge badge-primary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px">
          <i class="fas fa-search" style="font-size:10px"></i> <?= e(mb_substr($search, 0, 25)) ?> <i class="fas fa-times" style="font-size:9px;opacity:.7"></i>
        </a>
        <?php endif; ?>
        <?php if ($qtype): ?>
        <?php $qtypeLabels = ['practice'=>'Entraînement','evaluation'=>'Évaluation','certification'=>'Certification','survey'=>'Sondage']; ?>
        <a href="<?= quizFilterRemoveUrl('quiz_type') ?>" class="badge badge-secondary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px">
          <?= e($qtypeLabels[$qtype] ?? ucfirst($qtype)) ?> <i class="fas fa-times" style="font-size:9px;opacity:.7"></i>
        </a>
        <?php endif; ?>
        <?php foreach ($activeFilters as $key => $label): ?>
        <a href="<?= quizFilterRemoveUrl($key) ?>" class="badge badge-secondary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px;max-width:220px">
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($label) ?></span>
          <i class="fas fa-times" style="font-size:9px;opacity:.7;flex-shrink:0"></i>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Liste -->
  <?php if (empty($quizzes)): ?>
  <div class="empty-state">
    <div class="icon">❓</div>
    <h3><?= $hasFilters ? 'Aucun quiz ne correspond aux filtres' : 'Aucun quiz' ?></h3>
    <p><?= $hasFilters ? 'Essayez d\'élargir vos critères de recherche.' : 'Créez votre premier quiz pour évaluer vos apprenants.' ?></p>
    <?php if ($hasFilters): ?>
    <a href="<?= url('teacher/quizzes/index.php') ?>" class="btn btn-secondary" style="margin-top:8px"><i class="fas fa-times"></i> Réinitialiser les filtres</a>
    <?php else: ?>
    <a href="<?= url('teacher/quizzes/create.php') ?>" class="btn btn-primary">Créer un quiz</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">
    <?php
    $typeIcons  = ['practice'=>'fas fa-dumbbell','evaluation'=>'fas fa-tasks','certification'=>'fas fa-certificate','survey'=>'fas fa-poll'];
    $typeColors = ['practice'=>'#6366f1','evaluation'=>'#f59e0b','certification'=>'#10b981','survey'=>'#0ea5e9'];
    $typeLabels = ['practice'=>'Entraînement','evaluation'=>'Évaluation','certification'=>'Certification','survey'=>'Sondage'];
    foreach ($quizzes as $quiz):
      $icon  = $typeIcons[$quiz['quiz_type']]  ?? 'fas fa-question-circle';
      $color = $typeColors[$quiz['quiz_type']] ?? '#6366f1';
      $label = $typeLabels[$quiz['quiz_type']] ?? $quiz['quiz_type'];
    ?>
    <div class="card">
      <div class="card-body">
        <!-- En-tête -->
        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px">
          <div style="width:42px;height:42px;border-radius:var(--radius);background:<?= $color ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="<?= $icon ?>" style="color:<?= $color ?>"></i>
          </div>
          <div style="flex:1;overflow:hidden">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:4px"><?= e($quiz['title']) ?></h3>
            <span class="badge badge-secondary" style="font-size:10px"><?= $label ?></span>
          </div>
        </div>

        <!-- Rattachements pédagogiques -->
        <?php if ($quiz['formation_title'] || $quiz['at_code'] || $quiz['co_code']): ?>
        <div style="margin-bottom:10px;display:flex;flex-direction:column;gap:4px">
          <?php if ($quiz['formation_title']): ?>
          <div style="font-size:12px;color:var(--text-muted)">
            <i class="fas fa-graduation-cap" style="margin-right:4px;color:#0ea5e9;font-size:10px"></i><?= e(mb_substr($quiz['formation_title'], 0, 38)) ?>
          </div>
          <?php endif; ?>
          <?php if ($quiz['module_title']): ?>
          <div style="font-size:11px;color:var(--text-faint)">
            <i class="fas fa-cube" style="margin-right:4px;color:#10b981;font-size:10px"></i><?= e(mb_substr($quiz['module_title'], 0, 38)) ?>
          </div>
          <?php endif; ?>
          <?php if ($quiz['at_code'] || $quiz['co_code']): ?>
          <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:2px">
            <?php if ($quiz['at_code']): ?>
            <span class="badge badge-secondary" style="font-size:10px" title="<?= e($quiz['at_title']) ?>">
              <i class="fas fa-layer-group" style="color:#f59e0b"></i> <?= e($quiz['at_code']) ?>
            </span>
            <?php endif; ?>
            <?php if ($quiz['co_code']): ?>
            <span class="badge badge-secondary" style="font-size:10px" title="<?= e($quiz['co_title']) ?>">
              <i class="fas fa-star" style="color:#ef4444"></i> <?= e($quiz['co_code']) ?>
            </span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;text-align:center">
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:white"><?= $quiz['question_count'] ?></div>
            <div style="font-size:10px;color:var(--text-muted)">Questions</div>
          </div>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:white"><?= $quiz['attempt_count'] ?></div>
            <div style="font-size:10px;color:var(--text-muted)">Réponses</div>
          </div>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:<?= $quiz['avg_score'] >= $quiz['passing_score'] ? 'var(--success)' : 'var(--danger)' ?>">
              <?= $quiz['avg_score'] ? round($quiz['avg_score']) . '%' : '—' ?>
            </div>
            <div style="font-size:10px;color:var(--text-muted)">Moy.</div>
          </div>
        </div>

        <div style="display:flex;gap:6px;font-size:12px;color:var(--text-muted);margin-bottom:12px">
          <span><i class="fas fa-star-half-alt"></i> Seuil <?= $quiz['passing_score'] ?>%</span>
          <?php if ($quiz['time_limit_minutes']): ?><span><i class="fas fa-clock"></i> <?= $quiz['time_limit_minutes'] ?> min</span><?php endif; ?>
          <span><i class="fas fa-redo"></i> <?= $quiz['max_attempts'] ?> essais</span>
        </div>

        <!-- Actions -->
        <div style="display:flex;gap:6px;margin-bottom:6px">
          <a href="<?= url('teacher/quizzes/create.php?id=' . $quiz['id']) ?>" class="btn btn-secondary btn-sm" style="flex:1;justify-content:center"><i class="fas fa-edit"></i> Modifier</a>
          <a href="<?= url('teacher/quizzes/preview.php?id=' . $quiz['id']) ?>" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center;color:var(--warning);border:1px solid rgba(245,158,11,.3)" target="_blank"><i class="fas fa-play-circle"></i> Tester</a>
        </div>
        <div style="display:flex;gap:6px">
          <a href="<?= url('teacher/evaluations/index.php?quiz_id=' . $quiz['id']) ?>" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center" title="Voir les résultats"><i class="fas fa-chart-bar"></i> Résultats</a>
          <form method="POST" action="<?= url('teacher/quizzes/delete.php') ?>" onsubmit="return confirm('Supprimer définitivement le quiz « <?= e(addslashes($quiz['title'])) ?> » et toutes ses données ?')">
            <?= csrfField() ?>
            <input type="hidden" name="quiz_id" value="<?= $quiz['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="Supprimer"><i class="fas fa-trash"></i></button>
          </form>
        </div>

      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php
  $paginationParams = array_filter(compact('search') + [
      'quiz_type'    => $qtype,
      'rncp_id'      => $rncpId      ?: '',
      'formation_id' => $formationId ?: '',
      'module_id'    => $moduleId    ?: '',
      'at_id'        => $atId        ?: '',
      'competency_id'=> $compId      ?: '',
  ]);
  echo $p['totalPages'] > 1 ? renderPagination($p, url('teacher/quizzes/index.php?' . http_build_query(array_filter($paginationParams)))) : '';
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
