<?php
// ============================================================
// LMS CFA Pro - Authentification et autorisation
// ============================================================

/**
 * Vérifier si l'utilisateur est connecté
 */
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_role']);
}

/**
 * Récupérer l'utilisateur courant
 */
function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND status = "active"');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

/**
 * Vérifier le rôle
 */
function hasRole(string ...$roles): bool {
    if (!isLoggedIn()) return false;
    return in_array($_SESSION['user_role'], $roles);
}

function isAdmin(): bool    { return hasRole('admin'); }
function isPedagogy(): bool { return hasRole('admin', 'pedagogy'); }
function isTeacher(): bool  { return hasRole('admin', 'pedagogy', 'teacher'); }
function isStudent(): bool  { return hasRole('student'); }

/**
 * Protéger une page — rediriger si non connecté
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        setFlash('error', 'Veuillez vous connecter pour accéder à cette page.');
        redirect(BASE_URL . '/index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    }
    // Mettre à jour last_activity
    $pdo = getDB();
    $pdo->prepare('UPDATE users SET last_activity = NOW() WHERE id = ?')->execute([$_SESSION['user_id']]);
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        http_response_code(403);
        setFlash('error', 'Accès non autorisé.');
        redirect(getDashboardUrl());
    }
}

function requireAdmin(): void    { requireRole('admin'); }
function requirePedagogy(): void { requireRole('admin', 'pedagogy'); }
function requireTeacher(): void  { requireRole('admin', 'pedagogy', 'teacher'); }

/**
 * URL du tableau de bord selon le rôle
 */
function getDashboardUrl(): string {
    $role = $_SESSION['user_role'] ?? 'student';
    return match($role) {
        'admin'     => BASE_URL . '/admin/index.php',
        'pedagogy'  => BASE_URL . '/pedagogy/index.php',
        'teacher'   => BASE_URL . '/teacher/index.php',
        default     => BASE_URL . '/student/index.php',
    };
}

/**
 * Connexion
 */
function login(string $email, string $password, bool $remember = false): array {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        auditLog('login_failed', 'user', 0, [], ['email' => $email]);
        return ['success' => false, 'error' => 'Email ou mot de passe incorrect.'];
    }
    if ($user['status'] === 'pending') {
        return ['success' => false, 'error' => 'Votre compte est en attente de validation par un administrateur.'];
    }
    if ($user['status'] === 'suspended') {
        return ['success' => false, 'error' => 'Votre compte a été suspendu. Contactez l\'administrateur.'];
    }
    if ($user['status'] === 'inactive') {
        return ['success' => false, 'error' => 'Votre compte est désactivé.'];
    }

    // Mise à jour session
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];

    // Update last_login & streak
    $lastLogin = $user['last_login'] ? date('Y-m-d', strtotime($user['last_login'])) : null;
    $today = date('Y-m-d');
    $streak = $user['streak_days'];
    if ($lastLogin === date('Y-m-d', strtotime('-1 day'))) {
        $streak++;
    } elseif ($lastLogin !== $today) {
        $streak = 1;
    }

    $pdo->prepare('UPDATE users SET last_login = NOW(), streak_days = ? WHERE id = ?')->execute([$streak, $user['id']]);

    // Badge premier login
    $firstBadge = $pdo->prepare('SELECT COUNT(*) FROM user_badges ub JOIN badges b ON ub.badge_id = b.id WHERE ub.user_id = ? AND b.criteria_type = "first_login"');
    $firstBadge->execute([$user['id']]);
    if ($firstBadge->fetchColumn() == 0) {
        $badge = $pdo->query('SELECT id FROM badges WHERE criteria_type = "first_login" LIMIT 1')->fetch();
        if ($badge) {
            $pdo->prepare('INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?,?)')->execute([$user['id'], $badge['id']]);
        }
    }

    auditLog('login_success', 'user', $user['id']);
    return ['success' => true, 'user' => $user];
}

/**
 * Déconnexion
 */
function logout(): void {
    auditLog('logout', 'user', $_SESSION['user_id'] ?? 0);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Inscription
 */
function register(array $data): array {
    $pdo = getDB();

    $email = strtolower(trim($data['email'] ?? ''));
    $password = $data['password'] ?? '';
    $firstName = trim($data['first_name'] ?? '');
    $lastName  = trim($data['last_name'] ?? '');

    if (!validateEmail($email)) {
        return ['success' => false, 'error' => 'Email invalide.'];
    }
    if (!validatePassword($password)) {
        return ['success' => false, 'error' => 'Le mot de passe doit contenir au moins 8 caractères.'];
    }
    if (empty($firstName) || empty($lastName)) {
        return ['success' => false, 'error' => 'Prénom et nom sont obligatoires.'];
    }

    $existing = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $existing->execute([$email]);
    if ($existing->fetch()) {
        return ['success' => false, 'error' => 'Cet email est déjà utilisé.'];
    }

    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));

    $requiresApproval = getSetting('registration_requires_approval', '1') === '1';
    $status = $requiresApproval ? 'pending' : 'active';
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $pdo->prepare('INSERT INTO users (uuid, email, password, role, first_name, last_name, status) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$uuid, $email, $hash, 'student', $firstName, $lastName, $status]);
    $userId = (int)$pdo->lastInsertId();

    auditLog('register', 'user', $userId, [], ['email' => $email]);

    // Notifier les admins
    $admins = $pdo->query('SELECT id FROM users WHERE role IN ("admin","pedagogy") AND status = "active"')->fetchAll();
    foreach ($admins as $admin) {
        createNotification($admin['id'], 'Nouvelle inscription', "$firstName $lastName vient de s'inscrire.", 'info', BASE_URL . '/admin/users/index.php');
    }

    return ['success' => true, 'user_id' => $userId, 'requires_approval' => $requiresApproval];
}

/**
 * Changer le mot de passe
 */
function changePassword(int $userId, string $currentPassword, string $newPassword): array {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, $user['password'])) {
        return ['success' => false, 'error' => 'Mot de passe actuel incorrect.'];
    }
    if (!validatePassword($newPassword)) {
        return ['success' => false, 'error' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.'];
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $userId]);
    auditLog('password_changed', 'user', $userId);
    return ['success' => true];
}
