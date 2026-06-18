<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$search = trim($_GET['q'] ?? '');
$type   = $_GET['type'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));

$where  = ['l.created_by = ?'];
$params = [$userId];

if ($search) { $where[] = 'l.title LIKE ?'; $params[] = '%' . $search . '%'; }
if ($type)   { $where[] = 'l.content_type = ?'; $params[] = $type; }

$ws = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM lessons l WHERE $ws");
$total->execute($params);
$p = paginate((int)$total->fetchColumn(), 15, $page);

$stmt = $pdo->prepare("
    SELECT l.*, m.title as module_title, m.formation_id,
           f.title as formation_title,
           (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.lesson_id = l.id AND lp.status = 'completed') as completed_count,
           (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.lesson_id = l.id) as started_count
    FROM lessons l
    JOIN modules m ON l.module_id = m.id
    JOIN formations f ON m.formation_id = f.id
    WHERE $ws
    ORDER BY l.updated_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
$lessons = $stmt->fetchAll();

// Stats globales
$statsStmt = $pdo->prepare("SELECT content_type, COUNT(*) as cnt FROM lessons WHERE created_by = ? GROUP BY content_type");
$statsStmt->execute([$userId]);
$typeStats = array_column($statsStmt->fetchAll(), 'cnt', 'content_type');

renderHead('Mes capsules');
renderSidebar('teacher');
renderTopbar('Mes capsules', [['Enseignant', url('teacher/index.php')], ['Capsules', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Stats rapides -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
    <?php
    $types = ['video'=>['Vidéos','fa-play-circle','#ef4444'],'pdf'=>['PDF','fa-file-pdf','#f59e0b'],'quiz'=>['Quiz','fa-question-circle','#8b5cf6'],'text'=>['Textes','fa-align-left','#6366f1']];
    foreach ($types as $t => [$label, $icon, $color]):
    ?>
    <div class="card">
      <div class="card-body" style="display:flex;align-items:center;gap:12px;padding:14px">
        <div style="width:40px;height:40px;border-radius:var(--radius);background:<?= $color ?>22;display:flex;align-items:center;justify-content:center">
          <i class="fas <?= $icon ?>" style="color:<?= $color ?>"></i>
        </div>
        <div>
          <div style="font-size:20px;font-weight:800;color:white"><?= $typeStats[$t] ?? 0 ?></div>
          <div style="font-size:11px;color:var(--text-muted)"><?= $label ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div><h1>Mes capsules</h1><p><?= $p['total'] ?> capsule(s)</p></div>
      <div style="display:flex;gap:8px">
        <a href="<?= url('teacher/courses/order.php') ?>" class="btn btn-secondary"><i class="fas fa-sort"></i> Ordonner</a>
        <a href="<?= url('teacher/courses/create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvelle capsule</a>
      </div>
    </div>
  </div>

  <!-- Filtres -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div class="search-input" style="flex:1;min-width:200px">
          <i class="fas fa-search"></i>
          <input type="text" name="q" placeholder="Rechercher une capsule..." value="<?= e($search) ?>">
        </div>
        <select name="type" class="form-control" style="width:160px">
          <option value="">Tous types</option>
          <option value="video"        <?= $type==='video'?'selected':'' ?>>Vidéo</option>
          <option value="pdf"          <?= $type==='pdf'?'selected':'' ?>>PDF</option>
          <option value="document"     <?= $type==='document'?'selected':'' ?>>Document</option>
          <option value="presentation" <?= $type==='presentation'?'selected':'' ?>>Présentation</option>
          <option value="quiz"         <?= $type==='quiz'?'selected':'' ?>>Quiz</option>
          <option value="exercise"     <?= $type==='exercise'?'selected':'' ?>>Exercice</option>
          <option value="text"         <?= $type==='text'?'selected':'' ?>>Texte</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
        <?php if ($search || $type): ?><a href="<?= url('teacher/courses/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Liste -->
  <?php if (empty($lessons)): ?>
  <div class="empty-state">
    <div class="icon">📖</div>
    <h3>Aucune capsule</h3>
    <p>Créez votre première capsule pédagogique.</p>
    <a href="<?= url('teacher/courses/create.php') ?>" class="btn btn-primary">Créer une capsule</a>
  </div>
  <?php else: ?>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="table">
        <thead>
          <tr>
            <th>Capsule</th>
            <th>Formation / Module</th>
            <th>Type</th>
            <th>Durée</th>
            <th>XP</th>
            <th>Complétion</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lessons as $l): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <i class="<?= getContentTypeIcon($l['content_type']) ?>" style="font-size:18px;width:20px"></i>
                <div>
                  <div style="font-weight:600;font-size:14px"><?= e($l['title']) ?></div>
                  <?php if (!$l['is_mandatory']): ?><span style="font-size:10px;color:var(--text-faint)">Optionnel</span><?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <div style="font-size:13px"><?= e(mb_substr($l['formation_title'],0,30)) ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?= e(mb_substr($l['module_title'],0,30)) ?></div>
            </td>
            <td><span class="badge badge-secondary"><?= e(ucfirst($l['content_type'])) ?></span></td>
            <td><?= $l['duration_minutes'] ? formatDuration($l['duration_minutes']) : '—' ?></td>
            <td><span style="color:var(--warning)">+<?= $l['xp_reward'] ?> XP</span></td>
            <td>
              <?php if ($l['started_count'] > 0): ?>
              <div style="font-size:12px"><?= $l['completed_count'] ?>/<?= $l['started_count'] ?> apprenants</div>
              <div class="progress-bar" style="height:4px;margin-top:4px"><div class="progress-fill" style="width:<?= round($l['completed_count']/$l['started_count']*100) ?>%"></div></div>
              <?php else: ?>
              <span style="color:var(--text-faint);font-size:12px">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;gap:4px">
                <a href="<?= url('teacher/courses/create.php?id=' . $l['id']) ?>" class="btn btn-secondary btn-sm" title="Modifier"><i class="fas fa-edit"></i></a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?= $p['totalPages'] > 1 ? renderPagination($p, url('teacher/courses/index.php?' . http_build_query(array_filter(compact('search', 'type'))))) : '' ?>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
