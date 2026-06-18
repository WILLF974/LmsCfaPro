<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1,(int)($_GET['page']??1));

$where=['1=1'];$params=[];
if ($search){$where[]='(f.title LIKE ? OR r.rncp_code LIKE ?)';$like="%$search%";$params=[$like,$like];}
if ($status){$where[]='f.status=?';$params[]=$status;}
$ws=implode(' AND ',$where);

$total=$pdo->prepare("SELECT COUNT(*) FROM formations f LEFT JOIN rncp_titles r ON f.rncp_title_id=r.id WHERE $ws");
$total->execute($params);
$p=paginate((int)$total->fetchColumn(),15,$page);

$stmt=$pdo->prepare("
    SELECT f.*,r.rncp_code,r.level as rncp_level,u.first_name,u.last_name,
           (SELECT COUNT(*) FROM enrollments e WHERE e.formation_id=f.id AND e.status='active') as student_count,
           (SELECT COUNT(*) FROM modules m WHERE m.formation_id=f.id) as module_count,
           (SELECT COUNT(*) FROM lessons l JOIN modules m ON l.module_id=m.id WHERE m.formation_id=f.id) as lesson_count,
           (SELECT AVG(e.progress_percent) FROM enrollments e WHERE e.formation_id=f.id AND e.status='active') as avg_progress
    FROM formations f
    LEFT JOIN rncp_titles r ON f.rncp_title_id=r.id
    LEFT JOIN users u ON f.created_by=u.id
    WHERE $ws ORDER BY f.updated_at DESC LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params,[$p['perPage'],$p['offset']]));
$formations=$stmt->fetchAll();

renderHead('Formations');
renderSidebar(isAdmin()?'admin':'pedagogy');
renderTopbar('Formations',[['Admin',url('admin/index.php')],['Formations','']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>
  <div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div><h1>Formations</h1><p><?= $p['total'] ?> formation(s) trouvée(s)</p></div>
      <a href="<?= url('admin/formations/create.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Créer</a>
    </div>
  </div>

  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div class="search-input" style="flex:1;min-width:200px">
          <i class="fas fa-search"></i>
          <input type="text" name="q" placeholder="Chercher une formation..." value="<?= e($search) ?>">
        </div>
        <select name="status" class="form-control" style="width:140px">
          <option value="">Tous statuts</option>
          <option value="draft" <?= $status==='draft'?'selected':'' ?>>Brouillon</option>
          <option value="active" <?= $status==='active'?'selected':'' ?>>Actif</option>
          <option value="archived" <?= $status==='archived'?'selected':'' ?>>Archivé</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
        <?php if($search||$status):?><a href="<?= url('admin/formations/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
      </form>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
    <?php foreach($formations as $f): ?>
    <div class="card">
      <div style="height:120px;background:linear-gradient(135deg,var(--bg-elevated),var(--bg-hover));display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden">
        <?php if($f['thumbnail']&&file_exists(UPLOADS_PATH.'/'.$f['thumbnail'])): ?>
        <img src="<?= e(uploadUrl($f['thumbnail'])) ?>" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
        <div style="font-size:48px"><?= ['🎓','📚','💡','🔬','💻','🎨'][crc32($f['title'])%6] ?></div>
        <?php endif; ?>
        <div style="position:absolute;top:10px;left:10px;display:flex;gap:6px">
          <?php if($f['rncp_code']):?><span class="badge badge-primary"><?= e($f['rncp_code']) ?></span><?php endif;?>
          <?= getStatusBadge($f['status']) ?>
        </div>
      </div>
      <div class="card-body">
        <h3 style="font-size:15px;font-weight:700;margin-bottom:8px;line-height:1.3"><?= e($f['title']) ?></h3>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px">
          <div style="text-align:center;background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:white"><?= $f['student_count'] ?></div>
            <div style="font-size:10px;color:var(--text-muted)">Étudiants</div>
          </div>
          <div style="text-align:center;background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:white"><?= $f['module_count'] ?></div>
            <div style="font-size:10px;color:var(--text-muted)">Modules</div>
          </div>
          <div style="text-align:center;background:var(--bg-elevated);border-radius:var(--radius);padding:8px">
            <div style="font-size:16px;font-weight:800;color:white"><?= $f['lesson_count'] ?></div>
            <div style="font-size:10px;color:var(--text-muted)">Capsules</div>
          </div>
        </div>
        <?php if($f['student_count']>0): ?>
        <div style="margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:4px">
            <span>Progression moy.</span><span><?= round($f['avg_progress']??0) ?>%</span>
          </div>
          <div class="progress-bar" style="height:6px"><div class="progress-fill" style="width:<?= round($f['avg_progress']??0) ?>%"></div></div>
        </div>
        <?php endif; ?>
        <?php if($f['start_date']||$f['end_date']): ?>
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px">
          <?php if($f['start_date']):?><i class="fas fa-calendar-alt" style="margin-right:4px"></i><?= formatDate($f['start_date']) ?><?php endif;?>
          <?php if($f['end_date']):?> → <?= formatDate($f['end_date']) ?><?php endif;?>
        </div>
        <?php endif;?>
        <div style="display:flex;gap:8px">
          <a href="<?= url('admin/formations/view.php?id='.$f['id']) ?>" class="btn btn-primary btn-sm" style="flex:1;justify-content:center">
            <i class="fas fa-eye"></i> Gérer
          </a>
          <a href="<?= url('admin/formations/edit.php?id='.$f['id']) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
        </div>
      </div>
    </div>
    <?php endforeach;?>
    <?php if(empty($formations)): ?>
    <div style="grid-column:1/-1"><div class="empty-state"><div class="icon">📚</div><h3>Aucune formation</h3><p>Créez votre première formation.</p><a href="<?= url('admin/formations/create.php') ?>" class="btn btn-primary">Créer une formation</a></div></div>
    <?php endif;?>
  </div>
  <?= $p['totalPages']>1?renderPagination($p,url('admin/formations/index.php?'.http_build_query(array_filter(compact('search','status'))))):'' ?>
</div>
<?php renderFooter(); ?>
