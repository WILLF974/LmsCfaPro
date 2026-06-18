<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();
$errors = [];
$formData = [];
$editMode = isset($_GET['id']);
$titleData = null;

if ($editMode) {
    $stmt = $pdo->prepare('SELECT * FROM rncp_titles WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $titleData = $stmt->fetch();
    if (!$titleData) { setFlash('error','Titre introuvable.'); redirect(url('admin/rncp/index.php')); }
    $formData = $titleData;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $formData = [
        'rncp_code'       => trim($_POST['rncp_code'] ?? ''),
        'title'           => trim($_POST['title'] ?? ''),
        'acronym'         => trim($_POST['acronym'] ?? ''),
        'level'           => (int)($_POST['level'] ?? 0),
        'sector'          => trim($_POST['sector'] ?? ''),
        'nsc_code'        => trim($_POST['nsc_code'] ?? ''),
        'certifier'       => trim($_POST['certifier'] ?? ''),
        'description'     => trim($_POST['description'] ?? ''),
        'objectives'      => trim($_POST['objectives'] ?? ''),
        'registration_date' => $_POST['registration_date'] ?: null,
        'expiry_date'     => $_POST['expiry_date'] ?: null,
        'duration_hours'  => (int)($_POST['duration_hours'] ?? 0) ?: null,
        'duration_months' => (int)($_POST['duration_months'] ?? 0) ?: null,
        'status'          => $_POST['status'] ?? 'active',
    ];

    if (empty($formData['rncp_code'])) $errors[] = 'Le code RNCP est obligatoire.';
    if (empty($formData['title']))     $errors[] = 'L\'intitulé est obligatoire.';
    if ($formData['level'] < 1 || $formData['level'] > 8) $errors[] = 'Le niveau doit être entre 1 et 8.';

    // Handle doc upload
    $docPath = $titleData['document_path'] ?? null;
    if (!empty($_FILES['document']['name'])) {
        $upload = uploadFile($_FILES['document'], 'documents', ['application/pdf'], 10*1024*1024);
        if ($upload['success']) $docPath = $upload['path'];
        else $errors[] = 'Document : ' . $upload['error'];
    }

    if (empty($errors)) {
        $formData['document_path'] = $docPath;
        if ($editMode) {
            $stmt = $pdo->prepare("UPDATE rncp_titles SET rncp_code=?,title=?,acronym=?,level=?,sector=?,nsc_code=?,certifier=?,description=?,objectives=?,registration_date=?,expiry_date=?,duration_hours=?,duration_months=?,status=?,document_path=? WHERE id=?");
            $stmt->execute([...array_values(array_intersect_key($formData, array_flip(['rncp_code','title','acronym','level','sector','nsc_code','certifier','description','objectives','registration_date','expiry_date','duration_hours','duration_months','status','document_path']))), $titleData['id']]);
            setFlash('success','Titre RNCP mis à jour.');
        } else {
            $sql = "INSERT INTO rncp_titles (rncp_code,title,acronym,level,sector,nsc_code,certifier,description,objectives,registration_date,expiry_date,duration_hours,duration_months,status,document_path,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql)->execute([
                $formData['rncp_code'], $formData['title'], $formData['acronym'], $formData['level'],
                $formData['sector'], $formData['nsc_code'], $formData['certifier'], $formData['description'],
                $formData['objectives'], $formData['registration_date'] ?: null, $formData['expiry_date'] ?: null,
                $formData['duration_hours'], $formData['duration_months'], $formData['status'],
                $docPath, $_SESSION['user_id']
            ]);
            $newId = (int)$pdo->lastInsertId();
            auditLog('rncp_created', 'rncp_title', $newId);
            setFlash('success', 'Titre RNCP créé. Ajoutez maintenant les blocs de compétences.');
            redirect(url('admin/rncp/view.php?id=' . $newId));
        }
        redirect(url('admin/rncp/index.php'));
    }
}

$pageTitle = $editMode ? 'Modifier le titre RNCP' : 'Ajouter un titre RNCP';
renderHead($pageTitle);
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar($pageTitle, [['RNCP', url('admin/rncp/index.php')], [$pageTitle, '']]);
?>
<div class="page-content fade-in">
  <?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div style="display:grid;grid-template-columns:1fr 320px;gap:24px;align-items:start">

      <!-- Main form -->
      <div style="display:flex;flex-direction:column;gap:20px">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Identification du titre</h3></div>
          <div class="card-body">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Code RNCP <span class="required">*</span></label>
                <div class="input-group">
                  <i class="fas fa-hashtag input-icon"></i>
                  <input type="text" name="rncp_code" class="form-control" placeholder="RNCP35634" value="<?= e($formData['rncp_code'] ?? '') ?>" required>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Acronyme / Sigle</label>
                <input type="text" name="acronym" class="form-control" placeholder="BTS SIO" value="<?= e($formData['acronym'] ?? '') ?>">
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Intitulé complet <span class="required">*</span></label>
              <input type="text" name="title" class="form-control" placeholder="Titre Professionnel..." value="<?= e($formData['title'] ?? '') ?>" required>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Niveau EQF <span class="required">*</span></label>
                <select name="level" class="form-control" required>
                  <?php for ($i=1;$i<=8;$i++): ?>
                  <option value="<?= $i ?>" <?= ($formData['level']??0)==$i?'selected':'' ?>>Niveau <?= $i ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Code NSF</label>
                <input type="text" name="nsc_code" class="form-control" placeholder="326" value="<?= e($formData['nsc_code'] ?? '') ?>">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Secteur d'activité</label>
                <input type="text" name="sector" class="form-control" placeholder="Informatique, Commerce..." value="<?= e($formData['sector'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Certificateur</label>
                <input type="text" name="certifier" class="form-control" placeholder="Ministère du Travail..." value="<?= e($formData['certifier'] ?? '') ?>">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Date d'enregistrement</label>
                <input type="date" name="registration_date" class="form-control" value="<?= e($formData['registration_date'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Date d'expiration</label>
                <input type="date" name="expiry_date" class="form-control" value="<?= e($formData['expiry_date'] ?? '') ?>">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Durée (heures)</label>
                <input type="number" name="duration_hours" class="form-control" placeholder="1200" value="<?= e($formData['duration_hours'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Durée (mois)</label>
                <input type="number" name="duration_months" class="form-control" placeholder="12" value="<?= e($formData['duration_months'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h3 class="card-title">Description & Objectifs</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Description du titre</label>
              <textarea name="description" class="form-control" rows="4" placeholder="Présentation générale du titre professionnel..."><?= e($formData['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Objectifs de certification</label>
              <textarea name="objectives" class="form-control" rows="4" placeholder="Objectifs visés par la certification..."><?= e($formData['objectives'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div style="display:flex;flex-direction:column;gap:16px">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Publication</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Statut</label>
              <select name="status" class="form-control">
                <option value="active"   <?= ($formData['status']??'active')==='active'?'selected':'' ?>>Actif</option>
                <option value="inactive" <?= ($formData['status']??'')==='inactive'?'selected':'' ?>>Inactif</option>
                <option value="archived" <?= ($formData['status']??'')==='archived'?'selected':'' ?>>Archivé</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center">
              <i class="fas fa-save"></i> <?= $editMode ? 'Enregistrer' : 'Créer le titre' ?>
            </button>
            <a href="<?= url('admin/rncp/index.php') ?>" class="btn btn-ghost w-full" style="justify-content:center;margin-top:8px">Annuler</a>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h3 class="card-title">Document officiel</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Référentiel PDF</label>
              <label class="drop-zone" style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:20px;border:2px dashed var(--border);border-radius:var(--radius);cursor:pointer;transition:.2s">
                <i class="fas fa-file-pdf" style="font-size:28px;color:var(--danger)"></i>
                <span class="drop-zone-label text-sm text-muted">Cliquer ou glisser un PDF</span>
                <input type="file" name="document" accept=".pdf" style="display:none">
              </label>
              <?php if (!empty($formData['document_path'])): ?>
              <div style="margin-top:8px;font-size:12px;color:var(--success)"><i class="fas fa-check"></i> Document existant</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($editMode): ?>
        <div class="card">
          <div class="card-header"><h3 class="card-title">Structure</h3></div>
          <div class="card-body">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px">Gérez les blocs de compétences et les compétences associées.</p>
            <a href="<?= url('admin/rncp/view.php?id='.$titleData['id']) ?>" class="btn btn-primary w-full" style="justify-content:center">
              <i class="fas fa-sitemap"></i> Gérer la structure
            </a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>
<?php renderFooter(); ?>
