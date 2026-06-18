<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();
$isPedagogyOnly = !isAdmin(); // pedagogy mais pas admin

// Actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($_POST['action'] === 'validate') {
        $pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$userId]);
        $pdo->prepare("UPDATE enrollments SET status='active', validated_by=?, validated_at=NOW() WHERE user_id=? AND status='pending'")
            ->execute([$_SESSION['user_id'], $userId]);
        createNotification($userId, 'Compte validé !', 'Votre compte a été validé. Vous pouvez vous connecter.', 'success', url('index.php'));
        auditLog('user_validated', 'user', $userId);
        setFlash('success', 'Étudiant validé.');
    } elseif ($_POST['action'] === 'suspend') {
        $pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$userId]);
        auditLog('user_suspended', 'user', $userId);
        setFlash('success', 'Utilisateur suspendu.');
    } elseif ($_POST['action'] === 'delete') {
        $pdo->prepare("UPDATE users SET status='inactive' WHERE id=?")->execute([$userId]);
        setFlash('success', 'Utilisateur désactivé.');
    } elseif ($_POST['action'] === 'change_role' && isAdmin()) {
        $newRole = $_POST['role'] ?? 'student';
        if (in_array($newRole, ['admin','pedagogy','teacher','student'])) {
            $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$newRole, $userId]);
            setFlash('success', 'Rôle mis à jour.');
        }
    } elseif ($_POST['action'] === 'validate_enrollments') {
        $n = $pdo->prepare("UPDATE enrollments SET status='active', validated_by=?, validated_at=NOW() WHERE user_id=? AND status='pending'");
        $n->execute([$_SESSION['user_id'], $userId]);
        $count = $n->rowCount();
        createNotification($userId, 'Inscription(s) validée(s)', $count . ' inscription(s) ont été validées.', 'success');
        auditLog('enrollments_validated', 'user', $userId);
        setFlash('success', $count . ' inscription(s) validée(s) pour cet étudiant.');
    } elseif ($_POST['action'] === 'enroll') {
        $formationId = (int)($_POST['formation_id'] ?? 0);
        if ($userId && $formationId) {
            $check = $pdo->prepare('SELECT id FROM enrollments WHERE user_id=? AND formation_id=?');
            $check->execute([$userId, $formationId]);
            if ($check->fetch()) {
                setFlash('warning', 'Cet étudiant est déjà inscrit à cette formation.');
            } else {
                $pdo->prepare('INSERT INTO enrollments (user_id, formation_id, status, validated_by, validated_at) VALUES (?,?,?,?,NOW())')
                    ->execute([$userId, $formationId, 'active', $_SESSION['user_id']]);
                $f = $pdo->prepare('SELECT title FROM formations WHERE id=?'); $f->execute([$formationId]); $ft = $f->fetchColumn();
                createNotification($userId, 'Inscription validée', 'Vous êtes inscrit à : ' . $ft, 'success');
                auditLog('enrollment_created', 'enrollment', $userId);
                setFlash('success', 'Étudiant inscrit à la formation.');
            }
        }
    }
    $qs = http_build_query(array_filter(['role' => $_GET['role'] ?? '', 'status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? '']));
    redirect(url('admin/users/index.php' . ($qs ? '?' . $qs : '')));
}

// Filtres — par défaut students pour pédagogie
$role   = $_GET['role'] ?? ($isPedagogyOnly ? 'student' : '');
$status = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));

$where = ['1=1'];
$params = [];
if ($role)   { $where[] = 'u.role = ?';   $params[] = $role; }
if ($status) { $where[] = 'u.status = ?'; $params[] = $status; }
if ($search) {
    $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)';
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
}
$whereStr = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereStr");
$total->execute($params);
$p = paginate((int)$total->fetchColumn(), ITEMS_PER_PAGE, $page);

$stmt = $pdo->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM enrollments e WHERE e.user_id=u.id) as enrollment_count,
           (SELECT COUNT(*) FROM enrollments e WHERE e.user_id=u.id AND e.status='pending') as pending_enrollments
    FROM users u WHERE $whereStr ORDER BY u.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
$users = $stmt->fetchAll();

// Formations pour le modal inscription
$formations = $pdo->query("SELECT id, title FROM formations WHERE status='active' ORDER BY title")->fetchAll();

$pageTitle = $isPedagogyOnly ? 'Apprenants' : 'Gestion des utilisateurs';
renderHead($pageTitle);
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar($pageTitle, [
    [isAdmin() ? 'Admin' : 'Pédagogie', url(isAdmin() ? 'admin/index.php' : 'pedagogy/index.php')],
    [$pageTitle, '']
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div>
        <h1><?= $pageTitle ?></h1>
        <p><?= $p['total'] ?> apprenant<?= $p['total'] > 1 ? 's' : '' ?> trouvé<?= $p['total'] > 1 ? 's' : '' ?></p>
      </div>
      <?php if (isAdmin()): ?>
      <a href="<?= url('admin/users/create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px">
      <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div class="search-input" style="flex:1;min-width:200px">
          <i class="fas fa-search"></i>
          <input type="text" name="q" placeholder="Chercher par nom, email..." value="<?= e($search) ?>">
        </div>
        <?php if (isAdmin()): ?>
        <select name="role" class="form-control" style="width:160px">
          <option value="">Tous les rôles</option>
          <option value="admin"     <?= $role==='admin'?'selected':'' ?>>Administrateur</option>
          <option value="pedagogy"  <?= $role==='pedagogy'?'selected':'' ?>>Pédagogie</option>
          <option value="teacher"   <?= $role==='teacher'?'selected':'' ?>>Enseignant</option>
          <option value="student"   <?= $role==='student'?'selected':'' ?>>Étudiant</option>
        </select>
        <?php else: ?>
        <input type="hidden" name="role" value="student">
        <?php endif; ?>
        <select name="status" class="form-control" style="width:160px">
          <option value="">Tous les statuts</option>
          <option value="active"    <?= $status==='active'?'selected':'' ?>>Actif</option>
          <option value="pending"   <?= $status==='pending'?'selected':'' ?>>En attente</option>
          <option value="suspended" <?= $status==='suspended'?'selected':'' ?>>Suspendu</option>
          <option value="inactive"  <?= $status==='inactive'?'selected':'' ?>>Inactif</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrer</button>
        <?php if ($search || $status || ($role && $role !== 'student')): ?>
        <a href="<?= url('admin/users/index.php?role=student') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Reset</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-container">
      <table class="table">
        <thead>
          <tr>
            <th>Utilisateur</th>
            <th>Rôle</th>
            <th>Statut</th>
            <th>Inscriptions</th>
            <th>XP / Niveau</th>
            <th>Dernière connexion</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:12px">
                <div class="avatar avatar-md" style="background:<?= e(getAvatarColor($u['first_name'].$u['last_name'])) ?>">
                  <?php if ($u['avatar'] && file_exists(UPLOADS_PATH.'/avatars/'.$u['avatar'])): ?>
                  <img src="<?= e(uploadUrl('avatars/'.$u['avatar'])) ?>" alt="">
                  <?php else: ?><?= e(getAvatarInitials($u['first_name'], $u['last_name'])) ?><?php endif; ?>
                </div>
                <div>
                  <div style="font-weight:700;color:white"><?= e($u['first_name'].' '.$u['last_name']) ?></div>
                  <div style="font-size:12px;color:var(--text-muted)"><?= e($u['email']) ?></div>
                </div>
              </div>
            </td>
            <td><?= getRoleBadge($u['role']) ?></td>
            <td><?= getStatusBadge($u['status']) ?></td>
            <td style="font-size:14px;font-weight:600"><?= $u['enrollment_count'] ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#f59e0b,#f97316);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#0d1117"><?= getLevel((int)$u['xp_points']) ?></div>
                <span style="font-size:13px;color:var(--text-muted)"><?= number_format((int)$u['xp_points']) ?> XP</span>
              </div>
            </td>
            <td style="font-size:12px;color:var(--text-muted)"><?= $u['last_login'] ? timeAgo($u['last_login']) : 'Jamais' ?></td>
            <td style="text-align:right">
              <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">
                <?php if ($u['status'] === 'pending'): ?>
                <form method="POST" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <input type="hidden" name="action" value="validate">
                  <button type="submit" class="btn btn-success btn-sm" title="Activer le compte"><i class="fas fa-user-check"></i> Activer compte</button>
                </form>
                <?php endif; ?>
                <?php if ($u['role'] === 'student' && ($u['pending_enrollments'] ?? 0) > 0): ?>
                <form method="POST" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <input type="hidden" name="action" value="validate_enrollments">
                  <button type="submit" class="btn btn-warning btn-sm" title="Valider les inscriptions en attente">
                    <i class="fas fa-graduation-cap"></i> Valider inscriptions (<?= $u['pending_enrollments'] ?>)
                  </button>
                </form>
                <?php endif; ?>
                <?php if ($u['role'] === 'student' && $u['status'] === 'active' && !empty($formations)): ?>
                <button class="btn btn-primary btn-sm" onclick="openEnrollModal(<?= $u['id'] ?>, '<?= e(addslashes($u['first_name'].' '.$u['last_name'])) ?>')" title="Inscrire à une formation">
                  <i class="fas fa-graduation-cap"></i> Inscrire
                </button>
                <?php endif; ?>
                <?php if ($u['role'] === 'student'): ?>
                <a href="<?= url('admin/users/progress.php?id='.$u['id']) ?>" class="btn btn-ghost btn-sm" title="Suivi pédagogique" style="color:var(--primary-light)"><i class="fas fa-chart-line"></i></a>
                <?php endif; ?>
                <a href="<?= url('admin/users/edit.php?id='.$u['id']) ?>" class="btn btn-ghost btn-sm" title="Modifier"><i class="fas fa-edit"></i></a>
                <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                <div class="dropdown">
                  <button class="btn btn-ghost btn-sm" data-dropdown="user-actions-<?= $u['id'] ?>"><i class="fas fa-ellipsis-v"></i></button>
                  <div class="dropdown-menu" id="user-actions-<?= $u['id'] ?>">
                    <?php if ($u['status'] === 'active'): ?>
                    <form method="POST">
                      <?= csrfField() ?>
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <input type="hidden" name="action" value="suspend">
                      <button class="dropdown-item" type="submit" style="width:100%;border:none;cursor:pointer;background:none;text-align:left"><i class="fas fa-ban" style="color:var(--warning)"></i> Suspendre</button>
                    </form>
                    <?php else: ?>
                    <form method="POST">
                      <?= csrfField() ?>
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <input type="hidden" name="action" value="validate">
                      <button class="dropdown-item" type="submit" style="width:100%;border:none;cursor:pointer;background:none;text-align:left"><i class="fas fa-check" style="color:var(--success)"></i> Activer</button>
                    </form>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <div class="dropdown-divider"></div>
                    <form method="POST" onsubmit="return confirm('Désactiver cet utilisateur ?')">
                      <?= csrfField() ?>
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <input type="hidden" name="action" value="delete">
                      <button class="dropdown-item" type="submit" style="width:100%;border:none;cursor:pointer;background:none;text-align:left;color:var(--danger)"><i class="fas fa-trash"></i> Désactiver</button>
                    </form>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($users)): ?>
          <tr><td colspan="7"><div class="empty-state"><div class="icon"><i class="fas fa-users"></i></div><h3>Aucun utilisateur trouvé</h3><p>Modifiez vos filtres ou ajoutez un utilisateur.</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($p['totalPages'] > 1): ?>
    <div class="card-footer">
      <?= renderPagination($p, url('admin/users/index.php?'.http_build_query(array_filter(compact('role','status','search'))))) ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<!-- Modal : Inscrire à une formation -->
<?php if (!empty($formations)): ?>
<div id="modal-enroll" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:460px;max-width:95vw">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-graduation-cap"></i> Inscrire <span id="enroll-name"></span></h3>
      <button onclick="document.getElementById('modal-enroll').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <form method="POST" id="enroll-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="enroll">
        <input type="hidden" name="user_id" id="enroll-user-id">
        <div class="form-group">
          <label class="form-label">Formation <span class="required">*</span></label>
          <select name="formation_id" class="form-control" required>
            <option value="">— Choisir une formation —</option>
            <?php foreach ($formations as $f): ?>
            <option value="<?= $f['id'] ?>"><?= e($f['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-enroll').style.display='none'">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Inscrire</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function openEnrollModal(userId, userName) {
  document.getElementById('enroll-user-id').value = userId;
  document.getElementById('enroll-name').textContent = userName;
  document.getElementById('modal-enroll').style.display = 'flex';
}
document.getElementById('modal-enroll').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>
<?php endif; ?>
<?php renderFooter(); ?>
