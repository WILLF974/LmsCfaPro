<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();

$pdo         = getDB();
$userId      = (int)$_SESSION['user_id'];
$quizId      = (int)($_GET['id'] ?? 0);
$formationId = (int)($_GET['formation_id'] ?? 0);

if (!$quizId) { setFlash('error', 'Quiz introuvable.'); redirect(url('student/formations/index.php')); }

// Charger le quiz
$quizStmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = ?');
$quizStmt->execute([$quizId]);
$quiz = $quizStmt->fetch();
if (!$quiz) { setFlash('error', 'Quiz introuvable.'); redirect(url('student/formations/index.php')); }

// Vérifier le nombre de tentatives déjà utilisées
$attemptsStmt = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ? AND quiz_id = ? AND status = "completed"');
$attemptsStmt->execute([$userId, $quizId]);
$usedAttempts = (int)$attemptsStmt->fetchColumn();

// Dernière tentative réussie
$passedStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE user_id = ? AND quiz_id = ? AND passed = 1 ORDER BY completed_at DESC LIMIT 1');
$passedStmt->execute([$userId, $quizId]);
$bestAttempt = $passedStmt->fetch();

// Toutes les tentatives terminées pour l'historique
$historyStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE user_id = ? AND quiz_id = ? AND status = "completed" ORDER BY attempt_number DESC');
$historyStmt->execute([$userId, $quizId]);
$history = $historyStmt->fetchAll();

// Tentative en cours ?
$ongoingStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE user_id = ? AND quiz_id = ? AND status = "in_progress" ORDER BY id DESC LIMIT 1');
$ongoingStmt->execute([$userId, $quizId]);
$ongoingAttempt = $ongoingStmt->fetch();

// ── ACTION : Soumettre les réponses ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_quiz') {
    requireCsrf();

    $attemptId = (int)($_POST['attempt_id'] ?? 0);

    // Vérifier que la tentative appartient bien à cet utilisateur
    $checkStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id = ? AND user_id = ? AND quiz_id = ? AND status = "in_progress"');
    $checkStmt->execute([$attemptId, $userId, $quizId]);
    $attempt = $checkStmt->fetch();
    if (!$attempt) { setFlash('error', 'Tentative invalide.'); redirect(url("student/quiz/take.php?id=$quizId&formation_id=$formationId")); }

    // Charger toutes les questions + options
    $questionsStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY order_num');
    $questionsStmt->execute([$quizId]);
    $questions = $questionsStmt->fetchAll();

    $rawScore  = 0;
    $maxScore  = 0;

    foreach ($questions as $q) {
        $maxScore += $q['points'];
        $optionsStmt = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = ? ORDER BY order_num');
        $optionsStmt->execute([$q['id']]);
        $options = $optionsStmt->fetchAll();

        $textResponse     = null;
        $selectedOptionIds = null;
        $isCorrect        = 0;
        $pointsEarned     = 0;

        if ($q['question_type'] === 'short_answer' || $q['question_type'] === 'long_answer') {
            $textResponse = trim($_POST['answer'][$q['id']] ?? '');
            // Pas de correction automatique pour les réponses ouvertes
            $isCorrect = null;
            $pointsEarned = 0;
        } else {
            $selected = $_POST['answer'][$q['id']] ?? [];
            if (!is_array($selected)) $selected = [$selected];
            $selected = array_map('intval', array_filter($selected));
            $selectedOptionIds = $selected ? implode(',', $selected) : null;

            // Calculer si correct
            $correctIds = array_column(array_filter($options, fn($o) => $o['is_correct']), 'id');
            sort($correctIds); sort($selected);

            if ($q['question_type'] === 'single_choice' || $q['question_type'] === 'true_false') {
                $isCorrect = (count($selected) === 1 && in_array($selected[0], $correctIds)) ? 1 : 0;
            } else {
                // multiple_choice : toutes bonnes et aucune mauvaise
                $isCorrect = ($selected === $correctIds) ? 1 : 0;
            }

            if ($isCorrect) $pointsEarned = $q['points'];
        }

        $rawScore += $pointsEarned;

        $pdo->prepare('INSERT INTO quiz_attempt_answers (attempt_id, question_id, selected_option_ids, text_response, is_correct, points_earned) VALUES (?,?,?,?,?,?)')
            ->execute([$attemptId, $q['id'], $selectedOptionIds, $textResponse, $isCorrect, $pointsEarned]);
    }

    $scorePercent = $maxScore > 0 ? round(($rawScore / $maxScore) * 100) : 0;
    $passed       = $scorePercent >= $quiz['passing_score'] ? 1 : 0;
    $timeSpent    = (int)($_POST['time_spent'] ?? 0);

    $pdo->prepare('UPDATE quiz_attempts SET status="completed", completed_at=NOW(), raw_score=?, max_score=?, score=?, passed=?, time_spent_seconds=?, formation_id=? WHERE id=?')
        ->execute([$rawScore, $maxScore, $scorePercent, $passed, $timeSpent, $formationId ?: null, $attemptId]);

    // XP si réussi pour la première fois
    if ($passed && !$bestAttempt) {
        addXP($userId, $quiz['xp_reward'], 'Quiz réussi : ' . $quiz['title'], 'quiz', $quizId);
    }

    auditLog('quiz_submitted', 'quiz_attempt', $attemptId);
    redirect(url("student/quiz/take.php?id=$quizId&formation_id=$formationId&result=$attemptId"));
}

// ── ACTION : Soumettre pour correction enseignant ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_for_review') {
    requireCsrf();
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $checkStmt = $pdo->prepare('SELECT id FROM quiz_attempts WHERE id=? AND user_id=? AND quiz_id=? AND status="completed"');
    $checkStmt->execute([$attemptId, $userId, $quizId]);
    if ($checkStmt->fetch()) {
        $pdo->prepare('UPDATE quiz_attempts SET submitted_for_review=1, review_status="pending", formation_id=COALESCE(formation_id,?) WHERE id=?')
            ->execute([$formationId ?: null, $attemptId]);
        setFlash('success', 'Votre quiz a été soumis à votre enseignant pour correction.');
    }
    redirect(url("student/quiz/take.php?id=$quizId&formation_id=$formationId&result=$attemptId"));
}

// ── ACTION : Démarrer une tentative ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_quiz') {
    requireCsrf();

    if ($usedAttempts >= $quiz['max_attempts']) {
        setFlash('error', 'Vous avez épuisé vos tentatives pour ce quiz.');
        redirect(url("student/quiz/take.php?id=$quizId&formation_id=$formationId"));
    }

    // Abandonner toute tentative en cours
    $pdo->prepare('UPDATE quiz_attempts SET status="abandoned" WHERE user_id=? AND quiz_id=? AND status="in_progress"')
        ->execute([$userId, $quizId]);

    $attemptNum = $usedAttempts + 1;
    $pdo->prepare('INSERT INTO quiz_attempts (user_id, quiz_id, attempt_number, status) VALUES (?,?,?,"in_progress")')
        ->execute([$userId, $quizId, $attemptNum]);
    $newAttemptId = (int)$pdo->lastInsertId();

    redirect(url("student/quiz/take.php?id=$quizId&formation_id=$formationId&attempt=$newAttemptId"));
}

// ── MODE : Voir résultat ─────────────────────────────────────
$resultId = (int)($_GET['result'] ?? 0);
if ($resultId) {
    $resStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id = ? AND user_id = ? AND quiz_id = ?');
    $resStmt->execute([$resultId, $userId, $quizId]);
    $resultAttempt = $resStmt->fetch();

    // Charger les réponses avec questions et options
    $answersStmt = $pdo->prepare('
        SELECT a.*, q.question_text, q.question_type, q.points, q.explanation
        FROM quiz_attempt_answers a
        JOIN quiz_questions q ON a.question_id = q.id
        WHERE a.attempt_id = ?
        ORDER BY q.order_num
    ');
    $answersStmt->execute([$resultId]);
    $answers = $answersStmt->fetchAll();

    $answersWithOptions = [];
    foreach ($answers as $ans) {
        $optsStmt = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = ? ORDER BY order_num');
        $optsStmt->execute([$ans['question_id']]);
        $ans['options'] = $optsStmt->fetchAll();
        $ans['selected_ids'] = $ans['selected_option_ids'] ? array_map('intval', explode(',', $ans['selected_option_ids'])) : [];
        $answersWithOptions[] = $ans;
    }

    // Vérifier si des questions ouvertes existent
    $hasOpenQuestions = false;
    foreach ($answersWithOptions as $ans) {
        if ($ans['question_type'] === 'short_answer' || $ans['question_type'] === 'long_answer') {
            $hasOpenQuestions = true; break;
        }
    }

    renderHead('Résultats — ' . $quiz['title']);
    renderSidebar(isStudent() ? 'student' : (isTeacher() ? 'teacher' : 'admin'));
    renderTopbar('Résultats du quiz', [
        ['Mes formations', url('student/formations/index.php')],
        ['Parcours', url('student/formations/view.php?id='.$formationId)],
        ['Quiz', '']
    ]);
    ?>
    <div class="page-content fade-in">

      <!-- Résultat global -->
      <div class="card" style="margin-bottom:24px;border:2px solid <?= $resultAttempt['passed'] ? 'rgba(16,185,129,.5)' : 'rgba(239,68,68,.4)' ?>">
        <div class="card-body" style="padding:32px;text-align:center">
          <div style="font-size:64px;margin-bottom:16px"><?= $resultAttempt['passed'] ? '🏆' : '😕' ?></div>
          <h1 style="font-size:26px;margin-bottom:8px;color:<?= $resultAttempt['passed'] ? 'var(--success)' : 'var(--danger)' ?>">
            <?= $resultAttempt['passed'] ? 'Quiz réussi !' : 'Quiz non réussi' ?>
          </h1>
          <p style="color:var(--text-muted);margin-bottom:24px"><?= e($quiz['title']) ?></p>

          <div style="display:inline-flex;gap:32px;padding:20px 40px;background:var(--bg-elevated);border-radius:var(--radius-lg);margin-bottom:24px;flex-wrap:wrap;justify-content:center">
            <div>
              <div style="font-size:40px;font-weight:900;color:<?= $resultAttempt['passed'] ? 'var(--success)' : 'var(--danger)' ?>"><?= round($resultAttempt['score']) ?>%</div>
              <div style="font-size:12px;color:var(--text-muted)">Score obtenu</div>
            </div>
            <div style="width:1px;background:var(--border)"></div>
            <div>
              <div style="font-size:40px;font-weight:900;color:var(--text-muted)"><?= $quiz['passing_score'] ?>%</div>
              <div style="font-size:12px;color:var(--text-muted)">Score requis</div>
            </div>
            <div style="width:1px;background:var(--border)"></div>
            <div>
              <div style="font-size:40px;font-weight:900;color:var(--warning)"><?= round($resultAttempt['raw_score']) ?>/<?= round($resultAttempt['max_score']) ?></div>
              <div style="font-size:12px;color:var(--text-muted)">Points</div>
            </div>
            <?php if ($resultAttempt['time_spent_seconds']): ?>
            <div style="width:1px;background:var(--border)"></div>
            <div>
              <div style="font-size:40px;font-weight:900;color:var(--primary-light)"><?= formatDuration((int)($resultAttempt['time_spent_seconds']/60) ?: 1) ?></div>
              <div style="font-size:12px;color:var(--text-muted)">Temps passé</div>
            </div>
            <?php endif; ?>
          </div>

          <?php if ($resultAttempt['passed']): ?>
          <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:var(--radius);padding:12px 20px;margin-bottom:20px;display:inline-block">
            <i class="fas fa-bolt" style="color:var(--warning)"></i> +<?= $quiz['xp_reward'] ?> XP gagnés !
          </div>
          <?php endif; ?>

          <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
            <a href="<?= url('student/formations/view.php?id='.$formationId) ?>" class="btn btn-secondary">
              <i class="fas fa-arrow-left"></i> Retour au parcours
            </a>
            <?php $usedNow = count($history); ?>
            <?php if (!$resultAttempt['passed'] && $usedNow < $quiz['max_attempts']): ?>
            <form method="POST">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="start_quiz">
              <button type="submit" class="btn btn-primary"><i class="fas fa-redo"></i> Réessayer (<?= $quiz['max_attempts'] - $usedNow ?> restante(s))</button>
            </form>
            <?php elseif ($resultAttempt['passed'] && $usedNow < $quiz['max_attempts']): ?>
            <form method="POST">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="start_quiz">
              <button type="submit" class="btn btn-ghost"><i class="fas fa-redo"></i> Refaire</button>
            </form>
            <?php endif; ?>
          </div>

          <!-- Soumission pour correction enseignant -->
          <?php if ($resultAttempt['review_status'] === 'graded'): ?>
          <div style="margin-top:24px;padding:16px 24px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);border-radius:var(--radius);text-align:left">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
              <i class="fas fa-check-circle" style="color:var(--success);font-size:18px"></i>
              <strong style="color:var(--success)">Corrigé par votre enseignant</strong>
              <?php if ($resultAttempt['teacher_score'] !== null): ?>
              <span class="badge badge-success" style="font-size:14px;padding:4px 12px"><?= $resultAttempt['teacher_score'] ?>/<?= round($quiz['max_attempts'] > 0 ? 20 : 20) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($resultAttempt['teacher_feedback']): ?>
            <p style="color:var(--text-muted);font-size:13px;margin:0;font-style:italic">"<?= e($resultAttempt['teacher_feedback']) ?>"</p>
            <?php endif; ?>
          </div>
          <?php elseif ($resultAttempt['review_status'] === 'pending'): ?>
          <div style="margin-top:24px;padding:14px 20px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:var(--radius);display:flex;align-items:center;gap:10px">
            <i class="fas fa-clock" style="color:var(--warning)"></i>
            <span style="color:var(--text-muted);font-size:13px">Quiz soumis à votre enseignant — correction en attente.</span>
          </div>
          <?php else: ?>
          <div style="margin-top:24px;border-top:1px solid var(--border);padding-top:20px">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px">
              <?= $hasOpenQuestions ? '<i class="fas fa-pencil-alt" style="color:var(--warning)"></i> Ce quiz contient des réponses ouvertes qui nécessitent une correction manuelle.' : '<i class="fas fa-paper-plane" style="color:var(--primary-light)"></i> Soumettez votre quiz à votre enseignant pour recevoir une correction personnalisée.' ?>
            </p>
            <form method="POST">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="submit_for_review">
              <input type="hidden" name="attempt_id" value="<?= $resultAttempt['id'] ?>">
              <button type="submit" class="btn btn-primary"
                onclick="return confirm('Soumettre ce quiz à votre enseignant pour correction ?')">
                <i class="fas fa-paper-plane"></i> Soumettre pour correction
              </button>
            </form>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Détail des réponses -->
      <?php if ($quiz['show_results']): ?>
      <div style="display:flex;flex-direction:column;gap:16px">
        <h2 style="font-size:16px;font-weight:700">Détail des réponses</h2>
        <?php foreach ($answersWithOptions as $i => $ans): ?>
        <div class="card" style="border-left:4px solid <?= $ans['is_correct'] ? 'var(--success)' : ($ans['is_correct'] === null ? 'var(--warning)' : 'var(--danger)') ?>">
          <div class="card-body">
            <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px">
              <div style="width:28px;height:28px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;
                background:<?= $ans['is_correct'] ? 'rgba(16,185,129,.2)' : ($ans['is_correct'] === null ? 'rgba(245,158,11,.2)' : 'rgba(239,68,68,.2)') ?>;
                color:<?= $ans['is_correct'] ? 'var(--success)' : ($ans['is_correct'] === null ? 'var(--warning)' : 'var(--danger)') ?>">
                <?= $i + 1 ?>
              </div>
              <div style="flex:1">
                <div style="font-size:14px;font-weight:600;margin-bottom:10px"><?= e($ans['question_text']) ?></div>

                <?php if ($ans['question_type'] === 'short_answer' || $ans['question_type'] === 'long_answer'): ?>
                <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:10px 14px;font-size:13px">
                  <?= $ans['text_response'] ? e($ans['text_response']) : '<em style="color:var(--text-faint)">Sans réponse</em>' ?>
                </div>
                <div style="margin-top:6px;font-size:11px;color:var(--warning)"><i class="fas fa-info-circle"></i> Réponse ouverte — évaluation manuelle</div>

                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:6px">
                  <?php foreach ($ans['options'] as $opt):
                    $isSelected = in_array($opt['id'], $ans['selected_ids']);
                    $isCorrectOpt = (bool)$opt['is_correct'];
                    $bg = $isCorrectOpt ? 'rgba(16,185,129,.12)' : ($isSelected && !$isCorrectOpt ? 'rgba(239,68,68,.1)' : 'var(--bg-elevated)');
                    $border = $isCorrectOpt ? '1px solid rgba(16,185,129,.4)' : ($isSelected && !$isCorrectOpt ? '1px solid rgba(239,68,68,.35)' : '1px solid var(--border)');
                  ?>
                  <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:var(--radius);background:<?= $bg ?>;border:<?= $border ?>">
                    <?php if ($isCorrectOpt): ?>
                      <i class="fas fa-check-circle" style="color:var(--success);flex-shrink:0"></i>
                    <?php elseif ($isSelected): ?>
                      <i class="fas fa-times-circle" style="color:var(--danger);flex-shrink:0"></i>
                    <?php else: ?>
                      <i class="far fa-circle" style="color:var(--text-faint);flex-shrink:0"></i>
                    <?php endif; ?>
                    <span style="font-size:13px;flex:1"><?= e($opt['option_text']) ?></span>
                    <?php if ($isSelected && !$isCorrectOpt): ?><span style="font-size:11px;color:var(--danger)">Votre réponse</span><?php endif; ?>
                    <?php if ($isCorrectOpt && $quiz['show_correct_answers']): ?><span style="font-size:11px;color:var(--success)">Bonne réponse</span><?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($ans['explanation'] && $quiz['show_correct_answers']): ?>
                <div style="margin-top:10px;padding:10px 14px;background:rgba(99,102,241,.08);border-radius:var(--radius);border-left:3px solid var(--primary);font-size:13px;color:var(--text-muted)">
                  <i class="fas fa-lightbulb" style="color:var(--warning);margin-right:6px"></i><?= e($ans['explanation']) ?>
                </div>
                <?php endif; ?>
              </div>
              <div style="flex-shrink:0;text-align:right">
                <?php if ($ans['is_correct'] !== null): ?>
                <span style="font-size:13px;font-weight:700;color:<?= $ans['is_correct'] ? 'var(--success)' : 'var(--danger)' ?>">
                  <?= (int)$ans['points_earned'] ?>/<?= $ans['points'] ?> pt<?= $ans['points'] > 1 ? 's' : '' ?>
                </span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php
    renderFooter();
    exit;
}

// ── MODE : Passer le quiz ────────────────────────────────────
$attemptId = (int)($_GET['attempt'] ?? 0);
if ($attemptId) {
    // Vérifier que la tentative est valide
    $checkStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id = ? AND user_id = ? AND quiz_id = ? AND status = "in_progress"');
    $checkStmt->execute([$attemptId, $userId, $quizId]);
    $currentAttempt = $checkStmt->fetch();
    if (!$currentAttempt) { redirect(url("student/quiz/take.php?id=$quizId&formation_id=$formationId")); }

    // Charger les questions
    $questionsStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY ' . ($quiz['shuffle_questions'] ? 'RAND()' : 'order_num'));
    $questionsStmt->execute([$quizId]);
    $questions = $questionsStmt->fetchAll();

    foreach ($questions as &$q) {
        $optsStmt = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = ? ORDER BY ' . ($quiz['shuffle_answers'] ? 'RAND()' : 'order_num'));
        $optsStmt->execute([$q['id']]);
        $q['options'] = $optsStmt->fetchAll();
    }
    unset($q);

    renderHead($quiz['title']);
    renderSidebar(isStudent() ? 'student' : (isTeacher() ? 'teacher' : 'admin'));
    renderTopbar($quiz['title'], [
        ['Mes formations', url('student/formations/index.php')],
        ['Parcours', url('student/formations/view.php?id='.$formationId)],
        ['Quiz', '']
    ]);
    ?>
    <div class="page-content fade-in">

      <!-- Header quiz -->
      <div class="card" style="margin-bottom:20px">
        <div class="card-body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
          <div style="flex:1">
            <h1 style="font-size:18px;margin-bottom:4px"><?= e($quiz['title']) ?></h1>
            <div style="display:flex;gap:16px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
              <span><i class="fas fa-question-circle"></i> <?= count($questions) ?> question(s)</span>
              <span><i class="fas fa-trophy"></i> Seuil : <?= $quiz['passing_score'] ?>%</span>
              <span><i class="fas fa-bolt" style="color:var(--warning)"></i> +<?= $quiz['xp_reward'] ?> XP si réussi</span>
              <?php if ($quiz['time_limit_minutes']): ?>
              <span id="timer-display" style="color:var(--warning);font-weight:700"><i class="fas fa-stopwatch"></i> <span id="timer-text"><?= $quiz['time_limit_minutes'] ?>:00</span></span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($quiz['instructions']): ?>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:10px 14px;font-size:13px;color:var(--text-muted);max-width:360px">
            <i class="fas fa-info-circle" style="margin-right:6px"></i><?= e($quiz['instructions']) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Formulaire questions -->
      <form method="POST" id="quiz-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="submit_quiz">
        <input type="hidden" name="attempt_id" value="<?= $attemptId ?>">
        <input type="hidden" name="time_spent" id="time-spent-input" value="0">

        <div style="display:flex;flex-direction:column;gap:20px">
          <?php foreach ($questions as $i => $q): ?>
          <div class="card" id="question-<?= $q['id'] ?>">
            <div class="card-body">
              <div style="display:flex;align-items:flex-start;gap:12px">
                <!-- Numéro -->
                <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white;flex-shrink:0">
                  <?= $i + 1 ?>
                </div>
                <div style="flex:1">
                  <div style="font-size:15px;font-weight:600;margin-bottom:14px;line-height:1.5"><?= e($q['question_text']) ?></div>

                  <?php if ($q['question_type'] === 'short_answer'): ?>
                  <input type="text" name="answer[<?= $q['id'] ?>]" class="form-control" placeholder="Votre réponse..." style="max-width:480px">

                  <?php elseif ($q['question_type'] === 'long_answer'): ?>
                  <textarea name="answer[<?= $q['id'] ?>]" class="form-control" rows="4" placeholder="Votre réponse détaillée..."></textarea>

                  <?php else: ?>
                  <div style="display:flex;flex-direction:column;gap:8px">
                    <?php
                      $inputType = $q['question_type'] === 'multiple_choice' ? 'checkbox' : 'radio';
                      $inputName = $q['question_type'] === 'multiple_choice' ? "answer[{$q['id']}][]" : "answer[{$q['id']}]";
                    ?>
                    <?php foreach ($q['options'] as $opt): ?>
                    <label style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:var(--radius);border:1px solid var(--border);cursor:pointer;transition:background .15s,border-color .15s" class="quiz-option">
                      <input type="<?= $inputType ?>" name="<?= $inputName ?>" value="<?= $opt['id'] ?>"
                        style="width:16px;height:16px;flex-shrink:0;accent-color:var(--primary)">
                      <span style="font-size:14px"><?= e($opt['option_text']) ?></span>
                    </label>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <div style="margin-top:8px;font-size:11px;color:var(--text-faint)">
                    <?= $q['question_type'] === 'multiple_choice' ? 'Plusieurs réponses possibles' : ($q['question_type'] === 'single_choice' || $q['question_type'] === 'true_false' ? 'Une seule réponse' : '') ?>
                    · <?= $q['points'] ?> point(s)
                  </div>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:24px">
          <a href="<?= url('student/formations/view.php?id='.$formationId) ?>" class="btn btn-ghost"
            onclick="return confirm('Abandonner le quiz ? Votre progression sera perdue.')">
            <i class="fas fa-times"></i> Abandonner
          </a>
          <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('Soumettre vos réponses ? Vous ne pourrez plus les modifier.')">
            <i class="fas fa-paper-plane"></i> Soumettre le quiz
          </button>
        </div>
      </form>
    </div>

    <style>
    .quiz-option:hover { background:var(--bg-elevated); border-color:var(--primary); }
    .quiz-option:has(input:checked) { background:rgba(99,102,241,.1); border-color:var(--primary); }
    </style>
    <script>
    // Suivi du temps passé
    const startTime = Date.now();
    document.getElementById('quiz-form').addEventListener('submit', function() {
      document.getElementById('time-spent-input').value = Math.floor((Date.now() - startTime) / 1000);
    });

    <?php if ($quiz['time_limit_minutes']): ?>
    // Compte à rebours
    let remaining = <?= $quiz['time_limit_minutes'] * 60 ?>;
    const timerEl = document.getElementById('timer-text');
    const timerInterval = setInterval(() => {
      remaining--;
      const m = Math.floor(remaining / 60);
      const s = remaining % 60;
      timerEl.textContent = m + ':' + String(s).padStart(2, '0');
      if (remaining <= 60) timerEl.parentElement.style.color = 'var(--danger)';
      if (remaining <= 0) {
        clearInterval(timerInterval);
        document.getElementById('time-spent-input').value = <?= $quiz['time_limit_minutes'] * 60 ?>;
        document.getElementById('quiz-form').submit();
      }
    }, 1000);
    <?php endif; ?>
    </script>
    <?php
    renderFooter();
    exit;
}

// ── MODE : Page d'accueil du quiz (avant démarrage) ─────────
renderHead($quiz['title']);
renderSidebar(isStudent() ? 'student' : (isTeacher() ? 'teacher' : 'admin'));
renderTopbar($quiz['title'], [
    ['Mes formations', url('student/formations/index.php')],
    ['Parcours', url('student/formations/view.php?id='.$formationId)],
    ['Quiz', '']
]);
?>
<div class="page-content fade-in" style="max-width:680px;margin:0 auto">

  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:32px;text-align:center">
      <div style="font-size:52px;margin-bottom:16px">📝</div>
      <h1 style="font-size:22px;margin-bottom:8px"><?= e($quiz['title']) ?></h1>
      <?php if ($quiz['description']): ?>
      <p style="color:var(--text-muted);font-size:14px;margin-bottom:20px"><?= e($quiz['description']) ?></p>
      <?php endif; ?>

      <!-- Infos quiz -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:16px;margin-bottom:24px;text-align:center">
        <div style="padding:14px;background:var(--bg-elevated);border-radius:var(--radius)">
          <div style="font-size:22px;font-weight:800;color:var(--primary-light)"><?php $qCount = $pdo->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?'); $qCount->execute([$quizId]); echo $qCount->fetchColumn(); ?></div>
          <div style="font-size:12px;color:var(--text-muted)">Questions</div>
        </div>
        <div style="padding:14px;background:var(--bg-elevated);border-radius:var(--radius)">
          <div style="font-size:22px;font-weight:800;color:var(--success)"><?= $quiz['passing_score'] ?>%</div>
          <div style="font-size:12px;color:var(--text-muted)">Score requis</div>
        </div>
        <div style="padding:14px;background:var(--bg-elevated);border-radius:var(--radius)">
          <div style="font-size:22px;font-weight:800;color:var(--warning)"><?= $quiz['max_attempts'] - $usedAttempts ?>/<?= $quiz['max_attempts'] ?></div>
          <div style="font-size:12px;color:var(--text-muted)">Tentatives restantes</div>
        </div>
        <?php if ($quiz['time_limit_minutes']): ?>
        <div style="padding:14px;background:var(--bg-elevated);border-radius:var(--radius)">
          <div style="font-size:22px;font-weight:800;color:var(--info)"><?= $quiz['time_limit_minutes'] ?> min</div>
          <div style="font-size:12px;color:var(--text-muted)">Temps limite</div>
        </div>
        <?php endif; ?>
        <div style="padding:14px;background:var(--bg-elevated);border-radius:var(--radius)">
          <div style="font-size:22px;font-weight:800;color:var(--warning)">+<?= $quiz['xp_reward'] ?> XP</div>
          <div style="font-size:12px;color:var(--text-muted)">Récompense</div>
        </div>
      </div>

      <?php if ($quiz['instructions']): ?>
      <div style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);border-radius:var(--radius);padding:14px 20px;margin-bottom:24px;text-align:left">
        <div style="font-size:12px;font-weight:700;color:var(--primary-light);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px"><i class="fas fa-info-circle"></i> Instructions</div>
        <p style="font-size:14px;color:var(--text-muted);margin:0"><?= e($quiz['instructions']) ?></p>
      </div>
      <?php endif; ?>

      <?php if ($bestAttempt): ?>
      <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);border-radius:var(--radius);padding:12px 20px;margin-bottom:20px">
        <i class="fas fa-check-circle" style="color:var(--success)"></i>
        Meilleur score : <strong style="color:var(--success)"><?= round($bestAttempt['score']) ?>%</strong> — Quiz déjà réussi
      </div>
      <?php endif; ?>

      <?php if ($usedAttempts >= $quiz['max_attempts']): ?>
      <div class="alert" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--danger);border-radius:var(--radius);padding:12px 20px;margin-bottom:16px">
        <i class="fas fa-ban"></i> Vous avez utilisé toutes vos tentatives.
      </div>
      <a href="<?= url('student/formations/view.php?id='.$formationId) ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Retour au parcours</a>
      <?php else: ?>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="start_quiz">
        <button type="submit" class="btn btn-primary btn-lg" style="padding:14px 40px">
          <i class="fas fa-play"></i> <?= $usedAttempts > 0 ? 'Réessayer le quiz' : 'Démarrer le quiz' ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Historique des tentatives -->
  <?php if (!empty($history)): ?>
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-history"></i> Historique des tentatives</h3></div>
    <div style="overflow-x:auto">
      <table class="table">
        <thead><tr><th>Tentative</th><th>Score</th><th>Points</th><th>Résultat</th><th>Durée</th><th>Date</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($history as $h): ?>
          <tr>
            <td>#<?= $h['attempt_number'] ?></td>
            <td style="font-weight:700;color:<?= $h['passed'] ? 'var(--success)' : 'var(--danger)' ?>"><?= round($h['score']) ?>%</td>
            <td><?= round($h['raw_score']) ?>/<?= round($h['max_score']) ?></td>
            <td><?= $h['passed'] ? '<span class="badge badge-success"><i class="fas fa-check"></i> Réussi</span>' : '<span class="badge badge-danger"><i class="fas fa-times"></i> Échoué</span>' ?></td>
            <td><?= $h['time_spent_seconds'] ? formatDuration((int)($h['time_spent_seconds']/60) ?: 1) : '—' ?></td>
            <td><?= $h['completed_at'] ? formatDate($h['completed_at']) : '—' ?></td>
            <td><a href="<?= url("student/quiz/take.php?id=$quizId&formation_id=$formationId&result=".$h['id']) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-eye"></i></a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
