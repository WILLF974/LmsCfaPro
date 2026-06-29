<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();

$pdo = getDB();
$id  = (int)($_GET['id'] ?? 0);

if (!$id) { setFlash('error', 'Identifiant invalide.'); redirect(url('student/index.php')); }

// Charger l'étude de cas
$stmt = $pdo->prepare('SELECT cs.*, f.title as formation_title FROM case_studies cs LEFT JOIN formations f ON cs.formation_id = f.id WHERE cs.id = ?');
$stmt->execute([$id]);
$cs = $stmt->fetch();
if (!$cs) { setFlash('error', 'Étude de cas introuvable.'); redirect(url('student/index.php')); }

$userId = (int)$_SESSION['user_id'];

// Accès : enseignant/admin toujours autorisé ; étudiant vérifié si lié à une formation
if (!isTeacher() && !isAdmin() && !isPedagogy()) {
    if ($cs['formation_id']) {
        $enrolled = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id=? AND formation_id=? AND status="active"');
        $enrolled->execute([$userId, $cs['formation_id']]);
        if (!$enrolled->fetch()) {
            setFlash('error', 'Accès non autorisé.'); redirect(url('student/index.php'));
        }
    }
}

// Récupérer la formation active de l'étudiant pour cette étude de cas
$enrolledFormationId = 0;
if ($cs['formation_id']) {
    $efStmt = $pdo->prepare('SELECT formation_id FROM enrollments WHERE user_id=? AND formation_id=? AND status="active" LIMIT 1');
    $efStmt->execute([$userId, $cs['formation_id']]);
    $efRow = $efStmt->fetch();
    $enrolledFormationId = $efRow ? (int)$efRow['formation_id'] : (int)$cs['formation_id'];
} else {
    // Prendre la première formation active de l'étudiant
    $efStmt = $pdo->prepare('SELECT formation_id FROM enrollments WHERE user_id=? AND status="active" ORDER BY id DESC LIMIT 1');
    $efStmt->execute([$userId]);
    $efRow = $efStmt->fetch();
    $enrolledFormationId = $efRow ? (int)$efRow['formation_id'] : 0;
}

// Soumission existante de cet étudiant
$existingSubmission = null;
try {
    $subStmt = $pdo->prepare('SELECT * FROM case_study_submissions WHERE case_study_id=? AND user_id=? LIMIT 1');
    $subStmt->execute([$id, $userId]);
    $existingSubmission = $subStmt->fetch() ?: null;
} catch (PDOException $e) {}

// ── ACTION : Soumettre le travail ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_case_study') {
    requireCsrf();

    if (isTeacher() || isAdmin()) {
        setFlash('error', 'Les enseignants ne soumettent pas de travaux.');
        redirect(url("student/case_studies/view.php?id=$id"));
    }

    $textResponse    = trim($_POST['text_response'] ?? '');
    $submittedFormId = (int)($_POST['formation_id'] ?? $enrolledFormationId);
    $uploadedPath    = null;

    if (!empty($_FILES['submission_file']['name'])) {
        $uploadResult = uploadFile($_FILES['submission_file'], 'submissions');
        if (!$uploadResult['success']) {
            setFlash('error', 'Erreur lors de l\'upload : ' . ($uploadResult['error'] ?? 'Erreur inconnue'));
            redirect(url("student/case_studies/view.php?id=$id"));
        }
        $uploadedPath = json_encode(['path' => $uploadResult['path'], 'name' => $_FILES['submission_file']['name']]);
    }

    if (!$textResponse && !$uploadedPath) {
        setFlash('error', 'Veuillez saisir une réponse ou joindre un fichier.');
        redirect(url("student/case_studies/view.php?id=$id"));
    }

    if ($existingSubmission) {
        if (in_array($existingSubmission['status'], ['graded', 'returned'])) {
            setFlash('error', 'Ce travail a été évalué, il ne peut plus être modifié ni soumis à nouveau.');
            redirect(url("student/case_studies/view.php?id=$id"));
        } elseif (in_array($existingSubmission['status'], ['submitted', 'under_review'])) {
            setFlash('info', 'Votre travail est déjà en cours de correction.');
            redirect(url("student/case_studies/view.php?id=$id"));
        } else {
            setFlash('error', 'Action non autorisée.');
            redirect(url("student/case_studies/view.php?id=$id"));
        }
    } else {
        $pdo->prepare('INSERT INTO case_study_submissions (case_study_id, user_id, formation_id, text_response, file_path) VALUES (?,?,?,?,?)')
            ->execute([$id, $userId, $submittedFormId ?: null, $textResponse ?: null, $uploadedPath]);
        setFlash('success', 'Votre travail a bien été soumis à votre enseignant.');
    }

    auditLog('case_study_submitted', 'case_study_submissions', (int)$pdo->lastInsertId());
    redirect(url("student/case_studies/view.php?id=$id"));
}

// Ressources complémentaires
$resources = [];
try {
    $s = $pdo->prepare('SELECT * FROM case_study_resources WHERE case_study_id = ? ORDER BY id');
    $s->execute([$id]);
    $resources = $s->fetchAll();
} catch (PDOException $e) {}

// Décodage PDF multi
$isMultiPdf = false;
$pdfPages   = [];
if ($cs['file_type'] === 'pdf' && $cs['file_path']) {
    $dec = json_decode($cs['file_path'], true);
    if (is_array($dec)) {
        $isMultiPdf = count($dec) > 1;
        foreach ($dec as $p) {
            $pdfPages[] = ['url' => uploadUrl($p['path']), 'name' => $p['name'] ?? pathinfo($p['path'], PATHINFO_FILENAME)];
        }
    } else {
        $pdfPages[] = ['url' => uploadUrl($cs['file_path']), 'name' => pathinfo($cs['file_path'], PATHINFO_FILENAME)];
    }
}

$typeIcons = [
    'pdf'          => ['icon'=>'file-pdf',       'color'=>'#ef4444'],
    'document'     => ['icon'=>'file-word',      'color'=>'#3b82f6'],
    'presentation' => ['icon'=>'file-powerpoint','color'=>'#f97316'],
    'video'        => ['icon'=>'play-circle',    'color'=>'#ef4444'],
    'link'         => ['icon'=>'link',           'color'=>'#0ea5e9'],
];
$ti = $typeIcons[$cs['file_type']] ?? $typeIcons['document'];

renderHead(e($cs['title']));
renderSidebar('student');
renderTopbar(e($cs['title']), [
    ['Accueil', url('student/index.php')],
    ['Étude de cas', ''],
]);
?>
<div class="page-content fade-in">

  <!-- En-tête -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body">
      <div style="display:flex;align-items:flex-start;gap:14px">
        <div style="width:48px;height:48px;border-radius:12px;background:<?= $ti['color'] ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-<?= $ti['icon'] ?>" style="color:<?= $ti['color'] ?>;font-size:22px"></i>
        </div>
        <div style="flex:1;min-width:0">
          <h1 style="font-size:20px;font-weight:700;margin-bottom:4px"><?= e($cs['title']) ?></h1>
          <?php if ($cs['description']): ?>
          <p style="color:var(--text-muted);font-size:13px;margin:0;line-height:1.5"><?= nl2br(e($cs['description'])) ?></p>
          <?php endif; ?>
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
            <?php if ($cs['formation_title']): ?>
            <span class="badge badge-secondary"><i class="fas fa-graduation-cap"></i> <?= e($cs['formation_title']) ?></span>
            <?php endif; ?>
            <?php if ($isMultiPdf): ?>
            <span class="badge badge-primary"><i class="fas fa-copy"></i> <?= count($pdfPages) ?> PDF</span>
            <?php endif; ?>
            <span style="font-size:11px;color:var(--text-faint);align-self:center"><?= formatDate($cs['created_at'], 'd/m/Y') ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Contenu -->
  <?php if ($cs['file_type'] === 'pdf'): ?>

    <?php if ($isMultiPdf): ?>
    <!-- ── Multi-PDF : iframes empilées ── -->
    <?php foreach ($pdfPages as $i => $p): ?>
    <div style="margin-bottom:24px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span style="width:26px;height:26px;background:#ef4444;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:white;flex-shrink:0"><?= $i+1 ?></span>
        <span style="font-size:13px;font-weight:600;color:var(--text);flex:1"><?= e($p['name']) ?></span>
        <a href="<?= e($p['url']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Ouvrir dans un nouvel onglet">
          <i class="fas fa-external-link-alt"></i>
        </a>
        <button onclick="openFullscreenUrl('<?= e(addslashes($p['url'])) ?>#toolbar=1&navpanes=1')" class="btn btn-ghost btn-sm" title="Plein écran">
          <i class="fas fa-expand"></i>
        </button>
      </div>
      <div style="border-radius:var(--radius-lg);overflow:hidden;border:1px solid var(--border);height:720px">
        <iframe src="<?= e($p['url']) ?>#toolbar=1&navpanes=1" style="width:100%;height:100%;border:none" loading="lazy"></iframe>
      </div>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <!-- ── PDF unique ── -->
    <div class="card">
      <div class="card-body" style="padding:0">
        <div style="display:flex;align-items:center;padding:10px 14px;border-bottom:1px solid var(--border);gap:8px">
          <i class="fas fa-file-pdf" style="color:#ef4444"></i>
          <span style="flex:1;font-size:13px;font-weight:600"><?= e($pdfPages[0]['name']) ?></span>
          <a href="<?= e($pdfPages[0]['url']) ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-external-link-alt"></i></a>
          <button onclick="openFullscreen()" class="btn btn-ghost btn-sm"><i class="fas fa-expand"></i></button>
        </div>
        <div style="height:800px">
          <iframe id="doc-iframe" src="<?= e($pdfPages[0]['url']) ?>#toolbar=1&navpanes=1" style="width:100%;height:100%;border:none"></iframe>
        </div>
      </div>
    </div>
    <?php endif; ?>

  <?php elseif ($cs['file_type'] === 'video'): ?>
    <!-- ── Vidéo ── -->
    <div class="card">
      <div class="card-body">
        <?php if ($cs['content_url']): ?>
          <?php
          $videoUrl = $cs['content_url'];
          $embedUrl = null;
          // YouTube
          if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $videoUrl, $m)) {
              $embedUrl = 'https://www.youtube-nocookie.com/embed/' . $m[1];
          }
          // Vimeo
          elseif (preg_match('/vimeo\.com\/(\d+)/', $videoUrl, $m)) {
              $embedUrl = 'https://player.vimeo.com/video/' . $m[1];
          }
          ?>
          <?php if ($embedUrl): ?>
          <div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:var(--radius)">
            <iframe src="<?= e($embedUrl) ?>" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none"
              allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen></iframe>
          </div>
          <?php else: ?>
          <div style="text-align:center;padding:24px">
            <i class="fas fa-play-circle" style="font-size:40px;color:#ef4444;margin-bottom:12px;display:block"></i>
            <a href="<?= e($videoUrl) ?>" target="_blank" class="btn btn-primary"><i class="fas fa-external-link-alt"></i> Ouvrir la vidéo</a>
          </div>
          <?php endif; ?>
        <?php elseif ($cs['file_path']): ?>
          <video controls style="width:100%;border-radius:var(--radius);background:#000">
            <source src="<?= e(uploadUrl($cs['file_path'])) ?>">
            Votre navigateur ne supporte pas la lecture vidéo.
          </video>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($cs['file_type'] === 'link'): ?>
    <!-- ── Lien externe ── -->
    <div class="card">
      <div class="card-body" style="text-align:center;padding:40px">
        <i class="fas fa-link" style="font-size:42px;color:#0ea5e9;margin-bottom:16px;display:block"></i>
        <h3 style="margin-bottom:8px">Ressource externe</h3>
        <p style="color:var(--text-muted);margin-bottom:20px;font-size:13px"><?= e($cs['content_url']) ?></p>
        <a href="<?= e($cs['content_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
          <i class="fas fa-external-link-alt"></i> Accéder à la ressource
        </a>
      </div>
    </div>

  <?php else: ?>
    <!-- ── Document / Présentation (Office viewer) ── -->
    <?php if ($cs['file_path']): ?>
    <div class="card">
      <div class="card-body" style="padding:0">
        <div style="display:flex;align-items:center;padding:10px 14px;border-bottom:1px solid var(--border);gap:8px">
          <i class="fas fa-<?= $ti['icon'] ?>" style="color:<?= $ti['color'] ?>"></i>
          <span style="flex:1;font-size:13px;font-weight:600"><?= e(pathinfo($cs['file_path'], PATHINFO_FILENAME)) ?></span>
          <a href="<?= e(uploadUrl($cs['file_path'])) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Télécharger">
            <i class="fas fa-download"></i>
          </a>
          <button onclick="openFullscreen()" class="btn btn-ghost btn-sm"><i class="fas fa-expand"></i></button>
        </div>
        <?php
        $ext = strtolower(pathinfo($cs['file_path'], PATHINFO_EXTENSION));
        $officeExts = ['doc','docx','xls','xlsx','ppt','pptx'];
        $fileUrl = uploadUrl($cs['file_path']);
        ?>
        <?php if (in_array($ext, $officeExts)): ?>
        <div style="height:800px">
          <iframe id="doc-iframe" src="https://view.officeapps.live.com/op/embed.aspx?src=<?= urlencode($fileUrl) ?>"
            style="width:100%;height:100%;border:none"></iframe>
        </div>
        <?php else: ?>
        <div style="height:800px">
          <iframe id="doc-iframe" src="<?= e($fileUrl) ?>#toolbar=1" style="width:100%;height:100%;border:none"></iframe>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>Aucun fichier disponible.</p></div>
    <?php endif; ?>

  <?php endif; ?>

  <!-- ── Bloc de soumission étudiant ── -->
  <?php if (!isTeacher() && !isAdmin() && !isPedagogy()):
    $subStatus = $existingSubmission['status'] ?? null;
    $isGraded  = $subStatus === 'graded';
  ?>
  <?php
    $cardBorder = $isGraded ? 'rgba(16,185,129,.4)'
        : ($subStatus === 'returned'  ? 'rgba(239,68,68,.35)'
        : ($existingSubmission        ? 'rgba(245,158,11,.3)'
        :                               'rgba(99,102,241,.25)'));
  ?>
  <div class="card" style="margin-top:24px;border:2px solid <?= $cardBorder ?>">
    <div class="card-header" style="border-bottom:1px solid var(--border)">
      <h3 class="card-title" style="display:flex;align-items:center;gap:10px">
        <?php if ($isGraded): ?>
          <i class="fas fa-check-circle" style="color:var(--success)"></i> Résultat de la correction
        <?php elseif ($subStatus === 'returned'): ?>
          <i class="fas fa-undo" style="color:var(--danger)"></i> Travail retourné
        <?php elseif ($subStatus === 'submitted' || $subStatus === 'under_review'): ?>
          <i class="fas fa-clock" style="color:var(--warning)"></i> Travail soumis
        <?php else: ?>
          <i class="fas fa-paper-plane" style="color:var(--primary-light)"></i> Soumettre mon travail
        <?php endif; ?>
      </h3>
    </div>
    <div class="card-body">

      <?php if ($isGraded): ?>
      <!-- ── Travail corrigé et verrouillé ── -->
      <div style="display:flex;align-items:flex-start;gap:16px;padding:16px;background:rgba(16,185,129,.07);border-radius:var(--radius);margin-bottom:16px">
        <i class="fas fa-check-circle" style="color:var(--success);font-size:28px;flex-shrink:0;margin-top:2px"></i>
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;flex-wrap:wrap">
            <strong style="color:var(--success);font-size:16px">Travail corrigé</strong>
            <?php if ($existingSubmission['score'] !== null): ?>
            <span class="badge badge-success" style="font-size:15px;padding:4px 14px">
              <?= $existingSubmission['score'] ?> / <?= $existingSubmission['max_score'] ?>
            </span>
            <?php endif; ?>
            <?php if ($existingSubmission['grade']): ?>
            <span class="badge badge-primary" style="font-size:13px"><?= e($existingSubmission['grade']) ?></span>
            <?php endif; ?>
            <?php if ($existingSubmission['score'] === null && !$existingSubmission['grade']): ?>
            <span style="font-size:13px;color:var(--text-muted)">Aucune note chiffrée</span>
            <?php endif; ?>
          </div>
          <?php if ($existingSubmission['feedback']): ?>
          <div style="margin-top:8px;padding:10px 14px;background:rgba(255,255,255,.04);border-radius:var(--radius);border-left:3px solid var(--success)">
            <div style="font-size:11px;font-weight:700;color:var(--success);margin-bottom:4px;text-transform:uppercase">Commentaire de l'enseignant</div>
            <p style="color:var(--text);font-size:13px;font-style:italic;margin:0"><?= e($existingSubmission['feedback']) ?></p>
          </div>
          <?php endif; ?>
          <div style="font-size:11px;color:var(--text-faint);margin-top:8px">
            <i class="fas fa-calendar"></i> Corrigé le <?= formatDate($existingSubmission['graded_at'], 'd/m/Y') ?>
          </div>
        </div>
      </div>

      <!-- Verrouillage -->
      <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(99,102,241,.07);border:1px solid rgba(99,102,241,.18);border-radius:var(--radius);margin-bottom:16px">
        <i class="fas fa-lock" style="color:var(--primary-light)"></i>
        <span style="font-size:13px;color:var(--text-muted)">Ce travail est définitivement noté — vous ne pouvez plus le modifier.</span>
      </div>

      <!-- Consultation : travail soumis (lecture seule) -->
      <?php if ($existingSubmission['text_response']): ?>
      <div style="margin-bottom:12px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:6px">Votre réponse soumise</div>
        <div style="padding:14px;background:var(--bg-elevated);border-radius:var(--radius);font-size:13px;line-height:1.7;white-space:pre-wrap;color:var(--text)"><?= e($existingSubmission['text_response']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($existingSubmission['file_path']): ?>
      <?php
        $gFd = json_decode($existingSubmission['file_path'], true);
        $gFp = is_array($gFd) ? ($gFd['path'] ?? '') : $existingSubmission['file_path'];
        $gFn = is_array($gFd) ? ($gFd['name'] ?? basename($gFp)) : basename($gFp);
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--bg-elevated);border-radius:var(--radius)">
        <i class="fas fa-file-alt" style="color:var(--primary-light)"></i>
        <span style="flex:1;font-size:13px"><?= e($gFn) ?></span>
        <?php if ($gFp && $gFp !== 'Array'): ?>
        <a href="<?= e(uploadUrl($gFp)) ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-eye"></i> Consulter</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php elseif ($subStatus === 'returned'): ?>
      <!-- ── Travail retourné — lecture seule ── -->
      <div style="display:flex;align-items:flex-start;gap:16px;padding:16px;background:rgba(239,68,68,.06);border-radius:var(--radius);margin-bottom:16px">
        <i class="fas fa-undo" style="color:var(--danger);font-size:24px;flex-shrink:0;margin-top:2px"></i>
        <div style="flex:1">
          <strong style="color:var(--danger);font-size:15px;display:block;margin-bottom:6px">Travail retourné par l'enseignant</strong>
          <?php if ($existingSubmission['feedback']): ?>
          <div style="padding:10px 14px;background:rgba(255,255,255,.04);border-radius:var(--radius);border-left:3px solid var(--danger)">
            <div style="font-size:11px;font-weight:700;color:var(--danger);margin-bottom:4px;text-transform:uppercase">Commentaire</div>
            <p style="color:var(--text);font-size:13px;font-style:italic;margin:0"><?= e($existingSubmission['feedback']) ?></p>
          </div>
          <?php endif; ?>
          <div style="font-size:11px;color:var(--text-faint);margin-top:8px">
            <i class="fas fa-calendar"></i> Retourné le <?= formatDate($existingSubmission['graded_at'], 'd/m/Y') ?>
          </div>
        </div>
      </div>

      <!-- Verrouillage -->
      <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(99,102,241,.07);border:1px solid rgba(99,102,241,.18);border-radius:var(--radius);margin-bottom:16px">
        <i class="fas fa-lock" style="color:var(--primary-light)"></i>
        <span style="font-size:13px;color:var(--text-muted)">Ce travail a été évalué — vous ne pouvez plus le modifier ni le soumettre à nouveau.</span>
      </div>

      <!-- Consultation du travail soumis -->
      <?php if ($existingSubmission['text_response']): ?>
      <div style="margin-bottom:12px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:6px">Votre réponse soumise</div>
        <div style="padding:14px;background:var(--bg-elevated);border-radius:var(--radius);font-size:13px;line-height:1.7;white-space:pre-wrap;color:var(--text)"><?= e($existingSubmission['text_response']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($existingSubmission['file_path']): ?>
      <?php
        $rFd = json_decode($existingSubmission['file_path'], true);
        $rFp = is_array($rFd) ? ($rFd['path'] ?? '') : $existingSubmission['file_path'];
        $rFn = is_array($rFd) ? ($rFd['name'] ?? basename($rFp)) : basename($rFp);
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--bg-elevated);border-radius:var(--radius)">
        <i class="fas fa-file-alt" style="color:var(--primary-light)"></i>
        <span style="flex:1;font-size:13px"><?= e($rFn) ?></span>
        <?php if ($rFp && $rFp !== 'Array'): ?>
        <a href="<?= e(uploadUrl($rFp)) ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-eye"></i> Consulter</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php elseif ($subStatus === 'submitted' || $subStatus === 'under_review'): ?>
      <!-- ── En attente de correction ── -->
      <div style="display:flex;align-items:center;gap:12px;padding:14px 20px;background:rgba(245,158,11,.08);border-radius:var(--radius)">
        <i class="fas fa-clock" style="color:var(--warning);font-size:20px"></i>
        <div>
          <div style="font-weight:600;color:var(--text)">Correction en attente</div>
          <div style="font-size:12px;color:var(--text-muted)">Soumis le <?= formatDate($existingSubmission['submitted_at'], 'd/m/Y à H:i') ?></div>
        </div>
      </div>

      <?php else: ?>
      <!-- ── Formulaire de soumission (première soumission uniquement) ── -->
      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="submit_case_study">
        <input type="hidden" name="formation_id" value="<?= $enrolledFormationId ?>">

        <div style="margin-bottom:16px">
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:8px">
            <i class="fas fa-pen" style="color:var(--primary-light)"></i> Votre réponse / analyse
          </label>
          <textarea name="text_response" class="form-control" rows="6"
            placeholder="Rédigez votre analyse, vos réponses aux questions, vos observations..."
            style="resize:vertical"></textarea>
        </div>

        <div style="margin-bottom:20px">
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:8px">
            <i class="fas fa-paperclip" style="color:var(--primary-light)"></i> Joindre un document
            <span style="color:var(--text-faint);font-weight:400">(optionnel — PDF, Word, max 10 Mo)</span>
          </label>
          <input type="file" name="submission_file" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.txt">
        </div>

        <button type="submit" class="btn btn-primary"
          onclick="return confirm('Soumettre votre travail à l\'enseignant ?')">
          <i class="fas fa-paper-plane"></i> Soumettre mon travail
        </button>
      </form>
      <?php endif; ?>

    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($resources)): ?>
  <?php
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
  ?>
  <div class="card" style="margin-top:20px">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-paperclip" style="margin-right:8px;color:var(--primary-light)"></i>Ressources complémentaires</h3>
    </div>
    <div class="card-body" style="display:flex;flex-wrap:wrap;gap:10px">
      <?php foreach ($resources as $res):
        $ri = $resIcons[$res['type']] ?? $resIcons['other'];
        $isLink = !empty($res['url']);
        $href   = $isLink ? $res['url'] : uploadUrl($res['file_path']);
      ?>
      <a href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer"
        class="btn btn-secondary"
        style="gap:8px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
        title="<?= e($res['title']) ?>">
        <i class="fas fa-<?= $ri['icon'] ?>" style="color:<?= $ri['color'] ?>;flex-shrink:0"></i>
        <span style="overflow:hidden;text-overflow:ellipsis"><?= e(mb_substr($res['title'],0,40)) ?></span>
        <?php if (!empty($res['file_size'])): ?>
        <span style="color:var(--text-faint);font-size:11px;flex-shrink:0"><?= formatFileSize($res['file_size']) ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- Plein écran -->
<div id="fullscreen-overlay" style="display:none;position:fixed;inset:0;background:#000;z-index:9999;flex-direction:column">
  <div style="display:flex;align-items:center;justify-content:flex-end;padding:8px 14px;background:rgba(0,0,0,.7)">
    <button onclick="closeFullscreen()" style="background:none;border:none;color:white;font-size:22px;cursor:pointer" title="Fermer">
      <i class="fas fa-times"></i>
    </button>
  </div>
  <iframe id="fullscreen-iframe" src="" style="flex:1;border:none;width:100%"></iframe>
</div>

<script>
function openFullscreen() {
  var src = document.getElementById('doc-iframe')?.src || '';
  openFullscreenUrl(src);
}
function openFullscreenUrl(src) {
  document.getElementById('fullscreen-overlay').style.display = 'flex';
  document.getElementById('fullscreen-iframe').src = src;
  document.body.style.overflow = 'hidden';
}
function closeFullscreen() {
  document.getElementById('fullscreen-overlay').style.display = 'none';
  document.getElementById('fullscreen-iframe').src = '';
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeFullscreen(); });
</script>
<?php renderFooter(); ?>
