<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();

// Charger la table formation_sessions si elle existe
try {
    $pdo->query('SELECT 1 FROM formation_sessions LIMIT 1');
    $tableExists = true;
} catch (PDOException $e) {
    $tableExists = false;
}

// Actions POST
if ($tableExists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_session') {
        $fId        = (int)($_POST['formation_id'] ?? 0);
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

        if ($fId && $title && $date) {
            $pdo->prepare('INSERT INTO formation_sessions
                (formation_id, module_id, title, type, date, start_time, end_time, location, meeting_url, description, facilitator_id, max_participants)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$fId, $moduleId, $title, $type, $date,
                    $start ?: null, $end ?: null,
                    $location ?: null, $meetingUrl ?: null,
                    $desc ?: null, $facilitatorId, $maxPart]);

            $newId = (int)$pdo->lastInsertId();

            // Notifier les inscrits
            $enrolled = $pdo->prepare("SELECT u.id FROM enrollments e JOIN users u ON e.user_id=u.id WHERE e.formation_id=? AND e.status IN ('active','completed') AND u.status='active'");
            $enrolled->execute([$fId]);
            foreach ($enrolled->fetchAll() as $student) {
                createNotification($student['id'],
                    'Nouvelle session planifiée',
                    "Une nouvelle session « $title » est planifiée le " . formatDate($date, 'd/m/Y') . ($start ? ' à ' . substr($start,0,5) : '') . '.',
                    'info', url('student/calendar/index.php'));
            }

            setFlash('success', 'Session créée et apprenants notifiés.');
            redirect(url("admin/sessions/view.php?id=$newId"));
        }
        redirect(url('admin/sessions/index.php'));
    }

    if ($action === 'delete_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $pdo->prepare('DELETE FROM attendance WHERE session_id=?')->execute([$sid]);
        $pdo->prepare('DELETE FROM formation_sessions WHERE id=?')->execute([$sid]);
        setFlash('success', 'Session supprimée.');
        redirect(url('admin/sessions/index.php'));
    }
}

$fId  = (int)($_GET['formation_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));

if ($tableExists) {
    $where  = '1=1';
    $params = [];
    if ($fId) { $where = 'fs.formation_id = ?'; $params[] = $fId; }

    $total = $pdo->prepare("SELECT COUNT(*) FROM formation_sessions fs WHERE $where");
    $total->execute($params);
    $p = paginate((int)$total->fetchColumn(), 20, $page);

    $stmt = $pdo->prepare("
        SELECT fs.*, f.title as formation_title,
               CONCAT(u.first_name,' ',u.last_name) as facilitator_name,
               (SELECT COUNT(*) FROM attendance a WHERE a.session_id = fs.id AND a.status = 'present') as present_count,
               (SELECT COUNT(*) FROM attendance a WHERE a.session_id = fs.id) as total_attendance
        FROM formation_sessions fs
        JOIN formations f ON fs.formation_id = f.id
        LEFT JOIN users u ON fs.facilitator_id = u.id
        WHERE $where
        ORDER BY fs.date DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
    $sessions = $stmt->fetchAll();
} else {
    $sessions = [];
    $p = paginate(0, 20, 1);
}

$formations   = $pdo->query("SELECT id, title FROM formations WHERE status='active' ORDER BY title")->fetchAll();
$facilitators = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role IN ('teacher','admin') AND status='active' ORDER BY last_name")->fetchAll();

// Modules par formation (pour le JS)
$allModulesStmt = $pdo->query("SELECT id, formation_id, title FROM modules ORDER BY formation_id, order_num");
$allModules = $allModulesStmt->fetchAll();
$modulesByFormation = [];
foreach ($allModules as $m) {
    $modulesByFormation[$m['formation_id']][] = ['id'=>$m['id'],'title'=>$m['title']];
}

renderHead('Sessions');
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar('Sessions présentiel / distanciel', [['Admin', url('admin/index.php')], ['Sessions', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <?php if (!$tableExists): ?>
  <div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i> La table <code>formation_sessions</code> n'existe pas encore.
  </div>
  <?php else: ?>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div><h1>Sessions</h1><p><?= $p['total'] ?> session(s)</p></div>
      <button class="btn btn-primary" onclick="document.getElementById('modal-session').style.display='flex'">
        <i class="fas fa-plus"></i> Planifier une session
      </button>
    </div>
  </div>

  <!-- Filtre formation -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <form method="GET" style="display:flex;gap:12px;align-items:center">
        <select name="formation_id" class="form-control" style="flex:1;max-width:320px">
          <option value="">— Toutes les formations —</option>
          <?php foreach ($formations as $f): ?>
          <option value="<?= $f['id'] ?>" <?= $fId == $f['id'] ? 'selected' : '' ?>><?= e($f['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
        <?php if ($fId): ?><a href="<?= url('admin/sessions/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
      </form>
    </div>
  </div>

  <?php if (empty($sessions)): ?>
  <div class="empty-state">
    <div class="icon">📅</div>
    <h3>Aucune session planifiée</h3>
    <p>Créez vos sessions de formation présentiel ou distanciel.</p>
    <button class="btn btn-primary" onclick="document.getElementById('modal-session').style.display='flex'">Planifier une session</button>
  </div>
  <?php else: ?>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="table">
        <thead>
          <tr>
            <th>Session</th>
            <th>Formation</th>
            <th>Date</th>
            <th>Horaires</th>
            <th>Type</th>
            <th>Présences</th>
            <th>Formateur</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $typeLabels = ['online'=>'En ligne','onsite'=>'Présentiel','hybrid'=>'Hybride','virtual_class'=>'Classe virtuelle'];
          $typeIcons  = ['online'=>'fa-video','onsite'=>'fa-map-marker-alt','hybrid'=>'fa-random','virtual_class'=>'fa-desktop'];
          foreach ($sessions as $s):
            $isPast  = $s['date'] < date('Y-m-d');
            $isToday = $s['date'] === date('Y-m-d');
          ?>
          <tr>
            <td>
              <div style="font-weight:600"><a href="<?= url('admin/sessions/view.php?id='.$s['id']) ?>" style="color:white;text-decoration:none"><?= e($s['title']) ?></a></div>
              <?php if ($isToday): ?><span style="font-size:11px;color:var(--success)">Aujourd'hui</span>
              <?php elseif ($isPast): ?><span style="font-size:11px;color:var(--text-faint)">Passée</span>
              <?php else: ?><span style="font-size:11px;color:var(--info)">À venir</span>
              <?php endif; ?>
            </td>
            <td style="font-size:13px"><?= e(mb_substr($s['formation_title'],0,35)) ?></td>
            <td style="font-weight:600"><?= formatDate($s['date'], 'd/m/Y') ?></td>
            <td style="font-size:13px">
              <?= $s['start_time'] ? substr($s['start_time'],0,5) : '—' ?>
              <?= $s['end_time'] ? ' → ' . substr($s['end_time'],0,5) : '' ?>
            </td>
            <td>
              <span class="badge badge-secondary">
                <i class="fas <?= $typeIcons[$s['type']] ?? 'fa-calendar' ?>"></i>
                <?= $typeLabels[$s['type']] ?? $s['type'] ?>
              </span>
            </td>
            <td>
              <?php if ($s['total_attendance'] > 0): ?>
              <div style="font-size:13px"><?= $s['present_count'] ?>/<?= $s['total_attendance'] ?> présents</div>
              <div class="progress-bar" style="height:4px;margin-top:4px"><div class="progress-fill" style="width:<?= round($s['present_count']/$s['total_attendance']*100) ?>%"></div></div>
              <?php else: ?>
              <span style="color:var(--text-faint);font-size:12px">Non renseigné</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--text-muted)"><?= e($s['facilitator_name'] ?? '—') ?></td>
            <td>
              <div style="display:flex;gap:4px">
                <a href="<?= url('admin/sessions/view.php?id='.$s['id']) ?>" class="btn btn-secondary btn-sm" title="Voir la session"><i class="fas fa-eye"></i></a>
                <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer cette session et ses présences ?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_session">
                  <input type="hidden" name="session_id" value="<?= $s['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?= $p['totalPages'] > 1 ? renderPagination($p, url('admin/sessions/index.php?' . ($fId ? 'formation_id='.$fId.'&' : ''))) : '' ?>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Modal : Planifier une session -->
<div id="modal-session" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)">
  <div class="card" style="width:620px;max-width:96vw;max-height:90vh;overflow-y:auto">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-calendar-plus"></i> Planifier une session</h3>
      <button onclick="document.getElementById('modal-session').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_session">

        <div class="form-group">
          <label class="form-label">Formation <span class="required">*</span></label>
          <select name="formation_id" id="sel-formation" class="form-control" required onchange="loadModules(this.value)">
            <option value="">— Choisir —</option>
            <?php foreach ($formations as $f): ?>
            <option value="<?= $f['id'] ?>"><?= e($f['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Module rattaché <span style="color:var(--text-faint)">(optionnel)</span></label>
          <select name="module_id" id="sel-module" class="form-control">
            <option value="">— Sélectionnez d'abord une formation —</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Intitulé de la session <span class="required">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="Ex : Cours magistral — Module 1" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Date <span class="required">*</span></label>
            <input type="date" name="session_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Type</label>
            <select name="session_type" id="sel-type" class="form-control" onchange="toggleMeetingUrl()">
              <option value="onsite">Présentiel</option>
              <option value="online">Distanciel</option>
              <option value="hybrid">Hybride</option>
              <option value="virtual_class">Classe virtuelle</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Heure début</label>
            <input type="time" name="start_time" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Heure fin</label>
            <input type="time" name="end_time" class="form-control">
          </div>
        </div>

        <div class="form-group" id="row-location">
          <label class="form-label">Lieu</label>
          <input type="text" name="location" class="form-control" placeholder="Salle A, 12 rue de la Paix...">
        </div>

        <div class="form-group" id="row-meeting-url" style="display:none">
          <label class="form-label">Lien de connexion</label>
          <input type="url" name="meeting_url" class="form-control" placeholder="https://meet.google.com/...">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Formateur</label>
            <select name="facilitator_id" class="form-control">
              <option value="">— Aucun —</option>
              <?php foreach ($facilitators as $f): ?>
              <option value="<?= $f['id'] ?>"><?= e($f['last_name'].' '.$f['first_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Nb max participants</label>
            <input type="number" name="max_participants" class="form-control" placeholder="20" min="1" max="999">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description / Objectifs</label>
          <textarea name="description" class="form-control" rows="3" placeholder="Objectifs pédagogiques, programme détaillé..."></textarea>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-session').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Créer la session</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Modules par formation
const modulesByFormation = <?= json_encode($modulesByFormation) ?>;

function loadModules(formationId) {
  const sel = document.getElementById('sel-module');
  sel.innerHTML = '<option value="">— Aucun —</option>';
  const mods = modulesByFormation[formationId] || [];
  mods.forEach(m => {
    const opt = document.createElement('option');
    opt.value = m.id;
    opt.textContent = m.title;
    sel.appendChild(opt);
  });
}

function toggleMeetingUrl() {
  const type = document.getElementById('sel-type').value;
  const isOnline = type === 'online' || type === 'virtual_class';
  document.getElementById('row-location').style.display   = isOnline ? 'none' : '';
  document.getElementById('row-meeting-url').style.display = isOnline ? '' : 'none';
}

document.getElementById('modal-session').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>
<?php renderFooter(); ?>
