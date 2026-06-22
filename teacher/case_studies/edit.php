<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$editId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];

if (!$editId) {
    setFlash('error', 'Identifiant invalide.');
    redirect(url('teacher/case_studies/index.php'));
}

$stmt = $pdo->prepare('SELECT * FROM case_studies WHERE id = ?');
$stmt->execute([$editId]);
$csRow = $stmt->fetch();
if (!$csRow || (!isAdmin() && !isPedagogy() && $csRow['created_by'] != $userId)) {
    setFlash('error', 'Accès refusé ou étude introuvable.');
    redirect(url('teacher/case_studies/index.php'));
}

$csDefaults = [
    'id' => 0, 'title' => '', 'description' => '', 'file_type' => 'pdf',
    'file_path' => null, 'content_url' => '', 'formation_id' => null,
    'activity_type_id' => null, 'competency_id' => null,
    'module_id' => null, 'lesson_id' => null,
    'duration_minutes' => null, 'xp_reward' => 0,
];
$cs = array_merge($csDefaults, $csRow);

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $formationId = (int)($_POST['formation_id'] ?? 0) ?: null;
    $moduleId    = (int)($_POST['module_id'] ?? 0) ?: null;
    $lessonId    = (int)($_POST['lesson_id'] ?? 0) ?: null;
    $atId        = (int)($_POST['activity_type_id'] ?? 0) ?: null;
    $compId      = (int)($_POST['competency_id'] ?? 0) ?: null;
    $durationRaw = trim($_POST['duration_minutes'] ?? '');
    $duration    = $durationRaw !== '' ? max(0, (int)$durationRaw) : null;
    $xp          = max(0, (int)($_POST['xp_reward'] ?? 0));

    if (!$title) $errors[] = 'Le titre est obligatoire.';

    if (!$errors) {
        $pdo->prepare('
            UPDATE case_studies
            SET title=?, description=?, formation_id=?, module_id=?, lesson_id=?,
                activity_type_id=?, competency_id=?, duration_minutes=?, xp_reward=?, updated_at=NOW()
            WHERE id=?
        ')->execute([$title, $description, $formationId, $moduleId, $lessonId,
                     $atId, $compId, $duration, $xp, $editId]);

        // Suppression des ressources cochées
        foreach (array_map('intval', (array)($_POST['delete_resource_id'] ?? [])) as $did) {
            if ($did <= 0) continue;
            $r = $pdo->prepare('SELECT file_path FROM case_study_resources WHERE id=? AND case_study_id=?');
            $r->execute([$did, $editId]);
            $res = $r->fetch();
            if ($res && !empty($res['file_path'])) {
                $fp = UPLOADS_PATH . '/' . $res['file_path'];
                if (file_exists($fp)) @unlink($fp);
            }
            $pdo->prepare('DELETE FROM case_study_resources WHERE id=? AND case_study_id=?')
                ->execute([$did, $editId]);
        }

        // Nouveaux fichiers
        if (!empty($_FILES['new_res_files']['name'][0])) {
            $files  = $_FILES['new_res_files'];
            $titles = (array)($_POST['new_res_titles'] ?? []);
            for ($i = 0; $i < count($files['name']); $i++) {
                if (!$files['name'][$i] || $files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $singleFile = [
                    'name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i],
                    'size' => $files['size'][$i],  'error'    => $files['error'][$i],
                    'type' => $files['type'][$i],
                ];
                $path = uploadFile($singleFile, 'case_study_resources');
                if ($path) {
                    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $type = match(true) {
                        $ext === 'pdf'                                          => 'pdf',
                        in_array($ext, ['doc','docx'])                          => 'word',
                        in_array($ext, ['xls','xlsx'])                          => 'excel',
                        in_array($ext, ['ppt','pptx'])                          => 'powerpoint',
                        in_array($ext, ['mp4','webm','avi','mov'])              => 'video',
                        in_array($ext, ['jpg','jpeg','png','gif','webp','svg']) => 'image',
                        default                                                  => 'other',
                    };
                    $resTitle = trim($titles[$i] ?? '') ?: pathinfo($files['name'][$i], PATHINFO_FILENAME);
                    $pdo->prepare('INSERT INTO case_study_resources (case_study_id,title,type,file_path,file_size) VALUES (?,?,?,?,?)')
                        ->execute([$editId, $resTitle, $type, $path, $files['size'][$i]]);
                }
            }
        }

        // Nouveaux liens
        $linkTitles = (array)($_POST['new_res_link_titles'] ?? []);
        foreach ((array)($_POST['new_res_urls'] ?? []) as $j => $linkUrl) {
            $linkUrl = trim($linkUrl);
            if (!$linkUrl) continue;
            $pdo->prepare('INSERT INTO case_study_resources (case_study_id,title,type,url) VALUES (?,?,?,?)')
                ->execute([$editId, trim($linkTitles[$j] ?? '') ?: $linkUrl, 'link', $linkUrl]);
        }

        // Gestion des PDFs (réordonnancement, suppression, ajout de nouveaux)
        if ($cs['file_type'] === 'pdf') {
            $keptPaths = array_values(array_filter(array_map('trim', (array)($_POST['existing_pdf_paths'] ?? []))));
            $keptNames = array_values((array)($_POST['existing_pdf_names'] ?? []));

            // Supprimer les fichiers retirés
            $origDec   = json_decode($csRow['file_path'] ?? '', true);
            $origPaths = is_array($origDec) ? array_column($origDec, 'path') : ($csRow['file_path'] ? [$csRow['file_path']] : []);
            foreach ($origPaths as $origPath) {
                if ($origPath && !in_array($origPath, $keptPaths, true)) {
                    $fp = UPLOADS_PATH . '/' . $origPath;
                    if (file_exists($fp)) @unlink($fp);
                }
            }

            // Reconstruire le tableau dans l'ordre soumis
            $newPdfs = [];
            foreach ($keptPaths as $idx => $kpath) {
                $newPdfs[] = [
                    'path' => $kpath,
                    'name' => trim($keptNames[$idx] ?? '') ?: pathinfo($kpath, PATHINFO_FILENAME),
                ];
            }

            // Uploader les nouveaux PDFs
            if (!empty($_FILES['pdf_files']['name'][0])) {
                $pdfFiles = $_FILES['pdf_files'];
                $pdfNames = (array)($_POST['pdf_names'] ?? []);
                for ($i = 0; $i < count($pdfFiles['name']); $i++) {
                    if (!$pdfFiles['name'][$i] || $pdfFiles['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $singlePdf = [
                        'name'     => $pdfFiles['name'][$i],
                        'tmp_name' => $pdfFiles['tmp_name'][$i],
                        'size'     => $pdfFiles['size'][$i],
                        'error'    => $pdfFiles['error'][$i],
                        'type'     => $pdfFiles['type'][$i],
                    ];
                    $path = uploadFile($singlePdf, 'case_studies');
                    if ($path) {
                        $newPdfs[] = [
                            'path' => $path,
                            'name' => trim($pdfNames[$i] ?? '') ?: pathinfo($pdfFiles['name'][$i], PATHINFO_FILENAME),
                        ];
                    }
                }
            }

            $newFilePath = !empty($newPdfs) ? json_encode($newPdfs, JSON_UNESCAPED_UNICODE) : null;
            $pdo->prepare('UPDATE case_studies SET file_path=? WHERE id=?')->execute([$newFilePath, $editId]);
        }

        auditLog('case_study_updated', 'case_study', $editId);
        setFlash('success', 'Étude de cas mise à jour avec succès.');
        redirect(url('teacher/case_studies/edit.php?id=' . $editId));
    }

    $cs = array_merge($cs, [
        'title' => $title, 'description' => $description,
        'formation_id' => $formationId, 'module_id' => $moduleId, 'lesson_id' => $lessonId,
        'activity_type_id' => $atId, 'competency_id' => $compId,
        'duration_minutes' => $duration, 'xp_reward' => $xp,
    ]);
}

// ── Données de cascade ────────────────────────────────────────────────────────
$formations  = $pdo->query('SELECT id, title FROM formations ORDER BY title')->fetchAll();
$initModules = []; $initLessons = []; $initATs = []; $initComps = [];

if ($cs['formation_id']) {
    try {
        $s = $pdo->prepare('SELECT id, title FROM modules WHERE formation_id=? ORDER BY order_num, title');
        $s->execute([$cs['formation_id']]); $initModules = $s->fetchAll();
    } catch (PDOException $e) {}
    try {
        $s = $pdo->prepare('SELECT at.id, at.code, at.title FROM activity_types at INNER JOIN formations f ON f.rncp_title_id=at.rncp_title_id WHERE f.id=? ORDER BY at.order_num, at.code');
        $s->execute([$cs['formation_id']]); $initATs = $s->fetchAll();
    } catch (PDOException $e) {}
}
if ($cs['module_id']) {
    try {
        $s = $pdo->prepare('SELECT id, title FROM lessons WHERE module_id=? ORDER BY order_num, title');
        $s->execute([$cs['module_id']]); $initLessons = $s->fetchAll();
    } catch (PDOException $e) {}
}
if ($cs['activity_type_id']) {
    try {
        $s = $pdo->prepare('SELECT id, code, title FROM competencies WHERE activity_type_id=? ORDER BY order_num, code');
        $s->execute([$cs['activity_type_id']]); $initComps = $s->fetchAll();
    } catch (PDOException $e) {}
}

$existingResources = [];
try {
    $s = $pdo->prepare('SELECT * FROM case_study_resources WHERE case_study_id=? ORDER BY id');
    $s->execute([$editId]); $existingResources = $s->fetchAll();
} catch (PDOException $e) {}

$typeIcons = [
    'pdf'          => ['icon'=>'file-pdf',       'color'=>'#ef4444', 'label'=>'PDF'],
    'document'     => ['icon'=>'file-word',      'color'=>'#3b82f6', 'label'=>'Document'],
    'presentation' => ['icon'=>'file-powerpoint','color'=>'#f97316', 'label'=>'Présentation'],
    'video'        => ['icon'=>'play-circle',    'color'=>'#ef4444', 'label'=>'Vidéo'],
    'link'         => ['icon'=>'link',           'color'=>'#0ea5e9', 'label'=>'Lien'],
];
$ti = $typeIcons[$cs['file_type']] ?? $typeIcons['document'];

// Prépare les données du viewer principal
$pdfPages      = [];
$singleFileUrl = null;
if ($cs['file_type'] === 'pdf' && $cs['file_path']) {
    $dec = json_decode($cs['file_path'], true);
    if (is_array($dec)) {
        foreach ($dec as $p) {
            $pdfPages[] = ['path' => $p['path'], 'url' => uploadUrl($p['path']), 'name' => $p['name'] ?? pathinfo($p['path'], PATHINFO_FILENAME)];
        }
    } else {
        $pdfPages[] = ['path' => $cs['file_path'], 'url' => uploadUrl($cs['file_path']), 'name' => pathinfo($cs['file_path'], PATHINFO_FILENAME)];
    }
} elseif (in_array($cs['file_type'], ['document','presentation']) && $cs['file_path']) {
    $singleFileUrl = uploadUrl($cs['file_path']);
} elseif ($cs['file_type'] === 'video' && $cs['file_path']) {
    $singleFileUrl = uploadUrl($cs['file_path']);
}

// Embed URL pour viewer vidéo + URL viewer document
$embedUrl     = null;
$docViewerUrl = null;
if ($cs['file_type'] === 'video' && $cs['content_url']) {
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $cs['content_url'], $m))
        $embedUrl = 'https://www.youtube-nocookie.com/embed/' . $m[1];
    elseif (preg_match('/vimeo\.com\/(\d+)/', $cs['content_url'], $m))
        $embedUrl = 'https://player.vimeo.com/video/' . $m[1];
}
if (in_array($cs['file_type'], ['document','presentation']) && $singleFileUrl) {
    $ext2 = strtolower(pathinfo($cs['file_path'] ?? '', PATHINFO_EXTENSION));
    $docViewerUrl = in_array($ext2, ['doc','docx','xls','xlsx','ppt','pptx'])
        ? 'https://view.officeapps.live.com/op/embed.aspx?src=' . urlencode($singleFileUrl)
        : $singleFileUrl . '#toolbar=1';
}

$resIcons = [
    'pdf'        => ['icon'=>'file-pdf',        'color'=>'#ef4444'],
    'word'       => ['icon'=>'file-word',       'color'=>'#3b82f6'],
    'excel'      => ['icon'=>'file-excel',      'color'=>'#10b981'],
    'powerpoint' => ['icon'=>'file-powerpoint', 'color'=>'#f97316'],
    'video'      => ['icon'=>'play-circle',     'color'=>'#ef4444'],
    'image'      => ['icon'=>'image',           'color'=>'#a855f7'],
    'link'       => ['icon'=>'link',            'color'=>'#0ea5e9'],
    'other'      => ['icon'=>'file',            'color'=>'var(--text-muted)'],
];

function editCascadeSelect(string $name, string $id, array $items, ?int $selected, string $label, string $placeholder, ?callable $labelFn = null): void {
    echo '<div>';
    echo '<label class="form-label" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">' . htmlspecialchars($label) . '</label>';
    echo '<select name="' . htmlspecialchars($name) . '" id="sel-' . htmlspecialchars($id) . '" class="form-control"';
    if ($id === 'module') echo ' onchange="onModuleChange(this.value)"';
    if ($id === 'at')     echo ' onchange="onATChange(this.value)"';
    echo '>';
    if (empty($items)) {
        echo '<option value="" disabled selected style="color:var(--text-faint)">' . htmlspecialchars($placeholder) . '</option>';
    } else {
        echo '<option value="">— Aucun(e) —</option>';
        foreach ($items as $item) {
            $lbl = $labelFn ? $labelFn($item) : mb_substr($item['title'], 0, 50);
            $sel = ($item['id'] == $selected) ? ' selected' : '';
            echo '<option value="' . (int)$item['id'] . '"' . $sel . '>' . htmlspecialchars($lbl) . '</option>';
        }
    }
    echo '</select></div>';
}

renderHead('Modifier l\'étude de cas');
renderSidebar('teacher');
renderTopbar('Modifier l\'étude de cas', [
    ['Enseignant', url('teacher/index.php')],
    ['Études de cas', url('teacher/case_studies/index.php')],
    [mb_substr(e($cs['title']), 0, 40), ''],
]);
?>
<style>.page-content { overflow: visible !important; }</style>
<div class="page-content fade-in" style="overflow:visible">
  <?= renderFlash() ?>
  <?php foreach ($errors as $err): ?>
  <div class="alert alert-error" style="margin-bottom:16px"><i class="fas fa-times-circle"></i> <?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= $editId ?>">

    <div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start">

      <!-- Colonne principale -->
      <div style="display:flex;flex-direction:column;gap:20px">

        <!-- Informations -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">
              <div style="display:flex;align-items:center;gap:10px">
                <div style="width:32px;height:32px;border-radius:8px;background:<?= $ti['color'] ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <i class="fas fa-<?= $ti['icon'] ?>" style="color:<?= $ti['color'] ?>;font-size:14px"></i>
                </div>
                <span>Informations <span style="font-size:12px;font-weight:400;color:var(--text-muted)"><?= $ti['label'] ?></span></span>
              </div>
            </h3>
          </div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
            <div>
              <label class="form-label">Titre <span style="color:var(--danger)">*</span></label>
              <input type="text" name="title" class="form-control" value="<?= e($cs['title']) ?>" placeholder="Titre de l'étude de cas" required>
            </div>
            <div>
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="4" placeholder="Description courte de l'étude de cas..."><?= e($cs['description']) ?></textarea>
            </div>
          </div>
        </div>

        <!-- Fichier principal — gestion et prévisualisation -->
        <div class="card">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <h3 class="card-title">
              <i class="fas fa-<?= $ti['icon'] ?>" style="margin-right:8px;color:<?= $ti['color'] ?>"></i>
              <?= $ti['label'] ?><?php if ($cs['file_type'] === 'pdf'): ?> <span style="font-size:12px;font-weight:400;color:var(--text-muted)">(<?= count($pdfPages) ?> document<?= count($pdfPages) > 1 ? 's' : '' ?>)</span><?php endif; ?>
            </h3>
            <div style="display:flex;gap:8px;flex-shrink:0">
              <?php if ($cs['file_type'] === 'pdf' && !empty($pdfPages)): ?>
              <button type="button" onclick="deleteAllExistingPdfs()" class="btn btn-ghost btn-sm" style="color:var(--danger)">
                <i class="fas fa-trash"></i> Tout supprimer
              </button>
              <?php endif; ?>
              <?php if ($cs['file_type'] !== 'link'): ?>
              <button type="button" id="preview-btn" onclick="togglePreview()" class="btn btn-ghost btn-sm">
                <i class="fas fa-eye"></i> Prévisualiser
              </button>
              <?php endif; ?>
            </div>
          </div>

          <div class="card-body" style="display:flex;flex-direction:column;gap:12px">

            <?php if ($cs['file_type'] === 'pdf'): ?>

              <?php if (!empty($pdfPages)): ?>
              <div id="existing-pdf-list" style="display:flex;flex-direction:column;gap:8px">
                <?php foreach ($pdfPages as $i => $p): ?>
                <div class="epdf-row" data-url="<?= e($p['url']) ?>" data-name="<?= e($p['name']) ?>"
                  style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
                  <input type="hidden" name="existing_pdf_paths[]" value="<?= e($p['path']) ?>">
                  <span class="epdf-num" style="width:24px;height:24px;background:#ef4444;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:white;flex-shrink:0"><?= $i + 1 ?></span>
                  <input type="text" name="existing_pdf_names[]" value="<?= e($p['name']) ?>" class="form-control epdf-name-input"
                    style="flex:1;font-size:13px" placeholder="Nom du PDF">
                  <div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0">
                    <button type="button" onclick="movePdfRow(this,-1)" class="btn btn-ghost btn-sm" style="padding:3px 8px" title="Remonter"><i class="fas fa-chevron-up"></i></button>
                    <button type="button" onclick="movePdfRow(this,1)"  class="btn btn-ghost btn-sm" style="padding:3px 8px" title="Descendre"><i class="fas fa-chevron-down"></i></button>
                  </div>
                  <button type="button" onclick="deleteExistingPdf(this)" class="btn btn-ghost btn-sm" style="color:var(--danger);flex-shrink:0" title="Supprimer">
                    <i class="fas fa-trash"></i>
                  </button>
                </div>
                <?php endforeach; ?>
              </div>
              <?php else: ?>
              <div style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px">
                <i class="fas fa-file-pdf" style="font-size:32px;opacity:.3;margin-bottom:8px;display:block"></i>
                Aucun PDF actuellement.
              </div>
              <?php endif; ?>

              <div id="new-pdf-slots" style="display:flex;flex-direction:column;gap:8px"></div>

              <button type="button" onclick="addNewPdfSlot()" class="btn btn-ghost btn-sm" style="align-self:flex-start">
                <i class="fas fa-plus"></i> Ajouter un PDF
              </button>

              <div id="pdf-viewer" style="display:none;border-top:1px solid var(--border);padding-top:16px;margin-top:4px"></div>

            <?php elseif ($cs['file_type'] === 'video'): ?>
              <div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
                <i class="fas fa-play-circle" style="color:#ef4444;font-size:22px;flex-shrink:0"></i>
                <span style="flex:1;min-width:0;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <?= $cs['content_url'] ? e($cs['content_url']) : ($singleFileUrl ? e(pathinfo($cs['file_path'], PATHINFO_FILENAME)) : '—') ?>
                </span>
              </div>
              <div id="preview-viewer"
                data-type="video"
                data-embed="<?= e($embedUrl ?? '') ?>"
                data-direct="<?= e($singleFileUrl ?? '') ?>"
                style="display:none"></div>

            <?php elseif ($cs['file_type'] === 'link' && $cs['content_url']): ?>
              <div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
                <i class="fas fa-link" style="color:#0ea5e9;font-size:20px;flex-shrink:0"></i>
                <span style="flex:1;min-width:0;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($cs['content_url']) ?></span>
                <a href="<?= e($cs['content_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm">
                  <i class="fas fa-external-link-alt"></i>
                </a>
              </div>

            <?php elseif ($singleFileUrl): ?>
              <div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
                <i class="fas fa-<?= $ti['icon'] ?>" style="color:<?= $ti['color'] ?>;font-size:20px;flex-shrink:0"></i>
                <span style="flex:1;min-width:0;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(pathinfo($cs['file_path'], PATHINFO_FILENAME)) ?></span>
                <a href="<?= e($singleFileUrl) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Télécharger"><i class="fas fa-download"></i></a>
              </div>
              <div id="preview-viewer"
                data-type="doc"
                data-url="<?= e($docViewerUrl ?? '') ?>"
                style="display:none"></div>

            <?php else: ?>
              <div style="text-align:center;padding:40px;color:var(--text-muted)">
                <i class="fas fa-file" style="font-size:40px;margin-bottom:16px;display:block;opacity:.3"></i>
                <p>Aucun fichier disponible.</p>
              </div>
            <?php endif; ?>

          </div>
        </div>

        <!-- Ressources complémentaires -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-paperclip" style="margin-right:8px;color:var(--primary-light)"></i>Ressources complémentaires</h3>
          </div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:20px">

            <?php if (!empty($existingResources)): ?>
            <div>
              <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:10px">Ressources actuelles</div>
              <div style="display:flex;flex-direction:column;gap:8px">
                <?php foreach ($existingResources as $res):
                    $ri   = $resIcons[$res['type']] ?? $resIcons['other'];
                    $href = !empty($res['url']) ? $res['url'] : (!empty($res['file_path']) ? uploadUrl($res['file_path']) : '#');
                ?>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
                  <i class="fas fa-<?= $ri['icon'] ?>" style="color:<?= $ri['color'] ?>;font-size:15px;flex-shrink:0;width:18px;text-align:center"></i>
                  <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($res['title']) ?></div>
                    <?php if (!empty($res['file_size'])): ?>
                    <div style="font-size:11px;color:var(--text-faint)"><?= formatFileSize($res['file_size']) ?></div>
                    <?php endif; ?>
                  </div>
                  <a href="<?= e($href) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Ouvrir"><i class="fas fa-external-link-alt"></i></a>
                  <label style="display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap;font-size:12px;color:var(--danger)">
                    <input type="checkbox" name="delete_resource_id[]" value="<?= (int)$res['id'] ?>" style="accent-color:var(--danger)">
                    Supprimer
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div style="border-top:1px solid var(--border)"></div>
            <?php endif; ?>

            <!-- Ajouter fichiers -->
            <div>
              <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:10px">Ajouter des fichiers</div>
              <div id="res-dropzone"
                style="border:2px dashed var(--border);border-radius:var(--radius);padding:24px;text-align:center;cursor:pointer;transition:.2s"
                onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'"
                onclick="document.getElementById('new-res-input').click()">
                <i class="fas fa-cloud-upload-alt" style="font-size:28px;color:var(--text-faint);margin-bottom:10px;display:block"></i>
                <div style="font-size:13px;color:var(--text-muted)">Cliquer ou glisser-déposer des fichiers</div>
                <div style="font-size:11px;color:var(--text-faint);margin-top:4px">PDF, Word, Excel, PowerPoint, vidéo…</div>
              </div>
              <input type="file" id="new-res-input" name="new_res_files[]" multiple style="display:none">
              <div id="new-res-list" style="display:flex;flex-direction:column;gap:8px;margin-top:10px"></div>
              <div id="new-res-titles-container" style="display:none"></div>
            </div>

            <!-- Ajouter liens -->
            <div>
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Ajouter des liens web</div>
                <button type="button" onclick="addLinkRow()" class="btn btn-ghost btn-sm">
                  <i class="fas fa-plus"></i> Ajouter un lien
                </button>
              </div>
              <div id="link-rows" style="display:flex;flex-direction:column;gap:8px"></div>
            </div>

          </div>
        </div>

      </div><!-- /Colonne principale -->

      <!-- Sidebar -->
      <div style="display:flex;flex-direction:column;gap:16px">

        <!-- Publication + Paramètres — sticky -->
        <div style="position:sticky;top:80px">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Publication</h3></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
              <button type="submit" class="btn btn-primary w-full" style="justify-content:center">
                <i class="fas fa-save"></i> Enregistrer
              </button>
              <a href="<?= url('student/case_studies/view.php?id='.$editId) ?>" target="_blank"
                class="btn btn-secondary w-full" style="justify-content:center">
                <i class="fas fa-eye"></i> Prévisualiser
              </a>
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

        <!-- Rattachements — flux normal, accessible par scroll -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-sitemap" style="margin-right:7px;color:var(--primary-light)"></i>Rattachements</h3>
          </div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
            <div>
              <label class="form-label" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Formation</label>
              <select name="formation_id" id="sel-formation" class="form-control" style="font-size:12px" onchange="onFormationChange(this.value)">
                <option value="">— Aucune —</option>
                <?php foreach ($formations as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $cs['formation_id'] == $f['id'] ? 'selected' : '' ?>><?= e(mb_substr($f['title'],0,38)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php editCascadeSelect('module_id','module',$initModules,$cs['module_id'],'Module de formation','← sélectionner une formation d\'abord'); ?>
            <?php editCascadeSelect('lesson_id','lesson',$initLessons,$cs['lesson_id'],'Capsule de cours','← sélectionner un module d\'abord'); ?>
            <div style="border-top:1px solid var(--border);margin:2px -16px"></div>
            <?php editCascadeSelect('activity_type_id','at',$initATs,$cs['activity_type_id'],'Bloc / Activité type','← sélectionner une formation d\'abord',function($i){ return $i['code'] . ' — ' . mb_substr($i['title'],0,30); }); ?>
            <?php editCascadeSelect('competency_id','competency',$initComps,$cs['competency_id'],'Compétence','← sélectionner un bloc d\'abord',function($i){ return $i['code'] . ' — ' . mb_substr($i['title'],0,30); }); ?>
          </div>
        </div>

      </div>

    </div>
  </form>
</div>

<script>
var AJAX_URL    = '<?= url('teacher/case_studies/ajax.php') ?>';
var _newResFiles = [];
var _linkRowIdx  = 0;

(function() {
  var dz  = document.getElementById('res-dropzone');
  var inp = document.getElementById('new-res-input');
  if (!dz || !inp) return;
  inp.addEventListener('change', function() { addResFiles(Array.from(inp.files)); });
  dz.addEventListener('dragover',  function(e){ e.preventDefault(); dz.style.borderColor='var(--primary)'; });
  dz.addEventListener('dragleave', function()  { dz.style.borderColor='var(--border)'; });
  dz.addEventListener('drop', function(e) {
    e.preventDefault(); dz.style.borderColor='var(--border)';
    addResFiles(Array.from(e.dataTransfer.files));
  });
})();

const RES_ICONS  = { pdf:'file-pdf',doc:'file-word',docx:'file-word',xls:'file-excel',xlsx:'file-excel',ppt:'file-powerpoint',pptx:'file-powerpoint',mp4:'play-circle',webm:'play-circle',jpg:'image',jpeg:'image',png:'image',gif:'image',webp:'image',svg:'image' };
const RES_COLORS = { 'file-pdf':'#ef4444','file-word':'#3b82f6','file-excel':'#10b981','file-powerpoint':'#f97316','play-circle':'#ef4444','image':'#a855f7' };

function addResFiles(files) {
  files.forEach(function(f) {
    if (!_newResFiles.find(function(e){ return e.name===f.name && e.size===f.size; })) _newResFiles.push(f);
  });
  syncResInput(); renderResList();
}
function syncResInput() {
  var inp = document.getElementById('new-res-input');
  var dt  = new DataTransfer();
  _newResFiles.forEach(function(f){ dt.items.add(f); });
  inp.files = dt.files;
}
function renderResList() {
  var list   = document.getElementById('new-res-list');
  var titCon = document.getElementById('new-res-titles-container');
  list.innerHTML = ''; titCon.innerHTML = '';
  _newResFiles.forEach(function(f, i) {
    var ext  = f.name.split('.').pop().toLowerCase();
    var icon = RES_ICONS[ext] || 'file';
    var col  = RES_COLORS[icon] || 'var(--text-muted)';
    var size = f.size >= 1048576 ? (f.size/1048576).toFixed(1)+' Mo' : Math.round(f.size/1024)+' Ko';
    var def  = f.name.replace(/\.[^.]+$/, '');
    var tid  = 'nrt-' + i;
    titCon.insertAdjacentHTML('beforeend', '<input type="text" name="new_res_titles[]" id="' + tid + '" value="' + def.replace(/"/g,'&quot;') + '">');
    list.insertAdjacentHTML('beforeend', `
      <div style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
        <i class="fas fa-${icon}" style="color:${col};font-size:14px;flex-shrink:0;width:16px"></i>
        <input type="text" value="${def.replace(/"/g,'&quot;')}" placeholder="Titre du document" class="form-control"
          style="flex:1;font-size:12px;padding:4px 8px" oninput="document.getElementById('${tid}').value=this.value">
        <span style="font-size:11px;color:var(--text-faint);flex-shrink:0">${size}</span>
        <button type="button" onclick="removeResFile(${i})" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:3px 8px;flex-shrink:0"><i class="fas fa-times"></i></button>
      </div>`);
  });
}
function removeResFile(idx) { _newResFiles.splice(idx,1); syncResInput(); renderResList(); }

function addLinkRow() {
  _linkRowIdx++;
  var idx = _linkRowIdx;
  document.getElementById('link-rows').insertAdjacentHTML('beforeend', `
    <div id="lr-${idx}" style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
      <i class="fas fa-link" style="color:#0ea5e9;flex-shrink:0;width:16px"></i>
      <div style="flex:1;display:flex;flex-direction:column;gap:5px">
        <input type="text" name="new_res_link_titles[]" placeholder="Titre de la ressource" class="form-control" style="font-size:12px;padding:4px 8px">
        <input type="url"  name="new_res_urls[]" placeholder="https://..." class="form-control" style="font-size:12px;padding:4px 8px">
      </div>
      <button type="button" onclick="document.getElementById('lr-${idx}').remove()" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:3px 8px;flex-shrink:0"><i class="fas fa-times"></i></button>
    </div>`);
}

// ── Gestion PDF existants ──────────────────────────────────────────────────────
function movePdfRow(btn, dir) {
  var row  = btn.closest('.epdf-row');
  var list = document.getElementById('existing-pdf-list');
  if (!list) return;
  var rows = Array.from(list.querySelectorAll('.epdf-row'));
  var idx  = rows.indexOf(row), newIdx = idx + dir;
  if (newIdx < 0 || newIdx >= rows.length) return;
  if (dir === -1) list.insertBefore(row, rows[newIdx]);
  else list.insertBefore(rows[newIdx], row);
  updateExistingPdfNumbers();
}
function updateExistingPdfNumbers() {
  document.querySelectorAll('#existing-pdf-list .epdf-row').forEach(function(row, i) {
    var badge = row.querySelector('.epdf-num');
    if (badge) badge.textContent = i + 1;
  });
}
function deleteExistingPdf(btn) {
  if (!confirm('Supprimer ce PDF de l\'étude de cas ?')) return;
  btn.closest('.epdf-row').remove();
  updateExistingPdfNumbers();
}
function deleteAllExistingPdfs() {
  var count = document.querySelectorAll('#existing-pdf-list .epdf-row').length;
  if (!count || !confirm('Supprimer les ' + count + ' PDF(s) existant(s) ?')) return;
  document.querySelectorAll('#existing-pdf-list .epdf-row').forEach(function(r){ r.remove(); });
}

// ── Ajout de nouveaux PDFs ────────────────────────────────────────────────────
var _newPdfIdx = 0;
function addNewPdfSlot() {
  _newPdfIdx++;
  var idx = _newPdfIdx;
  document.getElementById('new-pdf-slots').insertAdjacentHTML('beforeend',
    '<div class="npdf-slot" id="npdf-' + idx + '" style="display:flex;align-items:flex-start;gap:10px;padding:12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">' +
      '<div style="flex:1;min-width:0">' +
        '<input type="file" name="pdf_files[]" accept=".pdf,application/pdf" style="display:none" id="npf-' + idx + '" onchange="onNewPdfSelected(this,' + idx + ')">' +
        '<div id="npl-' + idx + '" onclick="document.getElementById(\'npf-' + idx + '\').click()" style="font-size:13px;color:var(--text-muted);cursor:pointer;padding:10px 14px;border:2px dashed var(--border);border-radius:6px;text-align:center;transition:.2s" onmouseover="this.style.borderColor=\'var(--primary)\'" onmouseout="this.style.borderColor=\'var(--border)\'">' +
          '<i class="fas fa-file-pdf" style="color:#ef4444;margin-right:6px"></i>Cliquer pour sélectionner un PDF' +
        '</div>' +
        '<input type="text" name="pdf_names[]" placeholder="Nom du document (optionnel)" class="form-control" style="margin-top:6px;font-size:12px" id="npn-' + idx + '">' +
      '</div>' +
      '<button type="button" onclick="document.getElementById(\'npdf-' + idx + '\').remove()" class="btn btn-ghost btn-sm" style="color:var(--danger);flex-shrink:0;margin-top:2px" title="Retirer"><i class="fas fa-trash"></i></button>' +
    '</div>'
  );
}
function onNewPdfSelected(input, idx) {
  var file = input.files[0]; if (!file) return;
  var label = document.getElementById('npl-' + idx);
  var nameIn = document.getElementById('npn-' + idx);
  var size = file.size >= 1048576 ? (file.size/1048576).toFixed(1)+' Mo' : Math.round(file.size/1024)+' Ko';
  label.innerHTML = '<i class="fas fa-check-circle" style="color:var(--success);margin-right:6px"></i><strong>' + file.name + '</strong> <span style="font-size:11px;color:var(--text-muted)">' + size + '</span>';
  label.style.borderColor = 'var(--success)';
  label.style.background  = 'rgba(16,185,129,.05)';
  if (!nameIn.value) nameIn.value = file.name.replace(/\.pdf$/i, '');
}

// ── Prévisualisation ──────────────────────────────────────────────────────────
var _previewActive = false;
function togglePreview() {
  var btn = document.getElementById('preview-btn');
  var pdfViewer = document.getElementById('pdf-viewer');
  var genericViewer = document.getElementById('preview-viewer');
  if (!_previewActive) {
    if (pdfViewer)     { buildPdfIframes(pdfViewer); pdfViewer.style.display = 'block'; }
    if (genericViewer) { buildGenericViewer(genericViewer); genericViewer.style.display = 'block'; }
  } else {
    if (pdfViewer)     { pdfViewer.innerHTML = ''; pdfViewer.style.display = 'none'; }
    if (genericViewer) { genericViewer.innerHTML = ''; genericViewer.style.display = 'none'; }
  }
  _previewActive = !_previewActive;
  if (btn) btn.innerHTML = _previewActive
    ? '<i class="fas fa-eye-slash"></i> Fermer'
    : '<i class="fas fa-eye"></i> Prévisualiser';
}
function buildPdfIframes(viewer) {
  var rows = document.querySelectorAll('#existing-pdf-list .epdf-row');
  if (!rows.length) {
    viewer.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:16px">Aucun PDF à prévisualiser.</p>';
    return;
  }
  var html = '';
  rows.forEach(function(row, i) {
    var url  = row.dataset.url;
    var name = row.querySelector('.epdf-name-input').value || row.dataset.name || ('PDF ' + (i + 1));
    html += '<div style="' + (i > 0 ? 'border-top:1px solid var(--border);margin-top:16px;padding-top:16px' : '') + '">';
    if (rows.length > 1) {
      html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">';
      html += '<span style="width:22px;height:22px;background:#ef4444;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:white;flex-shrink:0">' + (i + 1) + '</span>';
      html += '<span style="font-size:13px;font-weight:600;flex:1">' + name.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</span>';
      html += '<a href="' + url + '" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-external-link-alt"></i></a>';
      html += '</div>';
    }
    html += '<div style="height:700px;border-radius:var(--radius);overflow:hidden;border:1px solid var(--border)">';
    html += '<iframe src="' + url + '#toolbar=1&navpanes=1" style="width:100%;height:100%;border:none"></iframe>';
    html += '</div></div>';
  });
  viewer.innerHTML = html;
}
function buildGenericViewer(viewer) {
  var type   = viewer.dataset.type;
  var embed  = viewer.dataset.embed;
  var direct = viewer.dataset.direct;
  var url    = viewer.dataset.url;
  if (type === 'video') {
    if (embed) {
      viewer.innerHTML = '<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:var(--radius)"><iframe src="' + embed + '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none" allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen></iframe></div>';
    } else if (direct) {
      viewer.innerHTML = '<video controls style="width:100%;border-radius:var(--radius);background:#000"><source src="' + direct + '"></video>';
    }
  } else if (type === 'doc') {
    viewer.innerHTML = '<div style="height:700px;border-radius:var(--radius);overflow:hidden;border:1px solid var(--border)"><iframe src="' + url + '" style="width:100%;height:100%;border:none"></iframe></div>';
  }
}

function resetSel(selId, placeholder) {
  var sel = document.getElementById(selId); if (!sel) return;
  while (sel.options.length) sel.remove(0);
  var opt = new Option(placeholder, ''); opt.disabled = true; opt.selected = true; sel.add(opt);
}
function populateSel(selId, items, labelFn) {
  var sel = document.getElementById(selId); if (!sel) return;
  while (sel.options.length) sel.remove(0);
  sel.add(new Option('— Aucun(e) —', ''));
  items.forEach(function(item) { sel.add(new Option(labelFn ? labelFn(item) : item.title, item.id)); });
}

async function onFormationChange(fid) {
  resetSel('sel-module','← sélectionner une formation d\'abord');
  resetSel('sel-lesson','← sélectionner un module d\'abord');
  resetSel('sel-at','← sélectionner une formation d\'abord');
  resetSel('sel-competency','← sélectionner un bloc d\'abord');
  if (!fid) return;
  try {
    var [mods, ats] = await Promise.all([
      fetch(AJAX_URL+'?action=modules&formation_id='+fid).then(function(r){return r.json();}),
      fetch(AJAX_URL+'?action=activity_types&formation_id='+fid).then(function(r){return r.json();})
    ]);
    if (mods.length) populateSel('sel-module', mods);
    if (ats.length)  populateSel('sel-at', ats, function(i){ return i.code+' — '+i.title; });
  } catch(e) {}
}
async function onModuleChange(mid) {
  resetSel('sel-lesson','← sélectionner un module d\'abord');
  if (!mid) return;
  try {
    var lessons = await fetch(AJAX_URL+'?action=lessons&module_id='+mid).then(function(r){return r.json();});
    if (lessons.length) populateSel('sel-lesson', lessons);
  } catch(e) {}
}
async function onATChange(atid) {
  resetSel('sel-competency','← sélectionner un bloc d\'abord');
  if (!atid) return;
  try {
    var comps = await fetch(AJAX_URL+'?action=competencies&activity_type_id='+atid).then(function(r){return r.json();});
    if (comps.length) populateSel('sel-competency', comps, function(i){ return i.code+' — '+i.title; });
  } catch(e) {}
}
</script>
<?php renderFooter(); ?>
