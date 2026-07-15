<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Actions POST : monter / descendre ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $seqId  = (int)($_POST['seq_id'] ?? 0);

    if ($action === 'seq_toggle_publish' && $seqId) {
        $pdo->prepare("UPDATE sequences SET is_published = 1 - is_published WHERE id = ?")->execute([$seqId]);
    }

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
                $a = $list[$pos]['order_num']    ?: $pos + 1;
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
    redirect(url('teacher/courses/sequences.php#seq-' . $seqId));
}

// ── Filtres ───────────────────────────────────────────────────────────────────
$rncpId = (int)($_GET['rncp_id']      ?? 0);
$atId   = (int)($_GET['at_id']        ?? 0);
$compId = (int)($_GET['competency_id'] ?? 0);

// ── Données filtres (dropdowns) ───────────────────────────────────────────────
$allRncps = $pdo->query("
    SELECT DISTINCT rt.id, rt.rncp_code, rt.title
    FROM rncp_titles rt
    JOIN activity_types at ON at.rncp_title_id = rt.id
    JOIN competencies c ON c.activity_type_id = at.id
    JOIN sequences s ON s.competency_id = c.id
    ORDER BY rt.rncp_code
")->fetchAll();

$allATs = [];
if ($rncpId) {
    $s = $pdo->prepare("
        SELECT DISTINCT at.id, at.code, at.title FROM activity_types at
        JOIN competencies c ON c.activity_type_id = at.id
        JOIN sequences seq ON seq.competency_id = c.id
        WHERE at.rncp_title_id = ?
        ORDER BY at.order_num, at.code
    ");
    $s->execute([$rncpId]);
    $allATs = $s->fetchAll();
}

$allComps = [];
if ($atId) {
    $s = $pdo->prepare("
        SELECT DISTINCT c.id, c.code, c.title FROM competencies c
        JOIN sequences seq ON seq.competency_id = c.id
        WHERE c.activity_type_id = ?
        ORDER BY c.order_num, c.code
    ");
    $s->execute([$atId]);
    $allComps = $s->fetchAll();
}

// ── Données séquences ─────────────────────────────────────────────────────────
$sqlWhere = '';
$sqlParams = [];
if ($compId)  { $sqlWhere .= ' AND s.competency_id = ?';   $sqlParams[] = $compId; }
elseif ($atId){ $sqlWhere .= ' AND at.id = ?';              $sqlParams[] = $atId; }
elseif ($rncpId){ $sqlWhere .= ' AND rt.id = ?';            $sqlParams[] = $rncpId; }

$seqStmt = $pdo->prepare("
    SELECT s.id, s.title, s.order_num, s.competency_id, s.is_published, s.scenario_path,
           c.code AS comp_code, c.title AS comp_title,
           at.id AS at_id, at.code AS at_code, at.title AS at_title,
           rt.id AS rncp_id, rt.rncp_code, rt.title AS rncp_title,
           (SELECT COUNT(*) FROM modules m WHERE m.sequence_id = s.id) AS seance_count
    FROM sequences s
    JOIN competencies c  ON s.competency_id = c.id
    JOIN activity_types at ON c.activity_type_id = at.id
    JOIN rncp_titles rt  ON at.rncp_title_id = rt.id
    WHERE 1=1 $sqlWhere
    ORDER BY rt.rncp_code, at.order_num, c.order_num, s.order_num, s.id
");
$seqStmt->execute($sqlParams);
$seqs = $seqStmt->fetchAll();

// Regrouper par compétence, AT, RNCP
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

$highlight = (int)($_GET['highlight'] ?? 0);

renderHead('Séquences');
renderSidebar('teacher');
renderTopbar('Séquences', [['Enseignant', url('teacher/index.php')], ['Séquences', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header" style="margin-bottom:20px">
    <div>
      <h1><i class="fas fa-list-ol" style="color:var(--primary-light);margin-right:10px"></i>Séquences</h1>
      <p>Organisez et réordonnez les séquences pédagogiques par compétence.</p>
    </div>
    <a href="<?= url('teacher/courses/sequence_create.php') ?>" class="btn btn-primary">
      <i class="fas fa-plus"></i> Nouvelle séquence
    </a>
  </div>

  <!-- Filtres cascade RNCP → AT → Compétence -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 18px">
      <form id="filter-form" method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">

        <!-- RNCP -->
        <select name="rncp_id" class="form-control" style="width:220px" onchange="onRncpChange(this.value)">
          <option value="">Tous les RNCP</option>
          <?php foreach ($allRncps as $r): ?>
          <option value="<?= $r['id'] ?>" <?= $rncpId == $r['id'] ? 'selected' : '' ?>>
            <?= e($r['rncp_code']) ?> — <?= e($r['title']) ?>
          </option>
          <?php endforeach; ?>
        </select>

        <!-- AT (visible si RNCP sélectionné) -->
        <select name="at_id" id="sel-at" class="form-control" style="width:220px<?= !$rncpId ? ';display:none' : '' ?>" onchange="onAtChange(this.value)">
          <option value="">Toutes les AT</option>
          <?php foreach ($allATs as $a): ?>
          <option value="<?= $a['id'] ?>" <?= $atId == $a['id'] ? 'selected' : '' ?>>
            <?= e($a['code']) ?> — <?= e($a['title']) ?>
          </option>
          <?php endforeach; ?>
        </select>

        <!-- Compétence (visible si AT sélectionnée) -->
        <select name="competency_id" id="sel-comp" class="form-control" style="width:220px<?= !$atId ? ';display:none' : '' ?>" onchange="document.getElementById('filter-form').submit()">
          <option value="">Toutes les compétences</option>
          <?php foreach ($allComps as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $compId == $c['id'] ? 'selected' : '' ?>>
            <?= e($c['code']) ?> — <?= e($c['title']) ?>
          </option>
          <?php endforeach; ?>
        </select>

        <?php if ($rncpId || $atId || $compId): ?>
        <a href="<?= url('teacher/courses/sequences.php') ?>" class="btn btn-ghost btn-sm" style="white-space:nowrap">
          <i class="fas fa-times"></i> Effacer
        </a>
        <?php endif; ?>

      </form>
    </div>
  </div>

  <?php if (empty($tree)): ?>
  <div class="empty-state">
    <div class="icon"><i class="fas fa-list-ol"></i></div>
    <h3>Aucune séquence</h3>
    <p>Créez votre première séquence pour organiser vos séances.</p>
    <a href="<?= url('teacher/courses/sequence_create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvelle séquence</a>
  </div>
  <?php else: ?>

  <?php foreach ($tree as $rncpId => $rncpNode): ?>
  <div style="margin-bottom:28px">

    <!-- Titre RNCP -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      <div style="padding:3px 10px;background:rgba(139,92,246,.15);border:1px solid rgba(139,92,246,.3);border-radius:20px;font-size:11px;font-weight:800;color:#a78bfa">
        <?= e($rncpNode['rncp_code']) ?>
      </div>
      <span style="font-size:13px;color:var(--text-muted)"><?= e(mb_substr($rncpNode['rncp_title'], 0, 80)) ?></span>
    </div>

    <?php foreach ($rncpNode['ats'] as $atId => $atNode): ?>
    <div style="margin-bottom:16px;padding-left:12px;border-left:2px solid rgba(245,158,11,.3)">

      <div style="display:flex;align-items:center;gap:6px;margin-bottom:10px">
        <span style="font-size:11px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.06em">
          <i class="fas fa-layer-group" style="margin-right:4px"></i><?= e($atNode['at_code']) ?>
        </span>
        <span style="font-size:12px;color:var(--text-muted)"><?= e(mb_substr($atNode['at_title'], 0, 70)) ?></span>
      </div>

      <?php foreach ($atNode['comps'] as $compId => $compNode):
        $compSeqs = $compNode['seqs'];
        $seqCount = count($compSeqs);
      ?>
      <div style="margin-bottom:12px;padding-left:12px;border-left:2px solid rgba(239,68,68,.25)">

        <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px">
          <span style="font-size:11px;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:.06em">
            <i class="fas fa-bullseye" style="margin-right:4px"></i><?= e($compNode['comp_code']) ?>
          </span>
          <span style="font-size:12px;color:var(--text-muted)"><?= e(mb_substr($compNode['comp_title'], 0, 70)) ?></span>
          <a href="<?= url('teacher/courses/sequence_create.php?competency_id=' . $compId) ?>"
             class="btn btn-ghost btn-sm" style="margin-left:auto;font-size:11px;color:var(--primary-light)" title="Ajouter une séquence dans cette compétence">
            <i class="fas fa-plus"></i> Séquence
          </a>
        </div>

        <div class="card" style="overflow:visible">
          <?php foreach ($compSeqs as $si => $seq):
            $isFirst = $si === 0;
            $isLast  = $si === $seqCount - 1;
            $isNew   = $seq['id'] === $highlight;
          ?>
          <div id="seq-<?= $seq['id'] ?>"
               style="display:flex;align-items:center;gap:10px;padding:10px 14px;<?= $si > 0 ? 'border-top:1px solid var(--border-faint,rgba(255,255,255,.05))' : '' ?>;<?= $isNew ? 'background:rgba(16,185,129,.07)' : '' ?>;<?= !$seq['is_published'] ? 'opacity:.45' : '' ?>;transition:background .2s,opacity .2s"
               class="seq-row">

            <!-- Numéro -->
            <div style="width:26px;height:26px;border-radius:50%;background:rgba(99,102,241,.15);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:var(--primary-light);flex-shrink:0">
              <?= $si + 1 ?>
            </div>

            <!-- Titre -->
            <div style="flex:1;min-width:0">
              <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($seq['title']) ?></div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px;display:flex;align-items:center;gap:8px">
                <span><?= $seq['seance_count'] ?> séance<?= $seq['seance_count'] !== '1' ? 's' : '' ?></span>
                <?php if (!empty($seq['scenario_path'])): ?>
                <a href="<?= uploadUrl($seq['scenario_path']) ?>" target="_blank"
                   style="display:inline-flex;align-items:center;gap:3px;color:#6366f1;text-decoration:none;font-size:10px;font-weight:600;letter-spacing:.02em"
                   title="Scénario pédagogique disponible — cliquer pour télécharger">
                  <i class="fas fa-file-alt"></i> Scénario
                </a>
                <?php endif; ?>
              </div>
            </div>

            <!-- Flèches ↑↓ -->
            <div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0">
              <?php if (!$isFirst): ?>
              <form method="POST" style="margin:0">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="seq_up">
                <input type="hidden" name="seq_id" value="<?= $seq['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 7px;width:28px;height:24px;display:flex;align-items:center;justify-content:center" title="Remonter">
                  <i class="fas fa-chevron-up" style="font-size:10px"></i>
                </button>
              </form>
              <?php else: ?><div style="width:28px;height:24px"></div><?php endif; ?>

              <?php if (!$isLast): ?>
              <form method="POST" style="margin:0">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="seq_down">
                <input type="hidden" name="seq_id" value="<?= $seq['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 7px;width:28px;height:24px;display:flex;align-items:center;justify-content:center" title="Descendre">
                  <i class="fas fa-chevron-down" style="font-size:10px"></i>
                </button>
              </form>
              <?php else: ?><div style="width:28px;height:24px"></div><?php endif; ?>
            </div>

            <!-- Actions -->
            <div style="display:flex;gap:4px;flex-shrink:0">
              <form method="POST" style="margin:0" title="<?= $seq['is_published'] ? 'Publié — masquer aux apprenants' : 'Masqué — rendre visible' ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="seq_toggle_publish">
                <input type="hidden" name="seq_id" value="<?= $seq['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;font-size:11px;color:<?= $seq['is_published'] ? '#10b981' : 'var(--text-muted)' ?>">
                  <i class="fas <?= $seq['is_published'] ? 'fa-eye' : 'fa-eye-slash' ?>"></i>
                </button>
              </form>
              <a href="<?= url('teacher/courses/seance_create.php?seq_id=' . $seq['id']) ?>"
                 class="btn btn-ghost btn-sm" style="padding:4px 8px;font-size:11px;color:var(--primary-light)" title="Ajouter une séance">
                <i class="fas fa-plus"></i>
              </a>
              <a href="<?= url('teacher/courses/sequence_create.php?id=' . $seq['id']) ?>"
                 class="btn btn-ghost btn-sm" style="padding:4px 8px;font-size:11px" title="Modifier la séquence">
                <i class="fas fa-edit"></i>
              </a>
            </div>

          </div>
          <?php endforeach; ?>
        </div>

      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <?php endif; ?>
</div>
<style>
.seq-row:hover { background: var(--bg-elevated) !important; }
</style>
<script>
function onRncpChange(val) {
  // Réinitialise AT et Comp, soumet
  document.querySelector('[name="at_id"]').value = '';
  document.querySelector('[name="competency_id"]').value = '';
  document.getElementById('filter-form').submit();
}
function onAtChange(val) {
  // Réinitialise Comp, soumet
  document.querySelector('[name="competency_id"]').value = '';
  document.getElementById('filter-form').submit();
}
</script>
<?php renderFooter(); ?>
