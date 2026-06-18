<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$search = trim($_GET['q'] ?? '');
$fId    = (int)($_GET['formation_id'] ?? 0);
$page   = max(1, (int)($_GET['page'] ?? 1));

// Formations où l'enseignant a des capsules
$myFormations = $pdo->prepare("SELECT DISTINCT f.id, f.title FROM formations f JOIN modules m ON m.formation_id = f.id JOIN lessons l ON l.module_id = m.id WHERE l.created_by = ? ORDER BY f.title");
$myFormations->execute([$userId]);
$myFormations = $myFormations->fetchAll();

// Étudiants inscrits dans ces formations
$fIds = array_column($myFormations, 'id');
if (empty($fIds)) {
    $students = [];
    $p = paginate(0, 20, 1);
} else {
    $inPlaceholders = implode(',', array_fill(0, count($fIds), '?'));
    $where  = ["e.formation_id IN ($inPlaceholders)", 'u.role = ?', 'e.status IN (\'active\',\'completed\')'];
    $params = array_merge($fIds, ['student']);

    if ($search) { $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)'; $like = '%'.$search.'%'; $params = array_merge($params, [$like,$like,$like]); }
    if ($fId)    { $where[] = 'e.formation_id = ?'; $params[] = $fId; }
    $ws = implode(' AND ', $where);

    $total = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM enrollments e JOIN users u ON e.user_id = u.id WHERE $ws");
    $total->execute($params);
    $p = paginate((int)$total->fetchColumn(), 20, $page);

    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.avatar, u.xp_points, u.level, u.last_activity,
               COUNT(DISTINCT e.formation_id) as formation_count,
               ROUND(AVG(e.progress_percent)) as avg_progress,
               (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON lp.lesson_id = l.id JOIN modules m ON l.module_id = m.id WHERE lp.user_id = u.id AND l.created_by = ? AND lp.status = 'completed') as lessons_done
        FROM enrollments e
        JOIN users u ON e.user_id = u.id
        WHERE $ws
        GROUP BY u.id
        ORDER BY u.last_name
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge([$userId], $params, [$p['perPage'], $p['offset']]));
    $students = $stmt->fetchAll();
}

renderHead('Mes étudiants');
renderSidebar('teacher');
renderTopbar('Mes étudiants', [['Enseignant', url('teacher/index.php')], ['Étudiants', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div><h1>Mes étudiants</h1><p><?= $p['total'] ?> apprenant(s) dans mes formations</p></div>
    </div>
  </div>

  <!-- Filtres -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div class="search-input" style="flex:1;min-width:200px">
          <i class="fas fa-search"></i>
          <input type="text" name="q" placeholder="Rechercher un étudiant..." value="<?= e($search) ?>">
        </div>
        <?php if (!empty($myFormations)): ?>
        <select name="formation_id" class="form-control" style="width:220px">
          <option value="">Toutes mes formations</option>
          <?php foreach ($myFormations as $f): ?>
          <option value="<?= $f['id'] ?>" <?= $fId == $f['id'] ? 'selected' : '' ?>><?= e(mb_substr($f['title'],0,35)) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
        <?php if ($search || $fId): ?><a href="<?= url('teacher/students/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
      </form>
    </div>
  </div>

  <?php if (empty($students)): ?>
  <div class="empty-state">
    <div class="icon">🎓</div>
    <h3>Aucun étudiant</h3>
    <p>Vos formations n'ont pas encore d'apprenants inscrits.</p>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px">
    <?php foreach ($students as $s): ?>
    <div class="card">
      <div class="card-body">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
          <div class="avatar" style="background:<?= getAvatarColor($s['first_name'].$s['last_name']) ?>">
            <?php if ($s['avatar'] && file_exists(UPLOADS_PATH.'/avatars/'.$s['avatar'])): ?>
            <img src="<?= e(uploadUrl('avatars/'.$s['avatar'])) ?>" alt="">
            <?php else: ?><?= getAvatarInitials($s['first_name'],$s['last_name']) ?><?php endif; ?>
          </div>
          <div style="flex:1;overflow:hidden">
            <div style="font-weight:700;font-size:14px"><?= e($s['first_name'].' '.$s['last_name']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($s['email']) ?></div>
          </div>
          <div style="text-align:right">
            <div style="font-size:13px;font-weight:800;color:var(--warning)">Niv. <?= $s['level'] ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= number_format($s['xp_points']) ?> XP</div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;text-align:center">
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:white"><?= $s['formation_count'] ?></div>
            <div style="font-size:10px;color:var(--text-muted)">Formations</div>
          </div>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:white"><?= $s['lessons_done'] ?></div>
            <div style="font-size:10px;color:var(--text-muted)">Capsules</div>
          </div>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:var(--success)"><?= $s['avg_progress'] ?? 0 ?>%</div>
            <div style="font-size:10px;color:var(--text-muted)">Progression</div>
          </div>
        </div>

        <div class="progress-bar" style="height:6px;margin-bottom:8px"><div class="progress-fill" style="width:<?= $s['avg_progress'] ?? 0 ?>%"></div></div>

        <div style="font-size:11px;color:var(--text-faint)">
          <i class="fas fa-circle" style="font-size:8px;color:<?= $s['last_activity'] && strtotime($s['last_activity']) > strtotime('-7 days') ? 'var(--success)' : 'var(--text-faint)' ?>"></i>
          <?= $s['last_activity'] ? 'Actif ' . timeAgo($s['last_activity']) : 'Jamais connecté' ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?= $p['totalPages'] > 1 ? renderPagination($p, url('teacher/students/index.php?' . http_build_query(array_filter(['q'=>$search,'formation_id'=>$fId])))) : '' ?>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
