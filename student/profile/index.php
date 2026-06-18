<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$user = currentUser();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? 'profile';

    if ($action === 'profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $bio       = trim($_POST['bio'] ?? '');
        $birthDate = $_POST['birth_date'] ?? null;

        if (!$firstName || !$lastName) $errors[] = 'Prénom et nom requis.';

        // Avatar upload
        $avatarFile = $user['avatar'];
        if (!empty($_FILES['avatar']['name'])) {
            $upload = uploadFile($_FILES['avatar'], 'avatars', ALLOWED_IMAGE_TYPES, 5*1024*1024);
            if ($upload['success']) {
                // Delete old avatar
                if ($avatarFile && file_exists(UPLOADS_PATH.'/avatars/'.$avatarFile)) {
                    unlink(UPLOADS_PATH.'/avatars/'.$avatarFile);
                }
                $avatarFile = $upload['filename'];
            } else {
                $errors[] = $upload['error'];
            }
        }

        if (empty($errors)) {
            $pdo->prepare('UPDATE users SET first_name=?,last_name=?,phone=?,bio=?,birth_date=?,avatar=? WHERE id=?')
                ->execute([$firstName,$lastName,$phone,$bio,$birthDate?:null,$avatarFile,$userId]);

            // Badge profil complet
            if ($firstName && $lastName && $phone && $bio) {
                $badge = $pdo->query("SELECT id FROM badges WHERE criteria_type='profile_complete' LIMIT 1")->fetch();
                if ($badge) {
                    $already = $pdo->prepare('SELECT id FROM user_badges WHERE user_id=? AND badge_id=?');
                    $already->execute([$userId,$badge['id']]);
                    if (!$already->fetch()) {
                        $pdo->prepare('INSERT IGNORE INTO user_badges (user_id,badge_id) VALUES (?,?)')->execute([$userId,$badge['id']]);
                        addXP($userId, 25, 'Profil complet', 'badge', $badge['id']);
                    }
                }
            }

            $_SESSION['user_name'] = $firstName . ' ' . $lastName;
            setFlash('success', 'Profil mis à jour !');
            redirect(url('student/profile/index.php'));
        }
    } elseif ($action === 'password') {
        $result = changePassword($userId, $_POST['current_password']??'', $_POST['new_password']??'');
        if ($result['success']) { setFlash('success','Mot de passe changé.'); redirect(url('student/profile/index.php')); }
        else $errors[] = $result['error'];
    }
}

$xp = (int)($user['xp_points']??0);
$level = getLevel($xp);

renderHead('Mon profil');
renderSidebar($_SESSION['user_role'] === 'student' ? 'student' : ($_SESSION['user_role'] === 'teacher' ? 'teacher' : 'admin'));
renderTopbar('Mon profil', [['Mon espace', url('student/index.php')], ['Profil', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>
  <?php foreach ($errors as $e): ?><div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= e($e) ?></div><?php endforeach; ?>

  <div style="display:grid;grid-template-columns:280px 1fr;gap:24px;align-items:start">

    <!-- Avatar Panel -->
    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="card">
        <div class="card-body" style="text-align:center;padding:28px 20px">
          <div style="position:relative;display:inline-block;margin-bottom:16px">
            <div class="avatar avatar-xxl" style="background:<?= getAvatarColor($user['first_name'].$user['last_name']) ?>;margin:0 auto">
              <?php if($user['avatar']&&file_exists(UPLOADS_PATH.'/avatars/'.$user['avatar'])): ?>
              <img src="<?= e(uploadUrl('avatars/'.$user['avatar'])) ?>" alt="">
              <?php else: ?><?= e(getAvatarInitials($user['first_name'],$user['last_name'])) ?><?php endif; ?>
            </div>
            <label style="position:absolute;bottom:0;right:0;width:32px;height:32px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;border:3px solid var(--bg-surface)">
              <i class="fas fa-camera" style="font-size:12px;color:white"></i>
              <input type="file" accept="image/*" style="display:none" id="avatar-quick">
            </label>
          </div>
          <div style="font-size:18px;font-weight:800;color:white"><?= e($user['first_name'].' '.$user['last_name']) ?></div>
          <div style="font-size:13px;color:var(--text-muted);margin:4px 0"><?= getRoleLabel($user['role']) ?></div>
          <div style="font-size:12px;color:var(--text-faint)"><?= e($user['email']) ?></div>
          <div style="margin-top:16px;padding:12px;background:var(--bg-elevated);border-radius:var(--radius)">
            <div style="font-size:22px;font-weight:900;color:var(--accent)"><?= $level ?></div>
            <div style="font-size:11px;color:var(--text-muted)">Niveau · <?= number_format($xp) ?> XP</div>
          </div>
          <?php
            $bc = $pdo->prepare('SELECT COUNT(*) FROM user_badges WHERE user_id=?');
            $bc->execute([$userId]);
          ?>
          <div style="font-size:13px;color:var(--text-muted);margin-top:8px">🏆 <?= $bc->fetchColumn() ?> badges obtenus</div>
        </div>
      </div>

      <div class="card">
        <div class="card-body" style="padding:16px">
          <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px">Statistiques</div>
          <?php
            $stats2 = [
              'Formations inscrit' => $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status="active"')->execute([$userId]) ? $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status="active"') : 0,
            ];
            $s1=$pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status="active"');$s1->execute([$userId]);
            $s2=$pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status="completed"');$s2->execute([$userId]);
            $s3=$pdo->prepare('SELECT COUNT(*) FROM lesson_progress WHERE user_id=? AND status="completed"');$s3->execute([$userId]);
          ?>
          <?php foreach ([['Formations actives',$s1->fetchColumn()],['Formations terminées',$s2->fetchColumn()],['Capsules terminées',$s3->fetchColumn()],['Jours de connexion',$user['streak_days']??0]] as [$label,$val]): ?>
          <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light)">
            <span style="font-size:12px;color:var(--text-muted)"><?= $label ?></span>
            <span style="font-size:13px;font-weight:700;color:white"><?= $val ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Edit Forms -->
    <div style="display:flex;flex-direction:column;gap:20px">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Informations personnelles</h3></div>
        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="profile">
          <div class="card-body">
            <input type="file" name="avatar" id="avatar-file" style="display:none" accept="image/*">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Prénom <span class="required">*</span></label>
                <input type="text" name="first_name" class="form-control" value="<?= e($user['first_name']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Nom <span class="required">*</span></label>
                <input type="text" name="last_name" class="form-control" value="<?= e($user['last_name']) ?>" required>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Téléphone</label>
                <div class="input-group">
                  <i class="fas fa-phone input-icon"></i>
                  <input type="tel" name="phone" class="form-control" value="<?= e($user['phone']??'') ?>" placeholder="+33 6...">
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Date de naissance</label>
                <input type="date" name="birth_date" class="form-control" value="<?= e($user['birth_date']??'') ?>">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Biographie / Présentation</label>
              <textarea name="bio" class="form-control" rows="3" placeholder="Parlez de vous, de vos objectifs..."><?= e($user['bio']??'') ?></textarea>
            </div>
          </div>
          <div class="card-footer" style="display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
          </div>
        </form>
      </div>

      <!-- Password -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">Changer le mot de passe</h3></div>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="password">
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Mot de passe actuel</label>
              <div class="input-group">
                <i class="fas fa-lock input-icon"></i>
                <input type="password" name="current_password" class="form-control" placeholder="Votre mot de passe actuel" required>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Nouveau mot de passe</label>
                <input type="password" name="new_password" class="form-control" placeholder="Min. 8 caractères" required>
              </div>
              <div class="form-group">
                <label class="form-label">Confirmer</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Répéter" required>
              </div>
            </div>
          </div>
          <div class="card-footer" style="display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Changer</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="<?= asset('js/main.js') ?>"></script>
<script>
// Quick avatar change
document.getElementById('avatar-quick').addEventListener('change', function() {
  document.getElementById('avatar-file').files = this.files;
  const form = document.createElement('form');
  // Trigger via the main form
  const mainForm = document.querySelector('form[method="POST"][enctype]');
  if (mainForm) {
    const dt = new DataTransfer();
    dt.items.add(this.files[0]);
    mainForm.querySelector('[name="avatar"]').files = dt.files;
    mainForm.submit();
  }
});
</script>
<?php renderFooter(['']); ?>
