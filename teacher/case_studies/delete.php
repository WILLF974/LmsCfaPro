<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();
requireCsrf();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$id     = (int)($_POST['id'] ?? 0);

if (!$id) { setFlash('error', 'ID invalide.'); redirect(url('teacher/case_studies/index.php')); }

$cs = $pdo->prepare('SELECT * FROM case_studies WHERE id = ?');
$cs->execute([$id]);
$cs = $cs->fetch();

if (!$cs) { setFlash('error', 'Étude de cas introuvable.'); redirect(url('teacher/case_studies/index.php')); }

if (!isAdmin() && !isPedagogy() && $cs['created_by'] !== $userId) {
    setFlash('error', 'Accès refusé.');
    redirect(url('teacher/case_studies/index.php'));
}

$pdo->prepare('DELETE FROM case_studies WHERE id = ?')->execute([$id]);
auditLog('case_study_deleted', 'case_study', $id);
setFlash('success', 'Étude de cas « ' . $cs['title'] . ' » supprimée.');
redirect(url('teacher/case_studies/index.php'));
