<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requireTeacher();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Créer la table si elle n'existe pas encore
$pdo->exec("
    CREATE TABLE IF NOT EXISTS case_studies (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        title         VARCHAR(255) NOT NULL,
        description   TEXT,
        file_type     VARCHAR(30) NOT NULL DEFAULT 'pdf',
        file_path     TEXT,
        content_url   VARCHAR(500),
        formation_id  INT NULL,
        created_by    INT NOT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_created_by (created_by),
        INDEX idx_formation  (formation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Ajouter les colonnes de rattachement si absentes (migration non-destructive)
foreach ([
    "ALTER TABLE case_studies ADD COLUMN activity_type_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN competency_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN module_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN lesson_id INT NULL",
    "ALTER TABLE case_studies ADD COLUMN duration_minutes INT NULL",
    "ALTER TABLE case_studies ADD COLUMN xp_reward SMALLINT NOT NULL DEFAULT 0",
] as $sql) { try { $pdo->exec($sql); } catch (PDOException $e) {} }

$ownerOnly   = !isAdmin() && !isPedagogy();
$whereClause = $ownerOnly ? 'WHERE cs.created_by = ' . $userId : '';

$caseStudies = $pdo->query("
    SELECT cs.*,
           f.title  AS formation_title,
           m.title  AS module_title,
           l.title  AS lesson_title,
           at.code  AS at_code,  at.title AS at_title,
           co.code  AS co_code,  co.title AS co_title,
           CONCAT(u.first_name,' ',u.last_name) AS author
    FROM case_studies cs
    LEFT JOIN formations   f  ON cs.formation_id      = f.id
    LEFT JOIN modules      m  ON cs.module_id         = m.id
    LEFT JOIN lessons      l  ON cs.lesson_id         = l.id
    LEFT JOIN activity_types at ON cs.activity_type_id = at.id
    LEFT JOIN competencies   co ON cs.competency_id    = co.id
    LEFT JOIN users u ON cs.created_by = u.id
    $whereClause
    ORDER BY cs.created_at DESC
")->fetchAll();

$typeIcons = [
    'pdf'          => ['icon'=>'file-pdf',       'color'=>'#ef4444', 'label'=>'PDF'],
    'document'     => ['icon'=>'file-word',      'color'=>'#3b82f6', 'label'=>'Document'],
    'presentation' => ['icon'=>'file-powerpoint','color'=>'#f97316', 'label'=>'Présentation'],
    'video'        => ['icon'=>'play-circle',    'color'=>'#ef4444', 'label'=>'Vidéo'],
    'link'         => ['icon'=>'link',           'color'=>'#0ea5e9', 'label'=>'Lien'],
];

renderHead('Études de cas');
renderSidebar('teacher');
renderTopbar('Études de cas', [['Enseignant', url('teacher/index.php')], ['Études de cas', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div>
        <h1>Études de cas</h1>
        <p><?= count($caseStudies) ?> étude(s) de cas</p>
      </div>
      <a href="<?= url('teacher/case_studies/create.php') ?>" class="btn btn-primary">
        <i class="fas fa-plus"></i> Importer une étude de cas
      </a>
    </div>
  </div>

  <?php if (empty($caseStudies)): ?>
  <div class="empty-state">
    <div class="icon">📂</div>
    <h3>Aucune étude de cas</h3>
    <p>Importez des documents, PDF ou présentations à soumettre à vos apprenants.</p>
    <a href="<?= url('teacher/case_studies/create.php') ?>" class="btn btn-primary" style="margin-top:12px">
      <i class="fas fa-plus"></i> Importer une étude de cas
    </a>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px">
    <?php foreach ($caseStudies as $cs):
        $ti = $typeIcons[$cs['file_type']] ?? $typeIcons['document'];
        $pdfPages = null;
        if ($cs['file_type'] === 'pdf' && $cs['file_path']) {
            $dec = json_decode($cs['file_path'], true);
            if (is_array($dec)) $pdfPages = $dec;
        }
        $pdfCount = $pdfPages ? count($pdfPages) : ($cs['file_path'] ? 1 : 0);
    ?>
    <div class="card" style="display:flex;flex-direction:column">
      <div class="card-body" style="flex:1">
        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px">
          <div style="width:42px;height:42px;border-radius:10px;background:<?= $ti['color'] ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-<?= $ti['icon'] ?>" style="color:<?= $ti['color'] ?>;font-size:18px"></i>
          </div>
          <div style="flex:1;min-width:0">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:4px;color:white"><?= e($cs['title']) ?></h3>
            <?php if ($cs['description']): ?>
            <p style="font-size:12px;color:var(--text-muted);margin:0;line-height:1.4"><?= e(mb_substr($cs['description'],0,80)) ?><?= mb_strlen($cs['description'])>80?'…':'' ?></p>
            <?php endif; ?>
          </div>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
          <span class="badge badge-secondary"><i class="fas fa-<?= $ti['icon'] ?>"></i> <?= $ti['label'] ?></span>
          <?php if ($cs['file_type'] === 'pdf' && $pdfCount > 1): ?>
          <span class="badge badge-primary"><i class="fas fa-copy"></i> <?= $pdfCount ?> PDF</span>
          <?php endif; ?>
          <?php if ($cs['formation_title']): ?>
          <span class="badge badge-secondary" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($cs['formation_title']) ?>">
            <i class="fas fa-graduation-cap"></i> <?= e(mb_substr($cs['formation_title'],0,22)) ?>
          </span>
          <?php endif; ?>
          <?php if ($cs['module_title']): ?>
          <span class="badge badge-secondary" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="Module : <?= e($cs['module_title']) ?>">
            <i class="fas fa-cube"></i> <?= e(mb_substr($cs['module_title'],0,22)) ?>
          </span>
          <?php endif; ?>
          <?php if ($cs['at_code']): ?>
          <span class="badge badge-secondary" title="Bloc / Activité type : <?= e($cs['at_title']) ?>">
            <i class="fas fa-layer-group"></i> <?= e($cs['at_code']) ?>
          </span>
          <?php endif; ?>
          <?php if ($cs['co_code']): ?>
          <span class="badge badge-secondary" title="Compétence : <?= e($cs['co_title']) ?>">
            <i class="fas fa-star"></i> <?= e($cs['co_code']) ?>
          </span>
          <?php endif; ?>
          <?php if ($cs['lesson_title']): ?>
          <span class="badge badge-secondary" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="Capsule : <?= e($cs['lesson_title']) ?>">
            <i class="fas fa-book-open"></i> <?= e(mb_substr($cs['lesson_title'],0,22)) ?>
          </span>
          <?php endif; ?>
        </div>

        <div style="font-size:11px;color:var(--text-faint)">
          <?= formatDate($cs['created_at'], 'd/m/Y à H:i') ?>
          <?php if (!$ownerOnly): ?> · <?= e($cs['author']) ?><?php endif; ?>
        </div>
      </div>

      <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:6px">
        <a href="<?= url('student/case_studies/view.php?id='.$cs['id']) ?>" target="_blank" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center">
          <i class="fas fa-eye"></i> Visualiser
        </a>
        <a href="<?= url('teacher/case_studies/create.php?id='.$cs['id']) ?>" class="btn btn-ghost btn-sm" title="Modifier">
          <i class="fas fa-edit"></i>
        </a>
        <?php $canDelete = isAdmin() || isPedagogy() || $cs['created_by'] == $userId; ?>
        <?php if ($canDelete): ?>
        <form method="POST" action="<?= url('teacher/case_studies/delete.php') ?>"
              onsubmit="return confirm('Supprimer « <?= e(addslashes($cs['title'])) ?> » définitivement ?')">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $cs['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="Supprimer">
            <i class="fas fa-trash"></i>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php renderFooter(); ?>
