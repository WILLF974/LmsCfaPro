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
</script>
<?php renderFooter(); ?>
