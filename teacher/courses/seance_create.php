<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo     = getDB();
$userId  = (int)$_SESSION['user_id'];
$editId  = (int)($_GET['id'] ?? 0);
$ownerOnly = !isAdmin() && !isPedagogy();

// Charger la séance à éditer
$seance = null;
if ($editId) {
    $sql = "
        SELECT m.*,
               seq.competency_id AS seq_comp_id, seq.title AS seq_title,
               c.activity_type_id AS at_id
        FROM modules m
        LEFT JOIN sequences seq ON m.sequence_id = seq.id
        LEFT JOIN competencies c ON seq.competency_id = c.id
        WHERE m.id = ?" . ($ownerOnly ? " AND m.created_by = ?" : "");
    $s = $pdo->prepare($sql);
    $s->execute($ownerOnly ? [$editId, $userId] : [$editId]);
    $seance = $s->fetch();
    if (!$seance) { setFlash('error', 'Séance introuvable ou accès refusé.'); redirect(url('teacher/courses/index.php')); }
}
$isEdit = $seance !== null;

// ── POST ──────────────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $competencyId = (int)($_POST['competency_id'] ?? 0);
    $seqMode      = $_POST['seq_mode'] ?? 'existing';   // 'existing' | 'new'
    $seqId        = (int)($_POST['sequence_id']    ?? 0);
    $seqTitle     = trim($_POST['sequence_title']  ?? '');
    $seqDesc      = trim($_POST['sequence_desc']   ?? '');
    $title        = trim($_POST['title']           ?? '');
    $description  = trim($_POST['description']     ?? '');
    $durationHrs  = floatval($_POST['duration_hours'] ?? 0);
    $formationId  = (int)($_POST['formation_id']   ?? 0) ?: null;
    $contentType  = $_POST['content_type'] ?? 'text';
    $contentUrl   = trim($_POST['content_url'] ?? '');
    $contentBody  = sanitizeRichHtml(trim($_POST['content_body'] ?? ''));
    $xpReward     = (int)($_POST['xp_reward'] ?? 10);
    $isMandatory  = isset($_POST['is_mandatory']) ? 1 : 0;
    $isPreview    = isset($_POST['is_preview']) ? 1 : 0;
    $orderNumIn   = (int)($_POST['order_num'] ?? 0);

    if (!$competencyId)          $errors[] = 'La compétence est obligatoire.';
    if ($seqMode === 'new' && !$seqTitle) $errors[] = 'Le titre de la séquence est obligatoire.';
    if ($seqMode === 'existing' && !$seqId) $errors[] = 'Sélectionnez une séquence ou créez-en une nouvelle.';
    if (!$title)                 $errors[] = 'Le titre de la séance est obligatoire.';
    if (!$contentType)           $errors[] = 'Le type de contenu est obligatoire.';

    // ── Fichiers (sauf pour le type "quiz", qui n'a pas de contenu propre) ──
    $filePath  = $seance['file_path']  ?? null;
    $thumbnail = $seance['thumbnail']  ?? null;

    if ($contentType === 'pdf') {
        $pdfEntries = [];
        foreach (($_POST['existing_pdf_paths'] ?? []) as $idx => $path) {
            $path = trim($path);
            if (!$path) continue;
            $name = trim($_POST['existing_pdf_names'][$idx] ?? '') ?: pathinfo($path, PATHINFO_FILENAME);
            $pdfEntries[] = ['path' => $path, 'name' => $name];
        }
        foreach (($_FILES['pdf_files']['name'] ?? []) as $i => $pdfName) {
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
                $errors[] = 'Nouveau PDF n°' . ($i + 1) . ' : ' . $upload['error'];
            }
        }
        $filePath = !empty($pdfEntries) ? json_encode($pdfEntries, JSON_UNESCAPED_UNICODE) : null;
    } elseif (!in_array($contentType, ['quiz', 'text', 'link'], true) && !empty($_FILES['seance_file']['name'])) {
        $upload = uploadFile($_FILES['seance_file'], 'courses', ALLOWED_DOC_TYPES, MAX_UPLOAD_SIZE);
        if ($upload['success']) $filePath = $upload['path'];
        else $errors[] = 'Fichier : ' . $upload['error'];
    }

    if (!empty($_FILES['thumbnail']['name'])) {
        $upload = uploadFile($_FILES['thumbnail'], 'courses/thumbs', ALLOWED_IMAGE_TYPES);
        if ($upload['success']) $thumbnail = $upload['path'];
    }

    if (!$errors) {
        // Créer la séquence si besoin
        if ($seqMode === 'new') {
            $maxOrd = $pdo->prepare("SELECT COALESCE(MAX(order_num),0)+1 FROM sequences WHERE competency_id = ?");
            $maxOrd->execute([$competencyId]);
            $seqOrderNum = (int)$maxOrd->fetchColumn();

            $ins = $pdo->prepare("
                INSERT INTO sequences (formation_id, competency_id, title, description, order_num, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$formationId, $competencyId, $seqTitle, $seqDesc ?: null, $seqOrderNum, $userId]);
            $seqId = (int)$pdo->lastInsertId();
        } else {
            $check = $pdo->prepare("SELECT id FROM sequences WHERE id = ? AND competency_id = ?");
            $check->execute([$seqId, $competencyId]);
            if (!$check->fetch()) {
                $errors[] = 'La séquence ne correspond pas à la compétence sélectionnée.';
            }
        }
    }

    if (!$errors) {
        // Auto-hériter formation_id depuis la séquence si le prof n'en a pas sélectionné
        if (!$formationId && $seqId) {
            $sf = $pdo->prepare("SELECT formation_id FROM sequences WHERE id = ?");
            $sf->execute([$seqId]);
            $formationId = $sf->fetchColumn() ?: null;
        }

        if ($editId) {
            $pdo->prepare("
                UPDATE modules SET formation_id=?, sequence_id=?, title=?, description=?, duration_hours=?,
                    content_type=?, content_url=?, content_body=?, file_path=?, thumbnail=?,
                    xp_reward=?, is_mandatory=?, is_preview=?
                WHERE id=?
            ")->execute([
                $formationId, $seqId, $title, $description ?: null, $durationHrs ?: null,
                $contentType, $contentUrl ?: null, $contentBody ?: null, $filePath, $thumbnail,
                $xpReward, $isMandatory, $isPreview, $editId,
            ]);
            auditLog('module_updated', 'module', $editId);
            setFlash('success', 'Séance mise à jour.');
            $savedId = $editId;
        } else {
            $maxOrd = $pdo->prepare("SELECT COALESCE(MAX(order_num),0)+1 FROM modules WHERE sequence_id = ?");
            $maxOrd->execute([$seqId]);
            $orderNum = $orderNumIn ?: (int)$maxOrd->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO modules (formation_id, sequence_id, title, description, duration_hours, order_num,
                    content_type, content_url, content_body, file_path, thumbnail,
                    xp_reward, is_mandatory, is_preview, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $formationId, $seqId, $title, $description ?: null, $durationHrs ?: null, $orderNum,
                $contentType, $contentUrl ?: null, $contentBody ?: null, $filePath, $thumbnail,
                $xpReward, $isMandatory, $isPreview, $userId,
            ]);
            $savedId = (int)$pdo->lastInsertId();

            // Ressources complémentaires
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
                        $pdo->prepare('INSERT INTO module_resources (module_id,title,type,file_path,file_size) VALUES (?,?,?,?,?)')
                            ->execute([$savedId, pathinfo($rName,PATHINFO_FILENAME), $rType, $rUpload['path'], $rFile['size']]);
                    }
                }
            }
            auditLog('module_created', 'module', $savedId);
            setFlash('success', 'Séance créée avec succès.');
        }

        // Type "quiz" : construire les questions juste après — seulement si aucun quiz n'existe encore
        if ($contentType === 'quiz') {
            $hasQuiz = $pdo->prepare("SELECT id FROM quizzes WHERE module_id = ?");
            $hasQuiz->execute([$savedId]);
            if (!$hasQuiz->fetch()) {
                redirect(url('teacher/quizzes/create.php?module_id=' . $savedId . '&seance_title=' . urlencode($title)));
            }
        }
        redirect(url('teacher/courses/index.php?highlight=' . $savedId));
    }
}

if ($errors) { foreach ($errors as $e) setFlash('error', $e); }

// ── Données pour cascade JS ───────────────────────────────────────────────────
$allRncp = $pdo->query("SELECT id, rncp_code, title FROM rncp_titles WHERE status='active' ORDER BY rncp_code")->fetchAll();
$allAT   = $pdo->query("SELECT id, rncp_title_id, code, title FROM activity_types ORDER BY rncp_title_id, order_num")->fetchAll();
$allComp = $pdo->query("SELECT id, activity_type_id, code, title FROM competencies ORDER BY activity_type_id, order_num")->fetchAll();
$allSeq  = $pdo->query("
    SELECT s.id, s.competency_id, s.title, s.formation_id, s.created_by
    FROM sequences s
    ORDER BY s.competency_id, s.order_num
")->fetchAll();

// Séances existantes groupées par sequence_id (pour l'affichage informatif)
$seancesPerSeqRaw = $pdo->query("
    SELECT id, sequence_id, title, duration_hours, order_num, content_type
    FROM modules WHERE sequence_id IS NOT NULL ORDER BY sequence_id, order_num
")->fetchAll();
$seancesPerSeq = [];
foreach ($seancesPerSeqRaw as $m) {
    $seancesPerSeq[(int)$m['sequence_id']][] = [
        'id' => $m['id'], 'title' => $m['title'],
        'duration' => $m['duration_hours'], 'type' => $m['content_type'],
    ];
}
$allFormations = $pdo->query("
    SELECT f.id, f.rncp_title_id, f.title
    FROM formations f WHERE f.status='active' ORDER BY f.title
")->fetchAll();

// Valeurs pré-remplies (mode édition ou ?seq_id=X depuis index)
$preSeqId  = $seance ? (int)$seance['sequence_id'] : (int)($_GET['seq_id'] ?? 0);
$preCompId = $seance ? (int)$seance['seq_comp_id'] : 0;
if (!$preCompId && $preSeqId) {
    foreach ($allSeq as $sq) { if ($sq['id'] == $preSeqId) { $preCompId = (int)$sq['competency_id']; break; } }
}
$preAtId   = $seance ? (int)$seance['at_id'] : 0;
if (!$preAtId && $preCompId) {
    foreach ($allComp as $c) { if ($c['id'] == $preCompId) { $preAtId = (int)$c['activity_type_id']; break; } }
}
$preRncpId = 0;
$preFormId = $seance ? (int)($seance['formation_id'] ?? 0) : (int)($_GET['formation_id'] ?? 0);

if ($preAtId) {
    foreach ($allAT as $a) { if ($a['id'] == $preAtId) { $preRncpId = (int)$a['rncp_title_id']; break; } }
}

// Content types
$contentTypes = [
    'video'        => ['label'=>'Vidéo',        'icon'=>'play-circle',    'color'=>'#ef4444'],
    'pdf'          => ['label'=>'PDF',           'icon'=>'file-pdf',       'color'=>'#ef4444'],
    'document'     => ['label'=>'Document',      'icon'=>'file-word',      'color'=>'#3b82f6'],
    'presentation' => ['label'=>'Présentation',  'icon'=>'file-powerpoint','color'=>'#f97316'],
    'quiz'         => ['label'=>'Quizz',         'icon'=>'question-circle','color'=>'#8b5cf6'],
    'exercise'     => ['label'=>'Exercice',      'icon'=>'pencil-alt',     'color'=>'#10b981'],
    'text'         => ['label'=>'Texte/HTML',    'icon'=>'align-left',     'color'=>'#6b7280'],
    'link'         => ['label'=>'Lien externe',  'icon'=>'link',           'color'=>'#0ea5e9'],
];
$getType = $_GET['type'] ?? '';
$curType = $seance['content_type'] ?? (isset($contentTypes[$getType]) ? $getType : 'text');

$pageTitle = $editId ? 'Modifier la séance' : 'Nouvelle séance';

renderHead($pageTitle);
renderSidebar(isAdmin() ? 'admin' : (isPedagogy() ? 'pedagogy' : 'teacher'));
renderTopbar($pageTitle, [
    ['Enseignant', url('teacher/index.php')],
    ['Séances', url('teacher/courses/index.php')],
    [$pageTitle, ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div style="max-width:760px;margin:0 auto">

    <!-- ══ Aide contextuelle ══════════════════════════════════════════════════ -->
    <div id="help-banner" style="background:rgba(139,92,246,.08);border:1px solid rgba(139,92,246,.3);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:20px;display:none">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
        <div>
          <div style="font-weight:700;font-size:14px;color:#a78bfa;margin-bottom:10px"><i class="fas fa-sitemap" style="margin-right:6px"></i>Comment fonctionne la hiérarchie pédagogique ?</div>
          <div style="display:grid;gap:8px">
            <?php foreach ([
              ['fa-certificate',  '#8b5cf6', 'Titre RNCP',      'La certification nationale (ex : Titre Pro Comptable). Sert de filtre.'],
              ['fa-layer-group',  '#f59e0b', 'Activité type',   'Bloc d\'activités défini par France Compétences dans le référentiel RNCP. Sert de filtre.'],
              ['fa-bullseye',     '#ef4444', 'Compétence',      'Compétence précise que l\'apprenant doit maîtriser. Obligatoire pour la séquence.'],
              ['fa-list-ol',      '#10b981', 'Séquence',        'Ensemble cohérent de séances couvrant une compétence. Créez-en une ou utilisez-en une existante.'],
              ['fa-bookmark',     '#0ea5e9', 'Séance',          'Unité de contenu (vidéo, PDF, texte, quiz…) que vous créez ici.'],
              ['fa-graduation-cap','#94a3b8','Formation',       'Optionnel. Rattachez la séance à une formation si l\'enseignant est positionné sur une session.'],
            ] as [$ico, $col, $label, $desc]): ?>
            <div style="display:flex;align-items:flex-start;gap:10px">
              <div style="width:28px;height:28px;border-radius:50%;background:<?= $col ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px">
                <i class="fas <?= $ico ?>" style="font-size:11px;color:<?= $col ?>"></i>
              </div>
              <div>
                <span style="font-weight:600;font-size:13px;color:var(--text)"><?= $label ?></span>
                <span style="font-size:12px;color:var(--text-muted)"> — <?= $desc ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <button onclick="toggleHelp()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;padding:0;flex-shrink:0" title="Fermer">×</button>
      </div>
    </div>

    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <div>
          <h3 style="margin:0"><i class="fas fa-bookmark" style="color:#0ea5e9;margin-right:8px"></i><?= e($pageTitle) ?></h3>
          <p style="color:var(--text-muted);font-size:13px;margin:4px 0 0">Compétence, séquence, contenu : tout se passe ici.</p>
        </div>
        <button onclick="toggleHelp()" id="help-btn" style="display:flex;align-items:center;gap:6px;background:rgba(139,92,246,.15);border:1px solid rgba(139,92,246,.35);border-radius:var(--radius);padding:6px 12px;color:#a78bfa;cursor:pointer;font-size:12px;font-weight:600;transition:.15s" title="Aide sur la hiérarchie pédagogique">
          <i class="fas fa-question-circle"></i> Aide
        </button>
      </div>

      <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="seance-form">
          <?= csrfField() ?>

          <!-- ═══ ÉTAPE 1 : Compétence cible ══════════════════════════════ -->
          <div style="margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
              <div style="width:24px;height:24px;border-radius:50%;background:#8b5cf6;color:white;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">1</div>
              <span style="font-weight:700;font-size:13px">Compétence visée</span>
              <span style="font-size:11px;color:var(--text-muted)">(RNCP → Activité type → Compétence)</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
              <div class="form-group" style="margin:0">
                <label class="form-label" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">
                  <i class="fas fa-certificate" style="color:#8b5cf6;margin-right:4px"></i>Titre RNCP
                </label>
                <select id="filter-rncp" class="form-control" style="font-size:12px">
                  <option value="">— Tous —</option>
                  <?php foreach ($allRncp as $r): ?>
                  <option value="<?= $r['id'] ?>" <?= $preRncpId == $r['id'] ? 'selected' : '' ?>>
                    <?= e($r['rncp_code']) ?> — <?= e(mb_substr($r['title'], 0, 45)) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">
                  <i class="fas fa-layer-group" style="color:#f59e0b;margin-right:4px"></i>Activité type
                </label>
                <select id="filter-at" class="form-control" style="font-size:12px">
                  <option value="">— Toutes —</option>
                  <?php foreach ($allAT as $a): ?>
                  <option value="<?= $a['id'] ?>" data-rncp="<?= $a['rncp_title_id'] ?>" <?= $preAtId == $a['id'] ? 'selected' : '' ?>>
                    <?= e($a['code']) ?> — <?= e(mb_substr($a['title'], 0, 40)) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">
                  <i class="fas fa-bullseye" style="color:#ef4444;margin-right:4px"></i>Compétence <span style="color:#ef4444">*</span>
                </label>
                <select id="filter-comp" name="competency_id" class="form-control" style="font-size:12px" required>
                  <option value="">← sélectionner AT</option>
                  <?php foreach ($allComp as $c): ?>
                  <option value="<?= $c['id'] ?>" data-at="<?= $c['activity_type_id'] ?>" <?= $preCompId == $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['code']) ?> — <?= e(mb_substr($c['title'], 0, 30)) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <!-- ═══ ÉTAPE 2 : Séquence ══════════════════════════════════════ -->
          <div style="margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
              <div style="width:24px;height:24px;border-radius:50%;background:#10b981;color:white;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">2</div>
              <span style="font-weight:700;font-size:13px">Séquence</span>
              <span style="font-size:11px;color:var(--text-muted)">(regroupement de séances pour une compétence)</span>
            </div>
            <div style="display:flex;gap:0;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:12px;max-width:340px">
              <label id="tab-existing" style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:8px 14px;cursor:pointer;font-size:12px;font-weight:600;transition:.15s;background:var(--primary);color:white">
                <input type="radio" name="seq_mode" value="existing" checked style="display:none" onchange="switchSeqMode('existing')">
                <i class="fas fa-list-ul"></i> Existante
              </label>
              <label id="tab-new" style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:8px 14px;cursor:pointer;font-size:12px;font-weight:600;transition:.15s;border-left:1px solid var(--border);color:var(--text-muted)">
                <input type="radio" name="seq_mode" value="new" style="display:none" onchange="switchSeqMode('new')">
                <i class="fas fa-plus"></i> Nouvelle séquence
              </label>
            </div>
            <div id="seq-existing-panel">
              <select id="filter-seq" name="sequence_id" class="form-control">
                <option value="">← sélectionner une compétence d'abord</option>
                <?php foreach ($allSeq as $seq): ?>
                <option value="<?= $seq['id'] ?>" data-comp="<?= $seq['competency_id'] ?>" <?= $preSeqId == $seq['id'] ? 'selected' : '' ?>>
                  <?= e($seq['title']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div id="seq-empty-hint" style="font-size:12px;color:var(--warning);margin-top:6px;display:none">
                <i class="fas fa-info-circle"></i> Aucune séquence pour cette compétence — créez-en une nouvelle.
              </div>
              <div id="seq-seances-panel" style="display:none;margin-top:10px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px">
                  <i class="fas fa-list-ul" style="margin-right:5px"></i>Séances déjà dans cette séquence
                </div>
                <div id="seq-seances-list" style="display:flex;flex-direction:column;gap:5px"></div>
              </div>
            </div>
            <div id="seq-new-panel" style="display:none">
              <div style="display:grid;gap:10px;background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.25);border-radius:var(--radius);padding:14px">
                <div class="form-group" style="margin:0">
                  <label class="form-label">Titre de la séquence <span style="color:#ef4444">*</span></label>
                  <input type="text" name="sequence_title" id="seq-title-input" class="form-control" placeholder="Ex : Maîtrise des opérations courantes de comptabilité" maxlength="500">
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Description <span style="color:var(--text-muted);font-weight:400;font-size:11px">(optionnel)</span></label>
                  <textarea name="sequence_desc" class="form-control" rows="2" placeholder="Objectifs généraux de la séquence..."></textarea>
                </div>
              </div>
            </div>
          </div>

          <!-- ═══ ÉTAPE 3 : Type de contenu ════════════════════════════════ -->
          <div style="margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
              <div style="width:24px;height:24px;border-radius:50%;background:#f59e0b;color:white;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">3</div>
              <span style="font-weight:700;font-size:13px">Type de contenu</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px" id="type-grid">
              <?php foreach ($contentTypes as $type => $info): ?>
              <label style="cursor:pointer">
                <input type="radio" name="content_type" value="<?= $type ?>" style="display:none" <?= ($curType===$type?'checked':'') ?>>
                <div class="type-card" data-type="<?= $type ?>" style="padding:14px 10px;border:2px solid var(--border);border-radius:var(--radius-lg);text-align:center;transition:.2s;<?= $curType===$type?'border-color:var(--primary);background:rgba(99,102,241,.08)':'' ?>">
                  <i class="fas fa-<?= $info['icon'] ?>" style="font-size:22px;color:<?= $info['color'] ?>;display:block;margin-bottom:6px"></i>
                  <div style="font-size:11px;font-weight:700;color:var(--text)"><?= $info['label'] ?></div>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- ═══ ÉTAPE 4 : Détails de la séance ═══════════════════════════ -->
          <div style="margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
              <div style="width:24px;height:24px;border-radius:50%;background:#0ea5e9;color:white;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">4</div>
              <span style="font-weight:700;font-size:13px">Détails de la séance</span>
            </div>
            <div class="form-group">
              <label class="form-label">Titre de la séance <span style="color:#ef4444">*</span></label>
              <input type="text" name="title" class="form-control" placeholder="Ex : Introduction aux fondamentaux comptables"
                value="<?= e($seance['title'] ?? '') ?>" required maxlength="500">
            </div>
            <div class="form-group">
              <label class="form-label">Description <span style="color:var(--text-muted);font-weight:400;font-size:11px">(optionnel)</span></label>
              <textarea name="description" class="form-control" rows="3"
                placeholder="Objectifs pédagogiques de la séance..."><?= e($seance['description'] ?? '') ?></textarea>
            </div>
            <div style="max-width:180px">
              <label class="form-label">Durée (heures)</label>
              <input type="number" name="duration_hours" class="form-control" min="0" max="999" step="0.5"
                placeholder="Ex : 2.5" value="<?= $seance ? e($seance['duration_hours'] ?? '') : '' ?>">
            </div>
          </div>

          <!-- ═══ ÉTAPE 5 : Contenu (masqué pour le type Quizz) ═══════════════ -->
          <div style="margin-bottom:24px" id="content-step">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
              <div style="width:24px;height:24px;border-radius:50%;background:#6366f1;color:white;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">5</div>
              <span style="font-weight:700;font-size:13px" id="content-title">Contenu vidéo</span>
            </div>

            <div id="quiz-hint" style="display:none;background:rgba(139,92,246,.08);border:1px solid rgba(139,92,246,.3);border-radius:var(--radius);padding:14px 16px;font-size:13px;color:var(--text-secondary)">
              <i class="fas fa-info-circle" style="color:#8b5cf6;margin-right:6px"></i>
              Vous allez configurer les questions du quiz juste après avoir enregistré la séance.
            </div>

            <div id="zone-url">
              <div class="form-group">
                <label class="form-label">URL de la vidéo</label>
                <div class="input-group">
                  <i class="fas fa-link input-icon"></i>
                  <input type="url" name="content_url" id="content-url" class="form-control" placeholder="https://www.youtube.com/watch?v=..." value="<?= e($seance['content_url'] ?? '') ?>">
                </div>
                <div class="form-hint">YouTube, Vimeo, ou URL directe MP4</div>
              </div>
              <div class="form-group">
                <label class="form-label">ou téléverser un fichier</label>
                <input type="file" name="seance_file" id="seance-file" style="display:none">
                <div id="seance-file-dropzone" style="display:flex;align-items:center;gap:12px;padding:20px;border:2px dashed var(--border);border-radius:var(--radius);cursor:pointer;transition:border-color .2s,background .2s">
                  <i class="fas fa-cloud-upload-alt" style="font-size:24px;color:var(--text-muted);flex-shrink:0"></i>
                  <div>
                    <div id="seance-file-label" style="font-size:14px;font-weight:600">Cliquer ou glisser un fichier</div>
                    <div style="font-size:12px;color:var(--text-muted)">MP4, PDF, Word, Excel, PPT (max 50 Mo)</div>
                  </div>
                </div>
              </div>
            </div>

            <?php
            $existingPdfs = null;
            if ($isEdit && $seance['content_type'] === 'pdf' && $seance['file_path']) {
                $decoded = json_decode($seance['file_path'], true);
                $existingPdfs = is_array($decoded) ? $decoded
                    : [['path' => $seance['file_path'], 'name' => pathinfo($seance['file_path'], PATHINFO_FILENAME)]];
            }
            ?>
            <div id="zone-pdf-multi" style="display:none" data-has-existing="<?= $existingPdfs ? 'true' : 'false' ?>">
              <?php if ($existingPdfs): ?>
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--primary);letter-spacing:.05em;margin-bottom:10px">
                <i class="fas fa-file-pdf" style="margin-right:5px"></i>PDFs de la séance
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
                  <button type="button" onclick="deleteExistingPdf(this)" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:4px 8px;flex-shrink:0" title="Supprimer ce PDF"><i class="fas fa-trash"></i></button>
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

            <div id="zone-text" style="display:none">
              <div class="form-group">
                <label class="form-label">Contenu de la séance</label>
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

          <!-- ═══ ÉTAPE 6 : Ressources complémentaires (masqué pour Quizz) ══ -->
          <div style="margin-bottom:24px" id="resources-step">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
              <div style="width:24px;height:24px;border-radius:50%;background:rgba(148,163,184,.3);color:var(--text-muted);font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">6</div>
              <span style="font-weight:700;font-size:13px">Ressources complémentaires</span>
              <span style="font-size:11px;color:var(--text-muted)">(optionnel)</span>
            </div>
            <input type="file" name="resources[]" id="resources-input" multiple style="display:none" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx">
            <div id="resources-dropzone" style="border:2px dashed var(--border);border-radius:var(--radius);padding:24px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s">
              <i class="fas fa-paperclip" style="font-size:28px;color:var(--text-muted);margin-bottom:8px;display:block"></i>
              <div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:4px">Cliquer ou glisser des fichiers</div>
              <div style="font-size:12px;color:var(--text-muted)">PDF, Word, Excel, PowerPoint (plusieurs fichiers)</div>
            </div>
            <div id="resource-list" style="margin-top:12px;display:flex;flex-direction:column;gap:6px"></div>
          </div>

          <!-- ═══ ÉTAPE 7 : Formation + Publication ═══════════════════════ -->
          <div style="border-top:1px solid var(--border);padding-top:20px;margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
              <div style="width:24px;height:24px;border-radius:50%;background:rgba(148,163,184,.3);color:var(--text-muted);font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">7</div>
              <span style="font-weight:700;font-size:13px">Rattachement à une formation</span>
              <span style="background:rgba(148,163,184,.2);color:var(--text-muted);font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:uppercase;letter-spacing:.05em">Optionnel</span>
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin:0 0 12px">Laissez vide si la séance n'est pas encore rattachée à une formation. Vous pourrez l'associer plus tard.</p>
            <select id="filter-formation" name="formation_id" class="form-control" style="max-width:420px;margin-bottom:18px">
              <option value="">— Aucune formation pour l'instant —</option>
              <?php foreach ($allFormations as $f): ?>
              <option value="<?= $f['id'] ?>" data-rncp="<?= $f['rncp_title_id'] ?>" <?= $preFormId == $f['id'] ? 'selected' : '' ?>>
                <?= e(mb_substr($f['title'], 0, 60)) ?>
              </option>
              <?php endforeach; ?>
            </select>

            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:560px">
              <div class="form-group" style="margin:0">
                <label class="form-label">Points XP</label>
                <input type="number" name="xp_reward" class="form-control" value="<?= $isEdit ? (int)$seance['xp_reward'] : 10 ?>" min="0" max="500">
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label">N° d'ordre</label>
                <input type="number" name="order_num" class="form-control" value="<?= $isEdit ? (int)$seance['order_num'] : '' ?>" min="1" placeholder="auto">
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label">Vignette</label>
                <input type="file" name="thumbnail" accept="image/*" style="display:none" id="thumb-input">
                <div id="thumb-dropzone" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:2px dashed var(--border);border-radius:var(--radius);cursor:pointer">
                  <div id="thumb-preview" style="width:32px;height:32px;border-radius:6px;background:var(--bg-elevated);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">🖼️</div>
                  <span style="font-size:11px;color:var(--text-muted)">Image</span>
                </div>
              </div>
            </div>
            <div style="display:flex;gap:20px;margin-top:14px">
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                <label class="toggle"><input type="checkbox" name="is_mandatory" <?= (!$isEdit || $seance['is_mandatory']) ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
                <span style="font-size:13px">Séance obligatoire</span>
              </label>
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                <label class="toggle"><input type="checkbox" name="is_preview" <?= ($isEdit && $seance['is_preview']) ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
                <span style="font-size:13px">Prévisualisation libre</span>
              </label>
            </div>
          </div>

          <!-- Boutons -->
          <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary" style="min-width:180px">
              <i class="fas <?= $editId ? 'fa-save' : 'fa-plus-circle' ?>"></i>
              <?= $editId ? 'Enregistrer les modifications' : 'Créer la séance' ?>
            </button>
            <?php if ($editId): ?>
            <a href="<?= url('student/course/seance.php?id='.$editId.'&preview=1') ?>" target="_blank" class="btn btn-secondary">
              <i class="fas fa-eye"></i> Tester la séance
            </a>
            <?php endif; ?>
            <a href="<?= url('teacher/courses/index.php') ?>" class="btn btn-ghost">Annuler</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ── JS Cascade + UI ─────────────────────────────────────────────────────── -->
<div id="js-error-banner" style="display:none;position:fixed;top:0;left:0;right:0;z-index:9999;background:#ef4444;color:#fff;padding:12px 16px;font-size:13px;font-weight:600;word-break:break-all"></div>
<script>
window.onerror = function(msg, src, line, col, err) {
  var b = document.getElementById('js-error-banner');
  b.textContent = 'Erreur JS [' + (src||'').split('/').pop() + ':' + line + '] ' + msg;
  b.style.display = 'block';
  return false;
};
var SEANCES_PER_SEQ = <?= json_encode($seancesPerSeq, JSON_UNESCAPED_UNICODE) ?>;

(function() {
  var allAtOpts   = Array.from(document.getElementById('filter-at').options).slice(1).map(function(o) {
    return { el: o, id: +o.value, rncp: +(o.dataset.rncp || 0) };
  });
  var allCompOpts = Array.from(document.getElementById('filter-comp').options).slice(1).map(function(o) {
    return { el: o, id: +o.value, at: +(o.dataset.at || 0) };
  });
  var allSeqOpts  = Array.from(document.getElementById('filter-seq').options).slice(1).map(function(o) {
    return { el: o, id: +o.value, comp: +(o.dataset.comp || 0) };
  });
  var allFormOpts = Array.from(document.getElementById('filter-formation').options).slice(1).map(function(o) {
    return { el: o, id: +o.value, rncp: +(o.dataset.rncp || 0) };
  });

  var rncpSel  = document.getElementById('filter-rncp');
  var atSel    = document.getElementById('filter-at');
  var compSel  = document.getElementById('filter-comp');
  var seqSel   = document.getElementById('filter-seq');
  var formSel  = document.getElementById('filter-formation');
  var seqHint  = document.getElementById('seq-empty-hint');

  function rebuildSelect(sel, placeholder, opts) {
    var cur = sel.value;
    while (sel.options.length > 0) sel.remove(0);
    var def = document.createElement('option');
    def.value = ''; def.textContent = placeholder;
    sel.appendChild(def);
    opts.forEach(function(o) { sel.appendChild(o.el); });
    if (cur && Array.from(sel.options).some(function(o) { return o.value === cur; })) sel.value = cur;
    else sel.value = '';
  }

  function onRncpChange() {
    var rncpId = +rncpSel.value;
    rebuildSelect(atSel, '— Toutes —', allAtOpts.filter(function(o) { return !rncpId || o.rncp === rncpId; }));
    onAtChange();
    rebuildSelect(formSel, '— Aucune formation pour l\'instant —', allFormOpts.filter(function(o) { return !rncpId || o.rncp === rncpId; }));
  }

  function onAtChange() {
    var atId = +atSel.value;
    rebuildSelect(compSel, atId ? '— Sélectionner —' : '← sélectionner AT', allCompOpts.filter(function(o) { return !atId || o.at === atId; }));
    onCompChange();
  }

  function onCompChange() {
    var compId = +compSel.value;
    var filtered = allSeqOpts.filter(function(o) { return compId && o.comp === compId; });
    rebuildSelect(seqSel, compId ? '— Sélectionner une séquence —' : '← sélectionner une compétence d\'abord', filtered);
    seqHint.style.display = (compId && filtered.length === 0) ? '' : 'none';
    if (compId && filtered.length === 0) switchSeqMode('new');
  }

  function onSeqChange() {
    var seqId = +seqSel.value;
    var panel = document.getElementById('seq-seances-panel');
    var list  = document.getElementById('seq-seances-list');
    var items = SEANCES_PER_SEQ[seqId] || [];
    if (seqId && items.length > 0) {
      list.innerHTML = items.map(function(s) {
        return '<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 8px;background:rgba(255,255,255,.04);border-radius:5px">'
          + '<span style="font-size:12px;font-weight:500">' + s.title + '</span>'
          + '<span style="font-size:11px;color:var(--text-muted)">'
          + (s.duration ? s.duration+'h · ' : '') + (s.type || '')
          + '</span></div>';
      }).join('');
      panel.style.display = '';
    } else {
      panel.style.display = 'none';
    }
  }

  rncpSel.addEventListener('change', onRncpChange);
  atSel.addEventListener('change', onAtChange);
  compSel.addEventListener('change', onCompChange);
  seqSel.addEventListener('change', onSeqChange);

  var initRncp = <?= $preRncpId ?>;
  var initAt   = <?= $preAtId ?>;
  var initComp = <?= $preCompId ?>;
  var initSeq  = <?= $preSeqId ?>;
  if (initRncp) { rncpSel.value = initRncp; onRncpChange(); }
  if (initAt)   { atSel.value   = initAt;   onAtChange(); }
  if (initComp) { compSel.value = initComp; onCompChange(); }
  if (initSeq)  { seqSel.value  = initSeq;  onSeqChange(); }
})();

function switchSeqMode(mode) {
  var existingPanel = document.getElementById('seq-existing-panel');
  var newPanel      = document.getElementById('seq-new-panel');
  var tabExisting   = document.getElementById('tab-existing');
  var tabNew        = document.getElementById('tab-new');
  var seqSel        = document.getElementById('filter-seq');
  var seqTitleInput = document.getElementById('seq-title-input');

  if (mode === 'new') {
    existingPanel.style.display = 'none';
    newPanel.style.display      = '';
    tabNew.style.background     = 'var(--primary)';
    tabNew.style.color          = 'white';
    tabExisting.style.background = '';
    tabExisting.style.color     = 'var(--text-muted)';
    seqSel.removeAttribute('name');
    seqTitleInput.setAttribute('name', 'sequence_title');
    document.querySelector('[name="seq_mode"][value="new"]').checked = true;
  } else {
    existingPanel.style.display = '';
    newPanel.style.display      = 'none';
    tabExisting.style.background = 'var(--primary)';
    tabExisting.style.color     = 'white';
    tabNew.style.background     = '';
    tabNew.style.color          = 'var(--text-muted)';
    seqSel.setAttribute('name', 'sequence_id');
    seqTitleInput.removeAttribute('name');
    document.querySelector('[name="seq_mode"][value="existing"]').checked = true;
  }
}
document.querySelector('[name="seq_mode"][value="existing"]').checked = true;

function toggleHelp() {
  var banner = document.getElementById('help-banner');
  banner.style.display = banner.style.display === 'none' ? '' : 'none';
}

// ── Type de contenu ──────────────────────────────────────────
var editData = <?= $isEdit ? (json_encode(['type'=>$seance['content_type'],'url'=>$seance['content_url'],'body'=>sanitizeRichHtml($seance['content_body'])], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null') : 'null' ?>;
const typeCards = document.querySelectorAll('.type-card');

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
  const quizHint     = document.getElementById('quiz-hint');
  const resourcesStep= document.getElementById('resources-step');
  const title        = document.getElementById('content-title');
  const urlInput     = document.getElementById('content-url');
  const fileInput    = document.getElementById('seance-file');
  const fileDropzone = document.getElementById('seance-file-dropzone');

  const titles = {video:'Contenu vidéo',pdf:'Documents PDF',document:'Document',presentation:'Présentation',text:'Contenu HTML',link:'Lien externe',quiz:'Quizz',exercise:'Fichier d\'exercice'};
  title.textContent = titles[type] || 'Contenu';

  zoneUrl.style.display = 'none'; zoneText.style.display = 'none'; zonePdfMulti.style.display = 'none';
  quizHint.style.display = 'none'; resourcesStep.style.display = '';

  if (type === 'quiz') {
    quizHint.style.display = '';
    resourcesStep.style.display = 'none';
  } else if (type === 'text') {
    zoneText.style.display = 'block';
  } else if (type === 'pdf') {
    zonePdfMulti.style.display = 'block';
    var hasExisting = zonePdfMulti.dataset.hasExisting === 'true';
    if (!hasExisting && !document.querySelector('#pdf-slots .pdf-slot')) addPdfSlot();
  } else if (type === 'link') {
    zoneUrl.style.display = 'block';
    urlInput.placeholder = 'https://exemple.com/ressource';
    fileDropzone.style.display = 'none';
  } else {
    zoneUrl.style.display = 'block';
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
  var existingCount = document.querySelectorAll('#existing-pdf-list .existing-pdf-row').length;
  document.querySelectorAll('#pdf-slots .pdf-slot').forEach(function(slot, i) {
    var n = slot.querySelector('.slot-num'); if (n) n.textContent = existingCount + i + 1;
  });
}

function moveExistingPdf(btn, dir) {
  var row = btn.closest('.existing-pdf-row');
  var list = document.getElementById('existing-pdf-list');
  var rows = Array.from(list.querySelectorAll('.existing-pdf-row'));
  var idx  = rows.indexOf(row);
  var newIdx = idx + dir;
  if (newIdx < 0 || newIdx >= rows.length) return;
  if (dir === -1) list.insertBefore(row, rows[newIdx]);
  else list.insertBefore(rows[newIdx], row);
  updateExistingPdfNumbers();
}

function deleteExistingPdf(btn) {
  if (!confirm('Supprimer ce PDF de la séance ?')) return;
  btn.closest('.existing-pdf-row').remove();
  updateExistingPdfNumbers();
  updateSlotNumbers();
}

function deleteAllExistingPdfs() {
  var list = document.getElementById('existing-pdf-list');
  if (!list) return;
  var count = list.querySelectorAll('.existing-pdf-row').length;
  if (!count) return;
  if (!confirm('Supprimer les ' + count + ' PDF(s) de la séance ?')) return;
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

const editor = document.getElementById('text-editor');
const hidden = document.getElementById('content-body-hidden');
if (editor) {
  editor.addEventListener('input', () => { hidden.value = editor.innerHTML; });
}

if (editData) {
  typeCards.forEach(c => { c.style.borderColor='var(--border)'; c.style.background=''; });
  const activeCard = document.querySelector(`.type-card[data-type="${editData.type}"]`);
  if (activeCard) { activeCard.style.borderColor='var(--primary)'; activeCard.style.background='rgba(99,102,241,.08)'; }
  const activeRadio = document.querySelector(`[name="content_type"][value="${editData.type}"]`);
  if (activeRadio) activeRadio.checked = true;
  updateContentZone(editData.type);
  if (editData.url) { const u = document.getElementById('content-url'); if (u) u.value = editData.url; }
  if (editData.body && editor) { editor.innerHTML = editData.body; hidden.value = editData.body; }
} else {
  updateContentZone('<?= e($curType) ?>');
}

function execFormat(cmd) {
  if (cmd === 'list-ul') { document.execCommand('insertUnorderedList'); }
  else if (cmd === 'list-ol') { document.execCommand('insertOrderedList'); }
  else { document.execCommand(cmd); }
  if (editor) editor.focus();
}

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

makeDrop(
  document.getElementById('seance-file-dropzone'),
  document.getElementById('seance-file'),
  files => {
    if (files.length) {
      document.getElementById('seance-file-label').textContent = files[0].name;
      document.getElementById('seance-file-dropzone').style.borderColor = 'var(--success)';
    }
  }
);

makeDrop(
  document.getElementById('thumb-dropzone'),
  document.getElementById('thumb-input'),
  files => {
    if (!files[0]) return;
    const r = new FileReader();
    r.onload = e => {
      document.getElementById('thumb-preview').innerHTML =
        `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;border-radius:6px">`;
    };
    r.readAsDataURL(files[0]);
  }
);

const resourceIcons = {pdf:'file-pdf',doc:'file-word',docx:'file-word',xls:'file-excel',xlsx:'file-excel',ppt:'file-powerpoint',pptx:'file-powerpoint'};
let selectedResources = [];

makeDrop(
  document.getElementById('resources-dropzone'),
  document.getElementById('resources-input'),
  files => renderResourceList(files)
);

function renderResourceList(newFiles) {
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

document.getElementById('seance-form').addEventListener('submit', () => {
  if (editor) hidden.value = editor.innerHTML;
});
</script>
<?php renderFooter(); ?>
