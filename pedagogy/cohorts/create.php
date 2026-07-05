<?php
require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/layout.php';
requirePedagogy();

$pdo      = getDB();
$editId   = (int)($_GET['id'] ?? 0);
$cohort   = null;
$isEdit   = false;

if ($editId) {
    $s = $pdo->prepare("SELECT * FROM cohorts WHERE id=?");
    $s->execute([$editId]);
    $cohort = $s->fetch();
    if (!$cohort) { setFlash('error', 'Cohorte introuvable.'); redirect(url('pedagogy/cohorts/index.php')); }
    $isEdit = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $name       = trim($_POST['name'] ?? '');
    $rncpId     = (int)($_POST['rncp_title_id'] ?? 0) ?: null;
    $year       = (int)($_POST['year'] ?? 0) ?: null;
    $description = trim($_POST['description'] ?? '') ?: null;

    if (!$name) {
        setFlash('error', 'Le nom est obligatoire.');
    } else {
        if ($isEdit) {
            $pdo->prepare("UPDATE cohorts SET name=?, rncp_title_id=?, year=?, description=? WHERE id=?")
                ->execute([$name, $rncpId, $year, $description, $editId]);
            auditLog('cohort_updated', 'cohort', $editId);
            setFlash('success', 'Cohorte mise à jour.');
            redirect(url('pedagogy/cohorts/view.php?id='.$editId));
        } else {
            $pdo->prepare("INSERT INTO cohorts (name, rncp_title_id, year, description, created_by) VALUES (?,?,?,?,?)")
                ->execute([$name, $rncpId, $year, $description, $_SESSION['user_id']]);
            $newId = (int)$pdo->lastInsertId();
            auditLog('cohort_created', 'cohort', $newId);
            setFlash('success', 'Cohorte créée.');
            redirect(url('pedagogy/cohorts/view.php?id='.$newId));
        }
    }
}

$rncpTitles = $pdo->query("SELECT id, rncp_code, title FROM rncp_titles ORDER BY rncp_code")->fetchAll();

$pageTitle = $isEdit ? 'Modifier la cohorte' : 'Nouvelle cohorte';
renderHead($pageTitle);
renderSidebar('pedagogy');
renderTopbar($pageTitle, [
    ['Pédagogie', url('pedagogy/index.php')],
    ['Cohortes', url('pedagogy/cohorts/index.php')],
    [$pageTitle, ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div style="max-width:640px">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fas fa-layer-group" style="color:var(--primary-light);margin-right:8px"></i><?= $pageTitle ?></h3>
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>

          <div class="form-group">
            <label class="form-label">Nom de la cohorte <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" required
              placeholder="Ex : Promotion BTS MCO 2024-2026"
              value="<?= e($cohort['name'] ?? $_POST['name'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Titre RNCP rattaché <span style="color:var(--text-faint);font-weight:400">(optionnel)</span></label>
            <select name="rncp_title_id" class="form-control">
              <option value="">— Aucun rattachement RNCP —</option>
              <?php foreach ($rncpTitles as $r): ?>
              <option value="<?= $r['id'] ?>" <?= (($cohort['rncp_title_id'] ?? $_POST['rncp_title_id'] ?? '') == $r['id']) ? 'selected' : '' ?>>
                <?= e($r['rncp_code']) ?> — <?= e(mb_substr($r['title'],0,60)) ?><?= mb_strlen($r['title'])>60?'…':'' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint">Permet de regrouper les apprenants selon leur certification cible.</div>
          </div>

          <div class="form-group">
            <label class="form-label">Année de promotion <span style="color:var(--text-faint);font-weight:400">(optionnel)</span></label>
            <input type="number" name="year" class="form-control" min="2000" max="2099" style="width:160px"
              placeholder="<?= date('Y') ?>"
              value="<?= e($cohort['year'] ?? $_POST['year'] ?? '') ?>">
            <div class="form-hint">Année de début ou d'entrée en formation.</div>
          </div>

          <div class="form-group">
            <label class="form-label">Description <span style="color:var(--text-faint);font-weight:400">(optionnel)</span></label>
            <textarea name="description" class="form-control" rows="3"
              placeholder="Informations complémentaires sur cette cohorte…"><?= e($cohort['description'] ?? $_POST['description'] ?? '') ?></textarea>
          </div>

          <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
            <a href="<?= url($isEdit ? 'pedagogy/cohorts/view.php?id='.$editId : 'pedagogy/cohorts/index.php') ?>" class="btn btn-ghost">Annuler</a>
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-<?= $isEdit ? 'save' : 'plus' ?>"></i> <?= $isEdit ? 'Enregistrer' : 'Créer la cohorte' ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php renderFooter(); ?>
