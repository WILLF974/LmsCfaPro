<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Séquences ayant au moins une séance
$sequencesStmt = $pdo->query("
    SELECT DISTINCT seq.id, seq.title, c.code AS comp_code
    FROM sequences seq
    JOIN modules m ON m.sequence_id = seq.id
    LEFT JOIN competencies c ON seq.competency_id = c.id
    ORDER BY seq.title
");
$sequences = $sequencesStmt->fetchAll();

$sequenceId = (int)($_GET['sequence_id'] ?? ($sequences[0]['id'] ?? 0));

// ── Actions POST : monter / descendre ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action   = $_POST['action'] ?? '';
    $moduleId = (int)($_POST['module_id'] ?? 0);
    $seqId    = (int)($_POST['sequence_id'] ?? 0);

    if ($action === 'toggle_publish_seq' && $seqId) {
        $pdo->prepare("UPDATE sequences SET is_published = 1 - is_published WHERE id = ?")->execute([$seqId]);
    }

    if ($action === 'toggle_publish_module' && $moduleId) {
        $pdo->prepare("UPDATE modules SET is_published = 1 - is_published WHERE id = ?")->execute([$moduleId]);
    }

    if (in_array($action, ['move_seq_up', 'move_seq_down']) && $seqId) {
        $row = $pdo->prepare('SELECT id, competency_id, order_num FROM sequences WHERE id=?');
        $row->execute([$seqId]);
        $current = $row->fetch();
        if ($current) {
            $siblings = $pdo->prepare('SELECT id, order_num FROM sequences WHERE competency_id=? ORDER BY order_num, id');
            $siblings->execute([$current['competency_id']]);
            $list = $siblings->fetchAll();
            $pos  = null;
            foreach ($list as $i => $r) { if ($r['id'] == $seqId) { $pos = $i; break; } }
            $swapPos = $action === 'move_seq_up' ? $pos - 1 : $pos + 1;
            if ($pos !== null && isset($list[$swapPos])) {
                $a = $list[$pos]['order_num']    ?: $pos + 1;
                $b = $list[$swapPos]['order_num'] ?: $swapPos + 1;
                if ($a === $b) { $a = $pos + 1; $b = $swapPos + 1; }
                $pdo->prepare('UPDATE sequences SET order_num=? WHERE id=?')->execute([$b, $seqId]);
                $pdo->prepare('UPDATE sequences SET order_num=? WHERE id=?')->execute([$a, $list[$swapPos]['id']]);
                $siblings2 = $pdo->prepare('SELECT id FROM sequences WHERE competency_id=? ORDER BY order_num, id');
                $siblings2->execute([$current['competency_id']]);
                foreach ($siblings2->fetchAll() as $k => $r) {
                    $pdo->prepare('UPDATE sequences SET order_num=? WHERE id=?')->execute([$k + 1, $r['id']]);
                }
            }
        }
    }

    if (in_array($action, ['move_up', 'move_down']) && $moduleId && $seqId) {
        $check = $pdo->prepare('SELECT id, order_num FROM modules WHERE id=? AND sequence_id=?');
        $check->execute([$moduleId, $seqId]);
        $current = $check->fetch();

        if ($current) {
            $siblings = $pdo->prepare('SELECT id, order_num FROM modules WHERE sequence_id=? ORDER BY order_num, id');
            $siblings->execute([$seqId]);
            $list = $siblings->fetchAll();

            $pos = null;
            foreach ($list as $i => $row) {
                if ($row['id'] == $moduleId) { $pos = $i; break; }
            }

            $swapPos = $action === 'move_up' ? $pos - 1 : $pos + 1;

            if ($pos !== null && isset($list[$swapPos])) {
                $targetId     = $list[$swapPos]['id'];
                $currentOrder = $list[$pos]['order_num'];
                $targetOrder  = $list[$swapPos]['order_num'];

                if ($currentOrder === $targetOrder) {
                    $currentOrder = $pos + 1;
                    $targetOrder  = $swapPos + 1;
                }

                $pdo->prepare('UPDATE modules SET order_num=? WHERE id=?')->execute([$targetOrder, $moduleId]);
                $pdo->prepare('UPDATE modules SET order_num=? WHERE id=?')->execute([$currentOrder, $targetId]);

                $siblings2 = $pdo->prepare('SELECT id FROM modules WHERE sequence_id=? ORDER BY order_num, id');
                $siblings2->execute([$seqId]);
                foreach ($siblings2->fetchAll() as $k => $row) {
                    $pdo->prepare('UPDATE modules SET order_num=? WHERE id=?')->execute([$k + 1, $row['id']]);
                }
            }
        }
    }
    $redirectTo = $_POST['redirect_to'] ?? '';
    if ($redirectTo && str_starts_with($redirectTo, 'teacher/')) {
        redirect(url($redirectTo));
    } else {
        redirect(url("teacher/courses/order.php?sequence_id=$seqId"));
    }
}

// ── Charger les séances de la séquence ───────────────────────
$seances = [];
if ($sequenceId) {
    $stmt = $pdo->prepare("
        SELECT m.id, m.title, m.content_type, m.duration_hours, m.xp_reward,
               m.is_mandatory, m.order_num, m.created_by, m.is_published, u.first_name, u.last_name
        FROM modules m
        LEFT JOIN users u ON m.created_by = u.id
        WHERE m.sequence_id = ?
        ORDER BY m.order_num, m.id
    ");
    $stmt->execute([$sequenceId]);
    $seances = $stmt->fetchAll();
}

$sequenceTitle = '';
foreach ($sequences as $s) {
    if ($s['id'] == $sequenceId) { $sequenceTitle = $s['title']; break; }
}

renderHead('Ordre des séances');
renderSidebar(isAdmin() ? 'admin' : (isPedagogy() ? 'pedagogy' : 'teacher'));
renderTopbar('Ordre des séances', [
    ['Enseignant', url('teacher/index.php')],
    ['Séances', url('teacher/courses/index.php')],
    ['Ordre', '']
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <?php if (count($sequences) > 1): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <form method="GET" style="display:flex;align-items:center;gap:12px">
        <label style="font-size:13px;font-weight:600;color:var(--text-muted);white-space:nowrap">Séquence :</label>
        <select name="sequence_id" class="form-control" style="flex:1;max-width:480px" onchange="this.form.submit()">
          <?php foreach ($sequences as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id']==$sequenceId?'selected':'' ?>><?= e($s['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$sequenceId || empty($seances)): ?>
  <div class="empty-state">
    <div class="icon">📚</div>
    <h3>Aucune séance trouvée</h3>
    <p>Créez d'abord des séances dans une séquence.</p>
    <a href="<?= url('teacher/courses/seance_create.php') ?>" class="btn btn-primary">Créer une séance</a>
  </div>
  <?php else: ?>

  <div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <h2 style="font-size:16px;font-weight:700;margin-bottom:2px"><?= e($sequenceTitle) ?></h2>
      <p style="font-size:13px;color:var(--text-muted)">Utilisez les flèches pour réorganiser les séances de cette séquence.</p>
    </div>
    <a href="<?= url('teacher/courses/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Mes séances</a>
  </div>

  <div class="card">
    <?php $total = count($seances); ?>
    <div style="display:flex;flex-direction:column">
      <?php foreach ($seances as $j => $s):
        $isFirst = $j === 0;
        $isLast  = $j === $total - 1;
        $isOwn   = $s['created_by'] == $userId;
      ?>
      <div class="seance-order-row" style="display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border-faint, rgba(255,255,255,.05));transition:background .15s,opacity .2s;<?= ($s['is_published'] ?? 1) ? '' : 'opacity:.45' ?>">

        <div style="width:28px;height:28px;border-radius:50%;background:var(--bg-elevated);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--text-muted);flex-shrink:0">
          <?= $j + 1 ?>
        </div>

        <i class="<?= getContentTypeIcon($s['content_type']) ?>" style="color:var(--text-muted);width:16px;flex-shrink:0"></i>

        <div style="flex:1;min-width:0">
          <div style="font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($s['title']) ?></div>
          <div style="display:flex;gap:8px;margin-top:3px;flex-wrap:wrap">
            <span style="font-size:11px;color:var(--text-faint)"><?= ucfirst($s['content_type']) ?></span>
            <?php if ($s['duration_hours']): ?><span style="font-size:11px;color:var(--text-faint)"><i class="fas fa-clock"></i> <?= $s['duration_hours'] ?>h</span><?php endif; ?>
            <span style="font-size:11px;color:var(--warning)"><i class="fas fa-bolt"></i> +<?= $s['xp_reward'] ?> XP</span>
            <?php if (!$s['is_mandatory']): ?><span class="badge badge-secondary" style="font-size:10px">Optionnel</span><?php endif; ?>
            <?php if (!$isOwn && $s['first_name']): ?><span class="badge badge-secondary" style="font-size:10px;color:var(--text-faint)">Par <?= e($s['first_name']) ?></span><?php endif; ?>
          </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:3px;flex-shrink:0">
          <?php if (!$isFirst): ?>
          <form method="POST" style="margin:0">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="move_up">
            <input type="hidden" name="module_id" value="<?= $s['id'] ?>">
            <input type="hidden" name="sequence_id" value="<?= $sequenceId ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;width:32px;height:28px;display:flex;align-items:center;justify-content:center" title="Monter">
              <i class="fas fa-chevron-up" style="font-size:11px"></i>
            </button>
          </form>
          <?php else: ?>
          <div style="width:32px;height:28px"></div>
          <?php endif; ?>

          <?php if (!$isLast): ?>
          <form method="POST" style="margin:0">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="move_down">
            <input type="hidden" name="module_id" value="<?= $s['id'] ?>">
            <input type="hidden" name="sequence_id" value="<?= $sequenceId ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;width:32px;height:28px;display:flex;align-items:center;justify-content:center" title="Descendre">
              <i class="fas fa-chevron-down" style="font-size:11px"></i>
            </button>
          </form>
          <?php else: ?>
          <div style="width:32px;height:28px"></div>
          <?php endif; ?>
        </div>

        <form method="POST" style="margin:0;flex-shrink:0" title="<?= ($s['is_published'] ?? 1) ? 'Publié — masquer aux apprenants' : 'Masqué — rendre visible' ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_publish_module">
          <input type="hidden" name="module_id" value="<?= $s['id'] ?>">
          <input type="hidden" name="sequence_id" value="<?= $sequenceId ?>">
          <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;color:<?= ($s['is_published'] ?? 1) ? '#10b981' : 'var(--text-muted)' ?>">
            <i class="fas <?= ($s['is_published'] ?? 1) ? 'fa-eye' : 'fa-eye-slash' ?>"></i>
          </button>
        </form>
        <a href="<?= url('teacher/courses/seance_create.php?id='.$s['id']) ?>" class="btn btn-ghost btn-sm" style="flex-shrink:0;padding:4px 10px" title="Modifier">
          <i class="fas fa-edit"></i>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<style>
.seance-order-row:hover { background: var(--bg-elevated); }
</style>
<?php renderFooter(); ?>
