<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();

// ── Claude API ────────────────────────────────────────────────
function callClaudeWithPdf(string $pdfPath, string $apiKey): array {
    $prompt = 'Analyse ce REAC. Retourne UNIQUEMENT ce JSON valide (rien avant ni après). Maximum 8 mots par valeur textuelle :
{"level":5,"objectives":"résumé court","activity_types":[{"code":"AT1","title":"Libellé exact AT","description":"résumé 8 mots max","competencies":[{"code":"C1.1","title":"Libellé exact compétence","description":"résumé 8 mots max","evaluation_criteria":"critère 8 mots max"}]}]}
Règles : level=3-7 · AT1 AT2… · C1.1 C1.2 C2.1… · TOUTES les AT et compétences sans exception · maximum 8 mots par champ description/objectives/evaluation_criteria.';

    $pdfB64  = base64_encode(file_get_contents($pdfPath));
    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 2500,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdfB64]],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 28,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: pdfs-2024-09-25',
        ],
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    $curlNo   = curl_errno($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => ($curlNo === CURLE_OPERATION_TIMEOUTED)
            ? 'Délai API dépassé (25s). Réessayez.'
            : 'Erreur réseau curl : ' . $curlErr];
    }

    $resp = json_decode($raw, true);
    if ($httpCode !== 200 || empty($resp['content'][0]['text'])) {
        return ['success' => false, 'error' => 'Claude API HTTP ' . $httpCode . ' : ' . substr($resp['error']['message'] ?? $raw, 0, 200)];
    }

    $text = $resp['content'][0]['text'];
    if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
        $data = json_decode($m[0], true);
        if ($data) return ['success' => true, 'data' => $data];
        return ['success' => false, 'error' => 'JSON malformé dans la réponse.', 'raw' => substr($text, 0, 300)];
    }
    return ['success' => false, 'error' => 'Aucun JSON trouvé dans la réponse Claude.', 'raw' => substr($text, 0, 300)];
}

// ── AJAX : extract ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'extract') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $apiKey = getSetting('anthropic_api_key', '');
    if (!$apiKey) { echo json_encode(['success' => false, 'error' => 'Clé API Anthropic non configurée.']); exit; }

    if (empty($_FILES['pdf']['tmp_name']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Fichier non reçu (code ' . ($_FILES['pdf']['error'] ?? -1) . ').']); exit;
    }

    $tmpPath = $_FILES['pdf']['tmp_name'];
    $result  = callClaudeWithPdf($tmpPath, $apiKey);
    echo json_encode($result);
    exit;
}

// ── POST : save ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCsrf();
    $rncpId  = (int)($_POST['rncp_id'] ?? 0);
    $payload = json_decode($_POST['extracted_json'] ?? '', true);
    if (!$rncpId || !$payload) { setFlash('error', 'Données invalides.'); redirect(url('admin/rncp/import.php')); }

    $stmt = $pdo->prepare('SELECT id FROM rncp_titles WHERE id = ?');
    $stmt->execute([$rncpId]);
    if (!$stmt->fetch()) { setFlash('error', 'Titre RNCP introuvable.'); redirect(url('admin/rncp/import.php')); }

    $updateParts = []; $updateParams = [];
    if (!empty($payload['objectives'])) { $updateParts[] = 'objectives = ?'; $updateParams[] = $payload['objectives']; }
    if (!empty($payload['description'])) { $updateParts[] = 'description = ?'; $updateParams[] = $payload['description']; }
    if ($updateParts) { $updateParams[] = $rncpId; $pdo->prepare('UPDATE rncp_titles SET ' . implode(', ', $updateParts) . ' WHERE id = ?')->execute($updateParams); }

    $atInsert   = $pdo->prepare('INSERT INTO activity_types (rncp_title_id,code,title,description,order_num) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description)');
    $compInsert = $pdo->prepare('INSERT INTO competencies (activity_type_id,code,title,description,evaluation_criteria,order_num) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),evaluation_criteria=VALUES(evaluation_criteria)');

    $atCount = $compCount = 0;
    foreach (($payload['activity_types'] ?? []) as $i => $at) {
        $code = trim($at['code'] ?? ('AT'.($i+1))); $title = trim($at['title'] ?? '');
        if (!$title) continue;
        $atInsert->execute([$rncpId, $code, $title, trim($at['description'] ?? ''), $i+1]);
        $atId = (int)$pdo->lastInsertId();
        if (!$atId) { $r = $pdo->prepare('SELECT id FROM activity_types WHERE rncp_title_id=? AND code=?'); $r->execute([$rncpId,$code]); $atId=(int)$r->fetchColumn(); }
        $atCount++;
        foreach (($at['competencies'] ?? []) as $j => $c) {
            $cc = trim($c['code'] ?? ('C'.($i+1).'.'.($j+1))); $ct = trim($c['title'] ?? '');
            if (!$ct) continue;
            $compInsert->execute([$atId,$cc,$ct,trim($c['description']??''),trim($c['evaluation_criteria']??''),$j+1]);
            $compCount++;
        }
    }
    auditLog('rncp_reac_imported','rncp_title',$rncpId,[],['at_count'=>$atCount,'comp_count'=>$compCount]);
    setFlash('success', "$atCount activités types et $compCount compétences importées.");
    redirect(url('admin/rncp/view.php?id='.$rncpId));
}

// ── GET : formulaire ──────────────────────────────────────────
$rncpTitles = $pdo->query("SELECT id,rncp_code,title FROM rncp_titles WHERE status='active' ORDER BY title")->fetchAll();
$apiKey     = getSetting('anthropic_api_key','');
$preselect  = (int)($_GET['rncp_id'] ?? 0);

header('Cache-Control: no-store, no-cache');

renderHead('Importer un REAC');
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar('Importer un REAC PDF', [
    ['Admin', url('admin/index.php')],
    ['Titres RNCP', url('admin/rncp/index.php')],
    ['Importer REAC', ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <?php if (!$apiKey): ?>
  <div class="alert alert-warning" style="margin-bottom:24px">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Clé API Anthropic manquante.</strong>
    Configurez-la dans <a href="<?= url('admin/settings/index.php') ?>" style="color:inherit;text-decoration:underline">Paramètres → IA</a>.
  </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:400px 1fr;gap:24px;align-items:start">

    <!-- Panneau gauche -->
    <div>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-file-import" style="color:var(--primary-light);margin-right:8px"></i>Importer le REAC</h3>
        </div>
        <div class="card-body" style="padding:20px">

          <div class="form-group">
            <label class="form-label">Titre RNCP à enrichir <span style="color:var(--danger)">*</span></label>
            <select id="rncp-select" class="form-control">
              <option value="">— Choisir un titre RNCP —</option>
              <?php foreach ($rncpTitles as $t): ?>
              <option value="<?= $t['id'] ?>" <?= $t['id']===$preselect ? 'selected' : '' ?>><?= e($t['rncp_code'].' — '.$t['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Document REAC (PDF) <span style="color:var(--danger)">*</span></label>
            <div id="drop-zone" style="border:2px dashed var(--border);border-radius:var(--radius-lg);padding:28px 20px;text-align:center;cursor:pointer"
                 onclick="document.getElementById('pdf-input').click()">
              <i class="fas fa-cloud-upload-alt" style="font-size:28px;color:var(--text-muted);display:block;margin-bottom:10px"></i>
              <div id="drop-label" style="font-size:14px;font-weight:600;color:var(--text-secondary)">Cliquez pour choisir un PDF</div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:4px">Max 15 Mo</div>
              <input type="file" id="pdf-input" accept=".pdf,application/pdf" style="display:none">
            </div>
          </div>

          <!-- Zone d'état : erreur ou info fichier -->
          <div id="status-box" style="display:none;margin-bottom:14px;padding:12px 14px;border-radius:8px;font-size:13px"></div>

          <button id="btn-analyze" class="btn btn-primary" style="width:100%;padding:12px" onclick="analyzeReac()">
            <i class="fas fa-brain"></i> Analyser avec l'IA
          </button>

          <!-- Zone de progression -->
          <div id="progress-box" style="display:none;margin-top:16px;padding:18px;background:#1e1b4b;border:2px solid #6366f1;border-radius:10px;text-align:center">
            <div style="width:36px;height:36px;border:4px solid #6366f1;border-top-color:transparent;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px"></div>
            <div id="prog-label" style="font-size:14px;font-weight:600;color:#a5b4fc;margin-bottom:10px">Analyse en cours…</div>
            <div style="height:10px;background:#312e81;border-radius:5px;overflow:hidden">
              <div id="prog-bar" style="height:100%;background:#6366f1;border-radius:5px;width:0%"></div>
            </div>
            <div id="prog-pct" style="font-size:11px;color:#818cf8;margin-top:5px">0%</div>
          </div>

        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <div class="card-body" style="padding:16px;font-size:12px;color:var(--text-muted)">
          <div style="font-weight:700;color:var(--text-secondary);margin-bottom:8px">Ce qui sera importé</div>
          <div style="display:flex;flex-direction:column;gap:6px">
            <div><i class="fas fa-layer-group" style="color:var(--primary-light);width:16px"></i> Activités types (AT1, AT2…)</div>
            <div><i class="fas fa-check" style="color:#10b981;width:16px"></i> Compétences (C1.1, C1.2…)</div>
            <div><i class="fas fa-align-left" style="color:#f59e0b;width:16px"></i> Descriptions et critères</div>
            <div><i class="fas fa-bullseye" style="color:#0ea5e9;width:16px"></i> Objectifs pédagogiques</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Panneau droit -->
    <div id="preview-panel" style="display:none">
      <div class="card">
        <div class="card-header" style="justify-content:space-between">
          <h3 class="card-title"><i class="fas fa-eye" style="color:#10b981;margin-right:8px"></i>Données extraites — Vérifiez avant import</h3>
          <div style="display:flex;gap:8px">
            <button class="btn btn-ghost btn-sm" onclick="resetForm()"><i class="fas fa-redo"></i> Réinitialiser</button>
            <button class="btn btn-success" onclick="confirmImport()"><i class="fas fa-database"></i> Confirmer l'import</button>
          </div>
        </div>
        <div class="card-body" style="padding:20px">
          <div style="margin-bottom:20px;padding:16px;background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);border-radius:var(--radius-lg)">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--primary-light);margin-bottom:12px">Informations générales</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div class="form-group" style="margin:0"><label class="form-label" style="font-size:11px">Niveau EQF</label><input type="number" id="prev-level" class="form-control form-control-sm" min="1" max="8"></div>
              <div class="form-group" style="margin:0"><label class="form-label" style="font-size:11px">Secteur</label><input type="text" id="prev-sector" class="form-control form-control-sm"></div>
            </div>
            <div class="form-group" style="margin-top:12px;margin-bottom:0"><label class="form-label" style="font-size:11px">Objectifs pédagogiques</label><textarea id="prev-objectives" class="form-control" rows="3" style="font-size:13px"></textarea></div>
            <div class="form-group" style="margin-top:12px;margin-bottom:0"><label class="form-label" style="font-size:11px">Description</label><textarea id="prev-description" class="form-control" rows="2" style="font-size:13px"></textarea></div>
          </div>
          <div id="activity-types-list"></div>
          <div style="margin-top:16px;text-align:center">
            <button class="btn btn-ghost btn-sm" onclick="addActivityType()"><i class="fas fa-plus"></i> Ajouter une activité type</button>
          </div>
        </div>
      </div>
    </div>

    <div id="empty-panel" style="display:flex;align-items:center;justify-content:center;min-height:400px">
      <div style="text-align:center;color:var(--text-muted)">
        <i class="fas fa-file-pdf" style="font-size:48px;margin-bottom:16px;opacity:.3"></i>
        <div style="font-size:15px;font-weight:600;margin-bottom:8px">Uploadez un REAC pour commencer</div>
        <div style="font-size:13px">L'IA extraira automatiquement les activités types et compétences</div>
      </div>
    </div>
  </div>
</div>

<form id="save-form" method="POST" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="rncp_id" id="save-rncp-id">
  <input type="hidden" name="extracted_json" id="save-json">
</form>

<style>
@keyframes spin    { to { transform:rotate(360deg); } }
@keyframes barfill { from { width:0% } to { width:91% } }
.at-card  { background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-lg);margin-bottom:16px;overflow:hidden }
.at-header{ padding:14px 16px;background:rgba(99,102,241,.08);display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border-light) }
.at-body  { padding:16px }
.comp-row { display:grid;grid-template-columns:80px 1fr 1fr auto;gap:10px;align-items:start;padding:10px 0;border-bottom:1px solid var(--border-light) }
.comp-row:last-child { border-bottom:none;padding-bottom:0 }
</style>

<script>
let selectedFile = null;
let atCounter    = 0;

// ── Sélection fichier ─────────────────────────────────────────
document.getElementById('pdf-input').addEventListener('change', function(e) {
    var f = e.target.files[0];
    if (f) { selectedFile = f; showFileInfo(f); }
});

function showFileInfo(f) {
    document.getElementById('drop-label').textContent = '📄 ' + f.name;
    showStatus('ok', '<i class="fas fa-check-circle"></i> <strong>Fichier sélectionné :</strong> ' + f.name + ' · ' + (f.size/1024/1024).toFixed(2) + ' Mo');
}

function showStatus(type, html) {
    var box = document.getElementById('status-box');
    var styles = {
        ok:    'background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#34d399',
        error: 'background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#f87171',
    };
    box.style.cssText = 'display:block;margin-bottom:14px;padding:12px 14px;border-radius:8px;font-size:13px;' + (styles[type] || styles.error);
    box.innerHTML = html;
}

function hideStatus() { document.getElementById('status-box').style.display = 'none'; }

// ── Analyse ───────────────────────────────────────────────────
async function analyzeReac() {
    var rncpId = document.getElementById('rncp-select').value;
    if (!rncpId) { showStatus('error', '<i class="fas fa-exclamation-circle"></i> Veuillez choisir un titre RNCP.'); return; }

    var file = selectedFile;
    if (!file) { showStatus('error', '<i class="fas fa-exclamation-circle"></i> Veuillez sélectionner un fichier PDF.'); return; }

    // Préparer l'UI
    hideStatus();
    document.getElementById('preview-panel').style.display = 'none';
    document.getElementById('empty-panel').style.display = 'flex';

    var btn = document.getElementById('btn-analyze');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyse en cours…';

    startProgress();

    // Délai pour laisser le navigateur afficher la barre
    await new Promise(function(r) { setTimeout(r, 150); });

    var fd = new FormData();
    fd.append('action', 'extract');
    fd.append('pdf', file);

    var data;
    try {
        var resp = await fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
        var raw  = await resp.text();
        try { data = JSON.parse(raw); }
        catch(e) {
            stopProgress();
            showStatus('error', '<i class="fas fa-times-circle"></i> Réponse serveur invalide (HTTP ' + resp.status + ')<br><small style="font-family:monospace;opacity:.7">' + raw.substring(0,200) + '</small>');
            resetBtn(); return;
        }
    } catch(e) {
        stopProgress();
        showStatus('error', '<i class="fas fa-times-circle"></i> Erreur réseau : ' + e.message);
        resetBtn(); return;
    }

    stopProgress();

    if (data.success && data.data) {
        renderPreview(data.data);
    } else {
        var msg = data.error || 'Extraction échouée.';
        if (data.raw) msg += '<br><small style="font-family:monospace;opacity:.7">' + data.raw + '</small>';
        showStatus('error', '<i class="fas fa-times-circle"></i> ' + msg);
        resetBtn();
    }
}

// ── Barre de progression (CSS animation) ─────────────────────
var progTimer = null;
var progStart = 0;

function startProgress() {
    var box = document.getElementById('progress-box');
    var bar = document.getElementById('prog-bar');
    var lbl = document.getElementById('prog-label');
    var pct = document.getElementById('prog-pct');

    box.style.display = 'block';
    bar.style.animation = 'none';
    bar.style.width = '0%';

    // Force reflow
    bar.getBoundingClientRect();

    bar.style.animation = 'barfill 28s ease-in-out forwards';

    lbl.textContent = 'Claude Haiku analyse le REAC…';
    pct.textContent = '0%';

    progStart = Date.now();
    progTimer = setInterval(function() {
        var s = Math.round((Date.now() - progStart) / 1000);
        pct.textContent = s + 's écoulées';
    }, 1000);
}

function stopProgress() {
    if (progTimer) { clearInterval(progTimer); progTimer = null; }
    document.getElementById('progress-box').style.display = 'none';
    var bar = document.getElementById('prog-bar');
    bar.style.animation = 'none';
    bar.style.width = '0%';
}

function resetBtn() {
    var btn = document.getElementById('btn-analyze');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-brain"></i> Analyser avec l\'IA';
}

// ── Prévisualisation ──────────────────────────────────────────
function renderPreview(data) {
    document.getElementById('prev-level').value       = data.level || '';
    document.getElementById('prev-sector').value      = data.sector || '';
    document.getElementById('prev-objectives').value  = data.objectives || '';
    document.getElementById('prev-description').value = data.description || '';
    var list = document.getElementById('activity-types-list');
    list.innerHTML = '';
    atCounter = 0;
    (data.activity_types || []).forEach(function(at) { renderActivityType(at); });
    document.getElementById('empty-panel').style.display   = 'none';
    document.getElementById('preview-panel').style.display = 'block';
    resetBtn();
}

function renderActivityType(at) {
    var idx = atCounter++;
    var div = document.createElement('div');
    div.className = 'at-card'; div.id = 'at-' + idx;
    div.innerHTML =
        '<div class="at-header">' +
        '<span style="background:rgba(99,102,241,.2);color:var(--primary-light);font-size:11px;font-weight:800;padding:3px 8px;border-radius:4px">' + escHtml(at.code||'AT?') + '</span>' +
        '<input type="text" class="form-control form-control-sm" style="flex:1" value="' + escHtml(at.title||'') + '" placeholder="Libellé" data-at-idx="' + idx + '" data-field="title">' +
        '<button class="btn btn-ghost btn-sm" onclick="removeAt(' + idx + ')"><i class="fas fa-trash" style="color:var(--danger)"></i></button>' +
        '</div>' +
        '<div class="at-body">' +
        '<textarea class="form-control form-control-sm" style="margin-bottom:12px;font-size:12px" rows="2" placeholder="Description" data-at-idx="' + idx + '" data-field="description">' + escHtml(at.description||'') + '</textarea>' +
        '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:8px">Compétences</div>' +
        '<div id="comps-' + idx + '">' + (at.competencies||[]).map(function(c,j){return renderCompRow(idx,j,c);}).join('') + '</div>' +
        '<button class="btn btn-ghost btn-sm" style="margin-top:8px" onclick="addComp(' + idx + ')"><i class="fas fa-plus" style="font-size:10px"></i> Ajouter</button>' +
        '</div>';
    document.getElementById('activity-types-list').appendChild(div);
}

function renderCompRow(atIdx, cIdx, c) {
    return '<div class="comp-row" id="comp-' + atIdx + '-' + cIdx + '">' +
        '<input type="text" class="form-control form-control-sm" value="' + escHtml(c.code||'') + '" placeholder="Code" data-at="' + atIdx + '" data-c="' + cIdx + '" data-field="code" style="font-size:11px;font-family:monospace">' +
        '<div><input type="text" class="form-control form-control-sm" value="' + escHtml(c.title||'') + '" placeholder="Libellé" data-at="' + atIdx + '" data-c="' + cIdx + '" data-field="title" style="margin-bottom:4px">' +
        '<textarea class="form-control form-control-sm" rows="2" placeholder="Description" data-at="' + atIdx + '" data-c="' + cIdx + '" data-field="description" style="font-size:11px">' + escHtml(c.description||'') + '</textarea></div>' +
        '<textarea class="form-control form-control-sm" rows="3" placeholder="Critères d\'évaluation" data-at="' + atIdx + '" data-c="' + cIdx + '" data-field="evaluation_criteria" style="font-size:11px">' + escHtml(c.evaluation_criteria||'') + '</textarea>' +
        '<button class="btn btn-ghost btn-sm" onclick="removeComp(' + atIdx + ',' + cIdx + ')"><i class="fas fa-times" style="color:var(--danger)"></i></button>' +
        '</div>';
}

function addActivityType() { renderActivityType({code:'AT'+(atCounter+1),title:'',description:'',competencies:[]}); }
function removeAt(i) { var el=document.getElementById('at-'+i); if(el) el.remove(); }
function addComp(atIdx) {
    var c = document.getElementById('comps-'+atIdx);
    var t = document.createElement('div');
    t.innerHTML = renderCompRow(atIdx, c.querySelectorAll('.comp-row').length, {code:'',title:'',description:'',evaluation_criteria:''});
    c.appendChild(t.firstElementChild);
}
function removeComp(ai,ci) { var el=document.getElementById('comp-'+ai+'-'+ci); if(el) el.remove(); }

function collectData() {
    var data = {
        level:   parseInt(document.getElementById('prev-level').value)||null,
        sector:  document.getElementById('prev-sector').value,
        objectives:  document.getElementById('prev-objectives').value,
        description: document.getElementById('prev-description').value,
        activity_types: []
    };
    document.querySelectorAll('.at-card').forEach(function(atEl) {
        var idx = atEl.id.replace('at-','');
        var at = {
            code:  atEl.querySelector('.at-header span').textContent.trim(),
            title: atEl.querySelector('[data-at-idx="'+idx+'"][data-field="title"]').value,
            description: atEl.querySelector('[data-at-idx="'+idx+'"][data-field="description"]').value,
            competencies: []
        };
        atEl.querySelectorAll('.comp-row').forEach(function(r) {
            at.competencies.push({
                code:  r.querySelector('[data-field="code"]').value,
                title: r.querySelector('[data-field="title"]').value,
                description: r.querySelector('[data-field="description"]').value,
                evaluation_criteria: r.querySelector('[data-field="evaluation_criteria"]').value
            });
        });
        if (at.title) data.activity_types.push(at);
    });
    return data;
}

function confirmImport() {
    var rncpId = document.getElementById('rncp-select').value;
    if (!rncpId) { alert('Veuillez sélectionner un titre RNCP.'); return; }
    var data = collectData();
    if (!confirm('Confirmer l\'import ?\n\n' + data.activity_types.length + ' activité(s) type(s)\n' + data.activity_types.reduce(function(s,at){return s+at.competencies.length;},0) + ' compétence(s)')) return;
    document.getElementById('save-rncp-id').value = rncpId;
    document.getElementById('save-json').value = JSON.stringify(data);
    document.getElementById('save-form').submit();
}

function resetForm() {
    selectedFile = null;
    document.getElementById('preview-panel').style.display = 'none';
    document.getElementById('empty-panel').style.display = 'flex';
    document.getElementById('pdf-input').value = '';
    document.getElementById('drop-label').textContent = 'Cliquez pour choisir un PDF';
    hideStatus();
    resetBtn();
}

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>
<?php renderFooter(); ?>
