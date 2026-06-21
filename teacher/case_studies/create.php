<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$editId = (int)($_GET['id'] ?? 0);
$isEdit = $editId > 0;
$errors = [];

// Charger formations pour le menu déroulant
$formations = $pdo->query("SELECT id, title FROM formations WHERE status='active' ORDER BY title")->fetchAll();

// Mode édition — charger l'étude existante
$cs = ['id'=>0,'title'=>'','description'=>'','file_type'=>'pdf','file_path'=>null,'content_url'=>'','formation_id'=>null];
if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM case_studies WHERE id = ?');
    $stmt->execute([$editId]);
    $loaded = $stmt->fetch();
    if (!$loaded || (!isAdmin() && !isPedagogy() && $loaded['created_by'] != $userId)) {
        setFlash('error', 'Accès refusé ou étude introuvable.');
        redirect(url('teacher/case_studies/index.php'));
    }
    $cs = $loaded;
}

// ── POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $postEditId = (int)($_POST['edit_id'] ?? 0);

    $data = [
        'title'        => trim($_POST['title'] ?? ''),
        'description'  => trim($_POST['description'] ?? ''),
        'file_type'    => $_POST['file_type'] ?? 'pdf',
        'content_url'  => trim($_POST['content_url'] ?? ''),
        'formation_id' => (int)($_POST['formation_id'] ?? 0) ?: null,
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
        ];

        if ($postEditId) {
            $pdo->prepare("
                UPDATE case_studies SET title=?,description=?,file_type=?,file_path=?,
                content_url=?,formation_id=?,updated_at=NOW() WHERE id=?
            ")->execute(array_merge($row, [$postEditId]));
            auditLog('case_study_updated', 'case_study', $postEditId);
            setFlash('success', 'Étude de cas modifiée.');
        } else {
            $row[] = $userId;
            $pdo->prepare("
                INSERT INTO case_studies (title,description,file_type,file_path,content_url,formation_id,created_by)
                VALUES (?,?,?,?,?,?,?)
            ")->execute($row);
            $newId = (int)$pdo->lastInsertId();
            auditLog('case_study_created', 'case_study', $newId);
            setFlash('success', 'Étude de cas importée avec succès.');
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
<div class="page-content fade-in">
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

      </div><!-- /Main -->

      <!-- Sidebar -->
      <div style="display:flex;flex-direction:column;gap:16px;position:sticky;top:80px">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Publication</h3></div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Formation liée <span style="color:var(--text-muted)">(optionnel)</span></label>
              <select name="formation_id" class="form-control" style="font-size:13px">
                <option value="">— Aucune —</option>
                <?php foreach ($formations as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $cs['formation_id'] == $f['id'] ? 'selected' : '' ?>>
                  <?= e(mb_substr($f['title'],0,40)) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
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
</script>
<?php renderFooter(); ?>
