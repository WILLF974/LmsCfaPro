<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/layout.php';
requireLogin();
if (!isStudent()) redirect(getDashboardUrl());

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$user = currentUser();

// Student enrollments
$enrollments = $pdo->prepare("
    SELECT e.*, f.title as formation_title, f.thumbnail, f.duration_hours,
           r.rncp_code, r.level as rncp_level,
           (SELECT COUNT(*) FROM modules m WHERE m.formation_id=f.id) as module_count,
           (SELECT COUNT(*) FROM lessons l JOIN modules m ON l.module_id=m.id WHERE m.formation_id=f.id) as lesson_count
    FROM enrollments e
    JOIN formations f ON e.formation_id=f.id
    LEFT JOIN rncp_titles r ON f.rncp_title_id=r.id
    WHERE e.user_id=? AND e.status IN ('active','completed')
    ORDER BY e.enrolled_at DESC
");
$enrollments->execute([$userId]);
$myFormations = $enrollments->fetchAll();

// Badges earned
$badgesStmt = $pdo->prepare("
    SELECT b.*, ub.earned_at FROM user_badges ub
    JOIN badges b ON ub.badge_id=b.id
    WHERE ub.user_id=? ORDER BY ub.earned_at DESC LIMIT 5
");
$badgesStmt->execute([$userId]);
$recentBadges = $badgesStmt->fetchAll();

// Next lesson to continue
$nextLesson = $pdo->prepare("
    SELECT l.*, mo.title as module_title, f.title as formation_title, f.id as formation_id,
           lp.status as progress_status, lp.last_position
    FROM lessons l
    JOIN modules mo ON l.module_id=mo.id
    JOIN formations f ON mo.formation_id=f.id
    JOIN enrollments e ON f.id=e.formation_id AND e.user_id=? AND e.status='active'
    LEFT JOIN lesson_progress lp ON l.id=lp.lesson_id AND lp.user_id=?
    WHERE (lp.status IS NULL OR lp.status='in_progress')
    ORDER BY mo.order_num, l.order_num LIMIT 1
");
$nextLesson->execute([$userId, $userId]);
$next = $nextLesson->fetch();

// Recent activity
$recentActivity = $pdo->prepare("
    SELECT lp.*, l.title as lesson_title, l.content_type, mo.title as module_title
    FROM lesson_progress lp
    JOIN lessons l ON lp.lesson_id=l.id
    JOIN modules mo ON l.module_id=mo.id
    WHERE lp.user_id=? AND lp.status='completed'
    ORDER BY lp.completed_at DESC LIMIT 5
");
$recentActivity->execute([$userId]);
$activity = $recentActivity->fetchAll();

// XP / Level info
$xp = (int)($user['xp_points'] ?? 0);
$level = getLevel($xp);
$progress = getLevelProgress($xp);
$xpToNext = getXpToNextLevel($xp);
$levelName = getLevelName($level);

// Streak
$streak = (int)($user['streak_days'] ?? 0);

renderHead('Mon espace');
renderSidebar('student');
renderTopbar('Mon espace', [['Accueil', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Welcome + XP Banner -->
  <div style="background:linear-gradient(135deg,rgba(99,102,241,.12) 0%,rgba(139,92,246,.08) 50%,rgba(245,158,11,.06) 100%);border:1px solid rgba(99,102,241,.2);border-radius:var(--radius-xl);padding:28px;margin-bottom:28px;display:grid;grid-template-columns:1fr auto;gap:24px;align-items:center">
    <div>
      <div style="font-size:24px;font-weight:800;margin-bottom:6px">Bonjour, <?= e($user['first_name']) ?> ! 👋</div>
      <div style="color:var(--text-muted);font-size:14px;margin-bottom:20px"><?= date('l d F Y') ?> · Continuez votre parcours</div>

      <!-- XP Bar -->
      <div class="xp-bar">
        <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#f59e0b,#f97316);display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:900;color:#0d1117;box-shadow:0 0 20px rgba(245,158,11,.5)"><?= $level ?></div>
        <div style="flex:1">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <span style="font-size:14px;font-weight:700;color:white"><?= e($levelName) ?> — Niveau <?= $level ?></span>
            <span style="font-size:12px;color:var(--text-muted)"><?= number_format($xp) ?> XP · <?= $xpToNext ?> avant niveau <?= $level+1 ?></span>
          </div>
          <div class="progress-bar" style="height:10px">
            <div class="progress-fill" style="width:<?= $progress ?>%"></div>
          </div>
        </div>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:12px;align-items:flex-end">
      <!-- Streak -->
      <div style="display:flex;align-items:center;gap:10px;background:rgba(249,115,22,.12);border:1px solid rgba(249,115,22,.25);padding:10px 16px;border-radius:var(--radius-lg)">
        <span style="font-size:24px">🔥</span>
        <div>
          <div style="font-size:20px;font-weight:900;color:#f97316"><?= $streak ?></div>
          <div style="font-size:11px;color:var(--text-muted)">Jours consécutifs</div>
        </div>
      </div>
      <!-- Badges count -->
      <a href="<?= url('student/badges/index.php') ?>" style="display:flex;align-items:center;gap:10px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);padding:10px 16px;border-radius:var(--radius-lg);text-decoration:none">
        <span style="font-size:22px">🏆</span>
        <div>
          <?php
            $badgeCount = $pdo->prepare('SELECT COUNT(*) FROM user_badges WHERE user_id=?');
            $badgeCount->execute([$userId]);
          ?>
          <div style="font-size:18px;font-weight:900;color:#f59e0b"><?= $badgeCount->fetchColumn() ?></div>
          <div style="font-size:11px;color:var(--text-muted)">Badges</div>
        </div>
      </a>
    </div>
  </div>

  <!-- Continue where you left off -->
  <?php if ($next): ?>
  <div style="background:linear-gradient(135deg,rgba(16,185,129,.1),rgba(14,165,233,.08));border:1px solid rgba(16,185,129,.25);border-radius:var(--radius-xl);padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;gap:16px">
    <div style="width:52px;height:52px;border-radius:var(--radius-lg);background:var(--success);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0">▶️</div>
    <div style="flex:1">
      <div style="font-size:11px;color:var(--success);font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:3px">Reprendre</div>
      <div style="font-size:16px;font-weight:700;color:white"><?= e($next['title']) ?></div>
      <div style="font-size:12px;color:var(--text-muted)"><?= e($next['formation_title']) ?> · <?= e($next['module_title']) ?></div>
    </div>
    <a href="<?= url('student/course/view.php?id='.$next['id'].'&formation_id='.$next['formation_id']) ?>" class="btn btn-success">
      <i class="fas fa-play"></i> Continuer
    </a>
  </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 320px;gap:24px">
    <!-- My Formations -->
    <div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <h2 style="font-size:18px;font-weight:800">Mes formations (<?= count($myFormations) ?>)</h2>
        <a href="<?= url('student/formations/index.php') ?>" class="btn btn-ghost btn-sm">Voir tout <i class="fas fa-arrow-right"></i></a>
      </div>

      <?php if (empty($myFormations)): ?>
      <div class="empty-state">
        <div class="icon">📚</div>
        <h3>Pas encore de formation</h3>
        <p>Contactez votre référent pédagogique pour être inscrit à une formation.</p>
      </div>
      <?php else: ?>
      <div class="course-grid" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
        <?php foreach ($myFormations as $enr): ?>
        <div class="course-card">
          <div class="course-card-thumb">
            <?php if ($enr['thumbnail'] && file_exists(UPLOADS_PATH.'/'.$enr['thumbnail'])): ?>
            <img src="<?= e(uploadUrl($enr['thumbnail'])) ?>" alt="">
            <?php else: ?>
            <div style="font-size:48px"><?= ['📗','📘','📙','📕','📓'][crc32($enr['formation_title'])%5] ?></div>
            <?php endif; ?>
            <div class="content-type-badge">
              <i class="fas fa-certificate"></i> <?= e($enr['rncp_code'] ?? 'RNCP') ?>
            </div>
            <?php if ($enr['status'] === 'completed'): ?>
            <div style="position:absolute;top:12px;right:12px;background:var(--success);color:white;padding:3px 10px;border-radius:var(--radius-full);font-size:11px;font-weight:700"><i class="fas fa-check"></i> Terminé</div>
            <?php endif; ?>
          </div>
          <div class="course-card-body">
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Niveau <?= $enr['rncp_level'] ?? '–' ?></div>
            <div class="course-card-title"><?= e($enr['formation_title']) ?></div>
            <div class="course-card-meta">
              <span><i class="fas fa-layer-group"></i> <?= $enr['module_count'] ?> modules</span>
              <span><i class="fas fa-film"></i> <?= $enr['lesson_count'] ?> capsules</span>
              <?php if ($enr['duration_hours']): ?>
              <span><i class="fas fa-clock"></i> <?= $enr['duration_hours'] ?>h</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="course-card-footer">
            <div style="flex:1">
              <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:6px">
                <span>Progression</span>
                <span style="font-weight:700;color:white"><?= $enr['progress_percent'] ?>%</span>
              </div>
              <div class="progress-bar" style="height:6px">
                <div class="progress-fill <?= $enr['status']==='completed'?'success':'' ?>" style="width:<?= $enr['progress_percent'] ?>%"></div>
              </div>
            </div>
            <a href="<?= url('student/formations/view.php?id='.$enr['formation_id']) ?>" class="btn btn-primary btn-sm" style="margin-left:12px">
              <?= $enr['status']==='completed' ? 'Revoir' : 'Continuer' ?>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right Panel -->
    <div style="display:flex;flex-direction:column;gap:20px">

      <!-- Recent Badges -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-trophy" style="color:var(--accent);margin-right:8px"></i>Derniers badges</h3>
          <a href="<?= url('student/badges/index.php') ?>" class="btn btn-ghost btn-sm">Voir tout</a>
        </div>
        <?php if (empty($recentBadges)): ?>
        <div class="empty-state" style="padding:24px">
          <p style="font-size:13px">Complétez des capsules pour gagner des badges !</p>
        </div>
        <?php else: ?>
        <div style="padding:12px;display:flex;flex-direction:column;gap:8px">
          <?php foreach ($recentBadges as $b): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:10px;background:var(--bg-elevated);border-radius:var(--radius);border:1px solid var(--border)">
            <div style="width:40px;height:40px;border-radius:50%;background:rgba(245,158,11,.15);border:2px solid <?= e($b['color']) ?>;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">
              <i class="fas fa-<?= e($b['icon'] ?? 'star') ?>" style="color:<?= e($b['color']) ?>"></i>
            </div>
            <div style="flex:1;overflow:hidden">
              <div style="font-size:13px;font-weight:700;color:white"><?= e($b['name']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?= timeAgo($b['earned_at']) ?></div>
            </div>
            <div style="font-size:12px;font-weight:700;color:var(--accent)">+<?= $b['xp_reward'] ?> XP</div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Recent Activity -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">Activité récente</h3></div>
        <?php if (empty($activity)): ?>
        <div class="empty-state" style="padding:24px"><p style="font-size:13px">Commencez une capsule pour voir votre activité !</p></div>
        <?php else: ?>
        <div style="padding:0">
          <?php foreach ($activity as $act): ?>
          <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border-light)">
            <div style="width:32px;height:32px;border-radius:var(--radius);background:rgba(16,185,129,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="<?= getContentTypeIcon($act['content_type']) ?>"></i>
            </div>
            <div style="flex:1;overflow:hidden">
              <div style="font-size:13px;font-weight:600;color:var(--text)" class="truncate"><?= e($act['lesson_title']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?= timeAgo($act['completed_at']) ?></div>
            </div>
            <i class="fas fa-check-circle" style="color:var(--success);font-size:14px"></i>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
<?php renderFooter(); ?>
