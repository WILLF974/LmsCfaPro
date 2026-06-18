<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$quizId = (int)($_GET['quiz_id'] ?? 0);
$page   = max(1, (int)($_GET['page'] ?? 1));

// Mes quiz (pour le filtre)
$myQuizzes = $pdo->prepare("SELECT id, title, quiz_type FROM quizzes WHERE created_by = ? ORDER BY title");
$myQuizzes->execute([$userId]);
$myQuizzes = $myQuizzes->fetchAll();

// Tentatives
$where  = ['q.created_by = ?', 'qa.status = ?'];
$params = [$userId, 'completed'];
if ($quizId) { $where[] = 'qa.quiz_id = ?'; $params[] = $quizId; }
$ws = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id WHERE $ws");
$total->execute($params);
$p = paginate((int)$total->fetchColumn(), 20, $page);

$stmt = $pdo->prepare("
    SELECT qa.*, q.title as quiz_title, q.passing_score, q.quiz_type,
           u.first_name, u.last_name, u.email,
           f.title as formation_title
    FROM quiz_attempts qa
    JOIN quizzes q ON qa.quiz_id = q.id
    JOIN users u ON qa.user_id = u.id
    LEFT JOIN formations f ON q.formation_id = f.id
    WHERE $ws
    ORDER BY qa.completed_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
$attempts = $stmt->fetchAll();

// Stats globales
$globalStats = $pdo->prepare("SELECT COUNT(*) as total, ROUND(AVG(qa.score),1) as avg_score, SUM(CASE WHEN qa.passed=1 THEN 1 ELSE 0 END) as passed FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id WHERE q.created_by = ? AND qa.status='completed'");
$globalStats->execute([$userId]);
$stats = $globalStats->fetch();

renderHead('Corrections & Résultats');
renderSidebar('teacher');
renderTopbar('Corrections & Résultats', [['Enseignant', url('teacher/index.php')], ['Évaluations', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px">
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(99,102,241,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-list" style="color:var(--primary-light)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:white"><?= $stats['total'] ?? 0 ?></div><div style="font-size:12px;color:var(--text-muted)">Tentatives totales</div></div>
    </div></div>
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(16,185,129,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-check" style="color:var(--success)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:var(--success)"><?= $stats['passed'] ?? 0 ?></div><div style="font-size:12px;color:var(--text-muted)">Réussites</div></div>
    </div></div>
    <div class="card"><div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:var(--radius);background:rgba(245,158,11,.15);display:flex;align-items:center;justify-content:center"><i class="fas fa-percentage" style="color:var(--warning)"></i></div>
      <div><div style="font-size:24px;font-weight:800;color:var(--warning)"><?= $stats['avg_score'] ?? 0 ?>%</div><div style="font-size:12px;color:var(--text-muted)">Score moyen</div></div>
    </div></div>
  </div>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div><h1>Résultats des apprenants</h1><p><?= $p['total'] ?> résultat(s)</p></div>
      <a href="<?= url('api/export.php?type=evaluations') ?>" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Exporter CSV</a>
    </div>
  </div>

  <!-- Filtre par quiz -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <select name="quiz_id" class="form-control" style="flex:1;min-width:200px">
          <option value="">— Tous mes quiz —</option>
          <?php foreach ($myQuizzes as $q): ?>
          <option value="<?= $q['id'] ?>" <?= $quizId == $q['id'] ? 'selected' : '' ?>><?= e($q['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
        <?php if ($quizId): ?><a href="<?= url('teacher/evaluations/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
      </form>
    </div>
  </div>

  <?php if (empty($attempts)): ?>
  <div class="empty-state">
    <div class="icon">📊</div>
    <h3>Aucun résultat</h3>
    <p>Vos apprenants n'ont pas encore complété de quiz.</p>
  </div>
  <?php else: ?>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="table">
        <thead>
          <tr>
            <th>Apprenant</th>
            <th>Quiz</th>
            <th>Score</th>
            <th>Résultat</th>
            <th>Durée</th>
            <th>Tentative</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($attempts as $a):
            $passed = (bool)$a['passed'];
            $score  = round($a['score'] ?? 0);
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div class="avatar avatar-sm" style="background:<?= getAvatarColor($a['first_name'].$a['last_name']) ?>"><?= getAvatarInitials($a['first_name'],$a['last_name']) ?></div>
                <div>
                  <div style="font-weight:600;font-size:13px"><?= e($a['first_name'].' '.$a['last_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted)"><?= e($a['email']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <div style="font-size:13px;font-weight:600"><?= e(mb_substr($a['quiz_title'],0,35)) ?></div>
              <?php if ($a['formation_title']): ?><div style="font-size:11px;color:var(--text-muted)"><?= e(mb_substr($a['formation_title'],0,30)) ?></div><?php endif; ?>
            </td>
            <td>
              <div style="font-size:16px;font-weight:800;color:<?= $passed ? 'var(--success)' : 'var(--danger)' ?>"><?= $score ?>%</div>
              <div style="font-size:10px;color:var(--text-muted)">seuil : <?= $a['passing_score'] ?>%</div>
            </td>
            <td>
              <?php if ($passed): ?>
              <span class="badge badge-success"><i class="fas fa-check"></i> Réussi</span>
              <?php else: ?>
              <span class="badge badge-danger"><i class="fas fa-times"></i> Échec</span>
              <?php endif; ?>
            </td>
            <td style="font-size:13px"><?= $a['time_spent_seconds'] ? round($a['time_spent_seconds']/60) . ' min' : '—' ?></td>
            <td><span class="badge badge-secondary">#<?= $a['attempt_number'] ?></span></td>
            <td style="font-size:12px;color:var(--text-muted)"><?= $a['completed_at'] ? formatDateTime($a['completed_at']) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?= $p['totalPages'] > 1 ? renderPagination($p, url('teacher/evaluations/index.php?' . http_build_query(array_filter(['quiz_id'=>$quizId])))) : '' ?>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
