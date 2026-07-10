<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$errors = [];

$formations = $pdo->query("SELECT id, title FROM formations WHERE status='active' ORDER BY title")->fetchAll();

// Module/séance pré-lié (venant de seance_create.php après redirection)
$linkedModuleId    = (int)($_GET['module_id'] ?? 0);
$linkedSeanceTitle = trim($_GET['seance_title'] ?? '');

// Toutes les séances de type quiz (pour sélecteur "Associer à une séance")
$quizSeances = $pdo->query("
    SELECT m.id, m.title AS seance_title,
           s.title AS seq_title, c.code AS comp_code, at.code AS at_code
    FROM modules m
    LEFT JOIN sequences s    ON m.sequence_id = s.id
    LEFT JOIN competencies c  ON s.competency_id = c.id
    LEFT JOIN activity_types at ON c.activity_type_id = at.id
    WHERE m.content_type = 'quiz'
    ORDER BY at.code, c.code, s.title, m.order_num
")->fetchAll();

// ── Mode édition ─────────────────────────────────────────────
$editId = (int)($_GET['id'] ?? 0);
$editQuiz = null;
$existingQuestions = [];
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = ? AND created_by = ?');
    $stmt->execute([$editId, $userId]);
    $editQuiz = $stmt->fetch();
    if (!$editQuiz) { setFlash('error', 'Quiz introuvable ou accès refusé.'); redirect(url('teacher/quizzes/index.php')); }

    $qStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY order_num');
    $qStmt->execute([$editId]);
    $existingQuestions = $qStmt->fetchAll();
    foreach ($existingQuestions as &$q) {
        $oStmt = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = ? ORDER BY order_num');
        $oStmt->execute([$q['id']]);
        $q['options'] = $oStmt->fetchAll();
    }
    unset($q);
}
$isEdit = $editQuiz !== null;

// ── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $postEditId = (int)($_POST['edit_id'] ?? 0);

    $quiz = [
        'title'                => trim($_POST['title'] ?? ''),
        'description'          => trim($_POST['description'] ?? ''),
        'instructions'         => trim($_POST['instructions'] ?? ''),
        'quiz_type'            => $_POST['quiz_type'] ?? 'practice',
        'formation_id'         => (int)($_POST['formation_id'] ?? 0) ?: null,
        'activity_type_id'     => (int)($_POST['activity_type_id'] ?? 0) ?: null,
        'competency_id'        => (int)($_POST['competency_id'] ?? 0) ?: null,
        'module_id'            => (int)($_POST['seance_module_id'] ?? $_POST['module_id'] ?? 0) ?: null,
        'passing_score'        => (int)($_POST['passing_score'] ?? 70),
        'max_attempts'         => (int)($_POST['max_attempts'] ?? 3),
        'time_limit_minutes'   => (int)($_POST['time_limit_minutes'] ?? 0) ?: null,
        'shuffle_questions'    => isset($_POST['shuffle_questions']) ? 1 : 0,
        'shuffle_answers'      => isset($_POST['shuffle_answers']) ? 1 : 0,
        'show_results'         => isset($_POST['show_results']) ? 1 : 0,
        'show_correct_answers' => isset($_POST['show_correct_answers']) ? 1 : 0,
        'xp_reward'            => (int)($_POST['xp_reward'] ?? 50),
    ];

    if (!$quiz['title']) $errors[] = 'Titre requis.';
    if ($quiz['passing_score'] < 0 || $quiz['passing_score'] > 100) $errors[] = 'Score de passage : 0–100.';

    // Parse questions
    $questions = [];
    foreach ($_POST['q_text'] ?? [] as $i => $qText) {
        if (empty(trim($qText))) continue;
        $opts = [];
        foreach ($_POST['opt_text'][$i] ?? [] as $j => $optText) {
            if (empty(trim($optText))) continue;
            $opts[] = ['text' => trim($optText), 'is_correct' => isset($_POST['opt_correct'][$i][$j]) ? 1 : 0, 'order' => $j+1];
        }
        $questions[] = [
            'text'        => trim($qText),
            'type'        => $_POST['q_type'][$i] ?? 'single_choice',
            'points'      => max(1, (int)($_POST['q_points'][$i] ?? 1)),
            'explanation' => trim($_POST['q_explanation'][$i] ?? ''),
            'order'       => $i + 1,
            'options'     => $opts,
        ];
    }

    if (empty($questions)) $errors[] = 'Ajoutez au moins une question.';

    if (empty($errors)) {
        $vals = [$quiz['module_id'],$quiz['formation_id'],$quiz['activity_type_id'],$quiz['competency_id'],$quiz['title'],$quiz['description'],$quiz['instructions'],$quiz['quiz_type'],$quiz['passing_score'],$quiz['max_attempts'],$quiz['time_limit_minutes'],$quiz['shuffle_questions'],$quiz['shuffle_answers'],$quiz['show_results'],$quiz['show_correct_answers'],$quiz['xp_reward']];

        if ($postEditId) {
            // Vérifier propriété
            $check = $pdo->prepare('SELECT id FROM quizzes WHERE id = ? AND created_by = ?');
            $check->execute([$postEditId, $userId]);
            if (!$check->fetch()) { setFlash('error', 'Accès refusé.'); redirect(url('teacher/quizzes/index.php')); }

            $pdo->prepare("UPDATE quizzes SET module_id=?,formation_id=?,activity_type_id=?,competency_id=?,title=?,description=?,instructions=?,quiz_type=?,passing_score=?,max_attempts=?,time_limit_minutes=?,shuffle_questions=?,shuffle_answers=?,show_results=?,show_correct_answers=?,xp_reward=? WHERE id=?")
                ->execute(array_merge($vals, [$postEditId]));

            // Recréer les questions
            $pdo->prepare('DELETE FROM quiz_questions WHERE quiz_id = ?')->execute([$postEditId]);
            foreach ($questions as $q) {
                $pdo->prepare('INSERT INTO quiz_questions (quiz_id,question_text,question_type,explanation,points,order_num) VALUES (?,?,?,?,?,?)')->execute([$postEditId,$q['text'],$q['type'],$q['explanation'],$q['points'],$q['order']]);
                $qId = (int)$pdo->lastInsertId();
                foreach ($q['options'] as $opt) {
                    $pdo->prepare('INSERT INTO quiz_options (question_id,option_text,is_correct,order_num) VALUES (?,?,?,?)')->execute([$qId,$opt['text'],$opt['is_correct'],$opt['order']]);
                }
            }
            auditLog('quiz_updated', 'quiz', $postEditId);
            setFlash('success', 'Quiz mis à jour avec ' . count($questions) . ' questions !');
        } else {
            $pdo->prepare("INSERT INTO quizzes (module_id,formation_id,activity_type_id,competency_id,title,description,instructions,quiz_type,passing_score,max_attempts,time_limit_minutes,shuffle_questions,shuffle_answers,show_results,show_correct_answers,xp_reward,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute(array_merge($vals, [$userId]));
            $quizId = (int)$pdo->lastInsertId();
            foreach ($questions as $q) {
                $pdo->prepare('INSERT INTO quiz_questions (quiz_id,question_text,question_type,explanation,points,order_num) VALUES (?,?,?,?,?,?)')->execute([$quizId,$q['text'],$q['type'],$q['explanation'],$q['points'],$q['order']]);
                $qId = (int)$pdo->lastInsertId();
                foreach ($q['options'] as $opt) {
                    $pdo->prepare('INSERT INTO quiz_options (question_id,option_text,is_correct,order_num) VALUES (?,?,?,?)')->execute([$qId,$opt['text'],$opt['is_correct'],$opt['order']]);
                }
            }
            auditLog('quiz_created', 'quiz', $quizId);
            setFlash('success', 'Quiz créé avec ' . count($questions) . ' questions !');
        }
        redirect(url('teacher/quizzes/index.php'));
    }
}

$pageTitle = $isEdit ? 'Modifier le quiz' : 'Nouveau quiz';
renderHead($pageTitle);
renderSidebar('teacher');
renderTopbar($pageTitle, [['Enseignant', url('teacher/index.php')], ['Quiz', url('teacher/quizzes/index.php')], [$isEdit ? 'Modifier' : 'Nouveau', '']]);
?>
<div class="page-content fade-in">
  <?php foreach ($errors as $err): ?><div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= e($err) ?></div><?php endforeach; ?>

  <?php if (!$isEdit): ?>
  <!-- Bannière import IA -->
  <div style="background:linear-gradient(135deg,rgba(139,92,246,.12),rgba(99,102,241,.08));border:1px solid rgba(139,92,246,.25);border-radius:var(--radius-xl);padding:18px 22px;margin-bottom:20px;display:flex;align-items:center;gap:16px">
    <div style="width:44px;height:44px;border-radius:12px;background:rgba(139,92,246,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="fas fa-robot" style="color:#a78bfa;font-size:20px"></i>
    </div>
    <div style="flex:1">
      <div style="font-weight:700;color:white;margin-bottom:3px">Créer depuis l'IA (Gemini / ChatGPT)</div>
      <div style="font-size:13px;color:var(--text-muted)">Générez votre quiz en JSON grâce à l'IA, puis importez-le en un clic pour remplir le formulaire automatiquement.</div>
    </div>
    <div style="display:flex;gap:8px;flex-shrink:0">
      <button type="button" onclick="downloadTemplate()" class="btn btn-ghost btn-sm" title="Télécharger la trame JSON vide">
        <i class="fas fa-download"></i> Trame JSON
      </button>
      <button type="button" onclick="openImportModal()" class="btn btn-secondary">
        <i class="fas fa-file-import"></i> Importer un quiz
      </button>
    </div>
  </div>
  <?php else: ?>
  <!-- Bannière mode modification -->
  <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:var(--radius-xl);padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px">
    <div style="width:38px;height:38px;border-radius:10px;background:rgba(245,158,11,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="fas fa-edit" style="color:var(--warning);font-size:16px"></i>
    </div>
    <div style="flex:1">
      <div style="font-weight:700;color:white;font-size:13px;margin-bottom:2px">Mode modification</div>
      <div style="font-size:12px;color:var(--text-muted)">Enregistrez vos modifications, puis testez le quiz pour vérifier le rendu et les corrections.</div>
    </div>
    <div style="display:flex;gap:8px;flex-shrink:0">
      <button type="button" onclick="openImportModal()" class="btn btn-ghost btn-sm" title="Remplacer les questions depuis un JSON IA">
        <i class="fas fa-file-import"></i> Import IA
      </button>
      <a href="<?= url('teacher/quizzes/preview.php?id=' . $editQuiz['id']) ?>" class="btn btn-secondary" target="_blank">
        <i class="fas fa-play-circle"></i> Tester le quiz
      </a>
    </div>
  </div>
  <?php endif; ?>

  <form method="POST" id="quiz-form">
    <?= csrfField() ?>
    <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= $editQuiz['id'] ?>"><?php endif; ?>
    <?php if (!$isEdit && $linkedModuleId): ?>
    <input type="hidden" name="module_id" id="preset-module-id" value="<?= $linkedModuleId ?>">
    <div style="background:rgba(139,92,246,.08);border:1px solid rgba(139,92,246,.25);border-radius:var(--radius);padding:12px 16px;margin-bottom:16px;font-size:13px;color:var(--text-secondary);display:flex;align-items:center;gap:10px">
      <i class="fas fa-link" style="color:#a78bfa"></i>
      Ce quiz sera automatiquement lié à la séance <strong style="color:white"><?= $linkedSeanceTitle ? e($linkedSeanceTitle) : 'sélectionnée' ?></strong>.
    </div>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start">

      <div style="display:flex;flex-direction:column;gap:20px">
        <!-- Quiz Info -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Informations du quiz</h3></div>
          <div class="card-body">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Titre <span class="required">*</span></label>
                <input type="text" name="title" class="form-control" placeholder="Ex: Quiz Module 1..." required value="<?= $isEdit ? e($editQuiz['title']) : e($linkedSeanceTitle) ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Type</label>
                <select name="quiz_type" class="form-control">
                  <?php foreach (['practice'=>'Entraînement','evaluation'=>'Évaluation','certification'=>'Certification','survey'=>'Sondage'] as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= ($isEdit && $editQuiz['quiz_type'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Formation associée</label>
              <select name="formation_id" id="quiz-formation" class="form-control" onchange="onFormationChange(this.value)">
                <option value="">-- Aucune --</option>
                <?php foreach ($formations as $f): ?>
                <option value="<?= $f['id'] ?>" <?= ($isEdit && $editQuiz['formation_id'] == $f['id']) ? 'selected' : '' ?>><?= e($f['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-row" id="quiz-bloc-module-row" style="display:none">
              <div class="form-group">
                <label class="form-label">Bloc (Activité type RNCP)</label>
                <select name="activity_type_id" id="quiz-bloc" class="form-control" onchange="onBlocChange(this.value)">
                  <option value="">-- Tous les blocs --</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Module</label>
                <select name="<?= (!$isEdit && $linkedModuleId) ? '_module_cascade' : 'module_id' ?>" id="quiz-module" class="form-control">
                  <option value="">-- Tous les modules --</option>
                </select>
              </div>
            </div>
            <div class="form-group" id="quiz-comp-group" style="display:none">
              <label class="form-label">Compétence visée</label>
              <select name="competency_id" id="quiz-comp" class="form-control">
                <option value="">-- Toutes les compétences du bloc --</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Instructions</label>
              <textarea name="instructions" class="form-control" rows="2" placeholder="Instructions pour les apprenants..."><?= $isEdit ? e($editQuiz['instructions'] ?? '') : '' ?></textarea>
            </div>
            <?php if (!$linkedModuleId && $quizSeances): ?>
            <div class="form-group">
              <label class="form-label">Séance associée <span style="font-weight:400;color:var(--text-muted)">(optionnel)</span></label>
              <select name="seance_module_id" id="seance-module-select" class="form-control">
                <option value="">-- Aucune séance liée --</option>
                <?php foreach ($quizSeances as $sm): ?>
                <option value="<?= $sm['id'] ?>"
                  <?= ($isEdit && (int)$editQuiz['module_id'] === $sm['id']) ? 'selected' : '' ?>>
                  <?= $sm['at_code'] ? e($sm['at_code']) . ' › ' : '' ?><?= $sm['comp_code'] ? e($sm['comp_code']) . ' › ' : '' ?><?= e($sm['seance_title']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Lie ce quiz à une séance de type Quiz dans la hiérarchie RNCP.</div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Questions -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Questions (<span id="q-count">0</span>)</h3>
            <div style="display:flex;gap:8px">
              <button type="button" class="btn btn-ghost btn-sm" onclick="openImportModal()" title="Importer des questions depuis JSON IA"><i class="fas fa-file-import"></i> Import IA</button>
              <button type="button" class="btn btn-primary btn-sm" onclick="addQuestion()"><i class="fas fa-plus"></i> Ajouter</button>
            </div>
          </div>
          <div class="card-body">
            <div id="questions-container" style="display:flex;flex-direction:column;gap:20px"></div>
            <div id="empty-questions" style="text-align:center;padding:40px;color:var(--text-muted)">
              <i class="fas fa-question-circle" style="font-size:36px;opacity:.3;margin-bottom:12px;display:block"></i>
              <p>Aucune question. Cliquez sur "Ajouter" pour commencer.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Settings Sidebar -->
      <div style="display:flex;flex-direction:column;gap:16px;position:sticky;top:80px">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Paramètres</h3></div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
            <div class="form-group" style="margin:0">
              <label class="form-label">Score de passage (%)</label>
              <input type="number" name="passing_score" class="form-control" value="<?= $isEdit ? (int)$editQuiz['passing_score'] : 70 ?>" min="0" max="100">
            </div>
            <div class="form-group" style="margin:0">
              <label class="form-label">Tentatives max</label>
              <input type="number" name="max_attempts" class="form-control" value="<?= $isEdit ? (int)$editQuiz['max_attempts'] : 3 ?>" min="1" max="10">
            </div>
            <div class="form-group" style="margin:0">
              <label class="form-label">Temps limite (min)</label>
              <input type="number" name="time_limit_minutes" class="form-control" placeholder="Sans limite" min="1" value="<?= $isEdit && $editQuiz['time_limit_minutes'] ? (int)$editQuiz['time_limit_minutes'] : '' ?>">
            </div>
            <div class="form-group" style="margin:0">
              <label class="form-label">XP récompense</label>
              <input type="number" name="xp_reward" class="form-control" value="<?= $isEdit ? (int)$editQuiz['xp_reward'] : 50 ?>" min="0">
            </div>
            <hr style="border-color:var(--border)">
            <?php
            $toggles = [
                ['shuffle_questions',    'Mélanger les questions',       true],
                ['shuffle_answers',      'Mélanger les réponses',        true],
                ['show_results',         'Afficher le résultat',         true],
                ['show_correct_answers', 'Afficher les bonnes réponses', false],
            ];
            foreach ($toggles as [$name, $label, $default]):
                $checked = $isEdit ? (bool)$editQuiz[$name] : $default;
            ?>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
              <label class="toggle"><input type="checkbox" name="<?= $name ?>" <?= $checked ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
              <span style="font-size:13px"><?= $label ?></span>
            </label>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center">
              <i class="fas fa-save"></i> <?= $isEdit ? 'Enregistrer les modifications' : 'Créer le quiz' ?>
            </button>
            <a href="<?= url('teacher/quizzes/index.php') ?>" class="btn btn-ghost w-full" style="justify-content:center">Annuler</a>
            <?php if ($isEdit): ?>
            <a href="<?= url('teacher/quizzes/preview.php?id=' . $editQuiz['id']) ?>" class="btn btn-ghost w-full" style="justify-content:center;color:var(--warning)" target="_blank">
              <i class="fas fa-play-circle"></i> Tester le quiz
            </a>
            <?php endif; ?>
            <?php if ($isEdit): ?>
            <hr style="border-color:var(--border);margin:4px 0">
            <form method="POST" action="<?= url('teacher/quizzes/delete.php') ?>" onsubmit="return confirm('Supprimer définitivement ce quiz et toutes ses données ? Cette action est irréversible.')">
              <?= csrfField() ?>
              <input type="hidden" name="quiz_id" value="<?= $editQuiz['id'] ?>">
              <button type="submit" class="btn btn-ghost w-full" style="justify-content:center;color:var(--danger)">
                <i class="fas fa-trash"></i> Supprimer ce quiz
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- Question template (hidden) -->
<template id="question-template">
  <div class="question-builder" data-index="__IDX__">
    <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div style="font-size:12px;font-weight:700;color:var(--primary-light);text-transform:uppercase;letter-spacing:.08em">Question #<span class="q-num">__NUM__</span></div>
        <div style="display:flex;gap:6px">
          <select name="q_type[__IDX__]" class="form-control" style="width:160px" onchange="updateQuestionUI(this)">
            <option value="single_choice">Choix unique</option>
            <option value="multiple_choice">Choix multiple</option>
            <option value="true_false">Vrai/Faux</option>
            <option value="short_answer">Réponse courte</option>
          </select>
          <input type="number" name="q_points[__IDX__]" value="1" min="1" style="width:60px" class="form-control" title="Points">
          <button type="button" onclick="removeQuestion(this)" class="btn btn-ghost btn-sm" style="color:var(--danger)"><i class="fas fa-trash"></i></button>
        </div>
      </div>
      <textarea name="q_text[__IDX__]" class="form-control" rows="2" placeholder="Rédigez votre question ici..." style="margin-bottom:14px" required></textarea>
      <div class="options-zone">
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Options de réponse</div>
        <div class="options-list" style="display:flex;flex-direction:column;gap:6px"></div>
        <button type="button" onclick="addOption(this)" class="btn btn-ghost btn-sm" style="margin-top:8px"><i class="fas fa-plus"></i> Ajouter une option</button>
      </div>
      <div class="form-group" style="margin-top:12px;margin-bottom:0">
        <label class="form-label" style="font-size:11px">Explication (affichée après réponse)</label>
        <textarea name="q_explanation[__IDX__]" class="form-control" rows="2" placeholder="Explication de la bonne réponse..."></textarea>
      </div>
    </div>
  </div>
</template>

<template id="option-template">
  <div class="option-row" style="display:flex;align-items:center;gap:8px">
    <input type="checkbox" name="opt_correct[__IDX__][__OPTIDX__]" style="width:16px;height:16px;accent-color:var(--success);flex-shrink:0" title="Bonne réponse">
    <input type="text" name="opt_text[__IDX__][__OPTIDX__]" class="form-control" placeholder="Option de réponse..." required>
    <button type="button" onclick="this.closest('.option-row').remove()" class="btn btn-ghost btn-sm" style="color:var(--danger);flex-shrink:0"><i class="fas fa-times"></i></button>
  </div>
</template>

<!-- ══ Modal Import IA ══════════════════════════════════════════ -->
<div id="import-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);overflow-y:auto">
  <div style="min-height:100%;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-xl);width:100%;max-width:760px;box-shadow:0 24px 80px rgba(0,0,0,.5)">

      <!-- Header -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:12px">
          <div style="width:36px;height:36px;border-radius:10px;background:rgba(139,92,246,.2);display:flex;align-items:center;justify-content:center">
            <i class="fas fa-robot" style="color:#a78bfa"></i>
          </div>
          <div>
            <div style="font-weight:700;font-size:16px;color:white">Importer un quiz depuis l'IA</div>
            <div style="font-size:12px;color:var(--text-muted)">Générez le JSON avec Gemini, ChatGPT ou tout autre IA</div>
          </div>
        </div>
        <button type="button" onclick="closeImportModal()" style="background:none;border:none;color:var(--text-muted);font-size:20px;cursor:pointer;padding:4px 8px">&times;</button>
      </div>

      <!-- Tabs -->
      <div style="display:flex;border-bottom:1px solid var(--border)">
        <button type="button" class="import-tab active" data-tab="prompt" onclick="switchTab('prompt')" style="flex:1;padding:12px;background:none;border:none;color:var(--text-muted);font-size:13px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent">
          <i class="fas fa-copy" style="margin-right:6px"></i>1. Prompt IA
        </button>
        <button type="button" class="import-tab" data-tab="json" onclick="switchTab('json')" style="flex:1;padding:12px;background:none;border:none;color:var(--text-muted);font-size:13px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent">
          <i class="fas fa-code" style="margin-right:6px"></i>2. Coller le JSON
        </button>
        <button type="button" class="import-tab" data-tab="file" onclick="switchTab('file')" style="flex:1;padding:12px;background:none;border:none;color:var(--text-muted);font-size:13px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent">
          <i class="fas fa-upload" style="margin-right:6px"></i>3. Fichier .json
        </button>
      </div>

      <!-- Tab: Prompt IA -->
      <div id="tab-prompt" class="import-tab-content" style="padding:24px">
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:12px">
          Copiez ce prompt, collez-le dans <strong style="color:white">Gemini</strong> ou <strong style="color:white">ChatGPT</strong>, puis remplacez <code style="background:rgba(255,255,255,.08);padding:1px 5px;border-radius:4px">[THÈME]</code> par votre sujet.
        </div>
        <div style="position:relative">
          <textarea id="gemini-prompt-text" readonly rows="14" style="width:100%;background:rgba(0,0,0,.3);border:1px solid var(--border);border-radius:var(--radius-lg);padding:14px;font-family:monospace;font-size:12px;color:#e2e8f0;resize:none;line-height:1.6"></textarea>
          <button type="button" onclick="copyPrompt()" style="position:absolute;top:10px;right:10px;background:rgba(139,92,246,.3);border:1px solid rgba(139,92,246,.5);color:#a78bfa;padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px;display:flex;align-items:center;gap:5px" id="copy-prompt-btn">
            <i class="fas fa-copy"></i> Copier
          </button>
        </div>
        <div style="margin-top:14px;padding:12px;background:rgba(14,165,233,.08);border:1px solid rgba(14,165,233,.2);border-radius:var(--radius-lg);font-size:12px;color:var(--text-muted)">
          <i class="fas fa-lightbulb" style="color:#38bdf8;margin-right:6px"></i>
          <strong style="color:#38bdf8">Conseil :</strong> Demandez à l'IA de générer 10 à 20 questions avec des types variés. Répondez "uniquement le JSON" si l'IA ajoute du texte autour.
        </div>
      </div>

      <!-- Tab: Coller JSON -->
      <div id="tab-json" class="import-tab-content" style="display:none;padding:24px">
        <label style="font-size:13px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:8px">Collez le JSON généré par l'IA :</label>
        <textarea id="import-json-text" rows="16" placeholder='{ "title": "Mon quiz", "questions": [...] }' style="width:100%;background:rgba(0,0,0,.3);border:1px solid var(--border);border-radius:var(--radius-lg);padding:14px;font-family:monospace;font-size:12px;color:#e2e8f0;resize:vertical;line-height:1.6"></textarea>
        <div id="import-json-error" style="display:none;margin-top:8px;color:var(--danger);font-size:12px"></div>
        <div style="display:flex;gap:8px;margin-top:14px">
          <button type="button" onclick="loadFromTextarea()" class="btn btn-primary" style="flex:1;justify-content:center">
            <i class="fas fa-magic"></i> Charger le quiz
          </button>
          <button type="button" onclick="closeImportModal()" class="btn btn-ghost">Annuler</button>
        </div>
      </div>

      <!-- Tab: Fichier -->
      <div id="tab-file" class="import-tab-content" style="display:none;padding:24px">
        <div style="border:2px dashed var(--border);border-radius:var(--radius-xl);padding:40px;text-align:center;cursor:pointer" onclick="document.getElementById('import-file-input').click()" id="file-drop-zone">
          <i class="fas fa-file-code" style="font-size:36px;color:var(--primary-light);opacity:.5;display:block;margin-bottom:12px"></i>
          <div style="font-weight:600;color:white;margin-bottom:4px">Glissez votre fichier .json ici</div>
          <div style="font-size:13px;color:var(--text-muted)">ou cliquez pour parcourir</div>
          <input type="file" id="import-file-input" accept=".json,application/json" style="display:none" onchange="loadFromFile(this)">
        </div>
        <div id="import-file-error" style="display:none;margin-top:8px;color:var(--danger);font-size:12px"></div>
        <div style="margin-top:14px;text-align:center">
          <button type="button" onclick="downloadTemplate()" class="btn btn-ghost btn-sm">
            <i class="fas fa-download"></i> Télécharger la trame JSON vide
          </button>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="<?= asset('js/main.js') ?>"></script>
<script>
let qCount = 0;

function addQuestion() {
  const tmpl = document.getElementById('question-template').innerHTML;
  const html = tmpl.replace(/__IDX__/g, qCount).replace(/__NUM__/g, qCount+1);
  const container = document.getElementById('questions-container');
  document.getElementById('empty-questions').style.display = 'none';
  container.insertAdjacentHTML('beforeend', html);
  const newQ = container.lastElementChild;
  for (let i=0;i<4;i++) addOption(newQ.querySelector('[onclick="addOption(this)"]'));
  qCount++;
  document.getElementById('q-count').textContent = container.querySelectorAll('.question-builder').length;
  newQ.scrollIntoView({behavior:'smooth',block:'center'});
}

function addOption(btn) {
  const qEl = btn.closest('.question-builder');
  const idx = qEl.dataset.index;
  const list = qEl.querySelector('.options-list');
  const optIdx = list.children.length;
  const tmpl = document.getElementById('option-template').innerHTML;
  const html = tmpl.replace(/__IDX__/g, idx).replace(/__OPTIDX__/g, optIdx);
  list.insertAdjacentHTML('beforeend', html);
}

function removeQuestion(btn) {
  btn.closest('.question-builder').remove();
  const container = document.getElementById('questions-container');
  const remaining = container.querySelectorAll('.question-builder');
  if (remaining.length === 0) document.getElementById('empty-questions').style.display = 'block';
  remaining.forEach((q, i) => { q.querySelector('.q-num').textContent = i+1; });
  document.getElementById('q-count').textContent = remaining.length;
}

function updateQuestionUI(sel) {
  const qEl = sel.closest('.question-builder');
  const optZone = qEl.querySelector('.options-zone');
  const type = sel.value;
  if (type === 'short_answer') {
    optZone.style.display = 'none';
  } else if (type === 'true_false') {
    optZone.style.display = 'block';
    const list = qEl.querySelector('.options-list');
    list.innerHTML = '';
    const idx = qEl.dataset.index;
    ['Vrai','Faux'].forEach((t,i) => {
      list.insertAdjacentHTML('beforeend', `<div class="option-row" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="opt_correct[${idx}][${i}]" style="width:16px;height:16px;accent-color:var(--success)"><input type="text" name="opt_text[${idx}][${i}]" class="form-control" value="${t}" readonly><span style="width:28px"></span></div>`);
    });
    list.querySelector('input[type="checkbox"]').checked = true;
    optZone.querySelector('[onclick="addOption(this)"]').style.display = 'none';
  } else {
    optZone.style.display = 'block';
    optZone.querySelector('[onclick="addOption(this)"]').style.display = '';
  }
}

// ── Charger une question existante (mode édition) ─────────────
function loadExistingQuestion(qData) {
  const idx = qCount;
  const tmpl = document.getElementById('question-template').innerHTML;
  const html = tmpl.replace(/__IDX__/g, idx).replace(/__NUM__/g, idx+1);
  const container = document.getElementById('questions-container');
  document.getElementById('empty-questions').style.display = 'none';
  container.insertAdjacentHTML('beforeend', html);
  const qEl = container.lastElementChild;
  qCount++;
  document.getElementById('q-count').textContent = container.querySelectorAll('.question-builder').length;

  // Type
  const typeSelect = qEl.querySelector(`[name="q_type[${idx}]"]`);
  if (typeSelect) { typeSelect.value = qData.question_type || 'single_choice'; updateQuestionUI(typeSelect); }

  // Texte
  const textarea = qEl.querySelector(`[name="q_text[${idx}]"]`);
  if (textarea) textarea.value = qData.question_text || '';

  // Points
  const pts = qEl.querySelector(`[name="q_points[${idx}]"]`);
  if (pts) pts.value = qData.points || 1;

  // Explication
  const expl = qEl.querySelector(`[name="q_explanation[${idx}]"]`);
  if (expl) expl.value = qData.explanation || '';

  // Options
  const list = qEl.querySelector('.options-list');
  const type = qData.question_type || 'single_choice';
  if (type === 'short_answer') {
    // pas d'options
  } else if (type === 'true_false') {
    // updateQuestionUI a déjà rendu Vrai/Faux
    const checkboxes = list.querySelectorAll('input[type="checkbox"]');
    (qData.options || []).forEach((opt, i) => {
      if (checkboxes[i]) checkboxes[i].checked = parseInt(opt.is_correct) === 1;
    });
  } else {
    list.innerHTML = '';
    const addBtn = qEl.querySelector('[onclick="addOption(this)"]');
    (qData.options || []).forEach(opt => {
      addOption(addBtn);
      const row = list.lastElementChild;
      const oIdx = list.children.length - 1;
      const ti = row.querySelector(`[name="opt_text[${idx}][${oIdx}]"]`);
      const ci = row.querySelector(`[name="opt_correct[${idx}][${oIdx}]"]`);
      if (ti) ti.value = opt.option_text || '';
      if (ci) ci.checked = parseInt(opt.is_correct) === 1;
    });
  }
}

// ── Initialisation ────────────────────────────────────────────
var existingQuestions = <?= json_encode($existingQuestions) ?>;
var editData = {
  formation_id:     <?= $isEdit && $editQuiz['formation_id']     ? (int)$editQuiz['formation_id']     : 'null' ?>,
  activity_type_id: <?= $isEdit && $editQuiz['activity_type_id'] ? (int)$editQuiz['activity_type_id'] : 'null' ?>,
  module_id:        <?= $isEdit && $editQuiz['module_id']        ? (int)$editQuiz['module_id']        : 'null' ?>,
  competency_id:    <?= $isEdit && $editQuiz['competency_id']    ? (int)$editQuiz['competency_id']    : 'null' ?>,
};

if (existingQuestions.length > 0) {
  existingQuestions.forEach(q => loadExistingQuestion(q));
} else {
  addQuestion();
}

const apiBase = '<?= url('api/data.php') ?>';

async function onFormationChange(formationId, preAt, preMod, preComp) {
  const row     = document.getElementById('quiz-bloc-module-row');
  const blocSel = document.getElementById('quiz-bloc');
  const modSel  = document.getElementById('quiz-module');
  const compGrp = document.getElementById('quiz-comp-group');
  const compSel = document.getElementById('quiz-comp');

  blocSel.innerHTML = '<option value="">-- Tous les blocs --</option>';
  modSel.innerHTML  = '<option value="">-- Tous les modules --</option>';
  compSel.innerHTML = '<option value="">-- Toutes les compétences du bloc --</option>';
  compGrp.style.display = 'none';

  if (!formationId) { row.style.display = 'none'; return; }
  row.style.display = '';

  try {
    const r = await fetch(`${apiBase}?action=activity_types_by_formation&formation_id=${formationId}`);
    const blocs = await r.json();
    blocs.forEach(b => blocSel.insertAdjacentHTML('beforeend', `<option value="${b.id}">${b.code} — ${b.title}</option>`));
    if (preAt) blocSel.value = preAt;
  } catch(e) {}

  try {
    const r = await fetch(`${apiBase}?action=modules_by_formation&formation_id=${formationId}`);
    const mods = await r.json();
    mods.forEach(m => modSel.insertAdjacentHTML('beforeend', `<option value="${m.id}">${m.title}</option>`));
    if (preMod) modSel.value = preMod;
  } catch(e) {}

  if (preAt) await onBlocChange(preAt, preComp);
}

async function onBlocChange(atId, preComp) {
  const compGrp = document.getElementById('quiz-comp-group');
  const compSel = document.getElementById('quiz-comp');
  compSel.innerHTML = '<option value="">-- Toutes les compétences du bloc --</option>';

  if (!atId) { compGrp.style.display = 'none'; return; }

  try {
    const r = await fetch(`${apiBase}?action=competencies_by_activity_type&activity_type_id=${atId}`);
    const comps = await r.json();
    if (comps.length > 0) {
      comps.forEach(c => compSel.insertAdjacentHTML('beforeend', `<option value="${c.id}">${c.code} — ${c.title}</option>`));
      compGrp.style.display = '';
      if (preComp) compSel.value = preComp;
    } else {
      compGrp.style.display = 'none';
    }
  } catch(e) { compGrp.style.display = 'none'; }
}

// Pré-remplir les champs AJAX en mode édition
if (editData.formation_id) {
  onFormationChange(editData.formation_id, editData.activity_type_id, editData.module_id, editData.competency_id);
}

// Listener select formation (appel normal sans pré-sélection)
document.getElementById('quiz-formation').addEventListener('change', function() {
  onFormationChange(this.value);
});
document.getElementById('quiz-bloc').addEventListener('change', function() {
  onBlocChange(this.value);
});

// ── Import IA ─────────────────────────────────────────────────

const GEMINI_PROMPT = `Tu es un expert en formation professionnelle et pédagogie.
Crée un quiz complet sur le thème : [THÈME ICI]

Génère le résultat en JSON valide, en respectant EXACTEMENT cette structure (sans texte avant ni après) :

{
  "title": "Titre du quiz",
  "quiz_type": "practice",
  "instructions": "Lisez attentivement chaque question avant de répondre.",
  "passing_score": 70,
  "max_attempts": 3,
  "time_limit_minutes": null,
  "xp_reward": 50,
  "shuffle_questions": true,
  "shuffle_answers": true,
  "show_results": true,
  "show_correct_answers": false,
  "questions": [
    {
      "text": "Question à choix unique ?",
      "type": "single_choice",
      "points": 1,
      "explanation": "Explication de la bonne réponse.",
      "options": [
        { "text": "Bonne réponse", "correct": true },
        { "text": "Mauvaise réponse A", "correct": false },
        { "text": "Mauvaise réponse B", "correct": false },
        { "text": "Mauvaise réponse C", "correct": false }
      ]
    },
    {
      "text": "Question à choix multiples (cochez toutes les bonnes réponses) ?",
      "type": "multiple_choice",
      "points": 2,
      "explanation": "Les bonnes réponses sont A et C.",
      "options": [
        { "text": "Bonne réponse A", "correct": true },
        { "text": "Mauvaise réponse B", "correct": false },
        { "text": "Bonne réponse C", "correct": true }
      ]
    },
    {
      "text": "Cette affirmation est-elle vraie ?",
      "type": "true_false",
      "points": 1,
      "explanation": "C'est vrai car...",
      "options": [
        { "text": "Vrai", "correct": true },
        { "text": "Faux", "correct": false }
      ]
    },
    {
      "text": "Question à réponse courte ?",
      "type": "short_answer",
      "points": 1,
      "explanation": "La réponse attendue est : ...",
      "options": []
    }
  ]
}

Contraintes obligatoires :
- Génère entre 10 et 20 questions variées sur le thème demandé
- Utilise les 4 types : single_choice, multiple_choice, true_false, short_answer
- single_choice : exactement 4 options, une seule correcte
- multiple_choice : 3 à 5 options, 2 ou 3 correctes
- true_false : exactement 2 options ("Vrai" et "Faux")
- short_answer : tableau options vide []
- Chaque question doit avoir une explication pédagogique de la bonne réponse
- Réponds UNIQUEMENT avec le JSON brut, aucun texte avant ou après`;

function openImportModal() {
  document.getElementById('import-modal').style.display = 'block';
  document.body.style.overflow = 'hidden';
  document.getElementById('gemini-prompt-text').value = GEMINI_PROMPT;
  switchTab('prompt');
}

function closeImportModal() {
  document.getElementById('import-modal').style.display = 'none';
  document.body.style.overflow = '';
}

function switchTab(tab) {
  document.querySelectorAll('.import-tab').forEach(t => {
    const active = t.dataset.tab === tab;
    t.style.color = active ? 'white' : 'var(--text-muted)';
    t.style.borderBottomColor = active ? 'var(--primary-light)' : 'transparent';
  });
  document.querySelectorAll('.import-tab-content').forEach(c => c.style.display = 'none');
  document.getElementById('tab-' + tab).style.display = 'block';
}

function copyPrompt() {
  navigator.clipboard.writeText(GEMINI_PROMPT).then(() => {
    const btn = document.getElementById('copy-prompt-btn');
    btn.innerHTML = '<i class="fas fa-check"></i> Copié !';
    btn.style.background = 'rgba(16,185,129,.3)';
    btn.style.borderColor = 'rgba(16,185,129,.5)';
    btn.style.color = '#34d399';
    setTimeout(() => {
      btn.innerHTML = '<i class="fas fa-copy"></i> Copier';
      btn.style.background = 'rgba(139,92,246,.3)';
      btn.style.borderColor = 'rgba(139,92,246,.5)';
      btn.style.color = '#a78bfa';
    }, 2000);
  });
}

function downloadTemplate() {
  const tpl = {
    title: "Titre du quiz",
    quiz_type: "practice",
    instructions: "Lisez attentivement chaque question avant de répondre.",
    passing_score: 70,
    max_attempts: 3,
    time_limit_minutes: null,
    xp_reward: 50,
    shuffle_questions: true,
    shuffle_answers: true,
    show_results: true,
    show_correct_answers: false,
    questions: [
      { text: "Question à choix unique ?", type: "single_choice", points: 1, explanation: "Explication...", options: [{ text: "Bonne réponse", correct: true }, { text: "Option B", correct: false }, { text: "Option C", correct: false }, { text: "Option D", correct: false }] },
      { text: "Question à choix multiples ?", type: "multiple_choice", points: 2, explanation: "Les bonnes réponses sont A et C.", options: [{ text: "Option A", correct: true }, { text: "Option B", correct: false }, { text: "Option C", correct: true }] },
      { text: "Affirmation vrai ou faux ?", type: "true_false", points: 1, explanation: "Explication...", options: [{ text: "Vrai", correct: true }, { text: "Faux", correct: false }] },
      { text: "Question à réponse courte ?", type: "short_answer", points: 1, explanation: "Réponse attendue : ...", options: [] }
    ]
  };
  const blob = new Blob([JSON.stringify(tpl, null, 2)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'trame_quiz.json';
  a.click();
}

function loadFromTextarea() {
  const raw = document.getElementById('import-json-text').value.trim();
  const errEl = document.getElementById('import-json-error');
  errEl.style.display = 'none';
  try {
    const data = JSON.parse(raw);
    applyImportedQuiz(data);
  } catch(e) {
    errEl.textContent = 'JSON invalide : ' + e.message;
    errEl.style.display = 'block';
  }
}

function loadFromFile(input) {
  const errEl = document.getElementById('import-file-error');
  errEl.style.display = 'none';
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    try {
      const data = JSON.parse(e.target.result);
      applyImportedQuiz(data);
    } catch(err) {
      errEl.textContent = 'Fichier JSON invalide : ' + err.message;
      errEl.style.display = 'block';
    }
  };
  reader.readAsText(file);
}

function applyImportedQuiz(data) {
  // Remplir les champs généraux
  if (data.title)        document.querySelector('[name="title"]').value = data.title;
  if (data.quiz_type)    document.querySelector('[name="quiz_type"]').value = data.quiz_type;
  if (data.instructions) document.querySelector('[name="instructions"]').value = data.instructions;
  if (data.passing_score != null)      document.querySelector('[name="passing_score"]').value = data.passing_score;
  if (data.max_attempts != null)       document.querySelector('[name="max_attempts"]').value = data.max_attempts;
  if (data.time_limit_minutes != null) document.querySelector('[name="time_limit_minutes"]').value = data.time_limit_minutes || '';
  if (data.xp_reward != null)          document.querySelector('[name="xp_reward"]').value = data.xp_reward;

  const toggleMap = { shuffle_questions: 'shuffle_questions', shuffle_answers: 'shuffle_answers', show_results: 'show_results', show_correct_answers: 'show_correct_answers' };
  Object.entries(toggleMap).forEach(([key, name]) => {
    if (data[key] != null) { const el = document.querySelector(`[name="${name}"]`); if (el) el.checked = !!data[key]; }
  });

  // Effacer les questions existantes et charger les nouvelles
  if (Array.isArray(data.questions) && data.questions.length > 0) {
    document.getElementById('questions-container').innerHTML = '';
    qCount = 0;
    data.questions.forEach(q => loadImportedQuestion(q));
  }

  closeImportModal();
  document.getElementById('questions-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function loadImportedQuestion(q) {
  // Normalise les champs depuis le format import vers le format interne
  const normalized = {
    question_text: q.text || q.question_text || '',
    question_type: q.type || q.question_type || 'single_choice',
    points:        q.points || 1,
    explanation:   q.explanation || '',
    options: (q.options || []).map((o, i) => ({
      option_text: o.text || o.option_text || '',
      is_correct:  (o.correct === true || o.correct === 1 || o.is_correct === 1 || o.is_correct === true) ? 1 : 0,
      order_num:   i + 1
    }))
  };
  loadExistingQuestion(normalized);
}

// Fermer le modal en cliquant sur le fond
document.getElementById('import-modal').addEventListener('click', function(e) {
  if (e.target === this) closeImportModal();
});
// Fermer avec Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeImportModal(); });
</script>
<?php renderFooter(); ?>
