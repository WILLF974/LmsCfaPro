<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();

// ── POST : fusion ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $compId      = (int)($_POST['comp_id']      ?? 0);
    $canonicalId = (int)($_POST['canonical_id'] ?? 0);

    if (!$compId || !$canonicalId) {
        setFlash('error', 'Données manquantes.');
        redirect(url('pedagogy/sequences/merge.php'));
    }

    // Vérifier que le canonique appartient bien à cette compétence
    $chk = $pdo->prepare("SELECT id, title FROM sequences WHERE id = ? AND competency_id = ?");
    $chk->execute([$canonicalId, $compId]);
    $canonical = $chk->fetch();
    if (!$canonical) {
        setFlash('error', 'Séquence canonique introuvable pour cette compétence.');
        redirect(url('pedagogy/sequences/merge.php'));
    }

    // Récupérer les séquences à fusionner (toutes sauf la canonique)
    $sources = $pdo->prepare("SELECT id FROM sequences WHERE competency_id = ? AND id != ?");
    $sources->execute([$compId, $canonicalId]);
    $sourceIds = array_column($sources->fetchAll(), 'id');

    if (empty($sourceIds)) {
        setFlash('error', 'Aucune séquence à fusionner.');
        redirect(url('pedagogy/sequences/merge.php'));
    }

    $pdo->beginTransaction();
    try {
        $movedModules = 0;
        foreach ($sourceIds as $srcId) {
            $upd = $pdo->prepare("UPDATE modules SET sequence_id = ? WHERE sequence_id = ?");
            $upd->execute([$canonicalId, $srcId]);
            $movedModules += (int)$pdo->query("SELECT ROW_COUNT()")->fetchColumn();
            $pdo->prepare("DELETE FROM sequences WHERE id = ?")->execute([$srcId]);
        }
        $pdo->commit();
        $merged = count($sourceIds);
        setFlash('success', "Fusion terminée : {$merged} séquence(s) fusionnée(s) dans « {$canonical['title']} », {$movedModules} séance(s) réassignée(s).");
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('error', 'Erreur lors de la fusion : ' . $e->getMessage());
    }
    redirect(url('pedagogy/sequences/merge.php'));
}

// ── Compétences ayant plusieurs séquences ────────────────────────────────────
$dupes = $pdo->query("
    SELECT c.id AS comp_id, c.code AS comp_code, c.title AS comp_title,
           at.code AS at_code, at.title AS at_title,
           rt.rncp_code,
           COUNT(seq.id) AS seq_count
    FROM competencies c
    JOIN sequences seq ON seq.competency_id = c.id
    LEFT JOIN activity_types at ON c.activity_type_id = at.id
    LEFT JOIN rncp_titles rt ON at.rncp_title_id = rt.id
    GROUP BY c.id
    HAVING seq_count > 1
    ORDER BY rt.rncp_code, at.order_num, c.order_num
")->fetchAll();

// Charger les séquences et leurs stats pour chaque compétence en doublon
foreach ($dupes as &$d) {
    $s = $pdo->prepare("
        SELECT seq.id, seq.title, seq.order_num, seq.created_at,
               u.first_name, u.last_name,
               COUNT(DISTINCT m.id)  AS nb_seances
        FROM sequences seq
        LEFT JOIN users u ON seq.created_by = u.id
        LEFT JOIN modules m ON m.sequence_id = seq.id
        WHERE seq.competency_id = ?
        GROUP BY seq.id
        ORDER BY nb_seances DESC, seq.created_at ASC
    ");
    $s->execute([$d['comp_id']]);
    $d['sequences'] = $s->fetchAll();
}
unset($d);

renderHead('Fusion des séquences en doublon');
renderSidebar('pedagogy');
renderTopbar('Fusion des séquences en doublon', [
    ['Pédagogie', url('pedagogy/index.php')],
    ['Séquences', url('pedagogy/sequences/index.php')],
    ['Fusion doublons', ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- En-tête -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px;display:flex;align-items:center;gap:14px">
      <div style="width:42px;height:42px;border-radius:10px;background:rgba(245,158,11,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="fas fa-compress-arrows-alt" style="color:#f59e0b;font-size:18px"></i>
      </div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:15px;color:var(--text-primary)">Fusion des séquences en doublon</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
          <?php if (empty($dupes)): ?>
            Aucun doublon détecté — toutes les compétences ont au plus une séquence.
          <?php else: ?>
            <?= count($dupes) ?> compétence<?= count($dupes) > 1 ? 's ont' : ' a' ?> plusieurs séquences.
            Choisissez la séquence à conserver (canonique) ; les séances des autres seront transférées puis elles seront supprimées.
          <?php endif; ?>
        </div>
      </div>
      <a href="<?= url('pedagogy/sequences/index.php') ?>" class="btn btn-ghost btn-sm">← Retour séquences</a>
    </div>
  </div>

  <?php if (empty($dupes)): ?>
  <div class="card">
    <div class="card-body" style="padding:60px;text-align:center;color:var(--text-muted)">
      <i class="fas fa-check-circle" style="font-size:48px;color:#34d399;opacity:.6;display:block;margin-bottom:14px"></i>
      <div style="font-weight:600;font-size:16px;color:var(--text-primary);margin-bottom:6px">Aucun doublon détecté</div>
      <div style="font-size:13px">Chaque compétence dispose d'au plus une séquence.</div>
    </div>
  </div>

  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:16px">
    <?php foreach ($dupes as $d): ?>
    <div class="card">
      <!-- En-tête compétence -->
      <div style="padding:14px 20px;border-bottom:1px solid var(--border-color);background:rgba(0,0,0,.1);border-radius:var(--radius-lg) var(--radius-lg) 0 0">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span style="font-size:11px;background:rgba(139,92,246,.15);color:#a78bfa;padding:3px 9px;border-radius:20px;font-weight:700">
            <i class="fas fa-certificate" style="font-size:10px;margin-right:4px"></i><?= e($d['rncp_code']) ?>
          </span>
          <span style="font-size:11px;color:var(--text-muted)">
            <i class="fas fa-layer-group" style="color:#f59e0b;margin-right:4px;font-size:10px"></i><?= e($d['at_code']) ?> — <?= e($d['at_title']) ?>
          </span>
          <span style="font-size:11px;font-weight:700;color:var(--text-primary)">
            <i class="fas fa-bullseye" style="color:#ef4444;margin-right:5px;font-size:10px"></i><?= e($d['comp_code']) ?> — <?= e($d['comp_title']) ?>
          </span>
          <span style="margin-left:auto;font-size:11px;background:rgba(245,158,11,.15);color:#f59e0b;padding:3px 9px;border-radius:20px;font-weight:700">
            <?= $d['seq_count'] ?> séquences
          </span>
        </div>
      </div>

      <!-- Tableau des séquences -->
      <div class="card-body" style="padding:0">
        <form method="POST">
          <input type="hidden" name="comp_id" value="<?= $d['comp_id'] ?>">
          <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
              <tr style="border-bottom:1px solid var(--border-color)">
                <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:left;width:40px">
                  <span title="Séquence à conserver (canonique)">Garder</span>
                </th>
                <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:left">Séquence</th>
                <th style="padding:10px 12px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:center">Séances</th>
                <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:left">Créé par</th>
                <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;text-align:left">Date</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($d['sequences'] as $i => $s): ?>
            <tr style="border-bottom:1px solid var(--border-color)<?= $i===0 ? ';background:rgba(16,185,129,.04)' : '' ?>">
              <td style="padding:12px 16px;text-align:center">
                <input type="radio" name="canonical_id" value="<?= $s['id'] ?>" <?= $i===0?'checked':'' ?>
                       style="width:16px;height:16px;accent-color:#10b981;cursor:pointer"
                       title="Sélectionner comme séquence canonique (à conserver)">
              </td>
              <td style="padding:12px 16px">
                <div style="font-weight:600;color:var(--text-primary)"><?= e($s['title']) ?></div>
                <?php if ($i === 0): ?>
                <div style="font-size:10px;color:#10b981;margin-top:2px"><i class="fas fa-star" style="font-size:9px;margin-right:3px"></i>suggérée (la plus de contenu)</div>
                <?php endif; ?>
              </td>
              <td style="padding:12px 12px;text-align:center">
                <span style="font-weight:700;color:<?= $s['nb_seances']>0?'#10b981':'var(--text-faint)' ?>">
                  <?= $s['nb_seances'] ?>
                </span>
              </td>
              <td style="padding:12px 16px;font-size:12px;color:var(--text-secondary)">
                <?= $s['first_name'] ? e($s['first_name'].' '.$s['last_name']) : '<span style="color:var(--text-faint)">Système</span>' ?>
              </td>
              <td style="padding:12px 16px;font-size:11px;color:var(--text-muted)">
                <?= date('d/m/Y', strtotime($s['created_at'])) ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <!-- Action fusionner -->
          <div style="padding:14px 20px;border-top:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div style="font-size:12px;color:var(--text-muted)">
              <i class="fas fa-info-circle" style="color:#f59e0b;margin-right:5px"></i>
              Les séances des séquences non cochées seront transférées dans la séquence sélectionnée, puis celles-ci seront supprimées.
            </div>
            <button type="submit" class="btn btn-sm"
                    style="background:#f59e0b;color:#1a1a2e;font-weight:700;flex-shrink:0"
                    onclick="return confirm('Fusionner les séquences de cette compétence ? Les séquences non retenues seront définitivement supprimées après transfert de leurs séances.')">
              <i class="fas fa-compress-arrows-alt"></i> Fusionner
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
