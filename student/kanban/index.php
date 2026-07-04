<?php
require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/layout.php';

$viewer     = currentUser();
if (!$viewer) { redirect(url('index.php')); }
$viewerRole = $viewer['role'];
$viewerId   = (int)$viewer['id'];

$pdo = getDB();

// ── Résolution de l'apprenant cible ─────────────────────────
if ($viewerRole === 'student') {
    $userId    = $viewerId;
    $isOwnView = true;
    $canManage = true;
    $canDelete = false;
} elseif (in_array($viewerRole, ['admin','pedagogy'])) {
    $userId = (int)($_GET['id'] ?? 0);
    if (!$userId) { redirect(url('admin/users/index.php?role=student')); }
    $isOwnView = false;
    $canManage = true;
    $canDelete = true;
} elseif ($viewerRole === 'teacher') {
    $userId = (int)($_GET['id'] ?? 0);
    if (!$userId) { redirect(url('teacher/cahier/index.php')); }
    $isOwnView = false;
    $canManage = true;
    $canDelete = false;
    try {
        $tc = $pdo->prepare("SELECT id FROM tutor_assignments WHERE teacher_id=? AND student_id=? AND revoked_at IS NULL LIMIT 1");
        $tc->execute([$viewerId, $userId]);
        if (!$tc->fetch()) {
            setFlash('error', "Vous n'êtes pas le tuteur de cet apprenant.");
            redirect(url('teacher/cahier/index.php'));
        }
    } catch (Exception $e) { redirect(url('teacher/cahier/index.php')); }
} else {
    redirect(url('index.php'));
}

// Charger l'apprenant
$stuStmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE id=? AND role='student'");
$stuStmt->execute([$userId]);
$student = $stuStmt->fetch();
if (!$student) { setFlash('error', 'Apprenant introuvable.'); redirect(url('index.php')); }

// Cohorte de l'apprenant (pour lien vers le kanban cohorte)
$studentCohort = null;
try {
    $coStmt = $pdo->prepare("
        SELECT c.id, c.name, rt.rncp_code
        FROM cohort_members cm
        JOIN cohorts c ON cm.cohort_id = c.id
        LEFT JOIN rncp_titles rt ON c.rncp_title_id = rt.id
        WHERE cm.student_id = ? LIMIT 1
    ");
    $coStmt->execute([$userId]);
    $studentCohort = $coStmt->fetch() ?: null;
} catch (Exception $e) {}

// Obtenir ou créer le board personnel
$boardId = null;
try {
    $bStmt = $pdo->prepare("SELECT id FROM kanban_boards WHERE student_id=? AND cohort_id IS NULL");
    $bStmt->execute([$userId]);
    $boardRow = $bStmt->fetch();
    if (!$boardRow) {
        $pdo->prepare("INSERT INTO kanban_boards (student_id, created_by) VALUES (?,?)")->execute([$userId, $viewerId]);
        $boardId = (int)$pdo->lastInsertId();
    } else {
        $boardId = (int)$boardRow['id'];
    }
} catch (PDOException $e) {
    setFlash('error', 'Les tables Kanban ne sont pas encore créées. Veuillez exécuter la migration.');
    redirect(url('student/index.php'));
}

$validStatuses = ['todo','in_progress','submitted','graded'];
$idParam = $isOwnView ? '' : '&id='.$userId;

// ── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || ($_POST['ajax'] ?? '') === '1';
    if (!$isAjax) requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_card' && $canManage) {
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '') ?: null;
        $due   = $_POST['due_date'] ?? '';
        $seqId = (int)($_POST['sequence_id'] ?? 0) ?: null;
        $modId = (int)($_POST['module_id']   ?? 0) ?: null;
        if ($title) {
            $posStmt = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM kanban_cards WHERE board_id=? AND status='todo'");
            $posStmt->execute([$boardId]);
            $pos = (int)$posStmt->fetchColumn();
            $pdo->prepare("INSERT INTO kanban_cards (board_id,title,description,due_date,sequence_id,module_id,status,position,created_by) VALUES (?,?,?,?,?,?,'todo',?,?)")
                ->execute([$boardId, $title, $desc, $due ?: null, $seqId, $modId, $pos, $viewerId]);
            auditLog('student_kanban_card_created', 'student', $userId);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
            setFlash('success', 'Tâche ajoutée.');
        } else {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'Titre requis.']); exit; }
            setFlash('error', 'Le titre est requis.');
        }

    } elseif ($action === 'move_card' && $canManage) {
        $cardId    = (int)($_POST['card_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        if ($cardId && in_array($newStatus, $validStatuses)) {
            $pdo->prepare("UPDATE kanban_cards SET status=? WHERE id=? AND board_id=?")->execute([$newStatus, $cardId, $boardId]);
            auditLog('student_kanban_card_moved', 'student', $userId);
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }

    } elseif ($action === 'delete_card' && $canDelete) {
        $cardId = (int)($_POST['card_id'] ?? 0);
        if ($cardId) {
            $pdo->prepare("DELETE FROM kanban_cards WHERE id=? AND board_id=?")->execute([$cardId, $boardId]);
            auditLog('student_kanban_card_deleted', 'student', $userId);
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
    }

    redirect(url("student/kanban/index.php?$idParam"));
}

// ── Données ───────────────────────────────────────────────────
$cardsStmt = $pdo->prepare("
    SELECT kc.*, seq.title as seq_title, m.title as mod_title
    FROM kanban_cards kc
    LEFT JOIN sequences seq ON kc.sequence_id = seq.id
    LEFT JOIN modules   m   ON kc.module_id   = m.id
    WHERE kc.board_id = ?
    ORDER BY kc.status, kc.position ASC, kc.created_at ASC
");
$cardsStmt->execute([$boardId]);
$allCards = $cardsStmt->fetchAll();
$cardsByStatus = ['todo'=>[],'in_progress'=>[],'submitted'=>[],'graded'=>[]];
foreach ($allCards as $c) { $cardsByStatus[$c['status']][] = $c; }

// Cascade RNCP
$rncpTitles   = $pdo->query("SELECT id, rncp_code, title FROM rncp_titles ORDER BY rncp_code")->fetchAll(PDO::FETCH_ASSOC);
$actTypes     = $pdo->query("SELECT id, rncp_title_id, code, title FROM activity_types ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);
$competencies = $pdo->query("SELECT id, activity_type_id, code, title FROM competencies ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);
$sequences    = $pdo->query("SELECT id, competency_id, title FROM sequences ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);
$allModules   = $pdo->query("SELECT id, sequence_id, title FROM modules ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);

$sidebarRole = in_array($viewerRole, ['admin','pedagogy']) ? 'pedagogy'
             : ($viewerRole === 'teacher' ? 'teacher' : 'student');

$breadcrumbs = $isOwnView
    ? [['Mon espace', url('student/index.php')], ['Mon Kanban', '']]
    : (in_array($viewerRole, ['admin','pedagogy'])
        ? [['Apprenants', url('admin/users/index.php?role=student')], [e($student['first_name'].' '.$student['last_name']), url('admin/users/progress.php?id='.$userId)], ['Kanban','']]
        : [['Mes filleuls', url('teacher/students/index.php')], [e($student['first_name'].' '.$student['last_name']),''], ['Kanban','']]);

$colMeta = [
    'todo'        => ['label'=>'À faire',  'color'=>'var(--text-muted)',   'icon'=>'fa-circle',        'bg'=>'rgba(148,163,184,.08)'],
    'in_progress' => ['label'=>'En cours', 'color'=>'var(--primary-light)','icon'=>'fa-spinner',       'bg'=>'rgba(99,102,241,.08)'],
    'submitted'   => ['label'=>'Rendu',    'color'=>'var(--warning)',      'icon'=>'fa-paper-plane',   'bg'=>'rgba(251,191,36,.08)'],
    'graded'      => ['label'=>'Corrigé',  'color'=>'var(--success)',      'icon'=>'fa-check-circle',  'bg'=>'rgba(52,211,153,.08)'],
];

$csrfToken = $_SESSION['csrf_token'] ?? '';

$pageTitle = $isOwnView ? 'Mon Kanban' : 'Kanban — '.$student['first_name'].' '.$student['last_name'];
renderHead($pageTitle);
renderSidebar($sidebarRole);
renderTopbar($pageTitle, $breadcrumbs);
?>
<style>
.kanban-board{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;align-items:start}
@media(max-width:900px){.kanban-board{grid-template-columns:repeat(2,1fr)}}
@media(max-width:580px){.kanban-board{grid-template-columns:1fr}}
.kanban-col{background:var(--bg-card);border-radius:var(--radius-lg);padding:12px;min-height:260px;transition:background .15s}
.kanban-col.drag-over{background:rgba(99,102,241,.13);outline:2px dashed var(--primary-light)}
.kanban-col-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:10px;border-bottom:2px solid var(--border-color)}
.kanban-col-title{font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px}
.kanban-col-count{font-size:11px;background:var(--bg-elevated);border-radius:99px;padding:2px 8px;font-weight:700;color:var(--text-muted)}
.kanban-cards{display:flex;flex-direction:column;gap:10px;min-height:60px}
.kanban-card{background:var(--bg-elevated);border-radius:var(--radius);padding:12px;cursor:grab;border-left:3px solid transparent;transition:box-shadow .15s,opacity .15s;position:relative}
.kanban-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.25)}
.kanban-card.dragging{opacity:.35;cursor:grabbing}
.kanban-card-title{font-size:13px;font-weight:700;color:white;margin-bottom:5px;line-height:1.35}
.kanban-card-desc{font-size:11px;color:var(--text-muted);margin-bottom:8px;line-height:1.4}
.kanban-card-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:10px;color:var(--text-faint)}
.kanban-card-actions{display:flex;gap:4px;position:absolute;top:8px;right:8px;opacity:0;transition:opacity .15s}
.kanban-card:hover .kanban-card-actions{opacity:1}
</style>

<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Lien cohorte si applicable -->
  <?php if ($studentCohort): ?>
  <div class="card" style="margin-bottom:18px;border:1px solid rgba(99,102,241,.25)">
    <div class="card-body" style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div style="font-size:13px;color:var(--text-muted)">
        <i class="fas fa-layer-group" style="color:var(--primary-light);margin-right:6px"></i>
        <?= $isOwnView ? 'Vous faites partie de la cohorte' : 'Apprenant dans la cohorte' ?>
        <strong style="color:white;margin-left:4px"><?= e($studentCohort['name']) ?></strong>
        <?php if ($studentCohort['rncp_code']): ?><span class="badge badge-primary" style="margin-left:6px"><?= e($studentCohort['rncp_code']) ?></span><?php endif; ?>
      </div>
      <a href="<?= url('pedagogy/cohorts/kanban.php?id='.$studentCohort['id'].'&view=student') ?>" class="btn btn-secondary btn-sm">
        <i class="fas fa-columns"></i> Kanban de la cohorte
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- En-tête -->
  <div class="page-header" style="margin-bottom:20px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <h1 style="margin-bottom:4px"><?= $isOwnView ? 'Mon Kanban personnel' : 'Kanban de '.$student['first_name'].' '.$student['last_name'] ?></h1>
        <p style="color:var(--text-muted);font-size:13px"><?= count($allCards) ?> tâche<?= count($allCards) > 1 ? 's' : '' ?> au total</p>
      </div>
      <?php if ($canManage): ?>
      <button class="btn btn-primary btn-sm" onclick="openAddModal()">
        <i class="fas fa-plus"></i> Nouvelle tâche
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Kanban board -->
  <div class="kanban-board">
    <?php foreach ($colMeta as $status => $meta): ?>
    <div class="kanban-col"
         data-col="<?= $status ?>"
         ondragover="dragOver(event)"
         ondragleave="dragLeave(event)"
         ondrop="dropCard(event,'<?= $status ?>')">
      <div class="kanban-col-header">
        <div class="kanban-col-title" style="color:<?= $meta['color'] ?>">
          <i class="fas <?= $meta['icon'] ?>" style="font-size:11px"></i>
          <?= $meta['label'] ?>
        </div>
        <span class="kanban-col-count" id="count-<?= $status ?>"><?= count($cardsByStatus[$status]) ?></span>
      </div>
      <div class="kanban-cards" id="cards-<?= $status ?>">
        <?php foreach ($cardsByStatus[$status] as $card): ?>
        <?php
          $dueTs = $card['due_date'] ? strtotime($card['due_date']) : null;
          $isOverdue = $dueTs && $dueTs < strtotime('today') && $status !== 'graded';
        ?>
        <div class="kanban-card"
             style="border-left-color:<?= $meta['color'] ?>"
             draggable="<?= $canManage ? 'true' : 'false' ?>"
             data-card-id="<?= $card['id'] ?>"
             ondragstart="dragStart(event,<?= $card['id'] ?>)"
             ondragend="dragEnd(event)">
          <?php if ($canManage): ?>
          <div class="kanban-card-actions">
            <?php if ($canDelete): ?>
            <button class="btn btn-ghost btn-sm" style="padding:3px 6px;color:var(--danger)"
                    onclick="deleteCard(<?= $card['id'] ?>,event)" title="Supprimer">
              <i class="fas fa-trash" style="font-size:10px"></i>
            </button>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <div class="kanban-card-title"><?= e($card['title']) ?></div>
          <?php if ($card['description']): ?>
          <div class="kanban-card-desc"><?= e(mb_strimwidth($card['description'],0,80,'…')) ?></div>
          <?php endif; ?>
          <div class="kanban-card-meta">
            <?php if ($dueTs): ?>
            <span style="color:<?= $isOverdue ? 'var(--danger)' : 'var(--text-faint)' ?>">
              <i class="fas fa-calendar-alt"></i>
              <?= $isOverdue ? '<b>Retard</b> ' : '' ?><?= date('d/m/Y',$dueTs) ?>
            </span>
            <?php endif; ?>
            <?php if ($card['seq_title']): ?>
            <span><i class="fas fa-sitemap"></i> <?= e(mb_strimwidth($card['seq_title'],0,30,'…')) ?></span>
            <?php elseif ($card['mod_title']): ?>
            <span><i class="fas fa-book"></i> <?= e(mb_strimwidth($card['mod_title'],0,30,'…')) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (empty($allCards) && $canManage): ?>
  <div class="empty-state" style="margin-top:40px">
    <div class="icon"><i class="fas fa-columns"></i></div>
    <h3>Aucune tâche</h3>
    <p><?= $isOwnView ? 'Ajoutez votre première tâche personnelle.' : 'Aucune tâche personnelle pour cet apprenant.' ?></p>
    <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Nouvelle tâche</button>
  </div>
  <?php endif; ?>

</div>

<!-- ════ MODAL : Nouvelle tâche ════ -->
<div id="add-modal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeAddModal()">
  <div class="modal-content" style="max-width:500px;width:95%">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-plus-circle" style="color:var(--primary-light);margin-right:8px"></i>Nouvelle tâche</h3>
      <button class="modal-close" onclick="closeAddModal()">&times;</button>
    </div>
    <form id="add-card-form" onsubmit="submitAddCard(event)">
      <input type="hidden" name="action" value="add_card">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Titre <span style="color:var(--danger)">*</span></label>
          <input type="text" name="title" class="form-control" required placeholder="Ex : Fiche de synthèse…">
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Objectifs, consignes…"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Date d'échéance</label>
          <input type="date" name="due_date" class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
        <details style="margin-top:4px">
          <summary style="cursor:pointer;font-size:12px;color:var(--text-muted);padding:6px 0;list-style:none;display:flex;align-items:center;gap:6px">
            <i class="fas fa-sitemap" style="color:var(--primary-light)"></i>
            Lier à une séquence / séance <span style="font-size:10px;color:var(--text-faint)">(optionnel)</span>
          </summary>
          <div style="margin-top:10px;padding:12px;background:var(--bg-elevated);border-radius:var(--radius)">
            <div class="form-group">
              <label class="form-label" style="font-size:11px">Titre RNCP</label>
              <select id="k-rncp" class="form-control form-control-sm" onchange="kCascade('rncp',this.value)">
                <option value="">— Tous —</option>
                <?php foreach ($rncpTitles as $r): ?>
                <option value="<?= $r['id'] ?>"><?= e($r['rncp_code'].' – '.$r['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" style="font-size:11px">Activité type</label>
              <select id="k-at" class="form-control form-control-sm" onchange="kCascade('at',this.value)" disabled>
                <option value="">— Sélectionnez un titre RNCP —</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" style="font-size:11px">Compétence</label>
              <select id="k-comp" class="form-control form-control-sm" onchange="kCascade('comp',this.value)" disabled>
                <option value="">— Sélectionnez une AT —</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" style="font-size:11px">Séquence</label>
              <select id="k-seq" name="sequence_id" class="form-control form-control-sm" onchange="kCascade('seq',this.value)" disabled>
                <option value="">— Sélectionnez une compétence —</option>
              </select>
            </div>
            <div class="form-group" id="k-mod-group" style="display:none">
              <label class="form-label" style="font-size:11px">Séance spécifique <span style="font-size:10px;color:var(--text-faint)">(optionnel)</span></label>
              <select id="k-mod" name="module_id" class="form-control form-control-sm">
                <option value="">— Toute la séquence —</option>
              </select>
            </div>
          </div>
        </details>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeAddModal()">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter</button>
      </div>
    </form>
  </div>
</div>

<?php
$kAt   = json_encode($actTypes,     JSON_HEX_TAG|JSON_HEX_APOS);
$kComp = json_encode($competencies, JSON_HEX_TAG|JSON_HEX_APOS);
$kSeq  = json_encode($sequences,    JSON_HEX_TAG|JSON_HEX_APOS);
$kMod  = json_encode($allModules,   JSON_HEX_TAG|JSON_HEX_APOS);
?>
<script>
const CSRF = <?= json_encode($csrfToken) ?>;
const CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
const CAN_DELETE = <?= $canDelete ? 'true' : 'false' ?>;

// ── Drag & Drop ──────────────────────────────────────────────
let dragged = null;

function dragStart(e, id) {
    dragged = id;
    setTimeout(() => e.target.classList.add('dragging'), 0);
}
function dragEnd(e) { e.target.classList.remove('dragging'); }
function dragOver(e) { e.preventDefault(); e.currentTarget.classList.add('drag-over'); }
function dragLeave(e) { e.currentTarget.classList.remove('drag-over'); }

async function dropCard(e, newStatus) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    if (!dragged) return;

    const form = new FormData();
    form.append('action',    'move_card');
    form.append('card_id',   dragged);
    form.append('status',    newStatus);
    form.append('csrf_token', CSRF);
    form.append('ajax', '1');

    const res  = await fetch('', {method:'POST', body:form});
    const data = await res.json();
    if (data.ok) {
        const card = document.querySelector(`[data-card-id="${dragged}"]`);
        const col  = document.getElementById('cards-' + newStatus);
        if (card && col) { col.appendChild(card); updateCounts(); }
    }
    dragged = null;
}

function updateCounts() {
    ['todo','in_progress','submitted','graded'].forEach(s => {
        const col = document.getElementById('cards-' + s);
        const cnt = document.getElementById('count-' + s);
        if (col && cnt) cnt.textContent = col.children.length;
    });
}

// ── Supprimer ────────────────────────────────────────────────
async function deleteCard(cardId, e) {
    e.stopPropagation();
    if (!confirm('Supprimer cette tâche ?')) return;

    const form = new FormData();
    form.append('action',  'delete_card');
    form.append('card_id', cardId);
    form.append('csrf_token', CSRF);
    form.append('ajax', '1');

    const res  = await fetch('', {method:'POST', body:form});
    const data = await res.json();
    if (data.ok) {
        const card = document.querySelector(`[data-card-id="${cardId}"]`);
        if (card) { card.remove(); updateCounts(); }
    }
}

// ── Modal ────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('add-modal').style.display = 'flex';
    document.getElementById('add-card-form').reset();
    document.getElementById('k-at').disabled   = true;
    document.getElementById('k-comp').disabled = true;
    document.getElementById('k-seq').disabled  = true;
    document.getElementById('k-mod-group').style.display = 'none';
}
function closeAddModal() { document.getElementById('add-modal').style.display = 'none'; }

async function submitAddCard(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('ajax', '1');
    const btn = e.target.querySelector('[type=submit]');
    btn.disabled = true;
    const res  = await fetch('', {method:'POST', body:form});
    const data = await res.json();
    btn.disabled = false;
    if (data.ok) { closeAddModal(); location.reload(); }
    else alert(data.msg || 'Erreur.');
}

// ── Cascade RNCP ─────────────────────────────────────────────
const K_AT   = <?= $kAt ?>;
const K_COMP = <?= $kComp ?>;
const K_SEQ  = <?= $kSeq ?>;
const K_MOD  = <?= $kMod ?>;

function kPopulate(sel, items, placeholder) {
    sel.innerHTML = `<option value="">${placeholder}</option>`;
    items.forEach(i => {
        const o = document.createElement('option');
        o.value = i.id;
        o.textContent = (i.code ? i.code + ' – ' : '') + i.title;
        sel.appendChild(o);
    });
}
function kCascade(level, val) {
    val = parseInt(val) || 0;
    if (level === 'rncp') {
        const atEl = document.getElementById('k-at');
        kPopulate(atEl, val ? K_AT.filter(a => a.rncp_title_id == val) : K_AT, '— Sélectionnez une AT —');
        atEl.disabled = false;
        document.getElementById('k-comp').disabled = true;
        document.getElementById('k-seq').disabled  = true;
        document.getElementById('k-mod-group').style.display = 'none';
        document.getElementById('k-comp').innerHTML = '<option value="">— Sélectionnez une AT —</option>';
        document.getElementById('k-seq').innerHTML  = '<option value="">— Sélectionnez une compétence —</option>';
        document.querySelector('[name=sequence_id]').value = '';
        document.querySelector('[name=module_id]').value   = '';
    } else if (level === 'at') {
        const compEl = document.getElementById('k-comp');
        kPopulate(compEl, val ? K_COMP.filter(c => c.activity_type_id == val) : K_COMP, '— Sélectionnez une compétence —');
        compEl.disabled = false;
        document.getElementById('k-seq').disabled = true;
        document.getElementById('k-mod-group').style.display = 'none';
        document.getElementById('k-seq').innerHTML = '<option value="">— Sélectionnez une compétence —</option>';
        document.querySelector('[name=sequence_id]').value = '';
        document.querySelector('[name=module_id]').value   = '';
    } else if (level === 'comp') {
        const seqEl = document.getElementById('k-seq');
        kPopulate(seqEl, val ? K_SEQ.filter(s => s.competency_id == val) : K_SEQ, '— Sélectionnez une séquence —');
        seqEl.disabled = false;
        document.getElementById('k-mod-group').style.display = 'none';
        document.querySelector('[name=module_id]').value = '';
    } else if (level === 'seq') {
        const modGroup = document.getElementById('k-mod-group');
        const modSel   = document.getElementById('k-mod');
        if (val) {
            const mods = K_MOD.filter(m => m.sequence_id == val);
            modSel.innerHTML = '<option value="">— Toute la séquence —</option>';
            mods.forEach(m => { const o=document.createElement('option'); o.value=m.id; o.textContent=m.title; modSel.appendChild(o); });
            modGroup.style.display = mods.length ? '' : 'none';
        } else {
            modGroup.style.display = 'none';
        }
        document.querySelector('[name=module_id]').value = '';
    }
}
</script>
<?php renderFooter(); ?>
