<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();
$errors = [];
$formData = [];
$editMode = isset($_GET['id']);
$formation = null;

if ($editMode) {
    $stmt = $pdo->prepare('SELECT * FROM formations WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $formation = $stmt->fetch();
    if (!$formation) { setFlash('error', 'Formation introuvable.'); redirect(url('admin/formations/index.php')); }
    $formData = $formation;
}

// Données nécessaires pour les selects
$rncpTitles = $pdo->query("SELECT id, rncp_code, title, acronym, level FROM rncp_titles WHERE status = 'active' ORDER BY rncp_code")->fetchAll();
$teachers   = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE role IN ('teacher','pedagogy','admin') AND status = 'active' ORDER BY last_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $formData = [
        'rncp_title_id'         => (int)($_POST['rncp_title_id'] ?? 0),
        'title'                 => trim($_POST['title'] ?? ''),
        'description'           => trim($_POST['description'] ?? ''),
        'objectives'            => trim($_POST['objectives'] ?? ''),
        'prerequisites'         => trim($_POST['prerequisites'] ?? ''),
        'target_audience'       => trim($_POST['target_audience'] ?? ''),
        'pedagogical_methods'   => trim($_POST['pedagogical_methods'] ?? ''),
        'evaluation_methods'    => trim($_POST['evaluation_methods'] ?? ''),
        'duration_hours'        => (int)($_POST['duration_hours'] ?? 0) ?: null,
        'duration_months'       => (int)($_POST['duration_months'] ?? 0) ?: null,
        'max_students'          => (int)($_POST['max_students'] ?? 0) ?: null,
        'start_date'            => $_POST['start_date'] ?: null,
        'end_date'              => $_POST['end_date'] ?: null,
        'price'                 => is_numeric($_POST['price'] ?? '') ? (float)$_POST['price'] : null,
        'status'                => $_POST['status'] ?? 'draft',
        'access_type'           => $_POST['access_type'] ?? 'approval',
        'qualiopi_certified'    => isset($_POST['qualiopi_certified']) ? 1 : 0,
    ];

    if (empty($formData['rncp_title_id'])) $errors[] = 'Le titre RNCP est obligatoire.';
    if (empty($formData['title']))          $errors[] = 'L\'intitulé de la formation est obligatoire.';

    // Thumbnail
    $thumbnail = $formation['thumbnail'] ?? null;
    if (!empty($_FILES['thumbnail']['name'])) {
        $upload = uploadFile($_FILES['thumbnail'], 'thumbnails', ALLOWED_IMAGE_TYPES, 5 * 1024 * 1024);
        if ($upload['success']) $thumbnail = $upload['path'];
        else $errors[] = 'Miniature : ' . $upload['error'];
    }

    if (empty($errors)) {
        $formData['slug'] = slugify($formData['title']) . '-' . time();
        $formData['thumbnail'] = $thumbnail;

        if ($editMode) {
            $stmt = $pdo->prepare("UPDATE formations SET rncp_title_id=?,title=?,slug=?,description=?,objectives=?,prerequisites=?,target_audience=?,pedagogical_methods=?,evaluation_methods=?,duration_hours=?,duration_months=?,max_students=?,start_date=?,end_date=?,price=?,status=?,access_type=?,qualiopi_certified=?,thumbnail=?,updated_at=NOW() WHERE id=?");
            $stmt->execute([$formData['rncp_title_id'],$formData['title'],$formData['slug'],$formData['description'],$formData['objectives'],$formData['prerequisites'],$formData['target_audience'],$formData['pedagogical_methods'],$formData['evaluation_methods'],$formData['duration_hours'],$formData['duration_months'],$formData['max_students'],$formData['start_date'],$formData['end_date'],$formData['price'],$formData['status'],$formData['access_type'],$formData['qualiopi_certified'],$formData['thumbnail'],$formation['id']]);
            auditLog('formation_updated', 'formation', $formation['id']);
            setFlash('success', 'Formation mise à jour avec succès.');
            redirect(url('admin/formations/view.php?id=' . $formation['id']));
        } else {
            $stmt = $pdo->prepare("INSERT INTO formations (rncp_title_id,title,slug,description,objectives,prerequisites,target_audience,pedagogical_methods,evaluation_methods,duration_hours,duration_months,max_students,start_date,end_date,price,status,access_type,qualiopi_certified,thumbnail,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$formData['rncp_title_id'],$formData['title'],$formData['slug'],$formData['description'],$formData['objectives'],$formData['prerequisites'],$formData['target_audience'],$formData['pedagogical_methods'],$formData['evaluation_methods'],$formData['duration_hours'],$formData['duration_months'],$formData['max_students'],$formData['start_date'],$formData['end_date'],$formData['price'],$formData['status'],$formData['access_type'],$formData['qualiopi_certified'],$formData['thumbnail'],$_SESSION['user_id']]);
            $newId = (int)$pdo->lastInsertId();
            auditLog('formation_created', 'formation', $newId);
            setFlash('success', 'Formation créée ! Ajoutez maintenant des modules et des capsules.');
            redirect(url('admin/formations/view.php?id=' . $newId));
        }
    }
}

$pageTitle = $editMode ? 'Modifier la formation' : 'Créer une formation';
renderHead($pageTitle);
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar($pageTitle, [['Formations', url('admin/formations/index.php')], [$pageTitle, '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>
  <?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div style="display:grid;grid-template-columns:1fr 320px;gap:24px;align-items:start">

      <!-- Colonne principale -->
      <div style="display:flex;flex-direction:column;gap:20px">

        <!-- Identification -->
        <div class="card">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-graduation-cap"></i> Identification</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Titre RNCP associé <span class="required">*</span></label>
              <select name="rncp_title_id" class="form-control" required>
                <option value="">— Sélectionner un titre RNCP —</option>
                <?php foreach ($rncpTitles as $r): ?>
                <option value="<?= $r['id'] ?>" <?= ($formData['rncp_title_id'] ?? 0) == $r['id'] ? 'selected' : '' ?>>
                  <?= e($r['rncp_code']) ?> — <?= e($r['title']) ?> (Niv. <?= $r['level'] ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Intitulé de la formation <span class="required">*</span></label>
              <input type="text" name="title" class="form-control" placeholder="Ex : Titre Professionnel Développeur Web" value="<?= e($formData['title'] ?? '') ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Description générale</label>
              <textarea name="description" class="form-control" rows="4" placeholder="Présentation de la formation..."><?= e($formData['description'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <!-- Informations pédagogiques (critère Qualiopi 1.1) -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-shield-alt" style="color:var(--success)"></i> Informations pédagogiques <span style="font-size:11px;color:var(--success);font-weight:400">Qualiopi</span></h3>
          </div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Objectifs de la formation</label>
              <textarea name="objectives" class="form-control" rows="4" placeholder="À l'issue de cette formation, le stagiaire sera capable de..."><?= e($formData['objectives'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Prérequis</label>
              <textarea name="prerequisites" class="form-control" rows="3" placeholder="Connaissances ou compétences requises avant la formation..."><?= e($formData['prerequisites'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Public cible</label>
              <textarea name="target_audience" class="form-control" rows="3" placeholder="Cette formation s'adresse à..."><?= e($formData['target_audience'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Méthodes pédagogiques</label>
              <textarea name="pedagogical_methods" class="form-control" rows="3" placeholder="Cours magistraux, travaux pratiques, projets..."><?= e($formData['pedagogical_methods'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Méthodes d'évaluation</label>
              <textarea name="evaluation_methods" class="form-control" rows="3" placeholder="Quiz, projets, examens blancs..."><?= e($formData['evaluation_methods'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <!-- Durée et planning -->
        <div class="card">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-calendar-alt"></i> Durée et planning</h3></div>
          <div class="card-body">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Durée totale (heures)</label>
                <input type="number" name="duration_hours" class="form-control" placeholder="420" min="1" value="<?= e($formData['duration_hours'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Durée (mois)</label>
                <input type="number" name="duration_months" class="form-control" placeholder="12" min="1" value="<?= e($formData['duration_months'] ?? '') ?>">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Date de début</label>
                <input type="date" name="start_date" class="form-control" value="<?= e($formData['start_date'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Date de fin</label>
                <input type="date" name="end_date" class="form-control" value="<?= e($formData['end_date'] ?? '') ?>">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Effectif max</label>
                <input type="number" name="max_students" class="form-control" placeholder="20" min="1" value="<?= e($formData['max_students'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Tarif (€)</label>
                <input type="number" name="price" class="form-control" placeholder="3500" step="0.01" min="0" value="<?= e($formData['price'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Colonne latérale -->
      <div style="display:flex;flex-direction:column;gap:16px">

        <!-- Publication -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Publication</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Statut</label>
              <select name="status" class="form-control">
                <option value="draft"    <?= ($formData['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Brouillon</option>
                <option value="active"   <?= ($formData['status'] ?? '') === 'active' ? 'selected' : '' ?>>Actif</option>
                <option value="archived" <?= ($formData['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archivé</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Accès</label>
              <select name="access_type" class="form-control">
                <option value="approval"   <?= ($formData['access_type'] ?? 'approval') === 'approval' ? 'selected' : '' ?>>Sur validation</option>
                <option value="invitation" <?= ($formData['access_type'] ?? '') === 'invitation' ? 'selected' : '' ?>>Sur invitation</option>
                <option value="open"       <?= ($formData['access_type'] ?? '') === 'open' ? 'selected' : '' ?>>Ouvert</option>
              </select>
            </div>
            <div class="form-group">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="qualiopi_certified" value="1" <?= ($formData['qualiopi_certified'] ?? 0) ? 'checked' : '' ?>>
                <span class="form-label" style="margin:0"><i class="fas fa-shield-alt" style="color:var(--success)"></i> Certification Qualiopi</span>
              </label>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;margin-top:4px">
              <i class="fas fa-save"></i> <?= $editMode ? 'Enregistrer' : 'Créer la formation' ?>
            </button>
            <a href="<?= url('admin/formations/index.php') ?>" class="btn btn-ghost w-full" style="justify-content:center;margin-top:8px">Annuler</a>
          </div>
        </div>

        <!-- Miniature -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Miniature</h3></div>
          <div class="card-body">
            <?php if (!empty($formData['thumbnail']) && file_exists(UPLOADS_PATH . '/' . $formData['thumbnail'])): ?>
            <img src="<?= e(uploadUrl($formData['thumbnail'])) ?>" style="width:100%;border-radius:var(--radius);margin-bottom:10px;object-fit:cover;height:120px">
            <?php endif; ?>
            <label class="drop-zone" style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:20px;border:2px dashed var(--border);border-radius:var(--radius);cursor:pointer;transition:.2s">
              <i class="fas fa-image" style="font-size:28px;color:var(--primary-light)"></i>
              <span style="font-size:12px;color:var(--text-muted)">Cliquer ou glisser une image</span>
              <input type="file" name="thumbnail" accept="image/*" style="display:none" onchange="previewImage(this)">
            </label>
            <img id="img-preview" style="display:none;width:100%;border-radius:var(--radius);margin-top:10px;object-fit:cover;height:120px">
          </div>
        </div>

        <?php if ($editMode): ?>
        <div class="card">
          <div class="card-header"><h3 class="card-title">Structure</h3></div>
          <div class="card-body">
            <a href="<?= url('admin/formations/view.php?id=' . $formation['id']) ?>" class="btn btn-primary w-full" style="justify-content:center">
              <i class="fas fa-sitemap"></i> Gérer modules & capsules
            </a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>

<script>
function previewImage(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      const preview = document.getElementById('img-preview');
      preview.src = e.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
</script>
<?php renderFooter(); ?>
