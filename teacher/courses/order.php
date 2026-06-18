<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Formations dont l'enseignant a au moins une capsule
$formationsStmt = $pdo->prepare("
    SELECT DISTINCT f.id, f.title
    FROM formations f
    JOIN modules m ON m.formation_id = f.id
    JOIN lessons l ON l.module_id = m.id
    WHERE l.created_by = ?
    ORDER BY f.title
");
$formationsStmt->execute([$userId]);
$formations = $formationsStmt->fetchAll();

$formationId = (int)($_GET['formation_id'] ?? ($formations[0]['id'] ?? 0));

// ── Actions POST : monter / descendre ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action   = $_POST['action'] ?? '';
    $lessonId = (int)($_POST['lesson_id'] ?? 0);
    $moduleId = (int)($_POST['module_id'] ?? 0);

    if (in_array($action, ['move_up', 'move_down']) && $lessonId && $moduleId) {
        // Vérifier que l'enseignant possède cette capsule
        $check = $pdo->prepare('SELECT id, order_num FROM lessons WHERE id=? AND created_by=? AND module_id=?');
        $check->execute([$lessonId, $userId, $moduleId]);
        $current = $check->fetch();

        if ($current) {
            // Récupérer toutes les capsules du module triées
            $siblings = $pdo->prepare('SELECT id, order_num FROM lessons WHERE module_id=? ORDER BY order_num, id');
            $siblings->execute([$moduleId]);
            $list = $siblings->fetchAll();

            // Trouver la position courante
            $pos = null;
            foreach ($list as $i => $row) {
                if ($row['id'] == $lessonId) { $pos = $i; break; }
            }

            $swapPos = $action === 'move_up' ? $pos - 1 : $pos + 1;

            if ($pos !== null && isset($list[$swapPos])) {
                $targetId     = $list[$swapPos]['id'];
                $currentOrder = $list[$pos]['order_num'];
                $targetOrder  = $list[$swapPos]['order_num'];

                // Si order_num identiques, forcer des valeurs distinctes
                if ($currentOrder === $targetOrder) {
                    $currentOrder = $pos + 1;
                    $targetOrder  = $swapPos + 1;
                }

                $pdo->prepare('UPDATE lessons SET order_num=? WHERE id=?')->execute([$targetOrder, $lessonId]);
                $pdo->prepare('UPDATE lessons SET order_num=? WHERE id=?')->execute([$currentOrder, $targetId]);

                // Renuméroter proprement après le swap
                $siblings2 = $pdo->prepare('SELECT id FROM lessons WHERE module_id=? ORDER BY order_num, id');
                $siblings2->execute([$moduleId]);
                foreach ($siblings2->fetchAll() as $k => $row) {
                    $pdo->prepare('UPDATE lessons SET order_num=? WHERE id=?')->execute([$k + 1, $row['id']]);
                }
            }
        }
    }
    redirect(url("teacher/courses/order.php?formation_id=$formationId#module-$moduleId"));
}

// ── Charger modules + capsules de la formation ───────────────
$modulesStmt = $pdo->prepare("
    SELECT m.id, m.title, m.order_num, at.code as at_code, at.title as at_title
    FROM modules m
    LEFT JOIN activity_types at ON m.activity_type_id = at.id
    WHERE m.formation_id = ?
    ORDER BY m.order_num
");
$modulesStmt->execute([$formationId]);
$modules = $modulesStmt->fetchAll();

$lessonsByModule = [];
foreach ($modules as $mod) {
    $stmt = $pdo->prepare("
        SELECT l.id, l.title, l.content_type, l.duration_minutes, l.xp_reward,
               l.is_mandatory, l.order_num, l.created_by
        FROM lessons l
        WHERE l.module_id = ?
        ORDER BY l.order_num, l.id
    ");
    $stmt->execute([$mod['id']]);
    $lessonsByModule[$mod['id']] = $stmt->fetchAll();
}

$formationTitle = '';
foreach ($formations as $f) {
    if ($f['id'] == $formationId) { $formationTitle = $f['title']; break; }
}

renderHead('Ordre des capsules');
renderSidebar('teacher');
renderTopbar('Ordre des capsules', [
    ['Enseignant', url('teacher/index.php')],
    ['Capsules', url('teacher/courses/index.php')],
    ['Ordre', '']
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Sélecteur de formation -->
  <?php if (count($formations) > 1): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <form method="GET" style="display:flex;align-items:center;gap:12px">
        <label style="font-size:13px;font-weight:600;color:var(--text-muted);white-space:nowrap">Formation :</label>
        <select name="formation_id" class="form-control" style="flex:1;max-width:480px" onchange="this.form.submit()">
          <?php foreach ($formations as $f): ?>
          <option value="<?= $f['id'] ?>" <?= $f['id']==$formationId?'selected':'' ?>><?= e($f['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$formationId || empty($modules)): ?>
  <div class="empty-state">
    <div class="icon">📚</div>
    <h3>Aucun module trouvé</h3>
    <p>Créez d'abord des capsules dans une formation.</p>
    <a href="<?= url('teacher/courses/create.php') ?>" class="btn btn-primary">Créer une capsule</a>
  </div>
  <?php else: ?>

  <div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <h2 style="font-size:16px;font-weight:700;margin-bottom:2px"><?= e($formationTitle) ?></h2>
      <p style="font-size:13px;color:var(--text-muted)">Glissez ou utilisez les flèches pour réorganiser les capsules dans chaque module.</p>
    </div>
    <a href="<?= url('teacher/courses/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Mes capsules</a>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <?php foreach ($modules as $mod):
      $lessons = $lessonsByModule[$mod['id']] ?? [];
      $total   = count($lessons);
    ?>
    <div class="card" id="module-<?= $mod['id'] ?>">
      <div class="card-header">
        <div>
          <h3 class="card-title"><?= e($mod['title']) ?></h3>
          <?php if ($mod['at_title']): ?>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><i class="fas fa-layer-group"></i> <?= e($mod['at_code']) ?> — <?= e($mod['at_title']) ?></div>
          <?php endif; ?>
        </div>
        <span class="badge badge-secondary"><?= $total ?> capsule(s)</span>
      </div>

      <?php if (empty($lessons)): ?>
      <div class="card-body" style="color:var(--text-faint);font-size:13px;text-align:center;padding:24px">
        Aucune capsule dans ce module.
      </div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column">
        <?php foreach ($lessons as $j => $l):
          $isFirst = $j === 0;
          $isLast  = $j === $total - 1;
          $isOwn   = $l['created_by'] == $userId;
        ?>
        <div class="lesson-order-row" style="display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border-faint, rgba(255,255,255,.05));transition:background .15s">

          <!-- Numéro d'ordre -->
          <div style="width:28px;height:28px;border-radius:50%;background:var(--bg-elevated);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--text-muted);flex-shrink:0">
            <?= $j + 1 ?>
          </div>

          <!-- Icône type -->
          <i class="<?= getContentTypeIcon($l['content_type']) ?>" style="color:var(--text-muted);width:16px;flex-shrink:0"></i>

          <!-- Titre + badges -->
          <div style="flex:1;min-width:0">
            <div style="font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($l['title']) ?></div>
            <div style="display:flex;gap:8px;margin-top:3px;flex-wrap:wrap">
              <span style="font-size:11px;color:var(--text-faint)"><?= ucfirst($l['content_type']) ?></span>
              <?php if ($l['duration_minutes']): ?><span style="font-size:11px;color:var(--text-faint)"><i class="fas fa-clock"></i> <?= formatDuration($l['duration_minutes']) ?></span><?php endif; ?>
              <span style="font-size:11px;color:var(--warning)"><i class="fas fa-bolt"></i> +<?= $l['xp_reward'] ?> XP</span>
              <?php if (!$l['is_mandatory']): ?><span class="badge badge-secondary" style="font-size:10px">Optionnel</span><?php endif; ?>
              <?php if (!$isOwn): ?><span class="badge badge-secondary" style="font-size:10px;color:var(--text-faint)">Autre enseignant</span><?php endif; ?>
            </div>
          </div>

          <!-- Boutons monter / descendre -->
          <div style="display:flex;flex-direction:column;gap:3px;flex-shrink:0">
            <?php if (!$isFirst): ?>
            <form method="POST" style="margin:0">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="move_up">
              <input type="hidden" name="lesson_id" value="<?= $l['id'] ?>">
              <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
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
              <input type="hidden" name="lesson_id" value="<?= $l['id'] ?>">
              <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;width:32px;height:28px;display:flex;align-items:center;justify-content:center" title="Descendre">
                <i class="fas fa-chevron-down" style="font-size:11px"></i>
              </button>
            </form>
            <?php else: ?>
            <div style="width:32px;height:28px"></div>
            <?php endif; ?>
          </div>

          <!-- Bouton éditer -->
          <a href="<?= url('teacher/courses/create.php?id='.$l['id']) ?>" class="btn btn-ghost btn-sm" style="flex-shrink:0;padding:4px 10px" title="Modifier">
            <i class="fas fa-edit"></i>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<style>
.lesson-order-row:hover { background: var(--bg-elevated); }
</style>
<?php renderFooter(); ?>
