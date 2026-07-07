<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();

// ── Données brutes ───────────────────────────────────────────────────────────
$grants = [];
$cohortGrants = [];
$members = [];

try {
    $grants = $pdo->query("
        SELECT ag.*, u.first_name, u.last_name, u.email
        FROM access_grants ag
        JOIN users u ON ag.user_id = u.id
        WHERE ag.revoked_at IS NULL
        ORDER BY ag.granted_at DESC
    ")->fetchAll();
} catch (\Exception $e) { $grantsErr = $e->getMessage(); }

try {
    $cohortGrants = $pdo->query("
        SELECT cag.*,
               co.name AS cohort_name,
               (SELECT COUNT(*) FROM cohort_members cm WHERE cm.cohort_id = cag.cohort_id) AS member_count,
               gb.first_name AS gb_first, gb.last_name AS gb_last
        FROM cohort_access_grants cag
        JOIN cohorts co ON cag.cohort_id = co.id
        LEFT JOIN users gb ON cag.granted_by = gb.id
        WHERE cag.revoked_at IS NULL
        ORDER BY co.name, cag.granted_at DESC
    ")->fetchAll();
} catch (\Exception $e) { $cgErr = $e->getMessage(); }

try {
    $members = $pdo->query("
        SELECT cm.cohort_id, co.name AS cohort_name, cm.student_id,
               u.first_name, u.last_name, u.email
        FROM cohort_members cm
        JOIN cohorts co ON cm.cohort_id = co.id
        JOIN users u ON cm.student_id = u.id
        ORDER BY co.name, u.last_name, u.first_name
    ")->fetchAll();
} catch (\Exception $e) { $membersErr = $e->getMessage(); }

// Résoudre libellés des scopes
function resolveLabel(PDO $pdo, string $type, int $id): string {
    $map = [
        'rncp_title'    => "SELECT CONCAT(rncp_code,' — ',title) FROM rncp_titles WHERE id=?",
        'activity_type' => "SELECT CONCAT(code,' — ',title) FROM activity_types WHERE id=?",
        'competency'    => "SELECT CONCAT(code,' — ',title) FROM competencies WHERE id=?",
        'sequence'      => "SELECT title FROM sequences WHERE id=?",
        'module'        => "SELECT title FROM modules WHERE id=?",
    ];
    if (!isset($map[$type])) return "($type:#$id)";
    $s = $pdo->prepare($map[$type]);
    $s->execute([$id]);
    return $s->fetchColumn() ?: "(supprimé #$id)";
}

renderHead('Diagnostic Accès');
renderSidebar('pedagogy');
renderTopbar('Diagnostic Accès', [['Pédagogie', url('pedagogy/index.php')], ['Diagnostic', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div style="background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3);border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;font-size:12px;color:var(--text-secondary)">
    <i class="fas fa-exclamation-triangle" style="color:#f87171;margin-right:6px"></i>
    <strong>Page de diagnostic temporaire</strong> — réservée aux comptes pédagogie/admin. Supprimer après usage.
  </div>

  <!-- ── Accès individuels ── -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-user" style="color:#8b5cf6;margin-right:8px"></i>Accès individuels (access_grants)</h3>
      <span class="badge badge-secondary"><?= count($grants) ?></span>
    </div>
    <?php if (isset($grantsErr)): ?>
    <div style="padding:14px 18px;color:#f87171;font-size:12px"><i class="fas fa-times-circle"></i> Erreur SQL : <?= e($grantsErr) ?></div>
    <?php elseif (empty($grants)): ?>
    <div style="padding:14px 18px;font-size:12px;color:var(--text-muted);font-style:italic">Aucun accès individuel actif.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="table" style="font-size:12px">
        <thead><tr><th>ID</th><th>Étudiant</th><th>Niveau</th><th>Périmètre (id)</th><th>Libellé</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($grants as $g): ?>
        <tr>
          <td><?= $g['id'] ?></td>
          <td><?= e($g['first_name'].' '.$g['last_name']) ?><br><span style="color:var(--text-muted)"><?= e($g['email']) ?></span><br><code style="font-size:10px">user_id=<?= $g['user_id'] ?></code></td>
          <td><code><?= e($g['scope_type']) ?></code></td>
          <td><code><?= $g['scope_id'] ?></code></td>
          <td><?= e(resolveLabel($pdo, $g['scope_type'], $g['scope_id'])) ?></td>
          <td><?= $g['granted_at'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Accès cohortes ── -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-users" style="color:#f59e0b;margin-right:8px"></i>Accès cohortes (cohort_access_grants)</h3>
      <span class="badge badge-secondary"><?= count($cohortGrants) ?></span>
    </div>
    <?php if (isset($cgErr)): ?>
    <div style="padding:14px 18px;color:#f87171;font-size:12px"><i class="fas fa-times-circle"></i> Erreur SQL : <?= e($cgErr) ?></div>
    <?php elseif (empty($cohortGrants)): ?>
    <div style="padding:14px 18px;font-size:13px;color:#f87171;font-weight:600">
      <i class="fas fa-exclamation-triangle" style="margin-right:6px"></i>
      Aucun accès cohorte trouvé dans la table cohort_access_grants.
      <div style="font-size:12px;font-weight:400;margin-top:4px;color:var(--text-muted)">Cela signifie qu'aucun accès n'a été accordé à une cohorte, ou que la table est vide.</div>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="table" style="font-size:12px">
        <thead><tr><th>ID grant</th><th>Cohorte</th><th>Membres</th><th>Niveau</th><th>Périmètre (id)</th><th>Libellé</th><th>Accordé par</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($cohortGrants as $cg): ?>
        <tr style="background:rgba(245,158,11,.04)">
          <td><?= $cg['id'] ?></td>
          <td><strong><?= e($cg['cohort_name']) ?></strong><br><code style="font-size:10px">cohort_id=<?= $cg['cohort_id'] ?></code></td>
          <td style="text-align:center"><?= $cg['member_count'] ?></td>
          <td><code><?= e($cg['scope_type']) ?></code></td>
          <td><code><?= $cg['scope_id'] ?></code></td>
          <td><?= e(resolveLabel($pdo, $cg['scope_type'], $cg['scope_id'])) ?></td>
          <td><?= $cg['gb_first'] ? e($cg['gb_first'].' '.$cg['gb_last']) : '—' ?></td>
          <td><?= $cg['granted_at'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Membres des cohortes ── -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-id-card" style="color:#10b981;margin-right:8px"></i>Membres des cohortes (cohort_members)</h3>
      <span class="badge badge-secondary"><?= count($members) ?></span>
    </div>
    <?php if (isset($membersErr)): ?>
    <div style="padding:14px 18px;color:#f87171;font-size:12px"><i class="fas fa-times-circle"></i> Erreur SQL : <?= e($membersErr) ?></div>
    <?php elseif (empty($members)): ?>
    <div style="padding:14px 18px;font-size:12px;color:var(--text-muted);font-style:italic">Aucun membre dans les cohortes.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="table" style="font-size:12px">
        <thead><tr><th>Cohorte</th><th>cohort_id</th><th>Étudiant</th><th>student_id (= user_id)</th><th>Email</th></tr></thead>
        <tbody>
        <?php foreach ($members as $m): ?>
        <tr>
          <td><?= e($m['cohort_name']) ?></td>
          <td><code><?= $m['cohort_id'] ?></code></td>
          <td><?= e($m['first_name'].' '.$m['last_name']) ?></td>
          <td><code style="font-weight:700;color:#10b981"><?= $m['student_id'] ?></code></td>
          <td><?= e($m['email']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Vérification croisée ── -->
  <?php if (!empty($cohortGrants) && !empty($members)): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-crosshairs" style="color:#ef4444;margin-right:8px"></i>Vérification croisée : qui a accès via cohorte ?</h3>
    </div>
    <div style="overflow-x:auto">
      <table class="table" style="font-size:12px">
        <thead>
          <tr>
            <th>Étudiant</th>
            <th>user_id</th>
            <th>Cohorte</th>
            <th>Grant cohorte trouvé ?</th>
            <th>Niveau / Périmètre</th>
          </tr>
        </thead>
        <tbody>
        <?php
        // Pour chaque membre, vérifier s'il y a un grant cohorte correspondant
        foreach ($members as $m):
            // Chercher les grants pour sa cohorte
            $matchingGrants = array_filter($cohortGrants, fn($cg) => $cg['cohort_id'] == $m['cohort_id']);
            if (empty($matchingGrants)):
        ?>
        <tr style="opacity:.45">
          <td><?= e($m['first_name'].' '.$m['last_name']) ?></td>
          <td><code><?= $m['student_id'] ?></code></td>
          <td><?= e($m['cohort_name']) ?></td>
          <td colspan="2" style="color:var(--text-muted);font-style:italic">Aucun accès cohorte pour cette cohorte</td>
        </tr>
        <?php else: foreach ($matchingGrants as $cg): ?>
        <tr style="background:rgba(16,185,129,.04)">
          <td><?= e($m['first_name'].' '.$m['last_name']) ?></td>
          <td><code><?= $m['student_id'] ?></code></td>
          <td><?= e($m['cohort_name']) ?></td>
          <td style="color:var(--success);font-weight:700"><i class="fas fa-check-circle"></i> OUI (grant #<?= $cg['id'] ?>)</td>
          <td><code><?= e($cg['scope_type']) ?></code> → ID <code><?= $cg['scope_id'] ?></code><br><small><?= e(resolveLabel($pdo, $cg['scope_type'], $cg['scope_id'])) ?></small></td>
        </tr>
        <?php endforeach; endif; endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Test hasContentAccess ── -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-vial" style="color:#0ea5e9;margin-right:8px"></i>Test d'accès à une séance spécifique</h3>
    </div>
    <div style="padding:16px 18px">
      <form method="GET" style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap">
        <div>
          <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;color:var(--text-muted)">USER ID de l'étudiant</label>
          <input type="number" name="test_user" value="<?= (int)($_GET['test_user'] ?? 0) ?>" class="form-control" style="width:120px" placeholder="ex: 5">
        </div>
        <div>
          <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;color:var(--text-muted)">ID de la séance (module)</label>
          <input type="number" name="test_module" value="<?= (int)($_GET['test_module'] ?? 0) ?>" class="form-control" style="width:120px" placeholder="ex: 29">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-flask"></i> Tester</button>
      </form>

      <?php
      $testUser   = (int)($_GET['test_user']   ?? 0);
      $testModule = (int)($_GET['test_module'] ?? 0);
      if ($testUser && $testModule):
          // Charger la séance
          $seanceRow = null;
          try {
              $s = $pdo->prepare("
                  SELECT m.*,
                         seq.id AS seq_id, seq.competency_id AS seq_comp_id,
                         c.id AS comp_id, c.activity_type_id AS comp_at_id,
                         at.id AS at_id, at.rncp_title_id AS at_rncp_id,
                         rt.id AS rncp_id
                  FROM modules m
                  LEFT JOIN sequences seq ON m.sequence_id = seq.id
                  LEFT JOIN competencies c ON seq.competency_id = c.id
                  LEFT JOIN activity_types at ON c.activity_type_id = at.id
                  LEFT JOIN rncp_titles rt ON at.rncp_title_id = rt.id
                  WHERE m.id = ?
              ");
              $s->execute([$testModule]);
              $seanceRow = $s->fetch();
          } catch (\Exception $e) { echo '<div style="color:#f87171;padding:10px;font-size:12px">Erreur : '.e($e->getMessage()).'</div>'; }

          if ($seanceRow):
      ?>
      <div style="margin-top:16px;padding:14px;background:var(--bg-elevated);border-radius:var(--radius);font-size:12px">
        <div style="font-weight:700;margin-bottom:8px">Séance chargée : <em><?= e($seanceRow['title'] ?? '?') ?></em></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px">
          <?php
          $chain = [
            'module'        => (int)$testModule,
            'sequence'      => (int)($seanceRow['seq_id'] ?? 0),
            'competency'    => (int)($seanceRow['comp_id'] ?? 0),
            'activity_type' => (int)($seanceRow['at_id'] ?? 0),
            'rncp_title'    => (int)($seanceRow['rncp_id'] ?? 0),
          ];
          foreach ($chain as $type => $id):
              $color = $id ? '#10b981' : '#94a3b8';
          ?>
          <div style="background:<?= $id ? 'rgba(16,185,129,.08)' : 'rgba(100,116,139,.08)' ?>;border:1px solid <?= $id ? 'rgba(16,185,129,.2)' : 'rgba(100,116,139,.15)' ?>;border-radius:4px;padding:6px 8px">
            <div style="font-size:10px;color:var(--text-muted);font-weight:600"><?= $type ?></div>
            <div style="font-weight:700;color:<?= $color ?>"><?= $id ? 'ID '.$id : 'NULL ⚠️' ?></div>
            <?php if ($id): ?><div style="font-size:10px;color:var(--text-muted);margin-top:2px"><?= e(resolveLabel($pdo, $type, $id)) ?></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <?php
        // Tester hasContentAccess manuellement
        $pairs = array_filter(array_map(fn($t,$i) => $i ? [$t,$i] : null, array_keys($chain), array_values($chain)));
        $indivResult = false;
        $cohortResult = false;
        $cohortMatchDetails = [];

        foreach ($pairs as [$type, $id]) {
            try {
                $g = $pdo->prepare("SELECT id FROM access_grants WHERE user_id=? AND scope_type=? AND scope_id=? AND revoked_at IS NULL LIMIT 1");
                $g->execute([$testUser, $type, $id]);
                if ($g->fetch()) { $indivResult = true; break; }
            } catch (\Exception $e) {}
        }
        foreach ($pairs as [$type, $id]) {
            try {
                $g = $pdo->prepare("
                    SELECT cag.id, cag.cohort_id, co.name AS cohort_name, cm.student_id
                    FROM cohort_access_grants cag
                    JOIN cohort_members cm ON cm.cohort_id = cag.cohort_id
                    JOIN cohorts co ON co.id = cag.cohort_id
                    WHERE cm.student_id = ? AND cag.scope_type = ? AND cag.scope_id = ?
                      AND cag.revoked_at IS NULL
                    LIMIT 5
                ");
                $g->execute([$testUser, $type, $id]);
                $rows = $g->fetchAll();
                if ($rows) {
                    $cohortResult = true;
                    foreach ($rows as $r) {
                        $cohortMatchDetails[] = "Grant #".$r['id']." (cohorte: ".$r['cohort_name'].", student_id=".$r['student_id'].") — scope: $type:$id";
                    }
                    break;
                }
            } catch (\Exception $e) {}
        }
        $finalResult = $indivResult || $cohortResult;
        ?>

        <div style="margin-top:14px;padding:12px;background:<?= $finalResult ? 'rgba(16,185,129,.1)' : 'rgba(248,113,113,.1)' ?>;border:1px solid <?= $finalResult ? 'rgba(16,185,129,.3)' : 'rgba(248,113,113,.3)' ?>;border-radius:var(--radius)">
          <div style="font-size:14px;font-weight:800;color:<?= $finalResult ? 'var(--success)' : '#f87171' ?>">
            <?= $finalResult ? '✅ hasContentAccess → TRUE : l\'étudiant PEUT accéder' : '❌ hasContentAccess → FALSE : l\'étudiant N\'a PAS accès' ?>
          </div>
          <div style="font-size:11px;color:var(--text-secondary);margin-top:6px">
            Accès individuel : <?= $indivResult ? '<span style="color:var(--success)">OUI</span>' : 'non' ?> |
            Accès cohorte : <?= $cohortResult ? '<span style="color:var(--success)">OUI</span>' : '<span style="color:#f87171">non</span>' ?>
          </div>
          <?php if ($cohortMatchDetails): ?>
          <ul style="margin:6px 0 0;padding-left:16px;font-size:11px;color:var(--text-muted)">
            <?php foreach ($cohortMatchDetails as $d): ?><li><?= e($d) ?></li><?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <?php if (!$finalResult): ?>
          <div style="margin-top:8px;font-size:11px;color:var(--text-muted)">
            Paires testées :
            <?php foreach ($pairs as [$t,$i]): ?><code><?= $t ?>:<?= $i ?></code> <?php endforeach; ?>
          </div>
          <?php if (!$indivResult && !$cohortResult): ?>
          <div style="margin-top:6px;font-size:11px;color:#fbbf24">
            ⚠️ Vérifiez : (1) l'étudiant est-il dans une cohorte ayant un accès accordé ? (2) le scope_type et scope_id correspondent-ils à la chaîne ci-dessus ?
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php else: ?>
      <div style="margin-top:12px;padding:10px;background:rgba(248,113,113,.08);border-radius:var(--radius);font-size:12px;color:#f87171">Séance (module) avec ID <?= $testModule ?> introuvable.</div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

</div>
<?php renderFooter(); ?>
