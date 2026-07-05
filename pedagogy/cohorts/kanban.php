<?php
require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/layout.php';

// Auth : admin, pédagogie, enseignant, apprenant (membre de la cohorte)
$viewer     = currentUser();
if (!$viewer) { redirect(url('index.php')); }
$viewerRole = $viewer['role'];
$viewerId   = (int)$viewer['id'];
if (!in_array($viewerRole, ['admin','pedagogy','teacher','student'])) { redirect(url('index.php')); }

$pdo      = getDB();
$cohortId = (int)($_GET['id'] ?? 0);
$backUrl  = in_array($viewerRole, ['admin','pedagogy'])
    ? url('pedagogy/cohorts/index.php')
    : ($viewerRole === 'teacher' ? url('teacher/cahier/index.php') : url('student/kanban/index.php'));
if (!$cohortId) { redirect($backUrl); }

// Charger la cohorte
$cStmt = $pdo->prepare("
    SELECT c.*, rt.rncp_code, rt.title as rncp_title
    FROM cohorts c LEFT JOIN rncp_titles rt ON c.rncp_title_id = rt.id
    WHERE c.id = ?
");
$cStmt->execute([$cohortId]);
$cohort = $cStmt->fetch();
if (!$cohort) { setFlash('error', 'Cohorte introuvable.'); redirect($backUrl); }

// Apprenant : vérifier qu'il est membre de la cohorte
if ($viewerRole === 'student') {
    $chkMember = $pdo->prepare("SELECT id FROM cohort_members WHERE cohort_id=? AND student_id=?");
    $chkMember->execute([$cohortId, $viewerId]);
    if (!$chkMember->fetch()) {
        setFlash('error', "Vous n'êtes pas membre de cette cohorte.");
        redirect(url('student/kanban/index.php'));
    }
}

$canManageTasks = in_array($viewerRole, ['admin','pedagogy','teacher']); // créer/déplacer les cartes-tâches
$canDeleteTasks = in_array($viewerRole, ['admin','pedagogy']);
$canMoveMember  = true; // tous les rôles peuvent déplacer une carte apprenant (étudiant = la sienne uniquement)

$view = in_array($_GET['view'] ?? '', ['task','student']) ? $_GET['view'] : 'task';

// Obtenir ou créer le board
try {
    $bStmt = $pdo->prepare("SELECT id FROM kanban_boards WHERE cohort_id=?");
    $bStmt->execute([$cohortId]);
    $boardRow = $bStmt->fetch();
    if (!$boardRow) {
        $pdo->prepare("INSERT INTO kanban_boards (cohort_id, created_by) VALUES (?,?)")->execute([$cohortId, $viewerId]);
        $boardId = (int)$pdo->lastInsertId();
    } else {
        $boardId = (int)$boardRow['id'];
    }
} catch (PDOException $e) {
    setFlash('error', 'Les tables Kanban ne sont pas encore créées. Veuillez exécuter la migration.');
    redirect($backUrl);
}

$validStatuses = ['todo','in_progress','submitted','graded'];

// ── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Réponse JSON pour les appels AJAX
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || ($_POST['ajax'] ?? '') === '1';

    if (!$isAjax) requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_card' && $canManageTasks) {
        $title   = trim($_POST['title'] ?? '');
        $desc    = trim($_POST['description'] ?? '') ?: null;
        $due     = $_POST['due_date'] ?? '';
        $seqId   = (int)($_POST['sequence_id'] ?? 0) ?: null;
        $modId   = (int)($_POST['module_id']   ?? 0) ?: null;
        if ($title) {
            $posStmt = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM kanban_cards WHERE board_id=? AND status='todo'");
            $posStmt->execute([$boardId]);
            $pos = (int)$posStmt->fetchColumn();
            $pdo->prepare("INSERT INTO kanban_cards (board_id,title,description,due_date,sequence_id,module_id,status,position,created_by) VALUES (?,?,?,?,?,?,'todo',?,?)")
                ->execute([$boardId, $title, $desc, $due ?: null, $seqId, $modId, $pos, $viewerId]);
            auditLog('kanban_card_created', 'cohort', $cohortId);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
            setFlash('success', 'Tâche ajoutée.');
        } else {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'Titre requis.']); exit; }
            setFlash('error', 'Le titre est requis.');
        }

    } elseif ($action === 'move_card' && $canManageTasks) {
        $cardId    = (int)($_POST['card_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        if ($cardId && in_array($newStatus, $validStatuses)) {
            $pdo->prepare("UPDATE kanban_cards SET status=? WHERE id=? AND board_id=?")->execute([$newStatus, $cardId, $boardId]);
            auditLog('kanban_card_moved', 'cohort', $cohortId);
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }

    } elseif ($action === 'move_member' && $canMoveMember) {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        // Apprenant ne peut déplacer que sa propre carte
        if ($viewerRole === 'student' && $studentId !== $viewerId) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'Interdit.']); exit; }
            redirect(url("pedagogy/cohorts/kanban.php?id=$cohortId&view=student"));
        }
        if ($studentId && in_array($newStatus, $validStatuses)) {
            $pdo->prepare("
                INSERT INTO kanban_member_status (board_id,student_id,status,updated_by) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE status=VALUES(status), updated_by=VALUES(updated_by)
            ")->execute([$boardId, $studentId, $newStatus, $viewerId]);
            auditLog('kanban_member_moved', 'cohort', $cohortId);
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }

    } elseif ($action === 'delete_card' && $canDeleteTasks) {
        $cardId = (int)($_POST['card_id'] ?? 0);
        if ($cardId) {
            $pdo->prepare("DELETE FROM kanban_cards WHERE id=? AND board_id=?")->execute([$cardId, $boardId]);
            auditLog('kanban_card_deleted', 'cohort', $cohortId);
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
    }

    redirect(url("pedagogy/cohorts/kanban.php?id=$cohortId&view=$view"));
}

// ── Données ───────────────────────────────────────────────────

// Cartes-tâches
$cardsStmt = $pdo->prepare("
    SELECT kc.*, u.first_name as cf, u.last_name as cl,
           seq.title as seq_title, m.title as mod_title
    FROM kanban_cards kc
    JOIN  users u   ON kc.created_by   = u.id
    LEFT JOIN sequences seq ON kc.sequence_id = seq.id
    LEFT JOIN modules   m   ON kc.module_id   = m.id
    WHERE kc.board_id = ?
    ORDER BY kc.status, kc.position ASC, kc.created_at ASC
");
$cardsStmt->execute([$boardId]);
$allCards = $cardsStmt->fetchAll();
$cardsByStatus = ['todo'=>[], 'in_progress'=>[], 'submitted'=>[], 'graded'=>[]];
foreach ($allCards as $c) { $cardsByStatus[$c['status']][] = $c; }

// Membres avec statut kanban
$membersStmt = $pdo->prepare("
    SELECT u.id, u.first_name, u.last_name, u.avatar,
           COALESCE(kms.status,'todo') as kanban_status,
           cm.excluded_from_stats
    FROM cohort_members cm
    JOIN  users u ON cm.student_id = u.id
    LEFT JOIN kanban_member_status kms ON kms.board_id=? AND kms.student_id=u.id
    WHERE cm.cohort_id=?
    ORDER BY u.last_name, u.first_name
");
$membersStmt->execute([$boardId, $cohortId]);
$members = $membersStmt->fetchAll();
$membersByStatus = ['todo'=>[], 'in_progress'=>[], 'submitted'=>[], 'graded'=>[]];
$memberMap = [];
foreach ($members as $m) {
    $membersByStatus[$m['kanban_status']][] = $m;
    $memberMap[$m['id']] = $m;
}

// Cascade RNCP pour modal
$rncpTitles   = $pdo->query("SELECT id, rncp_code, title FROM rncp_titles ORDER BY rncp_code")->fetchAll(PDO::FETCH_ASSOC);
$actTypes     = $pdo->query("SELECT id, rncp_title_id, code, title FROM activity_types ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);
$competencies = $pdo->query("SELECT id, activity_type_id, code, title FROM competencies ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);
$sequences    = $pdo->query("SELECT id, competency_id, title FROM sequences ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);
$allModules   = $pdo->query("SELECT id, sequence_id, title FROM modules ORDER BY order_num")->fetchAll(PDO::FETCH_ASSOC);

$sidebarRole = in_array($viewerRole, ['admin','pedagogy']) ? 'pedagogy'
             : ($viewerRole === 'teacher' ? 'teacher' : 'student');
$breadcrumbs = in_array($viewerRole, ['admin','pedagogy'])
    ? [['Pédagogie', url('pedagogy/index.php')],['Cohortes', url('pedagogy/cohorts/index.php')],[e($cohort['name']), url('pedagogy/cohorts/view.php?id='.$cohortId)],['Kanban','']]
    : ($viewerRole === 'teacher'
        ? [['Enseignant', url('teacher/index.php')],[e($cohort['name']),''],['Kanban','']]
        : [['Mon espace', url('student/index.php')],[e($cohort['name']),''],['Kanban','']]);

$colMeta = [
    'todo'        => ['label'=>'À faire',  'color'=>'var(--text-muted)',  'icon'=>'fa-circle',        'bg'=>'rgba(148,163,184,.08)'],
    'in_progress' => ['label'=>'En cours', 'color'=>'var(--primary-light)','icon'=>'fa-spinner',      'bg'=>'rgba(99,102,241,.08)'],
    'submitted'   => ['label'=>'Rendu',    'color'=>'var(--warning)',      'icon'=>'fa-paper-plane',  'bg'=>'rgba(251,191,36,.08)'],
    'graded'      => ['label'=>'Corrigé',  'color'=>'var(--success)',      'icon'=>'fa-check-circle', 'bg'=>'rgba(52,211,153,.08)'],
];

$csrfToken = $_SESSION[CSRF_TOKEN_NAME] ?? '';

renderHead('Kanban — ' . $cohort['name']);
renderSidebar($sidebarRole);
renderTopbar('Kanban', $breadcrumbs);
?>
<style>
.kanban-board{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;align-items:start}
@media(max-width:900px){.kanban-board{grid-template-columns:repeat(2,1fr)}}
@media(max-width:580px){.kanban-board{grid-template-columns:1fr}}
.kanban-col{background:var(--bg-card);border-radius:var(--radius-lg);padding:12px;min-height:300px;transition:background .15s}
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
.kanban-card-avatars{display:flex;flex-wrap:wrap;gap:3px;margin-top:8px}
.kavatar{width:22px;height:22px;border-radius:50%;font-size:8px;font-weight:800;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg-elevated);cursor:default}
.kanban-card-actions{display:flex;gap:4px;position:absolute;top:8px;right:8px;opacity:0;transition:opacity .15s}
.kanban-card:hover .kanban-card-actions{opacity:1}
.member-card{background:var(--bg-elevated);border-radius:var(--radius);padding:10px 12px;cursor:grab;display:flex;align-items:center;gap:10px;transition:box-shadow .15s,opacity .15s}
.member-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.25)}
.member-card.dragging{opacity:.35;cursor:grabbing}
.member-card.is-own{border:1px solid rgba(99,102,241,.4)}
.kview-toggle{display:flex;background:var(--bg-elevated);border-radius:var(--radius);padding:3px;gap:3px}
.kview-btn{padding:6px 14px;border-radius:calc(var(--radius) - 2px);font-size:12px;font-weight:600;color:var(--text-muted);cursor:pointer;border:none;background:transparent;transition:all .15s}
.kview-btn.active{background:var(--primary);color:white}
</style>

<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- En-tête -->
  <div class="page-header" style="margin-bottom:20px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <h1 style="margin-bottom:4px">Kanban</h1>
        <p style="color:var(--text-muted);font-size:13px">
          <i class="fas fa-layer-group" style="color:var(--primary-light);margin-right:5px"></i>
          <?= e($cohort['name']) ?>
          <?php if ($cohort['rncp_code']): ?>
            <span class="badge badge-primary" style="margin-left:6px"><?= e($cohort['rncp_code']) ?></span>
          <?php endif; ?>
          <span style="margin:0 8px;color:var(--border-color)">·</span>
          <?= count($allCards) ?> tâche<?= count($allCards) > 1 ? 's' : '' ?>
          <span style="margin:0 8px;color:var(--border-color)">·</span>
          <?= count($members) ?> apprenant<?= count($members) > 1 ? 's' : '' ?>
        </p>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <!-- Toggle vue -->
        <div class="kview-toggle">
          <button class="kview-btn <?= $view === 'task' ? 'active' : '' ?>"
                  onclick="switchView('task',this)">
            <i class="fas fa-tasks"></i> Tâches
          </button>
          <button class="kview-btn <?= $view === 'student' ? 'active' : '' ?>"
                  onclick="switchView('student',this)">
            <i class="fas fa-users"></i> Apprenants
          </button>
        </div>
        <?php if ($canManageTasks): ?>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()">
          <i class="fas fa-plus"></i> Nouvelle tâche
        </button>
        <?php endif; ?>
        <a href="<?= url('pedagogy/cohorts/agenda.php?id='.$cohortId) ?>" class="btn btn-secondary btn-sm">
          <i class="fas fa-book-open"></i> Cahier
        </a>
      </div>
    </div>
  </div>

  <!-- ════ VUE PAR TÂCHE ════ -->
  <div id="view-task" style="<?= $view === 'student' ? 'display:none' : '' ?>">
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
          <span class="kanban-col-count" id="count-task-<?= $status ?>"><?= count($cardsByStatus[$status]) ?></span>
        </div>
        <div class="kanban-cards" id="cards-<?= $status ?>">
          <?php foreach ($cardsByStatus[$status] as $card): ?>
          <?php
            $borderColor = $meta['color'];
            $dueTs = $card['due_date'] ? strtotime($card['due_date']) : null;
            $isOverdue = $dueTs && $dueTs < strtotime('today') && $status !== 'graded';
          ?>
          <div class="kanban-card"
               style="border-left-color:<?= $borderColor ?>"
               draggable="<?= $canManageTasks ? 'true' : 'false' ?>"
               data-card-id="<?= $card['id'] ?>"
               ondragstart="dragStart(event,<?= $card['id'] ?>,'card')"
               ondragend="dragEnd(event)">
            <?php if ($canManageTasks): ?>
            <div class="kanban-card-actions">
              <?php if ($canDeleteTasks): ?>
              <button class="btn btn-ghost btn-sm" style="padding:3px 6px;color:var(--danger)"
                      onclick="deleteCard(<?= $card['id'] ?>,<?= (int)($card['board_id']) ?>,event)"
                      title="Supprimer"><i class="fas fa-trash" style="font-size:10px"></i></button>
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
            <!-- Avatars membres -->
            <?php if (!empty($members)): ?>
            <div class="kanban-card-avatars">
              <?php foreach (array_slice($members,0,8) as $mbr): ?>
              <?php $col = getAvatarColor($mbr['first_name'].$mbr['last_name']); $initials = getAvatarInitials($mbr['first_name'],$mbr['last_name']); ?>
              <div class="kavatar" style="background:<?= $col ?>" title="<?= e($mbr['first_name'].' '.$mbr['last_name']) ?>"><?= $initials ?></div>
              <?php endforeach; ?>
              <?php if (count($members) > 8): ?>
              <div class="kavatar" style="background:var(--bg-hover);color:var(--text-muted)">+<?= count($members)-8 ?></div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (empty($allCards) && $canManageTasks): ?>
    <div class="empty-state" style="margin-top:40px">
      <div class="icon"><i class="fas fa-columns"></i></div>
      <h3>Aucune tâche</h3>
      <p>Créez la première tâche pour cette cohorte.</p>
      <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Nouvelle tâche</button>
    </div>
    <?php endif; ?>
  </div>

  <!-- ════ VUE PAR APPRENANT ════ -->
  <div id="view-student" style="<?= $view === 'task' ? 'display:none' : '' ?>">
    <?php if (empty($members)): ?>
    <div class="empty-state">
      <div class="icon"><i class="fas fa-users"></i></div>
      <h3>Aucun apprenant</h3>
      <p>Ajoutez des apprenants à cette cohorte depuis la <a href="<?= url('pedagogy/cohorts/view.php?id='.$cohortId) ?>">page cohorte</a>.</p>
    </div>
    <?php else: ?>
    <div class="kanban-board">
      <?php foreach ($colMeta as $status => $meta): ?>
      <div class="kanban-col"
           data-col-member="<?= $status ?>"
           ondragover="dragOver(event)"
           ondragleave="dragLeave(event)"
           ondrop="dropMember(event,'<?= $status ?>')">
        <div class="kanban-col-header">
          <div class="kanban-col-title" style="color:<?= $meta['color'] ?>">
            <i class="fas <?= $meta['icon'] ?>" style="font-size:11px"></i>
            <?= $meta['label'] ?>
          </div>
          <span class="kanban-col-count" id="count-member-<?= $status ?>"><?= count($membersByStatus[$status]) ?></span>
        </div>
        <div class="kanban-cards" id="members-<?= $status ?>">
          <?php foreach ($membersByStatus[$status] as $mbr): ?>
          <?php
            $isOwn = ($viewerRole === 'student' && $mbr['id'] === $viewerId);
            $isDraggable = $canMoveMember && ($viewerRole !== 'student' || $isOwn);
          ?>
          <div class="member-card <?= $isOwn ? 'is-own' : '' ?>"
               draggable="<?= $isDraggable ? 'true' : 'false' ?>"
               data-student-id="<?= $mbr['id'] ?>"
               ondragstart="dragStart(event,<?= $mbr['id'] ?>,'member')"
               ondragend="dragEnd(event)">
            <div class="kavatar" style="background:<?= getAvatarColor($mbr['first_name'].$mbr['last_name']) ?>;width:32px;height:32px;font-size:11px;flex-shrink:0">
              <?= getAvatarInitials($mbr['first_name'],$mbr['last_name']) ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:700;color:white">
                <?= e($mbr['first_name'].' '.$mbr['last_name']) ?>
                <?php if ($isOwn): ?><span style="font-size:10px;color:var(--primary-light);margin-left:4px">(moi)</span><?php endif; ?>
                <?php if ($mbr['excluded_from_stats']): ?><span style="font-size:10px;color:var(--text-faint);margin-left:4px">⚗</span><?php endif; ?>
              </div>
              <div style="font-size:10px;color:var(--text-faint);margin-top:2px">
                <?= $colMeta[$status]['label'] ?>
              </div>
            </div>
            <?php if ($isDraggable): ?>
            <i class="fas fa-grip-vertical" style="color:var(--border-color);font-size:12px;cursor:grab"></i>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- ════ MODAL : Nouvelle tâche ════ -->
<div id="add-modal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeAddModal()">
  <div class="modal-content" style="max-width:540px;width:95%">
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
          <input type="text" name="title" class="form-control" required placeholder="Ex : Rapport de stage…">
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Consignes, objectifs…"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Date d'échéance</label>
          <input type="date" name="due_date" class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
        <!-- Cascade RNCP (optionnelle) -->
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
// JSON des hiérarchies pour la cascade JS
$kAt   = json_encode($actTypes,     JSON_HEX_TAG|JSON_HEX_APOS) ?: '[]';
$kComp = json_encode($competencies, JSON_HEX_TAG|JSON_HEX_APOS) ?: '[]';
$kSeq  = json_encode($sequences,    JSON_HEX_TAG|JSON_HEX_APOS) ?: '[]';
$kMod  = json_encode($allModules,   JSON_HEX_TAG|JSON_HEX_APOS) ?: '[]';
?>
<script>
const CSRF = <?= json_encode($csrfToken) ?>;
const BOARD_ID = <?= (int)$boardId ?>;
const COHORT_ID = <?= (int)$cohortId ?>;
const CAN_MANAGE = <?= $canManageTasks ? 'true' : 'false' ?>;
const VIEWER_ROLE = <?= json_encode($viewerRole) ?>;
const VIEWER_ID   = <?= (int)$viewerId ?>;

// ── Drag & Drop ──────────────────────────────────────────────
let dragged = null;
let dragType = null; // 'card' | 'member'

function dragStart(e, id, type) {
    dragged  = id;
    dragType = type;
    setTimeout(() => e.target.classList.add('dragging'), 0);
}
function dragEnd(e) {
    e.target.classList.remove('dragging');
}
function dragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
}
function dragLeave(e) {
    e.currentTarget.classList.remove('drag-over');
}

async function dropCard(e, newStatus) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    if (!dragged || dragType !== 'card') return;

    const form = new FormData();
    form.append('action',   'move_card');
    form.append('card_id',  dragged);
    form.append('status',   newStatus);
    form.append('csrf_token', CSRF);
    form.append('ajax', '1');

    const res = await fetch('', {method:'POST', body:form});
    const data = await res.json();
    if (data.ok) {
        const card = document.querySelector(`[data-card-id="${dragged}"]`);
        const col  = document.getElementById('cards-' + newStatus);
        if (card && col) { col.appendChild(card); updateCounts('task'); }
    }
    dragged = null; dragType = null;
}

async function dropMember(e, newStatus) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    if (!dragged || dragType !== 'member') return;
    if (VIEWER_ROLE === 'student' && dragged !== VIEWER_ID) return;

    const form = new FormData();
    form.append('action',     'move_member');
    form.append('student_id', dragged);
    form.append('status',     newStatus);
    form.append('csrf_token', CSRF);
    form.append('ajax', '1');

    const res = await fetch('', {method:'POST', body:form});
    const data = await res.json();
    if (data.ok) {
        const card = document.querySelector(`[data-student-id="${dragged}"]`);
        const col  = document.getElementById('members-' + newStatus);
        if (card && col) { col.appendChild(card); updateCounts('member'); }
    }
    dragged = null; dragType = null;
}

function updateCounts(type) {
    const statuses = ['todo','in_progress','submitted','graded'];
    statuses.forEach(s => {
        const colId = type === 'task' ? 'cards-' + s : 'members-' + s;
        const countId = (type === 'task' ? 'count-task-' : 'count-member-') + s;
        const col = document.getElementById(colId);
        const cnt = document.getElementById(countId);
        if (col && cnt) cnt.textContent = col.children.length;
    });
}

// ── Supprimer une carte ──────────────────────────────────────
async function deleteCard(cardId, boardId, e) {
    e.stopPropagation();
    if (!confirm('Supprimer cette tâche ?')) return;

    const form = new FormData();
    form.append('action',  'delete_card');
    form.append('card_id', cardId);
    form.append('csrf_token', CSRF);
    form.append('ajax', '1');

    const res = await fetch('', {method:'POST', body:form});
    const data = await res.json();
    if (data.ok) {
        const card = document.querySelector(`[data-card-id="${cardId}"]`);
        if (card) { card.remove(); updateCounts('task'); }
    }
}

// ── Toggle vue ───────────────────────────────────────────────
function switchView(v, btn) {
    document.getElementById('view-task').style.display    = v === 'task'    ? '' : 'none';
    document.getElementById('view-student').style.display = v === 'student' ? '' : 'none';
    document.querySelectorAll('.kview-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    history.replaceState(null, '', '?id=<?= $cohortId ?>&view=' + v);
}

// ── Modal Nouvelle tâche ─────────────────────────────────────
function openAddModal() {
    const m = document.getElementById('add-modal');
    m.style.display = '';
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('add-card-form').reset();
    document.getElementById('k-at').disabled   = true;
    document.getElementById('k-comp').disabled = true;
    document.getElementById('k-seq').disabled  = true;
    document.getElementById('k-mod-group').style.display = 'none';
}
function closeAddModal() {
    const m = document.getElementById('add-modal');
    m.classList.remove('open');
    m.style.display = 'none';
    document.body.style.overflow = '';
}

async function submitAddCard(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('ajax', '1');

    const btn = e.target.querySelector('[type=submit]');
    btn.disabled = true;
    try {
        const res  = await fetch(location.pathname + location.search, {method:'POST', body:form});
        const data = await res.json();
        if (data.ok) {
            closeAddModal();
            location.reload();
        } else {
            alert(data.msg || 'Erreur lors de l\'ajout.');
        }
    } catch (err) {
        alert('Erreur réseau. Veuillez réessayer.');
    } finally {
        btn.disabled = false;
    }
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
            mods.forEach(m => {
                const o = document.createElement('option');
                o.value = m.id; o.textContent = m.title;
                modSel.appendChild(o);
            });
            modGroup.style.display = mods.length ? '' : 'none';
        } else {
            modGroup.style.display = 'none';
        }
        document.querySelector('[name=module_id]').value = '';
    }
}
</script>
<?php renderFooter(); ?>
