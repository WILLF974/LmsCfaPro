<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/layout.php';
requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$user   = currentUser();
$view   = $_GET['view'] ?? 'inbox'; // inbox | sent | compose | thread
$msgId  = (int)($_GET['id'] ?? 0);

// Envoyer un message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $subject     = trim($_POST['subject'] ?? '');
        $body        = trim($_POST['body'] ?? '');
        $parentId    = (int)($_POST['parent_id'] ?? 0) ?: null;

        if ($recipientId && $body) {
            $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body, parent_id) VALUES (?,?,?,?,?)')->execute([$userId, $recipientId, $subject ?: null, $body, $parentId]);
            createNotification($recipientId, 'Nouveau message', $user['first_name'] . ' ' . $user['last_name'] . ' vous a envoyé un message.', 'message', url('messages/index.php?view=inbox'));
            setFlash('success', 'Message envoyé.');
        }
        redirect(url('messages/index.php?view=sent'));
    }

    if ($action === 'delete') {
        $mid = (int)($_POST['message_id'] ?? 0);
        // On ne supprime que si on est l'expéditeur ou le destinataire
        $pdo->prepare('DELETE FROM messages WHERE id = ? AND (sender_id = ? OR recipient_id = ?)')->execute([$mid, $userId, $userId]);
        setFlash('success', 'Message supprimé.');
        redirect(url('messages/index.php?view=' . $view));
    }

    if ($action === 'mark_read') {
        $mid = (int)($_POST['message_id'] ?? 0);
        $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE id = ? AND recipient_id = ? AND read_at IS NULL')->execute([$mid, $userId]);
        redirect(url('messages/index.php?view=thread&id=' . $mid));
    }
}

// Charger le thread si demandé
$thread = [];
$currentMsg = null;
if ($view === 'thread' && $msgId) {
    $stmt = $pdo->prepare("SELECT m.*, u.first_name as s_first, u.last_name as s_last, u.avatar as s_avatar FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.id = ? AND (m.sender_id = ? OR m.recipient_id = ?)");
    $stmt->execute([$msgId, $userId, $userId]);
    $currentMsg = $stmt->fetch();
    if ($currentMsg) {
        // Marquer comme lu
        $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE id = ? AND recipient_id = ? AND read_at IS NULL')->execute([$msgId, $userId]);
        // Réponses
        $replies = $pdo->prepare("SELECT m.*, u.first_name as s_first, u.last_name as s_last FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.parent_id = ? ORDER BY m.created_at ASC");
        $replies->execute([$msgId]);
        $thread = $replies->fetchAll();
    }
}

// Boîte de réception
$inbox = $pdo->prepare("SELECT m.*, u.first_name as s_first, u.last_name as s_last, u.avatar as s_avatar FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.recipient_id = ? AND m.parent_id IS NULL ORDER BY m.created_at DESC LIMIT 50");
$inbox->execute([$userId]);
$inbox = $inbox->fetchAll();

// Messages envoyés
$sent = $pdo->prepare("SELECT m.*, u.first_name as r_first, u.last_name as r_last FROM messages m JOIN users u ON m.recipient_id = u.id WHERE m.sender_id = ? AND m.parent_id IS NULL ORDER BY m.created_at DESC LIMIT 50");
$sent->execute([$userId]);
$sent = $sent->fetchAll();

// Contacts (utilisateurs pour composer)
$contacts = $pdo->query("SELECT id, first_name, last_name, role FROM users WHERE status = 'active' ORDER BY last_name, first_name")->fetchAll();

$unread = count(array_filter($inbox, fn($m) => !$m['read_at']));

renderHead('Messagerie');
renderSidebar($user['role'] === 'student' ? 'student' : ($user['role'] === 'teacher' ? 'teacher' : 'admin'));
renderTopbar('Messagerie', [['Messages', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div style="display:grid;grid-template-columns:260px 1fr;gap:24px;align-items:start;height:calc(100vh - 120px)">

    <!-- Sidebar messages -->
    <div style="display:flex;flex-direction:column;gap:8px">
      <a href="<?= url('messages/index.php?view=compose') ?>" class="btn btn-primary" style="justify-content:center;margin-bottom:8px">
        <i class="fas fa-pen"></i> Nouveau message
      </a>

      <div class="card">
        <div class="card-body" style="padding:8px">
          <a href="<?= url('messages/index.php?view=inbox') ?>" class="nav-link <?= $view==='inbox'?'active':'' ?>" style="border-radius:var(--radius)">
            <i class="fas fa-inbox"></i> Boîte de réception
            <?php if ($unread > 0): ?><span class="badge badge-primary" style="margin-left:auto"><?= $unread ?></span><?php endif; ?>
          </a>
          <a href="<?= url('messages/index.php?view=sent') ?>" class="nav-link <?= $view==='sent'?'active':'' ?>" style="border-radius:var(--radius)">
            <i class="fas fa-paper-plane"></i> Envoyés
          </a>
        </div>
      </div>

      <!-- Contacts rapides -->
      <div class="card">
        <div class="card-header" style="padding:10px 14px"><h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0">Contacts</h4></div>
        <div class="card-body" style="padding:8px;max-height:300px;overflow-y:auto">
          <?php foreach (array_slice($contacts, 0, 15) as $c): ?>
          <?php if ($c['id'] === $userId) continue; ?>
          <a href="<?= url('messages/index.php?view=compose&to=' . $c['id']) ?>" class="nav-link" style="border-radius:var(--radius);padding:6px 8px">
            <div class="avatar avatar-sm" style="background:<?= getAvatarColor($c['first_name'].$c['last_name']) ?>;font-size:11px;width:28px;height:28px"><?= getAvatarInitials($c['first_name'],$c['last_name']) ?></div>
            <div style="overflow:hidden">
              <div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($c['first_name'].' '.$c['last_name']) ?></div>
              <div style="font-size:10px;color:var(--text-muted)"><?= getRoleLabel($c['role']) ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Contenu principal -->
    <div class="card" style="height:100%;display:flex;flex-direction:column;overflow:hidden">

      <?php if ($view === 'compose'): ?>
      <!-- Composer -->
      <?php $toId = (int)($_GET['to'] ?? 0); ?>
      <div class="card-header"><h3 class="card-title"><i class="fas fa-pen"></i> Nouveau message</h3></div>
      <div class="card-body" style="flex:1;overflow-y:auto">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="send">
          <div class="form-group">
            <label class="form-label">Destinataire <span class="required">*</span></label>
            <select name="recipient_id" class="form-control" required>
              <option value="">— Choisir un contact —</option>
              <?php foreach ($contacts as $c):
                if ($c['id'] === $userId) continue;
              ?>
              <option value="<?= $c['id'] ?>" <?= $toId === $c['id'] ? 'selected' : '' ?>>[<?= e(getRoleLabel($c['role'])) ?>] <?= e($c['first_name'].' '.$c['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Objet</label>
            <input type="text" name="subject" class="form-control" placeholder="Objet du message...">
          </div>
          <div class="form-group">
            <label class="form-label">Message <span class="required">*</span></label>
            <textarea name="body" class="form-control" rows="8" placeholder="Votre message..." required></textarea>
          </div>
          <div style="display:flex;gap:8px;justify-content:flex-end">
            <a href="<?= url('messages/index.php') ?>" class="btn btn-ghost">Annuler</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Envoyer</button>
          </div>
        </form>
      </div>

      <?php elseif ($view === 'thread' && $currentMsg): ?>
      <!-- Thread -->
      <div class="card-header">
        <div>
          <h3 class="card-title"><?= e($currentMsg['subject'] ?: '(Sans objet)') ?></h3>
          <div style="font-size:12px;color:var(--text-muted)">Conversation avec <?= e($currentMsg['s_first'].' '.$currentMsg['s_last']) ?></div>
        </div>
        <a href="<?= url('messages/index.php?view=inbox') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i></a>
      </div>
      <div class="card-body" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:16px">
        <!-- Message original -->
        <?php $isMe = $currentMsg['sender_id'] === $userId; ?>
        <div style="display:flex;gap:10px;<?= $isMe ? 'flex-direction:row-reverse' : '' ?>">
          <div class="avatar avatar-sm" style="background:<?= getAvatarColor($currentMsg['s_first'].$currentMsg['s_last']) ?>;flex-shrink:0"><?= getAvatarInitials($currentMsg['s_first'],$currentMsg['s_last']) ?></div>
          <div style="max-width:70%">
            <div style="background:<?= $isMe ? 'var(--primary)' : 'var(--bg-elevated)' ?>;border-radius:var(--radius);padding:12px 16px">
              <p style="font-size:14px;line-height:1.6;margin:0;color:white"><?= nl2br(e($currentMsg['body'])) ?></p>
            </div>
            <div style="font-size:11px;color:var(--text-faint);margin-top:4px;<?= $isMe ? 'text-align:right' : '' ?>"><?= formatDateTime($currentMsg['created_at']) ?></div>
          </div>
        </div>
        <!-- Réponses -->
        <?php foreach ($thread as $rep): $isMe = $rep['sender_id'] === $userId; ?>
        <div style="display:flex;gap:10px;<?= $isMe ? 'flex-direction:row-reverse' : '' ?>">
          <div class="avatar avatar-sm" style="background:<?= getAvatarColor($rep['s_first'].$rep['s_last']) ?>;flex-shrink:0"><?= getAvatarInitials($rep['s_first'],$rep['s_last']) ?></div>
          <div style="max-width:70%">
            <div style="background:<?= $isMe ? 'var(--primary)' : 'var(--bg-elevated)' ?>;border-radius:var(--radius);padding:12px 16px">
              <p style="font-size:14px;line-height:1.6;margin:0;color:white"><?= nl2br(e($rep['body'])) ?></p>
            </div>
            <div style="font-size:11px;color:var(--text-faint);margin-top:4px;<?= $isMe ? 'text-align:right' : '' ?>"><?= formatDateTime($rep['created_at']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <!-- Répondre -->
      <div style="border-top:1px solid var(--border);padding:16px">
        <form method="POST" style="display:flex;gap:8px;align-items:flex-end">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="recipient_id" value="<?= $currentMsg['sender_id'] === $userId ? $currentMsg['recipient_id'] : $currentMsg['sender_id'] ?>">
          <input type="hidden" name="subject" value="Re: <?= e($currentMsg['subject'] ?? '') ?>">
          <input type="hidden" name="parent_id" value="<?= $currentMsg['id'] ?>">
          <textarea name="body" class="form-control" rows="2" placeholder="Votre réponse..." style="flex:1;resize:none" required></textarea>
          <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i></button>
        </form>
      </div>

      <?php else: ?>
      <!-- Liste messages -->
      <?php $messages = ($view === 'sent') ? $sent : $inbox; ?>
      <div class="card-header">
        <h3 class="card-title"><?= $view === 'sent' ? '<i class="fas fa-paper-plane"></i> Messages envoyés' : '<i class="fas fa-inbox"></i> Boîte de réception' ?></h3>
        <span class="badge badge-secondary"><?= count($messages) ?></span>
      </div>
      <div style="flex:1;overflow-y:auto">
        <?php if (empty($messages)): ?>
        <div class="empty-state" style="padding:40px"><div class="icon">📭</div><h3>Aucun message</h3></div>
        <?php else: ?>
        <?php foreach ($messages as $msg):
          $isUnread = !$msg['read_at'] && $view === 'inbox';
          $name = $view === 'sent' ? ($msg['r_first'].' '.$msg['r_last']) : ($msg['s_first'].' '.$msg['s_last']);
        ?>
        <a href="<?= url('messages/index.php?view=thread&id=' . $msg['id']) ?>" style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border);text-decoration:none;background:<?= $isUnread ? 'rgba(99,102,241,.05)' : '' ?>;transition:.15s" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='<?= $isUnread ? 'rgba(99,102,241,.05)' : '' ?>'">
          <div class="avatar avatar-sm" style="background:<?= getAvatarColor($name) ?>;flex-shrink:0"><?= getAvatarInitials(explode(' ',$name)[0],explode(' ',$name)[1]??'') ?></div>
          <div style="flex:1;overflow:hidden">
            <div style="display:flex;justify-content:space-between;margin-bottom:2px">
              <span style="font-size:13px;font-weight:<?= $isUnread ? '700' : '500' ?>;color:white"><?= e($name) ?></span>
              <span style="font-size:11px;color:var(--text-faint);flex-shrink:0"><?= timeAgo($msg['created_at']) ?></span>
            </div>
            <div style="font-size:12px;color:var(--text-muted);font-weight:<?= $isUnread ? '600' : '400' ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($msg['subject'] ?: '(Sans objet)') ?></div>
            <div style="font-size:11px;color:var(--text-faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e(mb_substr($msg['body'],0,80)) ?></div>
          </div>
          <?php if ($isUnread): ?><div style="width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0"></div><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php renderFooter(); ?>
