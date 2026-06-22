<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$editId = (int)($_GET['id'] ?? 0);
$isEdit = $editId > 0;
$errors = [];

// ── Migration schéma (idem index.php — s'exécute quelle que soit la page d'entrée) ──
$pdo->exec("CREATE TABLE IF NOT EXISTS case_study_resources (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    case_study_id  INT NOT NULL,
    title          VARCHAR(255) NOT NULL,
    type           VARCHAR(30) NOT NULL DEFAULT 'other',
    file_path      TEXT DEFAULT NULL,
    url            VARCHAR(500) DEFAULT NULL,
    file_size      INT DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cs (case_study_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

foreach ([
    "ALTER TABLE case_studies ADD COLUMN activity_type_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN competency_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN module_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN lesson_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN duration_minutes INT NULL",
    "ALTER TABLE case_studies ADD COLUMN xp_reward SMALLINT NOT NULL DEFAULT 0",
] as $_mig) { try { $pdo->exec($_mig); } catch (PDOException $e) {} }

// Charger formations pour le menu déroulant
$formations = $pdo->query("SELECT id, title FROM formations WHERE status='active' ORDER BY title")->fetchAll();

// Mode édition — charger l'étude existante
$csDefaults = [
    'id'=>0,'title'=>'','description'=>'','file_type'=>'pdf','file_path'=>null,
    'content_url'=>'','formation_id'=>null,'activity_type_id'=>null,
    'competency_id'=>null,'module_id'=>null,'lesson_id'=>null,
    'duration_minutes'=>null,'xp_reward'=>0,
];
$cs = $csDefaults;
if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM case_studies WHERE id = ?');
    $stmt->execute([$editId]);
    $loaded = $stmt->fetch();
    if (!$loaded || (!isAdmin() && !isPedagogy() && $loaded['created_by'] != $userId)) {
        setFlash('error', 'Accès refusé ou étude introuvable.');
        redirect(url('teacher/case_studies/index.php'));
    }
    $cs = array_merge($csDefaults, $loaded); // garantit toutes les clés même si colonnes absentes en DB
}

// ── POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $postEditId = (int)($_POST['edit_id'] ?? 0);

    $data = [
        'title'            => trim($_POST['title'] ?? ''),
        'description'      => trim($_POST['description'] ?? ''),
        'file_type'        => $_POST['file_type'] ?? 'pdf',
        'content_url'      => trim($_POST['content_url'] ?? ''),
        'formation_id'     => (int)($_POST['formation_id'] ?? 0) ?: null,
        'activity_type_id' => (int)($_POST['activity_type_id'] ?? 0) ?: null,
        'competency_id'    => (int)($_POST['competency_id'] ?? 0) ?: null,
        'module_id'        => (int)($_POST['module_id'] ?? 0) ?: null,
        'lesson_id'        => (int)($_POST['lesson_id'] ?? 0) ?: null,
        'duration_minutes' => (int)($_POST['duration_minutes'] ?? 0) ?: null,
        'xp_reward'        => max(0, (int)($_POST['xp_reward'] ?? 0)),
    ];

    if (!$data['title'])     $errors[] = 'Le titre est requis.';
    if (!$data['file_type']) $errors[] = 'Le type de fichier est requis.';

    // Upload
    $filePath = $postEditId ? ($pdo->prepare('SELECT file_path FROM case_studies WHERE id=?')->execute([$postEditId]) ? null : null) : null;
    if ($postEditId) {
        $old = $pdo->prepare('SELECT file_path FROM case_studies WHERE id=?');
        $old->execute([$postEditId]);
        $filePath = $old->fetchColumn() ?: null;
    }

    if ($data['file_type'] === 'pdf') {
        // ── Multi-PDF (identique aux capsules) ─────────────────
        $pdfEntries = [];

        // 1. PDFs existants conservés (dans leur ordre)
        foreach (($_POST['existing_pdf_paths'] ?? []) as $idx => $path) {
            $path = trim($path);
            if (!$path) continue;
            $name = trim($_POST['existing_pdf_names'][$idx] ?? '') ?: pathinfo($path, PATHINFO_FILENAME);
            $pdfEntries[] = ['path' => $path, 'name' => $name];
        }

        // 2. Nouveaux PDFs uploadés
        foreach (($_FILES['pdf_files']['name'] ?? []) as $i => $pdfName) {
            if (empty($pdfName) || $_FILES['pdf_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $pdfFile = [
                'name'     => $pdfName,
                'tmp_name' => $_FILES['pdf_files']['tmp_name'][$i],
                'error'    => $_FILES['pdf_files']['error'][$i],
                'size'     => $_FILES['pdf_files']['size'][$i],
                'type'     => $_FILES['pdf_files']['type'][$i],
            ];
            $upload = uploadFile($pdfFile, 'case_studies', ['application/pdf'], MAX_UPLOAD_SIZE);
            if ($upload['success']) {
                $customName = trim($_POST['pdf_names'][$i] ?? '') ?: pathinfo($pdfName, PATHINFO_FILENAME);
                $pdfEntries[] = ['path' => $upload['path'], 'name' => $customName];
            } else {
                $errors[] = 'PDF n°' . ($i + 1) . ' : ' . $upload['error'];
            }
        }

        $filePath = !empty($pdfEntries) ? json_encode($pdfEntries, JSON_UNESCAPED_UNICODE) : null;

    } elseif (!empty($_FILES['cs_file']['name'])) {
        // ── Fichier unique (document, présentation, vidéo) ─────
        $allowed = $data['file_type'] === 'video'
            ? ['video/mp4','video/webm','video/ogg']
            : ALLOWED_DOC_TYPES;
        $upload = uploadFile($_FILES['cs_file'], 'case_studies', $allowed, MAX_UPLOAD_SIZE);
        if ($upload['success']) $filePath = $upload['path'];
        else $errors[] = 'Fichier : ' . $upload['error'];
    }

    if (empty($errors)) {
        $row = [
            $data['title'],
            $data['description'] ?: null,
            $data['file_type'],
            $filePath,
            $data['content_url'] ?: null,
            $data['formation_id'],
            $data['activity_type_id'],
            $data['competency_id'],
            $data['module_id'],
            $data['lesson_id'],
            $data['duration_minutes'],
            $data['xp_reward'],
        ];

        if ($postEditId) {
            $pdo->prepare("
                UPDATE case_studies SET title=?,description=?,file_type=?,file_path=?,
                content_url=?,formation_id=?,activity_type_id=?,competency_id=?,
                module_id=?,lesson_id=?,duration_minutes=?,xp_reward=?,updated_at=NOW() WHERE id=?
            ")->execute(array_merge($row, [$postEditId]));
            auditLog('case_study_updated', 'case_study', $postEditId);
            setFlash('success', 'Étude de cas modifiée.');
            $csId = $postEditId;
        } else {
            $row[] = $userId;
            $pdo->prepare("
                INSERT INTO case_studies
                  (title,description,file_type,file_path,content_url,formation_id,
                   activity_type_id,competency_id,module_id,lesson_id,
                   duration_minutes,xp_reward,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute($row);
            $csId = (int)$pdo->lastInsertId();
            auditLog('case_study_created', 'case_study', $csId);
            setFlash('success', 'Étude de cas importée avec succès.');
        }

        // ── Ressources complémentaires ──────────────────────────
        // 1. Supprimer les ressources retirées en édition
        if ($postEditId) {
            $keptIds = array_filter(array_map('intval', $_POST['existing_resource_ids'] ?? []));
            if (empty($keptIds)) {
                $pdo->prepare('DELETE FROM case_study_resources WHERE case_study_id = ?')->execute([$csId]);
            } else {
                $ph = implode(',', array_fill(0, count($keptIds), '?'));
                $pdo->prepare("DELETE FROM case_study_resources WHERE case_study_id = ? AND id NOT IN ($ph)")
                    ->execute(array_merge([$csId], $keptIds));
            }
        }

        // 2. Nouveaux fichiers
        foreach (($_FILES['new_res_files']['name'] ?? []) as $i => $rName) {
            if (empty($rName) || $_FILES['new_res_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $rFile = [
                'name'     => $rName,
                'tmp_name' => $_FILES['new_res_files']['tmp_name'][$i],
                'error'    => $_FILES['new_res_files']['error'][$i],
                'size'     => $_FILES['new_res_files']['size'][$i],
                'type'     => $_FILES['new_res_files']['type'][$i],
            ];
            $upload = uploadFile($rFile, 'case_studies/resources', ALLOWED_DOC_TYPES, MAX_UPLOAD_SIZE);
            if ($upload['success']) {
                $ext   = strtolower(pathinfo($rName, PATHINFO_EXTENSION));
                $rType = csResourceType($ext);
                $title = trim($_POST['new_res_titles'][$i] ?? '') ?: pathinfo($rName, PATHINFO_FILENAME);
                $pdo->prepare('INSERT INTO case_study_resources (case_study_id,title,type,file_path,file_size) VALUES (?,?,?,?,?)')
                    ->execute([$csId, $title, $rType, $upload['path'], (int)$rFile['size']]);
            }
        }

        // 3. Nouveaux liens web
        foreach (($_POST['new_res_urls'] ?? []) as $i => $linkUrl) {
            $linkUrl = trim($linkUrl);
            if (!$linkUrl) continue;
            $title = trim($_POST['new_res_link_titles'][$i] ?? '') ?: $linkUrl;
            $pdo->prepare('INSERT INTO case_study_resources (case_study_id,title,type,url) VALUES (?,?,?,?)')
                ->execute([$csId, mb_substr($title, 0, 255), 'link', $linkUrl]);
        }

        redirect(url('teacher/case_studies/index.php'));
    }
}

// PDFs existants en mode édition
$existingPdfs = null;
if ($isEdit && $cs['file_type'] === 'pdf' && $cs['file_path']) {
    $dec = json_decode($cs['file_path'], true);
    $existingPdfs = is_array($dec) ? $dec
        : [['path' => $cs['file_path'], 'name' => pathinfo($cs['file_path'], PATHINFO_FILENAME)]];
}

// Pré-chargement cascade (mode édition) — tout en try-catch pour éviter un crash si
// une table de référence (modules, activity_types…) n'existe pas encore sur ce serveur
$initModules       = [];
$initActivityTypes = [];
$initCompetencies  = [];
$initLessons       = [];
if ($cs['formation_id']) {
    try {
        $s = $pdo->prepare("SELECT id, title FROM modules WHERE formation_id = ? ORDER BY order_num, title");
        $s->execute([$cs['formation_id']]);
        $initModules = $s->fetchAll();
    } catch (PDOException $e) {}

    try {
        $s = $pdo->prepare("SELECT at.id, at.code, at.title FROM activity_types at INNER JOIN formations f ON f.rncp_title_id = at.rncp_title_id WHERE f.id = ? ORDER BY at.order_num, at.code");
        $s->execute([$cs['formation_id']]);
        $initActivityTypes = $s->fetchAll();
    } catch (PDOException $e) {}
}
if ($cs['activity_type_id']) {
    try {
        $s = $pdo->prepare("SELECT id, code, title FROM competencies WHERE activity_type_id = ? ORDER BY order_num, code");
        $s->execute([$cs['activity_type_id']]);
        $initCompetencies = $s->fetchAll();
    } catch (PDOException $e) {}
}
if ($cs['module_id']) {
    try {
        $s = $pdo->prepare("SELECT id, title FROM lessons WHERE module_id = ? ORDER BY order_num, title");
        $s->execute([$cs['module_id']]);
        $initLessons = $s->fetchAll();
    } catch (PDOException $e) {}
}

// Ressources complémentaires existantes (mode édition)
$existingResources = [];
if ($isEdit) {
    try {
        $s = $pdo->prepare('SELECT * FROM case_study_resources WHERE case_study_id = ? ORDER BY id');
        $s->execute([$editId]);
        $existingResources = $s->fetchAll();
    } catch (PDOException $e) {}
}

function csResourceType(string $ext): string {
    return match(true) {
        $ext === 'pdf'                               => 'pdf',
        in_array($ext, ['doc','docx'])               => 'word',
        in_array($ext, ['xls','xlsx'])               => 'excel',
        in_array($ext, ['ppt','pptx'])               => 'powerpoint',
        in_array($ext, ['mp4','webm','avi','mov'])   => 'video',
        in_array($ext, ['jpg','jpeg','png','gif','webp','svg']) => 'image',
        default                                      => 'other',
    };
}

function renderCascadeSelect(string $name, string $id, array $items, ?int $selected, string $label, string $placeholder, ?callable $labelFn = null): void {
    $hasItems = !empty($items);
    echo '<div id="grp-' . htmlspecialchars($id) . '">';
    echo '<label class="form-label" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">' . htmlspecialchars($label) . '</label>';
    echo '<select name="' . htmlspecialchars($name) . '" id="sel-' . htmlspecialchars($id) . '" class="form-control" style="font-size:12px"';
    if ($id === 'module') echo ' onchange="onModuleChange(this.value)"';
    if ($id === 'at')     echo ' onchange="onATChange(this.value)"';
    echo '>';
    if (!$hasItems) {
        echo '<option value="" disabled selected style="color:var(--text-faint)">' . htmlspecialchars($placeholder) . '</option>';
    } else {
        echo '<option value="">— Aucun(e) —</option>';
        foreach ($items as $item) {
            $optLabel = $labelFn ? $labelFn($item) : mb_substr($item['title'], 0, 38);
            $sel = ($item['id'] == $selected) ? ' selected' : '';
            echo '<option value="' . (int)$item['id'] . '"' . $sel . '>' . htmlspecialchars($optLabel) . '</option>';
        }
    }
    echo '</select></div>';
}

$contentTypes = [
    'pdf'          => ['label'=>'PDF',           'icon'=>'file-pdf',       'color'=>'#ef4444'],
    'document'     => ['label'=>'Document',      'icon'=>'file-word',      'color'=>'#3b82f6'],
    'presentation' => ['label'=>'Présentation',  'icon'=>'file-powerpoint','color'=>'#f97316'],
    'video'        => ['label'=>'Vidéo',         'icon'=>'play-circle',    'color'=>'#ef4444'],
    'link'         => ['label'=>'Lien externe',  'icon'=>'link',           'color'=>'#0ea5e9'],
];

$pageTitle = $isEdit ? 'Modifier l\'étude de cas' : 'Importer une étude de cas';
renderHead($pageTitle);
renderSidebar('teacher');
renderTopbar($pageTitle, [
    ['Enseignant', url('teacher/index.php')],
    ['Études de cas', url('teacher/case_studies/index.php')],
    [$isEdit ? 'Modifier' : 'Importer', '']
]);
?>
<style>
/* Override du scroll interne de .page-content pour cette page uniquement :
   le scroll se fait au niveau du viewport (comportement attendu par l'utilisateur). */
.page-content { overflow: visible !important; }
</style>
<div class="page-content fade-in" style="overflow:visible;min-height:calc(100vh - 64px)">
  <?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="POST" enctype="multipart/form-data" id="cs-form">
    <?= csrfField() ?>
    <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= $cs['id'] ?>"><?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start">

      <!-- Main -->
      <div style="display:flex;flex-direction:column;gap:20px">

        <!-- Type de fichier -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Type de fichier</h3></div>
          <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px">
              <?php foreach ($contentTypes as $type => $info): ?>
              <label style="cursor:pointer">
                <input type="radio" name="file_type" value="<?= $type ?>" style="display:none"
                  <?= ($cs['file_type'] === $type ? 'checked' : ($type==='pdf' && !$isEdit ? 'checked' : '')) ?>>
                <div class="type-card" data-type="<?= $type ?>"
                  style="padding:12px 8px;border:2px solid var(--border);border-radius:var(--radius-lg);text-align:center;transition:.2s;<?= ($cs['file_type'] === $type || (!$isEdit && $type==='pdf')) ? 'border-color:var(--primary);background:rgba(99,102,241,.08)' : '' ?>">
                  <i class="fas fa-<?= $info['icon'] ?>" style="font-size:20px;color:<?= $info['color'] ?>;display:block;margin-bottom:5px"></i>
                  <div style="font-size:11px;font-weight:700;color:var(--text)"><?= $info['label'] ?></div>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Informations -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Informations</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Titre <span class="required">*</span></label>
              <input type="text" name="title" class="form-control" required maxlength="255"
                placeholder="Ex : Cas Joly Construction — Gestion des stocks"
                value="<?= e($cs['title']) ?>">
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Description <span style="color:var(--text-muted)">(optionnel)</span></label>
              <textarea name="description" class="form-control" rows="3"
                placeholder="Contexte, objectifs pédagogiques..."><?= e($cs['description']) ?></textarea>
            </div>
          </div>
        </div>

        <!-- Zone de contenu -->
        <div class="card" id="content-card">
          <div class="card-header"><h3 class="card-title" id="content-title">Documents PDF</h3></div>
          <div class="card-body">

            <!-- ── Zone multi-PDF ── -->
            <div id="zone-pdf" data-has-existing="<?= $existingPdfs ? 'true' : 'false' ?>">

              <?php if ($existingPdfs): ?>
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--primary);letter-spacing:.05em;margin-bottom:10px">
                <i class="fas fa-file-pdf" style="margin-right:5px"></i>PDFs de l'étude de cas
              </div>
              <div id="existing-pdf-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
                <?php foreach ($existingPdfs as $i => $ep): ?>
                <div class="existing-pdf-row" style="display:flex;align-items:center;gap:8px;padding:9px 10px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
                  <input type="hidden" name="existing_pdf_paths[]" value="<?= e($ep['path']) ?>">
                  <span class="row-num" style="width:24px;height:24px;border-radius:5px;background:#ef4444;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:white;flex-shrink:0"><?= $i+1 ?></span>
                  <i class="fas fa-file-pdf" style="color:#ef4444;font-size:13px;flex-shrink:0"></i>
                  <input type="text" name="existing_pdf_names[]" value="<?= e($ep['name']) ?>" class="form-control" style="flex:1;font-size:13px;padding:5px 9px" placeholder="Nom du document">
                  <div style="display:flex;flex-direction:column;gap:1px;flex-shrink:0">
                    <button type="button" onclick="moveExistingPdf(this,-1)" class="btn btn-ghost btn-sm" style="padding:2px 7px;font-size:10px" title="Remonter"><i class="fas fa-chevron-up"></i></button>
                    <button type="button" onclick="moveExistingPdf(this,1)"  class="btn btn-ghost btn-sm" style="padding:2px 7px;font-size:10px" title="Descendre"><i class="fas fa-chevron-down"></i></button>
                  </div>
                  <a href="<?= e(uploadUrl($ep['path'])) ?>" target="_blank" class="btn btn-ghost btn-sm" style="padding:4px 8px;font-size:11px;flex-shrink:0" title="Ouvrir"><i class="fas fa-eye"></i></a>
                  <button type="button" onclick="deleteExistingPdf(this)" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:4px 8px;flex-shrink:0" title="Supprimer"><i class="fas fa-trash"></i></button>
                </div>
                <?php endforeach; ?>
              </div>
              <div style="display:flex;gap:8px;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--border)">
                <button type="button" onclick="deleteAllExistingPdfs()" class="btn btn-ghost btn-sm" style="color:var(--danger);font-size:12px">
                  <i class="fas fa-trash-alt" style="margin-right:5px"></i>Supprimer tous les PDFs
                </button>
              </div>
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);letter-spacing:.05em;margin-bottom:10px">
                <i class="fas fa-plus" style="margin-right:5px"></i>Ajouter de nouveaux PDFs à la suite
              </div>
              <?php endif; ?>

              <div id="pdf-slots" style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px"></div>
              <button type="button" onclick="addPdfSlot()" class="btn btn-ghost btn-sm">
                <i class="fas fa-plus-circle" style="color:var(--primary);margin-right:6px"></i>Ajouter un PDF
              </button>
            </div>

            <!-- ── Zone fichier unique (document, présentation, vidéo) ── -->
            <div id="zone-file" style="display:none">
              <?php
              $singleFileUrl = (!$isEdit || $cs['file_type'] === 'pdf') ? null
                  : ($cs['file_path'] ? uploadUrl($cs['file_path']) : null);
              ?>
              <?php if ($singleFileUrl): ?>
              <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.2);border-radius:var(--radius);padding:12px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
                <i class="fas fa-file-alt" style="color:var(--primary-light)"></i>
                <span style="flex:1;font-size:13px">Fichier actuel</span>
                <a href="<?= e($singleFileUrl) ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-eye"></i> Voir</a>
              </div>
              <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px">Choisir un nouveau fichier pour remplacer :</div>
              <?php endif; ?>
              <input type="file" name="cs_file" id="cs-file-input" style="display:none">
              <div id="cs-file-dropzone" onclick="document.getElementById('cs-file-input').click()"
                style="border:2px dashed var(--border);border-radius:var(--radius);padding:28px;text-align:center;cursor:pointer;transition:.2s"
                onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
                <i class="fas fa-cloud-upload-alt" style="font-size:28px;color:var(--text-muted);margin-bottom:8px;display:block"></i>
                <div id="cs-file-label" style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:4px">Cliquer ou glisser un fichier</div>
                <div id="cs-file-hint" style="font-size:12px;color:var(--text-muted)">PDF, Word, Excel, PowerPoint (max 50 Mo)</div>
              </div>
            </div>

            <!-- ── Zone vidéo ── -->
            <div id="zone-video" style="display:none">
              <div class="form-group">
                <label class="form-label">URL de la vidéo <span style="color:var(--text-muted)">(YouTube, Vimeo…)</span></label>
                <div class="input-group">
                  <i class="fas fa-link input-icon"></i>
                  <input type="url" name="content_url" id="content-url" class="form-control"
                    placeholder="https://www.youtube.com/watch?v=..." value="<?= e($cs['content_url']) ?>">
                </div>
              </div>
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label">ou téléverser un fichier vidéo</label>
                <input type="file" name="cs_file" id="cs-video-input" style="display:none" accept="video/*">
                <div onclick="document.getElementById('cs-video-input').click()"
                  style="border:2px dashed var(--border);border-radius:var(--radius);padding:20px;text-align:center;cursor:pointer;transition:.2s"
                  onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
                  <i class="fas fa-video" style="font-size:24px;color:var(--text-muted);margin-bottom:6px;display:block"></i>
                  <span style="font-size:13px;color:var(--text-muted)">MP4, WebM (max 50 Mo)</span>
                </div>
              </div>
            </div>

            <!-- ── Zone lien externe ── -->
            <div id="zone-link" style="display:none">
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label">URL de la ressource <span class="required">*</span></label>
                <div class="input-group">
                  <i class="fas fa-link input-icon"></i>
                  <input type="url" name="content_url" class="form-control"
                    placeholder="https://exemple.com/etude-de-cas" value="<?= e($cs['content_url']) ?>">
                </div>
              </div>
            </div>

          </div>
        </div>

        <!-- ── Ressources complémentaires ─────────────────────── -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-paperclip" style="margin-right:8px;color:var(--primary-light)"></i>Ressources complémentaires</h3>
            <p class="card-subtitle">Documents, liens ou fichiers annexes utiles à la réalisation de l'étude de cas</p>
          </div>
          <div class="card-body">

            <?php
            $resIcons = [
                'pdf'         => ['icon'=>'file-pdf',        'color'=>'#ef4444'],
                'word'        => ['icon'=>'file-word',       'color'=>'#3b82f6'],
                'excel'       => ['icon'=>'file-excel',      'color'=>'#10b981'],
                'powerpoint'  => ['icon'=>'file-powerpoint', 'color'=>'#f97316'],
                'video'       => ['icon'=>'play-circle',     'color'=>'#ef4444'],
                'image'       => ['icon'=>'image',           'color'=>'#a855f7'],
                'link'        => ['icon'=>'link',            'color'=>'#0ea5e9'],
                'other'       => ['icon'=>'file',            'color'=>'var(--text-muted)'],
            ];
            ?>

            <?php if (!empty($existingResources)): ?>
            <!-- Ressources existantes -->
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px">
              Ressources actuelles
            </div>
            <div id="existing-res-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:18px">
              <?php foreach ($existingResources as $res):
                $ri = $resIcons[$res['type']] ?? $resIcons['other'];
              ?>
              <div class="existing-res-row" style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
                <input type="hidden" name="existing_resource_ids[]" value="<?= (int)$res['id'] ?>">
                <i class="fas fa-<?= $ri['icon'] ?>" style="color:<?= $ri['color'] ?>;font-size:14px;flex-shrink:0;width:16px"></i>
                <span style="flex:1;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($res['title']) ?>">
                  <?= e(mb_substr($res['title'],0,60)) ?>
                </span>
                <?php if ($res['file_size']): ?>
                <span style="font-size:11px;color:var(--text-faint);flex-shrink:0"><?= formatFileSize($res['file_size']) ?></span>
                <?php endif; ?>
                <?php if ($res['file_path']): ?>
                <a href="<?= e(uploadUrl($res['file_path'])) ?>" target="_blank" class="btn btn-ghost btn-sm" style="padding:3px 8px" title="Ouvrir"><i class="fas fa-eye"></i></a>
                <?php elseif ($res['url']): ?>
                <a href="<?= e($res['url']) ?>" target="_blank" class="btn btn-ghost btn-sm" style="padding:3px 8px;max-width:140px;overflow:hidden;text-overflow:ellipsis" title="<?= e($res['url']) ?>">
                  <i class="fas fa-external-link-alt"></i>
                </a>
                <?php endif; ?>
                <button type="button" onclick="deleteExistingRes(this)" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:3px 8px;flex-shrink:0" title="Retirer">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
              <?php endforeach; ?>
            </div>
            <div style="border-top:1px solid var(--border);margin:0 -16px 18px"></div>
            <?php endif; ?>

            <!-- Ajout de fichiers -->
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px">
              Ajouter des fichiers
            </div>
            <input type="file" name="new_res_files[]" id="new-res-input" multiple style="display:none"
              accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.webm,.jpg,.jpeg,.png,.gif">
            <div id="new-res-dropzone"
              style="border:2px dashed var(--border);border-radius:var(--radius);padding:22px;text-align:center;cursor:pointer;transition:.2s"
              onclick="document.getElementById('new-res-input').click()"
              onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
              <i class="fas fa-cloud-upload-alt" style="font-size:26px;color:var(--text-muted);margin-bottom:6px;display:block"></i>
              <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:3px">Cliquer ou glisser des fichiers</div>
              <div style="font-size:11px;color:var(--text-muted)">PDF, Word, Excel, PowerPoint, vidéo, image</div>
            </div>
            <div id="new-res-list" style="margin-top:10px;display:flex;flex-direction:column;gap:6px"></div>
            <!-- Inputs cachés pour les titres des nouveaux fichiers (synchronisés par JS) -->
            <div id="new-res-titles-container" style="display:none"></div>

            <!-- Ajout de liens web -->
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:18px;margin-bottom:8px">
              <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Liens web</span>
              <button type="button" onclick="addLinkRow()" class="btn btn-ghost btn-sm" style="font-size:12px">
                <i class="fas fa-plus" style="margin-right:5px;color:var(--primary)"></i>Ajouter un lien
              </button>
            </div>
            <div id="link-rows" style="display:flex;flex-direction:column;gap:8px"></div>

          </div>
        </div>

      </div><!-- /Main -->

      <!-- Sidebar : Publication+Paramètres sticky, Rattachements en flux normal -->
      <div style="display:flex;flex-direction:column;gap:16px">

        <!-- Publication + Paramètres — sticky, tient dans le viewport -->
        <div style="position:sticky;top:80px">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Publication</h3></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
              <button type="submit" class="btn btn-primary w-full" style="justify-content:center">
                <i class="fas fa-save"></i> <?= $isEdit ? 'Enregistrer' : 'Importer' ?>
              </button>
              <?php if ($isEdit): ?>
              <a href="<?= url('student/case_studies/view.php?id='.$cs['id']) ?>" target="_blank"
                class="btn btn-secondary w-full" style="justify-content:center">
                <i class="fas fa-eye"></i> Prévisualiser
              </a>
              <?php endif; ?>
              <a href="<?= url('teacher/case_studies/index.php') ?>" class="btn btn-ghost w-full" style="justify-content:center">
                Annuler
              </a>
              <div style="border-top:1px solid var(--border);margin:4px -24px"></div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div>
                  <label class="form-label" style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Durée</label>
                  <div style="display:flex;align-items:center;gap:4px">
                    <input type="number" name="duration_minutes" class="form-control" style="font-size:12px;min-width:0;flex:1"
                      min="0" max="9999" placeholder="—" value="<?= $cs['duration_minutes'] !== null ? (int)$cs['duration_minutes'] : '' ?>">
                    <span style="font-size:11px;color:var(--text-muted);white-space:nowrap">min</span>
                  </div>
                </div>
                <div>
                  <label class="form-label" style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">XP</label>
                  <div style="display:flex;align-items:center;gap:4px">
                    <input type="number" name="xp_reward" class="form-control" style="font-size:12px;min-width:0;flex:1"
                      min="0" max="9999" value="<?= (int)$cs['xp_reward'] ?>">
                    <span style="font-size:11px;color:var(--text-muted);white-space:nowrap">XP</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Rattachements — flux normal, accessible par scroll du viewport -->
        <div class="card">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-sitemap" style="margin-right:7px;color:var(--primary-light)"></i>Rattachements</h3></div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
            <div>
              <label class="form-label" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Formation</label>
              <select name="formation_id" id="sel-formation" class="form-control" style="font-size:12px" onchange="onFormationChange(this.value)">
                <option value="">— Aucune —</option>
                <?php foreach ($formations as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $cs['formation_id'] == $f['id'] ? 'selected' : '' ?>>
                  <?= e(mb_substr($f['title'],0,38)) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php renderCascadeSelect('module_id','module',$initModules,$cs['module_id'],'Module de formation','← sélectionner une formation d\'abord'); ?>
            <?php renderCascadeSelect('lesson_id','lesson',$initLessons,$cs['lesson_id'],'Capsule de cours','← sélectionner un module d\'abord'); ?>
            <div style="border-top:1px solid var(--border);margin:2px -16px"></div>
            <?php renderCascadeSelect('activity_type_id','at',$initActivityTypes,$cs['activity_type_id'],'Bloc / Activité type','← sélectionner une formation d\'abord',function($i){ return $i['code'] . ' — ' . mb_substr($i['title'],0,30); }); ?>
            <?php renderCascadeSelect('competency_id','competency',$initCompetencies,$cs['competency_id'],'Compétence','← sélectionner un bloc d\'abord',function($i){ return $i['code'] . ' — ' . mb_substr($i['title'],0,30); }); ?>
          </div>
        </div>

      </div>

    </div>
  </form>
</div>

<script>
var _pdfSlotId = 0;

// ── Sélection du type ──────────────────────────────────────────
document.querySelectorAll('.type-card').forEach(function(card) {
  card.addEventListener('click', function() {
    document.querySelectorAll('.type-card').forEach(function(c) {
      c.style.borderColor = 'var(--border)'; c.style.background = '';
    });
    card.style.borderColor = 'var(--primary)';
    card.style.background  = 'rgba(99,102,241,.08)';
    updateZone(card.dataset.type);
  });
});

function updateZone(type) {
  var zonePdf   = document.getElementById('zone-pdf');
  var zoneFile  = document.getElementById('zone-file');
  var zoneVideo = document.getElementById('zone-video');
  var zoneLink  = document.getElementById('zone-link');
  var title     = document.getElementById('content-title');
  var fileInput = document.getElementById('cs-file-input');
  var fileHint  = document.getElementById('cs-file-hint');
  var titles    = {pdf:'Documents PDF', document:'Document', presentation:'Présentation', video:'Vidéo', link:'Lien externe'};

  title.textContent = titles[type] || 'Contenu';
  zonePdf.style.display   = 'none';
  zoneFile.style.display  = 'none';
  zoneVideo.style.display = 'none';
  zoneLink.style.display  = 'none';

  if (type === 'pdf') {
    zonePdf.style.display = 'block';
    var hasExisting = zonePdf.dataset.hasExisting === 'true';
    if (!hasExisting && !document.querySelector('#pdf-slots .pdf-slot')) addPdfSlot();
  } else if (type === 'video') {
    zoneVideo.style.display = 'block';
  } else if (type === 'link') {
    zoneLink.style.display = 'block';
  } else {
    // document ou presentation
    zoneFile.style.display = 'block';
    if (fileInput) {
      fileInput.accept = type === 'document'
        ? '.doc,.docx,.xls,.xlsx,.pdf'
        : '.ppt,.pptx,.pdf';
    }
    if (fileHint) {
      fileHint.textContent = type === 'document'
        ? 'Word, Excel, PDF (max 50 Mo)'
        : 'PowerPoint, PDF (max 50 Mo)';
    }
  }
}

// ── Multi-PDF slots ────────────────────────────────────────────
function addPdfSlot() {
  _pdfSlotId++;
  var id    = _pdfSlotId;
  var slots = document.getElementById('pdf-slots');
  var existingCount = (document.getElementById('existing-pdf-list') || {querySelectorAll:function(){return[]}})
    .querySelectorAll('.existing-pdf-row').length;
  var num = slots.querySelectorAll('.pdf-slot').length + existingCount + 1;
  var div = document.createElement('div');
  div.className = 'pdf-slot';
  div.id = 'ps-' + id;
  div.style.cssText = 'display:flex;align-items:flex-start;gap:10px;padding:12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)';
  div.innerHTML = `
    <div class="slot-num" style="width:28px;height:28px;border-radius:6px;background:rgba(239,68,68,.15);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#ef4444;flex-shrink:0;margin-top:2px">${num}</div>
    <div style="flex:1;min-width:0">
      <input type="file" name="pdf_files[]" accept=".pdf,application/pdf" style="display:none" id="pf-${id}" onchange="onPdfSelected(this)">
      <div id="pfl-${id}" onclick="document.getElementById('pf-${id}').click()"
        style="font-size:13px;color:var(--text-muted);cursor:pointer;padding:10px 14px;border:2px dashed var(--border);border-radius:6px;text-align:center;transition:.2s"
        onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
        <i class="fas fa-file-pdf" style="color:#ef4444;margin-right:6px"></i>Cliquer pour sélectionner un PDF
      </div>
      <input type="text" name="pdf_names[]" placeholder="Nom du document (ex : Annexe 1 — Contexte)" class="form-control" style="margin-top:6px;font-size:12px" id="pn-${id}">
    </div>
    <div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0;margin-top:2px">
      <button type="button" onclick="movePdfSlot('ps-${id}',-1)" class="btn btn-ghost btn-sm" style="padding:3px 8px" title="Remonter"><i class="fas fa-chevron-up"></i></button>
      <button type="button" onclick="movePdfSlot('ps-${id}',1)"  class="btn btn-ghost btn-sm" style="padding:3px 8px" title="Descendre"><i class="fas fa-chevron-down"></i></button>
    </div>
    <button type="button" onclick="removePdfSlot('ps-${id}')" class="btn btn-ghost btn-sm" style="color:var(--danger);flex-shrink:0;margin-top:2px" title="Supprimer"><i class="fas fa-trash"></i></button>
  `;
  slots.appendChild(div);
  updateSlotNumbers();
}

function onPdfSelected(input) {
  var file = input.files[0]; if (!file) return;
  var id = input.id.replace('pf-', '');
  var label = document.getElementById('pfl-' + id);
  var nameIn = document.getElementById('pn-' + id);
  var size = file.size >= 1048576 ? (file.size/1048576).toFixed(1)+' Mo' : Math.round(file.size/1024)+' Ko';
  label.innerHTML = '<i class="fas fa-check-circle" style="color:var(--success);margin-right:6px"></i><strong>' + file.name + '</strong> <span style="font-size:11px;color:var(--text-muted)">' + size + '</span>';
  label.style.borderColor = 'var(--success)';
  label.style.background  = 'rgba(16,185,129,.05)';
  if (!nameIn.value) nameIn.value = file.name.replace(/\.pdf$/i, '');
}

function movePdfSlot(slotId, dir) {
  var slot = document.getElementById(slotId);
  var parent = slot.parentNode;
  var siblings = Array.from(parent.querySelectorAll('.pdf-slot'));
  var idx = siblings.indexOf(slot), newIdx = idx + dir;
  if (newIdx < 0 || newIdx >= siblings.length) return;
  if (dir === -1) parent.insertBefore(slot, siblings[newIdx]);
  else parent.insertBefore(siblings[newIdx], slot);
  updateSlotNumbers();
}

function removePdfSlot(slotId) {
  var s = document.getElementById(slotId); if (s) s.remove();
  updateSlotNumbers();
  if (!document.querySelector('#pdf-slots .pdf-slot')) addPdfSlot();
}

function updateSlotNumbers() {
  var existingCount = document.querySelectorAll('#existing-pdf-list .existing-pdf-row').length;
  document.querySelectorAll('#pdf-slots .pdf-slot').forEach(function(slot, i) {
    var n = slot.querySelector('.slot-num'); if (n) n.textContent = existingCount + i + 1;
  });
}

// ── PDFs existants ─────────────────────────────────────────────
function moveExistingPdf(btn, dir) {
  var row = btn.closest('.existing-pdf-row');
  var list = document.getElementById('existing-pdf-list');
  var rows = Array.from(list.querySelectorAll('.existing-pdf-row'));
  var idx = rows.indexOf(row), newIdx = idx + dir;
  if (newIdx < 0 || newIdx >= rows.length) return;
  if (dir === -1) list.insertBefore(row, rows[newIdx]);
  else list.insertBefore(rows[newIdx], row);
  updateExistingPdfNumbers();
}

function deleteExistingPdf(btn) {
  if (!confirm('Supprimer ce PDF de l\'étude de cas ?')) return;
  btn.closest('.existing-pdf-row').remove();
  updateExistingPdfNumbers();
  updateSlotNumbers();
}

function deleteAllExistingPdfs() {
  var list = document.getElementById('existing-pdf-list');
  if (!list) return;
  var count = list.querySelectorAll('.existing-pdf-row').length;
  if (!count || !confirm('Supprimer les ' + count + ' PDF(s) ?')) return;
  list.querySelectorAll('.existing-pdf-row').forEach(function(r) { r.remove(); });
  updateSlotNumbers();
  if (!document.querySelector('#pdf-slots .pdf-slot')) addPdfSlot();
}

function updateExistingPdfNumbers() {
  var list = document.getElementById('existing-pdf-list');
  if (!list) return;
  list.querySelectorAll('.existing-pdf-row').forEach(function(row, i) {
    var n = row.querySelector('.row-num'); if (n) n.textContent = i + 1;
  });
  updateSlotNumbers();
}

// ── Dropzone fichier unique ────────────────────────────────────
(function() {
  var dz = document.getElementById('cs-file-dropzone');
  var inp = document.getElementById('cs-file-input');
  if (!dz || !inp) return;
  inp.addEventListener('change', function() {
    var f = inp.files[0]; if (!f) return;
    var size = f.size >= 1048576 ? (f.size/1048576).toFixed(1)+' Mo' : Math.round(f.size/1024)+' Ko';
    document.getElementById('cs-file-label').innerHTML =
      '<i class="fas fa-check-circle" style="color:var(--success);margin-right:6px"></i>' + f.name;
    document.getElementById('cs-file-hint').textContent = size;
    dz.style.borderColor = 'var(--success)';
    dz.style.background  = 'rgba(16,185,129,.05)';
  });
})();

// ── Init ──────────────────────────────────────────────────────
updateZone('<?= e($cs['file_type']) ?>');

// ── Ressources complémentaires ────────────────────────────────
const RES_ICONS = {
  pdf:'file-pdf',doc:'file-word',docx:'file-word',
  xls:'file-excel',xlsx:'file-excel',
  ppt:'file-powerpoint',pptx:'file-powerpoint',
  mp4:'play-circle',webm:'play-circle',
  jpg:'image',jpeg:'image',png:'image',gif:'image',webp:'image',svg:'image',
};
const RES_COLORS = {
  'file-pdf':'#ef4444','file-word':'#3b82f6','file-excel':'#10b981',
  'file-powerpoint':'#f97316','play-circle':'#ef4444','image':'#a855f7',
};

var _newResFiles  = []; // accumule les fichiers sélectionnés
var _linkRowIdx   = 0;

// Dropzone multi-fichiers
(function() {
  var inp = document.getElementById('new-res-input');
  var dz  = document.getElementById('new-res-dropzone');
  if (!inp) return;
  inp.addEventListener('change', function() { addResFiles(Array.from(inp.files)); });
  dz.addEventListener('dragover',  function(e){ e.preventDefault(); dz.style.borderColor='var(--primary)'; });
  dz.addEventListener('dragleave', function()  { dz.style.borderColor='var(--border)'; });
  dz.addEventListener('drop', function(e) {
    e.preventDefault(); dz.style.borderColor='var(--border)';
    addResFiles(Array.from(e.dataTransfer.files));
  });
})();

function addResFiles(newFiles) {
  newFiles.forEach(function(f) {
    if (!_newResFiles.find(function(e){ return e.name===f.name && e.size===f.size; }))
      _newResFiles.push(f);
  });
  syncResInput();
  renderResList();
}

function syncResInput() {
  var inp = document.getElementById('new-res-input');
  var dt  = new DataTransfer();
  _newResFiles.forEach(function(f){ dt.items.add(f); });
  inp.files = dt.files;
}

function renderResList() {
  var list = document.getElementById('new-res-list');
  var titlesContainer = document.getElementById('new-res-titles-container');
  list.innerHTML = '';
  titlesContainer.innerHTML = '';
  _newResFiles.forEach(function(f, i) {
    var ext  = f.name.split('.').pop().toLowerCase();
    var icon = RES_ICONS[ext] || 'file';
    var col  = RES_COLORS[icon] || 'var(--text-muted)';
    var size = f.size >= 1048576 ? (f.size/1048576).toFixed(1)+' Mo' : Math.round(f.size/1024)+' Ko';
    var defaultTitle = f.name.replace(/\.[^.]+$/, '');
    // Titre input (hidden container, submitted with form)
    var titleId = 'nrt-' + i;
    titlesContainer.insertAdjacentHTML('beforeend',
      '<input type="text" name="new_res_titles[]" id="' + titleId + '" value="' + defaultTitle.replace(/"/g,'&quot;') + '">');
    // Visible row
    list.insertAdjacentHTML('beforeend', `
      <div style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
        <i class="fas fa-${icon}" style="color:${col};font-size:14px;flex-shrink:0;width:16px"></i>
        <input type="text" value="${defaultTitle.replace(/"/g,'&quot;')}" placeholder="Titre du document"
          class="form-control" style="flex:1;font-size:12px;padding:4px 8px"
          oninput="document.getElementById('${titleId}').value=this.value">
        <span style="font-size:11px;color:var(--text-faint);flex-shrink:0">${size}</span>
        <button type="button" onclick="removeResFile(${i})" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:3px 8px;flex-shrink:0"><i class="fas fa-times"></i></button>
      </div>
    `);
  });
}

function removeResFile(idx) {
  _newResFiles.splice(idx, 1);
  syncResInput();
  renderResList();
}

// Supprimer une ressource existante (retire la ligne + le hidden input)
function deleteExistingRes(btn) {
  if (!confirm('Retirer cette ressource de l\'étude de cas ?')) return;
  btn.closest('.existing-res-row').remove();
}

// Ajouter un lien web
function addLinkRow() {
  _linkRowIdx++;
  var idx = _linkRowIdx;
  document.getElementById('link-rows').insertAdjacentHTML('beforeend', `
    <div id="lr-${idx}" style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
      <i class="fas fa-link" style="color:#0ea5e9;flex-shrink:0;width:16px"></i>
      <div style="flex:1;display:flex;flex-direction:column;gap:5px">
        <input type="text" name="new_res_link_titles[]" placeholder="Titre de la ressource (ex : Guide méthodologique)" class="form-control" style="font-size:12px;padding:4px 8px">
        <input type="url"  name="new_res_urls[]"        placeholder="https://..." class="form-control" style="font-size:12px;padding:4px 8px">
      </div>
      <button type="button" onclick="document.getElementById('lr-${idx}').remove()" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:3px 8px;flex-shrink:0"><i class="fas fa-times"></i></button>
    </div>
  `);
}

// ── Cascade AJAX ──────────────────────────────────────────────
var AJAX_URL = '<?= url('teacher/case_studies/ajax.php') ?>';

// Réinitialise un select avec une option placeholder désactivée (visible mais non sélectionnable)
function resetSel(selId, placeholder) {
  var sel = document.getElementById(selId);
  while (sel.options.length) sel.remove(0);
  var opt = new Option(placeholder, '');
  opt.disabled = true; opt.selected = true;
  sel.add(opt);
}

function populateSel(selId, items, labelFn) {
  var sel = document.getElementById(selId);
  while (sel.options.length) sel.remove(0);
  sel.add(new Option('— Aucun(e) —', ''));
  items.forEach(function(item) {
    sel.add(new Option(labelFn ? labelFn(item) : item.title, item.id));
  });
}

async function onFormationChange(fid) {
  resetSel('sel-module',     '← sélectionner une formation d\'abord');
  resetSel('sel-lesson',     '← sélectionner un module d\'abord');
  resetSel('sel-at',         '← sélectionner une formation d\'abord');
  resetSel('sel-competency', '← sélectionner un bloc d\'abord');
  if (!fid) return;
  try {
    var [mods, ats] = await Promise.all([
      fetch(AJAX_URL + '?action=modules&formation_id='       + fid).then(function(r){return r.json();}),
      fetch(AJAX_URL + '?action=activity_types&formation_id=' + fid).then(function(r){return r.json();})
    ]);
    if (mods.length) populateSel('sel-module', mods);
    if (ats.length)  populateSel('sel-at', ats, function(i){ return i.code + ' — ' + i.title; });
  } catch(e) {}
}

async function onModuleChange(mid) {
  resetSel('sel-lesson', '← sélectionner un module d\'abord');
  if (!mid) return;
  try {
    var lessons = await fetch(AJAX_URL + '?action=lessons&module_id=' + mid).then(function(r){return r.json();});
    if (lessons.length) populateSel('sel-lesson', lessons);
  } catch(e) {}
}

async function onATChange(atid) {
  resetSel('sel-competency', '← sélectionner un bloc d\'abord');
  if (!atid) return;
  try {
    var comps = await fetch(AJAX_URL + '?action=competencies&activity_type_id=' + atid).then(function(r){return r.json();});
    if (comps.length) populateSel('sel-competency', comps, function(i){ return i.code + ' — ' + i.title; });
  } catch(e) {}
}
</script>
<?php renderFooter(); ?>
