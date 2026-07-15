<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();

$pdo      = getDB();
$userId   = (int)$_SESSION['user_id'];
$moduleId = (int)($_GET['id'] ?? 0);

if (!$moduleId) { setFlash('error', 'Séance introuvable.'); redirect(url('student/index.php')); }

// ── Charger la séance (module) ────────────────────────────────────────────────
$seance = null;
try {
    $stmt = $pdo->prepare("
        SELECT m.*,
               seq.id AS seq_id, seq.title AS seq_title,
               c.id AS comp_id, c.code AS comp_code, c.title AS comp_title,
               at.id AS at_id, at.code AS at_code, at.title AS at_title,
               rt.id AS rncp_id, rt.rncp_code
        FROM modules m
        LEFT JOIN sequences      seq ON m.sequence_id   = seq.id
        LEFT JOIN competencies   c   ON seq.competency_id  = c.id
        LEFT JOIN activity_types at  ON c.activity_type_id = at.id
        LEFT JOIN rncp_titles    rt  ON at.rncp_title_id   = rt.id
        WHERE m.id = ?
    ");
    $stmt->execute([$moduleId]);
    $seance = $stmt->fetch();
} catch (\Exception $e) {
    // Fallback : query sans les joins (si sequence_id absent de modules)
    try {
        $stmt2 = $pdo->prepare("SELECT * FROM modules WHERE id=?");
        $stmt2->execute([$moduleId]);
        $seance = $stmt2->fetch();
    } catch (\Exception $e2) {}
}
if (!$seance) { setFlash('error', 'Séance introuvable.'); redirect(url('student/index.php')); }

// Séance non publiée = inaccessible aux apprenants
if (isStudent() && isset($seance['is_published']) && !$seance['is_published']) {
    setFlash('error', 'Cette séance n\'est pas disponible pour le moment.');
    redirect(url('student/access/index.php'));
}

// ── Contrôle d'accès ──────────────────────────────────────────────────────────
$isPreview = !empty($_GET['preview']) && !isStudent();
if (isStudent() && !($seance['is_preview'] ?? false)) {
    $scope = [
        'formation_id'    => (int)($seance['formation_id'] ?? 0),
        'module_id'       => $moduleId,
        'sequence_id'     => (int)($seance['seq_id']       ?? 0),
        'competency_id'   => (int)($seance['comp_id']      ?? 0),
        'activity_type_id'=> (int)($seance['at_id']        ?? 0),
        'rncp_title_id'   => (int)($seance['rncp_id']      ?? 0),
    ];
    if (!hasContentAccess($userId, $scope)) {
        setFlash('error', 'Vous n\'avez pas accès à cette séance.');
        redirect(url('student/index.php'));
    }
}

// ── Progression ───────────────────────────────────────────────────────────────
$progStmt = $pdo->prepare('SELECT * FROM module_progress WHERE user_id=? AND module_id=?');
$progStmt->execute([$userId, $moduleId]);
$myProgress = $progStmt->fetch();
$isCompleted = $myProgress && $myProgress['status'] === 'completed';

// Marquer comme démarrée si jamais commencée
if (!$myProgress && !$isPreview && isStudent()) {
    try {
        $pdo->prepare('INSERT IGNORE INTO module_progress (user_id, module_id, status, started_at) VALUES (?,?,?,NOW())')
            ->execute([$userId, $moduleId, 'in_progress']);
    } catch (\Exception $e) { /* table might not have started_at */
        try {
            $pdo->prepare('INSERT IGNORE INTO module_progress (user_id, module_id, status) VALUES (?,?,?)')
                ->execute([$userId, $moduleId, 'in_progress']);
        } catch (\Exception $e2) {}
    }
}

// ── Action : marquer complétée ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    requireCsrf();
    if (!$isCompleted && isStudent()) {
        try {
            $pdo->prepare("INSERT INTO module_progress (user_id, module_id, status, completed_at)
                           VALUES (?,?,'completed',NOW())
                           ON DUPLICATE KEY UPDATE status='completed', completed_at=NOW()")
                ->execute([$userId, $moduleId]);
        } catch (\Exception $e) {
            $pdo->prepare("UPDATE module_progress SET status='completed' WHERE user_id=? AND module_id=?")
                ->execute([$userId, $moduleId]);
        }
        $xp = (int)($seance['xp_reward'] ?? 10);
        if ($xp > 0) {
            addXP($userId, $xp, 'Séance complétée : ' . $seance['title'], 'module', $moduleId);
        }
        setFlash('success', 'Séance validée ! +' . $xp . ' XP');
    }
    redirect(url('student/course/seance.php?id=' . $moduleId));
}

// ── Exercice : charger soumissions et gérer le rendu ─────────────────────────
$exerciseSubmissions = [];
$latestExSub         = null;
if ($contentType === 'exercise' && isStudent() && !$isPreview) {
    try {
        $exStmt = $pdo->prepare('SELECT * FROM exercise_submissions WHERE module_id=? AND user_id=? ORDER BY submitted_at DESC');
        $exStmt->execute([$moduleId, $userId]);
        $exerciseSubmissions = $exStmt->fetchAll();
        $latestExSub = $exerciseSubmissions[0] ?? null;
    } catch (\Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_exercise' && isStudent() && !$isPreview) {
    requireCsrf();
    $textResponse  = trim($_POST['text_response'] ?? '');
    $uploadedFiles = [];
    foreach (($_FILES['exercise_files']['name'] ?? []) as $i => $fname) {
        if (empty($fname) || $_FILES['exercise_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $fFile = ['name' => $fname, 'tmp_name' => $_FILES['exercise_files']['tmp_name'][$i],
                  'error' => $_FILES['exercise_files']['error'][$i],
                  'size'  => $_FILES['exercise_files']['size'][$i],
                  'type'  => $_FILES['exercise_files']['type'][$i]];
        $up = uploadFile($fFile, 'exercises', ALLOWED_DOC_TYPES, MAX_UPLOAD_SIZE);
        if ($up['success']) {
            $uploadedFiles[] = ['path' => $up['path'],
                                'name' => pathinfo($fname, PATHINFO_FILENAME),
                                'ext'  => strtolower(pathinfo($fname, PATHINFO_EXTENSION))];
        }
    }
    if (!$uploadedFiles && !$textResponse) {
        setFlash('error', 'Veuillez joindre au moins un fichier ou saisir une réponse.');
        redirect(url('student/course/seance.php?id=' . $moduleId));
    }
    try {
        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM exercise_submissions WHERE module_id=? AND user_id=?');
        $cntStmt->execute([$moduleId, $userId]);
        $attemptNum = (int)$cntStmt->fetchColumn() + 1;
        $pdo->prepare('INSERT INTO exercise_submissions (module_id, user_id, attempt_number, files, text_response) VALUES (?,?,?,?,?)')
            ->execute([$moduleId, $userId, $attemptNum,
                       $uploadedFiles ? json_encode($uploadedFiles, JSON_UNESCAPED_UNICODE) : null,
                       $textResponse ?: null]);
        auditLog('exercise_submitted', 'exercise_submissions', (int)$pdo->lastInsertId());
        setFlash('success', 'Votre exercice a bien été soumis — votre enseignant le corrigera prochainement.');
    } catch (\Exception $e) {
        setFlash('error', 'Erreur lors de la soumission.');
    }
    redirect(url('student/course/seance.php?id=' . $moduleId));
}

// ── Séances adjacentes dans la même séquence ──────────────────────────────────
$prevSeance = $nextSeance = null;
if ($seance['seq_id']) {
    $siblings = $pdo->prepare("SELECT id, title FROM modules WHERE sequence_id=? AND (is_published IS NULL OR is_published=1) ORDER BY order_num, id");
    $siblings->execute([$seance['seq_id']]);
    $sibList = $siblings->fetchAll();
    foreach ($sibList as $i => $sib) {
        if ($sib['id'] == $moduleId) {
            if ($i > 0)                   $prevSeance = $sibList[$i - 1];
            if ($i < count($sibList) - 1) $nextSeance = $sibList[$i + 1];
            break;
        }
    }
}

// ── Contenu à afficher ────────────────────────────────────────────────────────
$contentType = $seance['content_type'] ?? 'text';
$contentUrl  = $seance['content_url']  ?? '';
$contentBody = $seance['content_body'] ?? '';

// Pour les séances de type quiz : récupérer l'id du quiz lié
$linkedQuizId = 0;
if ($contentType === 'quiz') {
    $qStmt = $pdo->prepare('SELECT id FROM quizzes WHERE module_id = ? LIMIT 1');
    $qStmt->execute([$moduleId]);
    $linkedQuizId = (int)($qStmt->fetchColumn() ?: 0);
}

// Convertir YouTube/Vimeo en URL embed
function makeEmbedUrl(string $url): string {
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1] . '?rel=0';
    }
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }
    return $url;
}

$embedUrl = ($contentType === 'video') ? makeEmbedUrl($contentUrl) : $contentUrl;

$pageTitle = e($seance['title']);
renderHead($pageTitle);
renderSidebar('student');
renderTopbar($pageTitle, [
    ['Mon espace', url('student/index.php')],
    ['Accès complémentaires', url('student/access/index.php')],
    [$pageTitle, ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div style="max-width:860px;margin:0 auto">

    <!-- Breadcrumb hiérarchique -->
    <?php if ($seance['rncp_code'] || $seance['at_code'] || $seance['seq_title']): ?>
    <div style="display:flex;align-items:center;gap:6px;font-size:11px;color:var(--text-muted);margin-bottom:12px;flex-wrap:wrap">
      <?php if ($seance['rncp_code']): ?>
        <span style="background:rgba(139,92,246,.15);color:#a78bfa;border-radius:4px;padding:1px 6px;font-weight:700"><?= e($seance['rncp_code']) ?></span>
        <i class="fas fa-chevron-right" style="font-size:8px"></i>
      <?php endif; ?>
      <?php if ($seance['at_code']): ?>
        <span><?= e($seance['at_code']) ?></span>
        <i class="fas fa-chevron-right" style="font-size:8px"></i>
      <?php endif; ?>
      <?php if ($seance['comp_code']): ?>
        <span><?= e($seance['comp_code']) ?></span>
        <i class="fas fa-chevron-right" style="font-size:8px"></i>
      <?php endif; ?>
      <?php if ($seance['seq_title']): ?>
        <span><?= e(mb_substr($seance['seq_title'], 0, 50)) ?></span>
        <i class="fas fa-chevron-right" style="font-size:8px"></i>
      <?php endif; ?>
      <span style="color:var(--text-secondary)"><?= $pageTitle ?></span>
    </div>
    <?php endif; ?>

    <!-- Titre et statut -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap">
      <div>
        <h1 style="font-size:20px;font-weight:800;margin:0 0 4px"><?= $pageTitle ?></h1>
        <div style="display:flex;align-items:center;gap:10px;font-size:12px;color:var(--text-muted)">
          <span><i class="<?= getContentTypeIcon($contentType) ?>"></i> <?= ucfirst($contentType) ?></span>
          <?php if ($seance['duration_hours']): ?><span><i class="fas fa-clock"></i> <?= $seance['duration_hours'] ?>h</span><?php endif; ?>
          <?php if ($seance['xp_reward'] ?? 0): ?><span style="color:#f59e0b"><i class="fas fa-bolt"></i> +<?= $seance['xp_reward'] ?> XP</span><?php endif; ?>
          <?php if ($isCompleted): ?><span style="color:var(--success);font-weight:700"><i class="fas fa-check-circle"></i> Validée</span><?php endif; ?>
        </div>
      </div>
      <?php if (!$isCompleted && isStudent()): ?>
      <button id="btn-complete" onclick="validateSeance(this)" class="btn btn-primary" style="flex-shrink:0">
        <i class="fas fa-check"></i> Valider la séance
      </button>
      <?php endif; ?>
    </div>

    <!-- Contenu -->
    <div class="card" style="margin-bottom:16px;overflow:hidden">
      <?php if ($contentType === 'video'):
        $videoFile = !$embedUrl && ($seance['file_path'] ?? '') ? uploadUrl($seance['file_path']) : '';
        $finalVideoUrl = $embedUrl ?: $videoFile;
      ?>
        <?php if ($finalVideoUrl): ?>
        <?php if (str_contains($finalVideoUrl, 'youtube.com/embed') || str_contains($finalVideoUrl, 'vimeo.com')): ?>
        <div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden">
          <iframe src="<?= e($finalVideoUrl) ?>" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allowfullscreen loading="lazy"></iframe>
        </div>
        <?php else: ?>
        <video controls style="width:100%;max-height:480px;background:#000;display:block">
          <source src="<?= e($finalVideoUrl) ?>">
          Votre navigateur ne supporte pas la lecture vidéo.
        </video>
        <?php endif; ?>
        <?php endif; ?>

      <?php elseif ($contentType === 'pdf' || $contentType === 'exercise'):
        $filePath = $seance['file_path'] ?? '';
        // file_path peut être un JSON (pages multiples) ou un chemin simple
        $pdfPages = [];
        if ($filePath) {
            $decoded = json_decode($filePath, true);
            if (is_array($decoded)) {
                $pdfPages = array_map(fn($p) => is_array($p)
                    ? ['url' => $p['url'] ?? uploadUrl($p['path'] ?? ''), 'name' => $p['name'] ?? pathinfo($p['path'] ?? '', PATHINFO_FILENAME)]
                    : ['url' => uploadUrl($p), 'name' => pathinfo($p, PATHINFO_FILENAME)],
                $decoded);
            } else {
                $pdfPages = [['url' => uploadUrl($filePath), 'name' => pathinfo($filePath, PATHINFO_FILENAME)]];
            }
        } elseif ($contentUrl) {
            $pdfPages = [['url' => $contentUrl, 'name' => 'Contenu']];
        }
      ?>
        <?php if ($pdfPages): ?>
          <?php if (count($pdfPages) > 1): ?>
            <?php foreach ($pdfPages as $pi => $p): ?>
            <div style="<?= $pi > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
              <div style="display:flex;align-items:center;gap:10px;padding:8px 14px;background:var(--bg-card)">
                <span style="width:22px;height:22px;background:#ef4444;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:white;flex-shrink:0"><?= $pi+1 ?></span>
                <span style="flex:1;font-size:13px;font-weight:600"><?= e($p['name']) ?></span>
                <a href="<?= e($p['url']) ?>" target="_blank" style="color:var(--text-muted);font-size:13px;text-decoration:none" title="Ouvrir dans un onglet"><i class="fas fa-external-link-alt"></i></a>
              </div>
              <div style="height:650px">
                <iframe src="<?= e($p['url']) ?>#toolbar=1&navpanes=1" style="width:100%;height:100%;border:0" loading="lazy"></iframe>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
          <div style="position:relative">
            <div id="pdf-wrapper" style="position:relative;height:650px">
              <iframe id="pdf-iframe" src="<?= e($pdfPages[0]['url']) ?>#toolbar=1&navpanes=1" style="width:100%;height:100%;border:0" loading="lazy"></iframe>
              <button onclick="togglePdfFullscreen()" id="pdf-fs-btn"
                style="position:absolute;top:10px;right:10px;z-index:10;background:rgba(0,0,0,.55);border:none;border-radius:6px;padding:6px 10px;cursor:pointer;color:#fff;font-size:13px;display:flex;align-items:center;gap:6px;backdrop-filter:blur(4px)"
                title="Plein écran">
                <i class="fas fa-expand" id="pdf-fs-icon"></i>
              </button>
            </div>
          </div>
          <?php endif; ?>
        <?php else: ?>
        <div style="padding:24px;text-align:center;color:var(--text-muted);font-style:italic">
          <i class="fas fa-info-circle" style="margin-right:6px"></i> Contenu non disponible.
        </div>
        <?php endif; ?>

      <?php elseif ($contentType === 'audio'):
        $audioSrc = $contentUrl ?: ($seance['file_path'] ? uploadUrl($seance['file_path']) : '');
      ?>
        <?php if ($audioSrc): ?>
        <div style="padding:24px">
          <audio controls style="width:100%">
            <source src="<?= e($audioSrc) ?>">
            Votre navigateur ne supporte pas la lecture audio.
          </audio>
        </div>
        <?php endif; ?>

      <?php elseif ($contentType === 'text' || $contentBody): ?>
        <div class="rich-content" style="padding:24px 28px;line-height:1.7">
          <?= $contentBody ?: '<p style="color:var(--text-muted);font-style:italic">Aucun contenu.</p>' ?>
        </div>

      <?php elseif ($contentUrl): ?>
        <div style="padding:24px;text-align:center">
          <a href="<?= e($contentUrl) ?>" target="_blank" rel="noopener" class="btn btn-primary">
            <i class="fas fa-external-link-alt"></i> Ouvrir le contenu
          </a>
        </div>

      <?php elseif ($contentType === 'quiz'): ?>
        <?php if ($linkedQuizId): ?>
        <div style="padding:40px 24px;text-align:center">
          <div style="font-size:48px;margin-bottom:16px">📝</div>
          <p style="color:var(--text-muted);margin-bottom:24px">Cette séance comprend un quiz d'évaluation.</p>
          <a href="<?= url('student/quiz/take.php?id=' . $linkedQuizId) ?>" class="btn btn-primary btn-lg">
            <i class="fas fa-pencil-alt"></i> Commencer le quiz
          </a>
        </div>
        <?php else: ?>
        <div style="padding:24px;text-align:center;color:var(--text-muted);font-style:italic">
          <i class="fas fa-info-circle" style="margin-right:6px"></i> Quiz non encore configuré.
        </div>
        <?php endif; ?>

      <?php else: ?>
        <div style="padding:24px;text-align:center;color:var(--text-muted);font-style:italic">
          <i class="fas fa-info-circle" style="margin-right:6px"></i> Contenu non disponible.
        </div>
      <?php endif; ?>
    </div>

    <!-- ── Rendu de l'exercice ── -->
    <?php if ($contentType === 'exercise' && isStudent() && !$isPreview):
      $exStatus   = $latestExSub['status'] ?? null;
      $canSubmit  = !$exStatus || in_array($exStatus, ['returned', 'graded']);
      $borderColor = $exStatus === 'graded' ? 'rgba(16,185,129,.4)' : ($exStatus === 'returned' ? 'rgba(239,68,68,.3)' : ($exStatus ? 'rgba(245,158,11,.3)' : 'rgba(99,102,241,.25)'));
    ?>
    <div class="card" style="margin-bottom:16px;border:2px solid <?= $borderColor ?>">
      <div class="card-header">
        <h3 class="card-title"><i class="fas fa-paper-plane" style="color:var(--primary-light)"></i> Rendre l'exercice</h3>
        <?php if ($exStatus): ?>
        <?php $statusLabels = ['submitted'=>['Soumis','warning'],'under_review'=>['En correction','info'],'graded'=>['Corrigé','success'],'returned'=>['Retourné','danger']]; ?>
        <span class="badge badge-<?= $statusLabels[$exStatus][1] ?? 'secondary' ?>"><?= $statusLabels[$exStatus][0] ?? $exStatus ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body">

        <?php if ($latestExSub): ?>
        <?php if ($exStatus === 'graded'): ?>
        <div style="display:flex;align-items:flex-start;gap:14px;padding:14px;background:rgba(16,185,129,.07);border-radius:var(--radius);margin-bottom:16px">
          <i class="fas fa-check-circle" style="color:var(--success);font-size:22px;flex-shrink:0;margin-top:2px"></i>
          <div style="flex:1">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
              <strong style="color:var(--success)">Exercice corrigé</strong>
              <?php if ($latestExSub['score'] !== null): ?><span class="badge badge-success" style="font-size:14px;padding:4px 12px"><?= $latestExSub['score'] ?><?php if ($latestExSub['max_score']): ?>/<?= $latestExSub['max_score'] ?><?php endif; ?></span><?php endif; ?>
              <?php if ($latestExSub['grade']): ?><span class="badge badge-primary"><?= e($latestExSub['grade']) ?></span><?php endif; ?>
            </div>
            <?php if ($latestExSub['feedback']): ?><p style="color:var(--text-muted);font-size:13px;margin:0;font-style:italic">"<?= e($latestExSub['feedback']) ?>"</p><?php endif; ?>
          </div>
        </div>
        <?php elseif ($exStatus === 'under_review'): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:rgba(14,165,233,.07);border:1px solid rgba(14,165,233,.2);border-radius:var(--radius);margin-bottom:16px">
          <i class="fas fa-search" style="color:var(--info);font-size:18px"></i>
          <div><div style="font-weight:600">En cours de correction par votre enseignant</div>
          <div style="font-size:12px;color:var(--text-muted)">Tentative <?= $latestExSub['attempt_number'] ?> · Soumis le <?= formatDate($latestExSub['submitted_at'], 'd/m/Y à H:i') ?></div></div>
        </div>
        <?php elseif ($exStatus === 'submitted'): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);border-radius:var(--radius);margin-bottom:16px">
          <i class="fas fa-clock" style="color:var(--warning);font-size:18px"></i>
          <div><div style="font-weight:600">En attente de correction</div>
          <div style="font-size:12px;color:var(--text-muted)">Tentative <?= $latestExSub['attempt_number'] ?> · Soumis le <?= formatDate($latestExSub['submitted_at'], 'd/m/Y à H:i') ?></div></div>
        </div>
        <?php elseif ($exStatus === 'returned'): ?>
        <div style="padding:12px 16px;background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:var(--radius);margin-bottom:16px">
          <div style="display:flex;align-items:center;gap:8px;font-weight:600;color:var(--danger);margin-bottom:4px"><i class="fas fa-undo"></i> Travail retourné — à réviser</div>
          <?php if ($latestExSub['feedback']): ?><p style="font-size:13px;color:var(--text-muted);margin:0;font-style:italic"><?= e($latestExSub['feedback']) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (count($exerciseSubmissions) > 1): ?>
        <details style="margin-bottom:14px">
          <summary style="font-size:12px;color:var(--text-muted);cursor:pointer">Voir les <?= count($exerciseSubmissions) ?> soumissions précédentes</summary>
          <div style="margin-top:8px;display:flex;flex-direction:column;gap:6px">
          <?php foreach ($exerciseSubmissions as $idx => $sub): if ($idx === 0) continue; ?>
          <div style="font-size:12px;color:var(--text-muted);padding:6px 10px;background:var(--bg-elevated);border-radius:var(--radius)">
            Tentative <?= $sub['attempt_number'] ?> — <?= formatDate($sub['submitted_at'], 'd/m/Y H:i') ?>
            <span class="badge badge-<?= ['submitted'=>'warning','graded'=>'success','returned'=>'danger','under_review'=>'info'][$sub['status']] ?? 'secondary' ?>" style="margin-left:8px"><?= $sub['status'] ?></span>
          </div>
          <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($canSubmit): ?>
        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="submit_exercise">
          <div style="margin-bottom:14px">
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:8px">
              <i class="fas fa-paperclip" style="color:var(--primary-light)"></i> Joindre votre production
              <span style="color:var(--text-muted);font-weight:400">(PDF, Word, PPT, Excel, ZIP — plusieurs fichiers acceptés)</span>
            </label>
            <input type="file" name="exercise_files[]" multiple
              accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"
              class="form-control">
          </div>
          <div style="margin-bottom:18px">
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:7px">
              <i class="fas fa-pen" style="color:var(--primary-light)"></i> Commentaire / démarche
              <span style="color:var(--text-muted);font-weight:400">(optionnel)</span>
            </label>
            <textarea name="text_response" class="form-control" rows="4"
              placeholder="Décrivez votre démarche, vos résultats, vos questions…"
              style="resize:vertical"></textarea>
          </div>
          <button type="submit" class="btn btn-primary" onclick="return confirm('Soumettre votre production à l\'enseignant ?')">
            <i class="fas fa-paper-plane"></i>
            <?= $latestExSub ? 'Soumettre à nouveau' : 'Rendre l\'exercice' ?>
          </button>
        </form>
        <?php elseif ($exStatus === 'submitted' || $exStatus === 'under_review'): ?>
        <p style="font-size:13px;color:var(--text-muted);margin:0"><i class="fas fa-lock" style="margin-right:6px"></i>Nouvelle soumission impossible tant que votre enseignant n'a pas rendu son verdict.</p>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

    <!-- Description -->
    <?php if ($seance['description']): ?>
    <div class="card" style="margin-bottom:16px;padding:18px 20px">
      <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:var(--text-secondary)">À propos de cette séance</div>
      <div style="font-size:13px;color:var(--text-secondary);line-height:1.6"><?= nl2br(e($seance['description'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Navigation précédent / suivant -->
    <?php if ($prevSeance || $nextSeance): ?>
    <div style="display:flex;justify-content:space-between;gap:10px;margin-top:8px">
      <?php if ($prevSeance): ?>
      <a href="<?= url('student/course/seance.php?id=' . $prevSeance['id']) ?>" class="btn btn-ghost btn-sm">
        <i class="fas fa-chevron-left"></i> <?= e(mb_substr($prevSeance['title'], 0, 40)) ?>
      </a>
      <?php else: ?><div></div><?php endif; ?>
      <?php if ($nextSeance): ?>
      <a href="<?= url('student/course/seance.php?id=' . $nextSeance['id']) ?>" class="btn btn-ghost btn-sm" style="text-align:right">
        <?= e(mb_substr($nextSeance['title'], 0, 40)) ?> <i class="fas fa-chevron-right"></i>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:16px;text-align:center">
      <a href="<?= url('student/access/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour aux accès</a>
    </div>

  </div>
</div>
<style>
.rich-content h1,.rich-content h2,.rich-content h3 { font-weight:700; margin:1em 0 .4em; }
.rich-content p { margin:0 0 .8em; }
.rich-content ul,.rich-content ol { padding-left:1.5em; margin:0 0 .8em; }
.rich-content a { color:var(--primary-light); }
.rich-content blockquote { border-left:3px solid var(--primary-light); padding-left:12px; color:var(--text-muted); }
#pdf-wrapper { position:relative }
@keyframes xpPop { from { opacity:0;transform:scale(.7) translateY(20px); } to { opacity:1;transform:scale(1) translateY(0); } }
</style>

<!-- Popup XP -->
<div id="xp-popup" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.75);align-items:center;justify-content:center;backdrop-filter:blur(6px)">
  <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:20px;padding:40px 48px;text-align:center;max-width:420px;width:90%;animation:xpPop .35s cubic-bezier(.34,1.56,.64,1) both">
    <div id="xp-icon" style="font-size:56px;margin-bottom:16px">🎉</div>
    <h2 id="xp-title" style="font-size:22px;font-weight:800;color:white;margin-bottom:8px">Séance validée !</h2>
    <p id="xp-subtitle" style="font-size:14px;color:var(--text-muted);margin-bottom:24px">Excellente progression, continuez ainsi !</p>
    <div id="xp-badge" style="display:inline-flex;align-items:center;gap:10px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);border-radius:50px;padding:10px 24px;margin-bottom:28px">
      <i class="fas fa-bolt" style="color:#f59e0b;font-size:20px"></i>
      <span id="xp-amount" style="font-size:28px;font-weight:900;color:#f59e0b"></span>
    </div>
    <div id="levelup-banner" style="display:none;background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.4);border-radius:12px;padding:12px 20px;margin-bottom:20px">
      <i class="fas fa-arrow-up" style="color:var(--primary-light)"></i>
      <span id="levelup-text" style="font-size:14px;font-weight:700;color:var(--primary-light);margin-left:6px"></span>
    </div>
    <button onclick="xpContinue()" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px">
      <i class="fas fa-arrow-right"></i> Continuer
    </button>
  </div>
</div>
<script>
const MODULE_ID  = <?= (int)$moduleId ?>;
const NEXT_URL   = <?= $nextSeance ? json_encode(url('student/course/seance.php?id='.$nextSeance['id'])) : 'null' ?>;

async function validateSeance(btn) {
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validation…'; }
  try {
    const res  = await fetch('<?= url('api/module_progress.php') ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'complete', module_id: MODULE_ID })
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Erreur');

    if (!data.already_completed && data.xp > 0) {
      document.getElementById('xp-icon').textContent    = NEXT_URL ? '🎉' : '🏆';
      document.getElementById('xp-title').textContent   = NEXT_URL ? 'Séance validée !' : 'Bravo !';
      document.getElementById('xp-subtitle').textContent = NEXT_URL
        ? 'Excellente progression, continuez ainsi !'
        : 'Vous avez terminé toutes les séances disponibles.';
      document.getElementById('xp-amount').textContent  = '+' + data.xp + ' XP';
      if (data.level_up) {
        document.getElementById('levelup-banner').style.display = 'block';
        document.getElementById('levelup-text').textContent = 'Niveau ' + data.new_level + ' atteint !';
      }
      document.getElementById('xp-popup').style.display = 'flex';
    } else {
      xpContinue();
    }
  } catch(e) {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Valider la séance'; }
    alert('Erreur lors de la validation. Veuillez réessayer.');
  }
}

function xpContinue() {
  document.getElementById('xp-popup').style.display = 'none';
  window.location.href = NEXT_URL || window.location.pathname + window.location.search;
}

document.getElementById('xp-popup').addEventListener('click', function(e) {
  if (e.target === this) xpContinue();
});

var _pdfFs = false;
function togglePdfFullscreen() {
  var wrap = document.getElementById('pdf-wrapper');
  var cont = document.getElementById('pdf-container');
  var icon = document.getElementById('pdf-fs-icon');
  _pdfFs = !_pdfFs;
  if (_pdfFs) {
    wrap.style.cssText  = 'position:fixed;inset:0;z-index:9999;background:#000;margin:0';
    cont.style.height   = '100dvh';
    icon.className      = 'fas fa-compress';
    document.body.style.overflow = 'hidden';
  } else {
    wrap.style.cssText  = 'position:relative';
    cont.style.height   = '600px';
    icon.className      = 'fas fa-expand';
    document.body.style.overflow = '';
  }
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && _pdfFs) togglePdfFullscreen();
});
</script>
<?php renderFooter(); ?>
