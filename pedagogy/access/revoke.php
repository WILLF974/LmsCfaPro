<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(url('pedagogy/access/index.php')); }
requireCsrf();

$pdo     = getDB();
$grantId = (int)($_POST['grant_id'] ?? 0);
$redir   = trim($_POST['redirect_to'] ?? 'pedagogy/access/index.php');
if (!str_starts_with($redir, 'pedagogy/') && !str_starts_with($redir, 'admin/')) {
    $redir = 'pedagogy/access/index.php';
}

if ($grantId) {
    $pdo->prepare("UPDATE access_grants SET revoked_at=NOW(), revoked_by=? WHERE id=? AND revoked_at IS NULL")
        ->execute([$_SESSION['user_id'], $grantId]);
    auditLog('access_grant_revoked', 'access_grants', $grantId);
    setFlash('success', 'Accès révoqué.');
}

redirect(url($redir));
