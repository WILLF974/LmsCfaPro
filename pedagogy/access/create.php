<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo    = getDB();
$meId   = (int)$_SESSION['user_id'];

// Pré-sélection depuis l'URL
$preUserId = (int)($_GET['user_id'] ?? 0);

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $userId     = (int)($_POST['user_id']    ?? 0);
    $scopeType  = trim($_POST['scope_type']  ?? '');
    $scopeId    = (int)($_POST['scope_id']   ?? 0);
    $notes      = mb_substr(trim($_POST['notes'] ?? ''), 0, 255);

    $validTypes = ['rncp_title','activity_type','competency','sequence','module'];
    $errors = [];
    if (!$userId)                               $errors[] = "L'apprenant est obligatoire.";
    if (!in_array($scopeType, $validTypes))     $errors[] = "Le niveau d'accès est obligatoire.";
    if (!$scopeId)                              $errors[] = "Le périmètre est obligatoire.";

    if (!$errors) {
        try {
            $pdo->prepare("
                INSERT INTO access_grants (user_id, scope_type, scope_id, granted_by, notes)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE revoked_at=NULL, revoked_by=NULL, granted_by=?, granted_at=NOW(), notes=?
            ")->execute([$userId, $scopeType, $scopeId, $meId, $notes ?: null, $meId, $notes ?: null]);
            auditLog('access_grant_created', 'access_grants', $userId, [], ['scope_type' => $scopeType, 'scope_id' => $scopeId]);
            setFlash('success', 'Accès accordé avec succès.');
        } catch (\PDOException $e) {
            setFlash('error', 'Erreur base de données : ' . $e->getMessage());
        }
        redirect(url('pedagogy/access/index.php?student_id=' . $userId));
    }
    foreach ($errors as $err) setFlash('error', $err);
}

// ── Données cascade ───────────────────────────────────────────────────────────
$students  = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='student' AND status='active' ORDER BY last_name, first_name")->fetchAll();
$allRncp   = $pdo->query("SELECT id, rncp_code, title FROM rncp_titles WHERE status='active' ORDER BY rncp_code")->fetchAll();
$allAT     = $pdo->query("SELECT id, rncp_title_id, code, title FROM activity_types ORDER BY rncp_title_id, order_num")->fetchAll();
$allComp   = $pdo->query("SELECT id, activity_type_id, code, title FROM competencies ORDER BY activity_type_id, order_num")->fetchAll();
$allSeqs   = $pdo->query("SELECT id, competency_id, title FROM sequences ORDER BY competency_id, order_num")->fetchAll();
$allMods   = $pdo->query("SELECT id, sequence_id, title FROM modules ORDER BY sequence_id, order_num")->fetchAll();

renderHead('Donner un accès');
renderSidebar('pedagogy');
renderTopbar('Donner un accès', [
    ['Pédagogie', url('pedagogy/index.php')],
    ['Accès', url('pedagogy/access/index.php')],
    ['Nouvel accès', ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>
  <div style="max-width:700px;margin:0 auto">

    <div style="background:rgba(99,102,241,.07);border:1px solid rgba(99,102,241,.25);border-radius:var(--radius-lg);padding:14px 18px;margin-bottom:20px;font-size:13px;color:var(--text-secondary)">
      <i class="fas fa-info-circle" style="color:#6366f1;margin-right:6px"></i>
      <strong style="color:#6366f1">Accès par périmètre</strong> — donnez accès à un apprenant sur un titre RNCP entier, une activité type, une compétence, une séquence ou une séance seule, sans l'inscrire à une formation complète.
    </div>

    <div class="card">
      <div class="card-body" style="padding:28px">
        <form method="POST" id="access-form">
          <?= csrfField() ?>

          <!-- Étape 1 : Apprenant -->
          <div style="margin-bottom:24px">
            <div style="font-weight:700;font-size:13px;margin-bottom:12px;display:flex;align-items:center;gap:8px">
              <span style="background:#6366f1;color:white;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800">1</span>
              Sélectionner l'apprenant
            </div>
            <select name="user_id" id="sel-student" class="form-control" required>
              <option value="">— Choisir un apprenant —</option>
              <?php foreach ($students as $s): ?>
              <option value="<?= $s['id'] ?>" <?= $preUserId==$s['id']?'selected':'' ?>><?= e($s['last_name'].' '.$s['first_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <hr style="border:none;border-top:1px solid var(--border-color);margin:22px 0">

          <!-- Étape 2 : Niveau d'accès -->
          <div style="margin-bottom:24px">
            <div style="font-weight:700;font-size:13px;margin-bottom:12px;display:flex;align-items:center;gap:8px">
              <span style="background:#8b5cf6;color:white;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800">2</span>
              Choisir le niveau d'accès
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px" id="level-btns">
              <?php
              $levels = [
                ['rncp_title',    'RNCP entier',   'certificate', '#a78bfa', 'Accès à tout le titre RNCP (toutes activités, compétences, séquences, séances)'],
                ['activity_type', 'Activité type', 'layer-group', '#f59e0b', 'Accès à toutes les compétences et séances d\'une activité type'],
                ['competency',    'Compétence',    'bullseye',    '#ef4444', 'Accès à toutes les séquences et séances d\'une compétence'],
                ['sequence',      'Séquence',      'list-ol',     '#10b981', 'Accès à toutes les séances d\'une séquence'],
                ['module',        'Séance seule',  'play-circle', '#0ea5e9', 'Accès à une seule séance'],
              ];
              foreach ($levels as [$val, $lbl, $ico, $col, $desc]): ?>
              <label style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 8px;background:rgba(255,255,255,.04);border:2px solid transparent;border-radius:var(--radius-lg);cursor:pointer;transition:all .15s;text-align:center" class="level-card" data-value="<?= $val ?>">
                <input type="radio" name="scope_type" value="<?= $val ?>" style="display:none" class="scope-radio">
                <i class="fas fa-<?= $ico ?>" style="font-size:18px;color:<?= $col ?>"></i>
                <span style="font-size:11px;font-weight:700;color:var(--text-primary)"><?= $lbl ?></span>
                <span style="font-size:10px;color:var(--text-muted);line-height:1.3"><?= $desc ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <hr style="border:none;border-top:1px solid var(--border-color);margin:22px 0">

          <!-- Étape 3 : Cascade de sélection -->
          <div style="margin-bottom:24px" id="cascade-zone">
            <div style="font-weight:700;font-size:13px;margin-bottom:12px;display:flex;align-items:center;gap:8px">
              <span style="background:#10b981;color:white;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800">3</span>
              Sélectionner le périmètre
            </div>
            <div style="display:grid;gap:12px">
              <!-- RNCP (toujours affiché) -->
              <div id="row-rncp">
                <label class="form-label"><i class="fas fa-certificate" style="color:#a78bfa;margin-right:5px"></i>Titre RNCP</label>
                <select id="sel-rncp" class="form-control">
                  <option value="">— Sélectionner —</option>
                  <?php foreach ($allRncp as $r): ?>
                  <option value="<?= $r['id'] ?>"><?= e($r['rncp_code']) ?> — <?= e($r['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <!-- AT -->
              <div id="row-at" style="display:none">
                <label class="form-label"><i class="fas fa-layer-group" style="color:#f59e0b;margin-right:5px"></i>Activité type</label>
                <select id="sel-at" class="form-control"><option value="">— Sélectionner —</option></select>
              </div>
              <!-- Compétence -->
              <div id="row-comp" style="display:none">
                <label class="form-label"><i class="fas fa-bullseye" style="color:#ef4444;margin-right:5px"></i>Compétence</label>
                <select id="sel-comp" class="form-control"><option value="">— Sélectionner —</option></select>
              </div>
              <!-- Séquence -->
              <div id="row-seq" style="display:none">
                <label class="form-label"><i class="fas fa-list-ol" style="color:#10b981;margin-right:5px"></i>Séquence</label>
                <select id="sel-seq" class="form-control"><option value="">— Sélectionner —</option></select>
              </div>
              <!-- Module/Séance -->
              <div id="row-mod" style="display:none">
                <label class="form-label"><i class="fas fa-play-circle" style="color:#0ea5e9;margin-right:5px"></i>Séance</label>
                <select id="sel-mod" class="form-control"><option value="">— Sélectionner —</option></select>
              </div>
              <!-- Champ hidden scope_id -->
              <input type="hidden" name="scope_id" id="scope-id-input" value="">
            </div>
          </div>

          <!-- Notes -->
          <div style="margin-bottom:24px">
            <label class="form-label">Note interne <span style="font-weight:400;color:var(--text-muted)">(optionnelle)</span></label>
            <input type="text" name="notes" class="form-control" placeholder="Motif, contexte…" maxlength="255">
          </div>

          <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary" id="submit-btn" disabled>
              <i class="fas fa-key"></i> Accorder l'accès
            </button>
            <a href="<?= url('pedagogy/access/index.php') ?>" class="btn btn-ghost">Annuler</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var ALL_AT   = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'rncp'=>(int)$r['rncp_title_id'],'text'=>$r['code'].' — '.$r['title']], $allAT), JSON_UNESCAPED_UNICODE) ?>;
  var ALL_COMP = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'at'=>(int)$r['activity_type_id'],'text'=>$r['code'].' — '.$r['title']], $allComp), JSON_UNESCAPED_UNICODE) ?>;
  var ALL_SEQS = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'comp'=>(int)$r['competency_id'],'text'=>$r['title']], $allSeqs), JSON_UNESCAPED_UNICODE) ?>;
  var ALL_MODS = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'seq'=>(int)($r['sequence_id']??0),'text'=>$r['title']], $allMods), JSON_UNESCAPED_UNICODE) ?>;

  var currentLevel = '';

  var levelCards  = document.querySelectorAll('.level-card');
  var radios      = document.querySelectorAll('.scope-radio');
  var selRncp     = document.getElementById('sel-rncp');
  var selAt       = document.getElementById('sel-at');
  var selComp     = document.getElementById('sel-comp');
  var selSeq      = document.getElementById('sel-seq');
  var selMod      = document.getElementById('sel-mod');
  var scopeInput  = document.getElementById('scope-id-input');
  var submitBtn   = document.getElementById('submit-btn');

  var rows = {
    rncp: document.getElementById('row-rncp'),
    at:   document.getElementById('row-at'),
    comp: document.getElementById('row-comp'),
    seq:  document.getElementById('row-seq'),
    mod:  document.getElementById('row-mod'),
  };

  function fill(sel, opts) {
    var old = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    opts.forEach(function(o) {
      var opt = document.createElement('option');
      opt.value = o.id; opt.textContent = o.text;
      if (String(o.id) === old) opt.selected = true;
      sel.appendChild(opt);
    });
    if (!sel.value) sel.value = '';
  }

  function showRows() {
    var order = ['rncp','at','comp','seq','mod'];
    var show  = {
      rncp_title:    ['rncp'],
      activity_type: ['rncp','at'],
      competency:    ['rncp','at','comp'],
      sequence:      ['rncp','at','comp','seq'],
      module:        ['rncp','at','comp','seq','mod'],
    }[currentLevel] || [];
    order.forEach(function(k) { rows[k].style.display = show.includes(k) ? '' : 'none'; });
    updateScopeId();
  }

  function updateScopeId() {
    var val = '';
    switch (currentLevel) {
      case 'rncp_title':    val = selRncp.value; break;
      case 'activity_type': val = selAt.value;   break;
      case 'competency':    val = selComp.value; break;
      case 'sequence':      val = selSeq.value;  break;
      case 'module':        val = selMod.value;  break;
    }
    scopeInput.value = val;
    submitBtn.disabled = !(currentLevel && val);
  }

  levelCards.forEach(function(card) {
    card.addEventListener('click', function() {
      currentLevel = card.dataset.value;
      levelCards.forEach(function(c) { c.style.borderColor='transparent'; });
      card.style.borderColor = '#6366f1';
      card.querySelector('.scope-radio').checked = true;
      showRows();
    });
  });

  selRncp.addEventListener('change', function() {
    var rid = selRncp.value;
    fill(selAt,   rid ? ALL_AT.filter(function(a){ return a.rncp==rid; }) : ALL_AT);
    selAt.value = ''; selComp.value = ''; selSeq.value = ''; selMod.value = '';
    updateScopeId();
  });
  selAt.addEventListener('change', function() {
    var aid = selAt.value;
    fill(selComp, aid ? ALL_COMP.filter(function(c){ return c.at==aid; }) : ALL_COMP);
    selComp.value = ''; selSeq.value = ''; selMod.value = '';
    updateScopeId();
  });
  selComp.addEventListener('change', function() {
    var cid = selComp.value;
    fill(selSeq, cid ? ALL_SEQS.filter(function(s){ return s.comp==cid; }) : ALL_SEQS);
    selSeq.value = ''; selMod.value = '';
    updateScopeId();
  });
  selSeq.addEventListener('change', function() {
    var sid = selSeq.value;
    fill(selMod, sid ? ALL_MODS.filter(function(m){ return m.seq==sid; }) : ALL_MODS);
    selMod.value = '';
    updateScopeId();
  });
  selMod.addEventListener('change', updateScopeId);
  selRncp.addEventListener('change', updateScopeId);
})();
</script>
<?php renderFooter(); ?>
