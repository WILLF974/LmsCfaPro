<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$editId = (int)($_GET['id'] ?? 0);

$seq = null;
if ($editId) {
    $s = $pdo->prepare("
        SELECT seq.*, c.activity_type_id AS at_id, at.rncp_title_id
        FROM sequences seq
        LEFT JOIN competencies c  ON seq.competency_id = c.id
        LEFT JOIN activity_types at ON c.activity_type_id = at.id
        WHERE seq.id = ?
    ");
    $s->execute([$editId]);
    $seq = $s->fetch();
    if (!$seq) { setFlash('error', 'Séquence introuvable.'); redirect(url('teacher/courses/sequences.php')); }
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $competencyId = (int)($_POST['competency_id'] ?? 0);
    $title        = trim($_POST['title']          ?? '');
    $description  = trim($_POST['description']    ?? '');
    $orderNum     = (int)($_POST['order_num']     ?? 0);
    $formationId  = (int)($_POST['formation_id']  ?? 0) ?: null;

    $errors = [];
    if (!$competencyId) $errors[] = 'La compétence est obligatoire.';
    if (!$title)        $errors[] = 'Le titre est obligatoire.';

    if (!$errors) {
        if (!$orderNum) {
            $maxOrd = $pdo->prepare("SELECT COALESCE(MAX(order_num),0)+1 FROM sequences WHERE competency_id = ?");
            $maxOrd->execute([$competencyId]);
            $orderNum = (int)$maxOrd->fetchColumn();
        }
        if ($editId) {
            $pdo->prepare("
                UPDATE sequences SET competency_id=?, formation_id=?, title=?, description=?, order_num=?
                WHERE id=?
            ")->execute([$competencyId, $formationId, $title, $description ?: null, $orderNum, $editId]);
            setFlash('success', 'Séquence mise à jour.');
        } else {
            $pdo->prepare("
                INSERT INTO sequences (competency_id, formation_id, title, description, order_num, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$competencyId, $formationId, $title, $description ?: null, $orderNum, $userId]);
            $newId = (int)$pdo->lastInsertId();
            setFlash('success', 'Séquence créée avec succès.');
            redirect(url('teacher/courses/sequences.php?highlight=' . $newId));
        }
        redirect(url('teacher/courses/sequences.php'));
    }
    foreach ($errors as $e) setFlash('error', $e);
}

// ── Données cascade ───────────────────────────────────────────────────────────
$allRncp       = $pdo->query("SELECT id, rncp_code, title FROM rncp_titles WHERE status='active' ORDER BY rncp_code")->fetchAll();
$allAT         = $pdo->query("SELECT id, rncp_title_id, code, title FROM activity_types ORDER BY rncp_title_id, order_num")->fetchAll();
$allComp       = $pdo->query("SELECT id, activity_type_id, code, title FROM competencies ORDER BY activity_type_id, order_num")->fetchAll();
$allFormations = $pdo->query("SELECT id, title FROM formations WHERE status='active' ORDER BY title")->fetchAll();

$seqCountPerComp = [];
foreach ($pdo->query("SELECT id, competency_id, title FROM sequences ORDER BY competency_id, order_num")->fetchAll() as $s) {
    if ($s['competency_id']) {
        $seqCountPerComp[(int)$s['competency_id']][] = ['id' => (int)$s['id'], 'title' => $s['title']];
    }
}

$preCompId = $seq ? (int)$seq['competency_id'] : (int)($_GET['competency_id'] ?? 0);
$preAtId   = $seq ? (int)$seq['at_id'] : 0;
$preRncpId = $seq ? (int)$seq['rncp_title_id'] : 0;
$preFormId = $seq ? (int)($seq['formation_id'] ?? 0) : 0;

if ($preCompId && !$preAtId) {
    foreach ($allComp as $c) { if ($c['id'] == $preCompId) { $preAtId = (int)$c['activity_type_id']; break; } }
}
if ($preAtId && !$preRncpId) {
    foreach ($allAT as $a) { if ($a['id'] == $preAtId) { $preRncpId = (int)$a['rncp_title_id']; break; } }
}

$pageTitle = $editId ? 'Modifier la séquence' : 'Nouvelle séquence';
renderHead($pageTitle);
renderSidebar('teacher');
renderTopbar($pageTitle, [
    ['Enseignant', url('teacher/index.php')],
    ['Séquences', url('teacher/courses/sequences.php')],
    [$pageTitle, ''],
]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>
  <div style="max-width:680px;margin:0 auto">

    <div style="background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.25);border-radius:var(--radius-lg);padding:14px 18px;margin-bottom:20px;font-size:13px;color:var(--text-secondary)">
      <i class="fas fa-info-circle" style="color:#10b981;margin-right:6px"></i>
      <strong style="color:#10b981">Hiérarchie :</strong>
      RNCP → Activité type → <strong>Compétence</strong> → <strong style="color:#10b981">Séquence</strong> → Séance.
      Une séquence regroupe plusieurs séances liées à une même compétence.
    </div>

    <div class="card">
      <div class="card-body" style="padding:28px">
        <form method="POST" id="form-seq">
          <?= csrfField() ?>

          <!-- Étape 1 : Compétence -->
          <div style="margin-bottom:24px">
            <div style="font-weight:700;font-size:13px;margin-bottom:14px;display:flex;align-items:center;gap:8px">
              <span style="background:#8b5cf6;color:white;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800">1</span>
              Sélectionner la compétence visée
            </div>
            <div style="display:grid;gap:14px">
              <div>
                <label class="form-label"><i class="fas fa-certificate" style="color:#8b5cf6;margin-right:5px"></i>Titre RNCP <span style="font-weight:400;color:var(--text-muted)">(filtre)</span></label>
                <select id="sel-rncp" class="form-control">
                  <option value="">— Tous les RNCP —</option>
                  <?php foreach ($allRncp as $r): ?>
                  <option value="<?= $r['id'] ?>" <?= $preRncpId==$r['id']?'selected':'' ?>><?= e($r['rncp_code']) ?> — <?= e($r['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="form-label"><i class="fas fa-layer-group" style="color:#f59e0b;margin-right:5px"></i>Activité type <span style="font-weight:400;color:var(--text-muted)">(filtre)</span></label>
                <select id="sel-at" class="form-control">
                  <option value="">— Toutes —</option>
                  <?php foreach ($allAT as $a): ?>
                  <option value="<?= $a['id'] ?>" data-rncp="<?= $a['rncp_title_id'] ?>" <?= $preAtId==$a['id']?'selected':'' ?>><?= e($a['code']) ?> — <?= e($a['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="form-label"><i class="fas fa-bullseye" style="color:#ef4444;margin-right:5px"></i>Compétence <span style="color:#ef4444">*</span></label>
                <select id="sel-comp" name="competency_id" class="form-control" required>
                  <option value="">— Sélectionner —</option>
                  <?php foreach ($allComp as $c):
                    $nbSeq = count($seqCountPerComp[(int)$c['id']] ?? []);
                  ?>
                  <option value="<?= $c['id'] ?>" data-at="<?= $c['activity_type_id'] ?>" data-seq-count="<?= $nbSeq ?>" <?= $preCompId==$c['id']?'selected':'' ?>>
                    <?= e($c['code']) ?> — <?= e($c['title']) ?><?= $nbSeq > 0 ? ' ⚠ '.$nbSeq.' séquence'.($nbSeq>1?'s':'').' existante'.($nbSeq>1?'s':'') : '' ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div id="dupli-warning" style="display:none;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.35);border-radius:var(--radius-lg);padding:14px 16px">
                <div style="font-weight:700;font-size:13px;color:#f59e0b;margin-bottom:8px"><i class="fas fa-exclamation-triangle" style="margin-right:6px"></i>Des séquences existent déjà pour cette compétence</div>
                <div style="font-size:12px;color:var(--text-secondary);margin-bottom:10px">Préférez utiliser ou fusionner les séquences existantes plutôt qu'en créer une nouvelle.</div>
                <div id="dupli-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px"></div>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;color:var(--text-muted)">
                  <input type="checkbox" id="dupli-confirm" style="width:14px;height:14px;accent-color:#f59e0b">
                  Je veux quand même créer une nouvelle séquence
                </label>
              </div>
            </div>
          </div>

          <hr style="border:none;border-top:1px solid var(--border-color);margin:22px 0">

          <!-- Étape 2 : Détails -->
          <div style="margin-bottom:24px">
            <div style="font-weight:700;font-size:13px;margin-bottom:14px;display:flex;align-items:center;gap:8px">
              <span style="background:#10b981;color:white;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800">2</span>
              Détails de la séquence
            </div>
            <div style="display:grid;gap:14px">
              <div>
                <label class="form-label">Titre <span style="color:#ef4444">*</span></label>
                <input type="text" name="title" class="form-control" placeholder="Ex : Analyse des états financiers" maxlength="500" required value="<?= e($seq['title'] ?? '') ?>">
              </div>
              <div>
                <label class="form-label">Description <span style="font-weight:400;color:var(--text-muted)">(optionnelle)</span></label>
                <textarea name="description" class="form-control" rows="3" placeholder="Objectifs pédagogiques, contenu prévu…" style="resize:vertical"><?= e($seq['description'] ?? '') ?></textarea>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                <div>
                  <label class="form-label">Ordre <span style="font-weight:400;color:var(--text-muted)">(auto si vide)</span></label>
                  <input type="number" name="order_num" class="form-control" min="1" max="999" value="<?= e($seq['order_num'] ?? '') ?>" placeholder="1">
                </div>
                <div>
                  <label class="form-label"><i class="fas fa-graduation-cap" style="color:#0ea5e9;margin-right:4px"></i>Formation <span style="font-weight:400;color:var(--text-muted)">(optionnelle)</span></label>
                  <select name="formation_id" class="form-control">
                    <option value="">— Aucune —</option>
                    <?php foreach ($allFormations as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $preFormId==$f['id']?'selected':'' ?>><?= e($f['title']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <div style="display:flex;gap:10px;margin-top:8px">
            <button type="submit" class="btn btn-primary" id="submit-btn">
              <i class="fas fa-save"></i> <?= $editId ? 'Enregistrer' : 'Créer la séquence' ?>
            </button>
            <a href="<?= url('teacher/courses/sequences.php') ?>" class="btn btn-ghost">Annuler</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var EDIT_ID       = <?= $editId ?: 0 ?>;
  var SEQS_PER_COMP = <?= json_encode($seqCountPerComp, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' ?>;
  var SEQ_URL       = '<?= url('teacher/courses/sequences.php') ?>';

  var selRncp    = document.getElementById('sel-rncp');
  var selAt      = document.getElementById('sel-at');
  var selComp    = document.getElementById('sel-comp');
  var warning    = document.getElementById('dupli-warning');
  var dupliList  = document.getElementById('dupli-list');
  var dupliCheck = document.getElementById('dupli-confirm');
  var submitBtn  = document.getElementById('submit-btn');

  var allAts   = Array.from(selAt.options).slice(1).map(function(o){ return {val:o.value,text:o.textContent,rncp:o.dataset.rncp||''}; });
  var allComps = Array.from(selComp.options).slice(1).map(function(o){ return {val:o.value,text:o.textContent,at:o.dataset.at||'',seqCount:o.dataset.seqCount||'0'}; });

  function rebuild(sel, opts) {
    var cur = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    opts.forEach(function(o) {
      var opt = document.createElement('option');
      opt.value = o.val; opt.textContent = o.text;
      if (o.rncp)     opt.dataset.rncp     = o.rncp;
      if (o.at)       opt.dataset.at       = o.at;
      if (o.seqCount) opt.dataset.seqCount = o.seqCount;
      if (o.val === cur) opt.selected = true;
      sel.appendChild(opt);
    });
  }

  function getExisting() {
    var cid = selComp.value; if (!cid) return [];
    var seqs = (SEQS_PER_COMP[cid] || []).filter(function(s){ return s.id !== EDIT_ID; });
    return seqs;
  }

  function escHtml(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function checkDuplicates() {
    var existing = getExisting();
    if (!selComp.value || existing.length === 0) { warning.style.display='none'; updateSubmit(); return; }
    dupliList.innerHTML = existing.map(function(s){
      return '<div style="display:flex;align-items:center;justify-content:space-between;background:rgba(0,0,0,.15);border-radius:6px;padding:8px 12px;gap:8px">'
        + '<span style="font-size:12px;font-weight:600"><i class="fas fa-list-ol" style="color:#10b981;margin-right:6px;font-size:11px"></i>' + escHtml(s.title) + '</span>'
        + '<a href="' + SEQ_URL + '" class="btn btn-secondary btn-sm" style="font-size:11px">Voir →</a></div>';
    }).join('');
    warning.style.display=''; dupliCheck.checked=false; updateSubmit();
  }

  function updateSubmit() {
    var blocked = getExisting().length > 0 && !dupliCheck.checked;
    submitBtn.disabled = blocked;
    submitBtn.style.opacity = blocked ? '.45' : '';
  }

  function onRncp() {
    var r = selRncp.value;
    rebuild(selAt, r ? allAts.filter(function(a){ return a.rncp===r; }) : allAts);
    onAt();
  }
  function onAt() {
    var a = selAt.value;
    rebuild(selComp, a ? allComps.filter(function(c){ return c.at===a; }) : allComps);
    checkDuplicates();
  }

  selRncp.addEventListener('change', onRncp);
  selAt.addEventListener('change', onAt);
  selComp.addEventListener('change', checkDuplicates);
  dupliCheck.addEventListener('change', updateSubmit);

  if (selRncp.value) onRncp();
  else if (selAt.value) onAt();
  else checkDuplicates();
})();
</script>
<?php renderFooter(); ?>
