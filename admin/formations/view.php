<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error', 'Formation introuvable.'); redirect(url('admin/formations/index.php')); }

$stmt = $pdo->prepare("SELECT f.*, r.rncp_code, r.title as rncp_title, r.level as rncp_level, u.first_name, u.last_name FROM formations f LEFT JOIN rncp_titles r ON f.rncp_title_id = r.id LEFT JOIN users u ON f.created_by = u.id WHERE f.id = ?");
$stmt->execute([$id]);
$formation = $stmt->fetch();
if (!$formation) { setFlash('error', 'Formation introuvable.'); redirect(url('admin/formations/index.php')); }

// Actions AJAX/POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // Ajouter un module
    if ($action === 'add_module') {
        $title   = trim($_POST['module_title'] ?? '');
        $desc    = trim($_POST['module_desc'] ?? '');
        $atId    = (int)($_POST['activity_type_id'] ?? 0) ?: null;
        $compId  = (int)($_POST['competency_id'] ?? 0) ?: null;
        $hours   = (float)($_POST['module_hours'] ?? 0) ?: null;
        if ($title) {
            $max = $pdo->prepare('SELECT COALESCE(MAX(order_num),0)+1 FROM modules WHERE formation_id = ?');
            $max->execute([$id]);
            $order = (int)$max->fetchColumn();
            $pdo->prepare('INSERT INTO modules (formation_id, activity_type_id, competency_id, title, description, duration_hours, order_num) VALUES (?,?,?,?,?,?,?)')->execute([$id, $atId, $compId, $title, $desc, $hours, $order]);
            setFlash('success', 'Module ajouté.');
        }
        redirect(url("admin/formations/view.php?id=$id"));
    }

    // Ajouter une capsule
    if ($action === 'add_lesson') {
        $moduleId    = (int)($_POST['module_id'] ?? 0);
        $title       = trim($_POST['lesson_title'] ?? '');
        $type        = $_POST['content_type'] ?? 'text';
        $url         = trim($_POST['content_url'] ?? '');
        $duration    = (int)($_POST['duration_minutes'] ?? 0) ?: null;
        $xpReward    = (int)($_POST['xp_reward'] ?? 10);
        $mandatory   = isset($_POST['is_mandatory']) ? 1 : 0;

        $filePath = null;
        if (!empty($_FILES['lesson_file']['name'])) {
            $upload = uploadFile($_FILES['lesson_file'], 'lessons', array_merge(ALLOWED_DOC_TYPES, ALLOWED_IMAGE_TYPES));
            if ($upload['success']) $filePath = $upload['path'];
            else { setFlash('error', $upload['error']); redirect(url("admin/formations/view.php?id=$id")); }
        }

        if ($title && $moduleId) {
            $max = $pdo->prepare('SELECT COALESCE(MAX(order_num),0)+1 FROM lessons WHERE module_id = ?');
            $max->execute([$moduleId]);
            $order = (int)$max->fetchColumn();
            $pdo->prepare('INSERT INTO lessons (module_id, title, content_type, content_url, file_path, duration_minutes, order_num, is_mandatory, xp_reward, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$moduleId, $title, $type, $url ?: null, $filePath, $duration, $order, $mandatory, $xpReward, $_SESSION['user_id']]);
            $newLessonId = (int)$pdo->lastInsertId();
            // Lier le quiz sélectionné
            if ($type === 'quiz') {
                $quizId = (int)($_POST['quiz_id'] ?? 0);
                if ($quizId) {
                    $pdo->prepare('UPDATE quizzes SET lesson_id = ? WHERE id = ?')->execute([$newLessonId, $quizId]);
                }
            }
            setFlash('success', 'Capsule ajoutée.');
        }
        redirect(url("admin/formations/view.php?id=$id"));
    }

    // Supprimer un module
    if ($action === 'delete_module') {
        $mid = (int)($_POST['module_id'] ?? 0);
        $pdo->prepare('DELETE FROM modules WHERE id = ? AND formation_id = ?')->execute([$mid, $id]);
        setFlash('success', 'Module supprimé.');
        redirect(url("admin/formations/view.php?id=$id"));
    }

    // Modifier une capsule
    if ($action === 'edit_lesson') {
        $lid      = (int)($_POST['lesson_id'] ?? 0);
        $title    = trim($_POST['lesson_title'] ?? '');
        $type     = $_POST['content_type'] ?? 'text';
        $url      = trim($_POST['content_url'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 0) ?: null;
        $xp       = (int)($_POST['xp_reward'] ?? 10);
        $mandatory = isset($_POST['is_mandatory']) ? 1 : 0;

        if ($lid && $title) {
            // Vérifier que la capsule appartient bien à cette formation
            $check = $pdo->prepare('SELECT l.id, l.file_path FROM lessons l JOIN modules m ON l.module_id = m.id WHERE l.id = ? AND m.formation_id = ?');
            $check->execute([$lid, $id]);
            $existing = $check->fetch();
            if ($existing) {
                $filePath = $existing['file_path'];
                if (!empty($_FILES['lesson_file']['name'])) {
                    $upload = uploadFile($_FILES['lesson_file'], 'lessons', array_merge(ALLOWED_DOC_TYPES, ALLOWED_IMAGE_TYPES));
                    if ($upload['success']) $filePath = $upload['path'];
                    else { setFlash('error', $upload['error']); redirect(url("admin/formations/view.php?id=$id")); }
                }
                $pdo->prepare('UPDATE lessons SET title=?, content_type=?, content_url=?, file_path=?, duration_minutes=?, is_mandatory=?, xp_reward=? WHERE id=?')
                    ->execute([$title, $type, $url ?: null, $filePath, $duration, $mandatory, $xp, $lid]);
                // Gérer le lien quiz : délier l'ancien, lier le nouveau
                $pdo->prepare('UPDATE quizzes SET lesson_id = NULL WHERE lesson_id = ?')->execute([$lid]);
                if ($type === 'quiz') {
                    $quizId = (int)($_POST['quiz_id'] ?? 0);
                    if ($quizId) {
                        $pdo->prepare('UPDATE quizzes SET lesson_id = ? WHERE id = ?')->execute([$lid, $quizId]);
                    }
                }
                setFlash('success', 'Capsule mise à jour.');
            }
        }
        redirect(url("admin/formations/view.php?id=$id"));
    }

    // Monter / descendre un module
    if (in_array($action, ['move_module_up', 'move_module_down'])) {
        $mid = (int)($_POST['module_id'] ?? 0);
        if ($mid) {
            // Récupérer tous les modules de la formation triés
            $siblings = $pdo->prepare('SELECT id, order_num FROM modules WHERE formation_id = ? ORDER BY order_num, id');
            $siblings->execute([$id]);
            $list = $siblings->fetchAll();

            $pos = null;
            foreach ($list as $i => $row) {
                if ($row['id'] == $mid) { $pos = $i; break; }
            }

            $swapPos = $action === 'move_module_up' ? $pos - 1 : $pos + 1;

            if ($pos !== null && isset($list[$swapPos])) {
                $targetId     = $list[$swapPos]['id'];
                $currentOrder = $list[$pos]['order_num'];
                $targetOrder  = $list[$swapPos]['order_num'];

                if ($currentOrder === $targetOrder) {
                    $currentOrder = $pos + 1;
                    $targetOrder  = $swapPos + 1;
                }

                $pdo->prepare('UPDATE modules SET order_num=? WHERE id=?')->execute([$targetOrder, $mid]);
                $pdo->prepare('UPDATE modules SET order_num=? WHERE id=?')->execute([$currentOrder, $targetId]);

                // Renuméroter proprement
                $siblings2 = $pdo->prepare('SELECT id FROM modules WHERE formation_id = ? ORDER BY order_num, id');
                $siblings2->execute([$id]);
                foreach ($siblings2->fetchAll() as $k => $row) {
                    $pdo->prepare('UPDATE modules SET order_num=? WHERE id=?')->execute([$k + 1, $row['id']]);
                }
            }
        }
        redirect(url("admin/formations/view.php?id=$id#module-{$_POST['module_id']}"));
    }

    // Supprimer une capsule
    if ($action === 'delete_lesson') {
        $lid = (int)($_POST['lesson_id'] ?? 0);
        $pdo->prepare('DELETE FROM lessons WHERE id = ? AND module_id IN (SELECT id FROM modules WHERE formation_id = ?)')->execute([$lid, $id]);
        setFlash('success', 'Capsule supprimée.');
        redirect(url("admin/formations/view.php?id=$id"));
    }

    // Monter / descendre une capsule
    if (in_array($action, ['move_lesson_up', 'move_lesson_down'])) {
        $lid = (int)($_POST['lesson_id'] ?? 0);
        $mid = (int)($_POST['module_id'] ?? 0);
        if ($lid && $mid) {
            // Vérifier que la capsule appartient bien à cette formation
            $check = $pdo->prepare('SELECT l.id FROM lessons l JOIN modules m ON l.module_id = m.id WHERE l.id = ? AND m.formation_id = ?');
            $check->execute([$lid, $id]);
            if ($check->fetch()) {
                $siblings = $pdo->prepare('SELECT id, order_num FROM lessons WHERE module_id = ? ORDER BY order_num, id');
                $siblings->execute([$mid]);
                $list = $siblings->fetchAll();

                $pos = null;
                foreach ($list as $i => $row) {
                    if ($row['id'] == $lid) { $pos = $i; break; }
                }

                $swapPos = $action === 'move_lesson_up' ? $pos - 1 : $pos + 1;

                if ($pos !== null && isset($list[$swapPos])) {
                    $targetId     = $list[$swapPos]['id'];
                    $currentOrder = $list[$pos]['order_num'];
                    $targetOrder  = $list[$swapPos]['order_num'];

                    if ($currentOrder === $targetOrder) {
                        $currentOrder = $pos + 1;
                        $targetOrder  = $swapPos + 1;
                    }

                    $pdo->prepare('UPDATE lessons SET order_num=? WHERE id=?')->execute([$targetOrder, $lid]);
                    $pdo->prepare('UPDATE lessons SET order_num=? WHERE id=?')->execute([$currentOrder, $targetId]);

                    // Renuméroter proprement
                    $siblings2 = $pdo->prepare('SELECT id FROM lessons WHERE module_id = ? ORDER BY order_num, id');
                    $siblings2->execute([$mid]);
                    foreach ($siblings2->fetchAll() as $k => $row) {
                        $pdo->prepare('UPDATE lessons SET order_num=? WHERE id=?')->execute([$k + 1, $row['id']]);
                    }
                }
            }
        }
        redirect(url("admin/formations/view.php?id=$id#card-module-{$_POST['module_id']}"));
    }

    // Inscrire un étudiant
    if ($action === 'enroll_student') {
        $uid = (int)($_POST['student_id'] ?? 0);
        if ($uid) {
            $check = $pdo->prepare('SELECT id FROM enrollments WHERE user_id = ? AND formation_id = ?');
            $check->execute([$uid, $id]);
            if ($check->fetch()) {
                setFlash('warning', 'Cet étudiant est déjà inscrit.');
            } else {
                $pdo->prepare('INSERT INTO enrollments (user_id, formation_id, status, validated_by, validated_at) VALUES (?,?,?,?,NOW())')->execute([$uid, $id, 'active', $_SESSION['user_id']]);
                createNotification($uid, 'Inscription validée', 'Vous êtes inscrit à la formation : ' . $formation['title'], 'success');
                setFlash('success', 'Étudiant inscrit.');
            }
        }
        redirect(url("admin/formations/view.php?id=$id"));
    }
}

// Données de la formation
$modules = $pdo->prepare("SELECT m.*, at.code as at_code, at.title as at_title, c.code as comp_code, c.title as comp_title, (SELECT COUNT(*) FROM lessons l WHERE l.module_id = m.id) as lesson_count FROM modules m LEFT JOIN activity_types at ON m.activity_type_id = at.id LEFT JOIN competencies c ON m.competency_id = c.id WHERE m.formation_id = ? ORDER BY m.order_num");
$modules->execute([$id]);
$modules = $modules->fetchAll();

// Leçons par module (avec quiz lié)
$allLessons = $pdo->prepare("
    SELECT l.*, m.formation_id, q.id as quiz_id, q.title as quiz_title
    FROM lessons l
    JOIN modules m ON l.module_id = m.id
    LEFT JOIN quizzes q ON q.lesson_id = l.id
    WHERE m.formation_id = ?
    ORDER BY l.module_id, l.order_num
");
$allLessons->execute([$id]);
$lessonsByModule = [];
foreach ($allLessons->fetchAll() as $l) {
    $lessonsByModule[$l['module_id']][] = $l;
}

// Inscriptions
$enrollments = $pdo->prepare("SELECT e.*, u.first_name, u.last_name, u.email, u.avatar FROM enrollments e JOIN users u ON e.user_id = u.id WHERE e.formation_id = ? ORDER BY e.enrolled_at DESC");
$enrollments->execute([$id]);
$enrollments = $enrollments->fetchAll();

// Activity types du titre RNCP
$actTypes = $pdo->prepare("SELECT id, code, title FROM activity_types WHERE rncp_title_id = ? ORDER BY order_num");
$actTypes->execute([$formation['rncp_title_id']]);
$actTypes = $actTypes->fetchAll();

// Compétences groupées par activité type
$compsByAt = [];
if (!empty($actTypes)) {
    $atIds = array_column($actTypes, 'id');
    $placeholders = implode(',', array_fill(0, count($atIds), '?'));
    $stmt = $pdo->prepare("SELECT id, activity_type_id, code, title FROM competencies WHERE activity_type_id IN ($placeholders) ORDER BY order_num");
    $stmt->execute($atIds);
    foreach ($stmt->fetchAll() as $comp) {
        $compsByAt[$comp['activity_type_id']][] = $comp;
    }
}

// Quiz disponibles pour lier à une capsule
$allQuizzes = $pdo->query("
    SELECT q.id, q.title, q.quiz_type, q.lesson_id, u.first_name, u.last_name
    FROM quizzes q
    JOIN users u ON q.created_by = u.id
    ORDER BY u.last_name, u.first_name, q.title
")->fetchAll();

// Étudiants disponibles pour inscription
$availableStudents = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.email FROM users u WHERE u.role = 'student' AND u.status = 'active' AND u.id NOT IN (SELECT user_id FROM enrollments WHERE formation_id = ?) ORDER BY u.last_name");
$availableStudents->execute([$id]);
$availableStudents = $availableStudents->fetchAll();

// Stats
$totalLessons = array_sum(array_column($modules, 'lesson_count'));
$totalStudents = count($enrollments);
$activeEnrollments = count(array_filter($enrollments, fn($e) => $e['status'] === 'active'));
$avgProgress = $totalStudents > 0 ? round(array_sum(array_column($enrollments, 'progress_percent')) / $totalStudents) : 0;

renderHead('Formation : ' . $formation['title']);
renderSidebar(isAdmin() ? 'admin' : (isPedagogy() ? 'pedagogy' : 'teacher'));
renderTopbar($formation['title'], [['Formations', url('admin/formations/index.php')], [$formation['title'], '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- En-tête -->
  <div class="page-header">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;gap:8px;margin-bottom:8px">
          <?php if ($formation['rncp_code']): ?><span class="badge badge-primary"><?= e($formation['rncp_code']) ?></span><?php endif; ?>
          <?= getStatusBadge($formation['status']) ?>
          <?php if ($formation['qualiopi_certified']): ?><span class="badge badge-success"><i class="fas fa-shield-alt"></i> Qualiopi</span><?php endif; ?>
        </div>
        <h1 style="font-size:22px;margin-bottom:4px"><?= e($formation['title']) ?></h1>
        <p style="color:var(--text-muted);font-size:13px"><?= e($formation['rncp_title'] ?? '') ?></p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= url('admin/formations/create.php?id=' . $id) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> Modifier</a>
        <a href="<?= url('admin/formations/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
    <div class="card"><div class="card-body" style="text-align:center;padding:16px">
      <div style="font-size:28px;font-weight:800;color:var(--primary-light)"><?= count($modules) ?></div>
      <div style="font-size:12px;color:var(--text-muted)">Modules</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;padding:16px">
      <div style="font-size:28px;font-weight:800;color:var(--primary-light)"><?= $totalLessons ?></div>
      <div style="font-size:12px;color:var(--text-muted)">Capsules</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;padding:16px">
      <div style="font-size:28px;font-weight:800;color:var(--primary-light)"><?= $totalStudents ?></div>
      <div style="font-size:12px;color:var(--text-muted)">Inscrits</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;padding:16px">
      <div style="font-size:28px;font-weight:800;color:var(--success)"><?= $avgProgress ?>%</div>
      <div style="font-size:12px;color:var(--text-muted)">Progression moy.</div>
    </div></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start">
    <!-- Modules & Capsules -->
    <div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <h2 style="font-size:16px;font-weight:700">Structure pédagogique</h2>
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('modal-module').style.display='flex'">
          <i class="fas fa-plus"></i> Ajouter un module
        </button>
      </div>

      <?php if (empty($modules)): ?>
      <div class="empty-state">
        <div class="icon">📚</div>
        <h3>Aucun module</h3>
        <p>Commencez par ajouter un module à cette formation.</p>
        <button class="btn btn-primary" onclick="document.getElementById('modal-module').style.display='flex'">Ajouter un module</button>
      </div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:16px">
        <?php foreach ($modules as $i => $mod):
          $isFirst = $i === 0;
          $isLast  = $i === count($modules) - 1;
        ?>
        <div class="card" id="card-module-<?= $mod['id'] ?>">
          <div class="card-header">
            <div style="display:flex;align-items:center;gap:12px;width:100%">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white;flex-shrink:0;cursor:pointer" onclick="toggleModule(<?= $mod['id'] ?>)"><?= $i + 1 ?></div>
              <div style="flex:1;cursor:pointer;min-width:0" onclick="toggleModule(<?= $mod['id'] ?>)">
                <div style="font-weight:700;font-size:14px"><?= e($mod['title']) ?></div>
                <?php if ($mod['at_title']): ?><div style="font-size:11px;color:var(--text-muted)"><?= e($mod['at_code']) ?> — <?= e($mod['at_title']) ?><?php if ($mod['comp_title']): ?> &rsaquo; <?= e($mod['comp_code']) ?> <?= e($mod['comp_title']) ?><?php endif; ?></div><?php endif; ?>
              </div>
              <span class="badge badge-secondary"><?= $mod['lesson_count'] ?> capsule(s)</span>
              <?php if ($mod['duration_hours']): ?><span style="font-size:12px;color:var(--text-muted)"><?= $mod['duration_hours'] ?>h</span><?php endif; ?>
              <!-- Boutons ordonner -->
              <div style="display:flex;gap:4px;flex-shrink:0;align-items:center">
                <?php if (!$isFirst): ?>
                <form method="POST" style="margin:0">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="move_module_up">
                  <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
                  <button type="submit" class="btn btn-secondary btn-sm" style="padding:4px 8px" title="Monter ce module">
                    <i class="fas fa-arrow-up" style="font-size:11px"></i>
                  </button>
                </form>
                <?php endif; ?>
                <?php if (!$isLast): ?>
                <form method="POST" style="margin:0">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="move_module_down">
                  <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
                  <button type="submit" class="btn btn-secondary btn-sm" style="padding:4px 8px" title="Descendre ce module">
                    <i class="fas fa-arrow-down" style="font-size:11px"></i>
                  </button>
                </form>
                <?php endif; ?>
              </div>
              <i class="fas fa-chevron-down" id="chevron-<?= $mod['id'] ?>" style="transition:.2s;color:var(--text-muted);cursor:pointer" onclick="toggleModule(<?= $mod['id'] ?>)"></i>
            </div>
          </div>
          <div id="module-<?= $mod['id'] ?>" style="display:block">
            <div class="card-body" style="padding-top:0">
              <!-- Liste capsules -->
              <?php $lessons = $lessonsByModule[$mod['id']] ?? []; ?>
              <?php if (!empty($lessons)):
                $totalLessonsInMod = count($lessons);
              ?>
              <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px">
                <?php foreach ($lessons as $j => $lesson):
                  $lFirst = $j === 0;
                  $lLast  = $j === $totalLessonsInMod - 1;
                ?>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg-elevated);border-radius:var(--radius)">
                  <span style="font-size:11px;color:var(--text-faint);width:20px;flex-shrink:0"><?= $j+1 ?>.</span>
                  <i class="<?= getContentTypeIcon($lesson['content_type']) ?>" style="width:16px;flex-shrink:0"></i>
                  <span style="flex:1;font-size:13px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($lesson['title']) ?></span>
                  <?php if ($lesson['duration_minutes']): ?><span style="font-size:11px;color:var(--text-muted);flex-shrink:0"><?= formatDuration($lesson['duration_minutes']) ?></span><?php endif; ?>
                  <span style="font-size:11px;color:var(--warning);flex-shrink:0">+<?= $lesson['xp_reward'] ?> XP</span>
                  <?php if (!$lesson['is_mandatory']): ?><span class="badge badge-secondary" style="font-size:10px;flex-shrink:0">Opt.</span><?php endif; ?>
                  <!-- Boutons ordonner capsule -->
                  <?php if (!$lFirst): ?>
                  <form method="POST" style="margin:0;flex-shrink:0">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="move_lesson_up">
                    <input type="hidden" name="lesson_id" value="<?= $lesson['id'] ?>">
                    <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
                    <button type="submit" class="btn btn-secondary btn-sm" style="padding:3px 7px" title="Monter"><i class="fas fa-arrow-up" style="font-size:10px"></i></button>
                  </form>
                  <?php else: ?><div style="width:30px;flex-shrink:0"></div><?php endif; ?>
                  <?php if (!$lLast): ?>
                  <form method="POST" style="margin:0;flex-shrink:0">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="move_lesson_down">
                    <input type="hidden" name="lesson_id" value="<?= $lesson['id'] ?>">
                    <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
                    <button type="submit" class="btn btn-secondary btn-sm" style="padding:3px 7px" title="Descendre"><i class="fas fa-arrow-down" style="font-size:10px"></i></button>
                  </form>
                  <?php else: ?><div style="width:30px;flex-shrink:0"></div><?php endif; ?>
                  <!-- Éditer / Supprimer -->
                  <button class="btn btn-ghost btn-sm btn-edit-lesson" style="padding:4px 6px;flex-shrink:0"
                    data-id="<?= $lesson['id'] ?>"
                    data-title="<?= e($lesson['title']) ?>"
                    data-type="<?= e($lesson['content_type']) ?>"
                    data-url="<?= e($lesson['content_url'] ?? '') ?>"
                    data-duration="<?= (int)$lesson['duration_minutes'] ?>"
                    data-xp="<?= (int)$lesson['xp_reward'] ?>"
                    data-mandatory="<?= (int)$lesson['is_mandatory'] ?>"
                    data-quiz-id="<?= (int)($lesson['quiz_id'] ?? 0) ?>">
                    <i class="fas fa-edit"></i>
                  </button>
                  <form method="POST" style="margin:0;flex-shrink:0" onsubmit="return confirm('Supprimer cette capsule ?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_lesson">
                    <input type="hidden" name="lesson_id" value="<?= $lesson['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 6px;color:var(--danger)"><i class="fas fa-trash"></i></button>
                  </form>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <div style="display:flex;gap:8px">
                <button class="btn btn-secondary btn-sm" data-mid="<?= $mod['id'] ?>" data-mtitle="<?= e($mod['title']) ?>" onclick="openAddLesson(this.dataset.mid, this.dataset.mtitle)">
                  <i class="fas fa-plus"></i> Ajouter une capsule
                </button>
                <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer ce module et toutes ses capsules ?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_module">
                  <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Colonne droite : inscriptions -->
    <div style="display:flex;flex-direction:column;gap:16px">
      <!-- Inscrire un étudiant -->
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-user-plus"></i> Inscrire un étudiant</h3></div>
        <div class="card-body">
          <?php if (empty($availableStudents)): ?>
          <p style="font-size:13px;color:var(--text-muted)">Tous les étudiants actifs sont déjà inscrits.</p>
          <?php else: ?>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="enroll_student">
            <select name="student_id" class="form-control" style="margin-bottom:10px">
              <option value="">— Choisir un étudiant —</option>
              <?php foreach ($availableStudents as $s): ?>
              <option value="<?= $s['id'] ?>"><?= e($s['last_name'] . ' ' . $s['first_name']) ?> — <?= e($s['email']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm w-full" style="justify-content:center"><i class="fas fa-plus"></i> Inscrire</button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- Liste inscrits -->
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-users"></i> Étudiants inscrits (<?= $totalStudents ?>)</h3></div>
        <div class="card-body" style="padding:0">
          <?php if (empty($enrollments)): ?>
          <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">Aucun étudiant inscrit</div>
          <?php else: ?>
          <div style="display:flex;flex-direction:column">
            <?php foreach ($enrollments as $enr): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)">
              <div class="avatar avatar-sm" style="background:<?= getAvatarColor($enr['first_name'].$enr['last_name']) ?>">
                <?= getAvatarInitials($enr['first_name'], $enr['last_name']) ?>
              </div>
              <div style="flex:1;overflow:hidden">
                <div style="font-size:13px;font-weight:600"><?= e($enr['first_name'] . ' ' . $enr['last_name']) ?></div>
                <div class="progress-bar" style="height:4px;margin-top:4px"><div class="progress-fill" style="width:<?= $enr['progress_percent'] ?>%"></div></div>
              </div>
              <div style="text-align:right;flex-shrink:0">
                <div style="font-size:12px;font-weight:700;color:var(--primary-light)"><?= $enr['progress_percent'] ?>%</div>
                <?= getStatusBadge($enr['status']) ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Description rapide -->
      <?php if ($formation['description']): ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title">Description</h3></div>
        <div class="card-body">
          <p style="font-size:13px;color:var(--text-muted);line-height:1.6"><?= nl2br(e($formation['description'])) ?></p>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal : Ajouter un module -->
<div id="modal-module" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:500px;max-width:95vw;max-height:90vh;overflow-y:auto">
    <div class="card-header">
      <h3 class="card-title">Ajouter un module</h3>
      <button onclick="document.getElementById('modal-module').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_module">
        <div class="form-group">
          <label class="form-label">Titre du module <span class="required">*</span></label>
          <input type="text" name="module_title" class="form-control" placeholder="Ex : Module 1 – Développement Frontend" required>
        </div>
        <?php if (!empty($actTypes)): ?>
        <div class="form-group">
          <label class="form-label">Activité type RNCP</label>
          <select name="activity_type_id" id="mod-at-select" class="form-control" onchange="updateCompetencies(this.value)">
            <option value="">— Non rattaché —</option>
            <?php foreach ($actTypes as $at): ?>
            <option value="<?= $at['id'] ?>"><?= e($at['code']) ?> — <?= e($at['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" id="mod-comp-group" style="display:none">
          <label class="form-label">Compétence visée</label>
          <select name="competency_id" id="mod-comp-select" class="form-control">
            <option value="">— Toutes les compétences du bloc —</option>
          </select>
        </div>
        <?php endif; ?>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Durée (heures)</label>
            <input type="number" name="module_hours" class="form-control" placeholder="35" min="0" step="0.5">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="module_desc" class="form-control" rows="3" placeholder="Objectifs du module..."></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-module').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal : Ajouter une capsule -->
<div id="modal-lesson" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:560px;max-width:95vw;max-height:90vh;overflow-y:auto">
    <div class="card-header">
      <h3 class="card-title">Ajouter une capsule — <span id="modal-lesson-module"></span></h3>
      <button onclick="document.getElementById('modal-lesson').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_lesson">
        <input type="hidden" name="module_id" id="lesson-module-id">
        <div class="form-group">
          <label class="form-label">Titre de la capsule <span class="required">*</span></label>
          <input type="text" name="lesson_title" class="form-control" placeholder="Ex : Introduction à HTML5" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Type de contenu</label>
            <select name="content_type" class="form-control" id="lesson-type-select" onchange="toggleLessonFields()">
              <option value="text">Texte / HTML</option>
              <option value="video">Vidéo</option>
              <option value="pdf">PDF</option>
              <option value="document">Document</option>
              <option value="presentation">Présentation</option>
              <option value="quiz">Quiz</option>
              <option value="exercise">Exercice</option>
              <option value="link">Lien externe</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Durée (min)</label>
            <input type="number" name="duration_minutes" class="form-control" placeholder="30" min="0">
          </div>
        </div>
        <div id="field-url" class="form-group">
          <label class="form-label">URL (vidéo ou lien)</label>
          <input type="url" name="content_url" class="form-control" placeholder="https://...">
        </div>
        <div id="field-file" class="form-group" style="display:none">
          <label class="form-label">Fichier</label>
          <input type="file" name="lesson_file" class="form-control">
        </div>
        <div id="field-quiz" class="form-group" style="display:none">
          <label class="form-label">Quiz à intégrer <span class="required">*</span></label>
          <select name="quiz_id" class="form-control" id="lesson-quiz-select">
            <option value="">— Sélectionner un quiz —</option>
            <?php foreach ($allQuizzes as $q): ?>
            <option value="<?= $q['id'] ?>" <?= $q['lesson_id'] ? 'style="color:var(--text-muted)"' : '' ?>>
              [<?= ucfirst(e($q['quiz_type'])) ?>] <?= e($q['title']) ?> — <?= e($q['first_name'].' '.$q['last_name']) ?><?= $q['lesson_id'] ? ' (déjà utilisé)' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">Les quiz "déjà utilisés" sont liés à une autre capsule ; les sélectionner les réassignera.</div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">XP attribués</label>
            <input type="number" name="xp_reward" class="form-control" value="10" min="0">
          </div>
          <div class="form-group" style="display:flex;align-items:center;padding-top:28px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
              <input type="checkbox" name="is_mandatory" value="1" checked>
              <span style="font-size:13px">Obligatoire</span>
            </label>
          </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-lesson').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal : Éditer une capsule -->
<div id="modal-edit-lesson" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:560px;max-width:95vw;max-height:90vh;overflow-y:auto">
    <div class="card-header">
      <h3 class="card-title">Modifier la capsule</h3>
      <button onclick="document.getElementById('modal-edit-lesson').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="edit_lesson">
        <input type="hidden" name="lesson_id" id="edit-lesson-id">
        <div class="form-group">
          <label class="form-label">Titre de la capsule <span class="required">*</span></label>
          <input type="text" name="lesson_title" id="edit-lesson-title" class="form-control" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Type de contenu</label>
            <select name="content_type" id="edit-lesson-type" class="form-control" onchange="toggleEditLessonFields()">
              <option value="text">Texte / HTML</option>
              <option value="video">Vidéo</option>
              <option value="pdf">PDF</option>
              <option value="document">Document</option>
              <option value="presentation">Présentation</option>
              <option value="quiz">Quiz</option>
              <option value="exercise">Exercice</option>
              <option value="link">Lien externe</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Durée (min)</label>
            <input type="number" name="duration_minutes" id="edit-lesson-duration" class="form-control" min="0">
          </div>
        </div>
        <div id="edit-field-url" class="form-group">
          <label class="form-label">URL (vidéo ou lien)</label>
          <input type="url" name="content_url" id="edit-lesson-url" class="form-control" placeholder="https://...">
        </div>
        <div id="edit-field-file" class="form-group" style="display:none">
          <label class="form-label">Remplacer le fichier</label>
          <input type="file" name="lesson_file" class="form-control">
        </div>
        <div id="edit-field-quiz" class="form-group" style="display:none">
          <label class="form-label">Quiz lié</label>
          <select name="quiz_id" class="form-control" id="edit-lesson-quiz">
            <option value="">— Aucun (délier) —</option>
            <?php foreach ($allQuizzes as $q): ?>
            <option value="<?= $q['id'] ?>">
              [<?= ucfirst(e($q['quiz_type'])) ?>] <?= e($q['title']) ?> — <?= e($q['first_name'].' '.$q['last_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">XP attribués</label>
            <input type="number" name="xp_reward" id="edit-lesson-xp" class="form-control" min="0">
          </div>
          <div class="form-group" style="display:flex;align-items:center;padding-top:28px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
              <input type="checkbox" name="is_mandatory" id="edit-lesson-mandatory" value="1">
              <span style="font-size:13px">Obligatoire</span>
            </label>
          </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-edit-lesson').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Compétences groupées par activité type (données PHP → JS)
var compsByAt = <?= json_encode($compsByAt) ?>;

function updateCompetencies(atId) {
  var group  = document.getElementById('mod-comp-group');
  var select = document.getElementById('mod-comp-select');
  select.innerHTML = '<option value="">— Toutes les compétences du bloc —</option>';
  var comps = compsByAt[atId] || [];
  if (!atId || comps.length === 0) {
    group.style.display = 'none';
    return;
  }
  comps.forEach(function(c) {
    var opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.code + ' — ' + c.title;
    select.appendChild(opt);
  });
  group.style.display = '';
}

function toggleModule(id) {
  const el = document.getElementById('module-' + id);
  const ch = document.getElementById('chevron-' + id);
  if (el.style.display === 'none') { el.style.display = 'block'; ch.style.transform = ''; }
  else { el.style.display = 'none'; ch.style.transform = 'rotate(-90deg)'; }
}

function openAddLesson(moduleId, moduleTitle) {
  document.getElementById('lesson-module-id').value = moduleId;
  document.getElementById('modal-lesson-module').textContent = moduleTitle;
  document.getElementById('modal-lesson').style.display = 'flex';
}

function toggleLessonFields() {
  const type = document.getElementById('lesson-type-select').value;
  const needsFile = ['pdf','document','presentation','exercise'].includes(type);
  const needsUrl  = ['video','link'].includes(type);
  const needsQuiz = type === 'quiz';
  document.getElementById('field-url').style.display   = needsUrl  ? '' : 'none';
  document.getElementById('field-file').style.display  = needsFile ? '' : 'none';
  document.getElementById('field-quiz').style.display  = needsQuiz ? '' : 'none';
}

function toggleEditLessonFields() {
  const type = document.getElementById('edit-lesson-type').value;
  const needsFile = ['pdf','document','presentation','exercise'].includes(type);
  const needsUrl  = ['video','link'].includes(type);
  const needsQuiz = type === 'quiz';
  document.getElementById('edit-field-url').style.display   = needsUrl  ? '' : 'none';
  document.getElementById('edit-field-file').style.display  = needsFile ? '' : 'none';
  document.getElementById('edit-field-quiz').style.display  = needsQuiz ? '' : 'none';
}

// Event delegation pour les boutons éditer capsule
document.addEventListener('click', function(e) {
  var btn = e.target.closest('.btn-edit-lesson');
  if (!btn) return;
  document.getElementById('edit-lesson-id').value       = btn.dataset.id;
  document.getElementById('edit-lesson-title').value    = btn.dataset.title;
  document.getElementById('edit-lesson-duration').value = btn.dataset.duration || '';
  document.getElementById('edit-lesson-xp').value       = btn.dataset.xp || '10';
  document.getElementById('edit-lesson-url').value      = btn.dataset.url || '';
  document.getElementById('edit-lesson-mandatory').checked = btn.dataset.mandatory === '1';
  var typeSelect = document.getElementById('edit-lesson-type');
  typeSelect.value = btn.dataset.type || 'text';
  toggleEditLessonFields();
  var quizSel = document.getElementById('edit-lesson-quiz');
  if (quizSel) quizSel.value = btn.dataset.quizId || '';
  document.getElementById('modal-edit-lesson').style.display = 'flex';
});

// Fermer modals en cliquant à l'extérieur
document.getElementById('modal-edit-lesson').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});

// Réinitialiser le sélecteur de compétence à l'ouverture du modal
document.getElementById('modal-module').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
document.getElementById('modal-lesson').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});

// Reset compétence quand on ouvre le modal module
document.querySelector('button[onclick*="modal-module"]') && document.querySelector('button[onclick*="modal-module"]').addEventListener('click', function() {
  var sel = document.getElementById('mod-at-select');
  if (sel) { sel.value = ''; updateCompetencies(''); }
});
</script>
<?php renderFooter(); ?>
