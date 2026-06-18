<?php
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Non autorisé']); exit; }

$userId = (int)$_SESSION['user_id'];
$pdo = getDB();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

if ($action === 'mark_read') {
    $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([$userId]);
    echo json_encode(['success'=>true]);
} elseif ($action === 'list') {
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$userId]);
    echo json_encode($stmt->fetchAll());
} elseif ($action === 'unread_count') {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL');
    $stmt->execute([$userId]);
    echo json_encode(['count'=>(int)$stmt->fetchColumn()]);
} else {
    echo json_encode(['error'=>'Action inconnue']);
}
