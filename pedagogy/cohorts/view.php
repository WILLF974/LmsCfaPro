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
</div>

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

<script>
// Fermer modals au clic extérieur
['modal-add','modal-notify'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('click', e => { if (e.target === el) el.style.display = 'none'; });
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
