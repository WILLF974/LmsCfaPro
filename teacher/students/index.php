<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo      = getDB();
$teacherId = (int)$_SESSION['user_id'];
$search    = trim($_GET['q'] ?? '');

// Apprenants tutorés
$students = [];
try {
    $where  = ['ta.teacher_id = ?', 'ta.revoked_at IS NULL', 'u.role = ?'];
    $params = [$teacherId, 'student'];
    if ($search) {
        $where[]  = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)';
        $like      = '%' . $search . '%';
        $params    = array_merge($params, [$like, $like, $like]);
    }
    $ws = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.avatar,
               u.xp_points, u.level, u.last_activity, u.status,
               ta.assigned_at, ta.notes,
               (SELECT COUNT(*) FROM module_progress mp WHERE mp.user_id=u.id AND mp.status='completed') as modules_done,
               (SELECT COUNT(*) FROM module_progress mp WHERE mp.user_id=u.id)                           as modules_total,
               (SELECT COUNT(*) FROM quiz_attempts  qa WHERE qa.user_id=u.id AND qa.passed=1)            as quizzes_passed,
               (SELECT COUNT(*) FROM quiz_attempts  qa WHERE qa.user_id=u.id AND qa.status='completed')  as quizzes_total,
               (SELECT SUM(mp2.time_spent_seconds) FROM module_progress mp2 WHERE mp2.user_id=u.id)      as total_sec
        FROM tutor_assignments ta
        JOIN users u ON ta.student_id = u.id
        WHERE $ws
        ORDER BY ta.assigned_at DESC
    ");
    $stmt->execute($params);
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    // tutor_assignments n'existe pas encore
    $students = [];
}

renderHead('Mes filleuls');
renderSidebar('teacher');
renderTopbar('Mes filleuls', [['Enseignant', url('teacher/index.php')], ['Mes filleuls', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
      <div>
        <h1>Mes filleuls</h1>
        <p><?= count($students) ?> apprenant<?= count($students) > 1 ? 's' : '' ?> sous tutorat</p>
      </div>
    </div>
  </div>

  <!-- Recherche -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div class="search-input" style="flex:1;min-width:200px">
          <i class="fas fa-search"></i>
          <input type="text" name="q" placeholder="Rechercher un apprenant..." value="<?= e($search) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
        <?php if ($search): ?><a href="<?= url('teacher/students/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
      </form>
    </div>
  </div>

  <?php if (empty($students)): ?>
  <div class="empty-state">
    <div class="icon"><i class="fas fa-chalkboard-teacher"></i></div>
    <h3>Aucun filleul</h3>
    <p>Vous n'avez pas encore d'apprenant sous tutorat.<br>L'équipe pédagogique peut vous en assigner depuis la liste des apprenants.</p>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
    <?php foreach ($students as $s):
      $modsDone  = (int)$s['modules_done'];
      $modsTotal = (int)$s['modules_total'];
      $pct       = $modsTotal > 0 ? round($modsDone / $modsTotal * 100) : 0;
      $totalSec  = (int)$s['total_sec'];
      $timeLabel = $totalSec > 3600 ? round($totalSec/3600,1).'h' : ($totalSec > 0 ? round($totalSec/60).' min' : '—');
      $isActive  = $s['last_activity'] && strtotime($s['last_activity']) > strtotime('-7 days');
    ?>
    <div class="card">
      <div class="card-body" style="padding:20px">

        <!-- En-tête étudiant -->
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
          <div class="avatar" style="width:48px;height:48px;font-size:16px;flex-shrink:0;background:<?= getAvatarColor($s['first_name'].$s['last_name']) ?>">
            <?php if ($s['avatar'] && file_exists(UPLOADS_PATH.'/avatars/'.$s['avatar'])): ?>
            <img src="<?= e(uploadUrl('avatars/'.$s['avatar'])) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%">
            <?php else: ?><?= getAvatarInitials($s['first_name'], $s['last_name']) ?><?php endif; ?>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:14px;color:white;margin-bottom:2px"><?= e($s['first_name'].' '.$s['last_name']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($s['email']) ?></div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:4px">
              <?= getStatusBadge($s['status']) ?>
              <span style="font-size:10px;color:var(--warning);font-weight:700"><i class="fas fa-bolt"></i> Niv.<?= $s['level'] ?> · <?= number_format($s['xp_points']) ?> XP</span>
            </div>
          </div>
        </div>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;text-align:center">
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:10px 6px">
            <div style="font-size:18px;font-weight:800;color:<?= $pct===100?'var(--success)':'var(--primary-light)' ?>"><?= $pct ?>%</div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px">Progression</div>
          </div>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:10px 6px">
            <div style="font-size:18px;font-weight:800;color:white"><?= $modsDone ?>/<?= $modsTotal ?></div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px">Séances</div>
          </div>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:10px 6px">
            <div style="font-size:18px;font-weight:800;color:var(--success)"><?= $s['quizzes_passed'] ?>/<?= $s['quizzes_total'] ?></div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px">Quiz réussis</div>
          </div>
        </div>

        <!-- Barre de progression -->
        <div style="height:5px;background:var(--bg-hover);border-radius:99px;margin-bottom:12px;overflow:hidden">
          <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct===100?'var(--success)':'var(--primary)' ?>;border-radius:99px;transition:width .4s"></div>
        </div>

        <!-- Infos bas de carte -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
          <div style="font-size:11px;color:var(--text-faint)">
            <i class="fas fa-circle" style="font-size:8px;color:<?= $isActive?'var(--success)':'var(--text-faint)' ?>"></i>
            <?= $s['last_activity'] ? 'Actif ' . timeAgo($s['last_activity']) : 'Jamais connecté' ?>
          </div>
          <div style="font-size:11px;color:var(--text-faint)"><i class="fas fa-clock"></i> <?= $timeLabel ?></div>
        </div>

        <?php if ($s['notes']): ?>
        <div style="font-size:11px;color:var(--text-muted);background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);border-radius:var(--radius);padding:8px 10px;margin-bottom:12px">
          <i class="fas fa-sticky-note" style="color:var(--primary-light);margin-right:4px"></i><?= e($s['notes']) ?>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div style="display:flex;gap:8px">
          <a href="<?= url('admin/users/progress.php?id='.$s['id']) ?>" class="btn btn-primary btn-sm" style="flex:1;justify-content:center">
            <i class="fas fa-chart-line"></i> Suivi pédagogique
          </a>
          <div style="font-size:10px;color:var(--text-faint);display:flex;align-items:center;white-space:nowrap">
            Tutorat depuis <?= formatDate($s['assigned_at']) ?>
          </div>
        </div>

      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
<?php renderFooter(); ?>
