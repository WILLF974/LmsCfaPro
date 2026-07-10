<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Section active : quiz | case_study ────────────────────────────────────────
$section = in_array($_GET['section'] ?? '', ['case_study']) ? 'case_study' : 'quiz';

// ── Filtres communs ───────────────────────────────────────────────────────────
$search      = trim($_GET['q']              ?? '');
$rncpId      = (int)($_GET['rncp_id']       ?? 0);
$formationId = (int)($_GET['formation_id']  ?? 0);
$moduleId    = (int)($_GET['module_id']     ?? 0);
$atId        = (int)($_GET['at_id']         ?? 0);
$compId      = (int)($_GET['competency_id'] ?? 0);
$page        = max(1, (int)($_GET['page']   ?? 1));

// ── Filtres spécifiques ───────────────────────────────────────────────────────
$quizId       = (int)($_GET['quiz_id']        ?? 0);   // section quiz
$reviewStatus = $_GET['review_status']        ?? '';   // section quiz (pending|graded|all)
$fileType     = $_GET['file_type']            ?? '';   // section études de cas
$subStatus    = $_GET['sub_status']           ?? '';   // section études de cas (submitted|graded)
$ownerOnly    = !isAdmin() && !isPedagogy();
$myQuizzes    = [];

// ════════════════════════════════════════════════════════════════════════════
// SECTION QUIZ
// ════════════════════════════════════════════════════════════════════════════
if ($section === 'quiz') {

    $where  = ['qa.status = ?'];
    $params = ['completed'];

    if ($search)        { $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($quizId)        { $where[] = 'qa.quiz_id = ?';            $params[] = $quizId; }
    if ($reviewStatus === 'pending') { $where[] = "qa.review_status = 'pending'"; }
    elseif ($reviewStatus === 'graded') { $where[] = "qa.review_status = 'graded'"; }
    elseif ($reviewStatus === 'submitted') { $where[] = "qa.submitted_for_review = 1"; }
    if ($rncpId)        { $where[] = 'f.rncp_title_id = ?';       $params[] = $rncpId; }
    if ($formationId)   { $where[] = 'q.formation_id = ?';        $params[] = $formationId; }
    if ($moduleId)      { $where[] = 'q.module_id = ?';           $params[] = $moduleId; }
    if ($atId)          { $where[] = 'q.activity_type_id = ?';    $params[] = $atId; }
    if ($compId)        { $where[] = 'q.competency_id = ?';       $params[] = $compId; }

    $ws = implode(' AND ', $where);

    $totalStmt = $pdo->prepare("
        SELECT COUNT(*) FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        JOIN users u ON qa.user_id = u.id
        LEFT JOIN formations f ON qa.formation_id = f.id
        WHERE $ws
    ");
    $totalStmt->execute($params);
    $p = paginate((int)$totalStmt->fetchColumn(), 20, $page);

    $stmt = $pdo->prepare("
        SELECT qa.*, q.title AS quiz_title, q.passing_score, q.quiz_type,
               u.first_name, u.last_name, u.email,
               f.title AS formation_title, f.rncp_title_id,
               rt.rncp_code,
               at.code AS at_code, at.title AS at_title,
               co.code AS co_code, co.title AS co_title
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        JOIN users u ON qa.user_id = u.id
        LEFT JOIN formations f ON qa.formation_id = f.id
        LEFT JOIN rncp_titles rt ON f.rncp_title_id = rt.id
        LEFT JOIN activity_types at ON q.activity_type_id = at.id
        LEFT JOIN competencies co ON q.competency_id = co.id
        WHERE $ws
        ORDER BY
          CASE qa.review_status WHEN 'pending' THEN 0 ELSE 1 END,
          qa.completed_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
    $attempts = $stmt->fetchAll();

    // Stats globales quiz
    $statsStmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               ROUND(AVG(qa.score),1) AS avg_score,
               SUM(CASE WHEN qa.passed=1 THEN 1 ELSE 0 END) AS passed,
               SUM(CASE WHEN qa.review_status='pending' THEN 1 ELSE 0 END) AS pending_reviews
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        WHERE qa.status='completed'
    ");
    $statsStmt->execute();
    $stats = $statsStmt->fetch();

    // Liste des quiz pour le filtre
    $myQuizzesStmt = $pdo->prepare("SELECT id, title FROM quizzes ORDER BY title");
    $myQuizzesStmt->execute();
    $myQuizzes = $myQuizzesStmt->fetchAll();

    // Données filtres pédagogiques
    $filterRncpsStmt = $pdo->prepare("
        SELECT DISTINCT rt.id, rt.rncp_code, rt.title FROM rncp_titles rt
        JOIN formations f ON f.rncp_title_id = rt.id
        JOIN quizzes q ON q.formation_id = f.id
        ORDER BY rt.rncp_code
    ");
    $filterRncpsStmt->execute();
    $filterRncps = $filterRncpsStmt->fetchAll();

    $fSql = "SELECT DISTINCT f.id, f.title FROM formations f JOIN quizzes q ON q.formation_id = f.id WHERE 1=1";
    $fP   = [];
    if ($rncpId) { $fSql .= " AND f.rncp_title_id = ?"; $fP[] = $rncpId; }
    $fSql .= " ORDER BY f.title";
    $filterFormations = $pdo->prepare($fSql); $filterFormations->execute($fP); $filterFormations = $filterFormations->fetchAll();

    $filterModules = [];
    if ($formationId) {
        $s = $pdo->prepare("SELECT DISTINCT m.id, m.title FROM modules m JOIN quizzes q ON q.module_id = m.id WHERE q.formation_id = ? ORDER BY m.order_num, m.title");
        $s->execute([$formationId]); $filterModules = $s->fetchAll();
    }

// ════════════════════════════════════════════════════════════════════════════
// SECTION ÉTUDES DE CAS
// ════════════════════════════════════════════════════════════════════════════
} else {

    // Section études de cas : on affiche les SOUMISSIONS d'étudiants
    $where  = ['cs.created_by = ?'];
    $params = [$userId];
    if ($search)      { $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR cs.title LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($subStatus)   { $where[] = 'css.status = ?';              $params[] = $subStatus; }
    if ($fileType)    { $where[] = 'cs.file_type = ?';            $params[] = $fileType; }
    if ($rncpId)      { $where[] = 'f.rncp_title_id = ?';         $params[] = $rncpId; }
    if ($formationId) { $where[] = 'css.formation_id = ?';        $params[] = $formationId; }
    if ($moduleId)    { $where[] = 'cs.module_id = ?';            $params[] = $moduleId; }
    if ($atId)        { $where[] = 'cs.activity_type_id = ?';     $params[] = $atId; }
    if ($compId)      { $where[] = 'cs.competency_id = ?';        $params[] = $compId; }
    $ws = 'WHERE ' . implode(' AND ', $where);

    $totalStmt = $pdo->prepare("
        SELECT COUNT(*) FROM case_study_submissions css
        JOIN case_studies cs ON css.case_study_id = cs.id
        JOIN users u ON css.user_id = u.id
        LEFT JOIN formations f ON css.formation_id = f.id
        $ws
    ");
    $totalStmt->execute($params);
    $p = paginate((int)$totalStmt->fetchColumn(), 15, $page);

    $stmt = $pdo->prepare("
        SELECT css.*, cs.title AS cs_title, cs.file_type,
               u.first_name, u.last_name, u.email,
               f.title AS formation_title, f.rncp_title_id,
               rt.rncp_code,
               at.code AS at_code, at.title AS at_title,
               co.code AS co_code, co.title AS co_title
        FROM case_study_submissions css
        JOIN case_studies cs ON css.case_study_id = cs.id
        JOIN users u ON css.user_id = u.id
        LEFT JOIN formations f     ON css.formation_id      = f.id
        LEFT JOIN rncp_titles rt   ON f.rncp_title_id       = rt.id
        LEFT JOIN activity_types at ON cs.activity_type_id  = at.id
        LEFT JOIN competencies co   ON cs.competency_id     = co.id
        $ws
        ORDER BY
          CASE css.status WHEN 'submitted' THEN 0 WHEN 'under_review' THEN 1 ELSE 2 END,
          css.submitted_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
    $submissions = $stmt->fetchAll();

    // Stats soumissions études de cas
    $statsStmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN css.status IN ('submitted','under_review') THEN 1 ELSE 0 END) AS pending_reviews,
               SUM(CASE WHEN css.status='graded' THEN 1 ELSE 0 END) AS graded
        FROM case_study_submissions css
        JOIN case_studies cs ON css.case_study_id = cs.id
        WHERE cs.created_by = ?
    ");
    $statsStmt->execute([$userId]);
    $stats = $statsStmt->fetch();

    // Données filtres pédagogiques (depuis les soumissions)
    $ownerJoinCS = "AND cs.created_by = $userId";
    $filterRncpsStmt = $pdo->prepare("
        SELECT DISTINCT rt.id, rt.rncp_code, rt.title FROM rncp_titles rt
        JOIN formations f ON f.rncp_title_id = rt.id
        JOIN case_study_submissions css ON css.formation_id = f.id
        JOIN case_studies cs ON css.case_study_id = cs.id $ownerJoinCS
        ORDER BY rt.rncp_code
    ");
    $filterRncpsStmt->execute([]); $filterRncps = $filterRncpsStmt->fetchAll();

    $fSql = "SELECT DISTINCT f.id, f.title FROM formations f JOIN case_study_submissions css ON css.formation_id = f.id JOIN case_studies cs ON css.case_study_id = cs.id $ownerJoinCS WHERE 1=1";
    $fP   = [];
    if ($rncpId) { $fSql .= " AND f.rncp_title_id = ?"; $fP[] = $rncpId; }
    $fSql .= " ORDER BY f.title";
    $filterFormations = $pdo->prepare($fSql); $filterFormations->execute($fP); $filterFormations = $filterFormations->fetchAll();

    $filterModules = [];
    if ($formationId) {
        $mSql = "SELECT DISTINCT m.id, m.title FROM modules m JOIN case_studies cs ON cs.module_id = m.id JOIN case_study_submissions css ON css.case_study_id = cs.id WHERE m.formation_id = ? $ownerJoinCS ORDER BY m.order_num, m.title";
        $s = $pdo->prepare($mSql); $s->execute([$formationId]); $filterModules = $s->fetchAll();
    }
}

// ── ATs et compétences (commun aux deux sections) ─────────────────────────────
$filterATs = [];
$atRncpId  = $rncpId;
if (!$atRncpId && $formationId) {
    $r = $pdo->prepare("SELECT rncp_title_id FROM formations WHERE id = ?");
    $r->execute([$formationId]); $row = $r->fetch(); $atRncpId = $row ? (int)$row['rncp_title_id'] : 0;
}
if ($atRncpId) {
    $s = $pdo->prepare("SELECT id, code, title FROM activity_types WHERE rncp_title_id = ? ORDER BY order_num, code");
    $s->execute([$atRncpId]); $filterATs = $s->fetchAll();
}
$filterComps = [];
if ($atId) {
    $s = $pdo->prepare("SELECT id, code, title FROM competencies WHERE activity_type_id = ? ORDER BY order_num, code");
    $s->execute([$atId]); $filterComps = $s->fetchAll();
}

// ── Labels chips ──────────────────────────────────────────────────────────────
$activeFilters = [];
if ($rncpId) {
    foreach ($filterRncps as $r) { if ($r['id'] == $rncpId) { $activeFilters['rncp_id'] = 'RNCP : '.$r['rncp_code']; break; } }
    if (!isset($activeFilters['rncp_id'])) { $r = $pdo->prepare("SELECT rncp_code FROM rncp_titles WHERE id=?"); $r->execute([$rncpId]); $row=$r->fetch(); if ($row) $activeFilters['rncp_id']='RNCP : '.$row['rncp_code']; }
}
if ($formationId) { foreach ($filterFormations as $f) { if ($f['id']==$formationId) { $activeFilters['formation_id']=mb_substr($f['title'],0,28); break; } } }
if ($moduleId && $filterModules) { foreach ($filterModules as $m) { if ($m['id']==$moduleId) { $activeFilters['module_id']=mb_substr($m['title'],0,28); break; } } }
if ($atId && $filterATs) { foreach ($filterATs as $a) { if ($a['id']==$atId) { $activeFilters['at_id']=$a['code'].' — '.mb_substr($a['title'],0,22); break; } } }
if ($compId && $filterComps) { foreach ($filterComps as $c) { if ($c['id']==$compId) { $activeFilters['competency_id']=$c['code'].' — '.mb_substr($c['title'],0,22); break; } } }

$hasFilters = $search || $rncpId || $formationId || $moduleId || $atId || $compId
              || ($section==='quiz' ? ($quizId || $reviewStatus) : ($fileType || $subStatus));

$typeIconsCS = [
    'pdf'          => ['icon'=>'file-pdf',        'color'=>'#ef4444','label'=>'PDF'],
    'document'     => ['icon'=>'file-word',       'color'=>'#3b82f6','label'=>'Document'],
    'presentation' => ['icon'=>'file-powerpoint', 'color'=>'#f97316','label'=>'Présentation'],
    'video'        => ['icon'=>'play-circle',     'color'=>'#ef4444','label'=>'Vidéo'],
    'link'         => ['icon'=>'link',            'color'=>'#0ea5e9','label'=>'Lien'],
];

function evalFilterRemoveUrl(string $removeKey): string {
    $params = $_GET;
    unset($params[$removeKey], $params['page']);
    $cascade = ['rncp_id'=>['formation_id','module_id','at_id','competency_id'],'formation_id'=>['module_id','at_id','competency_id'],'at_id'=>['competency_id']];
    foreach ($cascade[$removeKey] ?? [] as $child) unset($params[$child]);
    return url('teacher/evaluations/index.php?' . http_build_query($params));
}

renderHead('Corrections & Résultats');
renderSidebar('teacher');
renderTopbar('Corrections & Résultats', [['Enseignant', url('teacher/index.php')], ['Corrections', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Sélecteur de section -->
  <div style="display:flex;gap:8px;margin-bottom:24px">
    <a href="<?= url('teacher/evaluations/index.php?section=quiz') ?>"
       class="btn <?= $section==='quiz' ? 'btn-primary' : 'btn-secondary' ?>"
       style="display:flex;align-items:center;gap:8px;padding:10px 20px">
      <i class="fas fa-tasks"></i>
      <span>Quiz & Évaluations</span>
    </a>
    <a href="<?= url('teacher/evaluations/index.php?section=case_study') ?>"
       class="btn <?= $section==='case_study' ? 'btn-primary' : 'btn-secondary' ?>"
       style="display:flex;align-items:center;gap:8px;padding:10px 20px">
      <i class="fas fa-folder-open"></i>
      <span>Études de cas</span>
    </a>
  </div>

  <!-- Stats -->
  <?php if ($section === 'quiz'): ?>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(99,102,241,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-list" style="color:var(--primary-light)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:white"><?= $stats['total'] ?? 0 ?></div><div style="font-size:12px;color:var(--text-muted)">Tentatives totales</div></div>
    </div></div>
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(16,185,129,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-check" style="color:var(--success)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:var(--success)"><?= $stats['passed'] ?? 0 ?></div><div style="font-size:12px;color:var(--text-muted)">Réussites</div></div>
    </div></div>
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(245,158,11,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-percentage" style="color:var(--warning)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:var(--warning)"><?= $stats['avg_score'] ?? 0 ?>%</div><div style="font-size:12px;color:var(--text-muted)">Score moyen</div></div>
    </div></div>
    <a href="<?= url('teacher/evaluations/index.php?section=quiz&review_status=pending') ?>" style="text-decoration:none">
    <div class="card" style="border:1px solid rgba(245,158,11,<?= ($stats['pending_reviews']??0)>0?'.5':'.15' ?>)"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(245,158,11,.2);display:flex;align-items:center;justify-content:center"><i class="fas fa-clock" style="color:var(--warning)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:var(--warning)"><?= $stats['pending_reviews'] ?? 0 ?></div><div style="font-size:12px;color:var(--text-muted)">À corriger</div></div>
    </div></div>
    </a>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px">
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(99,102,241,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-inbox" style="color:var(--primary-light)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:white"><?= $stats['total'] ?? 0 ?></div><div style="font-size:12px;color:var(--text-muted)">Soumissions reçues</div></div>
    </div></div>
    <a href="<?= url('teacher/evaluations/index.php?section=case_study&sub_status=submitted') ?>" style="text-decoration:none">
    <div class="card" style="border:1px solid rgba(245,158,11,<?= ($stats['pending_reviews']??0)>0?'.5':'.15' ?>)"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(245,158,11,.2);display:flex;align-items:center;justify-content:center"><i class="fas fa-clock" style="color:var(--warning)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:var(--warning)"><?= $stats['pending_reviews'] ?? 0 ?></div><div style="font-size:12px;color:var(--text-muted)">En attente</div></div>
    </div></div>
    </a>
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(16,185,129,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-check-circle" style="color:var(--success)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:var(--success)"><?= $stats['graded'] ?? 0 ?></div><div style="font-size:12px;color:var(--text-muted)">Corrigées</div></div>
    </div></div>
  </div>
  <?php endif; ?>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div>
        <h1><?= $section==='quiz' ? 'Résultats & corrections quiz' : 'Soumissions — Études de cas' ?></h1>
        <p><?= $p['total'] ?> <?= $section==='quiz' ? 'résultat(s)' : 'soumission(s)' ?></p>
      </div>
      <?php if ($section==='quiz'): ?>
      <a href="<?= url('api/export.php?type=evaluations') ?>" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Exporter CSV</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filtres -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px;display:flex;flex-direction:column;gap:12px">
      <form id="filter-form" method="GET">
        <input type="hidden" name="section" value="<?= $section ?>">

        <!-- Ligne 1 : recherche + filtre spécifique à la section -->
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <div class="search-input" style="flex:1;min-width:180px">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="<?= $section==='quiz' ? 'Rechercher un apprenant...' : 'Rechercher une étude de cas...' ?>" value="<?= e($search) ?>">
          </div>

          <?php if ($section === 'quiz'): ?>
          <select name="quiz_id" class="form-control" style="width:200px" onchange="cascadeSubmit('quiz_id')">
            <option value="">— Tous les quiz —</option>
            <?php foreach ($myQuizzes as $q): ?>
            <option value="<?= $q['id'] ?>" <?= $quizId==$q['id']?'selected':'' ?>><?= e(mb_substr($q['title'],0,30)) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="review_status" class="form-control" style="width:170px" onchange="cascadeSubmit('review_status')">
            <option value="">Tous statuts</option>
            <option value="pending" <?= $reviewStatus==='pending'?'selected':'' ?>>⏳ À corriger</option>
            <option value="graded"  <?= $reviewStatus==='graded'?'selected':'' ?>>✅ Corrigés</option>
            <option value="submitted" <?= $reviewStatus==='submitted'?'selected':'' ?>>📤 Soumis (tous)</option>
          </select>
          <?php else: ?>
          <select name="sub_status" class="form-control" style="width:170px" onchange="cascadeSubmit('sub_status')">
            <option value="">Tous statuts</option>
            <option value="submitted"    <?= $subStatus==='submitted'?'selected':'' ?>>⏳ À corriger</option>
            <option value="under_review" <?= $subStatus==='under_review'?'selected':'' ?>>🔍 En cours</option>
            <option value="graded"       <?= $subStatus==='graded'?'selected':'' ?>>✅ Corrigés</option>
            <option value="returned"     <?= $subStatus==='returned'?'selected':'' ?>>↩️ Retournés</option>
          </select>
          <select name="file_type" class="form-control" style="width:150px" onchange="cascadeSubmit('file_type')">
            <option value="">Tous types</option>
            <option value="pdf"          <?= $fileType==='pdf'?'selected':'' ?>>PDF</option>
            <option value="document"     <?= $fileType==='document'?'selected':'' ?>>Document</option>
            <option value="presentation" <?= $fileType==='presentation'?'selected':'' ?>>Présentation</option>
            <option value="video"        <?= $fileType==='video'?'selected':'' ?>>Vidéo</option>
          </select>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtrer</button>
          <?php if ($hasFilters): ?>
          <a href="<?= url('teacher/evaluations/index.php?section='.$section) ?>" class="btn btn-ghost btn-sm" title="Réinitialiser">
            <i class="fas fa-times"></i> Réinitialiser
          </a>
          <?php endif; ?>
        </div>

        <!-- Ligne 2 : filtres pédagogiques en cascade -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;border-top:1px solid var(--border);padding-top:12px">

          <div style="min-width:160px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
              <i class="fas fa-certificate" style="color:#8b5cf6;margin-right:4px"></i>Titre RNCP
            </label>
            <select name="rncp_id" class="form-control" style="font-size:12px" onchange="cascadeSubmit('rncp_id')">
              <option value="">— Tous —</option>
              <?php foreach ($filterRncps as $r): ?>
              <option value="<?= $r['id'] ?>" <?= $rncpId==$r['id']?'selected':'' ?>><?= e($r['rncp_code']) ?> — <?= e(mb_substr($r['title'],0,28)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="min-width:160px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
              <i class="fas fa-graduation-cap" style="color:#0ea5e9;margin-right:4px"></i>Formation
            </label>
            <select name="formation_id" class="form-control" style="font-size:12px" onchange="cascadeSubmit('formation_id')">
              <option value="">— Toutes —</option>
              <?php foreach ($filterFormations as $f): ?>
              <option value="<?= $f['id'] ?>" <?= $formationId==$f['id']?'selected':'' ?>><?= e(mb_substr($f['title'],0,35)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($formationId && !empty($filterModules)): ?>
          <div style="min-width:150px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
              <i class="fas fa-cube" style="color:#10b981;margin-right:4px"></i>Module
            </label>
            <select name="module_id" class="form-control" style="font-size:12px" onchange="cascadeSubmit('module_id')">
              <option value="">— Tous —</option>
              <?php foreach ($filterModules as $m): ?>
              <option value="<?= $m['id'] ?>" <?= $moduleId==$m['id']?'selected':'' ?>><?= e(mb_substr($m['title'],0,35)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <?php if (!empty($filterATs)): ?>
          <div style="display:flex;align-items:flex-end;padding-bottom:1px;color:var(--border);font-size:18px">|</div>
          <div style="min-width:180px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
              <i class="fas fa-layer-group" style="color:#f59e0b;margin-right:4px"></i>Bloc / Activité type
            </label>
            <select name="at_id" class="form-control" style="font-size:12px" onchange="cascadeSubmit('at_id')">
              <option value="">— Tous —</option>
              <?php foreach ($filterATs as $a): ?>
              <option value="<?= $a['id'] ?>" <?= $atId==$a['id']?'selected':'' ?>><?= e($a['code']) ?> — <?= e(mb_substr($a['title'],0,28)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <?php if ($atId && !empty($filterComps)): ?>
          <div style="min-width:180px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px">
              <i class="fas fa-star" style="color:#ef4444;margin-right:4px"></i>Compétence
            </label>
            <select name="competency_id" class="form-control" style="font-size:12px" onchange="cascadeSubmit('competency_id')">
              <option value="">— Toutes —</option>
              <?php foreach ($filterComps as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $compId==$c['id']?'selected':'' ?>><?= e($c['code']) ?> — <?= e(mb_substr($c['title'],0,28)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

        </div>
      </form>

      <!-- Chips filtres actifs -->
      <?php
      $chip1Key = $section==='quiz' ? 'quiz_id' : 'file_type';
      $chip1Val = $section==='quiz' ? ($quizId ? ($myQuizzes ? (function() use ($myQuizzes,$quizId){ foreach($myQuizzes as $q){ if($q['id']==$quizId) return mb_substr($q['title'],0,28); } return ''; })() : '') : '') : ($fileType ? ($typeIconsCS[$fileType]['label'] ?? ucfirst($fileType)) : '');
      $hasChips = !empty($activeFilters) || $search || $chip1Val;
      ?>
      <?php if ($hasChips): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;padding-top:4px">
        <?php if ($search): ?>
        <a href="<?= evalFilterRemoveUrl('q') ?>" class="badge badge-primary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px">
          <i class="fas fa-search" style="font-size:10px"></i> <?= e(mb_substr($search,0,25)) ?> <i class="fas fa-times" style="font-size:9px;opacity:.7"></i>
        </a>
        <?php endif; ?>
        <?php if ($chip1Val): ?>
        <a href="<?= evalFilterRemoveUrl($chip1Key) ?>" class="badge badge-secondary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px">
          <?= e($chip1Val) ?> <i class="fas fa-times" style="font-size:9px;opacity:.7"></i>
        </a>
        <?php endif; ?>
        <?php foreach ($activeFilters as $key => $label): ?>
        <a href="<?= evalFilterRemoveUrl($key) ?>" class="badge badge-secondary" style="text-decoration:none;cursor:pointer;display:flex;align-items:center;gap:5px;max-width:220px">
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($label) ?></span>
          <i class="fas fa-times" style="font-size:9px;opacity:.7;flex-shrink:0"></i>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- ═══════════════════ CONTENU : QUIZ ═══════════════════ -->
  <?php if ($section === 'quiz'): ?>

  <?php if (empty($attempts)): ?>
  <div class="empty-state">
    <div class="icon">📊</div>
    <h3><?= $hasFilters ? 'Aucun résultat ne correspond aux filtres' : 'Aucun résultat' ?></h3>
    <p><?= $hasFilters ? 'Essayez d\'élargir vos critères.' : 'Vos apprenants n\'ont pas encore complété de quiz.' ?></p>
    <?php if ($hasFilters): ?>
    <a href="<?= url('teacher/evaluations/index.php?section=quiz') ?>" class="btn btn-secondary" style="margin-top:8px"><i class="fas fa-times"></i> Réinitialiser</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="table">
        <thead>
          <tr>
            <th>Apprenant</th>
            <th>Quiz</th>
            <th>Rattachement</th>
            <th>Score</th>
            <th>Résultat</th>
            <th>Correction</th>
            <th>Durée</th>
            <th>Tentative</th>
            <th>Date</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($attempts as $a):
            $passed = (bool)$a['passed'];
            $score  = round($a['score'] ?? 0);
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div class="avatar avatar-sm" style="background:<?= getAvatarColor($a['first_name'].$a['last_name']) ?>"><?= getAvatarInitials($a['first_name'],$a['last_name']) ?></div>
                <div>
                  <div style="font-weight:600;font-size:13px"><?= e($a['first_name'].' '.$a['last_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted)"><?= e($a['email']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <div style="font-size:13px;font-weight:600"><?= e(mb_substr($a['quiz_title'],0,32)) ?></div>
              <?php if ($a['formation_title']): ?><div style="font-size:11px;color:var(--text-muted)"><?= e(mb_substr($a['formation_title'],0,28)) ?></div><?php endif; ?>
            </td>
            <td>
              <div style="display:flex;flex-direction:column;gap:3px">
                <?php if ($a['rncp_code']): ?><span style="font-size:10px;color:var(--text-faint)"><i class="fas fa-certificate" style="color:#8b5cf6"></i> <?= e($a['rncp_code']) ?></span><?php endif; ?>
                <?php if ($a['at_code']): ?><span class="badge badge-secondary" style="font-size:10px" title="<?= e($a['at_title']) ?>"><i class="fas fa-layer-group" style="color:#f59e0b"></i> <?= e($a['at_code']) ?></span><?php endif; ?>
                <?php if ($a['co_code']): ?><span class="badge badge-secondary" style="font-size:10px" title="<?= e($a['co_title']) ?>"><i class="fas fa-star" style="color:#ef4444"></i> <?= e($a['co_code']) ?></span><?php endif; ?>
                <?php if (!$a['rncp_code'] && !$a['at_code'] && !$a['co_code']): ?><span style="font-size:11px;color:var(--text-faint)">—</span><?php endif; ?>
              </div>
            </td>
            <td>
              <div style="font-size:16px;font-weight:800;color:<?= $passed?'var(--success)':'var(--danger)' ?>"><?= $score ?>%</div>
              <?php if ($a['teacher_score'] !== null): ?><div style="font-size:10px;color:var(--primary-light)"><i class="fas fa-pen-fancy"></i> <?= $a['teacher_score'] ?>/20 par enseignant</div><?php endif; ?>
              <div style="font-size:10px;color:var(--text-muted)">seuil : <?= $a['passing_score'] ?>%</div>
            </td>
            <td><?= $passed ? '<span class="badge badge-success"><i class="fas fa-check"></i> Réussi</span>' : '<span class="badge badge-danger"><i class="fas fa-times"></i> Échec</span>' ?></td>
            <td>
              <?php
              $rs = $a['review_status'] ?? 'not_submitted';
              if ($rs === 'pending'): ?><span class="badge badge-warning" style="font-size:10px;animation:pulse 2s infinite"><i class="fas fa-clock"></i> À corriger</span>
              <?php elseif ($rs === 'graded'): ?><span class="badge badge-success" style="font-size:10px"><i class="fas fa-check-circle"></i> Corrigé</span>
              <?php else: ?><span style="font-size:11px;color:var(--text-faint)">—</span><?php endif; ?>
            </td>
            <td style="font-size:13px"><?= $a['time_spent_seconds'] ? round($a['time_spent_seconds']/60).' min' : '—' ?></td>
            <td><span class="badge badge-secondary">#<?= $a['attempt_number'] ?></span></td>
            <td style="font-size:12px;color:var(--text-muted)"><?= $a['completed_at'] ? formatDateTime($a['completed_at']) : '—' ?></td>
            <td>
              <a href="<?= url('teacher/evaluations/grade.php?type=quiz&id='.$a['id']) ?>"
                 class="btn <?= ($a['review_status']??'')==='pending' ? 'btn-warning' : 'btn-ghost' ?> btn-sm"
                 title="<?= ($a['review_status']??'')==='pending' ? 'Corriger maintenant' : 'Voir / Corriger' ?>">
                <i class="fas fa-<?= ($a['review_status']??'')==='pending' ? 'pen-fancy' : 'eye' ?>"></i>
                <?= ($a['review_status']??'')==='pending' ? 'Corriger' : 'Voir' ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
  $pp = array_filter(['section'=>'quiz','q'=>$search,'quiz_id'=>$quizId?:'','rncp_id'=>$rncpId?:'','formation_id'=>$formationId?:'','module_id'=>$moduleId?:'','at_id'=>$atId?:'','competency_id'=>$compId?:'']);
  echo $p['totalPages']>1 ? renderPagination($p, url('teacher/evaluations/index.php?'.http_build_query(array_filter($pp)))) : '';
  ?>
  <?php endif; ?>

  <!-- ═══════════════════ CONTENU : ÉTUDES DE CAS ═══════════════════ -->
  <?php else: ?>

  <?php if (empty($submissions)): ?>
  <div class="empty-state">
    <div class="icon">📬</div>
    <h3><?= $hasFilters ? 'Aucune soumission ne correspond aux filtres' : 'Aucune soumission reçue' ?></h3>
    <p><?= $hasFilters ? 'Essayez d\'élargir vos critères.' : 'Vos apprenants n\'ont pas encore soumis de travail sur vos études de cas.' ?></p>
    <?php if ($hasFilters): ?>
    <a href="<?= url('teacher/evaluations/index.php?section=case_study') ?>" class="btn btn-secondary" style="margin-top:8px"><i class="fas fa-times"></i> Réinitialiser</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <?php
  $subStatusLabels = ['submitted'=>['label'=>'À corriger','color'=>'var(--warning)','icon'=>'clock'],'under_review'=>['label'=>'En cours','color'=>'var(--info)','icon'=>'search'],'graded'=>['label'=>'Corrigé','color'=>'var(--success)','icon'=>'check-circle'],'returned'=>['label'=>'Retourné','color'=>'var(--danger)','icon'=>'undo']];
  ?>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="table">
        <thead>
          <tr>
            <th>Apprenant</th>
            <th>Étude de cas</th>
            <th>Formation</th>
            <th>Rattachement</th>
            <th>Statut</th>
            <th>Note</th>
            <th>Soumis le</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($submissions as $sub):
            $sl = $subStatusLabels[$sub['status']] ?? $subStatusLabels['submitted'];
            $ti = $typeIconsCS[$sub['file_type']] ?? $typeIconsCS['document'];
            $needsAction = in_array($sub['status'], ['submitted','under_review']);
          ?>
          <tr style="<?= $needsAction ? 'background:rgba(245,158,11,.04)' : '' ?>">
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div class="avatar avatar-sm" style="background:<?= getAvatarColor($sub['first_name'].$sub['last_name']) ?>"><?= getAvatarInitials($sub['first_name'],$sub['last_name']) ?></div>
                <div>
                  <div style="font-weight:600;font-size:13px"><?= e($sub['first_name'].' '.$sub['last_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted)"><?= e($sub['email']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="width:28px;height:28px;border-radius:6px;background:<?= $ti['color'] ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <i class="fas fa-<?= $ti['icon'] ?>" style="color:<?= $ti['color'] ?>;font-size:12px"></i>
                </div>
                <span style="font-size:13px;font-weight:600"><?= e(mb_substr($sub['cs_title'],0,30)) ?></span>
              </div>
            </td>
            <td>
              <div style="font-size:12px"><?= $sub['formation_title'] ? e(mb_substr($sub['formation_title'],0,26)) : '<span style="color:var(--text-faint)">—</span>' ?></div>
              <?php if ($sub['rncp_code']): ?><div style="font-size:10px;color:var(--text-faint)"><?= e($sub['rncp_code']) ?></div><?php endif; ?>
            </td>
            <td>
              <div style="display:flex;flex-direction:column;gap:3px">
                <?php if ($sub['at_code']): ?><span class="badge badge-secondary" style="font-size:10px" title="<?= e($sub['at_title']) ?>"><i class="fas fa-layer-group" style="color:#f59e0b"></i> <?= e($sub['at_code']) ?></span><?php endif; ?>
                <?php if ($sub['co_code']): ?><span class="badge badge-secondary" style="font-size:10px" title="<?= e($sub['co_title']) ?>"><i class="fas fa-star" style="color:#ef4444"></i> <?= e($sub['co_code']) ?></span><?php endif; ?>
                <?php if (!$sub['at_code'] && !$sub['co_code']): ?><span style="font-size:11px;color:var(--text-faint)">—</span><?php endif; ?>
              </div>
            </td>
            <td>
              <span class="badge" style="background:<?= $sl['color'] ?>22;color:<?= $sl['color'] ?>;border:1px solid <?= $sl['color'] ?>44;font-size:11px">
                <i class="fas fa-<?= $sl['icon'] ?>"></i> <?= $sl['label'] ?>
              </span>
            </td>
            <td>
              <?php if ($sub['score'] !== null): ?>
              <span style="font-size:14px;font-weight:700;color:var(--success)"><?= $sub['score'] ?><span style="font-size:10px;color:var(--text-muted)">/<?= $sub['max_score'] ?></span></span>
              <?php if ($sub['grade']): ?><div style="font-size:10px;color:var(--text-muted)"><?= e($sub['grade']) ?></div><?php endif; ?>
              <?php else: ?><span style="color:var(--text-faint)">—</span><?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--text-muted)"><?= formatDate($sub['submitted_at'],'d/m/Y') ?><div style="font-size:10px"><?= formatDate($sub['submitted_at'],'H:i') ?></div></td>
            <td>
              <a href="<?= url('teacher/evaluations/grade.php?type=case_study&id='.$sub['id']) ?>"
                 class="btn <?= $needsAction ? 'btn-warning' : 'btn-ghost' ?> btn-sm">
                <i class="fas fa-<?= $needsAction ? 'pen-fancy' : 'eye' ?>"></i>
                <?= $needsAction ? 'Corriger' : 'Voir' ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
  $pp = array_filter(['section'=>'case_study','q'=>$search,'file_type'=>$fileType,'rncp_id'=>$rncpId?:'','formation_id'=>$formationId?:'','module_id'=>$moduleId?:'','at_id'=>$atId?:'','competency_id'=>$compId?:'']);
  echo $p['totalPages']>1 ? renderPagination($p, url('teacher/evaluations/index.php?'.http_build_query(array_filter($pp)))) : '';
  ?>
  <?php endif; ?>
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
