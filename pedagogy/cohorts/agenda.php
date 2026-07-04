<?php
require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/layout.php';

// Auth : admin, pédagogie, enseignant
$viewer = currentUser();
if (!$viewer) { redirect(url('index.php')); }
$viewerRole = $viewer['role'];
$viewerId   = (int)$viewer['id'];
if (!in_array($viewerRole, ['admin', 'pedagogy', 'teacher'])) { redirect(url('index.php')); }
$canEditAll = in_array($viewerRole, ['admin', 'pedagogy']); // peut éditer/supprimer toutes les entrées

$pdo      = getDB();
$cohortId = (int)($_GET['id'] ?? 0);
$backUrl  = $canEditAll ? url('pedagogy/cohorts/index.php') : url('teacher/cahier/index.php');
if (!$cohortId) { redirect($backUrl); }

$cStmt = $pdo->prepare("
    SELECT c.*, rt.rncp_code, rt.title as rncp_title, rt.id as rncp_title_id_val
    FROM cohorts c
    LEFT JOIN rncp_titles rt ON c.rncp_title_id = rt.id
    WHERE c.id = ?
");
$cStmt->execute([$cohortId]);
$cohort = $cStmt->fetch();
if (!$cohort) { setFlash('error', 'Cohorte introuvable.'); redirect($backUrl); }

// Navigation mois/année
$year  = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
$year  = max(2020, min(2035, $year));
$month = max(1, min(12, $month));

$prevM = $month === 1 ? 12 : $month - 1;
$prevY = $month === 1 ? $year - 1 : $year;
$nextM = $month === 12 ? 1  : $month + 1;
$nextY = $month === 12 ? $year + 1 : $year;

$firstDayTs  = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int)date('t', $firstDayTs);
$startDow    = (int)date('N', $firstDayTs); // 1=Lu, 7=Di

$monthNames = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$dayNames   = ['Lu','Ma','Me','Je','Ve','Sa','Di'];

// Date par défaut dans le modal
$defaultDate = ($year == (int)date('Y') && $month == (int)date('n'))
    ? date('Y-m-d')
    : sprintf('%04d-%02d-01', $year, $month);

// ── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_entry') {
        $title        = trim($_POST['title'] ?? '');
        $date         = $_POST['scheduled_date'] ?? '';
        $instructions = trim($_POST['instructions'] ?? '') ?: null;
        $seqId        = (int)($_POST['sequence_id'] ?? 0) ?: null;
        $modId        = (int)($_POST['module_id'] ?? 0) ?: null;
        $timeStart    = $_POST['time_start'] ?? '';
        $timeEnd      = $_POST['time_end'] ?? '';
        if ($title && $date) {
            $pdo->prepare("
                INSERT INTO agenda_entries (cohort_id,title,instructions,scheduled_date,time_start,time_end,sequence_id,module_id,created_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([$cohortId, $title, $instructions, $date, $timeStart ?: null, $timeEnd ?: null, $seqId, $modId, $viewerId]);
            auditLog('agenda_entry_created', 'cohort', $cohortId);
            setFlash('success', 'Séance ajoutée au cahier de texte.');
        } else {
            setFlash('error', 'Le titre et la date sont obligatoires.');
        }

    } elseif ($action === 'edit_entry') {
        $entryId      = (int)($_POST['entry_id'] ?? 0);
        $title        = trim($_POST['title'] ?? '');
        $date         = $_POST['scheduled_date'] ?? '';
        $instructions = trim($_POST['instructions'] ?? '') ?: null;
        $seqId        = (int)($_POST['sequence_id'] ?? 0) ?: null;
        $modId        = (int)($_POST['module_id'] ?? 0) ?: null;
        $timeStart    = $_POST['time_start'] ?? '';
        $timeEnd      = $_POST['time_end'] ?? '';
        if ($entryId && $title && $date) {
            $chk = $pdo->prepare("SELECT created_by FROM agenda_entries WHERE id=? AND cohort_id=?");
            $chk->execute([$entryId, $cohortId]);
            $owner = $chk->fetchColumn();
            if ($owner !== false && ($canEditAll || (int)$owner === $viewerId)) {
                $pdo->prepare("
                    UPDATE agenda_entries
                    SET title=?,instructions=?,scheduled_date=?,time_start=?,time_end=?,sequence_id=?,module_id=?,updated_by=?
                    WHERE id=? AND cohort_id=?
                ")->execute([$title, $instructions, $date, $timeStart ?: null, $timeEnd ?: null, $seqId, $modId, $viewerId, $entryId, $cohortId]);
                auditLog('agenda_entry_updated', 'cohort', $cohortId);
                setFlash('success', 'Séance mise à jour.');
            } else {
                setFlash('error', 'Vous ne pouvez modifier que vos propres entrées.');
            }
        }

    } elseif ($action === 'delete_entry') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        if ($entryId) {
            $chk = $pdo->prepare("SELECT created_by FROM agenda_entries WHERE id=? AND cohort_id=?");
            $chk->execute([$entryId, $cohortId]);
            $owner = $chk->fetchColumn();
            if ($owner !== false && ($canEditAll || (int)$owner === $viewerId)) {
                $pdo->prepare("DELETE FROM agenda_entries WHERE id=? AND cohort_id=?")->execute([$entryId, $cohortId]);
                auditLog('agenda_entry_deleted', 'cohort', $cohortId);
                setFlash('success', 'Séance supprimée.');
            } else {
                setFlash('error', 'Vous ne pouvez supprimer que vos propres entrées.');
            }
        }
    }

    redirect(url("pedagogy/cohorts/agenda.php?id=$cohortId&y=$year&m=$month"));
}

// ── Entrées du mois ──────────────────────────────────────────
$eStmt = $pdo->prepare("
    SELECT ae.*,
           u.first_name  as creator_first, u.last_name as creator_last, u.role as creator_role,
           ue.first_name as updater_first, ue.last_name as updater_last,
           seq.title as seq_title,
           m.title  as module_title
    FROM agenda_entries ae
    JOIN  users u   ON ae.created_by = u.id
    LEFT JOIN users ue  ON ae.updated_by = ue.id
    LEFT JOIN sequences seq ON ae.sequence_id = seq.id
    LEFT JOIN modules   m   ON ae.module_id   = m.id
    WHERE ae.cohort_id = ?
      AND YEAR(ae.scheduled_date)  = ?
      AND MONTH(ae.scheduled_date) = ?
    ORDER BY ae.scheduled_date ASC, COALESCE(ae.time_start,'00:00') ASC
");
$eStmt->execute([$cohortId, $year, $month]);
$entries = $eStmt->fetchAll();

$byDay = [];
foreach ($entries as $e) {
    $byDay[(int)date('j', strtotime($e['scheduled_date']))][] = $e;
}

// ── Hiérarchie RNCP pour la cascade ─────────────────────────
$rncpTitles   = $pdo->query("SELECT id, rncp_code, title FROM rncp_titles ORDER BY rncp_code")->fetchAll(PDO::FETCH_ASSOC);
$actTypes     = $pdo->query("SELECT id, rncp_title_id, code, title FROM activity_types ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);
$competencies = $pdo->query("SELECT id, activity_type_id, code, title FROM competencies ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);
$sequences    = $pdo->query("SELECT id, competency_id, title FROM sequences ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);
$modules      = $pdo->query("SELECT id, sequence_id, title FROM modules ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);

$roleLabel = ['admin'=>'Admin','pedagogy'=>'Pédagogie','teacher'=>'Enseignant'];
$roleColor = ['admin'=>'var(--danger)','pedagogy'=>'var(--primary-light)','teacher'=>'var(--success)'];

$sidebarRole = in_array($viewerRole, ['admin','pedagogy']) ? 'pedagogy' : 'teacher';
$cohortViewUrl = $canEditAll ? url('pedagogy/cohorts/view.php?id='.$cohortId) : url('teacher/cahier/index.php');
$breadcrumbs = $canEditAll
    ? [['Pédagogie', url('pedagogy/index.php')],['Cohortes', url('pedagogy/cohorts/index.php')],[e($cohort['name']), $cohortViewUrl],['Cahier de texte','']]
    : [['Cahier de texte', url('teacher/cahier/index.php')],[e($cohort['name']),'']];

renderHead('Cahier de texte — ' . $cohort['name']);
renderSidebar($sidebarRole);
renderTopbar('Cahier de texte', $breadcrumbs);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- En-tête -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <div style="flex:1;min-width:0">
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">
            <i class="fas fa-layer-group" style="color:var(--primary-light);margin-right:4px"></i>
            <?= e($cohort['name']) ?>
            <?php if ($cohort['rncp_code']): ?><span class="badge badge-primary" style="margin-left:6px"><?= e($cohort['rncp_code']) ?></span><?php endif; ?>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <a href="?id=<?= $cohortId ?>&y=<?= $prevY ?>&m=<?= $prevM ?>" class="btn btn-ghost btn-sm" style="padding:5px 10px"><i class="fas fa-chevron-left"></i></a>
            <h2 style="font-size:20px;font-weight:800;color:white;margin:0;min-width:190px;text-align:center">
              <?= $monthNames[$month-1] ?> <?= $year ?>
            </h2>
            <a href="?id=<?= $cohortId ?>&y=<?= $nextY ?>&m=<?= $nextM ?>" class="btn btn-ghost btn-sm" style="padding:5px 10px"><i class="fas fa-chevron-right"></i></a>
            <?php if ($year != date('Y') || $month != date('n')): ?>
            <a href="?id=<?= $cohortId ?>&y=<?= date('Y') ?>&m=<?= date('n') ?>" class="btn btn-ghost btn-sm" style="font-size:11px">Aujourd'hui</a>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0">
          <button class="btn btn-primary btn-sm" onclick="openEntryModal()">
            <i class="fas fa-plus"></i> Ajouter une séance
          </button>
          <a href="<?= $cohortViewUrl ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
        </div>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:250px 1fr;gap:20px;align-items:start">

    <!-- Mini calendrier -->
    <div class="card" style="position:sticky;top:80px">
      <div class="card-body" style="padding:14px">
        <!-- Jours de la semaine -->
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:3px">
          <?php foreach ($dayNames as $dn): ?>
          <div style="text-align:center;font-size:9px;font-weight:700;color:var(--text-faint);padding:3px 0;text-transform:uppercase"><?= $dn ?></div>
          <?php endforeach; ?>
        </div>
        <!-- Cellules -->
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px">
          <?php
          $todayDay = (date('Y') == $year && date('n') == $month) ? (int)date('j') : -1;
          for ($i = 1; $i < $startDow; $i++): ?>
          <div></div>
          <?php endfor;
          for ($d = 1; $d <= $daysInMonth; $d++):
            $count   = isset($byDay[$d]) ? count($byDay[$d]) : 0;
            $isToday = ($d === $todayDay);
          ?>
          <a href="<?= $count ? '#day-'.$d : '#' ?>"
             title="<?= $count ? $count.' séance(s)' : '' ?>"
             style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:30px;border-radius:6px;text-decoration:none;
                    background:<?= $isToday?'var(--primary)':($count?'rgba(99,102,241,.15)':'transparent') ?>;
                    border:1px solid <?= $isToday?'var(--primary)':($count?'rgba(99,102,241,.35)':'transparent') ?>;
                    cursor:<?= $count?'pointer':'default' ?>;transition:background .12s"
             <?= $count && !$isToday ? 'onmouseover="this.style.background=\'rgba(99,102,241,.28)\'" onmouseout="this.style.background=\'rgba(99,102,241,.15)\'"' : '' ?>>
            <span style="font-size:11px;font-weight:<?= $isToday?'800':'600' ?>;color:<?= $isToday?'white':($count?'var(--primary-light)':'var(--text-muted)') ?>"><?= $d ?></span>
            <?php if ($count): ?><span style="font-size:8px;color:<?= $isToday?'rgba(255,255,255,.7)':'var(--primary-light)' ?>"><?= $count ?></span><?php endif; ?>
          </a>
          <?php endfor; ?>
        </div>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);font-size:10px;color:var(--text-faint);display:flex;flex-direction:column;gap:4px">
          <div style="display:flex;align-items:center;gap:5px"><div style="width:8px;height:8px;border-radius:2px;background:var(--primary);flex-shrink:0"></div>Aujourd'hui</div>
          <div style="display:flex;align-items:center;gap:5px"><div style="width:8px;height:8px;border-radius:2px;background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.4);flex-shrink:0"></div>Séance programmée</div>
        </div>
      </div>
    </div>

    <!-- Agenda liste -->
    <div style="min-width:0">
      <?php if (empty($entries)): ?>
      <div class="card">
        <div class="empty-state" style="padding:48px">
          <div class="icon"><i class="fas fa-calendar-alt"></i></div>
          <h3>Aucune séance ce mois</h3>
          <p>Programmez des séances pour cette cohorte.</p>
          <button class="btn btn-primary" onclick="openEntryModal()"><i class="fas fa-plus"></i> Ajouter une séance</button>
        </div>
      </div>
      <?php else:
        $dayNamesFull = ['','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
        foreach ($byDay as $day => $dayEntries):
          $dateTs   = mktime(0, 0, 0, $month, $day, $year);
          $dow      = (int)date('N', $dateTs); // 1=Lu
          $isToday2 = (date('Y-m-d', $dateTs) === date('Y-m-d'));
          $dayLabel = $dayNamesFull[$dow] . ' ' . $day . ' ' . $monthNames[$month-1] . ' ' . $year;
      ?>
      <div id="day-<?= $day ?>" style="margin-bottom:24px">
        <!-- Séparateur date -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <div style="height:1px;width:10px;background:var(--border)"></div>
          <div style="display:flex;align-items:center;gap:8px;white-space:nowrap">
            <span style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:<?= $isToday2?'var(--primary-light)':'var(--text-muted)' ?>"><?= $dayLabel ?></span>
            <?php if ($isToday2): ?><span class="badge badge-primary" style="font-size:9px">Aujourd'hui</span><?php endif; ?>
            <span class="badge badge-secondary" style="font-size:9px"><?= count($dayEntries) ?> séance<?= count($dayEntries)>1?'s':'' ?></span>
          </div>
          <div style="flex:1;height:1px;background:var(--border)"></div>
        </div>

        <div style="display:flex;flex-direction:column;gap:10px">
          <?php foreach ($dayEntries as $e):
            $canEdit       = $canEditAll || (int)$e['created_by'] === $viewerId;
            $cRoleLabel    = $roleLabel[$e['creator_role']] ?? $e['creator_role'];
            $cRoleColor    = $roleColor[$e['creator_role']] ?? 'var(--text-muted)';
            $wasEdited     = $e['updated_by'] && ($e['updated_at'] > $e['created_at']);
            $sameEditor    = $wasEdited && $e['updated_by'] === $e['created_by'];
          ?>
          <div class="card" style="border-left:3px solid var(--primary);transition:border-color .15s">
            <div class="card-body" style="padding:14px 16px">
              <div style="display:flex;gap:14px;align-items:flex-start">

                <!-- Plage horaire -->
                <div style="flex-shrink:0;width:48px;text-align:center;padding-top:2px">
                  <?php if ($e['time_start']): ?>
                  <div style="font-size:15px;font-weight:800;color:var(--primary-light);line-height:1"><?= substr($e['time_start'],0,5) ?></div>
                  <?php if ($e['time_end']): ?>
                  <div style="font-size:10px;color:var(--text-faint);margin-top:2px">↓ <?= substr($e['time_end'],0,5) ?></div>
                  <?php endif; ?>
                  <?php else: ?>
                  <div style="font-size:18px;color:var(--text-faint)">—</div>
                  <?php endif; ?>
                </div>

                <!-- Corps -->
                <div style="flex:1;min-width:0">
                  <div style="font-size:14px;font-weight:700;color:white;margin-bottom:<?= $e['instructions']?'6px':'4px' ?>"><?= e($e['title']) ?></div>
                  <?php if ($e['instructions']): ?>
                  <div style="font-size:13px;color:var(--text-muted);white-space:pre-wrap;line-height:1.5;margin-bottom:8px"><?= e($e['instructions']) ?></div>
                  <?php endif; ?>
                  <?php if ($e['module_title'] || $e['seq_title']): ?>
                  <div style="display:inline-flex;align-items:center;gap:5px;background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.2);border-radius:99px;padding:3px 10px;font-size:11px;color:var(--primary-light);margin-bottom:8px">
                    <i class="fas fa-link" style="font-size:9px"></i>
                    <?= e($e['module_title'] ?: ($e['seq_title'].' — toute la séquence')) ?>
                  </div>
                  <?php endif; ?>
                  <!-- Auteur -->
                  <div style="display:flex;align-items:center;gap:6px;margin-top:4px">
                    <div class="avatar" style="width:20px;height:20px;font-size:8px;flex-shrink:0;background:<?= getAvatarColor($e['creator_first'].$e['creator_last']) ?>">
                      <?= getAvatarInitials($e['creator_first'], $e['creator_last']) ?>
                    </div>
                    <span style="font-size:11px;color:var(--text-faint)">
                      <span style="color:white"><?= e($e['creator_first'].' '.$e['creator_last']) ?></span>
                      <span style="color:<?= $cRoleColor ?>">&nbsp;(<?= $cRoleLabel ?>)</span>
                      &nbsp;·&nbsp;<?= timeAgo($e['created_at']) ?>
                      <?php if ($wasEdited): ?>
                      &nbsp;·&nbsp;<em>modifié par <?= $sameEditor ? 'lui-même' : e($e['updater_first'].' '.$e['updater_last']) ?></em>
                      <?php endif; ?>
                    </span>
                  </div>
                </div>

                <!-- Actions -->
                <?php if ($canEdit): ?>
                <div style="display:flex;gap:4px;flex-shrink:0;margin-top:2px">
                  <button class="btn btn-ghost btn-sm" title="Modifier"
                    onclick='openEntryModal(<?= htmlspecialchars(json_encode([
                      'id'             => (int)$e['id'],
                      'title'          => $e['title'],
                      'scheduled_date' => $e['scheduled_date'],
                      'time_start'     => $e['time_start'] ? substr($e['time_start'],0,5) : '',
                      'time_end'       => $e['time_end']   ? substr($e['time_end'],0,5)   : '',
                      'instructions'   => $e['instructions'] ?? '',
                      'sequence_id'    => $e['sequence_id'] ? (int)$e['sequence_id'] : null,
                      'module_id'      => $e['module_id']   ? (int)$e['module_id']   : null,
                    ]), ENT_QUOTES) ?>)'>
                    <i class="fas fa-edit" style="color:var(--primary-light)"></i>
                  </button>
                  <form method="POST" onsubmit="return confirm('Supprimer cette séance ?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="delete_entry">
                    <input type="hidden" name="entry_id" value="<?= $e['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" title="Supprimer" style="color:var(--danger)"><i class="fas fa-trash"></i></button>
                  </form>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- Modal : Ajouter / Modifier une séance -->
<div id="modal-entry" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:640px;max-width:95vw;max-height:93vh;display:flex;flex-direction:column">
    <div class="card-header" style="flex-shrink:0">
      <h3 class="card-title" id="modal-entry-title"><i class="fas fa-plus" style="color:var(--primary-light)"></i> Ajouter une séance</h3>
      <button onclick="closeEntryModal()" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body" style="flex:1;overflow-y:auto;padding:16px 20px">
      <form method="POST" id="form-entry">
        <?= csrfField() ?>
        <input type="hidden" id="entry-action"   name="action"   value="add_entry">
        <input type="hidden" id="entry-id"        name="entry_id" value="">

        <!-- Date + Horaires -->
        <div style="display:grid;grid-template-columns:1fr 110px 110px;gap:12px;margin-bottom:16px">
          <div class="form-group" style="margin:0">
            <label class="form-label">Date <span class="required">*</span></label>
            <input type="date" name="scheduled_date" id="entry-date" class="form-control" required value="<?= $defaultDate ?>">
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Début</label>
            <input type="time" name="time_start" id="entry-time-start" class="form-control">
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Fin</label>
            <input type="time" name="time_end"   id="entry-time-end"   class="form-control">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Titre de la séance <span class="required">*</span></label>
          <input type="text" name="title" id="entry-title-input" class="form-control" required
            placeholder="Ex : Révision C1.1 — Analyse des flux financiers">
        </div>

        <div class="form-group">
          <label class="form-label">Consignes / Travail à réaliser</label>
          <textarea name="instructions" id="entry-instructions" class="form-control" rows="4"
            placeholder="Décrivez le contenu de la séance, les ressources à utiliser, les livrables attendus…"></textarea>
        </div>

        <!-- Cascade RNCP (optionnel) -->
        <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:4px">
          <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:10px">
            <i class="fas fa-sitemap" style="color:var(--primary-light);margin-right:4px"></i>
            Lier au référentiel RNCP
            <span style="font-weight:400;color:var(--text-faint)">&nbsp;— optionnel</span>
          </div>
          <div style="display:grid;gap:8px">
            <select id="cas-rncp" class="form-control" onchange="cascadeAT(this.value)">
              <option value="">— Titre RNCP —</option>
              <?php foreach ($rncpTitles as $r): ?>
              <option value="<?= $r['id'] ?>"><?= e($r['rncp_code']) ?> — <?= e(mb_substr($r['title'],0,55)) ?><?= mb_strlen($r['title'])>55?'…':'' ?></option>
              <?php endforeach; ?>
            </select>
            <select id="cas-at" class="form-control" onchange="cascadeComp(this.value)" disabled>
              <option value="">— Activité type —</option>
            </select>
            <select id="cas-comp" class="form-control" onchange="cascadeSeq(this.value)" disabled>
              <option value="">— Compétence —</option>
            </select>
            <select id="cas-seq" name="sequence_id" class="form-control" onchange="cascadeMod(this.value)" disabled>
              <option value="">— Séquence (lier la séquence entière) —</option>
            </select>
            <select id="cas-mod" name="module_id" class="form-control" disabled>
              <option value="">— Séance spécifique (optionnel) —</option>
            </select>
          </div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn btn-ghost" onclick="closeEntryModal()">Annuler</button>
          <button type="submit" class="btn btn-primary" id="modal-entry-btn"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Données RNCP pour cascade (chargées côté PHP)
const _rncp  = <?= json_encode(array_values($rncpTitles),   JSON_UNESCAPED_UNICODE) ?>;
const _ats   = <?= json_encode(array_values($actTypes),     JSON_UNESCAPED_UNICODE) ?>;
const _comps = <?= json_encode(array_values($competencies), JSON_UNESCAPED_UNICODE) ?>;
const _seqs  = <?= json_encode(array_values($sequences),    JSON_UNESCAPED_UNICODE) ?>;
const _mods  = <?= json_encode(array_values($modules),      JSON_UNESCAPED_UNICODE) ?>;
const _cohortRncpId = <?= $cohort['rncp_title_id'] ? (int)$cohort['rncp_title_id'] : 'null' ?>;
const _defaultDate  = '<?= $defaultDate ?>';

function _fillSel(sel, items, valKey, labelFn) {
  const placeholder = Array.from(sel.options).slice(0,1)[0];
  sel.innerHTML = '';
  sel.appendChild(placeholder);
  items.forEach(it => {
    const o = document.createElement('option');
    o.value = it[valKey];
    o.textContent = labelFn(it);
    sel.appendChild(o);
  });
  sel.disabled = items.length === 0;
}

function cascadeAT(rncpId) {
  const f = rncpId ? _ats.filter(a => a.rncp_title_id == rncpId) : [];
  _fillSel(document.getElementById('cas-at'), f, 'id', a => a.code + ' — ' + a.title);
  cascadeComp('');
}
function cascadeComp(atId) {
  const f = atId ? _comps.filter(c => c.activity_type_id == atId) : [];
  _fillSel(document.getElementById('cas-comp'), f, 'id', c => c.code + ' — ' + c.title);
  cascadeSeq('');
}
function cascadeSeq(compId) {
  const f = compId ? _seqs.filter(s => s.competency_id == compId) : [];
  _fillSel(document.getElementById('cas-seq'), f, 'id', s => s.title);
  cascadeMod('');
}
function cascadeMod(seqId) {
  const f = seqId ? _mods.filter(m => m.sequence_id == seqId) : [];
  _fillSel(document.getElementById('cas-mod'), f, 'id', m => m.title);
}

// Remonte la chaîne depuis sequence_id → rncp_title_id
function _rncpFromSeq(seqId) {
  const seq  = _seqs.find(s => s.id == seqId); if (!seq)  return null;
  const comp = _comps.find(c => c.id == seq.competency_id); if (!comp) return null;
  const at   = _ats.find(a => a.id == comp.activity_type_id); if (!at) return null;
  return { rncp_title_id: at.rncp_title_id, at_id: at.id, comp_id: comp.id };
}

function openEntryModal(entry) {
  const isEdit = !!entry;
  document.getElementById('modal-entry-title').innerHTML = isEdit
    ? '<i class="fas fa-edit" style="color:var(--primary-light)"></i> Modifier la séance'
    : '<i class="fas fa-plus" style="color:var(--primary-light)"></i> Ajouter une séance';
  document.getElementById('entry-action').value       = isEdit ? 'edit_entry'  : 'add_entry';
  document.getElementById('entry-id').value           = isEdit ? entry.id      : '';
  document.getElementById('entry-date').value         = isEdit ? entry.scheduled_date : _defaultDate;
  document.getElementById('entry-time-start').value   = isEdit ? (entry.time_start || '') : '';
  document.getElementById('entry-time-end').value     = isEdit ? (entry.time_end   || '') : '';
  document.getElementById('entry-title-input').value  = isEdit ? entry.title    : '';
  document.getElementById('entry-instructions').value = isEdit ? (entry.instructions || '') : '';
  document.getElementById('modal-entry-btn').innerHTML = isEdit
    ? '<i class="fas fa-save"></i> Mettre à jour'
    : '<i class="fas fa-save"></i> Enregistrer';

  // Cascade RNCP
  const rncpSel = document.getElementById('cas-rncp');
  if (isEdit && entry.sequence_id) {
    const chain = _rncpFromSeq(entry.sequence_id);
    if (chain) {
      rncpSel.value = chain.rncp_title_id;
      cascadeAT(chain.rncp_title_id);
      document.getElementById('cas-at').value = chain.at_id;
      cascadeComp(chain.at_id);
      document.getElementById('cas-comp').value = chain.comp_id;
      cascadeSeq(chain.comp_id);
      document.getElementById('cas-seq').value = entry.sequence_id;
      if (entry.module_id) {
        cascadeMod(entry.sequence_id);
        document.getElementById('cas-mod').value = entry.module_id;
      } else {
        cascadeMod(entry.sequence_id);
      }
    }
  } else {
    // Pré-sélectionner le RNCP de la cohorte si défini
    rncpSel.value = _cohortRncpId || '';
    if (_cohortRncpId) cascadeAT(_cohortRncpId);
    else { cascadeAT(''); }
  }

  document.getElementById('modal-entry').style.display = 'flex';
  setTimeout(() => document.getElementById('entry-title-input').focus(), 60);
}

function closeEntryModal() {
  document.getElementById('modal-entry').style.display = 'none';
}
document.getElementById('modal-entry').addEventListener('click', e => {
  if (e.target === document.getElementById('modal-entry')) closeEntryModal();
});
</script>
<?php renderFooter(); ?>
