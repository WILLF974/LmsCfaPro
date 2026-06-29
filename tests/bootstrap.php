<?php
// Stubs minimaux pour charger functions.php sans DB ni serveur web

define('BASE_URL',         'https://lmscfapro.fr');
define('UPLOADS_URL',      'https://lmscfapro.fr/uploads');
define('UPLOADS_PATH',     '/tmp/lms_uploads_test');
define('ROOT_PATH',        dirname(__DIR__));
define('CSRF_TOKEN_NAME',  'lms_csrf_token');
define('SESSION_NAME',     'LMSCFAPRO_SESSION');
define('SESSION_LIFETIME', 7200);
define('SECRET_KEY',       'test_secret');
define('MAX_UPLOAD_SIZE',  50 * 1024 * 1024);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png']);
define('ALLOWED_DOC_TYPES',   ['application/pdf']);
define('ITEMS_PER_PAGE',   20);
define('DEBUG',            false);
define('XP_PER_LEVEL',     500);
define('XP_FORMATION_COMPLETE', 200);
define('ASSETS_URL',       'https://lmscfapro.fr/assets');

// Session stub
$_SESSION = [];

// Fonctions globales inutilisables en test — stubs vides
if (!function_exists('getDB')) {
    function getDB(): never { throw new RuntimeException('DB non disponible en test unitaire'); }
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/includes/functions.php';
