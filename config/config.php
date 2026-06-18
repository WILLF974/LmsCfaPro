<?php
// ============================================================
// LMS CFA Pro - Configuration générale
// ============================================================

// Environnement
define('ENV', getenv('APP_ENV') ?: 'production');
define('DEBUG', ENV === 'development');

// URL de base — détection automatique ou override via variable d'environnement
if (getenv('APP_URL')) {
    define('BASE_URL', rtrim(getenv('APP_URL'), '/'));
} elseif (ENV === 'production') {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('BASE_URL', $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
} else {
    define('BASE_URL', 'http://localhost:8080');
}

// Chemins physiques
define('ROOT_PATH',    dirname(__DIR__));
define('CONFIG_PATH',  ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('ASSETS_PATH',  ROOT_PATH . '/assets');

// URL des uploads
define('UPLOADS_URL',  BASE_URL . '/uploads');
define('ASSETS_URL',   BASE_URL . '/assets');

// Sécurité
define('CSRF_TOKEN_NAME', 'lms_csrf_token');
define('SESSION_NAME', 'LMSCFAPRO_SESSION');
define('SESSION_LIFETIME', 7200); // 2 heures
define('SECRET_KEY', getenv('APP_SECRET') ?: 'lmscfapro_prod_k3y_2026_s3cur3_@fr!');

// Upload
define('MAX_UPLOAD_SIZE', 50 * 1024 * 1024); // 50 Mo
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_DOC_TYPES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'video/mp4', 'video/webm', 'video/ogg',
    'application/zip',
]);

// Pagination
define('ITEMS_PER_PAGE', 20);

// Gamification
define('XP_PER_LEVEL', 100);
define('XP_LESSON_COMPLETE', 10);
define('XP_QUIZ_PASS', 50);
define('XP_MODULE_COMPLETE', 100);
define('XP_FORMATION_COMPLETE', 500);

// Erreurs PHP
if (DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Timezone
date_default_timezone_set('Europe/Paris');

// Charset
mb_internal_encoding('UTF-8');

// Autoload des includes
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/auth.php';

// Démarrage session sécurisée
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => (ENV === 'production'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Regénérer le token CSRF si absent
if (empty($_SESSION[CSRF_TOKEN_NAME])) {
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}
