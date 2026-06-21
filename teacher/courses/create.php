<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$errors = [];

// Mode édition ?
$editId = (int)($_GET['id'] ?? 0);
$lesson = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM lessons WHERE id = ? AND created_by = ?');
    $stmt->execute([$editId, $userId]);
    $lesson = $stmt->fetch();
    if (!$lesson) { setFlash('error', 'Capsule introuvable ou accès refusé.'); redirect(url('teacher/courses/index.php')); }
}
$isEdit = $lesson !== null;

// Données pour la sélection en cascade
$rncp = $pdo->query("SELECT id, rncp_code, title FROM rncp_titles WHERE status='active' ORDER BY rncp_code")->fetchAll();

$formations = $pdo->query("SELECT id, rncp_title_id, title FROM formations WHERE status='active' ORDER BY title")->fetchAll();

$allModules = $pdo->query("
    SELECT mo.id, mo.formation_id, mo.activity_type_id, mo.title, mo.order_num
    FROM modules mo JOIN formations f ON mo.formation_id = f.id
    ORDER BY f.title, mo.order_num
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $postEditId = (int)($_POST['edit_id'] ?? 0);
    $data = [
        'module_id'        => (int)($_POST['module_id'] ?? 0),
        'competency_id'    => (int)($_POST['competency_id'] ?? 0) ?: null,
        'title'            => trim($_POST['title'] ?? ''),
        'description'      => trim($_POST['description'] ?? ''),
        'content_type'     => $_POST['content_type'] ?? 'text',
        'content_url'      => trim($_POST['content_url'] ?? ''),
        'content_body'     => trim($_POST['content_body'] ?? ''),
        'duration_minutes' => (int)($_POST['duration_minutes'] ?? 0) ?: null,
        'order_num'        => (int)($_POST['order_num'] ?? 1),
        'is_mandatory'     => isset($_POST['is_mandatory']) ? 1 : 0,
        'is_preview'       => isset($_POST['is_preview']) ? 1 : 0,
        'xp_reward'        => (int)($_POST['xp_reward'] ?? 10),
    ];

    if (!$data['module_id'])    $errors[] = 'Module requis.';
    if (!$data['title'])        $errors[] = 'Titre requis.';
    if (!$data['content_type']) $errors[] = 'Type de contenu requis.';

    // File upload
    $filePath = $lesson['file_path'] ?? null;

    if ($data['content_type'] === 'pdf' && !empty($_FILES['pdf_files']['name'][0])) {
        // ── Multi-PDF upload ──────────────────────────────────────
        $pdfEntries = [];
        foreach ($_FILES['pdf_files']['name'] as $i => $pdfName) {
            if (empty($pdfName) || $_FILES['pdf_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $pdfFile = [
                'name'     => $pdfName,
                'tmp_name' => $_FILES['pdf_files']['tmp_name'][$i],
                'error'    => $_FILES['pdf_files']['error'][$i],
                'size'     => $_FILES['pdf_files']['size'][$i],
                'type'     => $_FILES['pdf_files']['type'][$i],
            ];
            $upload = uploadFile($pdfFile, 'courses', ['application/pdf'], MAX_UPLOAD_SIZE);
            if ($upload['success']) {
                $customName = trim($_POST['pdf_names'][$i] ?? '') ?: pathinfo($pdfName, PATHINFO_FILENAME);
                $pdfEntries[] = ['path' => $upload['path'], 'name' => $customName];
            } else {
                $errors[] = 'PDF n°' . ($i + 1) . ' : ' . $upload['error'];
            }
        }
        if (!empty($pdfEntries)) {
            $filePath = json_encode($pdfEntries, JSON_UNESCAPED_UNICODE);
        }
    } elseif (!empty($_FILES['lesson_file']['name'])) {
        // ── Fichier unique (autres types) ─────────────────────────
        $upload = uploadFile($_FILES['lesson_file'], 'courses', ALLOWED_DOC_TYPES, MAX_UPLOAD_SIZE);
        if ($upload['success']) $filePath = $upload['path'];
        else $errors[] = 'Fichier : ' . $upload['error'];
    }

    // Thumbnail
    $thumbnail = $lesson['thumbnail'] ?? null;
    if (!empty($_FILES['thumbnail']['name'])) {
        $upload = uploadFile($_FILES['thumbnail'], 'courses/thumbs', ALLOWED_IMAGE_TYPES);
        if ($upload['success']) $thumbnail = $upload['path'];
    }

    if (empty($errors)) {
        if ($postEditId) {
            // Vérification de sécurité
            $check = $pdo->prepare('SELECT id FROM lessons WHERE id = ? AND created_by = ?');
            $check->execute([$postEditId, $userId]);
            if (!$check->fetch()) { setFlash('error', 'Accès refusé.'); redirect(url('teacher/courses/index.php')); }

            $pdo->prepare("
                UPDATE lessons SET module_id=?,competency_id=?,title=?,description=?,content_type=?,
                content_url=?,content_body=?,file_path=?,thumbnail=?,duration_minutes=?,
                order_num=?,is_mandatory=?,is_preview=?,xp_reward=? WHERE id=?
            ")->execute([
                $data['module_id'], $data['competency_id'], $data['title'], $data['description'],
                $data['content_type'], $data['content_url'] ?: null, $data['content_body'] ?: null,
                $filePath, $thumbnail, $data['duration_minutes'],
                $data['order_num'], $data['is_mandatory'], $data['is_preview'], $data['xp_reward'],
                $postEditId
            ]);
            auditLog('lesson_updated', 'lesson', $postEditId);
            setFlash('success', 'Capsule mise à jour avec succès !');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO lessons (module_id,competency_id,title,description,content_type,content_url,content_body,file_path,thumbnail,duration_minutes,order_num,is_mandatory,is_preview,xp_reward,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $data['module_id'], $data['competency_id'], $data['title'], $data['description'],
                $data['content_type'], $data['content_url'] ?: null, $data['content_body'] ?: null,
                $filePath, $thumbnail, $data['duration_minutes'],
                $data['order_num'], $data['is_mandatory'], $data['is_preview'], $data['xp_reward'], $userId
            ]);
            $lessonId = (int)$pdo->lastInsertId();

            // Handle additional resources
            if (!empty($_FILES['resources']['name'][0])) {
                foreach ($_FILES['resources']['name'] as $i => $rName) {
                    if (empty($rName)) continue;
                    $rFile = [
                        'name'     => $_FILES['resources']['name'][$i],
                        'tmp_name' => $_FILES['resources']['tmp_name'][$i],
                        'error'    => $_FILES['resources']['error'][$i],
                        'size'     => $_FILES['resources']['size'][$i],
                        'type'     => $_FILES['resources']['type'][$i],
                    ];
                    $rUpload = uploadFile($rFile, 'courses/resources', ALLOWED_DOC_TYPES);
                    if ($rUpload['success']) {
                        $ext = strtolower(pathinfo($rName, PATHINFO_EXTENSION));
                        $rType = match($ext) {
                            'pdf' => 'pdf', 'doc','docx' => 'word',
                            'xls','xlsx' => 'excel', 'ppt','pptx' => 'powerpoint',
                            'mp4','webm' => 'video', default => 'other'
                        };
                        $pdo->prepare('INSERT INTO lesson_resources (lesson_id,title,type,file_path,file_size) VALUES (?,?,?,?,?)')
                            ->execute([$lessonId, pathinfo($rName,PATHINFO_FILENAME), $rType, $rUpload['path'], $rFile['size']]);
                    }
                }
            }
            auditLog('lesson_created', 'lesson', $lessonId);
            setFlash('success', 'Capsule créée avec succès !');
        }
        redirect(url('teacher/courses/index.php'));
    }
}

// Content types
$contentTypes = [
    'video'        => ['label'=>'Vidéo',        'icon'=>'play-circle',    'color'=>'#ef4444'],
    'pdf'          => ['label'=>'PDF',           'icon'=>'file-pdf',       'color'=>'#ef4444'],
    'document'     => ['label'=>'Document',      'icon'=>'file-word',      'color'=>'#3b82f6'],
    'presentation' => ['label'=>'Présentation',  'icon'=>'file-powerpoint','color'=>'#f97316'],
    'quiz'         => ['label'=>'Quiz intégré',  'icon'=>'question-circle','color'=>'#8b5cf6'],
    'exercise'     => ['label'=>'Exercice',      'icon'=>'pencil-alt',     'color'=>'#10b981'],
    'text'         => ['label'=>'Texte/HTML',    'icon'=>'align-left',     'color'=>'#6b7280'],
    'link'         => ['label'=>'Lien externe',  'icon'=>'link',           'color'=>'#0ea5e9'],
];

$pageTitle = $isEdit ? 'Modifier la capsule' : 'Nouvelle capsule';
$breadcrumbLast = $isEdit ? 'Modifier' : 'Nouvelle';
renderHead($pageTitle);
renderSidebar('teacher');
renderTopbar($pageTitle, [['Enseignant', url('teacher/index.php')], ['Capsules', url('teacher/courses/index.php')], [$breadcrumbLast, '']]);
?>
<div class="page-content fade-in">
  <?php foreach ($errors as $err): ?><div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= e($err) ?></div><?php endforeach; ?>

  <form method="POST" enctype="multipart/form-data" id="course-form" data-autosave="new-lesson">
    <?= csrfField() ?>
    <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= $lesson['id'] ?>"><?php endif; ?>
    <div style="display:grid;grid-template-columns:1fr 320px;gap:24px;align-items:start">

      <!-- Main -->
      <div style="display:flex;flex-direction:column;gap:20px">

        <!-- Type selection -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Type de contenu</h3></div>
          <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px" id="type-grid">
              <?php foreach ($contentTypes as $type => $info): ?>
              <label style="cursor:pointer">
                <input type="radio" name="content_type" value="<?= $type ?>" style="display:none" <?= ($type==='video'?'checked':'') ?>>
                <div class="type-card" data-type="<?= $type ?>" style="padding:14px 10px;border:2px solid var(--border);border-radius:var(--radius-lg);text-align:center;transition:.2s;<?= $type==='video'?'border-color:var(--primary);background:rgba(99,102,241,.08)':'' ?>">
                  <i class="fas fa-<?= $info['icon'] ?>" style="font-size:22px;color:<?= $info['color'] ?>;display:block;margin-bottom:6px"></i>
                  <div style="font-size:11px;font-weight:700;color:var(--text)"><?= $info['label'] ?></div>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Basic info -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Informations</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Titre de la capsule <span class="required">*</span></label>
              <input type="text" name="title" class="form-control" placeholder="Ex: Introduction à JavaScript..." required maxlength="255" value="<?= $isEdit ? e($lesson['title']) : '' ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="3" placeholder="Description brève de la capsule..."><?= $isEdit ? e($lesson['description']) : '' ?></textarea>
            </div>
            <!-- Sélection en cascade : RNCP → Formation → Module → Compétence -->
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:16px">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);letter-spacing:.05em;margin-bottom:12px">Rattachement pédagogique</div>

              <div class="form-group" style="margin-bottom:10px">
                <label class="form-label" style="font-size:12px">1. Titre RNCP <span style="color:var(--text-faint)">(filtre optionnel)</span></label>
                <select id="filter-rncp" class="form-control" style="font-size:13px">
                  <option value="">— Tous les titres RNCP —</option>
                  <?php foreach ($rncp as $r): ?>
                  <option value="<?= $r['id'] ?>"><?= e($r['rncp_code']) ?> — <?= e($r['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group" style="margin-bottom:10px">
                <label class="form-label" style="font-size:12px">2. Formation <span style="color:var(--text-faint)">(filtre optionnel)</span></label>
                <select id="filter-formation" class="form-control" style="font-size:13px">
                  <option value="">— Toutes les formations —</option>
                  <?php foreach ($formations as $f): ?>
                  <option value="<?= $f['id'] ?>" data-rncp="<?= $f['rncp_title_id'] ?>"><?= e($f['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group" style="margin-bottom:10px">
                <label class="form-label" style="font-size:12px">3. Module <span class="required">*</span></label>
                <select name="module_id" class="form-control" required id="module-select" style="font-size:13px">
                  <option value="">— Sélectionner un module —</option>
                  <?php foreach ($allModules as $m): ?>
                  <option value="<?= $m['id'] ?>"
                    data-formation="<?= $m['formation_id'] ?>"
                    data-at="<?= (int)$m['activity_type_id'] ?>"
                    <?= ($isEdit && $lesson['module_id'] == $m['id']) ? 'selected' : '' ?>>
                    <?= e($m['title']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group" style="margin-bottom:0">
                <label class="form-label" style="font-size:12px">4. Compétence liée <span style="color:var(--text-faint)">(optionnel)</span></label>
                <select name="competency_id" class="form-control" id="competency-select" style="font-size:13px">
                  <option value="">— Aucune —</option>
                  <?php if ($isEdit && $lesson['competency_id']): ?>
                  <?php $compStmt = $pdo->prepare('SELECT id, code, title FROM competencies WHERE id = ?'); $compStmt->execute([$lesson['competency_id']]); $editComp = $compStmt->fetch(); ?>
                  <?php if ($editComp): ?><option value="<?= $editComp['id'] ?>" selected><?= e($editComp['code']) ?> — <?= e($editComp['title']) ?></option><?php endif; ?>
                  <?php endif; ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Content zone -->
        <div class="card" id="content-zone">
          <div class="card-header"><h3 class="card-title" id="content-title">Contenu vidéo</h3></div>
          <div class="card-body">
            <!-- Video URL -->
            <div id="zone-url">
              <div class="form-group">
                <label class="form-label">URL de la vidéo</label>
                <div class="input-group">
                  <i class="fas fa-link input-icon"></i>
                  <input type="url" name="content_url" id="content-url" class="form-control" placeholder="https://www.youtube.com/watch?v=...">
                </div>
                <div class="form-hint">YouTube, Vimeo, ou URL directe MP4</div>
              </div>
              <div class="form-group">
                <label class="form-label">ou téléverser un fichier</label>
                <input type="file" name="lesson_file" id="lesson-file" style="display:none">
                <div id="lesson-file-dropzone" style="display:flex;align-items:center;gap:12px;padding:20px;border:2px dashed var(--border);border-radius:var(--radius);cursor:pointer;transition:border-color .2s,background .2s">
                  <i class="fas fa-cloud-upload-alt" style="font-size:24px;color:var(--text-muted);flex-shrink:0"></i>
                  <div>
                    <div id="lesson-file-label" style="font-size:14px;font-weight:600">Cliquer ou glisser un fichier</div>
                    <div style="font-size:12px;color:var(--text-muted)">MP4, PDF, Word, Excel, PPT (max 50 Mo)</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Multi-PDF zone (PDF type uniquement) -->
            <?php
            $existingPdfs = null;
            if ($isEdit && $lesson['content_type'] === 'pdf' && $lesson['file_path']) {
                $decoded = json_decode($lesson['file_path'], true);
                $existingPdfs = is_array($decoded) ? $decoded
                    : [['path' => $lesson['file_path'], 'name' => pathinfo($lesson['file_path'], PATHINFO_FILENAME)]];
            }
            ?>
            <div id="zone-pdf-multi" style="display:none">
              <?php if ($existingPdfs): ?>
              <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.2);border-radius:var(--radius);padding:14px;margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--primary);letter-spacing:.05em;margin-bottom:10px">
                  <i class="fas fa-file-pdf" style="margin-right:5px"></i>PDFs actuellement en place
                </div>
                <?php foreach ($existingPdfs as $i => $ep): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:8px;background:var(--bg-elevated);border-radius:6px;margin-bottom:6px">
                  <span style="width:22px;height:22px;border-radius:5px;background:#ef4444;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:white;flex-shrink:0"><?= $i+1 ?></span>
                  <i class="fas fa-file-pdf" style="color:#ef4444;font-size:14px;flex-shrink:0"></i>
                  <span style="flex:1;font-size:13px;color:var(--text)"><?= e($ep['name']) ?></span>
                  <a href="<?= e(uploadUrl($ep['path'])) ?>" target="_blank" class="btn btn-ghost btn-sm" style="padding:2px 8px;font-size:11px"><i class="fas fa-eye"></i></a>
                </div>
                <?php endforeach; ?>
                <p style="font-size:12px;color:var(--text-muted);margin:8px 0 0">Uploadez de nouveaux fichiers ci-dessous pour remplacer l'ensemble des PDFs.</p>
              </div>
              <?php endif; ?>
              <div id="pdf-slots" style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px"></div>
              <button type="button" onclick="addPdfSlot()" class="btn btn-ghost btn-sm">
                <i class="fas fa-plus-circle" style="color:var(--primary);margin-right:6px"></i>Ajouter un PDF
              </button>
            </div>

            <!-- Text content -->
            <div id="zone-text" style="display:none">
              <div class="form-group">
                <label class="form-label">Contenu de la leçon</label>
                <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
                  <div style="padding:8px 12px;border-bottom:1px solid var(--border);display:flex;gap:6px;flex-wrap:wrap">
                    <?php foreach(['bold','italic','underline','|','list-ul','list-ol','|','link','image'] as $btn): ?>
                    <?php if($btn==='|'): ?><span style="width:1px;background:var(--border);margin:0 4px"></span><?php else: ?>
                    <button type="button" onclick="execFormat('<?= $btn ?>')" style="width:28px;height:28px;background:none;border:none;color:var(--text-muted);cursor:pointer;border-radius:4px" title="<?= $btn ?>"><i class="fas fa-<?= $btn ?>"></i></button>
                    <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                  <div id="text-editor" contenteditable="true" style="min-height:200px;padding:16px;outline:none;color:var(--text);font-size:15px;line-height:1.7"></div>
                </div>
                <textarea name="content_body" id="content-body-hidden" style="display:none"></textarea>
              </div>
            </div>
          </div>
        </div>

        <!-- Resources -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Ressources complémentaires</h3></div>
          <div class="card-body">
            <input type="file" name="resources[]" id="resources-input" multiple style="display:none" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx">
            <div id="resources-dropzone" style="border:2px dashed var(--border);border-radius:var(--radius);padding:24px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s">
              <i class="fas fa-paperclip" style="font-size:28px;color:var(--text-muted);margin-bottom:8px;display:block"></i>
              <div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:4px">Cliquer ou glisser des fichiers</div>
              <div style="font-size:12px;color:var(--text-muted)">PDF, Word, Excel, PowerPoint (plusieurs fichiers)</div>
            </div>
            <div id="resource-list" style="margin-top:12px;display:flex;flex-direction:column;gap:6px"></div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div style="display:flex;flex-direction:column;gap:16px;position:sticky;top:80px">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Publication</h3></div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Durée estimée (min)</label>
              <input type="number" name="duration_minutes" class="form-control" placeholder="15" min="1" value="<?= $isEdit ? ((int)$lesson['duration_minutes'] ?: '') : '' ?>">
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Points XP</label>
              <input type="number" name="xp_reward" class="form-control" value="<?= $isEdit ? (int)$lesson['xp_reward'] : 10 ?>" min="0" max="500">
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">N° d'ordre</label>
              <input type="number" name="order_num" class="form-control" value="<?= $isEdit ? (int)$lesson['order_num'] : 1 ?>" min="1">
            </div>
            <div style="display:flex;flex-direction:column;gap:10px">
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                <label class="toggle"><input type="checkbox" name="is_mandatory" <?= (!$isEdit || $lesson['is_mandatory']) ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
                <span style="font-size:13px">Capsule obligatoire</span>
              </label>
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                <label class="toggle"><input type="checkbox" name="is_preview" <?= ($isEdit && $lesson['is_preview']) ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
                <span style="font-size:13px">Prévisualisation libre</span>
              </label>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center">
              <i class="fas fa-save"></i> <?= $isEdit ? 'Enregistrer les modifications' : 'Créer la capsule' ?>
            </button>
            <?php if ($isEdit): ?>
            <a href="<?= url('student/course/view.php?id='.$lesson['id'].'&preview=1') ?>" target="_blank" class="btn btn-secondary w-full" style="justify-content:center">
              <i class="fas fa-eye"></i> Tester la capsule
            </a>
            <?php endif; ?>
            <a href="<?= url('teacher/courses/index.php') ?>" class="btn btn-ghost w-full" style="justify-content:center">Annuler</a>
          </div>
        </div>

        <!-- Thumbnail -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Vignette</h3></div>
          <div class="card-body">
            <input type="file" name="thumbnail" accept="image/*" style="display:none" id="thumb-input">
            <div id="thumb-dropzone" style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:20px;border:2px dashed var(--border);border-radius:var(--radius);cursor:pointer;transition:border-color .2s,background .2s">
              <div id="thumb-preview" style="width:80px;height:80px;border-radius:var(--radius);background:var(--bg-elevated);display:flex;align-items:center;justify-content:center;font-size:32px">🖼️</div>
              <span style="font-size:12px;color:var(--text-muted)">Image de couverture</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>


<script>
// Nettoyer les autosaves corrompus (contenant un token CSRF)
(function() {
  var key = 'autosave_new-lesson';
  try {
    var saved = JSON.parse(localStorage.getItem(key) || '{}');
    if (saved['<?= CSRF_TOKEN_NAME ?>']) {
      localStorage.removeItem(key);
    }
  } catch(e) { localStorage.removeItem(key); }
})();

var editData = <?= $isEdit ? json_encode(['type'=>$lesson['content_type'],'url'=>$lesson['content_url'],'body'=>$lesson['content_body']]) : 'null' ?>;

// Content type selection
const typeCards = document.querySelectorAll('.type-card');
const radios = document.querySelectorAll('[name="content_type"]');

typeCards.forEach(card => {
  card.addEventListener('click', () => {
    typeCards.forEach(c => { c.style.borderColor='var(--border)'; c.style.background=''; });
    card.style.borderColor = 'var(--primary)';
    card.style.background = 'rgba(99,102,241,.08)';
    updateContentZone(card.dataset.type);
  });
});

function updateContentZone(type) {
  const zoneUrl      = document.getElementById('zone-url');
  const zoneText     = document.getElementById('zone-text');
  const zonePdfMulti = document.getElementById('zone-pdf-multi');
  const title        = document.getElementById('content-title');
  const urlInput     = document.getElementById('content-url');
  const fileInput    = document.getElementById('lesson-file');
  const fileDropzone = document.getElementById('lesson-file-dropzone');

  const titles = {video:'Contenu vidéo',pdf:'Documents PDF',document:'Document',presentation:'Présentation',text:'Contenu HTML',link:'Lien externe',quiz:'Quiz intégré',exercise:'Fichier d\'exercice'};
  title.textContent = titles[type] || 'Contenu';

  if (type === 'text') {
    zoneUrl.style.display = 'none'; zoneText.style.display = 'block';
    if (zonePdfMulti) zonePdfMulti.style.display = 'none';
  } else if (type === 'pdf') {
    zoneUrl.style.display = 'none'; zoneText.style.display = 'none';
    if (zonePdfMulti) {
      zonePdfMulti.style.display = 'block';
      if (!document.querySelector('#pdf-slots .pdf-slot')) addPdfSlot();
    }
  } else if (type === 'link') {
    zoneUrl.style.display = 'block'; zoneText.style.display = 'none';
    if (zonePdfMulti) zonePdfMulti.style.display = 'none';
    urlInput.placeholder = 'https://exemple.com/ressource';
    fileDropzone.style.display = 'none';
  } else {
    zoneUrl.style.display = 'block'; zoneText.style.display = 'none';
    if (zonePdfMulti) zonePdfMulti.style.display = 'none';
    fileDropzone.style.display = 'flex';
    if (type === 'video') {
      urlInput.placeholder = 'https://www.youtube.com/...';
      fileInput.accept = 'video/*,.mp4,.webm';
    } else {
      urlInput.placeholder = '';
      fileInput.accept = '.doc,.docx,.xls,.xlsx,.ppt,.pptx';
    }
  }
}

// ── Multi-PDF slots ───────────────────────────────────────────
var _pdfSlotId = 0;
function addPdfSlot() {
  _pdfSlotId++;
  var id = _pdfSlotId;
  var slots = document.getElementById('pdf-slots');
  var num = slots.querySelectorAll('.pdf-slot').length + 1;
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
        onmouseover="this.style.borderColor='var(--primary)'"
        onmouseout="this.style.borderColor='var(--border)'">
        <i class="fas fa-file-pdf" style="color:#ef4444;margin-right:6px"></i>Cliquer pour sélectionner un PDF
      </div>
      <input type="text" name="pdf_names[]" placeholder="Nom du document (ex : Chapitre 1)" class="form-control" style="margin-top:6px;font-size:12px" id="pn-${id}">
    </div>
    <div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0;margin-top:2px">
      <button type="button" onclick="movePdfSlot('ps-${id}',-1)" class="btn btn-ghost btn-sm" style="padding:3px 8px" title="Remonter"><i class="fas fa-chevron-up"></i></button>
      <button type="button" onclick="movePdfSlot('ps-${id}',1)" class="btn btn-ghost btn-sm" style="padding:3px 8px" title="Descendre"><i class="fas fa-chevron-down"></i></button>
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
  var size = file.size >= 1048576 ? (file.size / 1048576).toFixed(1) + ' Mo' : Math.round(file.size / 1024) + ' Ko';
  label.innerHTML = '<i class="fas fa-check-circle" style="color:var(--success);margin-right:6px"></i><strong>' + file.name + '</strong> <span style="font-size:11px;color:var(--text-muted)">' + size + '</span>';
  label.style.borderColor = 'var(--success)';
  label.style.background = 'rgba(16,185,129,.05)';
  if (!nameIn.value) nameIn.value = file.name.replace(/\.pdf$/i, '');
}

function movePdfSlot(slotId, dir) {
  var slot = document.getElementById(slotId);
  var parent = slot.parentNode;
  var siblings = Array.from(parent.querySelectorAll('.pdf-slot'));
  var idx = siblings.indexOf(slot);
  var newIdx = idx + dir;
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
  document.querySelectorAll('#pdf-slots .pdf-slot').forEach(function(slot, i) {
    var n = slot.querySelector('.slot-num'); if (n) n.textContent = i + 1;
  });
}

// Rich text editor
const editor = document.getElementById('text-editor');
const hidden = document.getElementById('content-body-hidden');
if (editor) {
  editor.addEventListener('input', () => { hidden.value = editor.innerHTML; });
}

// Pré-remplir en mode édition
if (editData) {
  // Sélectionner le bon type
  typeCards.forEach(c => { c.style.borderColor='var(--border)'; c.style.background=''; });
  const activeCard = document.querySelector(`.type-card[data-type="${editData.type}"]`);
  if (activeCard) { activeCard.style.borderColor='var(--primary)'; activeCard.style.background='rgba(99,102,241,.08)'; }
  const activeRadio = document.querySelector(`[name="content_type"][value="${editData.type}"]`);
  if (activeRadio) activeRadio.checked = true;
  updateContentZone(editData.type);
  // Pré-remplir l'URL
  if (editData.url) { const u = document.getElementById('content-url'); if (u) u.value = editData.url; }
  // Pré-remplir le texte
  if (editData.body && editor) { editor.innerHTML = editData.body; hidden.value = editData.body; }
}
function execFormat(cmd) {
  if (cmd === 'list-ul') document.execCommand('insertUnorderedList');
  else if (cmd === 'list-ol') document.execCommand('insertOrderedList');
  else document.execCommand(cmd); editor.focus();
}

// ── Cascade RNCP → Formation → Module → Compétence ───────────
const rncpSel      = document.getElementById('filter-rncp');
const formationSel = document.getElementById('filter-formation');
const moduleSel    = document.getElementById('module-select');
const compSel      = document.getElementById('competency-select');

// Toutes les options mémorisées au chargement
const allFormationOpts = [...formationSel.options].slice(1).map(o => ({
  el: o, id: o.value, rncp: o.dataset.rncp, text: o.textContent
}));
const allModuleOpts = [...moduleSel.options].slice(1).map(o => ({
  el: o, id: o.value, formation: o.dataset.formation, at: o.dataset.at, text: o.textContent
}));

function filterFormations() {
  const rncpId = rncpSel.value;
  formationSel.innerHTML = '<option value="">— Toutes les formations —</option>';
  allFormationOpts.forEach(f => {
    if (!rncpId || f.rncp === rncpId) formationSel.appendChild(f.el);
  });
  filterModules();
}

function filterModules() {
  const formId = formationSel.value;
  const rncpId = rncpSel.value;
  const currentVal = moduleSel.value;
  moduleSel.innerHTML = '<option value="">— Sélectionner un module —</option>';
  allModuleOpts.forEach(m => {
    const matchFormation = !formId || m.formation === formId;
    // Si pas de filtre formation, filtrer quand même par RNCP via les formations visibles
    const visibleFormations = allFormationOpts.filter(f => !rncpId || f.rncp === rncpId).map(f => f.id);
    const matchRncp = !rncpId || visibleFormations.includes(m.formation);
    if (matchFormation && matchRncp) moduleSel.appendChild(m.el);
  });
  // Restaurer la sélection si l'option est encore présente
  if (currentVal) moduleSel.value = currentVal;
  loadCompetencies();
}

async function loadCompetencies() {
  const atId = moduleSel.options[moduleSel.selectedIndex]?.dataset?.at;
  compSel.innerHTML = '<option value="">— Aucune —</option>';
  if (!atId || atId === '0') return;
  try {
    const r = await fetch(`<?= url('api/data.php') ?>?action=competencies_by_activity_type&activity_type_id=${atId}`);
    const data = await r.json();
    data.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.id; opt.textContent = c.code + ' — ' + c.title;
      compSel.appendChild(opt);
    });
  } catch(e) {}
}

rncpSel.addEventListener('change', filterFormations);
formationSel.addEventListener('change', filterModules);
moduleSel.addEventListener('change', loadCompetencies);

// Initialiser en mode édition
<?php if ($isEdit): ?>
(async () => {
  // Retrouver la formation du module édité
  const modOpt = allModuleOpts.find(m => m.id === '<?= $lesson['module_id'] ?>');
  if (modOpt) {
    // Pré-sélectionner la formation dans le filtre
    formationSel.value = modOpt.formation;
    filterModules();
    moduleSel.value = '<?= $lesson['module_id'] ?>';
    await loadCompetencies();
    <?php if ($lesson['competency_id']): ?>
    compSel.value = '<?= $lesson['competency_id'] ?>';
    <?php endif; ?>
  }
})();
<?php else: ?>
filterFormations();
<?php endif; ?>

// ── Helpers upload ───────────────────────────────────────────
function makeDrop(dropzone, input, onFiles) {
  dropzone.addEventListener('click', () => input.click());
  dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.style.borderColor='var(--primary)'; dropzone.style.background='rgba(99,102,241,.06)'; });
  dropzone.addEventListener('dragleave', () => { dropzone.style.borderColor=''; dropzone.style.background=''; });
  dropzone.addEventListener('drop', e => {
    e.preventDefault(); dropzone.style.borderColor=''; dropzone.style.background='';
    const dt = new DataTransfer();
    [...(input.files||[]), ...e.dataTransfer.files].forEach(f => dt.items.add(f));
    input.files = dt.files;
    onFiles([...e.dataTransfer.files]);
  });
  input.addEventListener('change', () => onFiles([...input.files]));
}

// ── Fichier principal (lesson_file) ──────────────────────────
makeDrop(
  document.getElementById('lesson-file-dropzone'),
  document.getElementById('lesson-file'),
  files => {
    if (files.length) {
      document.getElementById('lesson-file-label').textContent = files[0].name;
      document.getElementById('lesson-file-dropzone').style.borderColor = 'var(--success)';
    }
  }
);

// ── Vignette ─────────────────────────────────────────────────
makeDrop(
  document.getElementById('thumb-dropzone'),
  document.getElementById('thumb-input'),
  files => {
    if (!files[0]) return;
    const r = new FileReader();
    r.onload = e => {
      document.getElementById('thumb-preview').innerHTML =
        `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;border-radius:var(--radius)">`;
    };
    r.readAsDataURL(files[0]);
  }
);

// ── Ressources complémentaires ───────────────────────────────
const resourceIcons = {pdf:'file-pdf',doc:'file-word',docx:'file-word',xls:'file-excel',xlsx:'file-excel',ppt:'file-powerpoint',pptx:'file-powerpoint'};
let selectedResources = []; // DataTransfer pour cumuler les fichiers

makeDrop(
  document.getElementById('resources-dropzone'),
  document.getElementById('resources-input'),
  files => renderResourceList(files)
);

function renderResourceList(newFiles) {
  // Fusionner avec la sélection existante
  const dt = new DataTransfer();
  selectedResources.forEach(f => dt.items.add(f));
  newFiles.forEach(f => { if (!selectedResources.find(e => e.name===f.name && e.size===f.size)) dt.items.add(f); });
  document.getElementById('resources-input').files = dt.files;
  selectedResources = [...dt.files];

  const list = document.getElementById('resource-list');
  list.innerHTML = '';
  if (!selectedResources.length) return;
  selectedResources.forEach((f, i) => {
    const ext = f.name.split('.').pop().toLowerCase();
    const icon = resourceIcons[ext] || 'file';
    const size = f.size > 1048576 ? (f.size/1048576).toFixed(1)+' Mo' : Math.round(f.size/1024)+' Ko';
    list.insertAdjacentHTML('beforeend', `
      <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--bg-elevated);border-radius:var(--radius);border:1px solid var(--border)">
        <i class="fas fa-${icon}" style="color:var(--text-muted);width:16px"></i>
        <span style="flex:1;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${f.name}</span>
        <span style="font-size:11px;color:var(--text-faint)">${size}</span>
        <button type="button" onclick="removeResource(${i})" style="background:none;border:none;color:var(--danger);cursor:pointer;padding:2px 6px"><i class="fas fa-times"></i></button>
      </div>
    `);
  });
}

function removeResource(idx) {
  selectedResources.splice(idx, 1);
  const dt = new DataTransfer();
  selectedResources.forEach(f => dt.items.add(f));
  document.getElementById('resources-input').files = dt.files;
  renderResourceList([]);
}

// Form submit — sync editor
document.getElementById('course-form').addEventListener('submit', () => {
  if (editor) hidden.value = editor.innerHTML;
});
</script>
<?php renderFooter(); ?>
