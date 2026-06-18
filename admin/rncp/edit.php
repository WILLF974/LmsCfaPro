<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . url('admin/rncp/index.php'));
    exit;
}
header('Location: ' . url('admin/rncp/create.php?id=' . $id));
exit;
