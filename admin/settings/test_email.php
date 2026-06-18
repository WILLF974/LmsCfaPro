<?php
/**
 * Test d'envoi SMTP — endpoint AJAX
 * Accepte les paramètres SMTP directement en POST (formulaire non sauvegardé)
 * ou lit les settings en base si les champs sont absents.
 * Retourne JSON {success, message}
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireAdmin();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

if (!verifyCsrf()) {
    echo json_encode(['success' => false, 'message' => 'Token CSRF invalide.']);
    exit;
}

$to = trim($_POST['test_to'] ?? '');
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Adresse email destinataire invalide.']);
    exit;
}

// Lire la config : préférer les valeurs envoyées depuis le formulaire (non sauvegardées),
// sinon charger depuis la base de données.
function cfg(string $key, string $postKey): string {
    $v = trim($_POST[$postKey] ?? '');
    return $v !== '' ? $v : getSetting($key, '');
}

$smtpHost = cfg('smtp_host', 'smtp_host');
$smtpPort = (int)(trim($_POST['smtp_port'] ?? '') ?: getSetting('smtp_port', '587'));
$smtpUser = cfg('smtp_user', 'smtp_user');
$smtpPass = cfg('smtp_pass', 'smtp_pass');
$smtpFrom = cfg('smtp_from', 'smtp_from') ?: $smtpUser;
$siteName = getSetting('site_name', 'LMS CFA Pro');

if (!$smtpHost || !$smtpUser || !$smtpPass) {
    echo json_encode(['success' => false, 'message' => 'Configuration SMTP incomplète (hôte, utilisateur ou mot de passe manquant).']);
    exit;
}

$result = smtpSendTest($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpFrom, $siteName, $to);
auditLog('smtp_test', 'settings', 0, [], ['to' => $to, 'success' => $result['success']]);

echo json_encode($result);
exit;

// ── Client SMTP natif ─────────────────────────────────────────
function smtpSendTest(
    string $host, int $port,
    string $user, string $pass, string $from, string $siteName,
    string $to
): array {
    $useSsl      = ($port === 465);
    $useStarttls = ($port === 587 || $port === 25);

    $ctx = stream_context_create(['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]]);

    $addr   = ($useSsl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($addr, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);

    if (!$socket) {
        return ['success' => false, 'message' => "Impossible de joindre le serveur SMTP ($host:$port) — $errstr (code $errno). Vérifiez l'hôte et le port."];
    }

    stream_set_timeout($socket, 10);

    $read = function () use ($socket): string {
        $buf = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $buf .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $buf;
    };

    $send = function (string $cmd) use ($socket, $read): string {
        fwrite($socket, $cmd . "\r\n");
        return $read();
    };

    $code = fn(string $r): int => (int)substr(ltrim($r), 0, 3);

    // Greeting
    $r = $read();
    if ($code($r) !== 220) {
        fclose($socket);
        return ['success' => false, 'message' => "Accueil serveur inattendu : " . trim($r)];
    }

    // EHLO
    $ehlo = gethostname() ?: 'lmscfapro';
    $r = $send("EHLO $ehlo");
    if ($code($r) !== 250) {
        fclose($socket);
        return ['success' => false, 'message' => "EHLO refusé : " . trim($r)];
    }

    // STARTTLS
    if ($useStarttls) {
        $r = $send("STARTTLS");
        if ($code($r) !== 220) {
            fclose($socket);
            return ['success' => false, 'message' => "STARTTLS refusé par le serveur : " . trim($r)];
        }
        $ok = stream_socket_enable_crypto($socket, true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT
        );
        if (!$ok) {
            fclose($socket);
            return ['success' => false, 'message' => "Échec de la négociation TLS. Vérifiez le certificat du serveur SMTP."];
        }
        $r = $send("EHLO $ehlo");
        if ($code($r) !== 250) {
            fclose($socket);
            return ['success' => false, 'message' => "EHLO post-TLS refusé : " . trim($r)];
        }
    }

    // AUTH LOGIN
    $r = $send("AUTH LOGIN");
    if ($code($r) !== 334) {
        fclose($socket);
        return ['success' => false, 'message' => "AUTH LOGIN non supporté ou refusé : " . trim($r)];
    }
    $r = $send(base64_encode($user));
    if ($code($r) !== 334) {
        fclose($socket);
        return ['success' => false, 'message' => "Identifiant SMTP refusé. Vérifiez le nom d'utilisateur."];
    }
    $r = $send(base64_encode($pass));
    if ($code($r) !== 235) {
        fclose($socket);
        return ['success' => false, 'message' => "Authentification échouée (code " . $code($r) . "). Vérifiez le mot de passe SMTP."];
    }

    // MAIL FROM
    $r = $send("MAIL FROM:<$from>");
    if ($code($r) !== 250) {
        fclose($socket);
        return ['success' => false, 'message' => "Expéditeur refusé ($from) : " . trim($r)];
    }

    // RCPT TO
    $r = $send("RCPT TO:<$to>");
    if ($code($r) !== 250) {
        fclose($socket);
        return ['success' => false, 'message' => "Destinataire refusé ($to) : " . trim($r)];
    }

    // DATA
    $r = $send("DATA");
    if ($code($r) !== 354) {
        fclose($socket);
        return ['success' => false, 'message' => "Commande DATA refusée : " . trim($r)];
    }

    $now  = date('r');
    $body = <<<HTML
    <div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;background:#f8fafc;padding:32px;border-radius:8px">
      <div style="background:#6366f1;border-radius:8px 8px 0 0;padding:24px;text-align:center">
        <h1 style="color:white;margin:0;font-size:22px">{$siteName}</h1>
        <p style="color:rgba(255,255,255,.8);margin:6px 0 0;font-size:13px">Test de configuration SMTP</p>
      </div>
      <div style="background:white;border-radius:0 0 8px 8px;padding:28px">
        <p style="color:#1e293b;font-size:15px">Bonjour,</p>
        <p style="color:#475569">Cet email confirme que la configuration SMTP de votre plateforme <strong>{$siteName}</strong> est correctement paramétrée.</p>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:16px;margin:20px 0">
          <p style="color:#15803d;margin:0;font-size:14px"><strong>✓ Connexion SMTP établie avec succès</strong><br>
          <span style="font-size:12px;opacity:.8">Serveur : {$host}:{$port} · Envoyé le {$now}</span></p>
        </div>
        <p style="color:#94a3b8;font-size:12px;margin-top:24px">Ce message a été envoyé automatiquement depuis le panneau d'administration de {$siteName}.</p>
      </div>
    </div>
    HTML;

    $msg  = "Date: $now\r\n";
    $msg .= "From: =?UTF-8?B?" . base64_encode($siteName) . "?= <$from>\r\n";
    $msg .= "To: $to\r\n";
    $msg .= "Subject: =?UTF-8?B?" . base64_encode("[Test SMTP] $siteName") . "?=\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($body));
    $msg .= "\r\n.";

    $r = $send($msg);
    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    if ($code($r) !== 250) {
        return ['success' => false, 'message' => "Message rejeté par le serveur : " . trim($r)];
    }

    return ['success' => true, 'message' => "Email de test envoyé avec succès à <strong>$to</strong>."];
}
