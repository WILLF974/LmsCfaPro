<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) redirect(getDashboardUrl());

$error = '';
$success = '';
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $formData = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name'  => trim($_POST['last_name'] ?? ''),
        'email'      => trim($_POST['email'] ?? ''),
        'password'   => $_POST['password'] ?? '',
        'password2'  => $_POST['password2'] ?? '',
    ];

    if ($formData['password'] !== $formData['password2']) {
        $error = 'Les mots de passe ne correspondent pas.';
    } elseif (!isset($_POST['terms'])) {
        $error = 'Vous devez accepter les conditions d\'utilisation.';
    } else {
        $result = register($formData);
        if ($result['success']) {
            if ($result['requires_approval']) {
                $success = 'Votre compte a été créé ! Un administrateur va valider votre inscription. Vous recevrez une confirmation.';
            } else {
                setFlash('success', 'Compte créé avec succès ! Vous pouvez maintenant vous connecter.');
                redirect(url('index.php'));
            }
        } else {
            $error = $result['error'];
        }
    }
}

$siteName = getSetting('site_name', 'LMS CFA Pro');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e($_SESSION[CSRF_TOKEN_NAME]) ?>">
<title>Inscription — <?= e($siteName) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?= asset('css/main.css') ?>">
<style>
.register-page { min-height:100vh; background:var(--bg); display:flex; align-items:center; justify-content:center; padding:40px 20px; }
.register-page::before { content:''; position:fixed; inset:0; background:radial-gradient(ellipse 70% 50% at 50% 0%, rgba(99,102,241,.12) 0%, transparent 70%); pointer-events:none; }
.register-card { width:100%; max-width:560px; background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius-xl); overflow:hidden; box-shadow:var(--shadow-lg); position:relative; }
.register-header { padding:32px 36px 24px; border-bottom:1px solid var(--border); text-align:center; }
.register-logo { width:52px;height:52px;background:linear-gradient(135deg,var(--primary),var(--secondary));border-radius:var(--radius-lg);display:flex;align-items:center;justify-content:center;font-size:24px;color:white;font-weight:900;font-family:var(--font-heading);margin:0 auto 16px; }
.register-body { padding:28px 36px 36px; }
.step-indicator { display:flex; gap:8px; margin-bottom:24px; }
.step { flex:1; height:4px; border-radius:var(--radius-full); background:var(--bg-elevated); transition:.3s; }
.step.done { background:var(--primary); }
</style>
</head>
<body>
<div class="register-page">
  <div class="register-card fade-in">
    <div class="register-header">
      <div class="register-logo">L</div>
      <h2 style="font-size:22px;font-weight:800;margin-bottom:6px">Créer votre compte</h2>
      <p style="color:var(--text-muted);font-size:14px">Rejoignez <?= e($siteName) ?> et commencez votre parcours</p>
    </div>

    <div class="register-body">
      <?php if ($success): ?>
      <div style="text-align:center;padding:20px 0">
        <div style="font-size:56px;margin-bottom:16px">✅</div>
        <h3 style="font-size:20px;font-weight:800;margin-bottom:12px">Inscription envoyée !</h3>
        <p style="color:var(--text-muted);margin-bottom:24px"><?= e($success) ?></p>
        <a href="<?= url('index.php') ?>" class="btn btn-primary"><i class="fas fa-home"></i> Retour à l'accueil</a>
      </div>
      <?php else: ?>

      <?php if ($error): ?>
      <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= e($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <?= csrfField() ?>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Prénom <span class="required">*</span></label>
            <div class="input-group">
              <i class="fas fa-user input-icon"></i>
              <input type="text" name="first_name" class="form-control" placeholder="Marie"
                     value="<?= e($formData['first_name'] ?? '') ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Nom <span class="required">*</span></label>
            <div class="input-group">
              <i class="fas fa-user input-icon"></i>
              <input type="text" name="last_name" class="form-control" placeholder="Dupont"
                     value="<?= e($formData['last_name'] ?? '') ?>" required>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Adresse email <span class="required">*</span></label>
          <div class="input-group">
            <i class="fas fa-envelope input-icon"></i>
            <input type="email" name="email" class="form-control" placeholder="votre@email.fr"
                   value="<?= e($formData['email'] ?? '') ?>" required autocomplete="email">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Mot de passe <span class="required">*</span></label>
            <div class="input-group">
              <i class="fas fa-lock input-icon"></i>
              <input type="password" name="password" id="pwd1" class="form-control" placeholder="Min. 8 caractères" required>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirmer <span class="required">*</span></label>
            <div class="input-group">
              <i class="fas fa-lock input-icon"></i>
              <input type="password" name="password2" id="pwd2" class="form-control" placeholder="Répéter" required>
            </div>
          </div>
        </div>

        <!-- Password strength -->
        <div id="pwd-strength" style="margin:-12px 0 16px;display:none">
          <div class="progress-bar" style="height:4px">
            <div class="progress-fill" id="pwd-strength-bar" style="width:0;transition:.3s"></div>
          </div>
          <div id="pwd-strength-text" style="font-size:11px;color:var(--text-muted);margin-top:4px"></div>
        </div>

        <div class="form-group">
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
            <input type="checkbox" name="terms" style="margin-top:3px;width:16px;height:16px;accent-color:var(--primary)" required>
            <span style="font-size:13px;color:var(--text-muted)">
              J'accepte les <a href="#">conditions d'utilisation</a> et la <a href="#">politique de confidentialité</a>
            </span>
          </label>
        </div>

        <button type="submit" class="btn btn-primary w-full btn-lg">
          <i class="fas fa-user-plus"></i> Créer mon compte
        </button>

        <p style="text-align:center;margin-top:20px;font-size:14px;color:var(--text-muted)">
          Déjà un compte ? <a href="<?= url('index.php') ?>" style="font-weight:600">Se connecter</a>
        </p>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="<?= asset('js/main.js') ?>"></script>
<script>
const pwd1 = document.getElementById('pwd1');
const strengthBar = document.getElementById('pwd-strength-bar');
const strengthText = document.getElementById('pwd-strength-text');
const strengthWrap = document.getElementById('pwd-strength');

pwd1.addEventListener('input', () => {
  const v = pwd1.value;
  strengthWrap.style.display = v ? 'block' : 'none';
  let score = 0;
  if (v.length >= 8) score++;
  if (/[A-Z]/.test(v)) score++;
  if (/[0-9]/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  const colors = ['#ef4444','#f97316','#f59e0b','#10b981'];
  const labels = ['Très faible','Faible','Moyen','Fort'];
  strengthBar.style.width = (score * 25) + '%';
  strengthBar.style.background = colors[score-1] || '#ef4444';
  strengthText.textContent = labels[score-1] || '';
});
</script>
</body>
</html>
