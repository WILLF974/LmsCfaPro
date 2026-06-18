<?php
require_once dirname(__DIR__) . '/config/config.php';
requirePedagogy();

$pdo    = getDB();
$type   = $_GET['type'] ?? '';
$fId    = (int)($_GET['formation_id'] ?? 0);
$format = $_GET['format'] ?? 'csv'; // csv only for now

$allowed = ['enrollments','progress','attendance','evaluations','audit_log','users'];
if (!in_array($type, $allowed)) {
    http_response_code(400);
    die('Type d\'export non reconnu.');
}

// Préparer les données selon le type
switch ($type) {
    case 'enrollments':
        $where  = '1=1';
        $params = [];
        if ($fId) { $where = 'e.formation_id = ?'; $params[] = $fId; }
        $stmt = $pdo->prepare("
            SELECT u.last_name, u.first_name, u.email, f.title as formation, r.rncp_code,
                   e.status, e.progress_percent, e.enrolled_at, e.completion_date, e.funding_type
            FROM enrollments e
            JOIN users u ON e.user_id = u.id
            JOIN formations f ON e.formation_id = f.id
            LEFT JOIN rncp_titles r ON f.rncp_title_id = r.id
            WHERE $where
            ORDER BY f.title, u.last_name
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = ['Nom','Prénom','Email','Formation','Code RNCP','Statut','Progression (%)','Date inscription','Date complétion','Financement'];
        $filename = 'inscriptions';
        break;

    case 'progress':
        $where  = '1=1';
        $params = [];
        if ($fId) { $where = 'm.formation_id = ?'; $params[] = $fId; }
        $stmt = $pdo->prepare("
            SELECT u.last_name, u.first_name, u.email, f.title as formation, mo.title as module,
                   l.title as capsule, l.content_type, lp.status, lp.started_at, lp.completed_at,
                   lp.time_spent_seconds, lp.score
            FROM lesson_progress lp
            JOIN users u ON lp.user_id = u.id
            JOIN lessons l ON lp.lesson_id = l.id
            JOIN modules mo ON l.module_id = mo.id
            JOIN formations f ON mo.formation_id = f.id
            WHERE $where
            ORDER BY f.title, u.last_name, mo.order_num, l.order_num
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = ['Nom','Prénom','Email','Formation','Module','Capsule','Type','Statut','Début','Fin','Durée (s)','Score'];
        $filename = 'progression';
        break;

    case 'attendance':
        $stmt = $pdo->prepare("
            SELECT u.last_name, u.first_name, u.email, fs.title as session_title, fs.session_date,
                   fs.start_time, fs.end_time, a.status, a.notes, f.title as formation
            FROM attendance a
            JOIN users u ON a.user_id = u.id
            JOIN formation_sessions fs ON a.session_id = fs.id
            JOIN formations f ON fs.formation_id = f.id
            " . ($fId ? 'WHERE fs.formation_id = ?' : '') . "
            ORDER BY fs.session_date DESC, u.last_name
        ");
        $stmt->execute($fId ? [$fId] : []);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = ['Nom','Prénom','Email','Session','Date','Début','Fin','Statut','Notes','Formation'];
        $filename = 'presences';
        break;

    case 'evaluations':
        $stmt = $pdo->prepare("
            SELECT u.last_name, u.first_name, u.email, q.title as quiz,
                   q.quiz_type, qa.attempt_number, qa.score, qa.passed, qa.time_spent_seconds,
                   qa.started_at, qa.completed_at, f.title as formation
            FROM quiz_attempts qa
            JOIN users u ON qa.user_id = u.id
            JOIN quizzes q ON qa.quiz_id = q.id
            LEFT JOIN formations f ON q.formation_id = f.id
            WHERE qa.status = 'completed'
            " . ($fId ? 'AND q.formation_id = ?' : '') . "
            ORDER BY qa.completed_at DESC
        ");
        $stmt->execute($fId ? [$fId] : []);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = ['Nom','Prénom','Email','Quiz','Type','Tentative','Score (%)','Réussi','Durée (s)','Début','Fin','Formation'];
        $filename = 'evaluations';
        break;

    case 'audit_log':
        requireAdmin();
        $stmt = $pdo->query("
            SELECT al.created_at, u.last_name, u.first_name, u.email, al.action,
                   al.entity_type, al.entity_id, al.ip_address
            FROM audit_log al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT 5000
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = ['Date','Nom','Prénom','Email','Action','Entité','ID','IP'];
        $filename = 'audit_log';
        break;

    case 'users':
        requireAdmin();
        $stmt = $pdo->query("
            SELECT u.last_name, u.first_name, u.email, u.role, u.status,
                   u.xp_points, u.level, u.created_at, u.last_login,
                   (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.status = 'active') as active_formations
            FROM users u
            ORDER BY u.role, u.last_name
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = ['Nom','Prénom','Email','Rôle','Statut','XP','Niveau','Créé le','Dernière connexion','Formations actives'];
        $filename = 'utilisateurs';
        break;

    default:
        $rows = [];
        $headers = [];
        $filename = 'export';
}

// Audit log de l'export
auditLog('export_' . $type, 'export', 0);

// Sortie CSV
$date = date('Y-m-d');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '_' . $date . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
// BOM pour Excel (UTF-8)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// En-têtes
fputcsv($output, $headers, ';');

// Données
foreach ($rows as $row) {
    $values = array_values($row);
    // Convertir les valeurs booléennes
    $values = array_map(function($v) {
        if ($v === null) return '';
        if ($v === 1 || $v === '1') return 'Oui';
        if ($v === 0 || $v === '0') return 'Non';
        return $v;
    }, $values);
    fputcsv($output, $values, ';');
}

fclose($output);
exit;
