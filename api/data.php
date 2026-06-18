<?php
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Non autorisé']); exit; }

$pdo = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'competencies_by_module':
        $moduleId = (int)($_GET['module_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT c.id, c.code, c.title FROM competencies c
            JOIN activity_types at ON c.activity_type_id=at.id
            JOIN modules m ON m.formation_id = (SELECT formation_id FROM modules WHERE id=?)
            AND at.rncp_title_id = (SELECT r.id FROM rncp_titles r JOIN formations f ON f.rncp_title_id=r.id JOIN modules mo ON mo.formation_id=f.id WHERE mo.id=?)
            ORDER BY c.code
        ");
        $stmt->execute([$moduleId, $moduleId]);
        echo json_encode($stmt->fetchAll());
        break;

    case 'lessons_by_module':
        $moduleId = (int)($_GET['module_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, title, content_type, duration_minutes FROM lessons WHERE module_id=? ORDER BY order_num');
        $stmt->execute([$moduleId]);
        echo json_encode($stmt->fetchAll());
        break;

    case 'modules_by_formation':
        $formationId = (int)($_GET['formation_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, title, order_num FROM modules WHERE formation_id=? ORDER BY order_num');
        $stmt->execute([$formationId]);
        echo json_encode($stmt->fetchAll());
        break;

    case 'activity_types_by_formation':
        $formationId = (int)($_GET['formation_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT at.id, at.code, at.title FROM activity_types at JOIN formations f ON at.rncp_title_id = f.rncp_title_id WHERE f.id = ? ORDER BY at.order_num');
        $stmt->execute([$formationId]);
        echo json_encode($stmt->fetchAll());
        break;

    case 'competencies_by_activity_type':
        $atId = (int)($_GET['activity_type_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, code, title FROM competencies WHERE activity_type_id = ? ORDER BY order_num');
        $stmt->execute([$atId]);
        echo json_encode($stmt->fetchAll());
        break;

    case 'formation_stats':
        $formationId = (int)($_GET['formation_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT
              (SELECT COUNT(*) FROM enrollments WHERE formation_id=? AND status='active') as active_students,
              (SELECT COUNT(*) FROM enrollments WHERE formation_id=? AND status='completed') as completed_students,
              (SELECT AVG(progress_percent) FROM enrollments WHERE formation_id=? AND status='active') as avg_progress,
              (SELECT COUNT(*) FROM modules WHERE formation_id=?) as module_count,
              (SELECT COUNT(*) FROM lessons l JOIN modules m ON l.module_id=m.id WHERE m.formation_id=?) as lesson_count
        ");
        $stmt->execute([$formationId,$formationId,$formationId,$formationId,$formationId]);
        echo json_encode($stmt->fetch());
        break;

    case 'user_search':
        requireTeacher();
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode([]); break; }
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role FROM users WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?) AND status='active' LIMIT 10");
        $like = "%$q%";
        $stmt->execute([$like,$like,$like]);
        echo json_encode($stmt->fetchAll());
        break;

    case 'avatar':
        // Generate SVG avatar with initials
        $name = strtoupper(trim($_GET['name'] ?? 'AB'));
        $colors = ['#6366f1','#8b5cf6','#0ea5e9','#10b981','#f59e0b','#ef4444','#ec4899','#14b8a6'];
        $color = $colors[abs(crc32($name)) % count($colors)];
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=86400');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80">';
        echo '<rect width="80" height="80" fill="'.$color.'" rx="40"/>';
        echo '<text x="40" y="52" font-family="Inter,system-ui,sans-serif" font-size="28" font-weight="700" fill="white" text-anchor="middle">'.htmlspecialchars(mb_substr($name,0,2)).'</text>';
        echo '</svg>';
        exit;

    default:
        echo json_encode(['error'=>'Action inconnue']);
}
