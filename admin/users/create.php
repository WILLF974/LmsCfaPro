<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireAdmin();

$pdo = getDB();
$errors = [];
$formData = ['role' => 'student', 'status' => 'active'];
$editMode = isset($_GET['id']);
$userData = null;

if ($editMode) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $userData = $stmt->fetch();
    if (!$userData) { setFlash('error', 'Utilisateur introuvable.'); redirect(url('admin/users/index.php')); }
    $formData = $userData;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $formData = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name'  => trim($_POST['last_name'] ?? ''),
        'email'      => trim($_POST['email'] ?? ''),
        'role'       => $_POST['role'] ?? 'student',
        'status'     => $_POST['status'] ?? 'active',
        'phone'      => trim($_POST['phone'] ?? ''),
        'birth_date' => $_POST['birth_date'] ?: null,
        'bio'        => trim($_POST['bio'] ?? ''),
    ];
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // Validation
    if (empty($formData['first_name']))  $errors[] = 'Le prénom est obligatoire.';
    if (empty($formData['last_name']))   $errors[] = 'Le nom est obligatoire.';
    if (empty($formData['email']) || !validateEmail($formData['email'])) $errors[] = 'Adresse email invalide.';
    if (!in_array($formData['role'], ['admin','pedagogy','teacher','student'])) $errors[] = 'Rôle invalide.';

    if (!$editMode) {
        if (empty($password))                      $errors[] = 'Le mot de passe est obligatoire.';
        elseif (!validatePassword($password))      $errors[] = 'Le mot de passe doit faire au moins 8 caractères.';
        elseif ($password !== $passwordConfirm)    $errors[] = 'Les mots de passe ne correspondent pas.';
    } elseif (!empty($password)) {
        if (!validatePassword($password))       $errors[] = 'Le mot de passe doit faire au moins 8 caractères.';
        elseif ($password !== $passwordConfirm) $errors[] = 'Les mots de passe ne correspondent pas.';
    }

    // Email unique
    if (empty($errors)) {
        $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $checkEmail->execute([$formData['email'], $editMode ? $userData['id'] : 0]);
        if ($checkEmail->fetch()) $errors[] = 'Cet email est déjà utilisé.';
    }

    if (empty($errors)) {
        if ($editMode) {
            $sql = 'UPDATE users SET first_name=?,last_name=?,email=?,role=?,status=?,phone=?,birth_date=?,bio=? WHERE id=?';
            $params = [$formData['first_name'],$formData['last_name'],$formData['email'],$formData['role'],$formData['status'],$formData['phone']?:null,$formData['birth_date'],$formData['bio']?:null,$userData['id']];
            if (!empty($password)) {
                $sql = 'UPDATE users SET first_name=?,last_name=?,email=?,role=?,status=?,phone=?,birth_date=?,bio=?,password=? WHERE id=?';
                $params = [$formData['first_name'],$formData['last_name'],$formData['email'],$formData['role'],$formData['status'],$formData['phone']?:null,$formData['birth_date'],$formData['bio']?:null,password_hash($password, PASSWORD_DEFAULT),$userData['id']];
            }
            $pdo->prepare($sql)->execute($params);
            auditLog('user_updated', 'user', $userData['id']);
            setFlash('success', 'Utilisateur mis à jour.');
        } else {
            $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
            $pdo->prepare('INSERT INTO users (uuid, first_name, last_name, email, password, role, status, phone, birth_date, bio, email_verified_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())')->execute([$uuid,$formData['first_name'],$formData['last_name'],$formData['email'],password_hash($password,PASSWORD_DEFAULT),$formData['role'],$formData['status'],$formData['phone']?:null,$formData['birth_date'],$formData['bio']?:null]);
            $newId = (int)$pdo->lastInsertId();
            auditLog('user_created', 'user', $newId);
            setFlash('success', 'Utilisateur créé avec succès.');
        }
        redirect(url('admin/users/index.php'));
    }
}

$pageTitle = $editMode ? 'Modifier l\'utilisateur' : 'Créer un utilisateur';
renderHead($pageTitle);
renderSidebar('admin');
renderTopbar($pageTitle, [['Utilisateurs', url('admin/users/index.php')], [$pageTitle, '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>
  <?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="POST" style="max-width:800px">
    <?= csrfField() ?>
    <div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start">

      <div style="display:flex;flex-direction:column;gap:20px">
        <!-- Identité -->
        <div class="card">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-user"></i> Identité</h3></div>
          <div class="card-body">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Prénom <span class="required">*</span></label>
                <input type="text" name="first_name" class="form-control" placeholder="Marie" value="<?= e($formData['first_name'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Nom <span class="required">*</span></label>
                <input type="text" name="last_name" class="form-control" placeholder="Dupont" value="<?= e($formData['last_name'] ?? '') ?>" required>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Adresse email <span class="required">*</span></label>
              <div class="input-group">
                <i class="fas fa-envelope input-icon"></i>
                <input type="email" name="email" class="form-control" placeholder="marie.dupont@email.fr" value="<?= e($formData['email'] ?? '') ?>" required>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Téléphone</label>
                <input type="tel" name="phone" class="form-control" placeholder="06 12 34 56 78" value="<?= e($formData['phone'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Date de naissance</label>
                <input type="date" name="birth_date" class="form-control" value="<?= e($formData['birth_date'] ?? '') ?>">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Biographie</label>
              <textarea name="bio" class="form-control" rows="3" placeholder="Présentation courte..."><?= e($formData['bio'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <!-- Mot de passe -->
        <div class="card">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-lock"></i> <?= $editMode ? 'Changer le mot de passe' : 'Mot de passe' ?></h3></div>
          <div class="card-body">
            <?php if ($editMode): ?>
            <div class="alert alert-info" style="margin-bottom:12px"><i class="fas fa-info-circle"></i> Laisser vide pour ne pas modifier le mot de passe.</div>
            <?php endif; ?>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Mot de passe <?= !$editMode ? '<span class="required">*</span>' : '' ?></label>
                <input type="password" name="password" class="form-control" placeholder="Min. 8 caractères" <?= !$editMode ? 'required' : '' ?>>
              </div>
              <div class="form-group">
                <label class="form-label">Confirmer</label>
                <input type="password" name="password_confirm" class="form-control" placeholder="Répéter le mot de passe">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Latérale -->
      <div style="display:flex;flex-direction:column;gap:16px">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Rôle & Statut</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Rôle <span class="required">*</span></label>
              <select name="role" class="form-control">
                <option value="student"   <?= ($formData['role'] ?? '') === 'student'   ? 'selected' : '' ?>>Étudiant</option>
                <option value="teacher"   <?= ($formData['role'] ?? '') === 'teacher'   ? 'selected' : '' ?>>Enseignant</option>
                <option value="pedagogy"  <?= ($formData['role'] ?? '') === 'pedagogy'  ? 'selected' : '' ?>>Pédagogie</option>
                <option value="admin"     <?= ($formData['role'] ?? '') === 'admin'     ? 'selected' : '' ?>>Administrateur</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Statut</label>
              <select name="status" class="form-control">
                <option value="active"    <?= ($formData['status'] ?? '') === 'active'    ? 'selected' : '' ?>>Actif</option>
                <option value="pending"   <?= ($formData['status'] ?? '') === 'pending'   ? 'selected' : '' ?>>En attente</option>
                <option value="inactive"  <?= ($formData['status'] ?? '') === 'inactive'  ? 'selected' : '' ?>>Inactif</option>
                <option value="suspended" <?= ($formData['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspendu</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center">
              <i class="fas fa-save"></i> <?= $editMode ? 'Enregistrer' : 'Créer l\'utilisateur' ?>
            </button>
            <?php if ($editMode && ($userData['role'] ?? '') === 'student'): ?>
            <a href="<?= url('admin/users/progress.php?id='.$userData['id']) ?>" class="btn btn-secondary w-full" style="justify-content:center;margin-top:8px">
              <i class="fas fa-chart-line"></i> Suivi pédagogique
            </a>
            <?php endif; ?>
            <a href="<?= url('admin/users/index.php') ?>" class="btn btn-ghost w-full" style="justify-content:center;margin-top:8px">Annuler</a>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>
<?php renderFooter(); ?>
