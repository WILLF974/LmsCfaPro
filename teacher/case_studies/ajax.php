<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireTeacher();

$pdo    = getDB();
$action = $_GET['action'] ?? '';
$data   = [];

switch ($action) {

    case 'modules':
        $fid = (int)($_GET['formation_id'] ?? 0);
        if ($fid) {
            $stmt = $pdo->prepare("SELECT id, title FROM modules WHERE formation_id = ? ORDER BY order_num, title");
            $stmt->execute([$fid]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        break;

    case 'lessons':
        $mid = (int)($_GET['module_id'] ?? 0);
        if ($mid) {
            $stmt = $pdo->prepare("SELECT id, title FROM lessons WHERE module_id = ? ORDER BY order_num, title");
            $stmt->execute([$mid]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        break;

    case 'activity_types':
        $fid = (int)($_GET['formation_id'] ?? 0);
        if ($fid) {
            $stmt = $pdo->prepare("SELECT at.id, at.code, at.title FROM activity_types at INNER JOIN formations f ON f.rncp_title_id = at.rncp_title_id WHERE f.id = ? ORDER BY at.order_num, at.code");
            $stmt->execute([$fid]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        break;

    case 'competencies':
        $atid = (int)($_GET['activity_type_id'] ?? 0);
        if ($atid) {
            $stmt = $pdo->prepare("SELECT id, code, title FROM competencies WHERE activity_type_id = ? ORDER BY order_num, code");
            $stmt->execute([$atid]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        break;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($data, JSON_UNESCAPED_UNICODE);
