<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM rncp_titles WHERE id = ?');
$stmt->execute([$id]);
$title = $stmt->fetch();
if (!$title) { setFlash('error','Titre RNCP introuvable.'); redirect(url('admin/rncp/index.php')); }

// Activity types with competencies
$ats = $pdo->prepare('SELECT * FROM activity_types WHERE rncp_title_id = ? ORDER BY order_num');
$ats->execute([$id]); $activityTypes = $ats->fetchAll();

foreach ($activityTypes as &$at) {
    $comps = $pdo->prepare('SELECT * FROM competencies WHERE activity_type_id = ? ORDER BY order_num');
    $comps->execute([$at['id']]); $at['competencies'] = $comps->fetchAll();
}
unset($at);

// Handle AJAX/POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_at') {
        $pdo->prepare('INSERT INTO activity_types (rncp_title_id,code,title,description,order_num) VALUES (?,?,?,?,?)')
            ->execute([$id, trim($_POST['code']), trim($_POST['title']), trim($_POST['description']??''), (int)($_POST['order_num']??1)]);
        setFlash('success','Bloc de compétences ajouté.');
    } elseif ($action === 'add_competency') {
        $atId = (int)$_POST['at_id'];
        $codes    = $_POST['codes'] ?? [];
        $titles   = $_POST['titles'] ?? [];
        $descs    = $_POST['descs'] ?? [];
        $crits    = $_POST['crits'] ?? [];
        $stmt = $pdo->prepare('INSERT INTO competencies (activity_type_id,code,title,description,evaluation_criteria,order_num) VALUES (?,?,?,?,?,?)');
        // Ordre de départ = max actuel + 1
        $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(order_num),0) FROM competencies WHERE activity_type_id=?');
        $maxOrder->execute([$atId]);
        $orderBase = (int)$maxOrder->fetchColumn();
        $added = 0;
        foreach ($titles as $i => $t) {
            $t = trim($t);
            if ($t === '') continue;
            $stmt->execute([$atId, trim($codes[$i] ?? ''), $t, trim($descs[$i] ?? ''), trim($crits[$i] ?? ''), $orderBase + $i + 1]);
            $added++;
        }
        setFlash('success', $added . ' compétence' . ($added > 1 ? 's ajoutées.' : ' ajoutée.'));
    } elseif ($action === 'edit_competency') {
        $compId = (int)$_POST['comp_id'];
        $pdo->prepare('UPDATE competencies SET code=?,title=?,description=?,evaluation_criteria=? WHERE id=?')
            ->execute([trim($_POST['code']), trim($_POST['title']), trim($_POST['description']??''), trim($_POST['criteria']??''), $compId]);
        setFlash('success', 'Compétence modifiée.');
    } elseif ($action === 'delete_at') {
        $pdo->prepare('DELETE FROM activity_types WHERE id=? AND rncp_title_id=?')->execute([(int)$_POST['at_id'], $id]);
        setFlash('success','Bloc supprimé.');
    } elseif ($action === 'delete_competency') {
        $pdo->prepare('DELETE FROM competencies WHERE id=?')->execute([(int)$_POST['comp_id']]);
        setFlash('success','Compétence supprimée.');
    }
    redirect(url('admin/rncp/view.php?id='.$id));
}

renderHead('Structure — '.$title['rncp_code']);
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar($title['rncp_code'].' — '.$title['title'], [['RNCP', url('admin/rncp/index.php')], ['Structure', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Header -->
  <div class="card" style="margin-bottom:24px">
    <div class="card-body">
      <div style="display:flex;align-items:flex-start;gap:20px">
        <div style="width:64px;height:64px;border-radius:var(--radius-lg);background:linear-gradient(135deg,rgba(99,102,241,.25),rgba(139,92,246,.2));border:1px solid rgba(99,102,241,.3);display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0">🎓</div>
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <span class="badge badge-primary"><?= e($title['rncp_code']) ?></span>
            <span class="badge badge-info">Niveau <?= e($title['level']) ?></span>
            <?= getStatusBadge($title['status']) ?>
          </div>
          <h2 style="font-size:20px;font-weight:800;color:white;margin-bottom:4px"><?= e($title['title']) ?></h2>
          <?php if ($title['certifier']): ?><p style="color:var(--text-muted);font-size:13px"><?= e($title['certifier']) ?></p><?php endif; ?>
          <?php if ($title['description']): ?><p style="color:var(--text-muted);font-size:14px;margin-top:8px;max-width:700px"><?= e($title['description']) ?></p><?php endif; ?>
        </div>
        <div style="display:flex;gap:8px">
          <a href="<?= url('admin/rncp/create.php?id='.$id) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> Modifier</a>
          <a href="<?= url('admin/formations/create.php?rncp_id='.$id) ?>" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Créer une formation</a>
        </div>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 320px;gap:24px;align-items:start">
    <!-- Tree Structure -->
    <div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <h3 style="font-size:18px;font-weight:700">Blocs de compétences (<?= count($activityTypes) ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('modal-add-at').style.display='flex'"><i class="fas fa-plus"></i> Ajouter un bloc</button>
      </div>

      <?php if (empty($activityTypes)): ?>
      <div class="empty-state">
        <div class="icon"><i class="fas fa-sitemap"></i></div>
        <h3>Aucun bloc de compétences</h3>
        <p>Commencez par créer les activités types du référentiel.</p>
        <button class="btn btn-primary" onclick="document.getElementById('modal-add-at').style.display='flex'">Ajouter un bloc</button>
      </div>
      <?php endif; ?>

      <?php foreach ($activityTypes as $at): ?>
      <div class="card" style="margin-bottom:16px;overflow:visible">
        <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:12px">
          <div style="width:38px;height:38px;border-radius:var(--radius);background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:white;flex-shrink:0"><?= e($at['code']) ?></div>
          <div style="flex:1">
            <div style="font-size:15px;font-weight:700;color:white"><?= e($at['title']) ?></div>
            <?php if ($at['description']): ?><div style="font-size:12px;color:var(--text-muted);margin-top:3px"><?= e($at['description']) ?></div><?php endif; ?>
            <div style="font-size:11px;color:var(--text-faint);margin-top:4px"><?= count($at['competencies']) ?> compétence<?= count($at['competencies'])>1?'s':'' ?></div>
          </div>
          <div style="display:flex;gap:6px">
            <button class="btn btn-success btn-sm btn-add-comp" data-id="<?= $at['id'] ?>" data-title="<?= e($at['title']) ?>"><i class="fas fa-plus"></i></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce bloc et toutes ses compétences ?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_at">
              <input type="hidden" name="at_id" value="<?= $at['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)"><i class="fas fa-trash"></i></button>
            </form>
          </div>
        </div>

        <!-- Competencies -->
        <?php if (!empty($at['competencies'])): ?>
        <div style="padding:8px 0">
          <?php foreach ($at['competencies'] as $ci => $comp): ?>
          <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 20px 10px 32px;<?= $ci<count($at['competencies'])-1?'border-bottom:1px solid var(--border-light)':'' ?>">
            <div style="width:6px;height:6px;border-radius:50%;background:var(--primary-light);margin-top:6px;flex-shrink:0"></div>
            <div style="flex:1">
              <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px">
                <span style="font-size:10px;font-weight:700;color:var(--primary-light);background:rgba(99,102,241,.15);padding:1px 7px;border-radius:var(--radius-full)"><?= e($comp['code']) ?></span>
              </div>
              <div style="font-size:13px;font-weight:600;color:var(--text)"><?= e($comp['title']) ?></div>
              <?php if ($comp['description']): ?><div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= e($comp['description']) ?></div><?php endif; ?>
              <?php if ($comp['evaluation_criteria']): ?>
              <div style="margin-top:6px;font-size:11px;color:var(--text-faint)">
                <i class="fas fa-clipboard-check" style="margin-right:4px"></i><?= e($comp['evaluation_criteria']) ?>
              </div>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:4px;flex-shrink:0">
              <button class="btn btn-ghost btn-sm btn-edit-comp"
                data-id="<?= $comp['id'] ?>"
                data-code="<?= e($comp['code']) ?>"
                data-title="<?= e($comp['title']) ?>"
                data-desc="<?= e($comp['description'] ?? '') ?>"
                data-criteria="<?= e($comp['evaluation_criteria'] ?? '') ?>"
                title="Modifier"><i class="fas fa-edit"></i></button>
              <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette compétence ?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_competency">
                <input type="hidden" name="comp_id" value="<?= $comp['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)"><i class="fas fa-times"></i></button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="padding:16px 20px;text-align:center;color:var(--text-faint);font-size:13px">
          <i class="fas fa-plus-circle" style="margin-right:6px"></i>
          <button class="btn btn-ghost btn-sm btn-add-comp" data-id="<?= $at['id'] ?>" data-title="<?= e($at['title']) ?>">Ajouter une compétence</button>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Summary panel -->
    <div style="position:sticky;top:80px">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Résumé</h3></div>
        <div class="card-body">
          <?php
            $totalComps = 0; foreach($activityTypes as $a) $totalComps += count($a['competencies']);
          ?>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;text-align:center">
            <div style="background:var(--bg-elevated);padding:16px;border-radius:var(--radius)">
              <div style="font-size:24px;font-weight:800;color:white"><?= count($activityTypes) ?></div>
              <div style="font-size:11px;color:var(--text-muted)">Blocs</div>
            </div>
            <div style="background:var(--bg-elevated);padding:16px;border-radius:var(--radius)">
              <div style="font-size:24px;font-weight:800;color:white"><?= $totalComps ?></div>
              <div style="font-size:11px;color:var(--text-muted)">Compétences</div>
            </div>
          </div>
          <div style="margin-top:16px;display:flex;flex-direction:column;gap:8px">
            <a href="<?= url('admin/formations/create.php?rncp_id='.$id) ?>" class="btn btn-primary w-full" style="justify-content:center"><i class="fas fa-graduation-cap"></i> Créer une formation</a>
            <a href="<?= url('admin/rncp/index.php') ?>" class="btn btn-ghost w-full" style="justify-content:center"><i class="fas fa-arrow-left"></i> Retour</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Add Activity Type -->
<div id="modal-add-at" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;padding:20px">
  <div class="card" style="width:100%;max-width:520px;max-height:90vh;overflow-y:auto">
    <div class="card-header">
      <h3 class="card-title">Ajouter un bloc de compétences</h3>
      <button class="btn-icon" onclick="document.getElementById('modal-add-at').style.display='none'"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_at">
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Code <span class="required">*</span></label>
            <input type="text" name="code" class="form-control" placeholder="AT1" required>
          </div>
          <div class="form-group">
            <label class="form-label">N° d'ordre</label>
            <input type="number" name="order_num" class="form-control" value="<?= count($activityTypes)+1 ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Intitulé du bloc <span class="required">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="Réaliser des développements..." required>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3" placeholder="Description de l'activité type..."></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-add-at').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary">Ajouter le bloc</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal Edit Competency -->
<div id="modal-edit-comp" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;padding:20px">
  <div class="card" style="width:100%;max-width:560px;max-height:90vh;overflow-y:auto">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-edit" style="color:var(--primary-light);margin-right:8px"></i>Modifier la compétence</h3>
      <button class="btn-icon" onclick="document.getElementById('modal-edit-comp').style.display='none'"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_competency">
      <input type="hidden" name="comp_id" id="edit-comp-id">
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">Code</label>
          <input type="text" name="code" id="edit-comp-code" class="form-control" placeholder="C1.1">
        </div>
        <div class="form-group">
          <label class="form-label">Intitulé <span class="required">*</span></label>
          <input type="text" name="title" id="edit-comp-title" class="form-control" placeholder="Analyser les besoins..." required>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" id="edit-comp-desc" class="form-control" rows="3" placeholder="Description de la compétence..."></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Critères d'évaluation</label>
          <textarea name="criteria" id="edit-comp-criteria" class="form-control" rows="3" placeholder="Critères permettant de valider cette compétence..."></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-edit-comp').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal Add Competency -->
<div id="modal-add-comp" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;padding:20px">
  <div class="card" style="width:100%;max-width:680px;max-height:90vh;overflow-y:auto">
    <div class="card-header" style="position:sticky;top:0;background:var(--bg-card);z-index:1">
      <h3 class="card-title"><i class="fas fa-plus-circle" style="color:var(--success);margin-right:8px"></i>Compétences — <span id="comp-at-name" style="font-weight:400;color:var(--text-muted)"></span></h3>
      <button class="btn-icon" onclick="document.getElementById('modal-add-comp').style.display='none'"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_competency">
      <input type="hidden" name="at_id" id="comp-at-id">
      <div class="card-body">

        <div id="comp-rows">
          <!-- Les lignes sont générées par JS -->
        </div>

        <button type="button" id="btn-add-row" class="btn btn-ghost w-full" style="border:2px dashed var(--border);margin-bottom:16px">
          <i class="fas fa-plus"></i> Ajouter une compétence
        </button>

        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-add-comp').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer tout</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
function makeCompRow(index) {
  var div = document.createElement('div');
  div.className = 'comp-row';
  div.style.cssText = 'background:var(--bg-elevated);border-radius:var(--radius);padding:14px;margin-bottom:10px;position:relative';
  div.innerHTML =
    '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">' +
      '<span style="font-size:12px;font-weight:700;color:var(--primary-light)">Compétence ' + (index + 1) + '</span>' +
      (index > 0 ? '<button type="button" onclick="this.closest(\'.comp-row\').remove();reindexRows()" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:16px"><i class="fas fa-times"></i></button>' : '') +
    '</div>' +
    '<div style="display:grid;grid-template-columns:120px 1fr;gap:10px;margin-bottom:8px">' +
      '<div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Code</label>' +
      '<input type="text" name="codes[]" class="form-control" placeholder="C1.1"></div>' +
      '<div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Intitulé <span style="color:var(--danger)">*</span></label>' +
      '<input type="text" name="titles[]" class="form-control" placeholder="Analyser les besoins..." required></div>' +
    '</div>' +
    '<div style="margin-bottom:8px"><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Description</label>' +
    '<textarea name="descs[]" class="form-control" rows="2" placeholder="Description..."></textarea></div>' +
    '<div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Critères d\'évaluation</label>' +
    '<textarea name="crits[]" class="form-control" rows="2" placeholder="Critères de validation..."></textarea></div>';
  return div;
}

function reindexRows() {
  document.querySelectorAll('.comp-row').forEach(function(row, i) {
    var label = row.querySelector('span');
    if (label) label.textContent = 'Compétence ' + (i + 1);
  });
}

document.addEventListener('DOMContentLoaded', function() {

  // Boutons ouvrir modal compétence
  document.querySelectorAll('.btn-add-comp').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.getElementById('comp-at-id').value = this.dataset.id;
      document.getElementById('comp-at-name').textContent = this.dataset.title;
      // Réinitialiser les lignes
      var rows = document.getElementById('comp-rows');
      rows.innerHTML = '';
      rows.appendChild(makeCompRow(0));
      document.getElementById('modal-add-comp').style.display = 'flex';
    });
  });

  // Ajouter une ligne
  document.getElementById('btn-add-row').addEventListener('click', function() {
    var rows = document.getElementById('comp-rows');
    rows.appendChild(makeCompRow(rows.querySelectorAll('.comp-row').length));
  });

  // Boutons modifier compétence
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.btn-edit-comp');
    if (!btn) return;
    document.getElementById('edit-comp-id').value       = btn.dataset.id;
    document.getElementById('edit-comp-code').value     = btn.dataset.code;
    document.getElementById('edit-comp-title').value    = btn.dataset.title;
    document.getElementById('edit-comp-desc').value     = btn.dataset.desc;
    document.getElementById('edit-comp-criteria').value = btn.dataset.criteria;
    document.getElementById('modal-edit-comp').style.display = 'flex';
  });

  // Fermer en cliquant à l'extérieur
  ['modal-add-at','modal-add-comp','modal-edit-comp'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) {
      if (e.target === this) this.style.display = 'none';
    });
  });
});
</script>
<?php renderFooter(); ?>
