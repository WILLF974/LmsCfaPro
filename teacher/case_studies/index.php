<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Migrations non-destructives ───────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS case_studies (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        title         VARCHAR(255) NOT NULL,
        description   TEXT,
        file_type     VARCHAR(30) NOT NULL DEFAULT 'pdf',
        file_path     TEXT,
        content_url   VARCHAR(500),
        formation_id  INT NULL,
        created_by    INT NOT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_created_by (created_by),
        INDEX idx_formation  (formation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS case_study_resources (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        case_study_id  INT NOT NULL,
        title          VARCHAR(255) NOT NULL,
        type           VARCHAR(30) NOT NULL DEFAULT 'other',
        file_path      TEXT DEFAULT NULL,
        url            VARCHAR(500) DEFAULT NULL,
        file_size      INT DEFAULT NULL,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cs (case_study_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
foreach ([
    "ALTER TABLE case_studies ADD COLUMN activity_type_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN competency_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN module_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN lesson_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN duration_minutes INT NULL",
    "ALTER TABLE case_studies ADD COLUMN xp_reward SMALLINT NOT NULL DEFAULT 0",
] as $sql) { try { $pdo->exec($sql); } catch (PDOException $e) {} }

// ── Droits ────────────────────────────────────────────────────────────────────
$ownerOnly = !isAdmin() && !isPedagogy();

// ── Filtres GET ───────────────────────────────────────────────────────────────
$search      = trim($_GET['q']              ?? '');
$fileType    = $_GET['file_type']           ?? '';
$rncpId      = (int)($_GET['rncp_id']       ?? 0);
$formationId = (int)($_GET['formation_id']  ?? 0);
$moduleId    = (int)($_GET['module_id']     ?? 0);
$atId        = (int)($_GET['at_id']         ?? 0);
$compId      = (int)($_GET['competency_id'] ?? 0);
$page        = max(1, (int)($_GET['page']   ?? 1));

// ── WHERE dynamique ───────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($ownerOnly) { $where[] = 'cs.created_by = ?'; $params[] = $userId; }
if ($search)    { $where[] = 'cs.title LIKE ?';    $params[] = '%' . $search . '%'; }
if ($fileType)  { $where[] = 'cs.file_type = ?';   $params[] = $fileType; }
if ($rncpId)    { $where[] = 'f.rncp_title_id = ?'; $params[] = $rncpId; }
if ($formationId) { $where[] = 'cs.formation_id = ?';    $params[] = $formationId; }
if ($moduleId)    { $where[] = 'cs.module_id = ?';       $params[] = $moduleId; }
if ($atId)        { $where[] = 'cs.activity_type_id = ?'; $params[] = $atId; }
if ($compId)      { $where[] = 'cs.competency_id = ?';   $params[] = $compId; }

$ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Compte + pagination ───────────────────────────────────────────────────────
$totalStmt = $pdo->prepare("
    SELECT COUNT(*) FROM case_studies cs
    LEFT JOIN formations f ON cs.formation_id = f.id
    $ws
");
$totalStmt->execute($params);
$p = paginate((int)$totalStmt->fetchColumn(), 15, $page);

// ── Liste études de cas ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT cs.*,
           f.title  AS formation_title, f.rncp_title_id,
           rt.rncp_code,
           m.title  AS module_title,
           l.title  AS lesson_title,
           at.code  AS at_code,  at.title AS at_title,
           co.code  AS co_code,  co.title AS co_title,
           CONCAT(u.first_name,' ',u.last_name) AS author
    FROM case_studies cs
    LEFT JOIN formations f     ON cs.formation_id      = f.id
    LEFT JOIN rncp_titles rt   ON f.rncp_title_id      = rt.id
    LEFT JOIN modules m        ON cs.module_id         = m.id
    LEFT JOIN lessons l        ON cs.lesson_id         = l.id
    LEFT JOIN activity_types at ON cs.activity_type_id = at.id
    LEFT JOIN competencies co   ON cs.competency_id    = co.id
    LEFT JOIN users u           ON cs.created_by       = u.id
    $ws
    ORDER BY cs.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
$caseStudies = $stmt->fetchAll();

// ── Données pour les dropdowns filtres ───────────────────────────────────────
$ownerJoin   = $ownerOnly ? "JOIN case_studies cs2 ON cs2.formation_id = f.id AND cs2.created_by = $userId" : "JOIN case_studies cs2 ON cs2.formation_id = f.id";
$ownerJoinCS = $ownerOnly ? "WHERE cs.created_by = $userId" : "";

// RNCPs avec des études de cas
$filterRncpsStmt = $pdo->prepare("
    SELECT DISTINCT rt.id, rt.rncp_code, rt.title
    FROM rncp_titles rt
    JOIN formations f ON f.rncp_title_id = rt.id
    $ownerJoin
    ORDER BY rt.rncp_code
");
$filterRncpsStmt->execute([]);
$filterRncps = $filterRncpsStmt->fetchAll();

// Formations (filtrées par RNCP si sélectionné)
$fSql    = "SELECT DISTINCT f.id, f.title FROM formations f
            JOIN case_studies cs ON cs.formation_id = f.id
            " . ($ownerOnly ? "AND cs.created_by = $userId" : "") . "
            WHERE 1=1";
$fParams = [];
if ($rncpId) { $fSql .= " AND f.rncp_title_id = ?"; $fParams[] = $rncpId; }
$fSql .= " ORDER BY f.title";
$filterFormationsStmt = $pdo->prepare($fSql);
$filterFormationsStmt->execute($fParams);
$filterFormations = $filterFormationsStmt->fetchAll();

// Modules (filtrés par formation si sélectionnée)
$filterModules = [];
if ($formationId) {
    $mSql  = "SELECT DISTINCT m.id, m.title FROM modules m
              JOIN case_studies cs ON cs.module_id = m.id
              WHERE m.formation_id = ?" . ($ownerOnly ? " AND cs.created_by = $userId" : "") . "
              ORDER BY m.order_num, m.title";
    $s = $pdo->prepare($mSql);
    $s->execute([$formationId]);
    $filterModules = $s->fetchAll();
}

// ATs depuis le RNCP sélectionné, ou depuis la formation sélectionnée
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

$hasFilters = $search || $fileType || $rncpId || $formationId || $moduleId || $atId || $compId;

$typeIcons = [
    'pdf'          => ['icon'=>'file-pdf',        'color'=>'#ef4444', 'label'=>'PDF'],
    'document'     => ['icon'=>'file-word',       'color'=>'#3b82f6', 'label'=>'Document'],
    'presentation' => ['icon'=>'file-powerpoint', 'color'=>'#f97316', 'label'=>'Présentation'],
    'video'        => ['icon'=>'play-circle',     'color'=>'#ef4444', 'label'=>'Vidéo'],
    'link'         => ['icon'=>'link',            'color'=>'#0ea5e9', 'label'=>'Lien'],
];

function csFilterRemoveUrl(string $removeKey): string {
    $params = $_GET;
    unset($params[$removeKey], $params['page']);
    $cascade = [
        'rncp_id'      => ['formation_id','module_id','at_id','competency_id'],
        'formation_id' => ['module_id','at_id','competency_id'],
        'at_id'        => ['competency_id'],
    ];
    foreach ($cascade[$removeKey] ?? [] as $child) unset($params[$child]);
    return url('teacher/case_studies/index.php?' . http_build_query($params));
}

renderHead('Études de cas');
renderSidebar('teacher');
renderTopbar('Études de cas', [['Enseignant', url('teacher/index.php')], ['Études de cas', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div>
        <h1>Études de cas</h1>
        <p><?= $p['total'] ?> étude(s) de cas</p>
      </div>
      <a href="<?= url('teacher/case_studies/create.php') ?>" class="btn btn-primary">
        <i class="fas fa-plus"></i> Importer une étude de cas
      </a>
    </div>
  </div>

  <!-- Filtres -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px;display:flex;flex-direction:column;gap:12px">
      <form id="filter-form" method="GET">

        <!-- Ligne 1 : recherche + type de fichier + actions -->
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <div class="search-input" style="flex:1;min-width:180px">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Rechercher une étude de cas..." value="<?= e($search) ?>">
          </div>
          <select name="file_type" class="form-control" style="width:180px" onchange="cascadeSubmit('file_type')">
            <option value="">Tous types</option>
            <option value="pdf"          <?= $fileType==='pdf'?'selected':'' ?>>PDF</option>
            <option value="document"     <?= $fileType==='document'?'selected':'' ?>>Document</option>
            <option value="presentation" <?= $fileType==='presentation'?'selected':'' ?>>Présentation</option>
            <option value="video"        <?= $fileType==='video'?'selected':'' ?>>Vidéo</option>
            <option value="link"         <?= $fileType==='link'?'selected':'' ?>>Lien</option>
          </select>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtrer</button>
          <?php if ($hasFilters): ?>
          <a href="<?= url('teacher/case_studies/index.php') ?>" class="btn btn-ghost btn-sm" title="Réinitialiser">
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

          <!-- Séparateur -->
          <?php if (!empty($filterATs)): ?>
          <div style="display:flex;align-items:flex-end;padding-bottom:1px;color:var(--border);font-size:18px">|</div>
          <?php endif; ?>

          <!-- Bloc / Activité type -->
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
      <?php if (!empty($activeFilters) || $search || $fileType): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;padding-top:4px">
        <?php if ($search): ?>
        <a href="<?= csFilterRemoveUrl('q') ?>" class="badge badge-primary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px">
          <i class="fas fa-search" style="font-size:10px"></i> <?= e(mb_substr($search, 0, 25)) ?> <i class="fas fa-times" style="font-size:9px;opacity:.7"></i>
        </a>
        <?php endif; ?>
        <?php if ($fileType): ?>
        <a href="<?= csFilterRemoveUrl('file_type') ?>" class="badge badge-secondary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px">
          <?= e($typeIcons[$fileType]['label'] ?? ucfirst($fileType)) ?> <i class="fas fa-times" style="font-size:9px;opacity:.7"></i>
        </a>
        <?php endif; ?>
        <?php foreach ($activeFilters as $key => $label): ?>
        <a href="<?= csFilterRemoveUrl($key) ?>" class="badge badge-secondary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px;max-width:220px">
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($label) ?></span>
          <i class="fas fa-times" style="font-size:9px;opacity:.7;flex-shrink:0"></i>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Liste -->
  <?php if (empty($caseStudies)): ?>
  <div class="empty-state">
    <div class="icon">📂</div>
    <h3><?= $hasFilters ? 'Aucune étude de cas ne correspond aux filtres' : 'Aucune étude de cas' ?></h3>
    <p><?= $hasFilters ? 'Essayez d\'élargir vos critères de recherche.' : 'Importez des documents, PDF ou présentations à soumettre à vos apprenants.' ?></p>
    <?php if ($hasFilters): ?>
    <a href="<?= url('teacher/case_studies/index.php') ?>" class="btn btn-secondary" style="margin-top:8px"><i class="fas fa-times"></i> Réinitialiser les filtres</a>
    <?php else: ?>
    <a href="<?= url('teacher/case_studies/create.php') ?>" class="btn btn-primary" style="margin-top:12px"><i class="fas fa-plus"></i> Importer une étude de cas</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px">
    <?php foreach ($caseStudies as $cs):
        $ti = $typeIcons[$cs['file_type']] ?? $typeIcons['document'];
        $pdfPages = null;
        if ($cs['file_type'] === 'pdf' && $cs['file_path']) {
            $dec = json_decode($cs['file_path'], true);
            if (is_array($dec)) $pdfPages = $dec;
        }
        $pdfCount = $pdfPages ? count($pdfPages) : ($cs['file_path'] ? 1 : 0);
    ?>
    <div class="card" style="display:flex;flex-direction:column">
      <div class="card-body" style="flex:1">
        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px">
          <div style="width:42px;height:42px;border-radius:10px;background:<?= $ti['color'] ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-<?= $ti['icon'] ?>" style="color:<?= $ti['color'] ?>;font-size:18px"></i>
          </div>
          <div style="flex:1;min-width:0">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:4px;color:white"><?= e($cs['title']) ?></h3>
            <?php if ($cs['description']): ?>
            <p style="font-size:12px;color:var(--text-muted);margin:0;line-height:1.4"><?= e(mb_substr($cs['description'],0,80)) ?><?= mb_strlen($cs['description'])>80?'…':'' ?></p>
            <?php endif; ?>
          </div>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
          <span class="badge badge-secondary"><i class="fas fa-<?= $ti['icon'] ?>"></i> <?= $ti['label'] ?></span>
          <?php if ($cs['file_type'] === 'pdf' && $pdfCount > 1): ?>
          <span class="badge badge-primary"><i class="fas fa-copy"></i> <?= $pdfCount ?> PDF</span>
          <?php endif; ?>
          <?php if ($cs['rncp_code']): ?>
          <span class="badge badge-secondary" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="RNCP : <?= e($cs['rncp_code']) ?>">
            <i class="fas fa-certificate" style="color:#8b5cf6"></i> <?= e($cs['rncp_code']) ?>
          </span>
          <?php endif; ?>
          <?php if ($cs['formation_title']): ?>
          <span class="badge badge-secondary" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($cs['formation_title']) ?>">
            <i class="fas fa-graduation-cap"></i> <?= e(mb_substr($cs['formation_title'],0,22)) ?>
          </span>
          <?php endif; ?>
          <?php if ($cs['module_title']): ?>
          <span class="badge badge-secondary" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="Module : <?= e($cs['module_title']) ?>">
            <i class="fas fa-cube"></i> <?= e(mb_substr($cs['module_title'],0,22)) ?>
          </span>
          <?php endif; ?>
          <?php if ($cs['at_code']): ?>
          <span class="badge badge-secondary" title="Bloc / Activité type : <?= e($cs['at_title']) ?>">
            <i class="fas fa-layer-group" style="color:#f59e0b"></i> <?= e($cs['at_code']) ?>
          </span>
          <?php endif; ?>
          <?php if ($cs['co_code']): ?>
          <span class="badge badge-secondary" title="Compétence : <?= e($cs['co_title']) ?>">
            <i class="fas fa-star" style="color:#ef4444"></i> <?= e($cs['co_code']) ?>
          </span>
          <?php endif; ?>
          <?php if ($cs['lesson_title']): ?>
          <span class="badge badge-secondary" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="Capsule : <?= e($cs['lesson_title']) ?>">
            <i class="fas fa-book-open"></i> <?= e(mb_substr($cs['lesson_title'],0,22)) ?>
          </span>
          <?php endif; ?>
        </div>

        <div style="font-size:11px;color:var(--text-faint)">
          <?= formatDate($cs['created_at'], 'd/m/Y à H:i') ?>
          <?php if (!$ownerOnly): ?> · <?= e($cs['author']) ?><?php endif; ?>
        </div>
      </div>

      <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:6px">
        <a href="<?= url('student/case_studies/view.php?id='.$cs['id']) ?>" target="_blank" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center">
          <i class="fas fa-eye"></i> Visualiser
        </a>
        <a href="<?= url('teacher/case_studies/edit.php?id='.$cs['id']) ?>" class="btn btn-ghost btn-sm" title="Modifier">
          <i class="fas fa-edit"></i>
        </a>
        <?php $canDelete = isAdmin() || isPedagogy() || $cs['created_by'] == $userId; ?>
        <?php if ($canDelete): ?>
        <form method="POST" action="<?= url('teacher/case_studies/delete.php') ?>"
              onsubmit="return confirm('Supprimer « <?= e(addslashes($cs['title'])) ?> » définitivement ?')">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $cs['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="Supprimer">
            <i class="fas fa-trash"></i>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php
  $paginationParams = array_filter(compact('search') + [
      'file_type'    => $fileType,
      'rncp_id'      => $rncpId      ?: '',
      'formation_id' => $formationId ?: '',
      'module_id'    => $moduleId    ?: '',
      'at_id'        => $atId        ?: '',
      'competency_id'=> $compId      ?: '',
  ]);
  echo $p['totalPages'] > 1 ? renderPagination($p, url('teacher/case_studies/index.php?' . http_build_query(array_filter($paginationParams)))) : '';
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
