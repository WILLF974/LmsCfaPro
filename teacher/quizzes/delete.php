<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireTeacher();
requireCsrf();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$quizId = (int)($_POST['quiz_id'] ?? 0);

if (!$quizId) { redirect(url('teacher/quizzes/index.php')); }

$ownerOnly = !isAdmin() && !isPedagogy();
$sql    = $ownerOnly ? 'SELECT id, title FROM quizzes WHERE id = ? AND created_by = ?' : 'SELECT id, title FROM quizzes WHERE id = ?';
$params = $ownerOnly ? [$quizId, $userId] : [$quizId];
$stmt   = $pdo->prepare($sql);
$stmt->execute($params);
$quiz   = $stmt->fetch();

if (!$quiz) {
    setFlash('error', 'Quiz introuvable ou accès refusé.');
    redirect(url('teacher/quizzes/index.php'));
}

// Réponses des tentatives (si la table existe)
try {
    $pdo->prepare('DELETE qa FROM quiz_answers qa JOIN quiz_attempts qat ON qa.attempt_id = qat.id WHERE qat.quiz_id = ?')->execute([$quizId]);
} catch (PDOException $e) {}

// Tentatives
$pdo->prepare('DELETE FROM quiz_attempts WHERE quiz_id = ?')->execute([$quizId]);

// Options des questions
$qIds = $pdo->prepare('SELECT id FROM quiz_questions WHERE quiz_id = ?');
$qIds->execute([$quizId]);
foreach ($qIds->fetchAll() as $q) {
    $pdo->prepare('DELETE FROM quiz_options WHERE question_id = ?')->execute([$q['id']]);
}

// Questions puis quiz
$pdo->prepare('DELETE FROM quiz_questions WHERE quiz_id = ?')->execute([$quizId]);
$pdo->prepare('DELETE FROM quizzes WHERE id = ?')->execute([$quizId]);

auditLog('quiz_deleted', 'quiz', $quizId);
setFlash('success', 'Quiz "' . $quiz['title'] . '" supprimé.');
redirect(url('teacher/quizzes/index.php'));
