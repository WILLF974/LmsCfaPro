<?php
// ============================================================
// LMS CFA Pro — Layout partagé (sidebar + topbar)
// Utilisation : renderLayout($title, $breadcrumb, $role)
// ============================================================

function renderHead(string $title, array $extraCss = []): void {
    $siteName = getSetting('site_name', 'LMS CFA Pro');
    $user = currentUser();
    $csrfToken = $_SESSION[CSRF_TOKEN_NAME] ?? '';
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e($csrfToken) ?>">
<title><?= e($title) ?> — <?= e($siteName) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?= asset('css/main.css') ?>">
<?php foreach ($extraCss as $css): ?>
<link rel="stylesheet" href="<?= e($css) ?>">
<?php endforeach; ?>
</head>
<body>
<?php
}

function renderSidebar(string $role): void {
    $user = currentUser();
    $unreadNotif = 0;
    if ($user) {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$user['id']]);
        $unreadNotif = (int)$stmt->fetchColumn();
    }
    $avatarUrl = $user ? getAvatarUrl($user['avatar'] ?? null, $user['first_name'], $user['last_name']) : '';
    $initials  = $user ? getAvatarInitials($user['first_name'], $user['last_name']) : '?';
    $color     = $user ? getAvatarColor($user['first_name'] . $user['last_name']) : '#6366f1';
    ?>
<div class="app-layout">
<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">L</div>
    <div class="sidebar-brand">LMS CFA Pro<span>Plateforme de formation</span></div>
  </div>

  <nav class="sidebar-nav">
<?php if ($role === 'admin'): ?>
    <div class="nav-section-title">Administration</div>
    <a href="<?= url('admin/index.php') ?>" class="nav-link <?= currentUrlContains('/admin/index') ? 'active' : '' ?>">
      <i class="fas fa-chart-line"></i> Tableau de bord
    </a>
    <a href="<?= url('admin/users/index.php') ?>" class="nav-link <?= currentUrlContains('/admin/users') ? 'active' : '' ?>">
      <i class="fas fa-users"></i> Utilisateurs
    </a>
    <a href="<?= url('admin/rncp/index.php') ?>" class="nav-link <?= currentUrlContains('/admin/rncp') && !currentUrlContains('/import') ? 'active' : '' ?>">
      <i class="fas fa-certificate"></i> Titres RNCP
    </a>
    <a href="<?= url('admin/rncp/import.php') ?>" class="nav-link <?= currentUrlContains('/rncp/import') ? 'active' : '' ?>" style="padding-left:32px;font-size:12px">
      <i class="fas fa-file-import" style="font-size:11px"></i> Importer REAC
    </a>
    <a href="<?= url('admin/formations/index.php') ?>" class="nav-link <?= currentUrlContains('/admin/formations') ? 'active' : '' ?>">
      <i class="fas fa-graduation-cap"></i> Formations
    </a>
    <a href="<?= url('admin/sessions/index.php') ?>" class="nav-link <?= currentUrlContains('/admin/sessions') ? 'active' : '' ?>">
      <i class="fas fa-calendar-alt"></i> Sessions
    </a>
    <div class="nav-section-title">Conformité</div>
    <a href="<?= url('admin/qualiopi/index.php') ?>" class="nav-link <?= currentUrlContains('/qualiopi') ? 'active' : '' ?>">
      <i class="fas fa-shield-alt"></i> Qualiopi
    </a>
    <a href="<?= url('admin/reports/index.php') ?>" class="nav-link <?= currentUrlContains('/reports') ? 'active' : '' ?>">
      <i class="fas fa-chart-bar"></i> Rapports
    </a>
    <div class="nav-section-title">Système</div>
    <a href="<?= url('admin/settings/index.php') ?>" class="nav-link <?= currentUrlContains('/settings') ? 'active' : '' ?>">
      <i class="fas fa-cog"></i> Paramètres
    </a>

<?php elseif ($role === 'pedagogy'): ?>
    <div class="nav-section-title">Pédagogie</div>
    <a href="<?= url('pedagogy/index.php') ?>" class="nav-link <?= currentUrlContains('/pedagogy/index') ? 'active' : '' ?>">
      <i class="fas fa-chart-line"></i> Tableau de bord
    </a>
    <a href="<?= url('admin/rncp/index.php') ?>" class="nav-link <?= currentUrlContains('/rncp') ? 'active' : '' ?>">
      <i class="fas fa-certificate"></i> Titres RNCP
    </a>
    <a href="<?= url('admin/formations/index.php') ?>" class="nav-link <?= currentUrlContains('/formations') ? 'active' : '' ?>">
      <i class="fas fa-graduation-cap"></i> Formations
    </a>
    <a href="<?= url('pedagogy/sequences/index.php') ?>" class="nav-link <?= currentUrlContains('/pedagogy/sequences') ? 'active' : '' ?>">
      <i class="fas fa-list-ol"></i> Séquences
    </a>
    <a href="<?= url('admin/users/index.php?role=student') ?>" class="nav-link <?= currentUrlContains('/admin/users') ? 'active' : '' ?>">
      <i class="fas fa-users"></i> Apprenants
    </a>
    <a href="<?= url('pedagogy/cohorts/index.php') ?>" class="nav-link <?= currentUrlContains('/pedagogy/cohorts') ? 'active' : '' ?>">
      <i class="fas fa-layer-group"></i> Cohortes
    </a>
    <a href="<?= url('admin/reports/index.php') ?>" class="nav-link">
      <i class="fas fa-chart-bar"></i> Rapports
    </a>

<?php elseif ($role === 'teacher'): ?>
    <div class="nav-section-title">Enseignant</div>
    <a href="<?= url('teacher/index.php') ?>" class="nav-link <?= currentUrlContains('/teacher/index') ? 'active' : '' ?>">
      <i class="fas fa-home"></i> Tableau de bord
    </a>
    <a href="<?= url('teacher/courses/index.php') ?>" class="nav-link <?= currentUrlContains('/courses') ? 'active' : '' ?>">
      <i class="fas fa-list-ol"></i> Séances / Séquences
    </a>
    <a href="<?= url('teacher/quizzes/index.php') ?>" class="nav-link <?= currentUrlContains('/quizzes') ? 'active' : '' ?>">
      <i class="fas fa-question-circle"></i> Quiz & Éval
    </a>
    <a href="<?= url('teacher/case_studies/index.php') ?>" class="nav-link <?= currentUrlContains('/case_studies') ? 'active' : '' ?>">
      <i class="fas fa-folder-open"></i> Études de cas
    </a>
    <a href="<?= url('teacher/students/index.php') ?>" class="nav-link <?= currentUrlContains('/students') ? 'active' : '' ?>">
      <i class="fas fa-user-graduate"></i> Mes étudiants
    </a>
    <a href="<?= url('teacher/cahier/index.php') ?>" class="nav-link <?= (currentUrlContains('/teacher/cahier') || currentUrlContains('/cohorts/agenda') || currentUrlContains('/student/cahier')) ? 'active' : '' ?>">
      <i class="fas fa-book-open"></i> Cahier de texte
    </a>
    <a href="<?= url('teacher/evaluations/index.php') ?>" class="nav-link <?= currentUrlContains('/evaluations') ? 'active' : '' ?>">
      <i class="fas fa-tasks"></i> Corrections
    </a>

<?php else: // student ?>
    <div class="nav-section-title">Apprenant</div>
    <a href="<?= url('student/index.php') ?>" class="nav-link <?= currentUrlContains('/student/index') ? 'active' : '' ?>">
      <i class="fas fa-home"></i> Mon espace
    </a>
    <a href="<?= url('student/cahier/index.php') ?>" class="nav-link <?= currentUrlContains('/student/cahier') ? 'active' : '' ?>">
      <i class="fas fa-book-open"></i> Cahier de texte
    </a>
    <a href="<?= url('student/formations/index.php') ?>" class="nav-link <?= currentUrlContains('/student/formations') ? 'active' : '' ?>">
      <i class="fas fa-graduation-cap"></i> Mes formations
    </a>
    <a href="<?= url('student/badges/index.php') ?>" class="nav-link <?= currentUrlContains('/badges') ? 'active' : '' ?>">
      <i class="fas fa-trophy"></i> Badges & XP
    </a>
    <a href="<?= url('student/calendar/index.php') ?>" class="nav-link <?= currentUrlContains('/calendar') ? 'active' : '' ?>">
      <i class="fas fa-calendar-alt"></i> Calendrier
    </a>
    <a href="<?= url('student/profile/index.php') ?>" class="nav-link <?= currentUrlContains('/profile') ? 'active' : '' ?>">
      <i class="fas fa-user"></i> Mon profil
    </a>
    <a href="<?= url('student/notifications/index.php') ?>" class="nav-link <?= currentUrlContains('/student/notifications') ? 'active' : '' ?>">
      <i class="fas fa-bell"></i> Corrections
      <?php if ($unreadNotif > 0): ?><span class="nav-badge" data-notif><?= $unreadNotif ?></span><?php endif; ?>
    </a>
<?php endif; ?>

    <div class="nav-section-title">Messages</div>
    <a href="<?= url('messages/index.php') ?>" class="nav-link <?= currentUrlContains('/messages') ? 'active' : '' ?>">
      <i class="fas fa-envelope"></i> Messages
    </a>
  </nav>

  <!-- Sidebar User -->
  <div class="sidebar-footer">
    <?php if ($user): ?>
    <?php $xp = (int)($user['xp_points'] ?? 0); $level = getLevel($xp); $progress = getLevelProgress($xp); ?>
    <div class="xp-bar" style="margin-bottom:12px">
      <div class="level-badge"><?= $level ?></div>
      <div class="xp-info">
        <div class="xp-label"><?= e(getLevelName($level)) ?> — Niveau <?= $level ?></div>
        <div class="progress-bar" style="height:6px">
          <div class="progress-fill" style="width:<?= $progress ?>%"></div>
        </div>
      </div>
    </div>
    <div class="nav-link" style="padding:8px 12px;cursor:default;">
      <div class="avatar avatar-sm" style="background:<?= e($color) ?>">
        <?php if ($user['avatar'] && file_exists(UPLOADS_PATH . '/avatars/' . $user['avatar'])): ?>
        <img src="<?= e($avatarUrl) ?>" alt="">
        <?php else: ?><?= e($initials) ?><?php endif; ?>
      </div>
      <div style="flex:1;overflow:hidden">
        <div style="font-size:13px;font-weight:600;color:white;truncate"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div style="font-size:11px;color:var(--text-muted)"><?= getRoleLabel($user['role']) ?></div>
      </div>
    </div>
    <a href="<?= url('logout.php') ?>" class="nav-link" style="color:var(--danger)">
      <i class="fas fa-sign-out-alt"></i> Déconnexion
    </a>
    <?php endif; ?>
  </div>
</aside>

<!-- Sidebar overlay (mobile) -->
<div id="sidebar-overlay" class="fixed inset-0 z-50" style="display:none;background:rgba(0,0,0,.5)" onclick="document.querySelector('.sidebar').classList.remove('open');this.style.display='none'"></div>

<div class="main-content">
<?php
}

function renderTopbar(string $title, array $breadcrumb = []): void {
    $user = currentUser();
    $unreadNotif = 0;
    $notifications = [];
    if ($user) {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$user['id']]);
        $unreadNotif = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8');
        $stmt->execute([$user['id']]);
        $notifications = $stmt->fetchAll();
    }
    $initials = $user ? getAvatarInitials($user['first_name'], $user['last_name']) : '?';
    $color = $user ? getAvatarColor($user['first_name'] . $user['last_name']) : '#6366f1';
    ?>
<!-- Topbar -->
<header class="topbar">
  <div class="topbar-left">
    <button id="sidebar-toggle" class="btn-icon" style="display:none" aria-label="Menu">
      <i class="fas fa-bars"></i>
    </button>
    <div>
      <div class="topbar-title"><?= e($title) ?></div>
      <?php if ($breadcrumb): ?>
      <div class="breadcrumb">
        <?php foreach ($breadcrumb as $i => [$label, $href]): ?>
          <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
          <?php if ($href): ?><a href="<?= e($href) ?>"><?= e($label) ?></a>
          <?php else: ?><span><?= e($label) ?></span><?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="topbar-actions">
    <!-- Notifications -->
    <div style="position:relative">
      <button class="btn-icon" id="notif-btn" title="Notifications">
        <i class="fas fa-bell"></i>
        <?php if ($unreadNotif > 0): ?><span class="notif-count"><?= $unreadNotif ?></span><?php endif; ?>
      </button>
      <div class="notif-panel" id="notif-panel">
        <div class="notif-header">
          <span class="font-bold text-white">Notifications</span>
          <?php if ($unreadNotif > 0): ?><span class="badge badge-primary"><?= $unreadNotif ?> non lues</span><?php endif; ?>
        </div>
        <?php if (empty($notifications)): ?>
        <div class="empty-state" style="padding:24px">
          <i class="fas fa-bell-slash" style="font-size:28px;opacity:.3;margin-bottom:8px"></i>
          <p style="margin:0;font-size:13px">Aucune notification</p>
        </div>
        <?php else: ?>
        <?php foreach ($notifications as $n): ?>
        <div class="notif-item <?= !$n['read_at'] ? 'unread' : '' ?>">
          <?php if (!$n['read_at']): ?><div class="notif-dot"></div><?php endif; ?>
          <div class="notif-body">
            <?php if ($n['action_url']): ?>
            <a href="<?= e($n['action_url']) ?>" class="notif-title" style="text-decoration:none;color:white"><?= e($n['title']) ?></a>
            <?php else: ?>
            <div class="notif-title"><?= e($n['title']) ?></div>
            <?php endif; ?>
            <div class="notif-text"><?= e($n['message']) ?></div>
            <div class="notif-time"><?= timeAgo($n['created_at']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- User menu -->
    <div class="dropdown">
      <div class="user-menu-trigger" data-dropdown="user-menu">
        <div class="avatar avatar-sm" style="background:<?= e($color) ?>">
          <?php if ($user && $user['avatar']): ?>
          <img src="<?= e(getAvatarUrl($user['avatar'], $user['first_name'], $user['last_name'])) ?>" alt="">
          <?php else: ?><?= e($initials) ?><?php endif; ?>
        </div>
        <div class="hidden" style="display:block">
          <div class="user-name"><?= $user ? e($user['first_name']) : '' ?></div>
          <div class="user-role"><?= $user ? getRoleLabel($user['role']) : '' ?></div>
        </div>
        <i class="fas fa-chevron-down" style="font-size:11px;color:var(--text-muted)"></i>
      </div>
      <div class="dropdown-menu" id="user-menu">
        <a href="<?= url('student/profile/index.php') ?>" class="dropdown-item"><i class="fas fa-user"></i> Mon profil</a>
        <a href="<?= url('student/badges/index.php') ?>" class="dropdown-item"><i class="fas fa-trophy"></i> Mes badges</a>
        <?php if (isAdmin()): ?>
        <a href="<?= url('admin/settings/index.php') ?>" class="dropdown-item"><i class="fas fa-cog"></i> Paramètres</a>
        <?php endif; ?>
        <div class="dropdown-divider"></div>
        <a href="<?= url('logout.php') ?>" class="dropdown-item" style="color:var(--danger)"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
      </div>
    </div>
  </div>
</header>
<?php
}

function renderFooter(array $extraJs = []): void {
    ?>
</div><!-- .main-content -->
</div><!-- .app-layout -->

<!-- Scripts -->
<script src="<?= asset('js/main.js') ?>?v=<?= filemtime(ASSETS_PATH . '/js/main.js') ?>"></script>
<?php foreach ($extraJs as $js): ?>
<script src="<?= e($js) ?>"></script>
<?php endforeach; ?>

<!-- Mobile sidebar toggle fix -->
<script>
const sidebarToggle = document.getElementById('sidebar-toggle');
if (sidebarToggle) {
  sidebarToggle.style.display = window.innerWidth <= 768 ? 'flex' : 'none';
  window.addEventListener('resize', () => {
    sidebarToggle.style.display = window.innerWidth <= 768 ? 'flex' : 'none';
  });
}
</script>
</body>
</html>
<?php
}
