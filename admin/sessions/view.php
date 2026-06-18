<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();
$sid = (int)($_GET['id'] ?? 0);
if (!$sid) { setFlash('error', 'Session introuvable.'); redirect(url('admin/sessions/index.php')); }

// Charger la session
$stmt = $pdo->prepare("
    SELECT fs.*, f.title as formation_title, f.id as formation_id,
           m.title as module_title,
           CONCAT(u.first_name,' ',u.last_name) as facilitator_name
    FROM formation_sessions fs
    JOIN formations f ON fs.formation_id = f.id
    LEFT JOIN modules m ON fs.module_id = m.id
    LEFT JOIN users u ON fs.facilitator_id = u.id
    WHERE fs.id = ?
");
$stmt->execute([$sid]);
$session = $stmt->fetch();
if (!$session) { setFlash('error', 'Session introuvable.'); redirect(url('admin/sessions/index.php')); }

// Actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // Sauvegarder les présences
    if ($action === 'save_attendance') {
        $statusMap = $_POST['status'] ?? [];  // [user_id => status]
        $notesMap  = $_POST['notes'] ?? [];
        $adminId   = (int)$_SESSION['user_id'];

        foreach ($statusMap as $uid => $status) {
            $uid = (int)$uid;
            if (!in_array($status, ['present','absent','excused','late'])) continue;
            $note = trim($notesMap[$uid] ?? '');

            // Upsert
            $check = $pdo->prepare('SELECT id FROM attendance WHERE session_id=? AND user_id=?');
            $check->execute([$sid, $uid]);
            if ($check->fetch()) {
                $pdo->prepare('UPDATE attendance SET status=?, notes=?, recorded_by=? WHERE session_id=? AND user_id=?')
                    ->execute([$status, $note ?: null, $adminId, $sid, $uid]);
            } else {
                $pdo->prepare('INSERT INTO attendance (session_id, user_id, status, notes, recorded_by) VALUES (?,?,?,?,?)')
                    ->execute([$sid, $uid, $status, $note ?: null, $adminId]);
            }

            // Notifier l'étudiant si absent non justifié
            if ($status === 'absent') {
                createNotification($uid, 'Absence enregistrée',
                    'Votre absence a été enregistrée pour la session : ' . $session['title'],
                    'warning', url('student/calendar/index.php'));
            }
        }
        setFlash('success', 'Présences enregistrées.');
        redirect(url("admin/sessions/view.php?id=$sid"));
    }

    // Envoyer des convocations
    if ($action === 'send_convocations') {
        $enrolledStmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name
            FROM enrollments e
            JOIN users u ON e.user_id = u.id
            WHERE e.formation_id = ? AND e.status IN ('active','completed') AND u.status = 'active'
        ");
        $enrolledStmt->execute([$session['formation_id']]);
        $enrolled = $enrolledStmt->fetchAll();

        $dateStr = formatDate($session['date'], 'd/m/Y');
        $timeStr = $session['start_time'] ? substr($session['start_time'],0,5) : '';
        if ($session['end_time']) $timeStr .= ' — ' . substr($session['end_time'],0,5);

        $count = 0;
        foreach ($enrolled as $student) {
            createNotification(
                $student['id'],
                'Convocation : ' . $session['title'],
                "Vous êtes convoqué(e) à la session « {$session['title']} » le $dateStr" .
                ($timeStr ? " de $timeStr" : '') .
                ($session['location'] ? " — Lieu : {$session['location']}" : '') .
                ($session['meeting_url'] ? " — Lien : {$session['meeting_url']}" : ''),
                'info',
                url('student/calendar/index.php')
            );
            $count++;
        }
        setFlash('success', "$count convocation(s) envoyée(s).");
        redirect(url("admin/sessions/view.php?id=$sid"));
    }

    // Modifier la session
    if ($action === 'edit_session') {
        $title      = trim($_POST['title'] ?? '');
        $date       = $_POST['session_date'] ?? '';
        $start      = $_POST['start_time'] ?? '';
        $end        = $_POST['end_time'] ?? '';
        $location   = trim($_POST['location'] ?? '');
        $meetingUrl = trim($_POST['meeting_url'] ?? '');
        $type       = $_POST['session_type'] ?? 'onsite';
        $maxPart    = (int)($_POST['max_participants'] ?? 0) ?: null;
        $facilitatorId = (int)($_POST['facilitator_id'] ?? 0) ?: null;
        $moduleId   = (int)($_POST['module_id'] ?? 0) ?: null;
        $desc       = trim($_POST['description'] ?? '');

        if ($title && $date) {
            $pdo->prepare("UPDATE formation_sessions SET title=?, date=?, start_time=?, end_time=?, location=?,
                meeting_url=?, type=?, max_participants=?, facilitator_id=?, module_id=?, description=? WHERE id=?")
                ->execute([$title, $date, $start ?: null, $end ?: null, $location ?: null,
                    $meetingUrl ?: null, $type, $maxPart, $facilitatorId, $moduleId, $desc ?: null, $sid]);
            setFlash('success', 'Session mise à jour.');
        }
        redirect(url("admin/sessions/view.php?id=$sid"));
    }
}

// Participants inscrits à la formation
$enrolledStmt = $pdo->prepare("
    SELECT u.id, u.first_name, u.last_name, u.email, u.avatar,
           a.status as attendance_status, a.notes as attendance_notes, a.id as att_id
    FROM enrollments e
    JOIN users u ON e.user_id = u.id
    LEFT JOIN attendance a ON a.session_id = ? AND a.user_id = u.id
    WHERE e.formation_id = ? AND e.status IN ('active','completed') AND u.status = 'active'
    ORDER BY u.last_name, u.first_name
");
$enrolledStmt->execute([$sid, $session['formation_id']]);
$participants = $enrolledStmt->fetchAll();

// Stats présences
$presentCount = count(array_filter($participants, fn($p) => $p['attendance_status'] === 'present'));
$absentCount  = count(array_filter($participants, fn($p) => $p['attendance_status'] === 'absent'));
$lateCount    = count(array_filter($participants, fn($p) => $p['attendance_status'] === 'late'));
$excusedCount = count(array_filter($participants, fn($p) => $p['attendance_status'] === 'excused'));
$notSetCount  = count(array_filter($participants, fn($p) => !$p['attendance_status']));

// Formateurs et modules pour l'édition
$facilitators = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role IN ('teacher','admin') AND status='active' ORDER BY last_name")->fetchAll();
$modules = $pdo->prepare("SELECT id, title FROM modules WHERE formation_id=? ORDER BY order_num")->execute([$session['formation_id']]) ? [] : [];
$modStmt = $pdo->prepare("SELECT id, title FROM modules WHERE formation_id=? ORDER BY order_num");
$modStmt->execute([$session['formation_id']]);
$modules = $modStmt->fetchAll();

$isPast = $session['date'] < date('Y-m-d');
$isToday = $session['date'] === date('Y-m-d');

renderHead('Session : ' . $session['title']);
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar($session['title'], [
    ['Sessions', url('admin/sessions/index.php')],
    [$session['title'], '']
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- En-tête session -->
  <div class="page-header">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;gap:8px;margin-bottom:8px">
          <?php if ($isToday): ?><span class="badge badge-success">Aujourd'hui</span>
          <?php elseif ($isPast): ?><span class="badge badge-secondary">Passée</span>
          <?php else: ?><span class="badge badge-primary">À venir</span>
          <?php endif; ?>
          <?php
            $typeLabels = ['online'=>'En ligne','onsite'=>'Présentiel','hybrid'=>'Hybride','virtual_class'=>'Classe virtuelle'];
            $typeColors = ['online'=>'var(--info)','onsite'=>'var(--primary)','hybrid'=>'var(--warning)','virtual_class'=>'var(--success)'];
            $typeIcons  = ['online'=>'fa-video','onsite'=>'fa-map-marker-alt','hybrid'=>'fa-random','virtual_class'=>'fa-desktop'];
          ?>
          <span class="badge badge-secondary" style="color:<?= $typeColors[$session['type']] ?? 'white' ?>">
            <i class="fas <?= $typeIcons[$session['type']] ?? 'fa-calendar' ?>"></i>
            <?= $typeLabels[$session['type']] ?? $session['type'] ?>
          </span>
        </div>
        <h1 style="font-size:22px;margin-bottom:4px"><?= e($session['title']) ?></h1>
        <p style="color:var(--text-muted);font-size:13px"><i class="fas fa-graduation-cap"></i> <?= e($session['formation_title']) ?></p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-secondary btn-sm" onclick="document.getElementById('modal-edit').style.display='flex'">
          <i class="fas fa-edit"></i> Modifier
        </button>
        <form method="POST" style="margin:0">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="send_convocations">
          <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Envoyer les convocations à <?= count($participants) ?> participant(s) ?')">
            <i class="fas fa-paper-plane"></i> Envoyer convocations
          </button>
        </form>
        <a href="<?= url('admin/sessions/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 320px;gap:24px;align-items:start">
    <!-- Colonne principale -->
    <div>

      <!-- Infos session -->
      <div class="card" style="margin-bottom:20px">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-info-circle"></i> Informations</h3></div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div>
              <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:700;margin-bottom:4px">Date</div>
              <div style="font-size:15px;font-weight:600"><?= formatDate($session['date'], 'l d F Y') ?></div>
            </div>
            <div>
              <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:700;margin-bottom:4px">Horaires</div>
              <div style="font-size:15px;font-weight:600">
                <?= $session['start_time'] ? substr($session['start_time'],0,5) : '—' ?>
                <?= $session['end_time'] ? ' → ' . substr($session['end_time'],0,5) : '' ?>
              </div>
            </div>
            <?php if ($session['location']): ?>
            <div>
              <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:700;margin-bottom:4px">Lieu</div>
              <div style="font-size:14px"><?= e($session['location']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($session['meeting_url']): ?>
            <div>
              <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:700;margin-bottom:4px">Lien visio</div>
              <a href="<?= e($session['meeting_url']) ?>" target="_blank" class="btn btn-sm btn-primary" style="display:inline-flex"><i class="fas fa-video"></i> Rejoindre</a>
            </div>
            <?php endif; ?>
            <?php if ($session['facilitator_name']): ?>
            <div>
              <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:700;margin-bottom:4px">Formateur</div>
              <div style="font-size:14px"><?= e($session['facilitator_name']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($session['module_title']): ?>
            <div>
              <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:700;margin-bottom:4px">Module</div>
              <div style="font-size:14px"><?= e($session['module_title']) ?></div>
            </div>
            <?php endif; ?>
          </div>
          <?php if ($session['description']): ?>
          <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:700;margin-bottom:6px">Description</div>
            <p style="font-size:14px;color:var(--text-secondary);line-height:1.6"><?= nl2br(e($session['description'])) ?></p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Feuille d'émargement -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-clipboard-list"></i> Feuille d'émargement</h3>
          <span class="badge badge-secondary"><?= count($participants) ?> participant(s)</span>
        </div>
        <?php if (empty($participants)): ?>
        <div class="card-body" style="text-align:center;color:var(--text-muted);padding:32px">
          Aucun étudiant inscrit à cette formation.
        </div>
        <?php else: ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="save_attendance">
          <div style="overflow-x:auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Étudiant</th>
                  <th style="text-align:center">Présent</th>
                  <th style="text-align:center">En retard</th>
                  <th style="text-align:center">Excusé</th>
                  <th style="text-align:center">Absent</th>
                  <th>Observations</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($participants as $p):
                  $as = $p['attendance_status'] ?? '';
                ?>
                <tr>
                  <td>
                    <div style="display:flex;align-items:center;gap:10px">
                      <div class="avatar avatar-sm" style="background:<?= getAvatarColor($p['first_name'].$p['last_name']) ?>">
                        <?= getAvatarInitials($p['first_name'], $p['last_name']) ?>
                      </div>
                      <div>
                        <div style="font-weight:600;font-size:13px"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted)"><?= e($p['email']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td style="text-align:center">
                    <input type="radio" name="status[<?= $p['id'] ?>]" value="present" <?= $as==='present'?'checked':'' ?> style="accent-color:var(--success);width:18px;height:18px;cursor:pointer">
                  </td>
                  <td style="text-align:center">
                    <input type="radio" name="status[<?= $p['id'] ?>]" value="late" <?= $as==='late'?'checked':'' ?> style="accent-color:var(--warning);width:18px;height:18px;cursor:pointer">
                  </td>
                  <td style="text-align:center">
                    <input type="radio" name="status[<?= $p['id'] ?>]" value="excused" <?= $as==='excused'?'checked':'' ?> style="accent-color:var(--info);width:18px;height:18px;cursor:pointer">
                  </td>
                  <td style="text-align:center">
                    <input type="radio" name="status[<?= $p['id'] ?>]" value="absent" <?= $as==='absent'?'checked':'' ?> style="accent-color:var(--danger);width:18px;height:18px;cursor:pointer">
                  </td>
                  <td>
                    <input type="text" name="notes[<?= $p['id'] ?>]" value="<?= e($p['attendance_notes'] ?? '') ?>"
                      class="form-control" style="padding:5px 10px;font-size:12px" placeholder="Observation...">
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
            <div style="font-size:12px;color:var(--text-muted)">
              <span style="color:var(--success)">■ <?= $presentCount ?> présents</span> &nbsp;
              <span style="color:var(--warning)">■ <?= $lateCount ?> en retard</span> &nbsp;
              <span style="color:var(--info)">■ <?= $excusedCount ?> excusés</span> &nbsp;
              <span style="color:var(--danger)">■ <?= $absentCount ?> absents</span> &nbsp;
              <?php if ($notSetCount): ?><span style="color:var(--text-faint)">■ <?= $notSetCount ?> non renseignés</span><?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer les présences</button>
          </div>
        </form>
        <?php endif; ?>
      </div>

    </div>

    <!-- Colonne droite : résumé -->
    <div style="display:flex;flex-direction:column;gap:16px">

      <!-- Stats présences -->
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-pie"></i> Présences</h3></div>
        <div class="card-body" style="padding:16px">
          <?php $total = count($participants); ?>
          <?php if ($total > 0): ?>
          <!-- Barre visuelle -->
          <div style="display:flex;height:12px;border-radius:50px;overflow:hidden;margin-bottom:16px;gap:1px">
            <?php if ($presentCount): ?><div style="flex:<?= $presentCount ?>;background:var(--success)" title="Présents"></div><?php endif; ?>
            <?php if ($lateCount): ?><div style="flex:<?= $lateCount ?>;background:var(--warning)" title="En retard"></div><?php endif; ?>
            <?php if ($excusedCount): ?><div style="flex:<?= $excusedCount ?>;background:var(--info)" title="Excusés"></div><?php endif; ?>
            <?php if ($absentCount): ?><div style="flex:<?= $absentCount ?>;background:var(--danger)" title="Absents"></div><?php endif; ?>
            <?php if ($notSetCount): ?><div style="flex:<?= $notSetCount ?>;background:var(--border)" title="Non renseignés"></div><?php endif; ?>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <?php foreach ([
              ['Présents',     $presentCount, 'var(--success)', 'fa-check-circle'],
              ['En retard',    $lateCount,    'var(--warning)', 'fa-clock'],
              ['Excusés',      $excusedCount, 'var(--info)',    'fa-envelope'],
              ['Absents',      $absentCount,  'var(--danger)',  'fa-times-circle'],
              ['Non renseignés',$notSetCount, 'var(--border)', 'fa-question-circle'],
            ] as [$label, $count, $color, $icon]):
              if ($count === 0) continue;
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between">
              <div style="display:flex;align-items:center;gap:8px;font-size:13px">
                <i class="fas <?= $icon ?>" style="color:<?= $color ?>;width:16px"></i>
                <?= $label ?>
              </div>
              <div style="display:flex;align-items:center;gap:8px">
                <span style="font-weight:700;font-size:14px"><?= $count ?></span>
                <span style="font-size:11px;color:var(--text-faint)"><?= $total > 0 ? round($count/$total*100) : 0 ?>%</span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <p style="color:var(--text-muted);font-size:13px;text-align:center">Aucun participant</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Convocations -->
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-paper-plane"></i> Convocations</h3></div>
        <div class="card-body">
          <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">
            Envoie une notification à tous les <?= count($participants) ?> participant(s) inscrit(s) à la formation avec les détails de la session.
          </p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_convocations">
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center"
              onclick="return confirm('Envoyer les convocations à <?= count($participants) ?> participant(s) ?')">
              <i class="fas fa-paper-plane"></i> Envoyer les convocations
            </button>
          </form>
          <div style="margin-top:12px;font-size:11px;color:var(--text-faint)">
            <i class="fas fa-info-circle"></i> Les participants recevront une notification dans la plateforme.
          </div>
        </div>
      </div>

      <!-- Liste participants -->
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-users"></i> Participants (<?= count($participants) ?>)</h3></div>
        <div style="display:flex;flex-direction:column">
          <?php foreach ($participants as $p):
            $as = $p['attendance_status'] ?? '';
            $statusColors = ['present'=>'var(--success)','late'=>'var(--warning)','excused'=>'var(--info)','absent'=>'var(--danger)'];
            $statusIcons  = ['present'=>'fa-check','late'=>'fa-clock','excused'=>'fa-envelope','absent'=>'fa-times'];
          ?>
          <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)">
            <div class="avatar avatar-sm" style="background:<?= getAvatarColor($p['first_name'].$p['last_name']) ?>">
              <?= getAvatarInitials($p['first_name'], $p['last_name']) ?>
            </div>
            <div style="flex:1;overflow:hidden">
              <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= e($p['first_name'] . ' ' . $p['last_name']) ?>
              </div>
            </div>
            <?php if ($as): ?>
            <i class="fas <?= $statusIcons[$as] ?>" style="color:<?= $statusColors[$as] ?>;font-size:13px" title="<?= ucfirst($as) ?>"></i>
            <?php else: ?>
            <i class="fas fa-minus" style="color:var(--border);font-size:11px" title="Non renseigné"></i>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Modal : Modifier la session -->
<div id="modal-edit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)">
  <div class="card" style="width:600px;max-width:96vw;max-height:90vh;overflow-y:auto">
    <div class="card-header">
      <h3 class="card-title">Modifier la session</h3>
      <button onclick="document.getElementById('modal-edit').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="edit_session">
        <div class="form-group">
          <label class="form-label">Intitulé <span class="required">*</span></label>
          <input type="text" name="title" class="form-control" value="<?= e($session['title']) ?>" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Date <span class="required">*</span></label>
            <input type="date" name="session_date" class="form-control" value="<?= e($session['date']) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Type</label>
            <select name="session_type" class="form-control">
              <?php foreach (['onsite'=>'Présentiel','online'=>'Distanciel','hybrid'=>'Hybride','virtual_class'=>'Classe virtuelle'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= $session['type']===$v?'selected':'' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Heure début</label>
            <input type="time" name="start_time" class="form-control" value="<?= e(substr($session['start_time']??'',0,5)) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Heure fin</label>
            <input type="time" name="end_time" class="form-control" value="<?= e(substr($session['end_time']??'',0,5)) ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Lieu</label>
            <input type="text" name="location" class="form-control" value="<?= e($session['location'] ?? '') ?>" placeholder="Salle A, Adresse...">
          </div>
          <div class="form-group">
            <label class="form-label">Lien visio</label>
            <input type="url" name="meeting_url" class="form-control" value="<?= e($session['meeting_url'] ?? '') ?>" placeholder="https://meet.google.com/...">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Formateur</label>
            <select name="facilitator_id" class="form-control">
              <option value="">— Aucun —</option>
              <?php foreach ($facilitators as $f): ?>
              <option value="<?= $f['id'] ?>" <?= $session['facilitator_id']==$f['id']?'selected':'' ?>><?= e($f['last_name'].' '.$f['first_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Module rattaché</label>
            <select name="module_id" class="form-control">
              <option value="">— Aucun —</option>
              <?php foreach ($modules as $m): ?>
              <option value="<?= $m['id'] ?>" <?= $session['module_id']==$m['id']?'selected':'' ?>><?= e($m['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Nb max participants</label>
          <input type="number" name="max_participants" class="form-control" value="<?= $session['max_participants'] ?? '' ?>" min="1">
        </div>
        <div class="form-group">
          <label class="form-label">Description / Objectifs</label>
          <textarea name="description" class="form-control" rows="4" placeholder="Objectifs, programme de la session..."><?= e($session['description'] ?? '') ?></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-edit').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('modal-edit').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>
<?php renderFooter(); ?>
