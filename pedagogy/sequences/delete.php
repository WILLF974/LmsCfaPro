<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('pedagogy/sequences/index.php'));
}

$pdo = getDB();
$id  = (int)($_POST['id'] ?? 0);

$seq = $pdo->prepare("SELECT id, title FROM sequences WHERE id = ?");
$seq->execute([$id]);
$row = $seq->fetch();

if (!$row) {
    setFlash('error', 'Séquence introuvable.');
    redirect(url('pedagogy/sequences/index.php'));
}

// Vérifier qu'il n'y a pas de séances rattachées
$cnt = $pdo->prepare("SELECT COUNT(*) FROM modules WHERE sequence_id = ?");
$cnt->execute([$id]);
if ((int)$cnt->fetchColumn() > 0) {
    setFlash('error', "Impossible de supprimer cette séquence : elle contient des séances. Supprimez d'abord les séances.");
    redirect(url('pedagogy/sequences/index.php'));
}

$pdo->prepare("DELETE FROM sequences WHERE id = ?")->execute([$id]);
setFlash('success', 'Séquence « ' . $row['title'] . ' » supprimée.');
redirect(url('pedagogy/sequences/index.php'));
