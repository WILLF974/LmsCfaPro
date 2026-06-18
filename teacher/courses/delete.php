<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireTeacher();
requireCsrf();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// Admin et pédagogie peuvent supprimer toute capsule ; enseignant seulement les siennes
$ownerOnly = !isAdmin() && !isPedagogy();

if ($action === 'lesson') {
    $lessonId = (int)($_POST['lesson_id'] ?? 0);

    $sql = $ownerOnly
        ? 'SELECT file_path, thumbnail FROM lessons WHERE id = ? AND created_by = ?'
        : 'SELECT file_path, thumbnail FROM lessons WHERE id = ?';
    $params = $ownerOnly ? [$lessonId, $userId] : [$lessonId];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lesson = $stmt->fetch();
    if (!$lesson) { setFlash('error', 'Capsule introuvable ou accès refusé.'); redirect(url('teacher/courses/index.php')); }

    // Supprimer les fichiers des ressources
    $res = $pdo->prepare('SELECT file_path FROM lesson_resources WHERE lesson_id = ?');
    $res->execute([$lessonId]);
    foreach ($res->fetchAll() as $r) {
        if ($r['file_path']) @unlink(UPLOADS_PATH . '/' . $r['file_path']);
    }

    // Supprimer le fichier principal et la vignette
    foreach ([$lesson['file_path'], $lesson['thumbnail']] as $fp) {
        if ($fp) @unlink(UPLOADS_PATH . '/' . $fp);
    }

    $pdo->prepare('DELETE FROM lessons WHERE id = ?')->execute([$lessonId]);
    auditLog('lesson_deleted', 'lesson', $lessonId);
    setFlash('success', 'Capsule supprimée.');
    redirect(url('teacher/courses/index.php'));

} elseif ($action === 'resource') {
    $resourceId = (int)($_POST['resource_id'] ?? 0);
    $lessonId   = (int)($_POST['lesson_id'] ?? 0);

    $sql = $ownerOnly
        ? 'SELECT lr.file_path FROM lesson_resources lr JOIN lessons l ON lr.lesson_id = l.id WHERE lr.id = ? AND l.created_by = ?'
        : 'SELECT file_path FROM lesson_resources WHERE id = ?';
    $params = $ownerOnly ? [$resourceId, $userId] : [$resourceId];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $res = $stmt->fetch();
    if (!$res) { setFlash('error', 'Ressource introuvable ou accès refusé.'); redirect(url('teacher/courses/index.php')); }

    if ($res['file_path']) @unlink(UPLOADS_PATH . '/' . $res['file_path']);

    $pdo->prepare('DELETE FROM lesson_resources WHERE id = ?')->execute([$resourceId]);
    auditLog('resource_deleted', 'lesson_resource', $resourceId);
    setFlash('success', 'Ressource supprimée.');
    redirect(url('student/course/view.php?id=' . $lessonId . '&preview=1'));
}

redirect(url('teacher/courses/index.php'));
