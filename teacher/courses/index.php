<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Filtres ───────────────────────────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$type      = $_GET['type'] ?? '';
$rncpId    = (int)($_GET['rncp_id']       ?? 0);
$atId      = (int)($_GET['at_id']         ?? 0);
$compId    = (int)($_GET['competency_id'] ?? 0);
$seqFilter = (int)($_GET['seq_id']        ?? 0);
$page      = max(1, (int)($_GET['page']   ?? 1));
$highlight = (int)($_GET['highlight']     ?? 0);
$hasFilters = $search || $type || $rncpId || $atId || $compId || $seqFilter;

$where  = ['1=1'];
$params = [];
if ($search)    { $where[] = 'm.title LIKE ?';        $params[] = '%'.$search.'%'; }
if ($type)      { $where[] = 'm.content_type = ?';    $params[] = $type; }
if ($rncpId)    { $where[] = 'at.rncp_title_id = ?';  $params[] = $rncpId; }
if ($atId)      { $where[] = 'at.id = ?';             $params[] = $atId; }
if ($compId)    { $where[] = 'c.id = ?';              $params[] = $compId; }
if ($seqFilter) { $where[] = 'm.sequence_id = ?';     $params[] = $seqFilter; }
$ws = implode(' AND ', $where);

$baseJoins = "
    FROM modules m
    LEFT JOIN formations f ON m.formation_id = f.id
    LEFT JOIN rncp_titles rt ON f.rncp_title_id = rt.id
    LEFT JOIN sequences seq ON m.sequence_id = seq.id
    LEFT JOIN competencies c ON seq.competency_id = c.id
    LEFT JOIN activity_types at ON c.activity_type_id = at.id
    LEFT JOIN users u ON m.created_by = u.id
";

$sStmt = $pdo->prepare("
    SELECT m.*,
           f.title AS formation_title, rt.rncp_code,
           seq.title AS sequence_title,
           c.id AS comp_id, c.code AS comp_code, c.title AS comp_title,
           at.id AS at_id_val, at.code AS at_code, at.title AS at_title,
           u.first_name, u.last_name, u.role AS creator_role,
           (SELECT COUNT(*) FROM module_progress mp WHERE mp.module_id = m.id AND mp.status='completed') AS completed_count,
           (SELECT COUNT(*) FROM module_progress mp WHERE mp.module_id = m.id) AS started_count
    $baseJoins
    WHERE $ws
    ORDER BY at.code, c.code, seq.order_num, m.order_num
");
$sStmt->execute($params);
$seances = $sStmt->fetchAll();

$tree = [];

// Charger d'abord toutes les séquences (même sans séances) en respectant les filtres structurels
if (!$search && !$type) {
    $sqWhere2 = ['1=1']; $sqParams2 = [];
    if ($rncpId)    { $sqWhere2[] = 'at.rncp_title_id = ?'; $sqParams2[] = $rncpId; }
    if ($atId)      { $sqWhere2[] = 'at.id = ?';            $sqParams2[] = $atId; }
    if ($compId)    { $sqWhere2[] = 'c.id = ?';             $sqParams2[] = $compId; }
    if ($seqFilter) { $sqWhere2[] = 'seq.id = ?';           $sqParams2[] = $seqFilter; }
    $sqStmt2 = $pdo->prepare("
        SELECT seq.id AS seq_id, seq.title AS seq_title,
               c.id AS comp_id, c.code AS comp_code, c.title AS comp_title,
               at.id AS at_id_val, at.code AS at_code, at.title AS at_title
        FROM sequences seq
        LEFT JOIN competencies c ON seq.competency_id = c.id
        LEFT JOIN activity_types at ON c.activity_type_id = at.id
        WHERE " . implode(' AND ', $sqWhere2) . "
        ORDER BY at.code, c.code, seq.order_num
    ");
    $sqStmt2->execute($sqParams2);
    foreach ($sqStmt2->fetchAll() as $sq) {
        $ak = $sq['at_id_val'] ?: '__none';
        $ck = $sq['comp_id']   ?: '__none';
        $sk = (string)$sq['seq_id'];
        if (!isset($tree[$ak]))                            $tree[$ak]                              = ['code'=>$sq['at_code'],'title'=>$sq['at_title'],'comps'=>[]];
        if (!isset($tree[$ak]['comps'][$ck]))              $tree[$ak]['comps'][$ck]               = ['code'=>$sq['comp_code'],'title'=>$sq['comp_title'],'seqs'=>[]];
        if (!isset($tree[$ak]['comps'][$ck]['seqs'][$sk])) $tree[$ak]['comps'][$ck]['seqs'][$sk] = ['id'=>$sq['seq_id'],'title'=>$sq['seq_title'],'items'=>[]];
    }
}

// Ajouter les séances dans l'arborescence
foreach ($seances as $s) {
    $ak = $s['at_id_val'] ?: '__none';
    $ck = $s['comp_id']   ?: '__none';
    $sk = $s['sequence_id'] !== null ? (string)$s['sequence_id'] : '__none';
    if (!isset($tree[$ak]))                            $tree[$ak]                              = ['code'=>$s['at_code'],'title'=>$s['at_title'],'comps'=>[]];
    if (!isset($tree[$ak]['comps'][$ck]))              $tree[$ak]['comps'][$ck]               = ['code'=>$s['comp_code'],'title'=>$s['comp_title'],'seqs'=>[]];
    if (!isset($tree[$ak]['comps'][$ck]['seqs'][$sk])) $tree[$ak]['comps'][$ck]['seqs'][$sk] = ['id'=>$s['sequence_id'],'title'=>$s['sequence_title'],'items'=>[]];
    $tree[$ak]['comps'][$ck]['seqs'][$sk]['items'][] = $s;
}

// Stats par type (toutes séances)
$statsStmt = $pdo->query("SELECT content_type, COUNT(*) AS cnt FROM modules GROUP BY content_type");
$typeStats = array_column($statsStmt->fetchAll(), 'cnt', 'content_type');
$totalSeances = (int)$pdo->query("SELECT COUNT(*) FROM modules")->fetchColumn();

// Données pour la cascade filtre côté client
$seanceFilterRncps = $pdo->query("SELECT id, rncp_code, title FROM rncp_titles WHERE status='active' ORDER BY rncp_code")->fetchAll();
$seanceFilterATs   = $pdo->query("SELECT id, rncp_title_id, code, title FROM activity_types ORDER BY rncp_title_id, order_num")->fetchAll();
$seanceFilterComps = $pdo->query("SELECT id, activity_type_id, code, title FROM competencies ORDER BY activity_type_id, order_num")->fetchAll();
$seanceFilterSeqs  = $pdo->query("SELECT id, competency_id, title FROM sequences ORDER BY competency_id, order_num")->fetchAll();

renderHead('Séances');
renderSidebar(isAdmin() ? 'admin' : (isPedagogy() ? 'pedagogy' : 'teacher'));
renderTopbar('Séances', [['Enseignant', url('teacher/index.php')], ['Séances', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
    <?php foreach (['video'=>['Vidéos','fa-play-circle','#ef4444'],'pdf'=>['PDF','fa-file-pdf','#f59e0b'],'quiz'=>['Quizz','fa-question-circle','#8b5cf6'],'text'=>['Textes','fa-align-left','#6366f1']] as $t => [$label, $icon, $color]): ?>
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px;padding:14px">
      <div style="width:40px;height:40px;border-radius:var(--radius);background:<?= $color ?>22;display:flex;align-items:center;justify-content:center">
        <i class="fas <?= $icon ?>" style="color:<?= $color ?>"></i>
      </div>
      <div>
        <div style="font-size:20px;font-weight:800;color:white"><?= $typeStats[$t] ?? 0 ?></div>
        <div style="font-size:11px;color:var(--text-muted)"><?= $label ?></div>
      </div>
    </div></div>
    <?php endforeach; ?>
  </div>

  <!-- Header + action -->
  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
      <div style="font-weight:700;font-size:15px">
        <i class="fas fa-bookmark" style="color:#0ea5e9;margin-right:8px"></i>Séances <span class="badge badge-secondary" style="font-size:10px;padding:2px 7px"><?= $totalSeances ?></span>
      </div>
      <a href="<?= url('teacher/courses/seance_create.php') ?>" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> Nouvelle séance
      </a>
    </div>
  </div>

  <!-- Filtres -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px">
      <form id="filter-form" method="GET">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
          <div class="search-input" style="flex:1;min-width:180px">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Rechercher une séance..." value="<?= e($search) ?>">
          </div>
          <select name="type" class="form-control" style="width:160px">
            <option value="">Tous types</option>
            <?php foreach (['video'=>'Vidéo','pdf'=>'PDF','document'=>'Document','presentation'=>'Présentation','quiz'=>'Quizz','exercise'=>'Exercice','text'=>'Texte','link'=>'Lien'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $type === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <div style="min-width:170px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px"><i class="fas fa-certificate" style="color:#8b5cf6;margin-right:4px"></i>RNCP</label>
            <select id="sf-rncp" name="rncp_id" class="form-control" style="font-size:12px">
              <option value="">— Tous —</option>
              <?php foreach ($seanceFilterRncps as $r): ?><option value="<?= $r['id'] ?>" data-code="<?= e($r['rncp_code']) ?>" <?= $rncpId==$r['id']?'selected':'' ?>><?= e($r['rncp_code']) ?> — <?= e($r['title']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="min-width:190px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px"><i class="fas fa-layer-group" style="color:#f59e0b;margin-right:4px"></i>Activité type</label>
            <select id="sf-at" name="at_id" class="form-control" style="font-size:12px">
              <option value="">— Toutes —</option>
              <?php foreach ($seanceFilterATs as $a): ?><option value="<?= $a['id'] ?>" data-rncp="<?= $a['rncp_title_id'] ?>" <?= $atId==$a['id']?'selected':'' ?>><?= e($a['code']) ?> — <?= e($a['title']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="min-width:190px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px"><i class="fas fa-bullseye" style="color:#ef4444;margin-right:4px"></i>Compétence</label>
            <select id="sf-comp" name="competency_id" class="form-control" style="font-size:12px">
              <option value="">— Toutes —</option>
              <?php foreach ($seanceFilterComps as $c): ?><option value="<?= $c['id'] ?>" data-at="<?= $c['activity_type_id'] ?>" <?= $compId==$c['id']?'selected':'' ?>><?= e($c['code']) ?> — <?= e($c['title']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="min-width:190px;flex:1">
            <label style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px"><i class="fas fa-list-ol" style="color:#10b981;margin-right:4px"></i>Séquence</label>
            <select id="sf-seq" name="seq_id" class="form-control" style="font-size:12px">
              <option value="">— Toutes —</option>
              <?php foreach ($seanceFilterSeqs as $sq): ?><option value="<?= $sq['id'] ?>" data-comp="<?= $sq['competency_id'] ?>" <?= $seqFilter==$sq['id']?'selected':'' ?>><?= e($sq['title']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end"><i class="fas fa-search"></i> Filtrer</button>
          <?php if ($hasFilters): ?><a href="<?= url('teacher/courses/index.php') ?>" class="btn btn-ghost btn-sm" style="align-self:flex-end"><i class="fas fa-times"></i> Réinitialiser</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Liste -->
  <?php if (empty($tree)): ?>
  <div class="empty-state">
    <div class="icon">📋</div>
    <h3><?= $hasFilters ? 'Aucune séance ne correspond aux filtres' : 'Aucune séance' ?></h3>
    <p><?= $hasFilters ? 'Essayez d\'élargir vos critères.' : 'Créez votre première séance pédagogique.' ?></p>
    <?php if ($hasFilters): ?>
    <a href="<?= url('teacher/courses/index.php') ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Réinitialiser</a>
    <?php else: ?>
    <a href="<?= url('teacher/courses/seance_create.php') ?>" class="btn btn-primary">Créer une séance</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:8px">

  <?php $atIdx = 0; foreach ($tree as $atKey => $at): $atIdx++;
    $atNodeId = 'at-'.$atIdx;
    $atCount = 0; foreach ($at['comps'] as $c) { foreach ($c['seqs'] as $sq) $atCount += count($sq['items']); }
  ?>
  <?php if ($at['code']): ?>
  <div style="border-radius:var(--radius);border:1px solid rgba(245,158,11,.2);overflow:hidden">
    <div onclick="treeToggle('<?= $atNodeId ?>')" style="padding:10px 14px;cursor:pointer;user-select:none;display:flex;align-items:center;gap:10px;background:rgba(245,158,11,.04)">
      <span style="font-size:9px;font-weight:800;padding:2px 8px;border-radius:3px;background:rgba(245,158,11,.15);color:#f59e0b;flex-shrink:0"><?= e($at['code']) ?></span>
      <div style="flex:1;min-width:0"><div style="font-weight:700;font-size:13px;color:white;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($at['title']) ?></div></div>
      <span style="font-size:11px;color:var(--text-faint)"><?= $atCount ?> séance<?= $atCount>1?'s':'' ?></span>
      <i class="fas fa-chevron-down" id="chev-<?= $atNodeId ?>" style="color:var(--text-muted);transition:.25s"></i>
    </div>
    <div id="<?= $atNodeId ?>" style="display:block;padding:5px 7px 7px">
  <?php else: ?>
  <div><div>
  <?php endif; ?>

    <?php $compIdx = 0; foreach ($at['comps'] as $ck => $comp): $compIdx++;
      $compNodeId = $atNodeId.'-c-'.$compIdx;
      $compCount = 0; foreach ($comp['seqs'] as $sq) $compCount += count($sq['items']);
    ?>
    <?php if ($comp['code']): ?>
    <div style="margin-bottom:5px;border-radius:calc(var(--radius)-1px);border:1px solid rgba(99,102,241,.15);overflow:hidden">
      <div onclick="treeToggle('<?= $compNodeId ?>')" style="padding:8px 11px;cursor:pointer;user-select:none;display:flex;align-items:center;gap:9px;background:rgba(99,102,241,.05)">
        <span style="font-size:9px;font-weight:800;padding:2px 6px;border-radius:3px;background:rgba(99,102,241,.2);color:var(--primary-light);flex-shrink:0"><?= e($comp['code']) ?></span>
        <div style="flex:1;min-width:0"><div style="font-weight:600;font-size:12px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($comp['title']) ?></div></div>
        <span style="font-size:11px;color:var(--text-faint)"><?= $compCount ?> séance<?= $compCount>1?'s':'' ?></span>
        <i class="fas fa-chevron-down" id="chev-<?= $compNodeId ?>" style="color:var(--text-muted);transition:.25s"></i>
      </div>
      <div id="<?= $compNodeId ?>" style="display:block;padding:4px 6px 6px">
    <?php else: ?>
    <div><div>
    <?php endif; ?>

      <?php
        $seqTotal   = count(array_filter($comp['seqs'], fn($s) => $s['id']));
        $seqRealIdx = 0;
        $seqIdx = 0;
        foreach ($comp['seqs'] as $sk => $seq): $seqIdx++;
        $seqNodeId = $atNodeId.'-c'.$compIdx.'-s'.$seqIdx;
        if ($seq['id']) $seqRealIdx++;
      ?>
      <?php if ($seq['id']): ?>
      <div style="margin-bottom:4px;border-radius:calc(var(--radius)-2px);border:1px solid rgba(255,255,255,.06);overflow:hidden">
        <div onclick="treeToggle('<?= $seqNodeId ?>')" style="padding:7px 10px;cursor:pointer;user-select:none;display:flex;align-items:center;gap:8px;background:var(--bg-elevated)">
          <i class="fas fa-list-ol" style="color:#10b981;font-size:11px;flex-shrink:0"></i>
          <div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($seq['title'] ?: 'Séquence') ?></div></div>
          <span style="font-size:10px;color:var(--text-faint)"><?= count($seq['items']) ?> séance<?= count($seq['items'])>1?'s':'' ?></span>
          <?php if ($seqTotal > 1): ?>
          <div onclick="event.stopPropagation()" style="display:flex;flex-direction:column;gap:1px;flex-shrink:0">
            <form method="POST" action="<?= url('teacher/courses/order.php') ?>" style="margin:0">
              <?= csrfField() ?>
              <input type="hidden" name="action"      value="move_seq_up">
              <input type="hidden" name="sequence_id" value="<?= $seq['id'] ?>">
              <input type="hidden" name="redirect_to" value="teacher/courses/index.php">
              <button type="submit" class="btn btn-ghost btn-sm" style="padding:1px 5px;height:17px;<?= $seqRealIdx===1?'opacity:.2;cursor:default':'' ?>" <?= $seqRealIdx===1?'disabled':'' ?> title="Remonter"><i class="fas fa-chevron-up" style="font-size:8px"></i></button>
            </form>
            <form method="POST" action="<?= url('teacher/courses/order.php') ?>" style="margin:0">
              <?= csrfField() ?>
              <input type="hidden" name="action"      value="move_seq_down">
              <input type="hidden" name="sequence_id" value="<?= $seq['id'] ?>">
              <input type="hidden" name="redirect_to" value="teacher/courses/index.php">
              <button type="submit" class="btn btn-ghost btn-sm" style="padding:1px 5px;height:17px;<?= $seqRealIdx===$seqTotal?'opacity:.2;cursor:default':'' ?>" <?= $seqRealIdx===$seqTotal?'disabled':'' ?> title="Descendre"><i class="fas fa-chevron-down" style="font-size:8px"></i></button>
            </form>
          </div>
          <?php endif; ?>
          <a href="<?= url('teacher/courses/seance_create.php?seq_id='.$seq['id']) ?>" class="btn btn-secondary btn-sm" style="font-size:10px;padding:2px 7px" title="Nouvelle séance" onclick="event.stopPropagation()"><i class="fas fa-plus"></i></a>
          <i class="fas fa-chevron-down" id="chev-<?= $seqNodeId ?>" style="color:var(--text-muted);transition:.25s"></i>
        </div>
        <div id="<?= $seqNodeId ?>" style="display:block">
      <?php else: ?>
      <div><div>
      <?php endif; ?>

        <?php $sIdx = 0; $sTotal = count($seq['items']); foreach ($seq['items'] as $s): $sIdx++;
          $isNew = $s['id'] === $highlight;
        ?>
        <div id="seance-<?= $s['id'] ?>" style="display:flex;align-items:center;gap:10px;padding:8px 10px 8px <?= $seq['id']?'20':'10' ?>px;border-top:1px solid var(--border-faint,rgba(255,255,255,.04));<?= $isNew?'background:rgba(16,185,129,.07)':'' ?>">
          <i class="<?= getContentTypeIcon($s['content_type']) ?>" style="font-size:15px;width:18px;color:var(--text-muted);flex-shrink:0"></i>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($s['title']) ?><?php if (!$s['is_mandatory']): ?> <span style="font-size:9px;color:var(--text-faint)">(opt.)</span><?php endif; ?></div>
            <?php if ($s['formation_title'] || $s['first_name']): ?>
            <div style="font-size:10px;color:var(--text-faint);margin-top:2px">
              <?php if ($s['formation_title']): ?><i class="fas fa-graduation-cap" style="margin-right:2px"></i><?= e(mb_substr($s['formation_title'],0,25)) ?><?= mb_strlen($s['formation_title'])>25?'…':'' ?><?php endif; ?>
              <?php if ($s['first_name']): ?><?php if ($s['formation_title']): ?> · <?php endif; ?><i class="fas <?= in_array($s['creator_role'],['admin','pedagogy'],true)?'fa-user-tie':'fa-chalkboard-teacher' ?>" style="margin-right:2px;font-size:9px"></i><?= e($s['first_name'].' '.$s['last_name']) ?><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <span class="badge badge-secondary" style="font-size:9px;flex-shrink:0"><?= e(ucfirst($s['content_type'])) ?></span>
          <span style="font-size:10px;color:var(--text-faint);flex-shrink:0;white-space:nowrap"><?= $s['duration_hours'] ? $s['duration_hours'].'h' : '—' ?></span>
          <span style="font-size:10px;color:var(--warning);font-weight:700;flex-shrink:0">+<?= $s['xp_reward'] ?></span>
          <div style="flex-shrink:0;min-width:40px;text-align:center">
            <?php if ($s['started_count'] > 0): ?>
            <div style="font-size:11px;color:#10b981;font-weight:600"><?= $s['completed_count'] ?>/<?= $s['started_count'] ?></div>
            <?php else: ?><span style="font-size:11px;color:var(--text-faint)">—</span><?php endif; ?>
          </div>
          <?php if ($seq['id']): ?>
          <div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0">
            <form method="POST" action="<?= url('teacher/courses/order.php') ?>" style="margin:0">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="move_up">
              <input type="hidden" name="module_id" value="<?= $s['id'] ?>">
              <input type="hidden" name="sequence_id" value="<?= $seq['id'] ?>">
              <input type="hidden" name="redirect_to" value="teacher/courses/index.php">
              <button type="submit" class="btn btn-ghost btn-sm" style="padding:2px 5px;height:19px;<?= $sIdx===1?'opacity:.25;cursor:default':'' ?>" <?= $sIdx===1?'disabled':'' ?> title="Monter"><i class="fas fa-chevron-up" style="font-size:9px"></i></button>
            </form>
            <form method="POST" action="<?= url('teacher/courses/order.php') ?>" style="margin:0">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="move_down">
              <input type="hidden" name="module_id" value="<?= $s['id'] ?>">
              <input type="hidden" name="sequence_id" value="<?= $seq['id'] ?>">
              <input type="hidden" name="redirect_to" value="teacher/courses/index.php">
              <button type="submit" class="btn btn-ghost btn-sm" style="padding:2px 5px;height:19px;<?= $sIdx===$sTotal?'opacity:.25;cursor:default':'' ?>" <?= $sIdx===$sTotal?'disabled':'' ?> title="Descendre"><i class="fas fa-chevron-down" style="font-size:9px"></i></button>
            </form>
          </div>
          <?php endif; ?>
          <div style="display:flex;gap:4px;flex-shrink:0">
            <a href="<?= url('teacher/courses/seance_create.php?id='.$s['id']) ?>" class="btn btn-secondary btn-sm" title="Modifier"><i class="fas fa-edit"></i></a>
            <form method="POST" action="<?= url('teacher/courses/delete.php') ?>" style="display:inline" onsubmit="return confirm('Supprimer la séance « <?= e(addslashes($s['title'])) ?> » ?\n\nCette action est irréversible.')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="module">
              <input type="hidden" name="module_id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm" title="Supprimer" style="color:#ef4444"><i class="fas fa-trash"></i></button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($seq['items'])): ?>
        <div style="padding:7px 10px;font-size:11px;color:var(--text-muted);font-style:italic">
          <i class="fas fa-info-circle" style="margin-right:4px"></i> Aucune séance dans cette séquence.
          <a href="<?= url('teacher/courses/seance_create.php?seq_id='.$seq['id']) ?>" style="margin-left:8px;color:var(--primary-light);text-decoration:none;font-weight:600"><i class="fas fa-plus" style="font-size:9px"></i> Créer</a>
        </div>
        <?php endif; ?>

      <?php if ($seq['id']): ?></div><?php endif; ?>
      </div>
      <?php endforeach; // séquences ?>

    </div></div><!-- fin comp -->
    <?php endforeach; // comps ?>

  </div></div><!-- fin AT -->
  <?php endforeach; // AT ?>

  </div>
  <?php endif; ?>

</div>

<script>
function treeToggle(id) {
  var el = document.getElementById(id);
  var ch = document.getElementById('chev-' + id);
  if (!el) return;
  var open = el.style.display !== 'none';
  el.style.display = open ? 'none' : 'block';
  if (ch) ch.style.transform = open ? 'rotate(-90deg)' : '';
}
<?php if ($highlight): ?>
  var el = document.getElementById('seance-<?= $highlight ?>');
  if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
<?php endif; ?>
</script>

<script>
// ── Filtres : cascade JS côté client RNCP → AT → Comp → Seq ─────────────────
(function() {
  var sfRncp = document.getElementById('sf-rncp');
  var sfAt   = document.getElementById('sf-at');
  var sfComp = document.getElementById('sf-comp');
  var sfSeq  = document.getElementById('sf-seq');

  var allAtOpts   = snapshot(sfAt);
  var allCompOpts = snapshot(sfComp);
  var allSeqOpts  = snapshot(sfSeq);

  function snapshot(sel) {
    return Array.from(sel.options).slice(1).map(function(o) {
      return { el: o, val: o.value, rncp: o.dataset.rncp||'', at: o.dataset.at||'', comp: o.dataset.comp||'' };
    });
  }
  function rebuild(sel, placeholder, opts) {
    var cur = sel.value;
    while (sel.options.length > 0) sel.remove(0);
    var def = document.createElement('option'); def.value=''; def.textContent=placeholder; sel.appendChild(def);
    opts.forEach(function(o){ sel.appendChild(o.el); });
    sel.value = (cur && Array.from(sel.options).some(function(o){return o.value===cur;})) ? cur : '';
  }
  function onRncp() {
    var r = sfRncp.value;
    rebuild(sfAt,   '— Toutes —',     allAtOpts.filter(function(o){return !r||o.rncp===r;}));
    onAt();
  }
  function onAt() {
    var a = sfAt.value;
    rebuild(sfComp, '— Toutes —',     allCompOpts.filter(function(o){return !a||o.at===a;}));
    onComp();
  }
  function onComp() {
    var c = sfComp.value;
    rebuild(sfSeq,  '— Toutes —',     allSeqOpts.filter(function(o){return !c||o.comp===c;}));
  }

  sfRncp.addEventListener('change', onRncp);
  sfAt.addEventListener('change', onAt);
  sfComp.addEventListener('change', onComp);

  var initRncp = sfRncp.value, initAt = sfAt.value, initComp = sfComp.value, initSeq = sfSeq.value;
  if (initRncp) { onRncp(); sfAt.value=initAt; }
  if (initAt)   { onAt();   sfComp.value=initComp; }
  if (initComp) { onComp(); sfSeq.value=initSeq; }
})();
</script>
<?php renderFooter(); ?>
