<?php
require_once __DIR__ . '/config/config.php';

// Accès restreint : admin uniquement en prod, ou via token secret
$token = $_GET['token'] ?? '';
$allowed = isAdmin() || $token === hash('sha256', SECRET_KEY . 'status');

if (!$allowed) {
    http_response_code(403);
    die('Accès refusé. Ajoutez ?token=' . hash('sha256', SECRET_KEY . 'status') . ' à l\'URL.');
}

$pdo = getDB();

// Tests
$checks = [];

// DB connection
try {
    $pdo->query('SELECT 1');
    $checks['database'] = ['ok' => true, 'msg' => 'Connexion OK'];
} catch (Exception $e) {
    $checks['database'] = ['ok' => false, 'msg' => $e->getMessage()];
}

// Session
$checks['session'] = [
    'ok'  => session_status() === PHP_SESSION_ACTIVE,
    'msg' => 'ID: ' . session_id() . ' | user_id: ' . ($_SESSION['user_id'] ?? 'none') . ' | role: ' . ($_SESSION['user_role'] ?? 'none'),
];

// Session writable
$savePath = session_save_path() ?: sys_get_temp_dir();
$checks['session_storage'] = [
    'ok'  => is_writable($savePath),
    'msg' => $savePath,
];

// BASE_URL
$checks['base_url'] = ['ok' => true, 'msg' => BASE_URL];

// ENV
$checks['env'] = ['ok' => true, 'msg' => ENV . ' | PHP ' . PHP_VERSION];

// PHP info
$checks['uploads_path'] = ['ok' => is_writable(UPLOADS_PATH), 'msg' => UPLOADS_PATH];

header('Content-Type: text/plain; charset=utf-8');
echo "=== LMS CFA Pro — Status ===\n\n";
foreach ($checks as $name => $check) {
    $icon = $check['ok'] ? '[OK]' : '[FAIL]';
    echo "$icon  $name: {$check['msg']}\n";
}
echo "\nTimestamp: " . date('Y-m-d H:i:s') . "\n";
