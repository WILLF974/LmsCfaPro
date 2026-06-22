<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireAdmin();

$pdo = getDB();
$errors = [];

// Charger les paramètres existants
$settingsStmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
$settings = array_column($settingsStmt->fetchAll(), 'setting_value', 'setting_key');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $toSave = [
        'site_name'       => trim($_POST['site_name'] ?? 'LMS CFA Pro'),
        'site_tagline'    => trim($_POST['site_tagline'] ?? ''),
        'contact_email'   => trim($_POST['contact_email'] ?? ''),
        'contact_phone'   => trim($_POST['contact_phone'] ?? ''),
        'address'         => trim($_POST['address'] ?? ''),
        'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
        'smtp_port'       => trim($_POST['smtp_port'] ?? '587'),
        'smtp_user'       => trim($_POST['smtp_user'] ?? ''),
        'smtp_pass'       => trim($_POST['smtp_pass'] ?? ''),
        'smtp_from'       => trim($_POST['smtp_from'] ?? ''),
        'homepage_mode'          => in_array($_POST['homepage_mode'] ?? '', ['default','marketing']) ? $_POST['homepage_mode'] : 'default',
        'marketing_headline'     => trim($_POST['marketing_headline'] ?? ''),
        'marketing_description'  => trim($_POST['marketing_description'] ?? ''),
        'marketing_badge_text'   => trim($_POST['marketing_badge_text'] ?? ''),
        'marketing_cta_label'    => trim($_POST['marketing_cta_label'] ?? ''),
        'registration_open' => isset($_POST['registration_open']) ? '1' : '0',
        'maintenance_mode'  => isset($_POST['maintenance_mode']) ? '1' : '0',
        'default_language'    => $_POST['default_language'] ?? 'fr',
        'timezone'            => $_POST['timezone'] ?? 'Europe/Paris',
        'anthropic_api_key'   => trim($_POST['anthropic_api_key'] ?? ''),
    ];

    $upsert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($toSave as $key => $value) {
        $upsert->execute([$key, $value]);
    }

    auditLog('settings_updated');
    setFlash('success', 'Paramètres enregistrés.');
    redirect(url('admin/settings/index.php'));
}

renderHead('Paramètres');
renderSidebar('admin');
renderTopbar('Paramètres', [['Admin', url('admin/index.php')], ['Paramètres', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <form method="POST" style="max-width:900px">
    <?= csrfField() ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

      <!-- Identité de la plateforme -->
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-building"></i> Identité de la plateforme</h3></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label">Nom de la plateforme</label>
            <input type="text" name="site_name" class="form-control" value="<?= e($settings['site_name'] ?? 'LMS CFA Pro') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Slogan / Tagline</label>
            <input type="text" name="site_tagline" class="form-control" placeholder="La plateforme de formation professionnelle" value="<?= e($settings['site_tagline'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Email de contact</label>
            <input type="email" name="contact_email" class="form-control" placeholder="contact@votrecfa.fr" value="<?= e($settings['contact_email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Téléphone</label>
            <input type="text" name="contact_phone" class="form-control" placeholder="01 23 45 67 89" value="<?= e($settings['contact_phone'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Adresse</label>
            <textarea name="address" class="form-control" rows="3" placeholder="1 rue de la Formation, 75000 Paris"><?= e($settings['address'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

      <!-- Page d'accueil -->
      <div class="card" style="grid-column:1/-1">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-home" style="color:var(--primary-light)"></i> Page d'accueil publique</h3></div>
        <div class="card-body">
          <?php $hMode = $settings['homepage_mode'] ?? 'default'; ?>
          <!-- Choix du mode -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
            <label style="cursor:pointer;border:2px solid <?= $hMode==='default'?'var(--primary)':'var(--border)' ?>;border-radius:var(--radius-lg);padding:16px;display:flex;gap:12px;align-items:flex-start;transition:border-color .2s" id="lbl-default">
              <input type="radio" name="homepage_mode" value="default" <?= $hMode==='default'?'checked':'' ?> onchange="switchHomeMode(this.value)" style="margin-top:2px;accent-color:var(--primary)">
              <div>
                <div style="font-weight:700;color:white;margin-bottom:4px"><i class="fas fa-layer-group" style="margin-right:6px;color:var(--primary-light)"></i>Présentation LMS (défaut)</div>
                <div style="font-size:12px;color:var(--text-muted)">Affiche les fonctionnalités de la plateforme : Qualiopi, RNCP, gamification, multi-formats.</div>
              </div>
            </label>
            <label style="cursor:pointer;border:2px solid <?= $hMode==='marketing'?'var(--primary)':'var(--border)' ?>;border-radius:var(--radius-lg);padding:16px;display:flex;gap:12px;align-items:flex-start;transition:border-color .2s" id="lbl-marketing">
              <input type="radio" name="homepage_mode" value="marketing" <?= $hMode==='marketing'?'checked':'' ?> onchange="switchHomeMode(this.value)" style="margin-top:2px;accent-color:var(--primary)">
              <div>
                <div style="font-weight:700;color:white;margin-bottom:4px"><i class="fas fa-bullhorn" style="margin-right:6px;color:#f59e0b"></i>Vitrine du centre de formation</div>
                <div style="font-size:12px;color:var(--text-muted)">Affiche le nombre d'apprenants, les titres RNCP, un message d'accroche et les contacts. Idéal pour attirer de nouveaux candidats.</div>
              </div>
            </label>
          </div>

          <!-- Champs visibles uniquement en mode marketing -->
          <div id="marketing-fields" style="display:<?= $hMode==='marketing'?'block':'none' ?>">
            <div style="background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.2);border-radius:var(--radius-lg);padding:20px">
              <div style="font-size:12px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.07em;margin-bottom:16px"><i class="fas fa-pencil-alt" style="margin-right:5px"></i>Contenu de la vitrine</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                <div class="form-group" style="margin:0">
                  <label class="form-label">Badge / certification (ligne courte)</label>
                  <input type="text" name="marketing_badge_text" class="form-control" placeholder="Centre de Formation Agréé · Certification Qualiopi" value="<?= e($settings['marketing_badge_text'] ?? '') ?>">
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Libellé bouton CTA</label>
                  <input type="text" name="marketing_cta_label" class="form-control" placeholder="Découvrir nos formations" value="<?= e($settings['marketing_cta_label'] ?? '') ?>">
                </div>
                <div class="form-group" style="margin:0;grid-column:1/-1">
                  <label class="form-label">Accroche principale (titre H1)</label>
                  <input type="text" name="marketing_headline" class="form-control" placeholder="Formez-vous aux métiers de demain" value="<?= e($settings['marketing_headline'] ?? '') ?>">
                  <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Affiché en grand sur la page d'accueil</div>
                </div>
                <div class="form-group" style="margin:0;grid-column:1/-1">
                  <label class="form-label">Description / argument principal</label>
                  <textarea name="marketing_description" class="form-control" rows="3" placeholder="Rejoignez notre centre de formation et obtenez un titre professionnel reconnu par l'État."><?= e($settings['marketing_description'] ?? '') ?></textarea>
                </div>
              </div>
              <div style="margin-top:14px;padding:10px 14px;background:rgba(99,102,241,.08);border-radius:var(--radius);font-size:12px;color:var(--text-muted)">
                <i class="fas fa-info-circle" style="color:var(--primary-light);margin-right:5px"></i>
                Les statistiques (apprenants, formations, titres RNCP) sont affichées <strong style="color:white">automatiquement</strong> depuis la base de données.
                Les coordonnées sont reprises depuis les champs <em>Email de contact</em>, <em>Téléphone</em> et <em>Adresse</em> ci-dessous.
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Options système -->
      <div style="display:flex;flex-direction:column;gap:16px">
        <div class="card">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-toggle-on"></i> Options</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                <input type="checkbox" name="registration_open" value="1" <?= ($settings['registration_open'] ?? '1') === '1' ? 'checked' : '' ?>>
                <div>
                  <div class="form-label" style="margin:0">Inscriptions ouvertes</div>
                  <div style="font-size:12px;color:var(--text-muted)">Autoriser les nouvelles inscriptions</div>
                </div>
              </label>
            </div>
            <div class="form-group">
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                <input type="checkbox" name="maintenance_mode" value="1" <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                <div>
                  <div class="form-label" style="margin:0;color:var(--warning)">Mode maintenance</div>
                  <div style="font-size:12px;color:var(--text-muted)">Bloquer l'accès aux non-admins</div>
                </div>
              </label>
            </div>
            <div class="form-group">
              <label class="form-label">Langue par défaut</label>
              <select name="default_language" class="form-control">
                <option value="fr" <?= ($settings['default_language'] ?? 'fr') === 'fr' ? 'selected' : '' ?>>Français</option>
                <option value="en" <?= ($settings['default_language'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Fuseau horaire</label>
              <select name="timezone" class="form-control">
                <?php foreach (['Europe/Paris'=>'Europe/Paris','UTC'=>'UTC','Europe/London'=>'Europe/London','America/New_York'=>'America/New_York'] as $tz => $label): ?>
                <option value="<?= $tz ?>" <?= ($settings['timezone'] ?? 'Europe/Paris') === $tz ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <!-- Stats système -->
        <div class="card">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-database"></i> Statistiques système</h3></div>
          <div class="card-body">
            <?php
            $stats = [
              'Utilisateurs' => $pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn(),
              'Formations'   => $pdo->query("SELECT COUNT(*) FROM formations WHERE status='active'")->fetchColumn(),
              'Capsules'     => $pdo->query("SELECT COUNT(*) FROM lessons")->fetchColumn(),
              'Quiz'         => $pdo->query("SELECT COUNT(*) FROM quizzes")->fetchColumn(),
            ];
            foreach ($stats as $label => $count): ?>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px">
              <span style="color:var(--text-muted)"><?= $label ?></span>
              <span style="font-weight:700;color:white"><?= number_format($count) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- IA / Claude API -->
      <div class="card" style="grid-column:1/-1">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-brain" style="color:var(--primary-light)"></i> Intelligence Artificielle — Claude API</h3></div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:end">
            <div class="form-group" style="margin:0">
              <label class="form-label">Clé API Anthropic</label>
              <div class="input-group">
                <i class="fas fa-key input-icon"></i>
                <input type="password" name="anthropic_api_key" id="anthropic-key-input" class="form-control"
                       placeholder="sk-ant-api03-..."
                       value="<?= e($settings['anthropic_api_key'] ?? '') ?>"
                       autocomplete="off">
                <button type="button" onclick="toggleApiKey()" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer">
                  <i class="fas fa-eye" id="api-key-eye"></i>
                </button>
              </div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:5px">
                Utilisée pour l'extraction automatique des REAC PDF. Obtenez votre clé sur <strong>console.anthropic.com</strong>
              </div>
            </div>
            <div>
              <?php if (!empty($settings['anthropic_api_key'])): ?>
              <div style="padding:12px 14px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:var(--radius);font-size:13px">
                <i class="fas fa-check-circle" style="color:#34d399"></i> <strong style="color:#34d399">Clé configurée</strong>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                  <a href="<?= url('admin/rncp/import.php') ?>" style="color:var(--primary-light)"><i class="fas fa-file-import"></i> Accéder à l'import REAC</a>
                </div>
              </div>
              <?php else: ?>
              <div style="padding:12px 14px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:var(--radius);font-size:13px">
                <i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i> <strong style="color:#f59e0b">Clé manquante</strong>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Sans cette clé, l'import automatique des REAC est désactivé.</div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Email SMTP -->
      <div class="card" style="grid-column:1/-1">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-envelope"></i> Configuration email SMTP</h3></div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">
            <div class="form-group">
              <label class="form-label">Serveur SMTP</label>
              <input type="text" name="smtp_host" class="form-control" placeholder="smtp.hostinger.com" value="<?= e($settings['smtp_host'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Port</label>
              <input type="number" name="smtp_port" class="form-control" placeholder="587" value="<?= e($settings['smtp_port'] ?? '587') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Email expéditeur</label>
              <input type="email" name="smtp_from" class="form-control" placeholder="noreply@votrecfa.fr" value="<?= e($settings['smtp_from'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Utilisateur SMTP</label>
              <input type="text" name="smtp_user" class="form-control" value="<?= e($settings['smtp_user'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Mot de passe SMTP</label>
              <input type="password" name="smtp_pass" class="form-control" placeholder="••••••••" value="<?= e($settings['smtp_pass'] ?? '') ?>">
            </div>
          </div>

          <!-- ── Test d'envoi ── -->
          <div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--border)">
            <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">
              <i class="fas fa-paper-plane" style="color:var(--primary-light);margin-right:5px"></i>Test d'envoi
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <div class="input-group" style="flex:1;min-width:220px;position:relative">
                <i class="fas fa-at input-icon"></i>
                <input type="email" id="test-email-addr" class="form-control" placeholder="destinataire@exemple.fr" style="padding-left:36px">
              </div>
              <button type="button" onclick="sendTestEmail()" id="test-email-btn" class="btn btn-secondary" style="white-space:nowrap">
                <i class="fas fa-paper-plane"></i> Envoyer un email de test
              </button>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
              Utilise les valeurs <strong>actuellement saisies dans le formulaire</strong> (sauvegardées ou non) pour tester la connexion SMTP.
            </div>
            <div id="test-email-result" style="display:none;margin-top:12px"></div>
          </div>

        </div>
      </div>
    </div>

    <!-- Bouton save -->
    <div style="margin-top:20px;display:flex;justify-content:flex-end;gap:8px">
      <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Enregistrer les paramètres</button>
    </div>
  </form>
</div>
<script>
function toggleApiKey() {
    const f = document.getElementById('anthropic-key-input');
    const e = document.getElementById('api-key-eye');
    if (f.type === 'password') { f.type = 'text'; e.className = 'fas fa-eye-slash'; }
    else { f.type = 'password'; e.className = 'fas fa-eye'; }
}
function switchHomeMode(val) {
    document.getElementById('marketing-fields').style.display = val === 'marketing' ? 'block' : 'none';
    document.getElementById('lbl-default').style.borderColor  = val === 'default'   ? 'var(--primary)' : 'var(--border)';
    document.getElementById('lbl-marketing').style.borderColor = val === 'marketing' ? 'var(--primary)' : 'var(--border)';
}

async function sendTestEmail() {
    const addr = document.getElementById('test-email-addr').value.trim();
    if (!addr) {
        document.getElementById('test-email-addr').focus();
        return;
    }
    const btn    = document.getElementById('test-email-btn');
    const result = document.getElementById('test-email-result');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours…';
    result.style.display = 'none';

    // Lire les valeurs courantes du formulaire (avant sauvegarde)
    const get = id => (document.querySelector('[name="' + id + '"]')?.value || '');
    const csrf = document.querySelector('[name="<?= CSRF_TOKEN_NAME ?>"]').value;

    const body = new URLSearchParams({
        '<?= CSRF_TOKEN_NAME ?>': csrf,
        test_to:   addr,
        smtp_host: get('smtp_host'),
        smtp_port: get('smtp_port'),
        smtp_user: get('smtp_user'),
        smtp_pass: get('smtp_pass'),
        smtp_from: get('smtp_from'),
    });

    try {
        const resp = await fetch('<?= url('admin/settings/test_email.php') ?>', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString(),
        });
        const data = await resp.json();
        result.style.display = 'block';
        if (data.success) {
            result.innerHTML = `<div class="alert alert-success" style="margin:0"><i class="fas fa-check-circle"></i> ${data.message}</div>`;
        } else {
            result.innerHTML = `<div class="alert alert-error" style="margin:0"><i class="fas fa-times-circle"></i> <strong>Échec :</strong> ${data.message}</div>`;
        }
    } catch (err) {
        result.style.display = 'block';
        result.innerHTML = '<div class="alert alert-error" style="margin:0"><i class="fas fa-times-circle"></i> Erreur réseau — vérifiez la console.</div>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Envoyer un email de test';
    }
}
</script>
<?php renderFooter(); ?>
