<?php
require_once __DIR__ . '/config/config.php';

// Rediriger si connecté
if (isLoggedIn()) {
    redirect(getDashboardUrl());
}

$error = '';
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $result = login($email, $password);
    if ($result['success']) {
        $redirect = filter_var($_GET['redirect'] ?? '', FILTER_SANITIZE_URL);
        redirect($redirect ?: getDashboardUrl());
    } else {
        $error = $result['error'];
        $formData['email'] = $email;
    }
}

$siteName = getSetting('site_name', 'LMS CFA Pro');
$tagline  = getSetting('site_tagline', 'La plateforme de formation professionnelle');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e($_SESSION[CSRF_TOKEN_NAME]) ?>">
<title><?= e($siteName) ?> — Connexion</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?= asset('css/main.css') ?>">
<style>
.landing-page { min-height: 100vh; display: flex; }

.landing-left {
  flex: 1; background: linear-gradient(135deg, #0d1117 0%, #1c2230 100%);
  display: flex; align-items: center; justify-content: center;
  padding: 60px 40px; position: relative; overflow: hidden;
}
.landing-left::before {
  content: ''; position: absolute; inset: 0;
  background: radial-gradient(ellipse 80% 60% at 30% 30%, rgba(99,102,241,.2) 0%, transparent 70%);
}
.landing-left::after {
  content: ''; position: absolute; inset: 0;
  background: radial-gradient(ellipse 60% 60% at 80% 80%, rgba(139,92,246,.15) 0%, transparent 60%);
}

.landing-right {
  width: 480px; background: var(--bg-surface);
  border-left: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  padding: 40px;
}

.hero-content { position: relative; z-index: 1; }

.features-list { list-style: none; margin-top: 40px; display: flex; flex-direction: column; gap: 16px; }
.feature-item { display: flex; align-items: flex-start; gap: 16px; }
.feature-icon {
  width: 42px; height: 42px; border-radius: var(--radius);
  background: rgba(99,102,241,.15); border: 1px solid rgba(99,102,241,.3);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; color: var(--primary-light); flex-shrink: 0;
}
.feature-text h4 { font-size: 15px; font-weight: 700; color: white; margin-bottom: 3px; }
.feature-text p  { font-size: 13px; color: var(--text-muted); }

.qualiopi-banner {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.3);
  padding: 8px 16px; border-radius: var(--radius-full);
  font-size: 12px; font-weight: 600; color: #34d399;
  margin-bottom: 20px;
}

.stats-row { display: flex; gap: 32px; margin-top: 48px; }
.stat-mini { text-align: center; }
.stat-mini .num { font-size: 28px; font-weight: 800; font-family: var(--font-heading); background: linear-gradient(135deg,#6366f1,#a5b4fc); -webkit-background-clip:text;-webkit-text-fill-color:transparent; }
.stat-mini .lbl { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

/* Login form */
.login-form-wrap { width: 100%; max-width: 380px; }
.login-logo { display: flex; align-items: center; gap: 12px; margin-bottom: 36px; }
.login-logo .logo-mark {
  width: 48px; height: 48px;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  border-radius: var(--radius-lg);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: white; font-weight: 900; font-family: var(--font-heading);
}
.login-logo .logo-text { font-family: var(--font-heading); font-size: 20px; font-weight: 800; }
.login-logo .logo-text span { display: block; font-size: 12px; font-weight: 400; color: var(--text-muted); }

.login-title { font-size: 26px; font-weight: 800; margin-bottom: 6px; }
.login-sub { color: var(--text-muted); font-size: 14px; margin-bottom: 32px; }

.divider { display: flex; align-items: center; gap: 12px; margin: 20px 0; color: var(--text-faint); font-size: 12px; }
.divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

@media (max-width: 900px) {
  .landing-left { display: none; }
  .landing-right { width: 100%; border-left: none; }
}
</style>
</head>
<body>
<div class="landing-page">
  <!-- LEFT: Hero -->
  <div class="landing-left">
    <div class="hero-content fade-in">
      <div class="qualiopi-banner">
        <i class="fas fa-shield-alt"></i> Certification Qualiopi · RNCP
      </div>

      <h1 style="font-size:clamp(32px,4vw,52px);font-family:var(--font-heading);font-weight:800;line-height:1.15;margin-bottom:18px">
        La plateforme LMS<br>
        <span style="background:linear-gradient(135deg,#6366f1,#a5b4fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          pour votre CFA
        </span>
      </h1>
      <p style="font-size:17px;color:var(--text-muted);max-width:460px;line-height:1.7;margin-bottom:0">
        Gérez vos formations RNCP, suivez vos apprenants et répondez aux critères Qualiopi depuis une seule plateforme engageante.
      </p>

      <ul class="features-list">
        <li class="feature-item">
          <div class="feature-icon"><i class="fas fa-certificate"></i></div>
          <div class="feature-text">
            <h4>Titres RNCP intégrés</h4>
            <p>Importez et structurez vos titres par activités types et compétences</p>
          </div>
        </li>
        <li class="feature-item">
          <div class="feature-icon"><i class="fas fa-trophy"></i></div>
          <div class="feature-text">
            <h4>Gamification & Badges</h4>
            <p>Motivez vos apprenants avec XP, niveaux et badges personnalisés</p>
          </div>
        </li>
        <li class="feature-item">
          <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
          <div class="feature-text">
            <h4>Conformité Qualiopi</h4>
            <p>Indicateurs de qualité, suivi des absences, évaluations traçables</p>
          </div>
        </li>
        <li class="feature-item">
          <div class="feature-icon"><i class="fas fa-play-circle"></i></div>
          <div class="feature-text">
            <h4>Multi-formats</h4>
            <p>Vidéos, PDF, quiz, exercices, SCORM — tout en un</p>
          </div>
        </li>
      </ul>

      <div class="stats-row">
        <div class="stat-mini"><div class="num">100%</div><div class="lbl">Qualiopi ready</div></div>
        <div class="stat-mini"><div class="num">RNCP</div><div class="lbl">Titres pro</div></div>
        <div class="stat-mini"><div class="num">∞</div><div class="lbl">Capsules</div></div>
      </div>
    </div>
  </div>

  <!-- RIGHT: Login Form -->
  <div class="landing-right">
    <div class="login-form-wrap fade-in">
      <div class="login-logo">
        <div class="logo-mark">L</div>
        <div class="logo-text"><?= e($siteName) ?><span>Espace de connexion</span></div>
      </div>

      <h2 class="login-title">Bon retour 👋</h2>
      <p class="login-sub">Connectez-vous à votre espace de formation</p>

      <?php if ($error): ?>
      <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= e($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="">
        <?= csrfField() ?>

        <div class="form-group">
          <label class="form-label">Adresse email</label>
          <div class="input-group">
            <i class="fas fa-envelope input-icon"></i>
            <input type="email" name="email" class="form-control" placeholder="votre@email.fr"
                   value="<?= e($formData['email'] ?? '') ?>" required autocomplete="email">
          </div>
        </div>

        <div class="form-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <label class="form-label" style="margin:0">Mot de passe</label>
            <a href="<?= url('forgot-password.php') ?>" style="font-size:12px">Mot de passe oublié ?</a>
          </div>
          <div class="input-group">
            <i class="fas fa-lock input-icon"></i>
            <input type="password" name="password" id="password-field" class="form-control" placeholder="••••••••" required autocomplete="current-password">
            <button type="button" onclick="togglePassword()" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer">
              <i class="fas fa-eye" id="pwd-eye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn btn-primary w-full btn-lg" style="margin-top:4px">
          <i class="fas fa-sign-in-alt"></i> Se connecter
        </button>
      </form>

      <div class="divider">ou</div>

      <a href="<?= url('register.php') ?>" class="btn btn-outline w-full">
        <i class="fas fa-user-plus"></i> Créer un compte étudiant
      </a>

      <!-- Demo accounts hint -->
      <div style="margin-top:28px;padding:16px;background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);border-radius:var(--radius);font-size:12px;color:var(--text-muted)">
        <div style="font-weight:700;color:var(--primary-light);margin-bottom:8px"><i class="fas fa-info-circle"></i> Comptes de démonstration</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px">
          <div>👑 <strong>Admin :</strong></div><div>admin@lmscfapro.fr</div>
          <div>📚 <strong>Enseignant :</strong></div><div>teacher@lmscfapro.fr</div>
          <div>🎓 <strong>Étudiant :</strong></div><div>student@lmscfapro.fr</div>
          <div style="grid-column:1/-1;margin-top:4px">🔑 <strong>Mot de passe :</strong> password</div>
        </div>
      </div>

      <p style="text-align:center;font-size:12px;color:var(--text-faint);margin-top:24px">
        © <?= date('Y') ?> <?= e($siteName) ?> · Tous droits réservés
      </p>
    </div>
  </div>
</div>

<script src="<?= asset('js/main.js') ?>"></script>
<script>
function togglePassword() {
  const f = document.getElementById('password-field');
  const eye = document.getElementById('pwd-eye');
  if (f.type === 'password') { f.type = 'text'; eye.className = 'fas fa-eye-slash'; }
  else { f.type = 'password'; eye.className = 'fas fa-eye'; }
}
</script>
</body>
</html>
