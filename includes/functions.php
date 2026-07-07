<?php
// ============================================================
// LMS CFA Pro - Fonctions utilitaires
// ============================================================

/**
 * Sécurisation des sorties HTML
 */
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Redirection sécurisée
 */
function redirect(string $url, int $code = 302): never {
    header("Location: $url", true, $code);
    exit;
}

/**
 * Token CSRF
 */
function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . e($_SESSION[CSRF_TOKEN_NAME]) . '">';
}

function verifyCsrf(): bool {
    $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function requireCsrf(): void {
    if (!verifyCsrf()) {
        http_response_code(403);
        die('CSRF token invalide.');
    }
}

/**
 * Flash messages
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlash(): array {
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

function renderFlash(): string {
    $messages = getFlash();
    if (empty($messages)) return '';
    $html = '';
    foreach ($messages as $msg) {
        $type = e($msg['type']);
        $text = e($msg['message']);
        $icons = ['success' => 'check-circle', 'error' => 'times-circle', 'warning' => 'exclamation-triangle', 'info' => 'info-circle'];
        $icon = $icons[$msg['type']] ?? 'info-circle';
        $html .= "<div class=\"alert alert-{$type}\"><i class=\"fas fa-{$icon}\"></i> {$text}</div>";
    }
    return $html;
}

/**
 * Pagination
 */
function paginate(int $total, int $perPage, int $currentPage): array {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;
    return compact('totalPages', 'currentPage', 'offset', 'total', 'perPage');
}

function renderPagination(array $p, string $baseUrl): string {
    if ($p['totalPages'] <= 1) return '';
    $html = '<nav class="pagination-nav"><ul class="pagination">';
    if ($p['currentPage'] > 1) {
        $html .= '<li><a href="' . $baseUrl . '&page=' . ($p['currentPage'] - 1) . '"><i class="fas fa-chevron-left"></i></a></li>';
    }
    for ($i = max(1, $p['currentPage'] - 2); $i <= min($p['totalPages'], $p['currentPage'] + 2); $i++) {
        $active = $i === $p['currentPage'] ? ' active' : '';
        $html .= '<li><a class="' . $active . '" href="' . $baseUrl . '&page=' . $i . '">' . $i . '</a></li>';
    }
    if ($p['currentPage'] < $p['totalPages']) {
        $html .= '<li><a href="' . $baseUrl . '&page=' . ($p['currentPage'] + 1) . '"><i class="fas fa-chevron-right"></i></a></li>';
    }
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Upload de fichier sécurisé
 */
function uploadFile(array $file, string $destination, array $allowedTypes = [], int $maxSize = 0): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [1=>'Fichier trop volumineux (ini)',2=>'Fichier trop volumineux (form)',3=>'Upload partiel',4=>'Aucun fichier'];
        return ['success' => false, 'error' => $errors[$file['error']] ?? 'Erreur inconnue'];
    }
    $maxSize = $maxSize ?: MAX_UPLOAD_SIZE;
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'Fichier trop volumineux (max ' . round($maxSize/1024/1024) . ' Mo)'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!empty($allowedTypes) && !in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => 'Type de fichier non autorisé'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $fullPath = UPLOADS_PATH . '/' . $destination . '/' . $filename;
    if (!is_dir(UPLOADS_PATH . '/' . $destination)) {
        mkdir(UPLOADS_PATH . '/' . $destination, 0755, true);
    }
    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        return ['success' => false, 'error' => 'Impossible de déplacer le fichier'];
    }
    return ['success' => true, 'filename' => $filename, 'path' => $destination . '/' . $filename, 'mime' => $mimeType, 'size' => $file['size']];
}

/**
 * Formatage
 */
function formatDate(string $date, string $format = 'd/m/Y'): string {
    if (!$date) return '-';
    try {
        $dt = new DateTimeImmutable($date);
        return $dt->format($format);
    } catch (Exception) { return '-'; }
}

function formatDateTime(string $date): string {
    if (!$date) return '-';
    try {
        $dt = new DateTimeImmutable($date);
        return $dt->format('d/m/Y H:i');
    } catch (Exception) { return '-'; }
}

function fmtSeconds(int $sec): string {
    if ($sec <= 0) return '—';
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($h > 0) return $h . 'h' . ($m > 0 ? str_pad($m, 2, '0', STR_PAD_LEFT) . 'min' : '');
    return $m > 0 ? $m . 'min' : '<1min';
}

function formatDuration(int $minutes): string {
    if ($minutes < 60) return $minutes . ' min';
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return $h . 'h' . ($m > 0 ? str_pad($m, 2, '0', STR_PAD_LEFT) : '');
}

function formatFileSize(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes/1073741824, 1) . ' Go';
    if ($bytes >= 1048576)    return round($bytes/1048576, 1) . ' Mo';
    if ($bytes >= 1024)       return round($bytes/1024, 1) . ' Ko';
    return $bytes . ' o';
}

function timeAgo(string $datetime): string {
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'il y a quelques secondes';
    if ($time < 3600) return 'il y a ' . floor($time/60) . ' min';
    if ($time < 86400) return 'il y a ' . floor($time/3600) . 'h';
    if ($time < 2592000) return 'il y a ' . floor($time/86400) . ' jours';
    if ($time < 31536000) return 'il y a ' . floor($time/2592000) . ' mois';
    return 'il y a ' . floor($time/31536000) . ' an(s)';
}

function slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(['é','è','ê','ë'],'e',$text);
    $text = str_replace(['à','â','ä'],'a',$text);
    $text = str_replace(['ù','û','ü'],'u',$text);
    $text = str_replace(['ô','ö'],'o',$text);
    $text = str_replace(['î','ï'],'i',$text);
    $text = str_replace(['ç'],'c',$text);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

/**
 * Génération d'avatar initiales SVG
 */
function getAvatarUrl(?string $avatar, string $firstName, string $lastName): string {
    if ($avatar && file_exists(UPLOADS_PATH . '/avatars/' . $avatar)) {
        return UPLOADS_URL . '/avatars/' . $avatar;
    }
    return BASE_URL . '/api/avatar.php?name=' . urlencode($firstName[0] . $lastName[0]);
}

function getAvatarInitials(string $firstName, string $lastName): string {
    return strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
}

function getAvatarColor(string $name): string {
    $colors = ['#6366f1','#8b5cf6','#0ea5e9','#10b981','#f59e0b','#ef4444','#ec4899','#14b8a6'];
    return $colors[abs(crc32($name)) % count($colors)];
}

/**
 * Niveau XP
 */
function getLevel(int $xp): int {
    return max(1, (int)floor($xp / XP_PER_LEVEL) + 1);
}

function getXpToNextLevel(int $xp): int {
    $level = getLevel($xp);
    return ($level * XP_PER_LEVEL) - $xp;
}

function getLevelProgress(int $xp): int {
    return $xp % XP_PER_LEVEL;
}

function getLevelName(int $level): string {
    $names = [1=>'Débutant',2=>'Apprenti',3=>'Étudiant',4=>'Confirmé',5=>'Avancé',6=>'Expert',7=>'Maître',8=>'Champion',9=>'Légende',10=>'Maître Suprême'];
    return $names[min($level, 10)] ?? 'Maître Suprême';
}

/**
 * Paramètre applicatif
 */
function getSetting(string $key, string $default = ''): string {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? ($row['setting_value'] ?? $default) : $default;
}

/**
 * Notification
 */
function createNotification(int $userId, string $title, string $message, string $type = 'info', string $actionUrl = ''): void {
    $pdo = getDB();
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, message, type, action_url) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $title, $message, $type, $actionUrl]);
}

/**
 * Contrôle d'accès unifié : enrollment OU access_grant
 * $scope peut contenir formation_id, module_id, sequence_id, competency_id, activity_type_id, rncp_title_id
 */
function hasContentAccess(int $userId, array $scope): bool {
    $pdo = getDB();

    $formationId    = (int)($scope['formation_id']    ?? 0);
    $moduleId       = (int)($scope['module_id']       ?? 0);
    $sequenceId     = (int)($scope['sequence_id']     ?? 0);
    $competencyId   = (int)($scope['competency_id']   ?? 0);
    $activityTypeId = (int)($scope['activity_type_id'] ?? 0);
    $rncpTitleId    = (int)($scope['rncp_title_id']   ?? 0);

    // 1. Inscription formation (mécanisme existant, prioritaire)
    if ($formationId) {
        $e = $pdo->prepare("SELECT id FROM enrollments WHERE user_id=? AND formation_id=? AND status IN ('active','completed') LIMIT 1");
        $e->execute([$userId, $formationId]);
        if ($e->fetch()) return true;
    }

    // 2. Résolution de l'ascendance depuis module_id (requêtes séquentielles simples)
    if ($moduleId && !$sequenceId) {
        try {
            $r = $pdo->prepare("SELECT sequence_id FROM modules WHERE id=?");
            $r->execute([$moduleId]);
            $sequenceId = (int)$r->fetchColumn() ?: $sequenceId;
        } catch (\Exception $e) {}
    }
    if ($sequenceId && !$competencyId) {
        try {
            $r = $pdo->prepare("SELECT competency_id FROM sequences WHERE id=?");
            $r->execute([$sequenceId]);
            $competencyId = (int)$r->fetchColumn() ?: $competencyId;
        } catch (\Exception $e) {}
    }
    if ($competencyId && !$activityTypeId) {
        try {
            $r = $pdo->prepare("SELECT activity_type_id FROM competencies WHERE id=?");
            $r->execute([$competencyId]);
            $activityTypeId = (int)$r->fetchColumn() ?: $activityTypeId;
        } catch (\Exception $e) {}
    }
    if ($activityTypeId && !$rncpTitleId) {
        try {
            $r = $pdo->prepare("SELECT rncp_title_id FROM activity_types WHERE id=?");
            $r->execute([$activityTypeId]);
            $rncpTitleId = (int)$r->fetchColumn() ?: $rncpTitleId;
        } catch (\Exception $e) {}
    }

    // 3. Vérification access_grants (du plus précis au plus large)
    $pairs = [];
    if ($moduleId)       $pairs[] = ['module',        $moduleId];
    if ($sequenceId)     $pairs[] = ['sequence',       $sequenceId];
    if ($competencyId)   $pairs[] = ['competency',     $competencyId];
    if ($activityTypeId) $pairs[] = ['activity_type',  $activityTypeId];
    if ($rncpTitleId)    $pairs[] = ['rncp_title',     $rncpTitleId];

    if (empty($pairs)) return false;

    try {
        foreach ($pairs as [$type, $id]) {
            $g = $pdo->prepare("SELECT id FROM access_grants WHERE user_id=? AND scope_type=? AND scope_id=? AND revoked_at IS NULL LIMIT 1");
            $g->execute([$userId, $type, $id]);
            if ($g->fetch()) return true;
        }
    } catch (\Exception $e) { /* table pas encore créée */ }

    // 4. Vérification cohort_access_grants (l'utilisateur est dans une cohorte ayant l'accès)
    try {
        foreach ($pairs as [$type, $id]) {
            $g = $pdo->prepare("
                SELECT cag.id FROM cohort_access_grants cag
                JOIN cohort_members cm ON cm.cohort_id = cag.cohort_id
                WHERE cm.student_id = ? AND cag.scope_type = ? AND cag.scope_id = ?
                  AND cag.revoked_at IS NULL
                LIMIT 1
            ");
            $g->execute([$userId, $type, $id]);
            if ($g->fetch()) return true;
        }
    } catch (\Exception $e) { /* table cohort_access_grants absente */ }

    return false;
}

/**
 * Audit log
 */
function auditLog(string $action, string $entityType = '', int $entityId = 0, array $oldValues = [], array $newValues = []): void {
    $pdo = getDB();
    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $stmt = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$userId, $action, $entityType ?: null, $entityId ?: null,
        $oldValues ? json_encode($oldValues) : null,
        $newValues ? json_encode($newValues) : null,
        $ip, $ua]);
}

/**
 * XP & Gamification
 */
function addXP(int $userId, int $amount, string $reason, string $refType = '', int $refId = 0): void {
    $pdo = getDB();
    $pdo->prepare('UPDATE users SET xp_points = xp_points + ?, level = GREATEST(1, FLOOR((xp_points + ?) / ? ) + 1) WHERE id = ?')
        ->execute([$amount, $amount, XP_PER_LEVEL, $userId]);
    $pdo->prepare('INSERT INTO xp_transactions (user_id, amount, reason, reference_type, reference_id) VALUES (?,?,?,?,?)')
        ->execute([$userId, $amount, $reason, $refType ?: null, $refId ?: null]);
    checkBadges($userId);
}

function checkBadges(int $userId): void {
    $pdo = getDB();
    $user = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $user->execute([$userId]);
    $userData = $user->fetch();
    if (!$userData) return;

    $earnedBadges = $pdo->prepare('SELECT badge_id FROM user_badges WHERE user_id = ?');
    $earnedBadges->execute([$userId]);
    $earned = array_column($earnedBadges->fetchAll(), 'badge_id');

    $allBadges = $pdo->query('SELECT * FROM badges WHERE is_active = 1')->fetchAll();

    foreach ($allBadges as $badge) {
        if (in_array($badge['id'], $earned)) continue;

        $met = false;
        switch ($badge['criteria_type']) {
            case 'lessons_completed':
                $count = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress WHERE user_id = ? AND status = "completed"');
                $count->execute([$userId]);
                $met = $count->fetchColumn() >= $badge['criteria_value'];
                break;
            case 'streak_days':
                $met = ($userData['streak_days'] ?? 0) >= $badge['criteria_value'];
                break;
            case 'xp_earned':
                $met = ($userData['xp_points'] ?? 0) >= $badge['criteria_value'];
                break;
            case 'formations_completed':
                $count = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND status = "completed"');
                $count->execute([$userId]);
                $met = $count->fetchColumn() >= $badge['criteria_value'];
                break;
        }

        if ($met) {
            $pdo->prepare('INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?,?)')->execute([$userId, $badge['id']]);
            createNotification($userId, 'Badge obtenu : ' . $badge['name'], $badge['description'], 'badge');
            addXP($userId, $badge['xp_reward'], 'Badge obtenu: ' . $badge['name'], 'badge', $badge['id']);
        }
    }
}

/**
 * Progress formation
 */
function updateEnrollmentProgress(int $userId, int $formationId): void {
    $pdo = getDB();
    $total = $pdo->prepare('SELECT COUNT(l.id) FROM lessons l JOIN modules m ON l.module_id = m.id WHERE m.formation_id = ? AND l.is_mandatory = 1');
    $total->execute([$formationId]);
    $totalCount = (int)$total->fetchColumn();
    if ($totalCount === 0) return;

    $done = $pdo->prepare('SELECT COUNT(lp.id) FROM lesson_progress lp JOIN lessons l ON lp.lesson_id = l.id JOIN modules m ON l.module_id = m.id WHERE m.formation_id = ? AND lp.user_id = ? AND lp.status = "completed"');
    $done->execute([$formationId, $userId]);
    $doneCount = (int)$done->fetchColumn();

    $percent = min(100, (int)round(($doneCount / $totalCount) * 100));

    $pdo->prepare('UPDATE enrollments SET progress_percent = ? WHERE user_id = ? AND formation_id = ?')
        ->execute([$percent, $userId, $formationId]);

    if ($percent === 100) {
        $pdo->prepare('UPDATE enrollments SET status = "completed", completion_date = NOW() WHERE user_id = ? AND formation_id = ? AND status = "active"')
            ->execute([$userId, $formationId]);
        addXP($userId, XP_FORMATION_COMPLETE, 'Formation terminée', 'formation', $formationId);
        createNotification($userId, 'Formation terminée !', 'Félicitations, vous avez terminé votre formation.', 'success');
    }
}

/**
 * Validation input
 */
function sanitizeInput(mixed $value): string {
    return trim(htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
}

function sanitizeRichHtml(?string $html): string {
    if ($html === null) return '';
    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><h1><h2><h3><h4><h5><a><blockquote><hr><img><span><div>';
    return strip_tags($html, $allowed);
}

function validateEmail(string $email): bool {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePassword(string $password): bool {
    return strlen($password) >= 8;
}

/**
 * URL helpers
 */
function url(string $path = ''): string {
    return BASE_URL . '/' . ltrim($path, '/');
}

function asset(string $path): string {
    return ASSETS_URL . '/' . ltrim($path, '/');
}

function uploadUrl(string $path): string {
    return UPLOADS_URL . '/' . ltrim($path, '/');
}

function isActive(string $page): string {
    $current = basename($_SERVER['PHP_SELF'], '.php');
    return $current === $page ? 'active' : '';
}

function currentUrlContains(string $needle): bool {
    return str_contains($_SERVER['REQUEST_URI'], $needle);
}

/**
 * Role checks
 */
function getRoleBadge(string $role): string {
    $badges = [
        'admin'     => '<span class="badge badge-danger">Admin</span>',
        'pedagogy'  => '<span class="badge badge-purple">Pédagogie</span>',
        'teacher'   => '<span class="badge badge-info">Enseignant</span>',
        'student'   => '<span class="badge badge-success">Étudiant</span>',
    ];
    return $badges[$role] ?? '';
}

function getRoleLabel(string $role): string {
    $labels = ['admin'=>'Administrateur','pedagogy'=>'Pédagogie','teacher'=>'Enseignant','student'=>'Étudiant'];
    return $labels[$role] ?? $role;
}

function getStatusBadge(string $status): string {
    $badges = [
        'active'    => '<span class="badge badge-success">Actif</span>',
        'inactive'  => '<span class="badge badge-secondary">Inactif</span>',
        'pending'   => '<span class="badge badge-warning">En attente</span>',
        'suspended' => '<span class="badge badge-danger">Suspendu</span>',
        'completed' => '<span class="badge badge-info">Terminé</span>',
        'draft'     => '<span class="badge badge-secondary">Brouillon</span>',
    ];
    return $badges[$status] ?? '<span class="badge badge-secondary">' . e($status) . '</span>';
}

/**
 * Contenu type icône
 */
function getContentTypeIcon(string $type): string {
    $icons = [
        'video'        => 'fas fa-play-circle text-red-400',
        'pdf'          => 'fas fa-file-pdf text-red-500',
        'document'     => 'fas fa-file-word text-blue-500',
        'presentation' => 'fas fa-file-powerpoint text-orange-500',
        'quiz'         => 'fas fa-question-circle text-purple-500',
        'exercise'     => 'fas fa-pencil-alt text-green-500',
        'text'         => 'fas fa-align-left text-gray-400',
        'scorm'        => 'fas fa-cube text-indigo-500',
        'link'         => 'fas fa-link text-sky-400',
    ];
    return $icons[$type] ?? 'fas fa-file text-gray-400';
}

function getRarityColor(string $rarity): string {
    $colors = ['common'=>'#9ca3af','uncommon'=>'#22c55e','rare'=>'#3b82f6','epic'=>'#a855f7','legendary'=>'#f59e0b'];
    return $colors[$rarity] ?? '#9ca3af';
}
