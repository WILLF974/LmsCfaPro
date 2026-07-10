<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$quizId = (int)($_GET['id'] ?? 0);

if (!$quizId) { redirect(url('teacher/quizzes/index.php')); }

$quizStmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = ?');
$quizStmt->execute([$quizId]);
$quiz = $quizStmt->fetch();
if (!$quiz) { setFlash('error', 'Quiz introuvable.'); redirect(url('teacher/quizzes/index.php')); }

$questionsStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY order_num');
$questionsStmt->execute([$quizId]);
$questions = $questionsStmt->fetchAll();

foreach ($questions as &$q) {
    $optsStmt = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = ? ORDER BY order_num');
    $optsStmt->execute([$q['id']]);
    $q['options'] = $optsStmt->fetchAll();
}
unset($q);

// Données scoring pour JS (uniquement ce qui est nécessaire côté client)
$jsQuestions = array_map(fn($q) => [
    'id'          => (int)$q['id'],
    'type'        => $q['question_type'],
    'points'      => (int)$q['points'],
    'explanation' => $q['explanation'] ?? '',
    'options'     => array_map(fn($o) => [
        'id'         => (int)$o['id'],
        'is_correct' => (bool)$o['is_correct'],
    ], $q['options']),
], $questions);

renderHead('Aperçu — ' . $quiz['title']);
renderSidebar('teacher');
renderTopbar('Aperçu du quiz', [
    ['Quiz', url('teacher/quizzes/index.php')],
    [mb_substr($quiz['title'], 0, 40), url('teacher/quizzes/create.php?id=' . $quizId)],
    ['Aperçu', ''],
]);
?>
<div class="page-content fade-in">

  <!-- Bandeau aperçu -->
  <div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);border-radius:var(--radius-lg);padding:12px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:10px;flex:1">
      <i class="fas fa-eye" style="color:var(--warning);font-size:18px"></i>
      <div>
        <div style="font-weight:700;color:var(--warning);font-size:13px">Mode aperçu enseignant</div>
        <div style="font-size:12px;color:var(--text-muted)">Aucune réponse n'est enregistrée. Répondez puis cliquez sur « Voir les corrections ».</div>
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <a href="<?= url('teacher/quizzes/create.php?id=' . $quizId) ?>" class="btn btn-ghost btn-sm">
        <i class="fas fa-edit"></i> Modifier le quiz
      </a>
      <a href="<?= url('teacher/quizzes/index.php') ?>" class="btn btn-ghost btn-sm">
        <i class="fas fa-list"></i> Retour à la liste
      </a>
    </div>
  </div>

  <!-- Header quiz (identique à la vue étudiant) -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="flex:1">
        <h1 style="font-size:18px;margin-bottom:4px"><?= e($quiz['title']) ?></h1>
        <div style="display:flex;gap:16px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
          <span><i class="fas fa-question-circle"></i> <?= count($questions) ?> question(s)</span>
          <span><i class="fas fa-trophy"></i> Seuil : <?= $quiz['passing_score'] ?>%</span>
          <span><i class="fas fa-redo"></i> <?= $quiz['max_attempts'] ?> essais max</span>
          <?php if ($quiz['time_limit_minutes']): ?>
          <span><i class="fas fa-clock"></i> <?= $quiz['time_limit_minutes'] ?> min</span>
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

  <!-- Résumé score (caché jusqu'à la soumission) -->
  <div id="score-banner" style="display:none;background:var(--bg-card);border:2px solid var(--primary);border-radius:var(--radius-lg);padding:24px;margin-bottom:20px;text-align:center">
    <div style="font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Résultat de l'aperçu</div>
    <div id="score-value" style="font-size:48px;font-weight:900;margin-bottom:6px"></div>
    <div id="score-detail" style="font-size:14px;color:var(--text-muted);margin-bottom:16px"></div>
    <div id="score-verdict" style="font-size:14px;font-weight:600;margin-bottom:20px"></div>
    <button type="button" id="reset-btn" onclick="resetPreview()" class="btn btn-secondary" style="display:none">
      <i class="fas fa-redo"></i> Recommencer l'aperçu
    </button>
  </div>

  <!-- Questions -->
  <form id="preview-form" onsubmit="event.preventDefault(); showCorrections()">
    <div style="display:flex;flex-direction:column;gap:20px">
      <?php foreach ($questions as $i => $q): ?>
      <div class="card" id="question-<?= $q['id'] ?>">
        <div class="card-body">
          <div style="display:flex;align-items:flex-start;gap:12px">
            <!-- Numéro -->
            <div class="q-bubble" style="width:32px;height:32px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white;flex-shrink:0">
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
                  $inputName = $q['question_type'] === 'multiple_choice'
                      ? "answer[{$q['id']}][]"
                      : "answer[{$q['id']}]";
                ?>
                <?php foreach ($q['options'] as $opt): ?>
                <label class="quiz-option" data-opt-id="<?= $opt['id'] ?>" style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:var(--radius);border:1px solid var(--border);cursor:pointer;transition:background .15s,border-color .15s">
                  <input type="<?= $inputType ?>" name="<?= $inputName ?>" value="<?= $opt['id'] ?>"
                    style="width:16px;height:16px;flex-shrink:0;accent-color:var(--primary)">
                  <span class="opt-text" style="font-size:14px;flex:1"><?= e($opt['option_text']) ?></span>
                  <span class="opt-icon" style="display:none;font-size:14px"></span>
                </label>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <!-- Feedback points (caché avant soumission) -->
              <div class="points-display" style="margin-top:8px;font-size:12px;height:18px"></div>

              <!-- Explication (cachée avant soumission) -->
              <?php if ($q['explanation']): ?>
              <div class="explanation" style="display:none;margin-top:10px;padding:10px 14px;background:rgba(99,102,241,.08);border-radius:var(--radius);border-left:3px solid var(--primary);font-size:13px;color:var(--text-muted);white-space:pre-wrap">
                <i class="fas fa-lightbulb" style="color:var(--warning);margin-right:6px"></i><?= e($q['explanation']) ?>
              </div>
              <?php else: ?>
              <div class="explanation" style="display:none"></div>
              <?php endif; ?>

              <div style="margin-top:6px;font-size:11px;color:var(--text-faint)">
                <?= $q['question_type'] === 'multiple_choice' ? 'Plusieurs réponses possibles' : ($q['question_type'] === 'single_choice' || $q['question_type'] === 'true_false' ? 'Une seule réponse' : 'Réponse libre') ?>
                · <?= $q['points'] ?> point(s)
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:24px">
      <a href="<?= url('teacher/quizzes/index.php') ?>" class="btn btn-ghost">
        <i class="fas fa-times"></i> Quitter l'aperçu
      </a>
      <button type="submit" id="submit-btn" class="btn btn-primary btn-lg">
        <i class="fas fa-check-double"></i> Voir les corrections
      </button>
    </div>
  </form>
</div>

<style>
.quiz-option:hover { background:var(--bg-elevated); border-color:var(--primary); }
.quiz-option:has(input:checked) { background:rgba(99,102,241,.1); border-color:var(--primary); }
.quiz-option.opt-correct  { background:rgba(16,185,129,.12) !important; border-color:rgba(16,185,129,.5) !important; }
.quiz-option.opt-wrong    { background:rgba(239,68,68,.10)  !important; border-color:rgba(239,68,68,.4)  !important; }
.quiz-option.opt-missed   { background:rgba(16,185,129,.06) !important; border-color:rgba(16,185,129,.3) !important; }
</style>

<script>
const QUIZ_DATA   = <?= json_encode($jsQuestions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PASS_SCORE  = <?= (int)$quiz['passing_score'] ?>;
const QUIZ_TITLE  = <?= json_encode($quiz['title']) ?>;

function showCorrections() {
    let rawScore = 0, maxScore = 0;
    const form = document.getElementById('preview-form');

    QUIZ_DATA.forEach(q => {
        maxScore += q.points;
        const card = document.getElementById('question-' + q.id);
        const expl = card.querySelector('.explanation');
        const pts  = card.querySelector('.points-display');

        if (q.type === 'short_answer' || q.type === 'long_answer') {
            // Réponse libre : juste afficher l'explication
            if (expl) expl.style.display = 'block';
            if (pts)  pts.innerHTML = '<span style="color:var(--warning)"><i class="fas fa-pen"></i> Correction manuelle requise</span>';
        } else {
            const correctIds = q.options.filter(o => o.is_correct).map(o => o.id);
            let selected = [];

            if (q.type === 'single_choice' || q.type === 'true_false') {
                const checked = card.querySelector('input[type="radio"]:checked');
                if (checked) selected = [parseInt(checked.value)];
            } else {
                card.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => {
                    selected.push(parseInt(cb.value));
                });
            }

            const sortedC = [...correctIds].sort((a,b)=>a-b);
            const sortedS = [...selected].sort((a,b)=>a-b);
            const isCorrect = sortedC.length > 0 &&
                              sortedS.length > 0 &&
                              JSON.stringify(sortedC) === JSON.stringify(sortedS);

            if (isCorrect) rawScore += q.points;

            // Colorer les options
            q.options.forEach(opt => {
                const label = card.querySelector(`.quiz-option[data-opt-id="${opt.id}"]`);
                const icon  = label ? label.querySelector('.opt-icon') : null;
                if (!label) return;

                label.querySelector('input').disabled = true;

                if (opt.is_correct && selected.includes(opt.id)) {
                    label.classList.add('opt-correct');
                    if (icon) { icon.textContent = '✓'; icon.style.color = 'var(--success)'; icon.style.display = ''; }
                } else if (!opt.is_correct && selected.includes(opt.id)) {
                    label.classList.add('opt-wrong');
                    if (icon) { icon.textContent = '✗'; icon.style.color = 'var(--danger)'; icon.style.display = ''; }
                } else if (opt.is_correct && !selected.includes(opt.id)) {
                    label.classList.add('opt-missed');
                    if (icon) { icon.textContent = '→ bonne réponse'; icon.style.color = 'var(--success)'; icon.style.fontSize = '11px'; icon.style.display = ''; }
                }
            });

            // Points
            if (pts) {
                pts.innerHTML = isCorrect
                    ? `<span style="color:var(--success);font-weight:700"><i class="fas fa-check"></i> ${q.points}/${q.points} pt${q.points>1?'s':''}</span>`
                    : `<span style="color:var(--danger);font-weight:700"><i class="fas fa-times"></i> 0/${q.points} pt${q.points>1?'s':''}</span>`;
            }

            // Explication
            if (expl) expl.style.display = 'block';

            // Colorer le numéro de question
            const bubble = card.querySelector('.q-bubble');
            if (bubble) {
                bubble.style.background = isCorrect ? 'var(--success)' : 'var(--danger)';
            }
        }
    });

    // Bloquer les champs texte
    form.querySelectorAll('input[type="text"], textarea').forEach(el => el.disabled = true);

    // Afficher le résumé
    const pct = maxScore > 0 ? Math.round(rawScore / maxScore * 100) : 0;
    const passed = pct >= PASS_SCORE;

    document.getElementById('score-value').textContent  = pct + '%';
    document.getElementById('score-value').style.color  = passed ? 'var(--success)' : 'var(--danger)';
    document.getElementById('score-detail').textContent = `${rawScore} / ${maxScore} points`;
    document.getElementById('score-verdict').innerHTML  = passed
        ? `<i class="fas fa-check-circle" style="color:var(--success)"></i> Score de passage atteint (${PASS_SCORE}% requis)`
        : `<i class="fas fa-times-circle" style="color:var(--danger)"></i> Score de passage non atteint (${PASS_SCORE}% requis)`;

    document.getElementById('score-banner').style.display = 'block';
    document.getElementById('submit-btn').style.display   = 'none';
    document.getElementById('reset-btn').style.display    = '';

    document.getElementById('score-banner').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function resetPreview() {
    const form = document.getElementById('preview-form');

    // Réactiver et vider les champs
    form.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(i => { i.checked = false; i.disabled = false; });
    form.querySelectorAll('input[type="text"], textarea').forEach(i => { i.value = ''; i.disabled = false; });

    // Retirer les classes de correction sur les options
    document.querySelectorAll('.quiz-option').forEach(l => {
        l.classList.remove('opt-correct', 'opt-wrong', 'opt-missed');
        const icon = l.querySelector('.opt-icon');
        if (icon) { icon.style.display = 'none'; icon.textContent = ''; }
    });

    // Remettre les bulles de numéro en couleur primaire
    document.querySelectorAll('.q-bubble').forEach(b => b.style.background = 'var(--primary)');

    // Cacher les explications et points
    document.querySelectorAll('.explanation').forEach(e => e.style.display = 'none');
    document.querySelectorAll('.points-display').forEach(p => p.innerHTML = '');

    // Cacher le bandeau score
    document.getElementById('score-banner').style.display = 'none';
    document.getElementById('submit-btn').style.display   = '';
    document.getElementById('reset-btn').style.display    = 'none';

    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>
<?php renderFooter(); ?>
