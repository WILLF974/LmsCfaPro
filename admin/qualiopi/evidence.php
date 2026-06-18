<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();
$criteriaId  = (int)($_GET['criteria_id'] ?? 0);
$formationId = (int)($_GET['formation_id'] ?? 0);
$errors = [];

// Charger le critère
$criteria = null;
if ($criteriaId) {
    $stmt = $pdo->prepare('SELECT * FROM qualiopi_criteria WHERE id = ?');
    $stmt->execute([$criteriaId]);
    $criteria = $stmt->fetch();
}

// Formation si filtrée
$formation = null;
if ($formationId) {
    $stmt = $pdo->prepare('SELECT id, title FROM formations WHERE id = ?');
    $stmt->execute([$formationId]);
    $formation = $stmt->fetch();
}

// Actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_evidence') {
        $cId   = (int)($_POST['criteria_id'] ?? 0);
        $fId   = (int)($_POST['formation_id'] ?? 0) ?: null;
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $url   = trim($_POST['url'] ?? '');

        $filePath = null;
        if (!empty($_FILES['evidence_file']['name'])) {
            $upload = uploadFile($_FILES['evidence_file'], 'qualiopi', array_merge(ALLOWED_DOC_TYPES, ALLOWED_IMAGE_TYPES));
            if ($upload['success']) $filePath = $upload['path'];
            else $errors[] = $upload['error'];
        }

        if (empty($title)) $errors[] = 'Le titre de la preuve est obligatoire.';

        if (empty($errors) && $cId) {
            $pdo->prepare('INSERT INTO qualiopi_evidences (criteria_id, formation_id, title, description, file_path, url, status, created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$cId, $fId, $title, $desc, $filePath, $url ?: null, 'submitted', $_SESSION['user_id']]);
            auditLog('qualiopi_evidence_added', 'qualiopi_evidence', (int)$pdo->lastInsertId());
            setFlash('success', 'Preuve ajoutée.');
            $redir = url('admin/qualiopi/evidence.php?criteria_id=' . $cId . ($fId ? '&formation_id=' . $fId : ''));
            redirect($redir);
        }
    }

    if ($action === 'validate_evidence') {
        $eid = (int)($_POST['evidence_id'] ?? 0);
        $pdo->prepare('UPDATE qualiopi_evidences SET status="validated", validated_by=?, validated_at=NOW() WHERE id=?')->execute([$_SESSION['user_id'], $eid]);
        setFlash('success', 'Preuve validée.');
        redirect($_SERVER['HTTP_REFERER'] ?? url('admin/qualiopi/index.php'));
    }

    if ($action === 'delete_evidence') {
        $eid = (int)($_POST['evidence_id'] ?? 0);
        $pdo->prepare('DELETE FROM qualiopi_evidences WHERE id=?')->execute([$eid]);
        setFlash('success', 'Preuve supprimée.');
        redirect($_SERVER['HTTP_REFERER'] ?? url('admin/qualiopi/index.php'));
    }
}

// Charger tous les critères groupés
$allCriteria = $pdo->query('SELECT * FROM qualiopi_criteria ORDER BY criterion_number, indicator_number')->fetchAll();
$criteriaGroups = [];
foreach ($allCriteria as $c) {
    $criteriaGroups[$c['criterion_number']][] = $c;
}

// Charger preuves selon filtre
$where = '1=1';
$params = [];
if ($criteriaId) { $where .= ' AND qe.criteria_id = ?'; $params[] = $criteriaId; }
if ($formationId) { $where .= ' AND (qe.formation_id = ? OR qe.formation_id IS NULL)'; $params[] = $formationId; }

$stmt = $pdo->prepare("SELECT qe.*, qc.criterion_number, qc.indicator_number, qc.title as criteria_title, f.title as formation_title, u.first_name, u.last_name FROM qualiopi_evidences qe JOIN qualiopi_criteria qc ON qe.criteria_id = qc.id LEFT JOIN formations f ON qe.formation_id = f.id LEFT JOIN users u ON qe.created_by = u.id WHERE $where ORDER BY qc.criterion_number, qc.indicator_number, qe.created_at DESC");
$stmt->execute($params);
$evidences = $stmt->fetchAll();

// Formations pour le select
$formations = $pdo->query("SELECT id, title FROM formations WHERE status IN ('active','draft') ORDER BY title")->fetchAll();

$pageTitle = $criteria ? ('Preuves — Indicateur ' . $criteria['indicator_number']) : 'Gestion des preuves Qualiopi';
renderHead($pageTitle);
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar($pageTitle, [['Qualiopi', url('admin/qualiopi/index.php')], ['Preuves', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>
  <?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= e($err) ?></div>
  <?php endforeach; ?>

  <?php if ($criteria): ?>
  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--success)">
    <div class="card-body">
      <div style="display:flex;align-items:flex-start;gap:16px">
        <div style="width:48px;height:48px;border-radius:50%;background:rgba(16,185,129,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <span style="font-size:16px;font-weight:800;color:var(--success)"><?= e($criteria['indicator_number']) ?></span>
        </div>
        <div>
          <h3 style="font-size:15px;font-weight:700;margin-bottom:4px"><?= e($criteria['title']) ?></h3>
          <p style="font-size:13px;color:var(--text-muted);margin-bottom:8px"><?= e($criteria['description']) ?></p>
          <?php if ($criteria['evidence_required']): ?>
          <div style="font-size:12px;background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);padding:8px 12px;border-radius:var(--radius);color:var(--text-muted)">
            <strong style="color:var(--primary-light)">Preuves attendues :</strong> <?= e($criteria['evidence_required']) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 320px;gap:24px;align-items:start">
    <!-- Liste des preuves -->
    <div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <h2 style="font-size:16px;font-weight:700">Preuves (<?= count($evidences) ?>)</h2>
        <div style="display:flex;gap:8px">
          <a href="<?= url('admin/qualiopi/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
          <button class="btn btn-primary btn-sm" onclick="document.getElementById('modal-evidence').style.display='flex'">
            <i class="fas fa-plus"></i> Ajouter une preuve
          </button>
        </div>
      </div>

      <?php if (empty($evidences)): ?>
      <div class="empty-state">
        <div class="icon">📋</div>
        <h3>Aucune preuve</h3>
        <p>Ajoutez des preuves de conformité pour cet indicateur.</p>
        <button class="btn btn-primary" onclick="document.getElementById('modal-evidence').style.display='flex'">Ajouter une preuve</button>
      </div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($evidences as $ev): ?>
        <div class="card">
          <div class="card-body">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
              <div style="flex:1">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                  <?php if (!$criteria): ?>
                  <span class="badge badge-secondary" style="font-size:10px">Indicateur <?= e($ev['indicator_number']) ?></span>
                  <?php endif; ?>
                  <?php
                  $statusColors = ['draft'=>'badge-secondary','submitted'=>'badge-warning','validated'=>'badge-success'];
                  $statusLabels = ['draft'=>'Brouillon','submitted'=>'Soumis','validated'=>'Validé'];
                  echo '<span class="badge ' . ($statusColors[$ev['status']] ?? 'badge-secondary') . '">' . ($statusLabels[$ev['status']] ?? $ev['status']) . '</span>';
                  ?>
                  <?php if ($ev['formation_title']): ?><span class="badge badge-info" style="font-size:10px"><?= e($ev['formation_title']) ?></span><?php endif; ?>
                </div>
                <h4 style="font-size:14px;font-weight:700;margin-bottom:4px"><?= e($ev['title']) ?></h4>
                <?php if ($ev['description']): ?><p style="font-size:12px;color:var(--text-muted);margin-bottom:8px"><?= e($ev['description']) ?></p><?php endif; ?>
                <div style="display:flex;gap:12px;font-size:12px;color:var(--text-faint)">
                  <span><i class="fas fa-user"></i> <?= e($ev['first_name'] . ' ' . $ev['last_name']) ?></span>
                  <span><i class="fas fa-clock"></i> <?= formatDate($ev['created_at']) ?></span>
                </div>
              </div>
              <div style="display:flex;gap:6px;flex-shrink:0">
                <?php if ($ev['file_path']): ?>
                <a href="<?= e(uploadUrl($ev['file_path'])) ?>" target="_blank" class="btn btn-secondary btn-sm" title="Télécharger"><i class="fas fa-download"></i></a>
                <?php endif; ?>
                <?php if ($ev['url']): ?>
                <a href="<?= e($ev['url']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="Ouvrir le lien"><i class="fas fa-external-link-alt"></i></a>
                <?php endif; ?>
                <?php if ($ev['status'] !== 'validated' && isAdmin()): ?>
                <form method="POST" style="margin:0">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="validate_evidence">
                  <input type="hidden" name="evidence_id" value="<?= $ev['id'] ?>">
                  <button type="submit" class="btn btn-success btn-sm" title="Valider"><i class="fas fa-check"></i></button>
                </form>
                <?php endif; ?>
                <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer cette preuve ?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_evidence">
                  <input type="hidden" name="evidence_id" value="<?= $ev['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Filtres critères -->
    <div>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-filter"></i> Filtrer par critère</h3></div>
        <div class="card-body" style="padding:0">
          <?php foreach ($criteriaGroups as $num => $cList): ?>
          <div>
            <div style="padding:8px 16px;background:var(--bg-elevated);font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Critère <?= $num ?></div>
            <?php foreach ($cList as $c): ?>
            <a href="<?= url('admin/qualiopi/evidence.php?criteria_id=' . $c['id'] . ($formationId ? '&formation_id=' . $formationId : '')) ?>" style="display:block;padding:8px 16px;font-size:12px;border-bottom:1px solid var(--border);text-decoration:none;color:<?= $criteriaId == $c['id'] ? 'var(--primary-light)' : 'var(--text-secondary)' ?>;background:<?= $criteriaId == $c['id'] ? 'rgba(99,102,241,.1)' : '' ?>">
              <span style="font-weight:600"><?= e($c['indicator_number']) ?></span> — <?= e(mb_substr($c['title'], 0, 50)) ?>...
            </a>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
          <div style="padding:12px 16px">
            <a href="<?= url('admin/qualiopi/evidence.php') ?>" class="btn btn-ghost btn-sm w-full" style="justify-content:center">Voir tout</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal : Ajouter une preuve -->
<div id="modal-evidence" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:520px;max-width:95vw;max-height:90vh;overflow-y:auto">
    <div class="card-header">
      <h3 class="card-title">Ajouter une preuve</h3>
      <button onclick="document.getElementById('modal-evidence').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_evidence">
        <div class="form-group">
          <label class="form-label">Indicateur concerné <span class="required">*</span></label>
          <select name="criteria_id" class="form-control" required>
            <option value="">— Sélectionner —</option>
            <?php foreach ($allCriteria as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $criteriaId == $c['id'] ? 'selected' : '' ?>><?= e($c['indicator_number']) ?> — <?= e(mb_substr($c['title'],0,60)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Formation associée (optionnel)</label>
          <select name="formation_id" class="form-control">
            <option value="">— Globale (non liée à une formation) —</option>
            <?php foreach ($formations as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $formationId == $f['id'] ? 'selected' : '' ?>><?= e($f['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Titre de la preuve <span class="required">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="Ex : Rapport de satisfaction post-formation" required>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3" placeholder="Détails sur cette preuve..."></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Fichier justificatif</label>
          <input type="file" name="evidence_file" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Lien URL (optionnel)</label>
          <input type="url" name="url" class="form-control" placeholder="https://...">
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-evidence').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('modal-evidence').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>
<?php renderFooter(); ?>
