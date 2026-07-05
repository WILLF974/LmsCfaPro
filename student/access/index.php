<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();
if (!isStudent()) redirect(getDashboardUrl());

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

$grantsStmt = $pdo->prepare("SELECT * FROM access_grants WHERE user_id = ? AND revoked_at IS NULL ORDER BY granted_at DESC");
$grantsStmt->execute([$userId]);
$grants = $grantsStmt->fetchAll();

$labelDefs = [
    'rncp_title'    => ['icon'=>'fa-certificate', 'color'=>'#8b5cf6', 'label'=>'Titre RNCP',    'sql'=>"SELECT CONCAT(rncp_code,' — ',title) FROM rncp_titles WHERE id=?"],
    'activity_type' => ['icon'=>'fa-layer-group', 'color'=>'#f59e0b', 'label'=>'Activité type', 'sql'=>"SELECT CONCAT(code,' — ',title) FROM activity_types WHERE id=?"],
    'competency'    => ['icon'=>'fa-bullseye',    'color'=>'#ef4444', 'label'=>'Compétence',    'sql'=>"SELECT CONCAT(code,' — ',title) FROM competencies WHERE id=?"],
    'sequence'      => ['icon'=>'fa-list-ol',     'color'=>'#10b981', 'label'=>'Séquence',      'sql'=>"SELECT title FROM sequences WHERE id=?"],
    'module'        => ['icon'=>'fa-bookmark',    'color'=>'#0ea5e9', 'label'=>'Séance',        'sql'=>"SELECT title FROM modules WHERE id=?"],
];
$moduleWhere = [
    'module'        => 'm.id = ?',
    'sequence'      => 'm.sequence_id = ?',
    'competency'    => 'seq.competency_id = ?',
    'activity_type' => 'c.activity_type_id = ?',
    'rncp_title'    => 'at.rncp_title_id = ?',
];

$items = [];
foreach ($grants as $g) {
    $def  = $labelDefs[$g['scope_type']];
    $s    = $pdo->prepare($def['sql']); $s->execute([(int)$g['scope_id']]);
    $scopeLabel = $s->fetchColumn() ?: '(supprimé)';

    $modsStmt = $pdo->prepare("
        SELECT m.id, m.title, m.content_type, m.xp_reward, m.duration_hours,
               mp.status AS progress_status,
               seq.id AS seq_id, seq.title AS seq_title, seq.order_num AS seq_order,
               c.code AS comp_code, c.title AS comp_title,
               at.code AS at_code, at.title AS at_title
        FROM modules m
        LEFT JOIN sequences seq ON m.sequence_id = seq.id
        LEFT JOIN competencies c ON seq.competency_id = c.id
        LEFT JOIN activity_types at ON c.activity_type_id = at.id
        LEFT JOIN module_progress mp ON mp.module_id = m.id AND mp.user_id = ?
        WHERE {$moduleWhere[$g['scope_type']]}
        ORDER BY at.code, c.code, seq.order_num, m.order_num
    ");
    $modsStmt->execute([$userId, (int)$g['scope_id']]);
    $modules = $modsStmt->fetchAll();

    // Arborescence AT → Compétence → Séquence → Séances
    $tree = [];

    // Charger d'abord les séquences du périmètre (même si elles n'ont pas encore de séances)
    if (in_array($g['scope_type'], ['rncp_title', 'activity_type', 'competency', 'sequence'])) {
        $seqWhereMap = [
            'sequence'      => 'seq.id = ?',
            'competency'    => 'seq.competency_id = ?',
            'activity_type' => 'c.activity_type_id = ?',
            'rncp_title'    => 'at.rncp_title_id = ?',
        ];
        $seqsStmt = $pdo->prepare("
            SELECT seq.id AS seq_id, seq.title AS seq_title, seq.order_num AS seq_order,
                   c.code AS comp_code, c.title AS comp_title,
                   at.code AS at_code, at.title AS at_title
            FROM sequences seq
            LEFT JOIN competencies c ON seq.competency_id = c.id
            LEFT JOIN activity_types at ON c.activity_type_id = at.id
            WHERE {$seqWhereMap[$g['scope_type']]}
            ORDER BY at.code, c.code, seq.order_num
        ");
        $seqsStmt->execute([(int)$g['scope_id']]);
        foreach ($seqsStmt->fetchAll() as $seq) {
            $ak = $seq['at_code']  ?: '__none';
            $ck = $seq['comp_code'] ?: '__none';
            $sk = (string)$seq['seq_id'];
            if (!isset($tree[$ak]))                            $tree[$ak]                              = ['code'=>$seq['at_code'],'title'=>$seq['at_title'],'comps'=>[]];
            if (!isset($tree[$ak]['comps'][$ck]))              $tree[$ak]['comps'][$ck]               = ['code'=>$seq['comp_code'],'title'=>$seq['comp_title'],'seqs'=>[]];
            if (!isset($tree[$ak]['comps'][$ck]['seqs'][$sk])) $tree[$ak]['comps'][$ck]['seqs'][$sk] = ['id'=>$seq['seq_id'],'title'=>$seq['seq_title'],'modules'=>[]];
        }
    }

    // Ajouter les séances dans l'arborescence
    foreach ($modules as $m) {
        $ak = $m['at_code']  ?: '__none';
        $ck = $m['comp_code'] ?: '__none';
        $sk = $m['seq_id'] !== null ? (string)$m['seq_id'] : '__none';
        if (!isset($tree[$ak]))                            $tree[$ak]                              = ['code'=>$m['at_code'],'title'=>$m['at_title'],'comps'=>[]];
        if (!isset($tree[$ak]['comps'][$ck]))              $tree[$ak]['comps'][$ck]               = ['code'=>$m['comp_code'],'title'=>$m['comp_title'],'seqs'=>[]];
        if (!isset($tree[$ak]['comps'][$ck]['seqs'][$sk])) $tree[$ak]['comps'][$ck]['seqs'][$sk] = ['id'=>$m['seq_id'],'title'=>$m['seq_title'],'modules'=>[]];
        $tree[$ak]['comps'][$ck]['seqs'][$sk]['modules'][] = $m;
    }

    $items[] = [
        'scopeType'  => $g['scope_type'],
        'scopeLabel' => $scopeLabel,
        'icon'       => $def['icon'],
        'color'      => $def['color'],
        'levelLabel' => $def['label'],
        'tree'       => $tree,
        'total'      => count($modules),
        'done'       => count(array_filter($modules, fn($m) => $m['progress_status'] === 'completed')),
    ];
}

renderHead('Accès complémentaires');
renderSidebar('student');
renderTopbar('Accès complémentaires', [['Mon espace', url('student/index.php')], ['Accès complémentaires', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div style="background:rgba(139,92,246,.07);border:1px solid rgba(139,92,246,.25);border-radius:var(--radius-lg);padding:14px 18px;margin-bottom:20px;font-size:13px;color:var(--text-secondary)">
    <i class="fas fa-info-circle" style="color:#8b5cf6;margin-right:6px"></i>
    Ressources auxquelles vous avez accès en dehors de vos formations inscrites.
  </div>

  <?php if (empty($items)): ?>
  <div class="empty-state">
    <div class="icon">🔓</div>
    <h3>Aucun accès complémentaire</h3>
    <p>Vous n'avez pas d'accès indépendant attribué pour le moment.</p>
  </div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:16px">

  <?php foreach ($items as $gIdx => $item):
    $gId       = 'g'.$gIdx;
    $scopeType = $item['scopeType'];
    // Niveaux à afficher selon la profondeur du périmètre d'accès
    $showAt   = $scopeType === 'rncp_title';
    $showComp = in_array($scopeType, ['rncp_title','activity_type']);
    $showSeq  = in_array($scopeType, ['rncp_title','activity_type','competency']);
  ?>
  <div class="card" style="border:1px solid <?= $item['color'] ?>33">

    <!-- En-tête du périmètre d'accès -->
    <div style="padding:13px 18px;background:<?= $item['color'] ?>0f;border-bottom:1px solid <?= $item['color'] ?>22;display:flex;align-items:center;gap:12px">
      <i class="fas <?= $item['icon'] ?>" style="color:<?= $item['color'] ?>;font-size:17px;flex-shrink:0"></i>
      <div style="flex:1;min-width:0">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?= $item['color'] ?>;margin-bottom:2px"><?= $item['levelLabel'] ?></div>
        <div style="font-size:14px;font-weight:700;color:white;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($item['scopeLabel']) ?></div>
      </div>
      <?php if ($scopeType !== 'module' && $item['total'] > 0): ?>
      <div style="font-size:12px;font-weight:700;color:<?= $item['done']===$item['total'] ? 'var(--success)' : 'var(--text-muted)' ?>;white-space:nowrap"><?= $item['done'] ?>/<?= $item['total'] ?> validées</div>
      <?php endif; ?>
    </div>

    <?php if ($scopeType === 'module'): ?>
    <!-- Séance unique : affichage direct (pas d'arborescence) -->
    <?php
      $m = null;
      foreach ($item['tree'] as $at) { foreach ($at['comps'] as $comp) { foreach ($comp['seqs'] as $seq) { foreach ($seq['modules'] as $_m) { $m = $_m; break 4; } } } }
      if ($m):
        $isDone = $m['progress_status']==='completed'; $isStarted = $m['progress_status']==='in_progress';
    ?>
    <div style="padding:14px 18px;display:flex;align-items:center;gap:12px">
      <div style="width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;<?= $isDone ? 'background:rgba(16,185,129,.2);color:var(--success)' : ($isStarted ? 'background:rgba(99,102,241,.2);color:var(--primary-light)' : 'background:var(--bg-elevated);color:var(--text-muted)') ?>">
        <?php if ($isDone): ?><i class="fas fa-check"></i><?php elseif ($isStarted): ?><i class="fas fa-play"></i><?php else: ?><i class="<?= getContentTypeIcon($m['content_type']) ?>"></i><?php endif; ?>
      </div>
      <div style="flex:1">
        <div style="font-size:13px;font-weight:600"><?= e($m['title']) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
          <i class="<?= getContentTypeIcon($m['content_type']) ?>"></i> <?= ucfirst($m['content_type']) ?>
          <?php if ($m['xp_reward']): ?>&nbsp;<span style="color:var(--warning)"><i class="fas fa-bolt"></i> +<?= $m['xp_reward'] ?> XP</span><?php endif; ?>
        </div>
      </div>
      <a href="<?= url('student/course/seance.php?id='.$m['id']) ?>" class="btn <?= $isDone ? 'btn-ghost' : 'btn-primary' ?> btn-sm">
        <i class="fas fa-<?= $isDone ? 'eye' : 'play' ?>"></i> <?= $isDone ? 'Revoir' : ($isStarted ? 'Continuer' : 'Ouvrir') ?>
      </a>
    </div>
    <?php endif; ?>

    <?php elseif (empty($item['tree'])): ?>
    <div style="padding:14px 16px;font-size:12px;color:var(--text-muted);font-style:italic">
      <i class="fas fa-info-circle" style="margin-right:5px"></i> Aucune séquence ni séance disponible dans ce périmètre pour le moment.
    </div>
    <?php else: ?>
    <!-- Arborescence AT → Compétence → Séquence → Séances -->
    <div style="padding:8px 10px 10px">

    <?php $atIdx = 0; $seqNum = 0; foreach ($item['tree'] as $atKey => $at): $atIdx++;
      $atNodeId = $gId.'-at-'.$atIdx;
      $atTotal = $atDone = 0;
      foreach ($at['comps'] as $comp) { foreach ($comp['seqs'] as $seq) { foreach ($seq['modules'] as $m) { $atTotal++; if ($m['progress_status']==='completed') $atDone++; }}}
      $atPct = $atTotal > 0 ? round($atDone/$atTotal*100) : 0;
      $atComplete = $atTotal > 0 && $atPct === 100;
    ?>

    <?php if ($showAt && $at['code']): ?>
    <div style="margin-bottom:8px;border-radius:var(--radius);border:1px solid <?= $atComplete ? 'rgba(16,185,129,.2)' : 'rgba(245,158,11,.18)' ?>;overflow:hidden">
      <div onclick="treeToggle('<?= $atNodeId ?>')" style="padding:10px 13px;cursor:pointer;user-select:none;display:flex;align-items:center;gap:10px;background:<?= $atComplete ? 'rgba(16,185,129,.05)' : 'rgba(245,158,11,.04)' ?>">
        <span style="font-size:9px;font-weight:800;padding:2px 7px;border-radius:3px;background:rgba(245,158,11,.15);color:#f59e0b;flex-shrink:0"><?= e($at['code']) ?></span>
        <div style="flex:1;min-width:0"><div style="font-weight:700;font-size:13px;color:white;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($at['title'] ?: 'Activités non classées') ?></div></div>
        <div style="font-size:11px;color:<?= $atComplete ? 'var(--success)' : '#f59e0b' ?>"><?= $atPct ?>%</div>
        <i class="fas fa-chevron-down" id="chev-<?= $atNodeId ?>" style="color:var(--text-muted);transition:.25s"></i>
      </div>
      <div id="<?= $atNodeId ?>" style="display:block;padding:5px 7px 7px">
    <?php else: ?>
    <div><div>
    <?php endif; ?>

      <?php $compIdx = 0; foreach ($at['comps'] as $compKey => $comp): $compIdx++;
        $compNodeId = $gId.'-at-'.$atIdx.'-c-'.$compIdx;
        $compTotal = $compDone = 0;
        foreach ($comp['seqs'] as $seq) { foreach ($seq['modules'] as $m) { $compTotal++; if ($m['progress_status']==='completed') $compDone++; }}
        $compPct = $compTotal > 0 ? round($compDone/$compTotal*100) : 0;
        $compComplete = $compTotal > 0 && $compPct === 100;
      ?>

      <?php if ($showComp && $comp['code']): ?>
      <div style="margin-bottom:6px;border-radius:calc(var(--radius)-1px);border:1px solid <?= $compComplete ? 'rgba(16,185,129,.2)' : 'rgba(99,102,241,.18)' ?>;overflow:hidden">
        <div onclick="treeToggle('<?= $compNodeId ?>')" style="padding:8px 11px;cursor:pointer;user-select:none;display:flex;align-items:center;gap:9px;background:<?= $compComplete ? 'rgba(16,185,129,.05)' : 'rgba(99,102,241,.06)' ?>">
          <span style="font-size:9px;font-weight:800;padding:2px 6px;border-radius:3px;background:rgba(99,102,241,.2);color:var(--primary-light);flex-shrink:0"><?= e($comp['code']) ?></span>
          <div style="flex:1;min-width:0"><div style="font-weight:600;font-size:12px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($comp['title'] ?: 'Compétence') ?></div></div>
          <div style="font-size:11px;color:<?= $compComplete ? 'var(--success)' : 'var(--primary-light)' ?>"><?= $compPct ?>% <span style="color:var(--text-faint)">(<?= $compDone ?>/<?= $compTotal ?>)</span></div>
          <i class="fas fa-chevron-down" id="chev-<?= $compNodeId ?>" style="color:var(--text-muted);transition:.25s"></i>
        </div>
        <div id="<?= $compNodeId ?>" style="display:block;padding:4px 6px 6px">
      <?php else: ?>
      <div><div>
      <?php endif; ?>

        <?php foreach ($comp['seqs'] as $sk => $seq): $seqNum++;
          $seqNodeId  = $gId.'-s-'.$seqNum;
          $seqTotal   = count($seq['modules']);
          $seqDone    = count(array_filter($seq['modules'], fn($m) => $m['progress_status']==='completed'));
          $seqComplete = $seqTotal > 0 && $seqDone === $seqTotal;
        ?>
        <div style="margin-bottom:4px;border-radius:calc(var(--radius)-2px);border:1px solid <?= $seqComplete ? 'rgba(16,185,129,.12)' : 'rgba(255,255,255,.05)' ?>;overflow:hidden">
          <?php if ($showSeq && $seq['id']): ?>
          <div onclick="treeToggle('<?= $seqNodeId ?>')" style="padding:7px 10px;cursor:pointer;user-select:none;display:flex;align-items:center;gap:8px;background:<?= $seqComplete ? 'rgba(16,185,129,.04)' : 'var(--bg-elevated)' ?>">
            <div style="width:20px;height:20px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:white;background:<?= $seqComplete ? 'var(--success)' : 'rgba(99,102,241,.4)' ?>"><?= $seqComplete ? '<i class="fas fa-check" style="font-size:8px"></i>' : $seqNum ?></div>
            <div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($seq['title'] ?: 'Séquence') ?></div></div>
            <div style="font-size:10px;color:<?= $seqComplete ? 'var(--success)' : 'var(--text-muted)' ?>"><?= $seqDone ?>/<?= $seqTotal ?></div>
            <i class="fas fa-chevron-down" id="chev-<?= $seqNodeId ?>" style="color:var(--text-muted);transition:.25s;transform:rotate(-90deg)"></i>
          </div>
          <div id="<?= $seqNodeId ?>" style="display:none">
          <?php endif; ?>

          <?php foreach ($seq['modules'] as $m):
            $isDone = $m['progress_status']==='completed'; $isStarted = $m['progress_status']==='in_progress';
          ?>
          <div style="display:flex;align-items:center;gap:10px;padding:8px 10px 8px 20px;border-top:1px solid var(--border-faint,rgba(255,255,255,.04));<?= $isDone ? 'background:rgba(16,185,129,.03)' : ($isStarted ? 'background:rgba(99,102,241,.03)' : '') ?>">
            <div style="width:20px;height:20px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:9px;<?= $isDone ? 'background:rgba(16,185,129,.2);color:var(--success)' : ($isStarted ? 'background:rgba(99,102,241,.2);color:var(--primary-light)' : 'background:var(--bg-elevated);color:var(--text-muted)') ?>">
              <?php if ($isDone): ?><i class="fas fa-check"></i><?php elseif ($isStarted): ?><i class="fas fa-play" style="font-size:8px;margin-left:1px"></i><?php else: ?><i class="<?= getContentTypeIcon($m['content_type']) ?>" style="font-size:8px"></i><?php endif; ?>
            </div>
            <div style="flex:1;min-width:0">
              <a href="<?= url('student/course/seance.php?id='.$m['id']) ?>" style="display:block;font-size:12px;font-weight:<?= $isDone ? '400' : '600' ?>;color:<?= $isDone ? 'var(--text-muted)' : 'var(--text)' ?>;text-decoration:none;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($m['title']) ?></a>
              <div style="display:flex;gap:8px;font-size:10px;color:var(--text-faint)">
                <span><i class="<?= getContentTypeIcon($m['content_type']) ?>"></i> <?= ucfirst($m['content_type']) ?></span>
                <?php if ($m['duration_hours']): ?><span><i class="fas fa-clock"></i> <?= $m['duration_hours'] ?>h</span><?php endif; ?>
                <?php if ($m['xp_reward']): ?><span style="color:var(--warning)"><i class="fas fa-bolt"></i> +<?= $m['xp_reward'] ?> XP</span><?php endif; ?>
              </div>
            </div>
            <a href="<?= url('student/course/seance.php?id='.$m['id']) ?>" class="btn <?= $isDone ? 'btn-ghost' : ($isStarted ? 'btn-primary' : 'btn-ghost') ?> btn-sm" style="padding:3px 7px;flex-shrink:0">
              <i class="fas fa-<?= $isDone ? 'eye' : ($isStarted ? 'play' : 'arrow-right') ?>" style="font-size:10px"></i>
            </a>
          </div>
          <?php endforeach; // modules ?>

          <?php if (empty($seq['modules'])): ?>
          <div style="padding:8px 12px;font-size:11px;color:var(--text-muted);font-style:italic">
            <i class="fas fa-info-circle" style="margin-right:4px"></i> Aucune séance dans cette séquence.
          </div>
          <?php endif; ?>

          <?php if ($showSeq && $seq['id']): ?></div><?php endif; ?>
        </div>
        <?php endforeach; // séquences ?>

      </div></div><!-- fin compétence -->
      <?php endforeach; // compétences ?>

    </div></div><!-- fin AT -->
    <?php endforeach; // AT ?>

    </div><!-- fin arborescence -->
    <?php endif; ?>

  </div>
  <?php endforeach; // items ?>

  </div>
  <?php endif; ?>
</div>

<script>
function treeToggle(id) {
  const el   = document.getElementById(id);
  const chev = document.getElementById('chev-' + id);
  if (!el) return;
  const open = el.style.display !== 'none';
  el.style.display = open ? 'none' : 'block';
  if (chev) chev.style.transform = open ? 'rotate(-90deg)' : '';
}
</script>
<?php renderFooter(); ?>
