<?php
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Non autorisé']); exit; }

$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$action   = $input['action'] ?? '';
$moduleId = (int)($input['module_id'] ?? 0);
$userId   = (int)$_SESSION['user_id'];
$pdo      = getDB();

if ($action === 'complete' && $moduleId) {
    $existing = $pdo->prepare('SELECT status FROM module_progress WHERE user_id=? AND module_id=?');
    $existing->execute([$userId, $moduleId]);
    $prev = $existing->fetch();
    $alreadyCompleted = $prev && $prev['status'] === 'completed';

    try {
        $pdo->prepare("INSERT INTO module_progress (user_id, module_id, status, started_at, completed_at)
                       VALUES (?,?,'completed',NOW(),NOW())
                       ON DUPLICATE KEY UPDATE status='completed', completed_at=COALESCE(completed_at, NOW())")
            ->execute([$userId, $moduleId]);
    } catch (\Exception $e) {
        $pdo->prepare("UPDATE module_progress SET status='completed' WHERE user_id=? AND module_id=?")
            ->execute([$userId, $moduleId]);
    }

    $xpAmt = 0; $newXp = 0; $newLevel = 0; $oldLevel = 0;

    if (!$alreadyCompleted) {
        $mod = $pdo->prepare('SELECT xp_reward, title FROM modules WHERE id=?');
        $mod->execute([$moduleId]);
        $m = $mod->fetch();
        $xpAmt = $m ? max(0, (int)$m['xp_reward']) : XP_LESSON_COMPLETE;

        $oldData = $pdo->prepare('SELECT xp_points FROM users WHERE id=?');
        $oldData->execute([$userId]);
        $oldXp   = (int)$oldData->fetchColumn();
        $oldLevel = getLevel($oldXp);

        if ($xpAmt > 0) {
            addXP($userId, $xpAmt, 'Séance terminée : ' . ($m['title'] ?? '#'.$moduleId), 'module', $moduleId);
        }

        $newXp    = (int)$pdo->prepare('SELECT xp_points FROM users WHERE id=?')->execute([$userId]) ? 0 : 0;
        $newXpRow = $pdo->prepare('SELECT xp_points FROM users WHERE id=?');
        $newXpRow->execute([$userId]);
        $newXp    = (int)$newXpRow->fetchColumn();
        $newLevel = getLevel($newXp);
    }

    echo json_encode([
        'success'          => true,
        'already_completed'=> $alreadyCompleted,
        'xp'               => $xpAmt,
        'total_xp'         => $newXp,
        'level_up'         => $newLevel > $oldLevel,
        'new_level'        => $newLevel,
    ]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètres invalides']);
}
