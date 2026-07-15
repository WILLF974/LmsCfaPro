<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo       = getDB();
$teacherId = (int)$_SESSION['user_id'];
$type      = $_GET['type'] ?? ''; // 'quiz' | 'case_study'
$subId     = (int)($_GET['id'] ?? 0);

if (!$subId || !in_array($type, ['quiz','case_study','exercise'])) {
    setFlash('error', 'Identifiant invalide.');
    redirect(url('teacher/evaluations/index.php'));
}

// ── Charger la soumission ─────────────────────────────────────────────────────
$answers = [];
if ($type === 'quiz') {
    $stmt = $pdo->prepare("
        SELECT qa.*, q.title AS quiz_title, q.passing_score, q.max_attempts,
               q.activity_type_id, q.competency_id,
               u.first_name, u.last_name, u.email,
               f.title AS formation_title, f.id AS formation_id,
               at.code AS at_code, at.title AS at_title,
               co.code AS co_code, co.title AS co_title,
               rt.rncp_code
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        JOIN users u ON qa.user_id = u.id
        LEFT JOIN formations f ON qa.formation_id = f.id
        LEFT JOIN rncp_titles rt ON f.rncp_title_id = rt.id
        LEFT JOIN activity_types at ON q.activity_type_id = at.id
        LEFT JOIN competencies co ON q.competency_id = co.id
        WHERE qa.id = ? AND q.created_by = ? AND qa.status = 'completed'
    ");
    $stmt->execute([$subId, $teacherId]);
    $submission = $stmt->fetch();
    if (!$submission) { setFlash('error', 'Soumission introuvable.'); redirect(url('teacher/evaluations/index.php')); }

    // Charger les réponses avec questions
    $answersStmt = $pdo->prepare("
        SELECT a.*, q.question_text, q.question_type, q.points, q.explanation
        FROM quiz_attempt_answers a
        JOIN quiz_questions q ON a.question_id = q.id
        WHERE a.attempt_id = ?
        ORDER BY q.order_num
    ");
    $answersStmt->execute([$subId]);
    $answers = $answersStmt->fetchAll();
    foreach ($answers as &$ans) {
        $o = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = ? ORDER BY order_num');
        $o->execute([$ans['question_id']]);
        $ans['options'] = $o->fetchAll();
        $ans['selected_ids'] = $ans['selected_option_ids'] ? array_map('intval', explode(',', $ans['selected_option_ids'])) : [];
    }
    unset($ans);

} elseif ($type === 'exercise') {
    $stmt = $pdo->prepare("
        SELECT es.*,
               m.title AS module_title, m.description AS module_description,
               u.first_name, u.last_name, u.email,
               seq.title AS seq_title,
               co.code AS co_code, co.title AS co_title,
               at.code AS at_code, at.title AS at_title,
               rt.rncp_code
        FROM exercise_submissions es
        JOIN modules m ON es.module_id = m.id
        JOIN users   u ON es.user_id   = u.id
        LEFT JOIN sequences      seq ON m.sequence_id      = seq.id
        LEFT JOIN competencies   co  ON seq.competency_id  = co.id
        LEFT JOIN activity_types at  ON co.activity_type_id = at.id
        LEFT JOIN rncp_titles    rt  ON at.rncp_title_id   = rt.id
        WHERE es.id = ?
    ");
    $stmt->execute([$subId]);
    $submission = $stmt->fetch();
    if (!$submission) { setFlash('error', 'Soumission introuvable.'); redirect(url('teacher/evaluations/index.php?section=exercise')); }
} else {
    $stmt = $pdo->prepare("
        SELECT css.*, cs.title AS cs_title, cs.description AS cs_description, cs.file_type,
               u.first_name, u.last_name, u.email,
               f.title AS formation_title,
               at.code AS at_code, at.title AS at_title,
               co.code AS co_code, co.title AS co_title,
               rt.rncp_code,
               CONCAT(author.first_name,' ',author.last_name) AS author_name
        FROM case_study_submissions css
        JOIN case_studies cs ON css.case_study_id = cs.id
        JOIN users u ON css.user_id = u.id
        LEFT JOIN formations f ON css.formation_id = f.id
        LEFT JOIN rncp_titles rt ON f.rncp_title_id = rt.id
        LEFT JOIN activity_types at ON cs.activity_type_id = at.id
        LEFT JOIN competencies co ON cs.competency_id = co.id
        LEFT JOIN users author ON cs.created_by = author.id
        WHERE css.id = ? AND cs.created_by = ?
    ");
    $stmt->execute([$subId, $teacherId]);
    $submission = $stmt->fetch();
    if (!$submission) { setFlash('error', 'Soumission introuvable.'); redirect(url('teacher/evaluations/index.php?section=case_study')); }
}

// ── POST : Sauvegarder la correction ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $feedback    = trim($_POST['feedback']     ?? '');
    $score       = $_POST['score'] !== '' ? (float)$_POST['score'] : null;
    $grade       = trim($_POST['grade']        ?? '');
    $status      = $_POST['status']            ?? 'graded';
    $returnBack  = (int)($_POST['return_back'] ?? 0);

    if ($type === 'exercise') {
        if ($status === 'returned') {
            $pdo->prepare("UPDATE exercise_submissions SET status='returned', feedback=?, graded_by=?, graded_at=NOW() WHERE id=?")
                ->execute([$feedback ?: null, $teacherId, $subId]);
            try { createNotification($submission['user_id'], 'Exercice retourné pour révision',
                'Votre exercice "' . $submission['module_title'] . '" a été retourné' . ($feedback ? ' : ' . mb_strimwidth($feedback, 0, 100, '…') : '.'),
                'warning', url('student/course/seance.php?id=' . $submission['module_id'])); } catch (\Exception $e) {}
        } else {
            $pdo->prepare("UPDATE exercise_submissions SET status='graded', score=?, max_score=?, grade=?, feedback=?, graded_by=?, graded_at=NOW() WHERE id=?")
                ->execute([$score, $_POST['max_score'] !== '' ? (float)$_POST['max_score'] : null, $grade ?: null, $feedback ?: null, $teacherId, $subId]);
            try { createNotification($submission['user_id'], 'Exercice corrigé',
                'Votre exercice "' . $submission['module_title'] . '" a été corrigé' . ($score !== null ? ' — Note : ' . $score . '/' . ($_POST['max_score'] ?: '?') : '') . '.',
                'success', url('student/course/seance.php?id=' . $submission['module_id'])); } catch (\Exception $e) {}
        }
        auditLog('exercise_graded', 'exercise_submissions', $subId);
        setFlash('success', $status === 'returned' ? 'Exercice retourné à l\'apprenant.' : 'Correction enregistrée.');
        redirect(url('teacher/evaluations/index.php?section=exercise'));
    }

    if ($status === 'returned') {
        // Retourner sans note
        if ($type === 'quiz') {
            $pdo->prepare("UPDATE quiz_attempts SET review_status='not_submitted', submitted_for_review=0, teacher_feedback=?, graded_by=?, graded_at=NOW() WHERE id=?")
                ->execute([$feedback ?: null, $teacherId, $subId]);
            try {
                $retMsg = 'Votre quiz "' . $submission['quiz_title'] . '" vous a été retourné pour révision.';
                if ($feedback) $retMsg .= ' Commentaire : ' . mb_strimwidth($feedback, 0, 120, '…');
                createNotification($submission['user_id'], 'Quiz retourné pour révision', $retMsg, 'warning',
                    url('student/quiz/take.php?id=' . $submission['quiz_id'] . '&formation_id=' . ($submission['formation_id'] ?? 0)));
            } catch (Exception $e) {}
        } else {
            $pdo->prepare("UPDATE case_study_submissions SET status='returned', feedback=?, graded_by=?, graded_at=NOW() WHERE id=?")
                ->execute([$feedback ?: null, $teacherId, $subId]);
            try {
                $retMsg = 'Votre étude de cas "' . $submission['cs_title'] . '" vous a été retournée pour révision.';
                if ($feedback) $retMsg .= ' Commentaire : ' . mb_strimwidth($feedback, 0, 120, '…');
                createNotification($submission['user_id'], 'Travail retourné pour révision', $retMsg, 'warning',
                    url('student/case_studies/view.php?id=' . $submission['case_study_id']));
            } catch (Exception $e) {}
        }
        setFlash('success', 'Travail retourné à l\'étudiant.');
    } else {
        if ($type === 'quiz') {
            $pdo->prepare("UPDATE quiz_attempts SET review_status='graded', teacher_score=?, teacher_feedback=?, graded_by=?, graded_at=NOW() WHERE id=?")
                ->execute([$score, $feedback ?: null, $teacherId, $subId]);
            try {
                $notifMsg = 'Votre quiz "' . $submission['quiz_title'] . '" a été corrigé';
                if ($score !== null) $notifMsg .= ' — Note : ' . $score . '/20';
                $notifMsg .= '.';
                createNotification($submission['user_id'], 'Quiz corrigé par votre enseignant', $notifMsg, 'success',
                    url('student/quiz/take.php?id=' . $submission['quiz_id'] . '&formation_id=' . ($submission['formation_id'] ?? 0) . '&result=' . $subId));
            } catch (Exception $e) {}
        } else {
            $pdo->prepare("UPDATE case_study_submissions SET status='graded', score=?, grade=?, feedback=?, graded_by=?, graded_at=NOW() WHERE id=?")
                ->execute([$score, $grade ?: null, $feedback ?: null, $teacherId, $subId]);
            try {
                $notifMsg = 'Votre étude de cas "' . $submission['cs_title'] . '" a été corrigée';
                if ($score !== null) $notifMsg .= ' — Note : ' . $score . '/' . $submission['max_score'];
                if ($grade) $notifMsg .= ' (' . $grade . ')';
                $notifMsg .= '.';
                createNotification($submission['user_id'], 'Étude de cas corrigée', $notifMsg, 'success',
                    url('student/case_studies/view.php?id=' . $submission['case_study_id']));
            } catch (Exception $e) {}
        }
        setFlash('success', 'Correction enregistrée.');
    }

    auditLog('graded', $type === 'quiz' ? 'quiz_attempts' : 'case_study_submissions', $subId);
    redirect(url('teacher/evaluations/index.php?section=' . ($type === 'quiz' ? 'quiz' : 'case_study')));
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
$pageTitle = match($type) { 'quiz' => 'Corriger le quiz', 'exercise' => 'Corriger l\'exercice', default => 'Corriger l\'étude de cas' };
$sectionUrl = match($type) { 'quiz' => url('teacher/evaluations/index.php?section=quiz'), 'exercise' => url('teacher/evaluations/index.php?section=exercise'), default => url('teacher/evaluations/index.php?section=case_study') };
$subjectTitle = match($type) { 'quiz' => $submission['quiz_title'], 'exercise' => $submission['module_title'], default => $submission['cs_title'] };
$studentName = e($submission['first_name'] . ' ' . $submission['last_name']);

renderHead($pageTitle);
renderSidebar('teacher');
renderTopbar($pageTitle, [
    ['Enseignant', url('teacher/index.php')],
    ['Corrections', $sectionUrl],
    [$subjectTitle, '']
]);
?>
<div class="page-content fade-in" style="max-width:900px">
  <?= renderFlash() ?>

  <!-- En-tête : infos soumission -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap">
      <div style="width:52px;height:52px;border-radius:var(--radius);background:<?= getAvatarColor($submission['first_name'].$submission['last_name']) ?>;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:white;flex-shrink:0">
        <?= getAvatarInitials($submission['first_name'], $submission['last_name']) ?>
      </div>
      <div style="flex:1;min-width:160px">
        <div style="font-size:18px;font-weight:700"><?= $studentName ?></div>
        <div style="font-size:13px;color:var(--text-muted)"><?= e($submission['email']) ?></div>
        <?php if ($submission['formation_title']): ?>
        <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
          <span class="badge badge-secondary"><i class="fas fa-graduation-cap"></i> <?= e($submission['formation_title']) ?></span>
          <?php if ($submission['rncp_code']): ?><span class="badge badge-secondary"><i class="fas fa-certificate" style="color:#8b5cf6"></i> <?= e($submission['rncp_code']) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:160px">
        <div style="font-size:16px;font-weight:700"><?= e($subjectTitle) ?></div>
        <?php if ($submission['at_code']): ?><div style="margin-top:4px"><span class="badge badge-secondary" title="<?= e($submission['at_title']) ?>"><i class="fas fa-layer-group" style="color:#f59e0b"></i> <?= e($submission['at_code']) ?></span><?php endif; ?>
        <?php if ($submission['co_code']): ?><span class="badge badge-secondary" style="margin-left:4px" title="<?= e($submission['co_title']) ?>"><i class="fas fa-star" style="color:#ef4444"></i> <?= e($submission['co_code']) ?></span><?php endif; ?>
        <?php if ($type==='quiz'): ?>
        <div style="margin-top:8px;font-size:13px;color:var(--text-muted)">
          Score automatique : <strong style="color:<?= $submission['passed']?'var(--success)':'var(--danger)' ?>"><?= round($submission['score']) ?>%</strong>
          <span style="color:var(--text-faint)">(seuil <?= $submission['passing_score'] ?>%)</span>
        </div>
        <?php else: ?>
        <div style="margin-top:6px;font-size:12px;color:var(--text-muted)">Soumis le <?= formatDate($submission['submitted_at'],'d/m/Y à H:i') ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Travail soumis -->
  <?php if ($type === 'exercise'): ?>
  <?php
    $exFiles = $submission['files'] ? json_decode($submission['files'], true) : [];
    $extIcons = ['pdf'=>['file-pdf','#ef4444'],'doc'=>['file-word','#3b82f6'],'docx'=>['file-word','#3b82f6'],'xls'=>['file-excel','#22c55e'],'xlsx'=>['file-excel','#22c55e'],'ppt'=>['file-powerpoint','#f97316'],'pptx'=>['file-powerpoint','#f97316'],'zip'=>['file-archive','#8b5cf6'],'txt'=>['file-alt','#6b7280']];
  ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-paperclip" style="color:var(--primary-light)"></i> Production remise</h3>
      <span style="font-size:12px;color:var(--text-muted)">Tentative <?= $submission['attempt_number'] ?> · Soumis le <?= formatDate($submission['submitted_at'],'d/m/Y à H:i') ?></span>
    </div>
    <div class="card-body">
      <?php if ($exFiles): ?>
      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:<?= $submission['text_response'] ? '20px' : '0' ?>">
        <?php foreach ($exFiles as $f):
          $ext = strtolower($f['ext'] ?? pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
          [$ico, $col] = $extIcons[$ext] ?? ['file', '#6b7280'];
          $fileUrl = uploadUrl($f['path']);
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius)">
          <div style="width:36px;height:36px;border-radius:8px;background:<?= $col ?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-<?= $ico ?>" style="color:<?= $col ?>;font-size:16px"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($f['name'] ?? basename($f['path'])) ?></div>
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase"><?= strtoupper($ext) ?></div>
          </div>
          <a href="<?= e($fileUrl) ?>" target="_blank" class="btn btn-secondary btn-sm">
            <i class="fas fa-download"></i> Télécharger
          </a>
          <?php if ($ext === 'pdf'): ?>
          <a href="<?= e($fileUrl) ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-eye"></i> Voir</a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p style="font-size:13px;color:var(--text-muted);font-style:italic">Aucun fichier joint.</p>
      <?php endif; ?>

      <?php if ($submission['text_response']): ?>
      <div>
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--text-muted);letter-spacing:.05em;margin-bottom:8px">Commentaire de l'apprenant</div>
        <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);padding:14px;font-size:13px;line-height:1.6;white-space:pre-wrap"><?= e($submission['text_response']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Formulaire de correction exercice -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-pen-fancy" style="color:var(--primary-light)"></i> Correction</h3></div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px">
          <div>
            <label class="form-label">Note obtenue</label>
            <input type="number" name="score" step="0.5" min="0" class="form-control"
              value="<?= $submission['score'] !== null ? $submission['score'] : '' ?>" placeholder="ex : 14">
          </div>
          <div>
            <label class="form-label">Sur (max)</label>
            <input type="number" name="max_score" step="0.5" min="0" class="form-control"
              value="<?= $submission['max_score'] ?? 20 ?>" placeholder="20">
          </div>
          <div>
            <label class="form-label">Appréciation</label>
            <select name="grade" class="form-control">
              <option value="">— Sans appréciation —</option>
              <?php foreach (['Acquis','En cours d\'acquisition','Non acquis','A','B','C','D','E','TB','B','AB','Insuffisant'] as $g): ?>
              <option value="<?= $g ?>" <?= ($submission['grade'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div style="margin-bottom:20px">
          <label class="form-label">Feedback à l'apprenant</label>
          <textarea name="feedback" class="form-control" rows="4" placeholder="Points forts, axes d'amélioration…" style="resize:vertical"><?= e($submission['feedback'] ?? '') ?></textarea>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button type="submit" name="status" value="graded" class="btn btn-success">
            <i class="fas fa-check-circle"></i> Valider la correction
          </button>
          <button type="submit" name="status" value="under_review" class="btn btn-secondary">
            <i class="fas fa-search"></i> Marquer « En cours »
          </button>
          <button type="submit" name="status" value="returned"
            class="btn btn-danger" onclick="return confirm('Retourner cet exercice à l\'apprenant pour révision ?')">
            <i class="fas fa-undo"></i> Retourner pour révision
          </button>
          <a href="<?= $sectionUrl ?>" class="btn btn-ghost" style="margin-left:auto">Annuler</a>
        </div>
      </form>
    </div>
  </div>

  <?php elseif ($type === 'quiz'): ?>
  <!-- Réponses quiz -->
  <div style="margin-bottom:24px">
    <h2 style="font-size:15px;font-weight:700;margin-bottom:14px"><i class="fas fa-list-alt" style="color:var(--primary-light)"></i> Réponses de l'apprenant</h2>
    <div style="display:flex;flex-direction:column;gap:12px">
      <?php foreach ($answers as $i => $ans): ?>
      <div class="card" style="border-left:4px solid <?= $ans['is_correct'] ? 'var(--success)' : ($ans['is_correct'] === null ? 'var(--warning)' : 'var(--danger)') ?>">
        <div class="card-body" style="padding:14px 18px">
          <div style="display:flex;align-items:flex-start;gap:10px">
            <div style="width:26px;height:26px;border-radius:50%;background:<?= $ans['is_correct'] ? 'rgba(16,185,129,.2)' : ($ans['is_correct']===null?'rgba(245,158,11,.2)':'rgba(239,68,68,.2)') ?>;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;margin-top:1px">
              <?= $i+1 ?>
            </div>
            <div style="flex:1">
              <div style="font-size:13px;font-weight:600;margin-bottom:8px"><?= e($ans['question_text']) ?></div>
              <?php if ($ans['question_type']==='short_answer'||$ans['question_type']==='long_answer'): ?>
              <div style="padding:10px 14px;background:var(--bg-elevated);border-radius:var(--radius);font-size:13px;white-space:pre-wrap"><?= $ans['text_response'] ? e($ans['text_response']) : '<em style="color:var(--text-faint)">Pas de réponse</em>' ?></div>
              <div style="margin-top:4px;font-size:11px;color:var(--warning)"><i class="fas fa-pencil-alt"></i> Réponse ouverte — à corriger manuellement</div>
              <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:5px">
                <?php foreach ($ans['options'] as $opt):
                  $sel = in_array($opt['id'], $ans['selected_ids']);
                  $ok  = (bool)$opt['is_correct'];
                  $bg  = $ok ? 'rgba(16,185,129,.1)' : ($sel && !$ok ? 'rgba(239,68,68,.08)' : 'var(--bg-elevated)');
                  $bo  = $ok ? 'rgba(16,185,129,.35)' : ($sel && !$ok ? 'rgba(239,68,68,.3)' : 'var(--border)');
                ?>
                <div style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:6px;border:1px solid <?= $bo ?>;background:<?= $bg ?>">
                  <?php if ($ok): ?><i class="fas fa-check-circle" style="color:var(--success);font-size:12px"></i>
                  <?php elseif ($sel): ?><i class="fas fa-times-circle" style="color:var(--danger);font-size:12px"></i>
                  <?php else: ?><i class="far fa-circle" style="color:var(--text-faint);font-size:12px"></i><?php endif; ?>
                  <span style="font-size:13px"><?= e($opt['option_text']) ?></span>
                  <?php if ($sel && !$ok): ?><span style="font-size:10px;color:var(--danger);margin-left:auto">Répondu</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
            <div style="flex-shrink:0;text-align:right;font-size:12px;color:var(--text-muted)"><?= (int)$ans['points_earned'] ?>/<?= $ans['points'] ?>pt</div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php else: ?>
  <!-- Travail de l'étudiant (étude de cas) -->
  <div style="margin-bottom:24px">
    <h2 style="font-size:15px;font-weight:700;margin-bottom:14px"><i class="fas fa-file-alt" style="color:var(--primary-light)"></i> Travail soumis par l'apprenant</h2>
    <?php if ($submission['text_response']): ?>
    <div class="card" style="margin-bottom:12px">
      <div class="card-header"><h4 class="card-title"><i class="fas fa-pen"></i> Réponse rédigée</h4></div>
      <div class="card-body" style="font-size:14px;line-height:1.7;white-space:pre-wrap;color:var(--text)"><?= e($submission['text_response']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($submission['file_path']): ?>
    <?php
        $fileData = json_decode($submission['file_path'], true);
        $filePath = is_array($fileData) ? ($fileData['path'] ?? '') : $submission['file_path'];
        $fileName = is_array($fileData) ? ($fileData['name'] ?? basename($filePath)) : basename($filePath);
        $fileOk   = $filePath && $filePath !== 'Array';
    ?>
    <div class="card">
      <div class="card-body" style="display:flex;align-items:center;gap:12px">
        <i class="fas fa-file-alt" style="font-size:24px;color:var(--primary-light)"></i>
        <div style="flex:1">
          <div style="font-size:13px;font-weight:600"><?= e($fileName) ?></div>
          <div style="font-size:11px;color:var(--text-muted)">Document soumis</div>
        </div>
        <?php if ($fileOk): ?>
        <a href="<?= e(uploadUrl($filePath)) ?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Télécharger</a>
        <?php else: ?>
        <span style="font-size:12px;color:var(--danger)"><i class="fas fa-exclamation-circle"></i> Fichier non disponible</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if (!$submission['text_response'] && !$submission['file_path']): ?>
    <div class="card"><div class="card-body" style="color:var(--text-faint);text-align:center"><i class="fas fa-inbox" style="font-size:24px;margin-bottom:8px;display:block"></i>Aucun contenu soumis</div></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Formulaire de correction (quiz / case_study uniquement) -->
  <?php if ($type !== 'exercise'): ?>
  <div class="card" style="border:2px solid rgba(99,102,241,.3)">
    <div class="card-header" style="background:rgba(99,102,241,.06)">
      <h3 class="card-title"><i class="fas fa-pen-fancy" style="color:var(--primary-light)"></i> Correction de l'enseignant</h3>
    </div>
    <div class="card-body">
      <?php
      $existingScore    = $type==='quiz' ? $submission['teacher_score'] : $submission['score'];
      $existingFeedback = $type==='quiz' ? $submission['teacher_feedback'] : $submission['feedback'];
      $existingGrade    = $type==='case_study' ? ($submission['grade'] ?? '') : '';
      ?>
      <form method="POST">
        <?= csrfField() ?>

        <div style="display:grid;grid-template-columns:1fr <?= $type==='case_study'?'1fr':'' ?>;gap:16px;margin-bottom:16px">
          <div>
            <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">
              Note <?= $type==='quiz' ? '(sur 20 — optionnel)' : '(sur '.$submission['max_score'].')' ?>
            </label>
            <input type="number" name="score" class="form-control" step="0.5" min="0" max="<?= $type==='case_study'?$submission['max_score']:20 ?>"
              value="<?= $existingScore !== null ? $existingScore : '' ?>"
              placeholder="Ex : 14.5" style="max-width:160px">
          </div>
          <?php if ($type === 'case_study'): ?>
          <div>
            <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Appréciation / Mention</label>
            <select name="grade" class="form-control" style="max-width:200px">
              <option value="">— Aucune —</option>
              <option value="Acquis"     <?= $existingGrade==='Acquis'?'selected':'' ?>>Acquis</option>
              <option value="Non acquis" <?= $existingGrade==='Non acquis'?'selected':'' ?>>Non acquis</option>
              <option value="En cours"   <?= $existingGrade==='En cours'?'selected':'' ?>>En cours d'acquisition</option>
              <option value="A"          <?= $existingGrade==='A'?'selected':'' ?>>A — Excellent</option>
              <option value="B"          <?= $existingGrade==='B'?'selected':'' ?>>B — Bien</option>
              <option value="C"          <?= $existingGrade==='C'?'selected':'' ?>>C — Satisfaisant</option>
              <option value="D"          <?= $existingGrade==='D'?'selected':'' ?>>D — Insuffisant</option>
            </select>
          </div>
          <?php endif; ?>
        </div>

        <div style="margin-bottom:20px">
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">
            <i class="fas fa-comment-alt" style="color:var(--primary-light)"></i> Feedback à l'apprenant
          </label>
          <textarea name="feedback" class="form-control" rows="5"
            placeholder="Commentaire, points forts, axes d'amélioration..."
            style="resize:vertical"><?= e($existingFeedback ?? '') ?></textarea>
        </div>

        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <button type="submit" name="status" value="graded" class="btn btn-primary">
            <i class="fas fa-check"></i> Valider la correction
          </button>
          <button type="submit" name="status" value="returned" class="btn btn-secondary"
            onclick="return confirm('Retourner le travail à l\'étudiant pour révision ?')">
            <i class="fas fa-undo"></i> Retourner à l'étudiant
          </button>
          <a href="<?= url('teacher/evaluations/index.php?section='.($type==='quiz'?'quiz':'case_study')) ?>" class="btn btn-ghost">
            <i class="fas fa-times"></i> Annuler
          </a>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
