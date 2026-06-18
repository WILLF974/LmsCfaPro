<?php
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Non autorisé']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';
$lessonId = (int)($input['lesson_id'] ?? 0);
$formationId = (int)($input['formation_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];
$pdo = getDB();

if ($action === 'start') {
    $stmt = $pdo->prepare('SELECT * FROM lesson_progress WHERE user_id=? AND lesson_id=?');
    $stmt->execute([$userId, $lessonId]);
    if (!$stmt->fetch()) {
        $pdo->prepare('INSERT INTO lesson_progress (user_id,lesson_id,status,started_at) VALUES (?,?,"in_progress",NOW())')->execute([$userId,$lessonId]);
    }
    echo json_encode(['success'=>true]);

} elseif ($action === 'complete') {
    $score = isset($input['score']) ? (int)$input['score'] : null;

    // Vérifier si déjà complétée AVANT de mettre à jour
    $existing = $pdo->prepare('SELECT status FROM lesson_progress WHERE user_id=? AND lesson_id=?');
    $existing->execute([$userId, $lessonId]);
    $prev = $existing->fetch();
    $alreadyCompleted = $prev && $prev['status'] === 'completed';

    // Mettre à jour ou insérer la progression
    $pdo->prepare("INSERT INTO lesson_progress (user_id,lesson_id,status,started_at,completed_at) VALUES (?,?,'completed',NOW(),NOW())
        ON DUPLICATE KEY UPDATE status='completed', completed_at=COALESCE(completed_at, NOW())")->execute([$userId, $lessonId]);

    $xpAmt = 0; $newXp = 0; $newLevel = 0; $oldLevel = 0; $badge = null;

    // N'accorder les XP que si c'est la PREMIÈRE complétion
    if (!$alreadyCompleted) {
        $lesson = $pdo->prepare('SELECT xp_reward FROM lessons WHERE id=?');
        $lesson->execute([$lessonId]);
        $ls = $lesson->fetch();
        $xpAmt = $ls ? (int)$ls['xp_reward'] : XP_LESSON_COMPLETE;

        $oldUser = $pdo->prepare('SELECT xp_points, level FROM users WHERE id=?');
        $oldUser->execute([$userId]);
        $oldData = $oldUser->fetch();
        $oldLevel = getLevel((int)$oldData['xp_points']);

        addXP($userId, $xpAmt, 'Capsule terminée: #'.$lessonId, 'lesson', $lessonId);

        $newUser = $pdo->prepare('SELECT xp_points FROM users WHERE id=?');
        $newUser->execute([$userId]);
        $newXp = (int)$newUser->fetchColumn();
        $newLevel = getLevel($newXp);

        $newBadges = $pdo->prepare("SELECT b.* FROM user_badges ub JOIN badges b ON ub.badge_id=b.id WHERE ub.user_id=? AND ub.earned_at > NOW() - INTERVAL 5 SECOND");
        $newBadges->execute([$userId]);
        $badge = $newBadges->fetch() ?: null;
    }

    // Mise à jour progression formation dans tous les cas
    if ($formationId) updateEnrollmentProgress($userId, $formationId);

    echo json_encode([
        'success'          => true,
        'already_completed'=> $alreadyCompleted,
        'xp'               => $xpAmt,
        'total_xp'         => $newXp,
        'level_up'         => $newLevel > $oldLevel,
        'new_level'        => $newLevel,
        'badge'            => $badge,
    ]);

} elseif ($action === 'position') {
    // Save video position
    $position = (int)($input['position'] ?? 0);
    $pdo->prepare("UPDATE lesson_progress SET last_position=? WHERE user_id=? AND lesson_id=?")->execute([$position,$userId,$lessonId]);
    echo json_encode(['success'=>true]);

} else {
    echo json_encode(['error'=>'Action inconnue']);
}
