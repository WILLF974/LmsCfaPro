<?php
// Redirection vers create.php en mode édition
require_once dirname(dirname(__DIR__)) . '/config/config.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . url('admin/formations/index.php'));
    exit;
}
header('Location: ' . url('admin/formations/create.php?id=' . $id));
exit;
