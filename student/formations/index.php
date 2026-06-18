<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$tab    = $_GET['tab'] ?? 'enrolled'; // enrolled | catalog

// Mes inscriptions
$enrolledStmt = $pdo->prepare("
    SELECT e.*, f.id as f_id, f.title, f.description, f.thumbnail, f.duration_hours, f.start_date, f.end_date,
           r.rncp_code, r.level as rncp_level,
           (SELECT COUNT(*) FROM modules m WHERE m.formation_id = f.id) as module_count,
           (SELECT COUNT(*) FROM lessons l JOIN modules m ON l.module_id = m.id WHERE m.formation_id = f.id AND l.is_mandatory = 1) as total_lessons,
           (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON lp.lesson_id = l.id JOIN modules m ON l.module_id = m.id WHERE m.formation_id = f.id AND lp.user_id = ? AND lp.status = 'completed') as completed_lessons
    FROM enrollments e
    JOIN formations f ON e.formation_id = f.id
    LEFT JOIN rncp_titles r ON f.rncp_title_id = r.id
    WHERE e.user_id = ? AND e.status IN ('active','completed','pending')
    ORDER BY e.enrolled_at DESC
");
$enrolledStmt->execute([$userId, $userId]);
$enrolled = $enrolledStmt->fetchAll();

// Catalogue (formations actives où pas inscrit)
$catalogStmt = $pdo->prepare("
    SELECT f.*, r.rncp_code, r.level as rncp_level,
           (SELECT COUNT(*) FROM enrollments e WHERE e.formation_id = f.id AND e.status = 'active') as student_count,
           (SELECT COUNT(*) FROM modules m WHERE m.formation_id = f.id) as module_count,
           (SELECT COUNT(*) FROM lessons l JOIN modules m ON l.module_id = m.id WHERE m.formation_id = f.id) as lesson_count
    FROM formations f
    LEFT JOIN rncp_titles r ON f.rncp_title_id = r.id
    WHERE f.status = 'active'
      AND f.id NOT IN (SELECT formation_id FROM enrollments WHERE user_id = ?)
    ORDER BY f.created_at DESC
");
$catalogStmt->execute([$userId]);
$catalog = $catalogStmt->fetchAll();

// Demande d'inscription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_enrollment') {
    requireCsrf();
    $fId = (int)($_POST['formation_id'] ?? 0);
    if ($fId) {
        $check = $pdo->prepare('SELECT id FROM enrollments WHERE user_id = ? AND formation_id = ?');
        $check->execute([$userId, $fId]);
        if (!$check->fetch()) {
            $f = $pdo->prepare('SELECT access_type, title FROM formations WHERE id = ? AND status = "active"');
            $f->execute([$fId]);
            $fData = $f->fetch();
            if ($fData) {
                $status = ($fData['access_type'] === 'open') ? 'active' : 'pending';
                $pdo->prepare('INSERT INTO enrollments (user_id, formation_id, status) VALUES (?,?,?)')->execute([$userId, $fId, $status]);
                if ($status === 'active') {
                    addXP($userId, 10, 'Inscription à une formation', 'formation', $fId);
                    setFlash('success', 'Vous êtes inscrit à la formation : ' . $fData['title']);
                } else {
                    setFlash('info', 'Votre demande d\'inscription est en attente de validation.');
                }
            }
        }
    }
    redirect(url('student/formations/index.php'));
}

renderHead('Mes formations');
renderSidebar('student');
renderTopbar('Mes formations', [['Mon espace', url('student/index.php')], ['Formations', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Onglets -->
  <div style="display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);padding-bottom:0">
    <a href="?tab=enrolled" class="btn btn-ghost" style="border-radius:var(--radius) var(--radius) 0 0;padding:10px 20px;<?= $tab==='enrolled' ? 'background:var(--bg-surface);border-bottom:2px solid var(--primary);color:var(--primary-light)' : '' ?>">
      <i class="fas fa-book-open"></i> Mes inscriptions <span class="badge badge-secondary" style="margin-left:4px"><?= count($enrolled) ?></span>
    </a>
    <a href="?tab=catalog" class="btn btn-ghost" style="border-radius:var(--radius) var(--radius) 0 0;padding:10px 20px;<?= $tab==='catalog' ? 'background:var(--bg-surface);border-bottom:2px solid var(--primary);color:var(--primary-light)' : '' ?>">
      <i class="fas fa-graduation-cap"></i> Catalogue <span class="badge badge-secondary" style="margin-left:4px"><?= count($catalog) ?></span>
    </a>
  </div>

  <?php if ($tab === 'enrolled'): ?>
  <!-- Mes formations -->
  <?php if (empty($enrolled)): ?>
  <div class="empty-state">
    <div class="icon">📚</div>
    <h3>Aucune formation</h3>
    <p>Vous n'êtes pas encore inscrit à une formation.</p>
    <a href="?tab=catalog" class="btn btn-primary">Voir le catalogue</a>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px">
    <?php foreach ($enrolled as $enr):
      $progress = $enr['progress_percent'];
      $totalL   = $enr['total_lessons'];
      $doneL    = $enr['completed_lessons'];
      $isActive = $enr['status'] === 'active';
      $isDone   = $enr['status'] === 'completed';
    ?>
    <div class="card" style="<?= $isDone ? 'border:1px solid rgba(16,185,129,.3)' : '' ?>">
      <div style="height:130px;background:linear-gradient(135deg,var(--bg-elevated),var(--bg-hover));display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden">
        <?php if ($enr['thumbnail'] && file_exists(UPLOADS_PATH.'/'.$enr['thumbnail'])): ?>
        <img src="<?= e(uploadUrl($enr['thumbnail'])) ?>" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
        <div style="font-size:52px"><?= ['🎓','📚','💡','🔬','💻','🎨'][crc32($enr['title'])%6] ?></div>
        <?php endif; ?>
        <div style="position:absolute;top:10px;left:10px;display:flex;gap:6px">
          <?php if ($enr['rncp_code']): ?><span class="badge badge-primary"><?= e($enr['rncp_code']) ?></span><?php endif; ?>
          <?php if ($isDone): ?><span class="badge badge-success"><i class="fas fa-check"></i> Terminé</span>
          <?php elseif ($enr['status']==='pending'): ?><span class="badge badge-warning">En attente</span>
          <?php else: ?><span class="badge badge-info">En cours</span><?php endif; ?>
        </div>
      </div>

      <div class="card-body">
        <h3 style="font-size:15px;font-weight:700;margin-bottom:8px"><?= e($enr['title']) ?></h3>

        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:6px">
          <span><?= $doneL ?>/<?= $totalL ?> capsules complétées</span>
          <span style="font-weight:700;color:<?= $isDone ? 'var(--success)' : 'var(--primary-light)' ?>"><?= $progress ?>%</span>
        </div>
        <div class="progress-bar" style="height:8px;margin-bottom:12px">
          <div class="progress-fill" style="width:<?= $progress ?>%;<?= $isDone ? 'background:var(--success)' : '' ?>"></div>
        </div>

        <div style="display:flex;gap:8px;font-size:12px;color:var(--text-muted);margin-bottom:12px">
          <span><i class="fas fa-layer-group"></i> <?= $enr['module_count'] ?> modules</span>
          <?php if ($enr['duration_hours']): ?><span><i class="fas fa-clock"></i> <?= $enr['duration_hours'] ?>h</span><?php endif; ?>
          <?php if ($enr['end_date']): ?><span><i class="fas fa-calendar"></i> <?= formatDate($enr['end_date']) ?></span><?php endif; ?>
        </div>

        <?php if ($isActive): ?>
        <a href="<?= url('student/formations/view.php?id=' . $enr['f_id']) ?>" class="btn btn-primary w-full" style="justify-content:center">
          <i class="fas fa-play"></i> <?= $progress > 0 ? 'Reprendre' : 'Commencer' ?>
        </a>
        <?php elseif ($isDone): ?>
        <div class="btn btn-ghost w-full" style="justify-content:center;pointer-events:none;color:var(--success)">
          <i class="fas fa-trophy"></i> Formation terminée
        </div>
        <?php else: ?>
        <div class="btn btn-ghost w-full" style="justify-content:center;pointer-events:none;color:var(--warning)">
          <i class="fas fa-hourglass-half"></i> En attente de validation
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- Catalogue -->
  <?php if (empty($catalog)): ?>
  <div class="empty-state">
    <div class="icon">🔍</div>
    <h3>Aucune formation disponible</h3>
    <p>Il n'y a pas de nouvelles formations pour le moment.</p>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px">
    <?php foreach ($catalog as $f): ?>
    <div class="card">
      <div style="height:130px;background:linear-gradient(135deg,var(--bg-elevated),var(--bg-hover));display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden">
        <?php if ($f['thumbnail'] && file_exists(UPLOADS_PATH.'/'.$f['thumbnail'])): ?>
        <img src="<?= e(uploadUrl($f['thumbnail'])) ?>" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
        <div style="font-size:52px"><?= ['🎓','📚','💡','🔬','💻','🎨'][crc32($f['title'])%6] ?></div>
        <?php endif; ?>
        <div style="position:absolute;top:10px;left:10px;display:flex;gap:6px">
          <?php if ($f['rncp_code']): ?><span class="badge badge-primary"><?= e($f['rncp_code']) ?></span><?php endif; ?>
          <?php if ($f['rncp_level']): ?><span class="badge badge-secondary">Niv. <?= $f['rncp_level'] ?></span><?php endif; ?>
        </div>
      </div>

      <div class="card-body">
        <h3 style="font-size:15px;font-weight:700;margin-bottom:6px"><?= e($f['title']) ?></h3>
        <?php if ($f['description']): ?>
        <p style="font-size:13px;color:var(--text-muted);line-height:1.5;margin-bottom:10px"><?= e(mb_substr($f['description'],0,100)) ?>…</p>
        <?php endif; ?>

        <div style="display:flex;gap:12px;font-size:12px;color:var(--text-muted);margin-bottom:12px;flex-wrap:wrap">
          <span><i class="fas fa-users"></i> <?= $f['student_count'] ?> inscrits</span>
          <span><i class="fas fa-layer-group"></i> <?= $f['module_count'] ?> modules</span>
          <?php if ($f['duration_hours']): ?><span><i class="fas fa-clock"></i> <?= $f['duration_hours'] ?>h</span><?php endif; ?>
          <?php if ($f['price']): ?><span><i class="fas fa-tag"></i> <?= number_format($f['price'],0,'.','\u00a0') ?> €</span><?php endif; ?>
        </div>

        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="request_enrollment">
          <input type="hidden" name="formation_id" value="<?= $f['id'] ?>">
          <button type="submit" class="btn btn-primary w-full" style="justify-content:center">
            <?php if ($f['access_type'] === 'open'): ?>
            <i class="fas fa-plus"></i> S'inscrire
            <?php else: ?>
            <i class="fas fa-paper-plane"></i> Demander l'accès
            <?php endif; ?>
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
