<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$user = currentUser();

// All badges with earn status
$allBadges = $pdo->prepare("
    SELECT b.*, ub.earned_at, ub.id as earned_id
    FROM badges b
    LEFT JOIN user_badges ub ON b.id=ub.badge_id AND ub.user_id=?
    WHERE b.is_active=1
    ORDER BY ub.earned_at DESC, b.rarity DESC, b.name
");
$allBadges->execute([$userId]);
$badges = $allBadges->fetchAll();

$earned = array_filter($badges, fn($b) => $b['earned_id']);
$locked = array_filter($badges, fn($b) => !$b['earned_id']);

// XP stats
$xp = (int)($user['xp_points'] ?? 0);
$level = getLevel($xp);
$levelName = getLevelName($level);
$progress = getLevelProgress($xp);

// XP Leaderboard (top 5)
$leaderboard = $pdo->query("
    SELECT u.first_name, u.last_name, u.avatar, u.xp_points, u.level
    FROM users u WHERE u.role='student' AND u.status='active'
    ORDER BY u.xp_points DESC LIMIT 5
")->fetchAll();

// Rank
$rankStmt = $pdo->prepare("SELECT COUNT(*)+1 as user_rank FROM users WHERE role='student' AND status='active' AND xp_points > ?");
$rankStmt->execute([$xp]);
$myRank = (int)$rankStmt->fetchColumn();

renderHead('Mes badges & XP');
renderSidebar('student');
renderTopbar('Badges & XP', [['Mon espace', url('student/index.php')], ['Badges', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- XP Header -->
  <div style="background:linear-gradient(135deg,rgba(245,158,11,.15),rgba(249,115,22,.08));border:1px solid rgba(245,158,11,.25);border-radius:var(--radius-xl);padding:28px;margin-bottom:28px">
    <div style="display:grid;grid-template-columns:1fr auto auto;gap:24px;align-items:center">
      <div>
        <div style="font-size:13px;color:var(--accent);font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:8px">Profil Gamification</div>
        <h2 style="font-size:28px;font-weight:900;margin-bottom:4px"><?= e($levelName) ?></h2>
        <p style="color:var(--text-muted);font-size:14px">Niveau <?= $level ?> · <?= number_format($xp) ?> XP total</p>
        <div style="margin-top:16px;display:flex;align-items:center;gap:12px">
          <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#f59e0b,#f97316);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;color:#0d1117;box-shadow:0 0 20px rgba(245,158,11,.5)"><?= $level ?></div>
          <div style="flex:1;max-width:300px">
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:6px">
              <span>Niveau <?= $level ?></span>
              <span><?= $progress ?>/<?= XP_PER_LEVEL ?> XP</span>
            </div>
            <div class="progress-bar" style="height:12px">
              <div class="progress-fill" style="width:<?= $progress ?>%"></div>
            </div>
          </div>
          <div style="font-size:13px;color:var(--text-muted)">→ Niveau <?= $level+1 ?></div>
        </div>
      </div>

      <div style="text-align:center;background:rgba(0,0,0,.2);border-radius:var(--radius-lg);padding:20px 24px;border:1px solid var(--border)">
        <div style="font-size:36px;font-weight:900;color:white"><?= count($earned) ?></div>
        <div style="font-size:11px;color:var(--text-muted)">/ <?= count($badges) ?> badges</div>
        <div style="font-size:12px;color:var(--accent);margin-top:4px">🏆 Obtenus</div>
      </div>

      <div style="text-align:center;background:rgba(0,0,0,.2);border-radius:var(--radius-lg);padding:20px 24px;border:1px solid var(--border)">
        <div style="font-size:36px;font-weight:900;color:white">#<?= $myRank ?></div>
        <div style="font-size:11px;color:var(--text-muted)">Classement</div>
        <div style="font-size:12px;color:var(--info);margin-top:4px">🔥 <?= (int)($user['streak_days']??0) ?> j streak</div>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 280px;gap:24px">
    <div>
      <!-- Earned Badges -->
      <?php if (!empty($earned)): ?>
      <div style="margin-bottom:28px">
        <h3 style="font-size:18px;font-weight:800;margin-bottom:16px"><span style="color:var(--accent)">🏆</span> Badges obtenus (<?= count($earned) ?>)</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px">
          <?php foreach ($earned as $b): ?>
          <div class="badge-card" style="--badge-color:<?= e($b['color']) ?>" title="<?= e($b['description']) ?>">
            <div class="rarity-tag rarity-<?= e($b['rarity']) ?>"><?= e(ucfirst($b['rarity'])) ?></div>
            <div class="badge-icon-wrap" style="border-color:<?= e($b['color']) ?>">
              <i class="fas fa-<?= e($b['icon'] ?? 'star') ?>" style="color:<?= e($b['color']) ?>"></i>
            </div>
            <div class="badge-name"><?= e($b['name']) ?></div>
            <div class="badge-desc"><?= e(mb_strimwidth($b['description'],0,50,'…')) ?></div>
            <div class="badge-xp">+<?= $b['xp_reward'] ?> XP</div>
            <?php if ($b['earned_at']): ?>
            <div style="font-size:10px;color:var(--text-faint);margin-top:4px"><?= formatDate($b['earned_at']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Locked Badges -->
      <?php if (!empty($locked)): ?>
      <div>
        <h3 style="font-size:18px;font-weight:800;margin-bottom:16px" style="color:var(--text-muted)">🔒 Badges à débloquer (<?= count($locked) ?>)</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px">
          <?php foreach ($locked as $b): ?>
          <?php
            $criteriaLabels = [
                'lessons_completed'   => "Terminer {$b['criteria_value']} capsule(s)",
                'streak_days'         => "{$b['criteria_value']} jours consécutifs",
                'xp_earned'           => "{$b['criteria_value']} XP total",
                'formations_completed'=> "Terminer {$b['criteria_value']} formation(s)",
                'first_login'         => 'Se connecter pour la première fois',
                'profile_complete'    => 'Compléter son profil',
                'quiz_score'          => "Score de {$b['criteria_value']}% à un quiz",
            ];
          ?>
          <div class="badge-card locked" title="<?= e($criteriaLabels[$b['criteria_type']] ?? $b['criteria_type']) ?>">
            <div class="rarity-tag rarity-<?= e($b['rarity']) ?>"><?= e(ucfirst($b['rarity'])) ?></div>
            <div class="badge-icon-wrap" style="border-color:var(--border)">
              <i class="fas fa-lock" style="color:var(--text-faint)"></i>
            </div>
            <div class="badge-name"><?= e($b['name']) ?></div>
            <div class="badge-desc" style="color:var(--text-faint);font-size:10px"><?= e($criteriaLabels[$b['criteria_type']] ?? '') ?></div>
            <div class="badge-xp" style="color:var(--text-faint)">+<?= $b['xp_reward'] ?> XP</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Leaderboard & XP History -->
    <div style="display:flex;flex-direction:column;gap:20px">
      <div class="card">
        <div class="card-header"><h3 class="card-title">🏅 Classement</h3></div>
        <div>
          <?php foreach ($leaderboard as $i => $p): ?>
          <?php $isMe = ($p['first_name'].' '.$p['last_name']) === ($user['first_name'].' '.$user['last_name']); ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;<?= $i<count($leaderboard)-1?'border-bottom:1px solid var(--border-light)':'' ?>;<?= $isMe?'background:rgba(245,158,11,.08)':'' ?>">
            <div style="width:26px;text-align:center;font-weight:800;color:<?= ['#f59e0b','#94a3b8','#b45309'][$i] ?? 'var(--text-muted)' ?>">
              <?= ['🥇','🥈','🥉'][$i] ?? $i+1 ?>
            </div>
            <div class="avatar avatar-sm" style="background:<?= getAvatarColor($p['first_name'].$p['last_name']) ?>">
              <?= getAvatarInitials($p['first_name'], $p['last_name']) ?>
            </div>
            <div style="flex:1;overflow:hidden">
              <div style="font-size:13px;font-weight:<?= $isMe?'800':'600' ?>;color:<?= $isMe?'var(--accent)':'white' ?>" class="truncate"><?= e($p['first_name'].' '.$p['last_name']) ?></div>
            </div>
            <div style="font-size:13px;font-weight:700;color:<?= $isMe?'var(--accent)':'var(--text-muted)' ?>"><?= number_format((int)$p['xp_points']) ?> XP</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- XP Transaction history -->
      <?php
        $history = $pdo->prepare("SELECT * FROM xp_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
        $history->execute([$userId]);
        $xpHistory = $history->fetchAll();
      ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title">Historique XP</h3></div>
        <div>
          <?php foreach ($xpHistory as $tx): ?>
          <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border-light)">
            <div style="width:28px;height:28px;border-radius:50%;background:<?= $tx['amount']>0?'rgba(16,185,129,.15)':'rgba(239,68,68,.15)' ?>;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0">
              <i class="fas fa-<?= $tx['amount']>0?'arrow-up':'arrow-down' ?>" style="color:<?= $tx['amount']>0?'var(--success)':'var(--danger)' ?>"></i>
            </div>
            <div style="flex:1;overflow:hidden">
              <div style="font-size:12px;font-weight:600;color:var(--text)" class="truncate"><?= e($tx['reason']) ?></div>
              <div style="font-size:10px;color:var(--text-faint)"><?= timeAgo($tx['created_at']) ?></div>
            </div>
            <div style="font-size:14px;font-weight:800;color:<?= $tx['amount']>0?'var(--success)':'var(--danger)' ?>">
              <?= $tx['amount']>0?'+':'' ?><?= $tx['amount'] ?> XP
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($xpHistory)): ?>
          <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">Aucun historique XP</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php renderFooter(); ?>
