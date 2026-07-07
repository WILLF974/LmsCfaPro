<?php
require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/layout.php';
requirePedagogy();

$pdo      = getDB();
$cohortId = (int)($_GET['id'] ?? 0);
if (!$cohortId) { redirect(url('pedagogy/cohorts/index.php')); }

$cStmt = $pdo->prepare("
    SELECT c.*, rt.rncp_code, rt.title as rncp_title, rt.level as rncp_level
    FROM cohorts c
    LEFT JOIN rncp_titles rt ON c.rncp_title_id = rt.id
    WHERE c.id = ?
");
$cStmt->execute([$cohortId]);
$cohort = $cStmt->fetch();
if (!$cohort) { setFlash('error', 'Cohorte introuvable.'); redirect(url('pedagogy/cohorts/index.php')); }

// ── Actions POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_members') {
        $ids = array_map('intval', (array)($_POST['student_ids'] ?? []));
        $addedBy = (int)$_SESSION['user_id'];
        $added = 0;
        foreach ($ids as $sid) {
            if (!$sid) continue;
            try {
                $pdo->prepare("INSERT IGNORE INTO cohort_members (cohort_id, student_id, added_by) VALUES (?,?,?)")
                    ->execute([$cohortId, $sid, $addedBy]);
                $added += $pdo->prepare("SELECT ROW_COUNT()")->execute() ? 1 : 0;
            } catch (PDOException $e) {}
        }
        // Compter combien ont vraiment été ajoutés
        $added = count($ids);
        auditLog('cohort_members_added', 'cohort', $cohortId, [], ['count' => $added]);
        setFlash('success', $added . ' apprenant(s) ajouté(s) à la cohorte.');

    } elseif ($action === 'remove_member') {
        $sid = (int)($_POST['student_id'] ?? 0);
        if ($sid) {
            $pdo->prepare("DELETE FROM cohort_members WHERE cohort_id=? AND student_id=?")->execute([$cohortId, $sid]);
            auditLog('cohort_member_removed', 'cohort', $cohortId, [], ['student_id' => $sid]);
            setFlash('success', 'Apprenant retiré de la cohorte.');
        }

    } elseif ($action === 'toggle_exclude_member') {
        $sid = (int)($_POST['student_id'] ?? 0);
        if ($sid) {
            $pdo->prepare("UPDATE cohort_members SET excluded_from_stats = 1 - excluded_from_stats WHERE cohort_id=? AND student_id=?")
                ->execute([$cohortId, $sid]);
            $newState = $pdo->prepare("SELECT excluded_from_stats FROM cohort_members WHERE cohort_id=? AND student_id=?");
            $newState->execute([$cohortId, $sid]);
            $isNowExcluded = (bool)$newState->fetchColumn();
            auditLog('cohort_member_toggle_exclude', 'cohort', $cohortId, [], ['student_id' => $sid, 'excluded' => $isNowExcluded]);
            setFlash('success', $isNowExcluded
                ? 'Apprenant exclu des statistiques de la cohorte.'
                : 'Apprenant réintégré dans les statistiques.');
        }

    } elseif ($action === 'grant_cohort_access') {
        $scopeType = trim($_POST['scope_type'] ?? '');
        $scopeId   = (int)($_POST['scope_id']   ?? 0);
        $notes     = mb_substr(trim($_POST['notes'] ?? ''), 0, 255);
        $validTypes = ['rncp_title','activity_type','competency','sequence','module'];
        if (in_array($scopeType, $validTypes) && $scopeId) {
            try {
                $pdo->prepare("
                    INSERT INTO cohort_access_grants (cohort_id, scope_type, scope_id, granted_by, notes)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE revoked_at=NULL, revoked_by=NULL, granted_by=?, granted_at=NOW(), notes=?
                ")->execute([$cohortId, $scopeType, $scopeId, (int)$_SESSION['user_id'], $notes ?: null,
                              (int)$_SESSION['user_id'], $notes ?: null]);
                auditLog('cohort_access_grant_created', 'cohort', $cohortId, [], ['scope_type'=>$scopeType,'scope_id'=>$scopeId]);
                setFlash('success', 'Accès accordé à la cohorte.');
            } catch (\PDOException $e) {
                setFlash('error', 'Erreur : ' . $e->getMessage());
            }
        } else {
            setFlash('error', 'Périmètre invalide.');
        }

    } elseif ($action === 'revoke_cohort_grant') {
        $grantId = (int)($_POST['grant_id'] ?? 0);
        if ($grantId) {
            $pdo->prepare("UPDATE cohort_access_grants SET revoked_at=NOW(), revoked_by=? WHERE id=? AND cohort_id=? AND revoked_at IS NULL")
                ->execute([(int)$_SESSION['user_id'], $grantId, $cohortId]);
            auditLog('cohort_access_grant_revoked', 'cohort', $cohortId, [], ['grant_id'=>$grantId]);
            setFlash('success', 'Accès révoqué.');
        }

    } elseif ($action === 'notify_all') {
        $msg = trim($_POST['message'] ?? '');
        $title = trim($_POST['notif_title'] ?? 'Message de votre cohorte');
        if ($msg) {
            $mStmt = $pdo->prepare("SELECT student_id FROM cohort_members WHERE cohort_id=?");
            $mStmt->execute([$cohortId]);
            $count = 0;
            foreach ($mStmt->fetchAll() as $m) {
                createNotification((int)$m['student_id'], $title, $msg, 'info');
                $count++;
            }
            auditLog('cohort_notified', 'cohort', $cohortId, [], ['count' => $count]);
            setFlash('success', $count . ' notification(s) envoyée(s).');
        } else {
            setFlash('error', 'Le message est vide.');
        }
    }

    redirect(url('pedagogy/cohorts/view.php?id='.$cohortId));
}

// ── Membres de la cohorte ────────────────────────────────────
$members = $pdo->prepare("
    SELECT u.id, u.first_name, u.last_name, u.email, u.avatar, u.status,
           u.xp_points, u.level, u.last_activity,
           cm.joined_at, cm.excluded_from_stats,
           (SELECT COUNT(*) FROM module_progress mp WHERE mp.user_id=u.id AND mp.status='completed') as modules_done,
           (SELECT COUNT(*) FROM module_progress mp WHERE mp.user_id=u.id)                           as modules_total,
           (SELECT COUNT(*) FROM quiz_attempts  qa WHERE qa.user_id=u.id AND qa.passed=1)            as quizzes_passed
    FROM cohort_members cm
    JOIN users u ON cm.student_id = u.id
    WHERE cm.cohort_id = ?
    ORDER BY cm.excluded_from_stats ASC, u.last_name, u.first_name
");
$members->execute([$cohortId]);
$members = $members->fetchAll();

// Stats agrégées (membres actifs uniquement — exclus ignorés)
$totalMembers  = count($members);
$countExcluded = 0;
$avgPct        = 0;
$activeRecent  = 0;
$totalModsDone = 0;
$totalMods     = 0;
$countForStats = 0;
foreach ($members as $m) {
    if ($m['excluded_from_stats']) { $countExcluded++; continue; }
    $countForStats++;
    $mt = (int)$m['modules_total'];
    $md = (int)$m['modules_done'];
    $totalModsDone += $md;
    $totalMods     += $mt;
    if ($mt > 0) $avgPct += round($md / $mt * 100);
    if ($m['last_activity'] && strtotime($m['last_activity']) > strtotime('-7 days')) $activeRecent++;
}
$avgPct = $countForStats > 0 ? round($avgPct / $countForStats) : 0;

// Étudiants éligibles (non membres, actifs) pour le modal d'ajout
$existingIds = array_column($members, 'id');
$notMembersWhere = $existingIds
    ? "WHERE u.role='student' AND u.status='active' AND u.id NOT IN (" . implode(',', $existingIds) . ")"
    : "WHERE u.role='student' AND u.status='active'";
$candidates = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email
    FROM users u
    $notMembersWhere
    ORDER BY u.last_name, u.first_name
")->fetchAll();

// ── Accès cohorte ────────────────────────────────────────────────────────────
$cohortGrants = [];
try {
    $cgStmt = $pdo->prepare("
        SELECT cag.*, u.first_name AS gb_first, u.last_name AS gb_last
        FROM cohort_access_grants cag
        LEFT JOIN users u ON cag.granted_by = u.id
        WHERE cag.cohort_id = ? AND cag.revoked_at IS NULL
        ORDER BY cag.granted_at DESC
    ");
    $cgStmt->execute([$cohortId]);
    $cohortGrants = $cgStmt->fetchAll();
} catch (\Exception $e) {}

// Cascade pour le formulaire d'ajout d'accès
$allRncp = $pdo->query("SELECT id, rncp_code, title FROM rncp_titles WHERE status='active' ORDER BY rncp_code")->fetchAll();
$allAT   = $pdo->query("SELECT id, rncp_title_id, code, title FROM activity_types ORDER BY rncp_title_id, order_num")->fetchAll();
$allComp = $pdo->query("SELECT id, activity_type_id, code, title FROM competencies ORDER BY activity_type_id, order_num")->fetchAll();
$allSeqs = $pdo->query("SELECT id, competency_id, title FROM sequences ORDER BY competency_id, order_num")->fetchAll();
$allMods = $pdo->query("SELECT id, sequence_id, title FROM modules ORDER BY sequence_id, order_num")->fetchAll();

// Résoudre les libellés des accès existants
$cgScopeLabels = [];
foreach ($cohortGrants as $cg) {
    $k = $cg['scope_type'].':'.$cg['scope_id'];
    if (isset($cgScopeLabels[$k])) continue;
    switch ($cg['scope_type']) {
        case 'rncp_title':    $r = $pdo->prepare("SELECT CONCAT(rncp_code,' — ',title) FROM rncp_titles WHERE id=?"); break;
        case 'activity_type': $r = $pdo->prepare("SELECT CONCAT(code,' — ',title) FROM activity_types WHERE id=?"); break;
        case 'competency':    $r = $pdo->prepare("SELECT CONCAT(code,' — ',title) FROM competencies WHERE id=?"); break;
        case 'sequence':      $r = $pdo->prepare("SELECT title FROM sequences WHERE id=?"); break;
        default:              $r = $pdo->prepare("SELECT title FROM modules WHERE id=?"); break;
    }
    $r->execute([$cg['scope_id']]);
    $cgScopeLabels[$k] = $r->fetchColumn() ?: '#'.$cg['scope_id'];
}

$cgScopeColors = [
    'rncp_title'    => ['color'=>'#a78bfa','bg'=>'rgba(139,92,246,.15)','label'=>'RNCP',       'icon'=>'certificate'],
    'activity_type' => ['color'=>'#f59e0b','bg'=>'rgba(245,158,11,.15)','label'=>'Activité',   'icon'=>'layer-group'],
    'competency'    => ['color'=>'#ef4444','bg'=>'rgba(239,68,68,.15)', 'label'=>'Compétence', 'icon'=>'bullseye'],
    'sequence'      => ['color'=>'#10b981','bg'=>'rgba(16,185,129,.15)','label'=>'Séquence',   'icon'=>'list-ol'],
    'module'        => ['color'=>'#0ea5e9','bg'=>'rgba(14,165,233,.15)','label'=>'Séance',     'icon'=>'bookmark'],
];

renderHead('Cohorte — ' . $cohort['name']);
renderSidebar('pedagogy');
renderTopbar('Cohorte', [
    ['Pédagogie', url('pedagogy/index.php')],
    ['Cohortes', url('pedagogy/cohorts/index.php')],
    [e($cohort['name']), ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- En-tête cohorte -->
  <div class="card" style="margin-bottom:24px">
    <div class="card-body" style="padding:20px 24px">
      <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap">
        <div style="flex:1;min-width:0">
          <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap">
            <?php if ($cohort['rncp_code']): ?>
            <span class="badge badge-primary"><?= e($cohort['rncp_code']) ?></span>
            <?php if ($cohort['rncp_level']): ?><span class="badge badge-secondary">Niveau <?= $cohort['rncp_level'] ?></span><?php endif; ?>
            <?php endif; ?>
            <?php if ($cohort['year']): ?><span class="badge badge-secondary"><i class="fas fa-calendar-alt"></i> <?= $cohort['year'] ?></span><?php endif; ?>
          </div>
          <h1 style="font-size:22px;margin-bottom:4px"><?= e($cohort['name']) ?></h1>
          <?php if ($cohort['rncp_title']): ?><div style="font-size:13px;color:var(--text-muted);margin-bottom:4px"><?= e($cohort['rncp_title']) ?></div><?php endif; ?>
          <?php if ($cohort['description']): ?><div style="font-size:13px;color:var(--text-faint)"><?= e($cohort['description']) ?></div><?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap">
          <?php if (!empty($members)): ?>
          <button class="btn btn-secondary btn-sm" onclick="document.getElementById('modal-notify').style.display='flex'">
            <i class="fas fa-bell"></i> Notifier tous
          </button>
          <?php endif; ?>
          <button class="btn btn-primary btn-sm" onclick="document.getElementById('modal-add').style.display='flex'">
            <i class="fas fa-user-plus"></i> Ajouter
          </button>
          <a href="<?= url('pedagogy/cohorts/agenda.php?id='.$cohortId) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-book-open"></i> Cahier de texte</a>
          <a href="<?= url('pedagogy/cohorts/kanban.php?id='.$cohortId) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-columns"></i> Kanban</a>
          <a href="<?= url('pedagogy/cohorts/create.php?id='.$cohortId) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-edit"></i> Modifier</a>
          <a href="<?= url('pedagogy/cohorts/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
        </div>
      </div>
    </div>
  </div>

  <!-- KPIs -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px">
    <?php
    $membresLabel = 'Membres (cohorte)';
    $membresNote  = $countExcluded > 0 ? $countExcluded.' exclu(s) des stats' : '';
    $kpis = [
      ['label'=>$membresLabel,     'val'=>$totalMembers,                        'note'=>$membresNote,          'color'=>'var(--primary-light)', 'icon'=>'users'],
      ['label'=>'Progression moy.','val'=>$avgPct.'%',                          'note'=>'hors comptes test',   'color'=>$avgPct===100?'var(--success)':'var(--info)', 'icon'=>'chart-line'],
      ['label'=>'Séances validées','val'=>$totalModsDone.'/'.$totalMods,        'note'=>'hors comptes test',   'color'=>'var(--success)', 'icon'=>'check-circle'],
      ['label'=>'Actifs (7 jours)','val'=>$activeRecent,                        'note'=>'hors comptes test',   'color'=>'var(--warning)', 'icon'=>'circle'],
    ];
    // Masquer la note "hors comptes test" s'il n'y a aucun exclu
    if (!$countExcluded) foreach ($kpis as &$k) $k['note'] = '';
    unset($k);
    foreach ($kpis as $k): ?>
    <div class="card">
      <div class="card-body" style="padding:14px;text-align:center">
        <i class="fas fa-<?= $k['icon'] ?>" style="color:<?= $k['color'] ?>;font-size:18px;margin-bottom:6px;display:block"></i>
        <div style="font-size:20px;font-weight:800;color:white"><?= $k['val'] ?></div>
        <div style="font-size:11px;color:var(--text-muted);line-height:1.3"><?= $k['label'] ?></div>
        <?php if ($k['note']): ?><div style="font-size:10px;color:var(--text-faint);margin-top:2px"><?= $k['note'] ?></div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Liste des membres -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-users" style="margin-right:8px;color:var(--primary-light)"></i>Membres de la cohorte</h3>
      <span class="badge badge-secondary"><?= $totalMembers ?></span>
    </div>

    <?php if (empty($members)): ?>
    <div class="empty-state" style="padding:48px">
      <div class="icon"><i class="fas fa-users"></i></div>
      <h3>Aucun membre</h3>
      <p>Ajoutez des apprenants à cette cohorte.</p>
      <button class="btn btn-primary" onclick="document.getElementById('modal-add').style.display='flex'">
        <i class="fas fa-user-plus"></i> Ajouter des apprenants
      </button>
    </div>
    <?php else: ?>
    <div class="table-container">
      <table class="table">
        <thead>
          <tr>
            <th>Apprenant</th>
            <th style="text-align:center">Statut</th>
            <th style="text-align:center">Progression</th>
            <th style="text-align:center">Séances</th>
            <th style="text-align:center">Quiz réussis</th>
            <th style="text-align:center">XP / Niv.</th>
            <th>Activité</th>
            <th>Ajouté le</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $m):
            $mt         = (int)$m['modules_total'];
            $md         = (int)$m['modules_done'];
            $pct        = $mt > 0 ? round($md / $mt * 100) : 0;
            $isActive   = $m['last_activity'] && strtotime($m['last_activity']) > strtotime('-7 days');
            $isExcluded = (bool)$m['excluded_from_stats'];
          ?>
          <tr style="<?= $isExcluded ? 'opacity:.45' : '' ?>">
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <div class="avatar avatar-sm" style="background:<?= getAvatarColor($m['first_name'].$m['last_name']) ?>;<?= $isExcluded?'filter:grayscale(1)':'' ?>">
                  <?php if ($m['avatar'] && file_exists(UPLOADS_PATH.'/avatars/'.$m['avatar'])): ?>
                  <img src="<?= e(uploadUrl('avatars/'.$m['avatar'])) ?>" alt="">
                  <?php else: ?><?= getAvatarInitials($m['first_name'], $m['last_name']) ?><?php endif; ?>
                </div>
                <div>
                  <div style="display:flex;align-items:center;gap:6px">
                    <span style="font-weight:700;color:white;font-size:13px"><?= e($m['first_name'].' '.$m['last_name']) ?></span>
                    <?php if ($isExcluded): ?>
                    <span style="font-size:10px;padding:1px 6px;border-radius:99px;background:rgba(251,191,36,.15);color:#fbbf24;border:1px solid rgba(251,191,36,.3);white-space:nowrap"><i class="fas fa-flask" style="font-size:9px"></i> Test</span>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:11px;color:var(--text-muted)"><?= e($m['email']) ?></div>
                </div>
              </div>
            </td>
            <td style="text-align:center"><?= getStatusBadge($m['status']) ?></td>
            <td style="text-align:center;min-width:110px">
              <div style="display:flex;align-items:center;gap:6px">
                <div style="flex:1;height:5px;background:var(--bg-hover);border-radius:99px;overflow:hidden">
                  <div style="height:100%;width:<?= $pct ?>%;background:<?= $isExcluded?'rgba(255,255,255,.2)':($pct===100?'var(--success)':'var(--primary)') ?>;border-radius:99px"></div>
                </div>
                <span style="font-size:11px;font-weight:700;color:var(--text-muted);flex-shrink:0"><?= $pct ?>%</span>
              </div>
            </td>
            <td style="text-align:center;font-size:13px;font-weight:600"><?= $md ?>/<?= $mt ?></td>
            <td style="text-align:center;font-size:13px;font-weight:600;color:var(--success)"><?= $m['quizzes_passed'] ?></td>
            <td style="text-align:center">
              <div style="font-size:13px;font-weight:700;color:var(--warning)"><?= number_format($m['xp_points']) ?> XP</div>
              <div style="font-size:10px;color:var(--text-faint)">Niv. <?= $m['level'] ?></div>
            </td>
            <td style="font-size:11px;color:<?= $isActive?'var(--success)':'var(--text-faint)' ?>">
              <i class="fas fa-circle" style="font-size:7px"></i>
              <?= $m['last_activity'] ? timeAgo($m['last_activity']) : 'Jamais' ?>
            </td>
            <td style="font-size:11px;color:var(--text-muted)"><?= formatDate($m['joined_at']) ?></td>
            <td style="text-align:right">
              <div style="display:flex;gap:6px;justify-content:flex-end">
                <a href="<?= url('admin/users/progress.php?id='.$m['id']) ?>" class="btn btn-ghost btn-sm" title="Suivi pédagogique" style="color:var(--primary-light)">
                  <i class="fas fa-chart-line"></i>
                </a>
                <!-- Exclure / réintégrer des stats -->
                <form method="POST">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_exclude_member">
                  <input type="hidden" name="student_id" value="<?= $m['id'] ?>">
                  <?php if ($isExcluded): ?>
                  <button type="submit" class="btn btn-ghost btn-sm" title="Réintégrer dans les statistiques" style="color:#34d399"><i class="fas fa-eye"></i></button>
                  <?php else: ?>
                  <button type="submit" class="btn btn-ghost btn-sm" title="Exclure des statistiques (compte test)"><i class="fas fa-eye-slash"></i></button>
                  <?php endif; ?>
                </form>
                <form method="POST" onsubmit="return confirm('Retirer <?= e(addslashes($m['first_name'].' '.$m['last_name'])) ?> de la cohorte ?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="remove_member">
                  <input type="hidden" name="student_id" value="<?= $m['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="Retirer"><i class="fas fa-user-minus"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

<!-- ═══════════════ Section Accès aux ressources (dans page-content) ═══════════════ -->
<?php // (section déplacée dans page-content pour héritage du padding/max-width) ?>

<!-- Modal : Ajouter des apprenants -->
<div id="modal-add" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:560px;max-width:95vw;max-height:90vh;display:flex;flex-direction:column">
    <div class="card-header" style="flex-shrink:0">
      <h3 class="card-title"><i class="fas fa-user-plus" style="color:var(--primary-light)"></i> Ajouter des apprenants</h3>
      <button onclick="document.getElementById('modal-add').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body" style="flex:1;overflow:hidden;display:flex;flex-direction:column;padding:16px 20px">
      <?php if (empty($candidates)): ?>
      <p style="color:var(--text-muted);text-align:center;padding:24px 0">Tous les apprenants actifs sont déjà dans cette cohorte.</p>
      <?php else: ?>
      <form method="POST" id="form-add" style="display:flex;flex-direction:column;flex:1;overflow:hidden">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_members">
        <!-- Recherche -->
        <div class="search-input" style="margin-bottom:12px;flex-shrink:0">
          <i class="fas fa-search"></i>
          <input type="text" id="add-search" placeholder="Filtrer par nom ou email…" oninput="filterCandidates(this.value)">
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-shrink:0">
          <span id="add-count" style="font-size:12px;color:var(--text-muted)"><?= count($candidates) ?> apprenant(s) disponible(s)</span>
          <button type="button" class="btn btn-ghost btn-sm" onclick="toggleAllCandidates()"><i class="fas fa-check-double"></i> Tout sélectionner</button>
        </div>
        <!-- Liste scrollable -->
        <div id="candidates-list" style="flex:1;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-elevated)">
          <?php foreach ($candidates as $c): ?>
          <label class="candidate-row" data-search="<?= e(strtolower($c['first_name'].' '.$c['last_name'].' '.$c['email'])) ?>"
            style="display:flex;align-items:center;gap:12px;padding:10px 14px;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.04);transition:background .15s"
            onmouseover="this.style.background='rgba(99,102,241,.08)'" onmouseout="this.style.background=''">
            <input type="checkbox" name="student_ids[]" value="<?= $c['id'] ?>" style="accent-color:var(--primary);width:16px;height:16px;flex-shrink:0">
            <div class="avatar avatar-sm" style="background:<?= getAvatarColor($c['first_name'].$c['last_name']) ?>;flex-shrink:0">
              <?= getAvatarInitials($c['first_name'], $c['last_name']) ?>
            </div>
            <div style="min-width:0">
              <div style="font-size:13px;font-weight:600;color:white"><?= e($c['first_name'].' '.$c['last_name']) ?></div>
              <div style="font-size:11px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($c['email']) ?></div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-shrink:0">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-add').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter la sélection</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal : Notifier tous -->
<?php if (!empty($members)): ?>
<div id="modal-notify" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:500px;max-width:95vw">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-bell" style="color:var(--warning)"></i> Notifier les <?= $totalMembers ?> membres</h3>
      <button onclick="document.getElementById('modal-notify').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="notify_all">
        <div class="form-group">
          <label class="form-label">Titre de la notification</label>
          <input type="text" name="notif_title" class="form-control" value="Message de votre promotion" maxlength="255">
        </div>
        <div class="form-group">
          <label class="form-label">Message <span class="required">*</span></label>
          <textarea name="message" class="form-control" rows="4" required
            placeholder="Votre message à destination de tous les apprenants de la cohorte…"></textarea>
        </div>
        <div style="background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.2);border-radius:var(--radius);padding:10px 14px;font-size:12px;color:var(--text-muted);margin-bottom:16px">
          <i class="fas fa-info-circle" style="color:var(--warning)"></i> Cette notification apparaîtra dans le centre de notifications de chaque apprenant.
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-notify').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-warning"><i class="fas fa-bell"></i> Envoyer</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════ Section Accès aux ressources ═══════════════ -->
<div style="margin-top:28px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap">
    <h2 style="font-size:16px;font-weight:800;margin:0;display:flex;align-items:center;gap:8px">
      <i class="fas fa-key" style="color:var(--primary-light)"></i> Accès aux ressources
    </h2>
    <button onclick="document.getElementById('modal-access').style.display='flex'" class="btn btn-primary btn-sm">
      <i class="fas fa-plus"></i> Donner un accès à la cohorte
    </button>
  </div>

  <?php if (empty($cohortGrants)): ?>
  <div class="card" style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">
    <i class="fas fa-key" style="font-size:28px;margin-bottom:8px;display:block;opacity:.25"></i>
    Aucun accès accordé à cette cohorte.<br>
    <small>Les accès accordés s'appliquent en cascade à tous les membres.</small>
  </div>
  <?php else: ?>
  <div class="card" style="overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="border-bottom:1px solid var(--border-color)">
          <th style="padding:10px 16px;font-size:11px;font-weight:600;color:var(--text-muted);text-align:left;text-transform:uppercase">Niveau</th>
          <th style="padding:10px 16px;font-size:11px;font-weight:600;color:var(--text-muted);text-align:left;text-transform:uppercase">Périmètre</th>
          <th style="padding:10px 16px;font-size:11px;font-weight:600;color:var(--text-muted);text-align:left;text-transform:uppercase">Accordé par</th>
          <th style="padding:10px 16px;font-size:11px;font-weight:600;color:var(--text-muted);text-align:left;text-transform:uppercase">Date</th>
          <th style="padding:10px 16px;font-size:11px;font-weight:600;color:var(--text-muted);text-align:left;text-transform:uppercase">Note</th>
          <th style="width:60px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cohortGrants as $cg):
        $st  = $cgScopeColors[$cg['scope_type']] ?? ['color'=>'#64748b','bg'=>'rgba(100,116,139,.15)','label'=>$cg['scope_type'],'icon'=>'circle'];
        $lbl = $cgScopeLabels[$cg['scope_type'].':'.$cg['scope_id']] ?? '—';
      ?>
      <tr style="border-bottom:1px solid var(--border-faint,rgba(255,255,255,.05))">
        <td style="padding:11px 16px">
          <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700">
            <i class="fas fa-<?= $st['icon'] ?>"></i> <?= $st['label'] ?>
          </span>
        </td>
        <td style="padding:11px 16px;font-size:12px;max-width:260px">
          <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= e($lbl) ?>"><?= e($lbl) ?></div>
        </td>
        <td style="padding:11px 16px;font-size:12px;color:var(--text-secondary)"><?= $cg['gb_first'] ? e($cg['gb_first'].' '.$cg['gb_last']) : '—' ?></td>
        <td style="padding:11px 16px;font-size:12px;color:var(--text-muted)"><?= formatDate($cg['granted_at']) ?></td>
        <td style="padding:11px 16px;font-size:11px;color:var(--text-muted);max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($cg['notes'] ?? '') ?></td>
        <td style="padding:11px 16px">
          <form method="POST" onsubmit="return confirm('Révoquer cet accès pour toute la cohorte ?')">
            <?= csrfField() ?>
            <input type="hidden" name="action"   value="revoke_cohort_grant">
            <input type="hidden" name="grant_id" value="<?= $cg['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="Révoquer"><i class="fas fa-ban"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div style="padding:10px 16px;font-size:12px;color:var(--text-muted);border-top:1px solid var(--border-color)">
      <?= count($cohortGrants) ?> accès actif<?= count($cohortGrants)>1?'s':'' ?> — s'appliquent aux <?= $totalMembers ?> membres de la cohorte
    </div>
  </div>
  <?php endif; ?>
</div>
</div><!-- /.page-content -->

<!-- Modal : Donner un accès à la cohorte -->
<div id="modal-access" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,.6);align-items:center;justify-content:center;backdrop-filter:blur(4px)">
  <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:28px 32px;width:min(580px,95vw);max-height:90vh;overflow-y:auto;position:relative">
    <button onclick="document.getElementById('modal-access').style.display='none'" style="position:absolute;top:16px;right:16px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px">×</button>
    <h3 style="font-size:16px;font-weight:800;margin:0 0 6px"><i class="fas fa-key" style="color:var(--primary-light);margin-right:8px"></i>Donner un accès à la cohorte</h3>
    <p style="font-size:12px;color:var(--text-muted);margin:0 0 20px">L'accès s'appliquera en cascade à tous les membres actuels et futurs de la cohorte.</p>

    <form method="POST" id="access-cohort-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="grant_cohort_access">

      <!-- Niveau d'accès -->
      <div style="margin-bottom:18px">
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:10px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-secondary)">Niveau d'accès</label>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:8px" id="level-cards">
          <?php foreach ([
            ['rncp_title',    'certificate', '#a78bfa', 'rgba(139,92,246,.15)', 'RNCP'],
            ['activity_type', 'layer-group', '#f59e0b', 'rgba(245,158,11,.15)', 'Activité type'],
            ['competency',    'bullseye',    '#ef4444', 'rgba(239,68,68,.15)',  'Compétence'],
            ['sequence',      'list-ol',     '#10b981', 'rgba(16,185,129,.15)', 'Séquence'],
            ['module',        'bookmark',    '#0ea5e9', 'rgba(14,165,233,.15)', 'Séance'],
          ] as [$val, $icon, $color, $bg, $name]): ?>
          <label style="cursor:pointer;text-align:center;border-radius:var(--radius);border:2px solid transparent;padding:10px 6px;background:<?= $bg ?>;transition:.15s" id="level-card-<?= $val ?>">
            <input type="radio" name="scope_type" value="<?= $val ?>" style="display:none" onchange="onLevelChange('<?= $val ?>')">
            <i class="fas fa-<?= $icon ?>" style="color:<?= $color ?>;font-size:18px;display:block;margin-bottom:4px"></i>
            <div style="font-size:10px;font-weight:700;color:<?= $color ?>"><?= $name ?></div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Cascade RNCP → AT → Comp → Seq → Séance -->
      <div style="margin-bottom:18px">
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-secondary)">Périmètre</label>
        <div style="display:flex;flex-direction:column;gap:8px">
          <select id="ca-rncp" class="form-control" style="font-size:12px" onchange="cascadeAT()">
            <option value="">— Titre RNCP —</option>
            <?php foreach ($allRncp as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['rncp_code']) ?> — <?= e($r['title']) ?></option><?php endforeach; ?>
          </select>
          <select id="ca-at" class="form-control" style="font-size:12px;display:none" onchange="cascadeComp()">
            <option value="">— Activité type —</option>
          </select>
          <select id="ca-comp" class="form-control" style="font-size:12px;display:none" onchange="cascadeSeq()">
            <option value="">— Compétence —</option>
          </select>
          <select id="ca-seq" class="form-control" style="font-size:12px;display:none" onchange="cascadeMod()">
            <option value="">— Séquence —</option>
          </select>
          <select id="ca-mod" class="form-control" style="font-size:12px;display:none">
            <option value="">— Séance —</option>
          </select>
        </div>
      </div>
      <input type="hidden" name="scope_id" id="ca-scope-id" value="">

      <div style="margin-bottom:20px">
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:6px;color:var(--text-secondary)">Note (facultatif)</label>
        <input type="text" name="notes" class="form-control" style="font-size:12px" placeholder="Ex: Accès formation automne 2025" maxlength="255">
      </div>

      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-access').style.display='none'">Annuler</button>
        <button type="submit" id="ca-submit-btn" class="btn btn-primary" disabled><i class="fas fa-key"></i> Accorder l'accès</button>
      </div>
    </form>
  </div>
</div>

<script>
// Fermer modals au clic extérieur
['modal-add','modal-notify','modal-access'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('click', e => { if (e.target === el) el.style.display = 'none'; });
});

// ── Cascade accès cohorte ────────────────────────────────────────────────────
const CA_AT   = <?= json_encode(array_map(fn($a)=>['id'=>$a['id'],'rncpId'=>$a['rncp_title_id'],'label'=>$a['code'].' — '.$a['title']], $allAT), JSON_UNESCAPED_UNICODE) ?>;
const CA_COMP = <?= json_encode(array_map(fn($c)=>['id'=>$c['id'],'atId'=>$c['activity_type_id'],'label'=>$c['code'].' — '.$c['title']], $allComp), JSON_UNESCAPED_UNICODE) ?>;
const CA_SEQS = <?= json_encode(array_map(fn($s)=>['id'=>$s['id'],'compId'=>$s['competency_id'],'label'=>$s['title']], $allSeqs), JSON_UNESCAPED_UNICODE) ?>;
const CA_MODS = <?= json_encode(array_map(fn($m)=>['id'=>$m['id'],'seqId'=>$m['sequence_id'],'label'=>$m['title']], $allMods), JSON_UNESCAPED_UNICODE) ?>;

let _caLevel = '';

function fillSelect(sel, items, labelKey='label') {
  const cur = sel.value;
  while (sel.options.length > 1) sel.remove(1);
  items.forEach(it => {
    const o = new Option(it[labelKey], it.id);
    sel.add(o);
  });
  if (items.find(i=>i.id==cur)) sel.value = cur;
}
function showSel(id, show) {
  document.getElementById(id).style.display = show ? '' : 'none';
}

function cascadeAT() {
  const rId = document.getElementById('ca-rncp').value;
  const items = CA_AT.filter(a => a.rncpId == rId);
  fillSelect(document.getElementById('ca-at'), items);
  showSel('ca-at', !!rId);
  showSel('ca-comp', false); showSel('ca-seq', false); showSel('ca-mod', false);
  updateScopeId();
}
function cascadeComp() {
  const aId = document.getElementById('ca-at').value;
  const items = CA_COMP.filter(c => c.atId == aId);
  fillSelect(document.getElementById('ca-comp'), items);
  showSel('ca-comp', !!aId);
  showSel('ca-seq', false); showSel('ca-mod', false);
  updateScopeId();
}
function cascadeSeq() {
  const cId = document.getElementById('ca-comp').value;
  const items = CA_SEQS.filter(s => s.compId == cId);
  fillSelect(document.getElementById('ca-seq'), items);
  showSel('ca-seq', !!cId);
  showSel('ca-mod', false);
  updateScopeId();
}
function cascadeMod() {
  const sId = document.getElementById('ca-seq').value;
  const items = CA_MODS.filter(m => m.seqId == sId);
  fillSelect(document.getElementById('ca-mod'), items);
  showSel('ca-mod', !!sId);
  updateScopeId();
}

function updateScopeId() {
  const ids = {
    'rncp_title':    document.getElementById('ca-rncp').value,
    'activity_type': document.getElementById('ca-at').value,
    'competency':    document.getElementById('ca-comp').value,
    'sequence':      document.getElementById('ca-seq').value,
    'module':        document.getElementById('ca-mod').value,
  };
  const scopeId = _caLevel ? (ids[_caLevel] || '') : '';
  document.getElementById('ca-scope-id').value = scopeId;
  document.getElementById('ca-submit-btn').disabled = !(_caLevel && scopeId);
}

document.querySelectorAll('#level-cards input[type=radio]').forEach(radio => {
  radio.addEventListener('change', () => updateScopeId());
});

function onLevelChange(val) {
  _caLevel = val;
  document.querySelectorAll('#level-cards label').forEach(lbl => {
    lbl.style.borderColor = lbl.id === 'level-card-'+val ? 'currentColor' : 'transparent';
  });
  updateScopeId();
}

// Synchro selects → scope_id à chaque changement
['ca-rncp','ca-at','ca-comp','ca-seq','ca-mod'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('change', updateScopeId);
});

function filterCandidates(q) {
  const term = q.toLowerCase();
  let visible = 0;
  document.querySelectorAll('.candidate-row').forEach(row => {
    const match = !term || row.dataset.search.includes(term);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  document.getElementById('add-count').textContent = visible + ' apprenant(s) affiché(s)';
}

let allSelected = false;
function toggleAllCandidates() {
  allSelected = !allSelected;
  document.querySelectorAll('#candidates-list input[type=checkbox]').forEach(cb => {
    if (cb.closest('.candidate-row').style.display !== 'none') cb.checked = allSelected;
  });
}
</script>
<?php renderFooter(); ?>
