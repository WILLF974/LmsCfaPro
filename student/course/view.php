<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$lessonId = (int)($_GET['id'] ?? 0);
$formationId = (int)($_GET['formation_id'] ?? 0);

// Load lesson
$stmt = $pdo->prepare("
    SELECT l.*, mo.title as module_title, mo.id as module_id, f.title as formation_title, f.id as fid
    FROM lessons l
    JOIN modules mo ON l.module_id=mo.id
    JOIN formations f ON mo.formation_id=f.id
    WHERE l.id=?
");
$stmt->execute([$lessonId]);
$lesson = $stmt->fetch();
if (!$lesson) { setFlash('error','Capsule introuvable.'); redirect(url('student/index.php')); }
$formationId = $formationId ?: $lesson['fid'];
$isPreview   = !empty($_GET['preview']) && !isStudent();
$previewParam = $isPreview ? '&preview=1' : '';

// Check enrollment
if (isStudent()) {
    $enrolled = $pdo->prepare("SELECT id FROM enrollments WHERE user_id=? AND formation_id=? AND status IN ('active','completed')");
    $enrolled->execute([$userId, $formationId]);
    if (!$enrolled->fetch() && !$lesson['is_preview']) {
        setFlash('error','Vous n\'êtes pas inscrit à cette formation.');
        redirect(url('student/index.php'));
    }
}

// All lessons in this formation for the sidebar
$allLessons = $pdo->prepare("
    SELECT l.*, mo.title as module_title, mo.id as mid, mo.order_num as mo_order,
           lp.status as progress_status
    FROM lessons l
    JOIN modules mo ON l.module_id=mo.id
    LEFT JOIN lesson_progress lp ON l.id=lp.lesson_id AND lp.user_id=?
    WHERE mo.formation_id=?
    ORDER BY mo.order_num, l.order_num
");
$allLessons->execute([$userId, $formationId]);
$lessonsByModule = [];
foreach ($allLessons->fetchAll() as $ls) {
    $lessonsByModule[$ls['mid']]['title'] = $ls['module_title'];
    $lessonsByModule[$ls['mid']]['lessons'][] = $ls;
}

// Resources
$resources = $pdo->prepare('SELECT * FROM lesson_resources WHERE lesson_id=?');
$resources->execute([$lessonId]);
$resFiles = $resources->fetchAll();

// Progress
$progress = $pdo->prepare('SELECT * FROM lesson_progress WHERE user_id=? AND lesson_id=?');
$progress->execute([$userId, $lessonId]);
$myProgress = $progress->fetch();

$isAlreadyCompleted = $myProgress && $myProgress['status'] === 'completed';

// Mark as started (seulement si jamais commencée)
if (!$myProgress && !$isPreview) {
    $pdo->prepare('INSERT INTO lesson_progress (user_id,lesson_id,status,started_at) VALUES (?,?,"in_progress",NOW())')->execute([$userId, $lessonId]);
}

// Quiz for this lesson
$quiz = $pdo->prepare('SELECT * FROM quizzes WHERE lesson_id=? LIMIT 1');
$quiz->execute([$lessonId]);
$lessonQuiz = $quiz->fetch();

// Check if quiz already passed
$quizPassed = false;
if ($lessonQuiz) {
    $attempt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE user_id=? AND quiz_id=? AND passed=1 LIMIT 1');
    $attempt->execute([$userId, $lessonQuiz['id']]);
    $quizPassed = (bool)$attempt->fetch();
}

// Prev / Next
$stmt2 = $pdo->prepare("
    SELECT l.id, l.title FROM lessons l
    JOIN modules mo ON l.module_id=mo.id
    WHERE mo.formation_id=? AND (mo.order_num < (SELECT mo2.order_num FROM modules mo2 JOIN lessons l2 ON l2.module_id=mo2.id WHERE l2.id=?)
      OR (mo.order_num=(SELECT mo2.order_num FROM modules mo2 JOIN lessons l2 ON l2.module_id=mo2.id WHERE l2.id=?) AND l.order_num < (SELECT l3.order_num FROM lessons l3 WHERE l3.id=?)))
    ORDER BY mo.order_num DESC, l.order_num DESC LIMIT 1
");
$stmt2->execute([$formationId, $lessonId, $lessonId, $lessonId]);
$prevLesson = $stmt2->fetch();

$stmt3 = $pdo->prepare("
    SELECT l.id, l.title FROM lessons l
    JOIN modules mo ON l.module_id=mo.id
    WHERE mo.formation_id=? AND (mo.order_num > (SELECT mo2.order_num FROM modules mo2 JOIN lessons l2 ON l2.module_id=mo2.id WHERE l2.id=?)
      OR (mo.order_num=(SELECT mo2.order_num FROM modules mo2 JOIN lessons l2 ON l2.module_id=mo2.id WHERE l2.id=?) AND l.order_num > (SELECT l3.order_num FROM lessons l3 WHERE l3.id=?)))
    ORDER BY mo.order_num, l.order_num LIMIT 1
");
$stmt3->execute([$formationId, $lessonId, $lessonId, $lessonId]);
$nextLesson = $stmt3->fetch();

// Render
function embedVideoUrl(string $url): string {
    if (preg_match('/youtube\.com\/watch\?v=([\w-]+)/', $url, $m)) return "https://www.youtube.com/embed/{$m[1]}?rel=0&autoplay=1";
    if (preg_match('/youtu\.be\/([\w-]+)/', $url, $m)) return "https://www.youtube.com/embed/{$m[1]}?rel=0";
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) return "https://player.vimeo.com/video/{$m[1]}?autoplay=1";
    return $url;
}

renderHead(e($lesson['title']));
renderSidebar($isPreview ? 'student' : (isStudent() ? 'student' : (isTeacher() ? 'teacher' : 'admin')));
renderTopbar($lesson['title'], [[$lesson['formation_title'], url('student/formations/view.php?id='.$formationId)], ['Capsule', '']]);
?>
<?php if ($isPreview): ?>
<div style="background:rgba(245,158,11,.12);border-bottom:1px solid rgba(245,158,11,.35);padding:10px 24px;display:flex;align-items:center;gap:12px;flex-shrink:0">
  <i class="fas fa-eye" style="color:#f59e0b"></i>
  <span style="font-size:13px;font-weight:600;color:#f59e0b">Mode prévisualisation enseignant</span>
  <span style="font-size:13px;color:var(--text-muted)">— La progression n'est pas enregistrée.</span>
  <button onclick="window.close()" style="margin-left:auto;background:none;border:1px solid rgba(245,158,11,.4);color:#f59e0b;border-radius:6px;padding:4px 12px;cursor:pointer;font-size:12px">
    <i class="fas fa-times"></i> Fermer
  </button>
</div>
<?php endif; ?>
<div class="page-content" style="padding:0;display:grid;grid-template-columns:1fr 320px;height:calc(100vh - var(--topbar-h)<?= $isPreview ? ' - 44px' : '' ?>);overflow:hidden">

  <!-- Main content -->
  <div style="overflow-y:auto;padding:24px">
    <?php if ($isPreview): ?><?= renderFlash() ?><?php endif; ?>

    <!-- Video / Content Player -->
    <div style="margin-bottom:20px">
      <?php if ($lesson['content_type'] === 'video'): ?>
      <?php $videoUrl = $lesson['content_url']; ?>
      <?php if (preg_match('/(youtube|vimeo)/i', $videoUrl ?? '')): ?>
      <div class="lesson-player" style="border-radius:var(--radius-lg);overflow:hidden">
        <iframe src="<?= e(embedVideoUrl($videoUrl)) ?>" frameborder="0" allowfullscreen allow="autoplay; encrypted-media"></iframe>
      </div>
      <?php elseif ($lesson['file_path']): ?>
      <div class="lesson-player" style="border-radius:var(--radius-lg);overflow:hidden">
        <video id="lesson-video" controls preload="metadata" style="width:100%;height:100%">
          <source src="<?= e(uploadUrl($lesson['file_path'])) ?>" type="video/mp4">
          Votre navigateur ne supporte pas la vidéo HTML5.
        </video>
      </div>
      <?php endif; ?>

      <?php elseif ($lesson['content_type'] === 'pdf'): ?>
      <?php if ($lesson['file_path']): ?>
      <div style="position:relative">
        <div id="doc-viewer" style="background:var(--bg-elevated);border-radius:var(--radius-lg);overflow:hidden;height:600px">
          <iframe id="doc-iframe" src="<?= e(uploadUrl($lesson['file_path'])) ?>#toolbar=1&navpanes=1" style="width:100%;height:100%;border:none"></iframe>
        </div>
        <button onclick="openFullscreen()" style="position:absolute;top:12px;right:12px;background:rgba(0,0,0,.65);border:none;color:white;border-radius:8px;padding:8px 12px;cursor:pointer;display:flex;align-items:center;gap:6px;font-size:13px;backdrop-filter:blur(4px)">
          <i class="fas fa-expand"></i> Plein écran
        </button>
      </div>
      <?php endif; ?>

      <?php elseif (in_array($lesson['content_type'], ['document','presentation'])): ?>
      <?php $docFile = $lesson['file_path'] ? uploadUrl($lesson['file_path']) : null; ?>
      <?php if ($docFile): ?>
      <?php
        $ext = strtolower(pathinfo($lesson['file_path'], PATHINFO_EXTENSION));
        $officeExts = ['doc','docx','xls','xlsx','ppt','pptx'];
        $iframeSrc = in_array($ext, $officeExts)
          ? 'https://view.officeapps.live.com/op/embed.aspx?src=' . urlencode($docFile)
          : $docFile;
      ?>
      <div style="position:relative">
        <div id="doc-viewer" style="background:var(--bg-elevated);border-radius:var(--radius-lg);overflow:hidden;height:600px">
          <iframe id="doc-iframe" src="<?= e($iframeSrc) ?>" style="width:100%;height:100%;border:none"></iframe>
        </div>
        <button onclick="openFullscreen()" style="position:absolute;top:12px;right:12px;background:rgba(0,0,0,.65);border:none;color:white;border-radius:8px;padding:8px 12px;cursor:pointer;display:flex;align-items:center;gap:6px;font-size:13px;backdrop-filter:blur(4px)">
          <i class="fas fa-expand"></i> Plein écran
        </button>
      </div>
      <?php endif; ?>

      <?php elseif ($lesson['content_type'] === 'text'): ?>
      <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:32px">
        <?= $lesson['content_body'] ?: '<p style="color:var(--text-muted)">Contenu non disponible.</p>' ?>
      </div>

      <?php elseif ($lesson['content_type'] === 'link'): ?>
      <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-lg);padding:40px;text-align:center">
        <i class="fas fa-external-link-alt" style="font-size:48px;color:var(--info);margin-bottom:16px;display:block"></i>
        <h3 style="margin-bottom:12px"><?= e($lesson['title']) ?></h3>
        <a href="<?= e($lesson['content_url']) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-lg" onclick="setTimeout(()=>markComplete(), 5000)">
          <i class="fas fa-external-link-alt"></i> Ouvrir la ressource
        </a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Lesson info -->
    <div class="card" style="margin-bottom:16px">
      <div class="card-body">
        <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
          <span class="badge badge-primary"><i class="<?= getContentTypeIcon($lesson['content_type']) ?>"></i> <?= ucfirst($lesson['content_type']) ?></span>
          <?php if ($lesson['duration_minutes']): ?><span class="badge badge-secondary"><i class="fas fa-clock"></i> <?= formatDuration($lesson['duration_minutes']) ?></span><?php endif; ?>
          <?php if ($isAlreadyCompleted): ?>
          <span class="badge badge-success"><i class="fas fa-check"></i> Déjà validée</span>
          <?php else: ?>
          <span class="badge badge-warning"><i class="fas fa-bolt"></i> +<?= $lesson['xp_reward'] ?> XP à gagner</span>
          <?php endif; ?>
        </div>
        <h1 style="font-size:22px;margin-bottom:8px"><?= e($lesson['title']) ?></h1>
        <?php if ($lesson['description']): ?><p style="color:var(--text-muted);font-size:14px"><?= e($lesson['description']) ?></p><?php endif; ?>
        <?php if ($isPreview): ?>
        <div style="display:flex;gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);flex-wrap:wrap">
          <a href="<?= url('teacher/courses/create.php?id='.$lesson['id']) ?>" class="btn btn-secondary btn-sm">
            <i class="fas fa-edit"></i> Modifier la capsule
          </a>
          <form method="POST" action="<?= url('teacher/courses/delete.php') ?>" onsubmit="return confirm('Supprimer définitivement cette capsule et toutes ses ressources ?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="lesson">
            <input type="hidden" name="lesson_id" value="<?= $lesson['id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3)">
              <i class="fas fa-trash"></i> Supprimer la capsule
            </button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Resources -->
    <?php if (!empty($resFiles)): ?>
    <div class="card" style="margin-bottom:16px">
      <div class="card-header"><h3 class="card-title"><i class="fas fa-paperclip" style="margin-right:8px"></i>Ressources</h3></div>
      <div class="card-body" style="display:flex;flex-wrap:wrap;gap:10px">
        <?php foreach ($resFiles as $res): ?>
        <?php $icons=['pdf'=>'file-pdf text-red-500','word'=>'file-word text-blue-500','excel'=>'file-excel text-green-500','powerpoint'=>'file-powerpoint text-orange-500','video'=>'play-circle text-red-400','image'=>'image text-purple-400','link'=>'link text-sky-400','other'=>'file text-gray-400']; ?>
        <?php if ($isPreview): ?>
        <div style="display:flex;align-items:center;gap:4px">
          <a href="<?= e(uploadUrl($res['file_path'])) ?>" target="_blank" class="btn btn-secondary" style="gap:8px">
            <i class="fas fa-<?= $icons[$res['type']] ?? 'file' ?>"></i>
            <?= e($res['title']) ?>
            <?php if ($res['file_size']): ?><span style="color:var(--text-faint);font-size:11px"><?= formatFileSize($res['file_size']) ?></span><?php endif; ?>
          </a>
          <form method="POST" action="<?= url('teacher/courses/delete.php') ?>" onsubmit="return confirm('Supprimer cette ressource ?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="resource">
            <input type="hidden" name="resource_id" value="<?= $res['id'] ?>">
            <input type="hidden" name="lesson_id" value="<?= $lesson['id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3)" title="Supprimer cette ressource">
              <i class="fas fa-times"></i>
            </button>
          </form>
        </div>
        <?php else: ?>
        <a href="<?= e(uploadUrl($res['file_path'])) ?>" target="_blank" class="btn btn-secondary" style="gap:8px">
          <i class="fas fa-<?= $icons[$res['type']] ?? 'file' ?>"></i>
          <?= e($res['title']) ?>
          <?php if ($res['file_size']): ?><span style="color:var(--text-faint);font-size:11px"><?= formatFileSize($res['file_size']) ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Quiz section -->
    <?php if ($lessonQuiz): ?>
    <div class="card" style="margin-bottom:16px;border-color:<?= $quizPassed ? 'rgba(16,185,129,.4)' : 'rgba(99,102,241,.3)' ?>">
      <div class="card-body">
        <div style="display:flex;align-items:center;gap:16px">
          <div style="width:52px;height:52px;border-radius:var(--radius-lg);background:<?= $quizPassed ? 'rgba(16,185,129,.15)' : 'rgba(99,102,241,.15)' ?>;display:flex;align-items:center;justify-content:center;font-size:24px">
            <?= $quizPassed ? '✅' : '📝' ?>
          </div>
          <div style="flex:1">
            <div style="font-size:16px;font-weight:700;color:white;margin-bottom:3px"><?= e($lessonQuiz['title']) ?></div>
            <div style="font-size:13px;color:var(--text-muted)">
              Score requis : <?= $lessonQuiz['passing_score'] ?>% · <?= $lessonQuiz['max_attempts'] ?> tentatives · +<?= $lessonQuiz['xp_reward'] ?> XP
            </div>
          </div>
          <a href="<?= url('student/quiz/take.php?id='.$lessonQuiz['id'].'&formation_id='.$formationId) ?>" class="btn <?= $quizPassed ? 'btn-success' : 'btn-primary' ?>">
            <i class="fas fa-<?= $quizPassed ? 'redo' : 'play' ?>"></i>
            <?= $quizPassed ? 'Refaire' : 'Démarrer le quiz' ?>
          </a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Navigation -->
    <div style="display:flex;gap:12px;justify-content:space-between;margin-top:8px">
      <?php if ($prevLesson): ?>
      <a href="<?= url('student/course/view.php?id='.$prevLesson['id'].'&formation_id='.$formationId).$previewParam ?>"
         class="btn btn-secondary" style="flex:1;justify-content:center">
        <i class="fas fa-chevron-left"></i> <?= e(mb_strimwidth($prevLesson['title'],0,28,'…')) ?>
      </a>
      <?php else: ?>
      <div style="flex:1"></div>
      <?php endif; ?>
      <?php if ($nextLesson): ?>
      <button id="btn-next" onclick="validateAndNext()" class="btn btn-primary" style="flex:1;justify-content:center">
        <?= e(mb_strimwidth($nextLesson['title'],0,28,'…')) ?> <i class="fas fa-chevron-right"></i>
      </button>
      <?php else: ?>
      <button id="btn-next" onclick="validateAndFinish()" class="btn btn-success" style="flex:1;justify-content:center">
        <i class="fas fa-flag-checkered"></i> Terminer la formation
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Lesson sidebar -->
  <div style="border-left:1px solid var(--border);overflow-y:auto;background:var(--bg-surface)">
    <div style="padding:16px 16px 8px;border-bottom:1px solid var(--border)">
      <div style="font-size:13px;font-weight:700;color:white;margin-bottom:2px"><?= e($lesson['formation_title']) ?></div>
      <?php
        $totalLessons = 0; $doneLessons = 0;
        foreach ($lessonsByModule as $mod) { foreach ($mod['lessons'] as $ls) { $totalLessons++; if ($ls['progress_status'] === 'completed') $doneLessons++; } }
        $formationPct = $totalLessons > 0 ? round(($doneLessons/$totalLessons)*100) : 0;
      ?>
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px"><?= $doneLessons ?>/<?= $totalLessons ?> capsules</div>
      <div class="progress-bar" style="height:4px"><div class="progress-fill" style="width:<?= $formationPct ?>%"></div></div>
    </div>

    <?php foreach ($lessonsByModule as $modId => $mod): ?>
    <div style="padding:12px 16px 6px">
      <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px"><?= e($mod['title']) ?></div>
      <ul class="lesson-list">
        <?php foreach ($mod['lessons'] as $ls): ?>
        <li class="lesson-item <?= $ls['id']==$lessonId?'active':'' ?> <?= $ls['progress_status']==='completed'?'completed':'' ?>" data-lesson="<?= $ls['id'] ?>">
          <div class="lesson-status <?= $ls['progress_status']==='completed'?'done':'' ?>">
            <?php if ($ls['progress_status']==='completed'): ?>
            <i class="fas fa-check" style="font-size:10px"></i>
            <?php else: ?>
            <i class="<?= getContentTypeIcon($ls['content_type']) ?>" style="font-size:9px"></i>
            <?php endif; ?>
          </div>
          <div class="lesson-info" style="overflow:hidden">
            <a href="<?= url('student/course/view.php?id='.$ls['id'].'&formation_id='.$formationId).$previewParam ?>" style="text-decoration:none;display:block">
              <div class="lesson-title <?= $ls['id']==$lessonId?'':'truncate' ?>"><?= e($ls['title']) ?></div>
              <div class="lesson-duration"><?= $ls['duration_minutes'] ? formatDuration($ls['duration_minutes']) : '&nbsp;' ?></div>
            </a>
          </div>
          <?php if ($ls['xp_reward']): ?><div class="lesson-xp">+<?= $ls['xp_reward'] ?>XP</div><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ═══════════════ Overlay Plein Écran ═══════════════ -->
<div id="fs-overlay" style="display:none;position:fixed;inset:0;z-index:9990;background:#000;flex-direction:column">
  <!-- Barre de contrôle -->
  <div style="display:flex;align-items:center;gap:12px;padding:10px 16px;background:rgba(15,15,25,.9);backdrop-filter:blur(8px);flex-shrink:0">
    <button onclick="closeFullscreen()" style="background:rgba(255,255,255,.1);border:none;color:white;border-radius:8px;padding:7px 13px;cursor:pointer;display:flex;align-items:center;gap:6px;font-size:13px">
      <i class="fas fa-compress"></i> Quitter plein écran
    </button>
    <span id="fs-title" style="flex:1;font-size:14px;font-weight:600;color:white;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
    <div style="display:flex;gap:8px">
      <?php if ($prevLesson): ?>
      <a href="<?= url('student/course/view.php?id='.$prevLesson['id'].'&formation_id='.$formationId).$previewParam ?>"
         class="btn btn-secondary btn-sm" style="text-decoration:none">
        <i class="fas fa-chevron-left"></i> <?= e(mb_strimwidth($prevLesson['title'],0,22,'…')) ?>
      </a>
      <?php endif; ?>
      <?php if ($nextLesson): ?>
      <button onclick="fsValidateAndNext()" class="btn btn-primary btn-sm" id="fs-btn-next">
        <?= e(mb_strimwidth($nextLesson['title'],0,22,'…')) ?> <i class="fas fa-chevron-right"></i>
      </button>
      <?php else: ?>
      <button onclick="fsValidateAndFinish()" class="btn btn-success btn-sm" id="fs-btn-next">
        <i class="fas fa-flag-checkered"></i> Terminer
      </button>
      <?php endif; ?>
    </div>
  </div>
  <!-- Iframe plein écran -->
  <div style="flex:1;overflow:hidden">
    <iframe id="fs-iframe" src="" style="width:100%;height:100%;border:none"></iframe>
  </div>
</div>

<!-- ═══════════════ Pop-up XP / Validation ═══════════════ -->
<div id="xp-popup" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.75);align-items:center;justify-content:center;backdrop-filter:blur(6px)">
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:20px;padding:40px 48px;text-align:center;max-width:420px;width:90%;animation:xpPop .35s cubic-bezier(.34,1.56,.64,1) both">
    <div id="xp-icon" style="font-size:56px;margin-bottom:16px">🎉</div>
    <h2 id="xp-title" style="font-size:22px;font-weight:800;color:white;margin-bottom:8px">Capsule validée !</h2>
    <p id="xp-subtitle" style="font-size:14px;color:var(--text-muted);margin-bottom:24px"></p>
    <div id="xp-badge" style="display:inline-flex;align-items:center;gap:10px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);border-radius:50px;padding:10px 24px;margin-bottom:28px">
      <i class="fas fa-bolt" style="color:#f59e0b;font-size:20px"></i>
      <span id="xp-amount" style="font-size:28px;font-weight:900;color:#f59e0b"></span>
    </div>
    <div id="levelup-banner" style="display:none;background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.4);border-radius:12px;padding:12px 20px;margin-bottom:20px">
      <i class="fas fa-arrow-up" style="color:var(--primary-light)"></i>
      <span id="levelup-text" style="font-size:14px;font-weight:700;color:var(--primary-light);margin-left:6px"></span>
    </div>
    <button id="xp-continue-btn" onclick="xpContinue()" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px">
      <i class="fas fa-arrow-right"></i> Continuer
    </button>
  </div>
</div>

<style>
@keyframes xpPop {
  from { opacity:0; transform:scale(.7) translateY(20px); }
  to   { opacity:1; transform:scale(1) translateY(0); }
}
</style>

<script src="<?= asset('js/main.js') ?>"></script>
<script>
const LESSON_ID    = <?= $lessonId ?>;
const FORMATION_ID = <?= $formationId ?>;
const IS_PREVIEW   = <?= $isPreview ? 'true' : 'false' ?>;
const NEXT_URL     = <?= $nextLesson ? json_encode(url('student/course/view.php?id='.$nextLesson['id'].'&formation_id='.$formationId).$previewParam) : 'null' ?>;
const PARCOURS_URL = <?= $isPreview ? json_encode(url('teacher/courses/index.php')) : json_encode(url('student/formations/view.php?id='.$formationId)) ?>;
const LESSON_TITLE = <?= json_encode($lesson['title']) ?>;

// ── Vidéo ────────────────────────────────────────────────────
const video = document.getElementById('lesson-video');
if (video) initVideoTracking(video, LESSON_ID, FORMATION_ID);

// ── Plein écran ──────────────────────────────────────────────
function openFullscreen() {
  const src = document.getElementById('doc-iframe')?.src;
  if (!src) return;
  const overlay = document.getElementById('fs-overlay');
  document.getElementById('fs-iframe').src = src;
  document.getElementById('fs-title').textContent = LESSON_TITLE;
  overlay.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeFullscreen() {
  document.getElementById('fs-overlay').style.display = 'none';
  document.getElementById('fs-iframe').src = '';
  document.body.style.overflow = '';
}

// ── Pop-up XP ────────────────────────────────────────────────
let _xpNextUrl = null;

function showXpPopup(data, nextUrl, isLast) {
  _xpNextUrl = nextUrl;
  const popup = document.getElementById('xp-popup');
  document.getElementById('xp-icon').textContent  = isLast ? '🏆' : '🎉';
  document.getElementById('xp-title').textContent = isLast ? 'Formation terminée !' : 'Capsule validée !';
  document.getElementById('xp-subtitle').textContent = isLast
    ? 'Bravo ! Vous avez complété toutes les capsules de cette formation.'
    : 'Excellente progression, continuez ainsi !';
  document.getElementById('xp-amount').textContent = '+' + data.xp + ' XP';

  const lvBanner = document.getElementById('levelup-banner');
  if (data.level_up) {
    lvBanner.style.display = 'block';
    document.getElementById('levelup-text').textContent = `Niveau ${data.new_level} atteint !`;
  } else {
    lvBanner.style.display = 'none';
  }

  popup.style.display = 'flex';
}

function xpContinue() {
  document.getElementById('xp-popup').style.display = 'none';
  if (_xpNextUrl) window.location.href = _xpNextUrl;
}

// Fermer popup en cliquant en dehors
document.getElementById('xp-popup').addEventListener('click', function(e) {
  if (e.target === this) xpContinue();
});

// ── Validation commune ────────────────────────────────────────
async function doValidate(btn) {
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
  const data = await completeLessonProgress(LESSON_ID, FORMATION_ID);
  // Mettre à jour la sidebar
  const item = document.querySelector(`.lesson-item[data-lesson="${LESSON_ID}"]`);
  if (item) {
    const st = item.querySelector('.lesson-status');
    if (st) { st.classList.add('done'); st.innerHTML = '<i class="fas fa-check" style="font-size:10px"></i>'; }
    item.classList.add('completed');
  }
  return data;
}

// ── Bouton Suivante (page normale) ───────────────────────────
async function validateAndNext() {
  if (IS_PREVIEW) { window.location.href = NEXT_URL; return; }
  const data = await doValidate(document.getElementById('btn-next'));
  if (!data.already_completed && data.xp) {
    showXpPopup(data, NEXT_URL, false);
  } else {
    window.location.href = NEXT_URL;
  }
}

// ── Bouton Terminer (page normale) ───────────────────────────
async function validateAndFinish() {
  if (IS_PREVIEW) { window.location.href = PARCOURS_URL; return; }
  const data = await doValidate(document.getElementById('btn-next'));
  if (!data.already_completed && data.xp) {
    showXpPopup(data, PARCOURS_URL, true);
  } else {
    window.location.href = PARCOURS_URL;
  }
}

// ── Bouton Suivante (plein écran) ────────────────────────────
async function fsValidateAndNext() {
  closeFullscreen();
  if (IS_PREVIEW) { window.location.href = NEXT_URL; return; }
  const data = await doValidate(document.getElementById('fs-btn-next'));
  if (!data.already_completed && data.xp) {
    showXpPopup(data, NEXT_URL, false);
  } else {
    window.location.href = NEXT_URL;
  }
}

// ── Bouton Terminer (plein écran) ────────────────────────────
async function fsValidateAndFinish() {
  closeFullscreen();
  if (IS_PREVIEW) { window.location.href = PARCOURS_URL; return; }
  const data = await doValidate(document.getElementById('fs-btn-next'));
  if (!data.already_completed && data.xp) {
    showXpPopup(data, PARCOURS_URL, true);
  } else {
    window.location.href = PARCOURS_URL;
  }
}
</script>
<?php renderFooter(['']); ?>
