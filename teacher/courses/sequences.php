<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Actions POST : monter / descendre ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action   = $_POST['action']    ?? '';
    $seqId    = (int)($_POST['seq_id']    ?? 0);
    $moduleId = (int)($_POST['module_id'] ?? 0);

    if (in_array($action, ['seq_up', 'seq_down']) && $seqId) {
        $row = $pdo->prepare("SELECT id, competency_id, order_num FROM sequences WHERE id = ?");
        $row->execute([$seqId]);
        $current = $row->fetch();
        if ($current) {
            $siblings = $pdo->prepare("SELECT id, order_num FROM sequences WHERE competency_id = ? ORDER BY order_num, id");
            $siblings->execute([$current['competency_id']]);
            $list = $siblings->fetchAll();
            $pos  = null;
            foreach ($list as $i => $r) { if ($r['id'] == $seqId) { $pos = $i; break; } }
            $swapPos = $action === 'seq_up' ? $pos - 1 : $pos + 1;
            if ($pos !== null && isset($list[$swapPos])) {
                $a = $list[$pos]['order_num']  ?: $pos + 1;
                $b = $list[$swapPos]['order_num'] ?: $swapPos + 1;
                if ($a === $b) { $a = $pos + 1; $b = $swapPos + 1; }
                $pdo->prepare("UPDATE sequences SET order_num=? WHERE id=?")->execute([$b, $seqId]);
                $pdo->prepare("UPDATE sequences SET order_num=? WHERE id=?")->execute([$a, $list[$swapPos]['id']]);
                $siblings2 = $pdo->prepare("SELECT id FROM sequences WHERE competency_id = ? ORDER BY order_num, id");
                $siblings2->execute([$current['competency_id']]);
                foreach ($siblings2->fetchAll() as $k => $r) {
                    $pdo->prepare("UPDATE sequences SET order_num=? WHERE id=?")->execute([$k + 1, $r['id']]);
                }
            }
        }
    }

    if (in_array($action, ['mod_up', 'mod_down']) && $moduleId && $seqId) {
        $row = $pdo->prepare("SELECT id, sequence_id, order_num FROM modules WHERE id = ? AND sequence_id = ?");
        $row->execute([$moduleId, $seqId]);
        $current = $row->fetch();
        if ($current) {
            $siblings = $pdo->prepare("SELECT id, order_num FROM modules WHERE sequence_id = ? ORDER BY order_num, id");
            $siblings->execute([$seqId]);
            $list = $siblings->fetchAll();
            $pos  = null;
            foreach ($list as $i => $r) { if ($r['id'] == $moduleId) { $pos = $i; break; } }
            $swapPos = $action === 'mod_up' ? $pos - 1 : $pos + 1;
            if ($pos !== null && isset($list[$swapPos])) {
                $a = $list[$pos]['order_num']    ?: $pos + 1;
                $b = $list[$swapPos]['order_num'] ?: $swapPos + 1;
                if ($a === $b) { $a = $pos + 1; $b = $swapPos + 1; }
                $pdo->prepare("UPDATE modules SET order_num=? WHERE id=?")->execute([$b, $moduleId]);
                $pdo->prepare("UPDATE modules SET order_num=? WHERE id=?")->execute([$a, $list[$swapPos]['id']]);
                $siblings2 = $pdo->prepare("SELECT id FROM modules WHERE sequence_id = ? ORDER BY order_num, id");
                $siblings2->execute([$seqId]);
                foreach ($siblings2->fetchAll() as $k => $r) {
                    $pdo->prepare("UPDATE modules SET order_num=? WHERE id=?")->execute([$k + 1, $r['id']]);
                }
            }
        }
    }

    $anchor = $seqId ? "#seq-$seqId" : '';
    redirect(url('teacher/courses/sequences.php' . $anchor));
}

// ── Charger la hiérarchie ─────────────────────────────────────────────────────
// Toutes les séquences où l'enseignant a créé au moins une séance
$seqIds = $pdo->prepare("
    SELECT DISTINCT sequence_id FROM modules
    WHERE sequence_id IS NOT NULL AND created_by = ?
");
$seqIds->execute([$userId]);
$mySeqIds = array_column($seqIds->fetchAll(), 'sequence_id');

// + les séquences créées par l'enseignant lui-même
$mySeqsDirect = $pdo->prepare("SELECT id FROM sequences WHERE created_by = ?");
$mySeqsDirect->execute([$userId]);
foreach ($mySeqsDirect->fetchAll() as $r) {
    if (!in_array($r['id'], $mySeqIds)) $mySeqIds[] = (int)$r['id'];
}

if (empty($mySeqIds)) {
    renderHead('Séquences & Séances');
    renderSidebar('teacher');
    renderTopbar('Séquences & Séances', [['Enseignant', url('teacher/index.php')], ['Séquences & Séances', '']]);
    echo '<div class="page-content fade-in"><div class="empty-state"><div class="icon"><i class="fas fa-list-ol"></i></div>';
    echo '<h3>Aucune séquence</h3><p>Créez votre première séance pour démarrer.</p>';
    echo '<a href="' . url('teacher/courses/seance_create.php') . '" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvelle séance</a></div></div>';
    renderFooter();
    exit;
}

$inList = implode(',', $mySeqIds);

$seqs = $pdo->query("
    SELECT s.id, s.title, s.order_num, s.competency_id, s.created_by,
           c.code AS comp_code, c.title AS comp_title,
           at.id AS at_id, at.code AS at_code, at.title AS at_title,
           rt.id AS rncp_id, rt.rncp_code, rt.title AS rncp_title
    FROM sequences s
    JOIN competencies c  ON s.competency_id = c.id
    JOIN activity_types at ON c.activity_type_id = at.id
    JOIN rncp_titles rt  ON at.rncp_title_id = rt.id
    WHERE s.id IN ($inList)
    ORDER BY rt.rncp_code, at.order_num, c.order_num, s.order_num, s.id
")->fetchAll();

// Modules (séances) par séquence
$mods = $pdo->query("
    SELECT id, sequence_id, title, content_type, duration_hours, order_num, created_by
    FROM modules
    WHERE sequence_id IN ($inList)
    ORDER BY sequence_id, order_num, id
")->fetchAll();
$modsBySeq = [];
foreach ($mods as $m) { $modsBySeq[(int)$m['sequence_id']][] = $m; }

// Regrouper séquences par compétence, AT, RNCP
$tree = [];
foreach ($seqs as $s) {
    $rk = $s['rncp_id'];
    $ak = $s['at_id'];
    $ck = $s['competency_id'];
    if (!isset($tree[$rk])) $tree[$rk] = ['rncp_code' => $s['rncp_code'], 'rncp_title' => $s['rncp_title'], 'ats' => []];
    if (!isset($tree[$rk]['ats'][$ak])) $tree[$rk]['ats'][$ak] = ['at_code' => $s['at_code'], 'at_title' => $s['at_title'], 'comps' => []];
    if (!isset($tree[$rk]['ats'][$ak]['comps'][$ck])) $tree[$rk]['ats'][$ak]['comps'][$ck] = ['comp_code' => $s['comp_code'], 'comp_title' => $s['comp_title'], 'seqs' => []];
    $tree[$rk]['ats'][$ak]['comps'][$ck]['seqs'][] = $s;
}

// Types de contenu
$typeIcons = [
    'video' => ['fa-play-circle','#ef4444'], 'pdf' => ['fa-file-pdf','#ef4444'],
    'document' => ['fa-file-word','#3b82f6'], 'presentation' => ['fa-file-powerpoint','#f97316'],
    'quiz' => ['fa-question-circle','#8b5cf6'], 'exercise' => ['fa-pencil-alt','#10b981'],
    'text' => ['fa-align-left','#6b7280'], 'link' => ['fa-link','#0ea5e9'],
];
$typeLabels = ['video'=>'Vidéo','pdf'=>'PDF','document'=>'Document','presentation'=>'Présentation','quiz'=>'Quiz','exercise'=>'Exercice','text'=>'Texte','link'=>'Lien'];

renderHead('Séquences & Séances');
renderSidebar('teacher');
renderTopbar('Séquences & Séances', [
    ['Enseignant', url('teacher/index.php')],
    ['Séquences & Séances', ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header" style="margin-bottom:20px">
    <div>
      <h1><i class="fas fa-list-ol" style="color:var(--primary-light);margin-right:10px"></i>Séquences & Séances</h1>
      <p>Utilisez les flèches pour réorganiser l'ordre des séquences et des séances.</p>
    </div>
    <a href="<?= url('teacher/courses/seance_create.php') ?>" class="btn btn-primary">
      <i class="fas fa-plus"></i> Nouvelle séance
    </a>
  </div>

  <?php foreach ($tree as $rncpId => $rncpNode): ?>
  <div style="margin-bottom:28px">

    <!-- Titre RNCP -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      <div style="padding:3px 10px;background:rgba(139,92,246,.15);border:1px solid rgba(139,92,246,.3);border-radius:20px;font-size:11px;font-weight:800;color:#a78bfa;letter-spacing:.05em">
        <?= e($rncpNode['rncp_code']) ?>
      </div>
      <span style="font-size:13px;color:var(--text-muted)"><?= e(mb_substr($rncpNode['rncp_title'], 0, 80)) ?></span>
    </div>

    <?php foreach ($rncpNode['ats'] as $atId => $atNode): ?>
    <div style="margin-bottom:16px;padding-left:12px;border-left:2px solid rgba(245,158,11,.3)">

      <!-- Activité type -->
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:10px">
        <span style="font-size:11px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.06em">
          <i class="fas fa-layer-group" style="margin-right:4px"></i><?= e($atNode['at_code']) ?>
        </span>
        <span style="font-size:12px;color:var(--text-muted)"><?= e(mb_substr($atNode['at_title'], 0, 60)) ?></span>
      </div>

      <?php foreach ($atNode['comps'] as $compId => $compNode):
        $compSeqs = $compNode['seqs'];
        $seqCount = count($compSeqs);
      ?>
      <div style="margin-bottom:12px;padding-left:12px;border-left:2px solid rgba(239,68,68,.25)">

        <!-- Compétence -->
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px">
          <span style="font-size:11px;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:.06em">
            <i class="fas fa-bullseye" style="margin-right:4px"></i><?= e($compNode['comp_code']) ?>
          </span>
          <span style="font-size:12px;color:var(--text-muted)"><?= e(mb_substr($compNode['comp_title'], 0, 70)) ?></span>
        </div>

        <!-- Séquences -->
        <div style="display:flex;flex-direction:column;gap:10px">
          <?php foreach ($compSeqs as $si => $seq):
            $seqMods  = $modsBySeq[$seq['id']] ?? [];
            $modCount = count($seqMods);
            $isFirst  = $si === 0;
            $isLast   = $si === $seqCount - 1;
          ?>
          <div class="card" id="seq-<?= $seq['id'] ?>" style="overflow:visible">
            <div class="card-header" style="padding:12px 16px">

              <!-- Flèches séquence -->
              <div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0;margin-right:6px">
                <?php if (!$isFirst): ?>
                <form method="POST" style="margin:0">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"  value="seq_up">
                  <input type="hidden" name="seq_id"  value="<?= $seq['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm ord-btn" title="Remonter la séquence">
                    <i class="fas fa-chevron-up" style="font-size:10px"></i>
                  </button>
                </form>
                <?php else: ?><div class="ord-placeholder"></div><?php endif; ?>

                <?php if (!$isLast): ?>
                <form method="POST" style="margin:0">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"  value="seq_down">
                  <input type="hidden" name="seq_id"  value="<?= $seq['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm ord-btn" title="Descendre la séquence">
                    <i class="fas fa-chevron-down" style="font-size:10px"></i>
                  </button>
                </form>
                <?php else: ?><div class="ord-placeholder"></div><?php endif; ?>
              </div>

              <!-- Numéro + titre -->
              <div style="width:26px;height:26px;border-radius:50%;background:rgba(99,102,241,.15);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:var(--primary-light);flex-shrink:0">
                <?= $si + 1 ?>
              </div>
              <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($seq['title']) ?></div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:1px">
                  <?= $modCount ?> séance<?= $modCount !== 1 ? 's' : '' ?>
                </div>
              </div>

              <!-- Bouton ajouter séance -->
              <a href="<?= url('teacher/courses/seance_create.php?seq_id=' . $seq['id']) ?>"
                 class="btn btn-ghost btn-sm" style="flex-shrink:0;font-size:11px;color:var(--primary-light)" title="Ajouter une séance dans cette séquence">
                <i class="fas fa-plus"></i>
              </a>
            </div>

            <?php if (!empty($seqMods)): ?>
            <div style="display:flex;flex-direction:column">
              <?php foreach ($seqMods as $mi => $mod):
                $isModFirst = $mi === 0;
                $isModLast  = $mi === $modCount - 1;
                [$icon, $color] = $typeIcons[$mod['content_type']] ?? ['fa-file','#94a3b8'];
                $label = $typeLabels[$mod['content_type']] ?? $mod['content_type'];
              ?>
              <div class="seance-row" style="display:flex;align-items:center;gap:10px;padding:9px 16px;border-top:1px solid var(--border-faint,rgba(255,255,255,.04))">

                <!-- Flèches séance -->
                <div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0">
                  <?php if (!$isModFirst): ?>
                  <form method="POST" style="margin:0">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"    value="mod_up">
                    <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
                    <input type="hidden" name="seq_id"    value="<?= $seq['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm ord-btn" title="Remonter">
                      <i class="fas fa-chevron-up" style="font-size:10px"></i>
                    </button>
                  </form>
                  <?php else: ?><div class="ord-placeholder"></div><?php endif; ?>

                  <?php if (!$isModLast): ?>
                  <form method="POST" style="margin:0">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"    value="mod_down">
                    <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
                    <input type="hidden" name="seq_id"    value="<?= $seq['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm ord-btn" title="Descendre">
                      <i class="fas fa-chevron-down" style="font-size:10px"></i>
                    </button>
                  </form>
                  <?php else: ?><div class="ord-placeholder"></div><?php endif; ?>
                </div>

                <!-- Numéro séance -->
                <div style="width:22px;height:22px;border-radius:4px;background:var(--bg-elevated);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--text-muted);flex-shrink:0">
                  <?= $mi + 1 ?>
                </div>

                <!-- Icône type -->
                <i class="fas <?= $icon ?>" style="color:<?= $color ?>;width:14px;flex-shrink:0;font-size:13px"></i>

                <!-- Titre -->
                <div style="flex:1;min-width:0">
                  <div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($mod['title']) ?></div>
                  <div style="display:flex;gap:10px;margin-top:2px">
                    <span style="font-size:11px;color:var(--text-faint)"><?= e($label) ?></span>
                    <?php if ($mod['duration_hours']): ?>
                    <span style="font-size:11px;color:var(--text-faint)"><i class="fas fa-clock"></i> <?= $mod['duration_hours'] ?>h</span>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Éditer -->
                <a href="<?= url('teacher/courses/seance_create.php?id=' . $mod['id']) ?>"
                   class="btn btn-ghost btn-sm" style="flex-shrink:0;padding:4px 8px" title="Modifier">
                  <i class="fas fa-edit" style="font-size:11px"></i>
                </a>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>

<style>
.ord-btn  { padding:3px 7px; width:28px; height:24px; display:flex; align-items:center; justify-content:center; }
.ord-placeholder { width:28px; height:24px; }
.seance-row:hover { background:var(--bg-elevated); }
</style>
<?php renderFooter(); ?>
