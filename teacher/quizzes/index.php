<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$search = trim($_GET['q'] ?? '');
$qtype  = $_GET['quiz_type'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));

$where  = ['q.created_by = ?'];
$params = [$userId];
if ($search) { $where[] = 'q.title LIKE ?'; $params[] = '%' . $search . '%'; }
if ($qtype)  { $where[] = 'q.quiz_type = ?'; $params[] = $qtype; }
$ws = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM quizzes q WHERE $ws");
$total->execute($params);
$p = paginate((int)$total->fetchColumn(), 15, $page);

$stmt = $pdo->prepare("
    SELECT q.*,
           (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) as question_count,
           (SELECT COUNT(DISTINCT qa.user_id) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.status = 'completed') as attempt_count,
           (SELECT ROUND(AVG(qa.score),1) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.status = 'completed') as avg_score,
           f.title as formation_title, m.title as module_title, l.title as lesson_title
    FROM quizzes q
    LEFT JOIN formations f ON q.formation_id = f.id
    LEFT JOIN modules m ON q.module_id = m.id
    LEFT JOIN lessons l ON q.lesson_id = l.id
    WHERE $ws
    ORDER BY q.updated_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$p['perPage'], $p['offset']]));
$quizzes = $stmt->fetchAll();

renderHead('Quiz & Évaluations');
renderSidebar('teacher');
renderTopbar('Quiz & Évaluations', [['Enseignant', url('teacher/index.php')], ['Quiz', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div><h1>Quiz & Évaluations</h1><p><?= $p['total'] ?> quiz créé(s)</p></div>
      <a href="<?= url('teacher/quizzes/create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Créer un quiz</a>
    </div>
  </div>

  <!-- Filtres -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div class="search-input" style="flex:1;min-width:200px">
          <i class="fas fa-search"></i>
          <input type="text" name="q" placeholder="Rechercher un quiz..." value="<?= e($search) ?>">
        </div>
        <select name="quiz_type" class="form-control" style="width:180px">
          <option value="">Tous types</option>
          <option value="practice"      <?= $qtype==='practice'?'selected':'' ?>>Entraînement</option>
          <option value="evaluation"    <?= $qtype==='evaluation'?'selected':'' ?>>Évaluation</option>
          <option value="certification" <?= $qtype==='certification'?'selected':'' ?>>Certification</option>
          <option value="survey"        <?= $qtype==='survey'?'selected':'' ?>>Sondage</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
        <?php if ($search || $qtype): ?><a href="<?= url('teacher/quizzes/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
      </form>
    </div>
  </div>

  <?php if (empty($quizzes)): ?>
  <div class="empty-state">
    <div class="icon">❓</div>
    <h3>Aucun quiz</h3>
    <p>Créez votre premier quiz pour évaluer vos apprenants.</p>
    <a href="<?= url('teacher/quizzes/create.php') ?>" class="btn btn-primary">Créer un quiz</a>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">
    <?php
    $typeIcons  = ['practice'=>'fas fa-dumbbell','evaluation'=>'fas fa-tasks','certification'=>'fas fa-certificate','survey'=>'fas fa-poll'];
    $typeColors = ['practice'=>'#6366f1','evaluation'=>'#f59e0b','certification'=>'#10b981','survey'=>'#0ea5e9'];
    $typeLabels = ['practice'=>'Entraînement','evaluation'=>'Évaluation','certification'=>'Certification','survey'=>'Sondage'];
    foreach ($quizzes as $quiz):
      $icon  = $typeIcons[$quiz['quiz_type']] ?? 'fas fa-question-circle';
      $color = $typeColors[$quiz['quiz_type']] ?? '#6366f1';
      $label = $typeLabels[$quiz['quiz_type']] ?? $quiz['quiz_type'];
    ?>
    <div class="card">
      <div class="card-body">
        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px">
          <div style="width:42px;height:42px;border-radius:var(--radius);background:<?= $color ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="<?= $icon ?>" style="color:<?= $color ?>"></i>
          </div>
          <div style="flex:1;overflow:hidden">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:4px"><?= e($quiz['title']) ?></h3>
            <span class="badge badge-secondary" style="font-size:10px"><?= $label ?></span>
          </div>
        </div>

        <?php if ($quiz['formation_title']): ?>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px"><i class="fas fa-graduation-cap" style="margin-right:4px"></i><?= e(mb_substr($quiz['formation_title'],0,35)) ?></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;text-align:center">
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:white"><?= $quiz['question_count'] ?></div>
            <div style="font-size:10px;color:var(--text-muted)">Questions</div>
          </div>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:white"><?= $quiz['attempt_count'] ?></div>
            <div style="font-size:10px;color:var(--text-muted)">Réponses</div>
          </div>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:<?= $quiz['avg_score'] >= $quiz['passing_score'] ? 'var(--success)' : 'var(--danger)' ?>"><?= $quiz['avg_score'] ? round($quiz['avg_score']) . '%' : '—' ?></div>
            <div style="font-size:10px;color:var(--text-muted)">Moy.</div>
          </div>
        </div>

        <div style="display:flex;gap:6px;font-size:12px;color:var(--text-muted);margin-bottom:12px">
          <span><i class="fas fa-star-half-alt"></i> Seuil <?= $quiz['passing_score'] ?>%</span>
          <?php if ($quiz['time_limit_minutes']): ?><span><i class="fas fa-clock"></i> <?= $quiz['time_limit_minutes'] ?> min</span><?php endif; ?>
          <span><i class="fas fa-redo"></i> <?= $quiz['max_attempts'] ?> essais</span>
        </div>

        <div style="display:flex;gap:8px">
          <a href="<?= url('teacher/quizzes/create.php?id=' . $quiz['id']) ?>" class="btn btn-secondary btn-sm" style="flex:1;justify-content:center"><i class="fas fa-edit"></i> Modifier</a>
          <a href="<?= url('teacher/evaluations/index.php?quiz_id=' . $quiz['id']) ?>" class="btn btn-ghost btn-sm" title="Voir les résultats"><i class="fas fa-chart-bar"></i></a>
          <form method="POST" action="<?= url('teacher/quizzes/delete.php') ?>" onsubmit="return confirm('Supprimer définitivement le quiz « <?= e(addslashes($quiz['title'])) ?> » et toutes ses données ?')">
            <?= csrfField() ?>
            <input type="hidden" name="quiz_id" value="<?= $quiz['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="Supprimer le quiz"><i class="fas fa-trash"></i></button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?= $p['totalPages'] > 1 ? renderPagination($p, url('teacher/quizzes/index.php?' . http_build_query(array_filter(['q'=>$search,'quiz_type'=>$qtype])))) : '' ?>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
